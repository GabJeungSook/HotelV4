<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Owner\CreateBranch;
use App\Enums\BranchStatus;
use App\Enums\CashMovementKind;
use App\Enums\DepositType;
use App\Enums\Role;
use App\Enums\ShiftStatus;
use App\Enums\StayState;
use App\Models\Branch;
use App\Models\CashCount;
use App\Models\CashMovement;
use App\Models\ExpenseCategory;
use App\Models\Floor;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\ShiftDefinition;
use App\Models\ShiftForwardedStay;
use App\Models\ShiftMember;
use App\Models\ShiftSession;
use App\Models\Stay;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\Ledger;
use Database\Seeders\Demo\ShiftTotals;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * A second, fully-populated branch — "Hotel Dalisay Koronadal" (KOR) — so the owner's
 * multi-branch screens (dashboard 60, summary 39, branch picker) show real figures.
 * Local only, additive (never wipes DGS). All names and figures are made up.
 *
 * Six closed AM shifts (10–15 Nov 2026), each frozen through the real
 * ReportService::computeShiftTotals, threading the still-in-house guests day to day,
 * then one open AM shift for 16 Nov so the branch shows live occupancy today.
 */
class SecondBranchSeeder extends Seeder
{
    private const PREFIX = 'KOR';

    /** The eight rooms the daily walk-ins reuse (all freed each morning first). */
    private const DAY_ROOMS = ['101', '102', '201', '202', '301', '103', '203', '104'];

    /** Options per daily room: 3 hourly (checked out before close) + 5 kept in house overnight. */
    private const DAY_OPTIONS = [6, 12, 'overnight', 'overnight', 24, 6, 'overnight', 'overnight'];

    private Ledger $ledger;

    /** @var array<string, User> */
    private array $staff = [];

    /** @var array<string, Stay> stays in house, by room number */
    private array $occupied = [];

    public function run(): void
    {
        if (Branch::query()->withoutGlobalScopes()->where('code_prefix', self::PREFIX)->exists()) {
            $this->command?->warn('KOR branch already exists — skipping.');

            return;
        }

        $owner = User::query()->where('role', Role::Owner)->where('username', 'ramon.alvarez')->firstOrFail();
        $source = DemoBranch::get();

        ['branch' => $branch] = app(CreateBranch::class)->handle($owner, [
            'name' => 'Hotel Dalisay Koronadal',
            'address' => 'National Highway, Koronadal City',
            'code_prefix' => self::PREFIX,
            'admin_name' => 'Marisol Fuentes',
            'admin_username' => 'marisol.fuentes',
            'copy_from_id' => $source->id,
        ]);

        $branch->update([
            'status' => BranchStatus::Active,
            'payment_methods' => ['cash', 'gcash'],
            'next_stay_number' => 1,
            'next_pos_number' => 1,
            'next_z_number' => 1,
        ]);
        $branch->refresh();

        $this->buildRooms($branch);
        Product::query()->where('branch_id', $branch->id)->update(['stock_on_hand' => 200]);
        $this->buildStaff($branch);

        $this->ledger = new Ledger($branch);

        $days = ['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-14', '2026-11-15'];
        $forwardedIn = collect();
        $grandBefore = 0;
        foreach ($days as $i => $date) {
            $shift = $this->openShift('AM', $date, Ledger::at('08:00', $date), $this->staff['cora'], 500000, $this->staff['elias']);
            $this->playDay($date, $i);
            $counted = $this->expectedCash($shift);
            $forwardedIn = $this->freeze($shift, Ledger::at('20:00', $date), $this->staff['cora'], $counted, $forwardedIn, grandTotalBefore: $grandBefore);
            $grandBefore = $shift->refresh()->grand_total_after?->centavos() ?? 0;
        }

        $this->openToday();
    }

