<?php

declare(strict_types=1);

use App\Actions\Owner\CreateBranch;
use App\Enums\BranchStatus;
use App\Enums\Role;
use App\Exceptions\ActionRefused;
use App\Models\AuditLog;
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
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Hotel;

/*
| CreateBranch (56, APP_FLOW §4.20, TRD §4.9 and §10 "Branches and permissions"): copies rates,
| settings, products and lists, never rooms, floors, users, stays or money; validation of the
| unique name, prefix and username.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 01:00:00');
    $this->owner = Hotel::user('ramon.alvarez');
    $this->source = Hotel::branch();
});

function newBranchForm(array $overrides = []): array
{
    return [
        'name' => 'Dalisay Koronadal',
        'address' => 'Gensan Drive, Koronadal City',
        'code_prefix' => 'dkr',
        'admin_name' => 'Noel Fabrigas',
        'admin_username' => 'noel.fabrigas',
        ...$overrides,
    ];
}

/** @return array<string, int> */
function countsFor(int $branchId): array
{
    $count = fn (string $model): int => $model::query()->withoutGlobalScopes()->where('branch_id', $branchId)->count();

    return [
        'shift_definitions' => $count(ShiftDefinition::class),
        'room_types' => $count(RoomType::class),
        'rates' => $count(Rate::class),
        'extension_rates' => $count(ExtensionRate::class),
        'discounts' => $count(Discount::class),
        'products' => $count(Product::class),
        'transfer_reasons' => $count(TransferReason::class),
        'expense_categories' => $count(ExpenseCategory::class),
        'reasons' => $count(Reason::class),
    ];
}

it('creates a branch copying rates, settings, products and lists but not rooms or users', function (): void {
    $this->actingAs($this->owner)
        ->post('/owner/branches', newBranchForm(['copy_from_id' => $this->source->id]))
        ->assertRedirect('/owner/branches')
        ->assertSessionHasNoErrors();

    $branch = Branch::query()->where('code_prefix', 'DKR')->sole();
    $source = $this->source->refresh();

    // The branch: setting up, its own identity and counters, the source's settings.
    expect($branch->status)->toBe(BranchStatus::SettingUp)
        ->and($branch->name)->toBe('Dalisay Koronadal')
        ->and($branch->address)->toBe('Gensan Drive, Koronadal City')
        ->and($branch->next_stay_number)->toBe(1)
        ->and($branch->next_z_number)->toBe(1)
        ->and($branch->deposit_amount->centavos())->toBe($source->deposit_amount->centavos())
        ->and($branch->payment_methods->map->value->all())->toBe($source->payment_methods->map->value->all())
        ->and($branch->overnight_enabled)->toBe($source->overnight_enabled)
        ->and($branch->kiosk_enabled)->toBe($source->kiosk_enabled)
        ->and($branch->kiosk_hold_minutes)->toBe($source->kiosk_hold_minutes)
        ->and($branch->cleaning_target_minutes)->toBe($source->cleaning_target_minutes)
        ->and($branch->house_rules)->toBe($source->house_rules)
        ->and($branch->vat_enabled)->toBeFalse()
        ->and($branch->tin)->toBeNull();

    // Every list copied row for row.
    $expected = countsFor($source->id);
    expect(countsFor($branch->id))->toBe($expected)
        ->and($expected['rates'])->toBeGreaterThan(0)
        ->and($expected['products'])->toBeGreaterThan(0)
        ->and($expected['reasons'])->toBeGreaterThan(0);

    // Rates point at the new branch's room types, with the same amounts.
    $rateKey = fn (Rate $r): string => RoomType::query()->withoutGlobalScopes()->findOrFail($r->room_type_id)->name.'|'.$r->stay_kind->value.'|'.$r->hours.'|'.$r->amount->centavos();
    $newRates = Rate::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->get();
    expect($newRates->map($rateKey)->sort()->values()->all())
        ->toBe(Rate::query()->withoutGlobalScopes()->where('branch_id', $source->id)->get()->map($rateKey)->sort()->values()->all())
        ->and(RoomType::query()->withoutGlobalScopes()->whereIn('id', $newRates->pluck('room_type_id'))->pluck('branch_id')->unique()->all())->toBe([$branch->id]);

    // A discount limited to some rates is limited to the copies of those rates.
    $promo = Discount::query()->withoutGlobalScopes()->where('branch_id', $source->id)->where('all_rates', false)->firstOrFail();
    $copy = Discount::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->where('name', $promo->name)->sole();
    $copyRates = $copy->rates()->withoutGlobalScopes()->get();
    expect($copyRates)->toHaveCount($promo->rates()->withoutGlobalScopes()->count())
        ->and($copyRates->pluck('branch_id')->unique()->all())->toBe([$branch->id]);

    // Products with prices, stock at 0.
    $products = Product::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->get();
    expect($products->pluck('stock_on_hand')->unique()->all())->toBe([0])
        ->and(Product::query()->withoutGlobalScopes()->where('branch_id', $source->id)->where('stock_on_hand', '>', 0)->exists())->toBeTrue()
        ->and($products->firstWhere('name', 'Chips')?->price_amount->centavos())
        ->toBe(Product::query()->withoutGlobalScopes()->where('branch_id', $source->id)->where('name', 'Chips')->first()?->price_amount->centavos());

    // Never rooms, floors, stays or staff: only the new admin.
    expect(Room::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->count())->toBe(0)
        ->and(Floor::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->count())->toBe(0)
        ->and(Stay::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->count())->toBe(0);

    $users = User::query()->where('branch_id', $branch->id)->get();
    expect($users)->toHaveCount(1);
    $admin = $users->sole();
    expect($admin->role)->toBe(Role::Admin)
        ->and($admin->username)->toBe('noel.fabrigas')
        ->and($admin->pin_hash)->toBeNull();

    $log = AuditLog::query()->where('action', 'branch.created')->sole();
    expect($log->branch_id)->toBe($branch->id)
        ->and($log->actor_id)->toBe($this->owner->id)
        ->and($log->after_json['copied_from'])->toBe($source->id)
        ->and($log->after_json['copied']['rates'])->toBe($expected['rates']);
});

