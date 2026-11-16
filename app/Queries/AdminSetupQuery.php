<?php

declare(strict_types=1);

namespace App\Queries;

use App\Actions\Admin\SaveListReason;
use App\Actions\Admin\SaveRate;
use App\Actions\Admin\SaveUser;
use App\Enums\PaymentMethod;
use App\Enums\ProductKind;
use App\Enums\ReasonKind;
use App\Enums\Role;
use App\Enums\RoomStatus;
use App\Enums\StayKind;
use App\Enums\StayState;
use App\Models\Branch;
use App\Models\Discount;
use App\Models\ExpenseCategory;
use App\Models\ExtensionRate;
use App\Models\Floor;
use App\Models\Product;
use App\Models\Rate;
use App\Models\Reason;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\ShiftDefinition;
use App\Models\Stay;
use App\Models\TransferReason;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * Read side of branch setup (44–48). Every query names the branch explicitly, so nothing of
 * another branch is ever read. Amounts go out as integer centavos.
 */
final class AdminSetupQuery
{
    /** Roles that set a PIN at first sign-in (DATABASE_SCHEMA §4.2); others show "—". */
    private const PIN_ROLES = [Role::Admin, Role::Supervisor, Role::Frontdesk];

    /**
     * Rooms (44) with the floors and room types tabs.
     *
     * @return array<string, mixed>
     */
    public function rooms(Branch $branch): array
    {
        $floors = Floor::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->withCount(['rooms' => fn ($q) => $q->withoutGlobalScopes()])->orderBy('number')->get();
        $types = RoomType::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->withCount(['rooms' => fn ($q) => $q->withoutGlobalScopes()])->orderBy('id')->get();
        $floorLabels = $floors->pluck('label', 'id');
        $floorNumbers = $floors->pluck('number', 'id');
        $typeNames = $types->pluck('name', 'id');
        $inUse = Stay::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->whereNotNull('active_room_id')->pluck('state', 'active_room_id');

        $rooms = Room::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->get()
            ->sort(fn (Room $a, Room $b): int => (($floorNumbers[$a->floor_id] ?? 0) <=> ($floorNumbers[$b->floor_id] ?? 0)) ?: strnatcasecmp($a->number, $b->number))
            ->values();

        return [
            'branch_status' => $branch->status->value,
            'rooms' => $rooms->map(function (Room $r) use ($floorLabels, $typeNames, $inUse): array {
                $held = isset($inUse[$r->id]) && ($inUse[$r->id] instanceof StayState ? $inUse[$r->id] : StayState::from((string) $inUse[$r->id])) === StayState::Held;

                return [
                    'id' => $r->id,
                    'number' => $r->number,
                    'floor_id' => $r->floor_id,
                    'floor' => $floorLabels[$r->floor_id] ?? '',
                    'room_type_id' => $r->room_type_id,
                    'type' => $typeNames[$r->room_type_id] ?? '',
                    'area' => $r->area,
                    'status' => $r->status->value,
                    'is_kiosk_enabled' => $r->is_kiosk_enabled,
                    'is_active' => $r->is_active,
                    'held' => $held,
                    // Only a free room may go between Available and Maintenance (APP_FLOW §5.2).
                    'status_editable' => in_array($r->status, [RoomStatus::Available, RoomStatus::Maintenance], true) && ! isset($inUse[$r->id]),
                ];
            })->all(),
            'floors' => $floors->map(fn (Floor $f): array => ['id' => $f->id, 'number' => $f->number, 'label' => $f->label, 'rooms' => (int) $f->getAttribute('rooms_count')])->values()->all(),
            'types' => $types->map(fn (RoomType $t): array => [
                'id' => $t->id, 'name' => $t->name, 'description' => $t->description, 'is_active' => $t->is_active, 'rooms' => (int) $t->getAttribute('rooms_count'),
            ])->values()->all(),
            'areas' => $rooms->pluck('area')->unique()->sort()->values()->all(),
        ];
    }