    /** 3 floors, 32 rooms, mapped to the copied room types by name. */
    private function buildRooms(Branch $branch): void
    {
        $types = RoomType::query()->where('branch_id', $branch->id)->get()->keyBy('name');
        $floors = [
            ['number' => 1, 'label' => '1F', 'type' => 'Standard', 'numbers' => ['101', '102', '103', '104', '105', '106', '107', '108', '109', '110', '111', '112', '113', '114', '115', '116', '117', '118']],
            ['number' => 2, 'label' => '2F', 'type' => 'Deluxe', 'numbers' => ['201', '202', '203', '204', '205', '206', '207', '208', '209', '210']],
            ['number' => 3, 'label' => '3F', 'type' => 'Suite', 'numbers' => ['301', '302', '3A', '3B']],
        ];

        foreach ($floors as $floor) {
            $floorRow = Floor::query()->create(['branch_id' => $branch->id, 'number' => $floor['number'], 'label' => $floor['label']]);
            $typeId = $types[$floor['type']]->id;
            foreach ($floor['numbers'] as $number) {
                Room::query()->create(['branch_id' => $branch->id, 'floor_id' => $floorRow->id, 'room_type_id' => $typeId, 'number' => $number]);
            }
        }
    }

    private function buildStaff(Branch $branch): void
    {
        $roster = [
            'cora' => ['Cora Diaz', 'cora.diaz', Role::Frontdesk, '1234'],
            'elias' => ['Elias Tan', 'elias.tan', Role::Frontdesk, '1234'],
            'hk' => ['Noel Rama', 'noel.rama', Role::Housekeeper, null],
        ];

        foreach ($roster as $key => [$name, $username, $role, $pin]) {
            $this->staff[$key] = User::query()->create([
                'branch_id' => $branch->id,
                'name' => $name,
                'username' => $username,
                'password' => 'password',
                'pin_hash' => $pin,
                'role' => $role,
                'is_active' => true,
            ]);
        }
    }

    /** One business day of walk-ins, POS, an extension, an expense and part check-out. */
    private function playDay(string $date, int $dayIndex): void
    {
        $l = $this->ledger;
        $by = $dayIndex % 2 === 0 ? $this->staff['cora'] : $this->staff['elias'];
        $hk = $this->staff['hk'];
        $t = fn (string $time): CarbonImmutable => Ledger::at($time, $date);

        // Free + clean every room carried in from the night before, before any walk-in reuses it.
        $i = 0;
        foreach (array_keys($this->occupied) as $room) {
            $room = (string) $room;
            $co = $t(sprintf('08:%02d', 1 + $i * 2));
            $l->checkOut($this->stay($room), $co, $by);
            $l->clean($room, $co->addMinute(), $co->addMinutes(2), $hk);
            $this->release($room);
            $i++;
        }

        // Eight walk-ins across the day into the shared room set.
        $names = $this->guestNames($dayIndex);
        $times = ['08:30', '09:20', '10:35', '11:50', '13:05', '14:20', '16:40', '18:15'];
        foreach (self::DAY_ROOMS as $k => $room) {
            $option = self::DAY_OPTIONS[$k];
            $discount = $k === 0 ? ($dayIndex % 2 === 0 ? 'Promo' : 'Senior citizen') : null;
            $this->occupied[$room] = $l->checkIn($room, $names[$k], $option, $t($times[$k]), $by, $discount);
        }

        // A few POS sales: one walk-in and two room charges.
        $l->charge(null, [['3-in-1 coffee', 1], ['Biscuits', 1]], $t('09:05'), $by);
        $l->charge($this->stay('201'), [['Beer 330 ml', 2]], $t('12:30'), $by);
        $l->charge($this->stay('202'), [['Cup noodles', 1], ['Bottled water 500 ml', 1]], $t('15:10'), $by, paid: false);

        // One extension inside the cycle on the 12-hour stay.
        $l->extend($this->stay('102'), 6, $t('14:45'), $by);

        // One small expense.
        $this->cashOut($l->shift, CashMovementKind::Expense, 15000, 'Light bulbs and cleaning supplies', $t('13:15'), $by, 'Repairs and supplies');

        // Check out + clean the three hourly stays before close; the five overnight/24h stays stay in house.
        foreach (['101' => '16:05', '102' => '17:10', '103' => '19:05'] as $room => $time) {
            $room = (string) $room;
            $co = $t($time);
            $l->checkOut($this->stay($room), $co, $by);
            $l->clean($room, $co->addMinutes(5), $co->addMinutes(20), $hk);
            $this->release($room);
        }
    }