it('shows the first admin a temporary password once, which signs them in and asks for a PIN', function (): void {
    $response = $this->actingAs($this->owner)->post('/owner/branches', newBranchForm());
    $response->assertRedirect('/owner/branches');

    $created = session('inertia.flash_data.created');

    $admin = User::query()->where('username', 'noel.fabrigas')->sole();
    expect($created)->not->toBeNull()
        ->and($created['username'])->toBe('noel.fabrigas')
        ->and(Hash::check($created['password'], $admin->password))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'branch.created')->sole()->after_json)->not->toHaveKey('password');

    auth()->logout();
    $this->post('/login', ['username' => 'noel.fabrigas', 'password' => $created['password']])->assertRedirect();
    $this->get('/home')->assertRedirect('/password/change');
});

it('starts from the column defaults when nothing is copied', function (): void {
    $result = app(CreateBranch::class)->handle($this->owner, [
        'name' => 'Dalisay Polomolok', 'address' => 'Polomolok', 'code_prefix' => 'DPL',
        'admin_name' => 'Ana Reyes', 'admin_username' => 'ana.reyes', 'copy_from_id' => null,
    ]);

    $branch = $result['branch'];
    expect($branch->status)->toBe(BranchStatus::SettingUp)
        ->and($branch->deposit_amount->centavos())->toBe(20000)
        ->and($branch->kiosk_enabled)->toBeFalse()
        ->and($branch->payment_methods->map->value->all())->toBe(['cash'])
        ->and(array_sum(countsFor($branch->id)))->toBe(0)
        ->and(strlen($result['password']))->toBe(10);
});

it('refuses a prefix another branch uses, with the branch name', function (): void {
    $this->actingAs($this->owner)
        ->from('/owner/branches/create')
        ->post('/owner/branches', newBranchForm(['code_prefix' => 'dgs']))
        ->assertRedirect('/owner/branches/create')
        ->assertSessionHasErrors(['code_prefix' => 'DGS is already used by Hotel Dalisay GenSan.']);

    expect(Branch::query()->count())->toBe(1)->and(User::query()->where('username', 'noel.fabrigas')->exists())->toBeFalse();
});

it('refuses a duplicate branch name and a taken username', function (): void {
    $this->actingAs($this->owner)
        ->post('/owner/branches', newBranchForm(['name' => 'Hotel Dalisay GenSan']))
        ->assertSessionHasErrors(['name']);

    $this->actingAs($this->owner)
        ->post('/owner/branches', newBranchForm(['admin_username' => 'ramil.ortega']))
        ->assertSessionHasErrors(['admin_username' => 'This username is taken.']);

    $this->actingAs($this->owner)
        ->post('/owner/branches', newBranchForm(['code_prefix' => 'DK']))
        ->assertSessionHasErrors(['code_prefix']);

    expect(Branch::query()->count())->toBe(1)->and(AuditLog::query()->where('action', 'branch.created')->count())->toBe(0);
});

it('rolls everything back when the admin username is taken at the last moment', function (): void {
    expect(fn () => app(CreateBranch::class)->handle($this->owner, [
        'name' => 'Dalisay Polomolok', 'address' => 'Polomolok', 'code_prefix' => 'DPL',
        'admin_name' => 'Ramil Again', 'admin_username' => 'ramil.ortega', 'copy_from_id' => $this->source->id,
    ]))->toThrow(ActionRefused::class, 'The username ramil.ortega is taken.');

    expect(Branch::query()->count())->toBe(1)
        ->and(Rate::query()->withoutGlobalScopes()->count())->toBe(Rate::query()->withoutGlobalScopes()->where('branch_id', $this->source->id)->count());
});

it('shows the add-branch form with the branches to copy from', function (): void {
    $this->actingAs($this->owner)->get('/owner/branches/create')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Owner/BranchForm')
        ->where('sources', [['value' => $this->source->id, 'label' => 'Hotel Dalisay GenSan']]));
});
