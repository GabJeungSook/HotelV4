<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\StayKind;
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
use App\Models\TransferReason;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

it('builds Hotel Dalisay GenSan with 46 rooms on 3 floors', function (): void {
    $branch = Branch::query()->sole();

    expect($branch->name)->toBe('Hotel Dalisay GenSan')
        ->and($branch->code_prefix)->toBe('DGS')
        ->and($branch->deposit_amount?->centavos())->toBe(20000)
        ->and($branch->payment_methods?->map->value->all())->toBe(['cash', 'gcash'])
        ->and($branch->overnight_enabled && $branch->kiosk_enabled && $branch->reservations_enabled)->toBeTrue()
        ->and(Floor::query()->count())->toBe(3)
        ->and(Room::query()->count())->toBe(46);

    $perType = Room::query()->with('roomType')->get()->countBy(fn (Room $r): string => $r->roomType->name)->all();
    expect($perType)->toBe(['Standard' => 24, 'Deluxe' => 18, 'Suite' => 4])
        ->and(Room::query()->where('number', '3A')->exists())->toBeTrue();
});

it('seeds the rates with the Suite 18-hour rate missing', function (): void {
    $suite = RoomType::query()->where('name', 'Suite')->sole();

    expect(Rate::query()->count())->toBe(14)
        ->and(Rate::query()->where('room_type_id', $suite->id)->where('hours', 18)->exists())->toBeFalse()
        ->and(Rate::query()->whereBelongsTo(RoomType::query()->where('name', 'Deluxe')->sole())->where('hours', 12)->sole()->amount?->centavos())->toBe(65000)
        ->and(ExtensionRate::query()->orderBy('hours')->pluck('hours')->all())->toBe([3, 6, 12, 24]);
});

it('seeds the discounts, lists, reasons and products', function (): void {
    $promo = Discount::query()->where('name', 'Promo')->sole();

    expect($promo->rates()->pluck('stay_kind')->unique()->all())->toBe([StayKind::Hourly])
        ->and($promo->rates()->pluck('hours')->unique()->all())->toBe([6])
        ->and($promo->kiosk_enabled)->toBeTrue()
        ->and(Discount::query()->where('is_vat_exempt', true)->pluck('name')->sort()->values()->all())->toBe(['PWD', 'Senior citizen'])
        ->and(TransferReason::query()->count())->toBe(6)
        ->and(TransferReason::query()->where('name', 'Aircon not cooling')->exists())->toBeTrue()
        ->and(ExpenseCategory::query()->count())->toBe(8)
        ->and(Reason::query()->count())->toBe(24)
        ->and(Product::query()->get()->countBy(fn (Product $p): string => $p->kind->value)->all())
        ->toBe(['food_drink' => 17, 'amenity' => 3, 'damage' => 3]);
});

it('seeds 1 owner and 11 branch users with the right roles', function (): void {
    $roles = User::query()->get()->countBy(fn (User $u): string => $u->role->value)->all();

    expect(User::query()->count())->toBe(12)
        ->and($roles)->toBe(['owner' => 1, 'admin' => 1, 'supervisor' => 1, 'frontdesk' => 4, 'backoffice' => 1, 'housekeeper' => 3, 'kiosk' => 1])
        ->and(User::query()->where('username', 'carol.villanueva')->sole()->role)->toBe(Role::Supervisor)
        ->and(User::query()->where('username', 'dodong.lumayag')->sole()->floors()->pluck('label')->sort()->values()->all())->toBe(['1F', '2F'])
        ->and(ShiftDefinition::query()->orderBy('sort_order')->pluck('name')->all())->toBe(['AM', 'PM']);
});