    /** The open AM shift of 16 Nov: live occupancy today on top of last night's in-house guests. */
    private function openToday(): void
    {
        $l = $this->ledger;
        $date = '2026-11-16';
        $by = $this->staff['cora'];
        $t = fn (string $time): CarbonImmutable => Ledger::at($time, $date);

        $last = ShiftSession::query()->where('branch_id', $l->branch->id)->where('status', ShiftStatus::Closed)->latest('closed_at')->firstOrFail();
        $this->openShift('AM', $date, $t('08:00'), $by, $last->counted_cash?->centavos() ?? 500000, $this->staff['elias']);

        foreach ([['08:25', '205', 'Teresita Alcantara', 6], ['09:40', '206', 'Rolando Mercado', 12], ['11:15', '105', 'Jocelyn Fuentes', 'overnight'], ['12:50', '106', 'Benigno Salcedo', 24]] as [$time, $room, $guest, $option]) {
            $this->occupied[$room] = $l->checkIn($room, $guest, $option, $t($time), $by);
        }

        $l->charge(null, [['Energy drink', 1]], $t('09:10'), $by);
        $l->charge($this->stay('205'), [['Chips', 2]], $t('10:20'), $by);
    }

    private function stay(string $room): Stay
    {
        return $this->occupied[$room] ?? throw new \LogicException("No guest in room {$room}.");
    }

    private function release(string $room): void
    {
        unset($this->occupied[$room]);
    }

    /** @return list<string> eight guest names for the day */
    private function guestNames(int $dayIndex): array
    {
        $pool = [
            'Ernesto Villamor', 'Luzviminda Paras', 'Rodel Cabrera', 'Marilou Deguzman', 'Benjie Salvador', 'Agnes Tolentino', 'Dioscoro Rabe', 'Perla Mangubat',
            'Felipe Arroyo', 'Normita Suyat', 'Alfredo Baclig', 'Editha Pascual', 'Rogelio Mamaril', 'Cristina Dizon', 'Manuel Ocampo', 'Teresita Rubio',
            'Bayani Fajardo', 'Loida Macaraeg', 'Prospero Ventura', 'Nenita Galang', 'Orlando Cuarto', 'Milagros Sibayan', 'Ignacio Punzalan', 'Herminia Lazaro',
            'Rustico Balmes', 'Josefa Trinidad', 'Domingo Entera', 'Pacita Almonte', 'Virgilio Rosales', 'Lourdes Baguio', 'Anselmo Quinto', 'Remedios Fabro',
            'Gregorio Payumo', 'Estrella Mabini', 'Teodulo Sarmiento', 'Corazon Abueva', 'Nicanor Dacanay', 'Luisa Panganiban', 'Leopoldo Españo', 'Flordeliza Cueto',
            'Macario Lagdameo', 'Benilda Rosario', 'Crispin Montalbo', 'Araceli Villena', 'Teofilo Mangahas', 'Soledad Jimenez', 'Fortunato Gaton', 'Imelda Bacsain',
        ];
        $offset = ($dayIndex * 8) % count($pool);

        return array_slice(array_merge($pool, $pool), $offset, 8);
    }

    private function openShift(string $period, string $date, CarbonImmutable $at, User $by, int $openingCash, ?User $second): ShiftSession
    {
        $branch = $this->ledger->branch;
        $shift = ShiftSession::query()->create([
            'branch_id' => $branch->id,
            'shift_definition_id' => ShiftDefinition::query()->where('branch_id', $branch->id)->where('name', $period)->value('id'),
            'period_name' => $period,
            'business_date' => $date,
            'status' => ShiftStatus::Open,
            'opened_at' => $at,
            'opened_by' => $by->id,
            'opening_cash' => $openingCash,
        ]);

        ShiftMember::query()->create(['shift_session_id' => $shift->id, 'user_id' => $by->id, 'joined_at' => $at]);
        if ($second !== null) {
            ShiftMember::query()->create(['shift_session_id' => $shift->id, 'user_id' => $second->id, 'joined_at' => $at->addMinutes(4)]);
        }

        $this->ledger->shift = $shift;

        return $shift;
    }

