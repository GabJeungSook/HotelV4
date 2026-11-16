<?php

declare(strict_types=1);

namespace App\Actions\Owner;

use App\Enums\BranchStatus;
use App\Enums\Role;
use App\Exceptions\ActionRefused;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Discount;
use App\Models\ExpenseCategory;
use App\Models\ExtensionRate;
use App\Models\Product;
use App\Models\Rate;
use App\Models\Reason;
use App\Models\RoomType;
use App\Models\ShiftDefinition;
use App\Models\TransferReason;
use App\Models\User;
use App\Support\Clock;
use App\Support\DuplicateKey;
use App\Support\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Add a branch (screen 56, APP_FLOW §4.20, TRD §4.9 "Create branch"). One transaction:
 *
 * 1. the branch, `setting_up`, with the source branch's settings (everything but its name,
 *    address, slip header, prefix, status, number counters and tax identity) or the column
 *    defaults;
 * 2. its first admin, with a temporary password returned once (never stored in plain text) and
 *    no PIN, so the PIN is set at first sign-in;
 * 3. when copying: shift definitions, room types, rates, extension rates, discounts with their
 *    discount_rate rows mapped to the new rates, products with stock 0, transfer reasons,
 *    expense categories and reasons. Never rooms, floors, users, stays or money rows;
 * 4. audit_logs `branch.created`.
 *
 * The form request already checks the unique name, prefix and username; a duplicate that slips
 * past it (two owners at once) is refused here by the unique keys and nothing is saved.
 */
final class CreateBranch
{
    /** Source columns that belong to the branch itself and are never copied. */
    private const NOT_COPIED = [
        'id', 'name', 'address', 'slip_header', 'code_prefix', 'status',
        'next_stay_number', 'next_pos_number', 'next_z_number', 'next_day_z_number',
        'tin', 'registered_name', 'vat_enabled', 'created_at', 'updated_at',
    ];

    public function __construct(
        private readonly Clock $clock,
        private readonly Transaction $transaction,
    ) {}

    /**
     * @param  array{name: string, address: string, code_prefix: string, admin_name: string, admin_username: string, copy_from_id?: int|null}  $data
     * @return array{branch: Branch, admin: User, password: string}
     */
    public function handle(User $owner, array $data): array
    {
        if ($owner->role !== Role::Owner) {
            throw ActionRefused::because('Only an owner can add a branch.');
        }

        $password = self::temporaryPassword();

        try {
            return $this->transaction->run(function () use ($owner, $data, $password): array {
                $source = isset($data['copy_from_id'])
                    ? Branch::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($data['copy_from_id'])
                    : null;

                $branch = $this->branch($data, $source);

                $admin = User::query()->create([
                    'branch_id' => $branch->id,
                    'name' => $data['admin_name'],
                    'username' => $data['admin_username'],
                    'password' => $password,
                    'must_change_password' => true,
                    'pin_hash' => null,
                    'role' => Role::Admin,
                    'is_active' => true,
                ]);

                $copied = $source === null ? [] : $this->copyLists($source, $branch);

                AuditLog::query()->create([
                    'branch_id' => $branch->id,
                    'actor_id' => $owner->id,
                    'action' => 'branch.created',
                    'subject_type' => 'branch',
                    'subject_id' => $branch->id,
                    'after_json' => [
                        'name' => $branch->name,
                        'code_prefix' => $branch->code_prefix,
                        'copied_from' => $source?->id,
                        'copied' => $copied,
                        'admin' => ['id' => $admin->id, 'username' => $admin->username],
                    ],
                    'created_at' => $this->clock->now(),
                ]);

                return ['branch' => $branch, 'admin' => $admin, 'password' => $password];
            });
        } catch (QueryException $e) {
            throw match (true) {
                DuplicateKey::on($e, 'branches_name_unique') => ActionRefused::because("{$data['name']} is already a branch."),
                DuplicateKey::on($e, 'branches_code_prefix_unique') => ActionRefused::because("{$data['code_prefix']} is already used by another branch."),
                DuplicateKey::on($e, 'users_username_unique') => ActionRefused::because("The username {$data['admin_username']} is taken."),
                default => $e,
            };
        }
    }