    /**
     * Rates (45): the columns are only the options the branch sells (46).
     *
     * @return array<string, mixed>
     */
    public function rates(Branch $branch): array
    {
        $columns = [];

        if ($branch->hourly_enabled) {
            foreach (SaveRate::HOURS as $h) {
                $columns[] = ['key' => "hourly-{$h}", 'kind' => StayKind::Hourly->value, 'hours' => $h, 'label' => "{$h} hrs"];
            }
        }

        if ($branch->overnight_enabled) {
            $columns[] = ['key' => 'overnight', 'kind' => StayKind::Overnight->value, 'hours' => 0, 'label' => 'Overnight'];
        }

        $types = RoomType::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->orderBy('id')->get();
        $rates = Rate::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->get();
        $discounts = Discount::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->with('rates')->orderBy('name')->get();
        $label = fn (Rate $r): string => $r->stay_kind === StayKind::Overnight ? 'Overnight' : "{$r->hours} hrs";
        $typeNames = $types->pluck('name', 'id');

        return [
            'columns' => $columns,
            'rows' => $types->map(fn (RoomType $t): array => [
                'room_type_id' => $t->id,
                'name' => $t->name,
                'is_active' => $t->is_active,
                'cells' => collect($columns)->mapWithKeys(function (array $c) use ($rates, $t): array {
                    $rate = $rates->first(fn (Rate $r): bool => $r->room_type_id === $t->id && $r->stay_kind->value === $c['kind'] && (int) $r->hours === $c['hours']);

                    return [$c['key'] => $rate === null ? null : ['id' => $rate->id, 'amount' => $rate->amount?->centavos()]];
                })->all(),
                'limited' => $discounts->filter(fn (Discount $d): bool => $d->is_active && ! $d->all_rates)
                    ->map(function (Discount $d) use ($t, $label): ?string {
                        $options = $d->rates->filter(fn (Rate $r): bool => $r->room_type_id === $t->id)->map($label)->values()->all();

                        return $options === [] ? null : $d->name.' · '.implode(', ', $options);
                    })->filter()->values()->all(),
            ])->values()->all(),
            'extension' => ExtensionRate::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->orderBy('hours')->get()
                ->map(fn (ExtensionRate $e): array => ['id' => $e->id, 'hours' => (int) $e->hours, 'amount' => $e->amount?->centavos()])->values()->all(),
            'discounts' => $discounts->map(fn (Discount $d): array => [
                'id' => $d->id,
                'name' => $d->name,
                'kind' => $d->kind->value,
                'value' => $d->value,
                'all_rates' => $d->all_rates,
                'kiosk_enabled' => $d->kiosk_enabled,
                'is_vat_exempt' => $d->is_vat_exempt,
                'is_active' => $d->is_active,
                'rate_ids' => $d->rates->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            ])->values()->all(),
            'rate_options' => $rates->sortBy(fn (Rate $r): string => sprintf('%08d-%d-%02d', $r->room_type_id, $r->stay_kind === StayKind::Overnight ? 1 : 0, $r->hours))
                ->map(fn (Rate $r): array => ['value' => $r->id, 'label' => ($typeNames[$r->room_type_id] ?? '').' '.$label($r)])->values()->all(),
            'extension_rule' => $branch->extension_rule->value,
        ];
    }

    /**
     * Branch settings (46): every group, the shifts and the reason lists.
     *
     * @return array<string, mixed>
     */
    public function settings(Branch $branch): array
    {
        $lists = [
            ['key' => SaveListReason::TRANSFER, 'label' => 'Transfer reasons', 'rows' => $this->listRows(TransferReason::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->orderBy('name')->get())],
            ['key' => SaveListReason::EXPENSE, 'label' => 'Expense categories', 'rows' => $this->listRows(ExpenseCategory::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->orderBy('name')->get())],
        ];
        $reasons = Reason::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->orderBy('name')->get()->groupBy(fn (Reason $r): string => $r->kind->value);

        foreach (ReasonKind::cases() as $kind) {
            $lists[] = ['key' => $kind->value, 'label' => $kind->label().' reasons', 'rows' => $this->listRows($reasons[$kind->value] ?? collect())];
        }

        $money = fn (?Money $m): int => $m?->centavos() ?? 0;

        return [
            'branch' => [
                'name' => $branch->name,
                'hourly_enabled' => $branch->hourly_enabled,
                'overnight_enabled' => $branch->overnight_enabled,
                'overnight_checkin_time' => substr($branch->overnight_checkin_time, 0, 5),
                'overnight_checkout_time' => substr($branch->overnight_checkout_time, 0, 5),
                'long_stay_enabled' => $branch->long_stay_enabled,
                'long_stay_max_days' => (int) $branch->long_stay_max_days,
                'extension_rule' => $branch->extension_rule->value,
                'extension_cycle_hours' => (int) $branch->extension_cycle_hours,
                'extension_per_hour_amount' => $money($branch->extension_per_hour_amount),
                'due_soon_minutes' => (int) $branch->due_soon_minutes,
                'deposit_enabled' => $branch->deposit_enabled,
                'deposit_name' => $branch->deposit_name,
                'deposit_amount' => $money($branch->deposit_amount),
                'lost_key_amount' => $money($branch->lost_key_amount),
                'lost_remote_amount' => $money($branch->lost_remote_amount),
                'payment_methods' => $branch->payment_methods->map(fn (PaymentMethod $m): string => $m->value)->values()->all(),
                'max_frontdesks_per_shift' => (int) $branch->max_frontdesks_per_shift,
                'shift_overdue_warn_minutes' => (int) $branch->shift_overdue_warn_minutes,
                'shift_stale_block_minutes' => (int) $branch->shift_stale_block_minutes,
                'kiosk_enabled' => $branch->kiosk_enabled,
                'kiosk_hold_minutes' => (int) $branch->kiosk_hold_minutes,
                'reservations_enabled' => $branch->reservations_enabled,
                'reservation_release_minutes' => (int) $branch->reservation_release_minutes,
                'pos_enabled' => $branch->pos_enabled,
                'housekeeping_enabled' => $branch->housekeeping_enabled,
                'cleaning_deadline_minutes' => (int) $branch->cleaning_deadline_minutes,
                'cleaning_target_minutes' => (int) $branch->cleaning_target_minutes,
                'cleaning_urgent_minutes' => (int) $branch->cleaning_urgent_minutes,
                'vat_enabled' => $branch->vat_enabled,
                'vat_rate_percent' => (int) $branch->vat_rate_percent,
                'tin' => $branch->tin,
                'registered_name' => $branch->registered_name,
                'slip_header' => $branch->slip_header,
                'address' => $branch->address,
                'house_rules' => $branch->house_rules,
            ],
            'methods' => array_map(
                fn (PaymentMethod $m): array => ['value' => $m->value, 'label' => $m->label()],
                [PaymentMethod::Cash, PaymentMethod::Gcash, PaymentMethod::Card],
            ),
            'active_discounts' => Discount::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->where('is_active', true)->count(),
            'shifts' => ShiftDefinition::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->orderBy('sort_order')->get()
                ->map(fn (ShiftDefinition $d): array => [
                    'id' => $d->id, 'name' => $d->name, 'starts_at' => substr((string) $d->starts_at, 0, 5), 'ends_at' => substr((string) $d->ends_at, 0, 5), 'is_active' => $d->is_active,
                ])->values()->all(),
            'lists' => $lists,
        ];
    }