    private function cashOut(ShiftSession $shift, CashMovementKind $kind, int $amount, string $note, CarbonImmutable $at, User $by, ?string $category = null): CashMovement
    {
        return CashMovement::query()->create([
            'branch_id' => $shift->branch_id, 'shift_session_id' => $shift->id, 'kind' => $kind,
            'expense_category_id' => $category === null ? null : ExpenseCategory::query()->where('branch_id', $shift->branch_id)->where('name', $category)->value('id'),
            'amount' => $amount, 'note' => $note, 'created_by' => $by->id, 'occurred_at' => $at, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    /**
     * Freeze a shift as in DATABASE_SCHEMA §8.10: count, expected, variance, forwarded stays,
     * Z number, grand totals and the frozen totals (mirrors DemoShiftSeeder::freeze).
     *
     * @param  Collection<int, ShiftForwardedStay>  $forwardedIn
     * @return Collection<int, ShiftForwardedStay>
     */
    private function freeze(ShiftSession $shift, CarbonImmutable $at, User $by, int $counted, Collection $forwardedIn, int $grandTotalBefore = 0): Collection
    {
        $branch = $this->ledger->branch->refresh();
        $expected = $this->expectedCash($shift);

        CashCount::query()->create([
            'shift_session_id' => $shift->id, 'denominations' => $this->denominations($counted), 'total_cash' => $counted,
            'counted_by' => $by->id, 'created_at' => $at->subMinutes(2),
        ]);

        $forwardedOut = Stay::query()->where('branch_id', $branch->id)->where('state', StayState::CheckedIn)->orderBy('id')->get()
            ->map(fn (Stay $stay): ShiftForwardedStay => ShiftForwardedStay::query()->create([
                'shift_session_id' => $shift->id, 'stay_id' => $stay->id, 'room_id' => $stay->room_id,
                'guest_name' => $stay->guest_name, 'expected_checkout_at' => $stay->expected_checkout_at,
                'unpaid_amount' => (int) $this->ledger->outstanding($stay)->sum('due'),
                'key_deposit_held' => $this->ledger->keyDepositHeld($stay),
                'guest_deposit_held' => $this->ledger->depositHeld($stay, DepositType::Guest),
                'created_at' => $at,
            ]));

        $zNo = $branch->next_z_number;
        $branch->update(['next_z_number' => $zNo + 1]);

        $shift->update([
            'status' => ShiftStatus::Closed, 'closed_at' => $at, 'closed_by' => $by->id,
            'expected_cash' => $expected, 'counted_cash' => $counted, 'variance_amount' => $counted - $expected,
            'z_no' => $zNo,
        ]);

        $totals = ShiftTotals::build($shift->refresh(), $forwardedIn, $forwardedOut);
        $shift->update([
            'totals' => $totals,
            'grand_total_before' => $grandTotalBefore,
            'grand_total_after' => $grandTotalBefore + $totals['sales']['total'],
        ]);

        return $forwardedOut;
    }

    /** TRD §4.5: opening + cash payments − expenses − remittances + shortage payments (voided rows count 0). */
    private function expectedCash(ShiftSession $shift): int
    {
        $cash = Payment::query()->where('shift_session_id', $shift->id)->where('method', 'cash')->get()
            ->sum(fn (Payment $p): int => $p->amount?->centavos() ?? 0);
        $movements = CashMovement::query()->where('shift_session_id', $shift->id)->whereNull('voided_at')->get()
            ->sum(fn (CashMovement $m): int => ($m->kind === CashMovementKind::ShortagePayment ? 1 : -1) * ($m->amount?->centavos() ?? 0));

        return ($shift->opening_cash?->centavos() ?? 0) + (int) $cash + (int) $movements;
    }

    /** @return array<int|string, int> pieces per note in centavos, coins as an amount */
    private function denominations(int $total): array
    {
        $pieces = [];
        foreach ([100000, 50000, 20000, 10000, 5000, 2000] as $note) {
            $pieces[(string) $note] = intdiv($total, $note);
            $total %= $note;
        }
        $pieces['coins'] = $total;

        return $pieces;
    }
}