    /** A temporary password the owner reads out once: 10 letters and digits, no look-alikes. */
    public static function temporaryPassword(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';

        for ($i = 0; $i < 10; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }

    /** @param  array{name: string, address: string, code_prefix: string}  $data */
    private function branch(array $data, ?Branch $source): Branch
    {
        $own = [
            'name' => $data['name'],
            'address' => $data['address'],
            'slip_header' => Str::upper(Str::limit($data['name'], 80, '')),
            'code_prefix' => $data['code_prefix'],
            'status' => BranchStatus::SettingUp->value,
        ];

        if ($source === null) {
            $branch = Branch::query()->create([...$own, 'payment_methods' => ['cash']]);

            return $branch->refresh();
        }

        $settings = array_diff_key($source->getAttributes(), array_flip(self::NOT_COPIED));

        $branch = new Branch;
        $branch->setRawAttributes([...$settings, ...$own]);
        $branch->save();

        return $branch->refresh();
    }

    /**
     * The lists of the source branch, re-pointed at the new branch. Ids that other copied rows
     * refer to (room types for rates, rates for discounts) are mapped old → new.
     *
     * @return array<string, int>
     */
    private function copyLists(Branch $source, Branch $branch): array
    {
        $copied = [];

        $copied['shift_definitions'] = $this->copyEach(ShiftDefinition::class, $source, $branch);

        $typeIds = [];
        foreach (RoomType::query()->withoutGlobalScopes()->where('branch_id', $source->id)->orderBy('id')->get() as $type) {
            $typeIds[$type->id] = $this->copy($type, $branch)->id;
        }
        $copied['room_types'] = count($typeIds);

        $rateIds = [];
        foreach (Rate::query()->withoutGlobalScopes()->where('branch_id', $source->id)->orderBy('id')->get() as $rate) {
            $rateIds[$rate->id] = $this->copy($rate, $branch, ['room_type_id' => $typeIds[$rate->room_type_id]])->id;
        }
        $copied['rates'] = count($rateIds);

        $copied['extension_rates'] = $this->copyEach(ExtensionRate::class, $source, $branch);

        $discounts = Discount::query()->withoutGlobalScopes()->where('branch_id', $source->id)->with(['rates' => fn ($q) => $q->withoutGlobalScopes()])->orderBy('id')->get();
        foreach ($discounts as $discount) {
            /** @var Discount $new */
            $new = $this->copy($discount, $branch);
            $new->rates()->attach($discount->rates->map(fn (Rate $r): int => $rateIds[$r->id])->all());
        }
        $copied['discounts'] = $discounts->count();

        $copied['products'] = $this->copyEach(Product::class, $source, $branch, ['stock_on_hand' => 0]);
        $copied['transfer_reasons'] = $this->copyEach(TransferReason::class, $source, $branch);
        $copied['expense_categories'] = $this->copyEach(ExpenseCategory::class, $source, $branch);
        $copied['reasons'] = $this->copyEach(Reason::class, $source, $branch);

        return $copied;
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $overrides
     */
    private function copyEach(string $model, Branch $source, Branch $branch, array $overrides = []): int
    {
        $rows = $model::query()->withoutGlobalScopes()->where('branch_id', $source->id)->orderBy('id')->get();

        foreach ($rows as $row) {
            $this->copy($row, $branch, $overrides);
        }

        return $rows->count();
    }

    /**
     * @template T of Model
     *
     * @param  T  $row
     * @param  array<string, mixed>  $overrides
     * @return T
     */
    private function copy(Model $row, Branch $branch, array $overrides = []): Model
    {
        $new = $row->replicate();
        $new->setRawAttributes([...$new->getAttributes(), ...$overrides, 'branch_id' => $branch->id]);
        $new->save();

        return $new;
    }
}