    /**
     * Users (47): this branch's accounts only, never an owner.
     *
     * @return array<string, mixed>
     */
    public function users(Branch $branch): array
    {
        $users = User::query()->where('branch_id', $branch->id)->where('role', '!=', Role::Owner)
            ->with(['floors' => fn ($q) => $q->withoutGlobalScopes()->orderBy('number')])->orderByDesc('is_active')->orderBy('name')->get();

        return [
            'users' => $users->map(fn (User $u): array => [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->username,
                'role' => $u->role->value,
                'role_label' => $u->role->label(),
                'pin' => in_array($u->role, self::PIN_ROLES, true) ? $u->pin_hash !== null : null,
                'floor_ids' => $u->floors->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                'floors' => $u->floors->pluck('label')->values()->all(),
                'is_active' => $u->is_active,
            ])->values()->all(),
            'roles' => array_map(fn (Role $r): array => ['value' => $r->value, 'label' => $r->label()], SaveUser::ROLES),
            'floors' => Floor::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->orderBy('number')->get()
                ->map(fn (Floor $f): array => ['value' => $f->id, 'label' => $f->label])->values()->all(),
        ];
    }

    /**
     * Products (48) of one kind, with the count of each kind for the tabs.
     *
     * @return array<string, mixed>
     */
    public function products(Branch $branch, ProductKind $kind): array
    {
        $all = Product::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->orderBy('category')->orderBy('name')->get();

        return [
            'kind' => $kind->value,
            'counts' => collect(ProductKind::cases())->mapWithKeys(fn (ProductKind $k): array => [$k->value => $all->where('kind', $k)->count()])->all(),
            'products' => $all->where('kind', $kind)->map(fn (Product $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'kind' => $p->kind->value,
                'category' => $p->category,
                'report_group' => $p->report_group->value,
                'outlet' => $p->outlet->value,
                'price_amount' => $p->price_amount?->centavos() ?? 0,
                'tracks_stock' => $p->tracks_stock,
                'stock_on_hand' => $p->stock_on_hand,
                'low_stock_at' => $p->low_stock_at,
                'is_active' => $p->is_active,
            ])->values()->all(),
            'categories' => $all->pluck('category')->unique()->sort()->values()->all(),
        ];
    }

    /**
     * @param  iterable<Model>  $rows
     * @return list<array{id: int, name: string, needs_note: bool, needs_manager: bool, is_active: bool}>
     */
    private function listRows(iterable $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row->getKey(),
                'name' => (string) $row->getAttribute('name'),
                'needs_note' => (bool) $row->getAttribute('needs_note'),
                'needs_manager' => (bool) $row->getAttribute('needs_manager'),
                'is_active' => (bool) $row->getAttribute('is_active'),
            ];
        }

        return $out;
    }
}
