<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ApprovalAction;
use App\Enums\CashMovementKind;
use App\Enums\DepositType;
use App\Enums\PaymentKind;
use App\Enums\PrintDocument;
use App\Enums\ReasonKind;
use App\Enums\ShiftStatus;
use App\Enums\StayKind;
use App\Enums\StaySource;
use App\Enums\StayState;
use App\Models\Approval;
use App\Models\CashCount;
use App\Models\CashMovement;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\Reason;
use App\Models\RoomType;
use App\Models\ShiftDefinition;
use App\Models\ShiftForwardedStay;
use App\Models\ShiftHandover;
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
 * The AM shift of 11 Nov 2026 exactly as drawn on screens 26, 37, 37b and 37c
 * (Z-0629), so the reports can be compared with the mockup figure by figure.
 * Local only. All names and figures are made up.
 *
 * Replayed in time order:
 *  - PM shift of 10 Nov (Z-0628, Mark Anthony): only the 19 guests it forwards to
 *    the AM shift, two of them with ₱225 unpaid; its grand total after is ₱11,482,340.00.
 *  - AM shift of 11 Nov (Z-0629, Jessa Mae and Rhea): 27 check-ins, 23 check-outs,
 *    41 POS orders, 7 extensions, one transfer (108 → 212), a reservation booked
 *    and cancelled with ₱700 paid ahead and refunded, expenses ₱650 (and a voided
 *    ₱3,000), remittance ₱10,000, counted ₱13,145 against ₱13,195 expected.
 *  - PM shift of 11 Nov: opened by Mark Anthony at the handover with ₱13,145.
 *
 * Decisions (Gab, 12 Nov 2026): totals are stored by kind with discounts as their
 * own lines (Room net of discounts is ₱14,350 on the reports); the ₱700 cancelled
 * stay is a same-shift reservation, so it leaves the roll-forwards alone.
 */
class DemoShiftSeeder extends Seeder
{
    private Ledger $ledger;

    /** @var array<string, User> */
    private array $u = [];

    /** @var array<int|string, Stay> stays by room number while in house (PHP turns "104" into 104) */
    private array $s = [];

    public function run(): void
    {
        $branch = DemoBranch::get();
        $branch->update(['next_stay_number' => 4093, 'next_pos_number' => 8820, 'next_z_number' => 628]);
        $this->ledger = new Ledger($branch);

        foreach (['mark.sumagaysay' => 'M', 'jessa.delacruz' => 'J', 'rhea.bautista' => 'R', 'carol.villanueva' => 'C', 'dodong.lumayag' => 'D', 'joy.pacana' => 'Y'] as $username => $key) {
            $this->u[$key] = User::query()->where('username', $username)->firstOrFail();
        }

        $pm10 = $this->openShift('PM', '2026-11-10', Ledger::at('19:56', '2026-11-10'), $this->u['M'], 500000, null);
        $this->playPreviousShift();
        $this->cashOut($pm10, CashMovementKind::Remittance, $this->expectedCash($pm10) - 500000, 'Remittance to the office', Ledger::at('07:40'), $this->u['M']);
        // Z-0628's grand total after is the 37b "before this shift" figure, ₱11,482,340.00.
        $forwardedToAm = $this->freeze($pm10, Ledger::at('07:58'), $this->u['M'], 500000, collect(), grandTotalAfter: 1148234000);

        $am = $this->openShift('AM', '2026-11-11', Ledger::at('07:58'), $this->u['J'], 500000, $this->u['R']);
        $this->handOver($pm10, $am, $this->u['J'], 500000, Ledger::at('07:58'));
        $this->playAmShift();
        $this->freeze($am, Ledger::at('20:04'), $this->u['J'], 1314500, $forwardedToAm, grandTotalBefore: $pm10->refresh()->grand_total_after?->centavos() ?? 0,
            varianceNote: 'Gave wrong change to Room 203, ₱50.', varianceReason: 'Wrong change given',
            handoverNote: 'Room 117 asked to extend, will pay when he comes down. Aircon tech coming for 108 at 9 PM.');
        $this->ledger->printed(PrintDocument::ShiftSlip, $am->id, $this->u['J'], Ledger::at('20:05'));
        $this->ledger->printed(PrintDocument::ZReport, $am->id, $this->u['J'], Ledger::at('20:06'));

        $pm11 = $this->openShift('PM', '2026-11-11', Ledger::at('20:04'), $this->u['M'], 1314500, null);
        $this->handOver($am, $pm11, $this->u['M'], 1314500, Ledger::at('20:04'));
    }

    /** PM shift of 10 Nov: the 19 guests still in house at 7:58 AM. */
    private function playPreviousShift(): void
    {
        $m = $this->u['M'];
        $ten = '2026-11-10';

        foreach ([
            ['20:10', $ten, '101', 'Arnel Villareal', 'overnight'],
            ['20:25', $ten, '102', 'Josefina Lacson', 12],
            ['20:35', $ten, '201', 'Ricardo Pineda', 'overnight'],
            ['20:50', $ten, '103', 'Melchor Dantes', 'overnight'],
            ['21:15', $ten, '104', 'Bernadette Ong', 12],
            ['21:20', $ten, '204', 'Glenn Tabora', 'overnight'],
            ['21:40', $ten, '105', 'Lorna Bacani', 'overnight'],
            ['21:55', $ten, '109', 'Efren Magbanua', 24],
            ['22:05', $ten, '3B', 'Teodoro Lim', 'overnight'],
            ['22:30', $ten, '106', 'Myrna Estrada', 12],
            ['22:40', $ten, '110', 'Wilfredo Sison', 24],
            ['22:46', $ten, '206', 'Nestor Santos', 12],
            ['23:05', $ten, '107', 'Analyn Corpuz', 'overnight'],
            ['23:20', $ten, '202', 'Sonia Reyes', 24],
            ['23:40', $ten, '208', 'Ramil Gatchalian', 12],
            ['00:15', '2026-11-11', '209', 'Cristina Yap', 'overnight'],
            ['01:30', '2026-11-11', '210', 'Danilo Ferrer', 'overnight'],
            ['02:10', '2026-11-11', '211', 'Herminio Cruz', 12],
            ['06:45', '2026-11-11', '3A', 'Victoria Uy', 12],
        ] as [$time, $date, $room, $guest, $option]) {
            $at = Ledger::at($time, $date);

            if ($room === '210') {
                $this->ledger->charge($this->stay('104'), [['Beer 330 ml', 1]], Ledger::at('01:05'), $m, paid: false);
            }

            $this->s[$room] = $this->ledger->checkIn($room, $guest, $option, $at, $m);
        }

        $this->ledger->extend($this->stay('102'), 3, Ledger::at('07:10'), $m, paid: false);
    }

    /** AM shift of 11 Nov, in time order. */
    private function playAmShift(): void
    {
        ['J' => $j, 'R' => $r, 'C' => $c, 'D' => $d, 'Y' => $y] = $this->u;
        $l = $this->ledger;
        $t = fn (string $time): CarbonImmutable => Ledger::at($time);
        $in = function (string $time, string $room, string $guest, int|string $option, User $by, ?string $discount = null) use ($l, $t): void {
            $this->s[$room] = $l->checkIn($room, $guest, $option, $t($time), $by, $discount);
        };
        $out = function (string $time, string $room, User $by, array $damages = []) use ($l, $t): void {
            $l->checkOut($this->stay($room), $t($time), $by, $damages);
            unset($this->s[$room]);
        };
        $walkIn = fn (string $time, string $item, User $by) => $l->charge(null, [[$item, 1]], $t($time), $by);
        $sell = fn (string $time, string $room, array $items, User $by, bool $paid = true) => $l->charge($this->stay($room), $items, $t($time), $by, $paid);

        $in('08:05', '111', 'Joel Paderanga', 6, $j);
        $in('08:15', '203', 'Liza Mendoza', 6, $j);
        $sell('08:30', '3B', [['3-in-1 coffee', 1]], $r, false);
        $sell('08:31', '3B', [['Hot chocolate', 2]], $r, false);
        $this->cashOut($l->shift, CashMovementKind::Expense, 25000, 'Drinking water refill · 5 gallons', $t('08:35'), $j, 'Drinking water');
        $in('08:40', '112', 'Marites Gonzales', 6, $r, 'Promo');
        $walkIn('08:50', '3-in-1 coffee', $r);
        $out('09:05', '104', $j);
        $in('09:12', '205', 'Rico Manalastas', 12, $j);
        $in('09:20', '114', 'Bong Alcaraz', 6, $r);
        $walkIn('09:30', 'Biscuits', $r);
        $in('09:40', '214', 'Grace Malinao', 6, $r, 'Promo');
        $reyes = $sell('09:50', '202', [['Cup noodles', 1], ['Bottled water 500 ml', 1]], $r, false);
        $l->pay($this->stay('202'), $t('09:50'), $r, $reyes->folioLines()->where('description', 'Bottled water 500 ml')->pluck('id')->all());
        $in('10:05', '108', 'Aileen Soriano', 12, $j);
        $this->cashOut($l->shift, CashMovementKind::Expense, 10000, 'Tricycle fare · bank errand', $t('10:10'), $r, 'Transport and errands');
        $walkIn('10:15', '3-in-1 coffee', $j);
        $in('10:20', '215', 'Ferdinand Abad', 24, $j);
        $out('10:20', '106', $r);
        $in('10:30', '115', 'Lourdes Navarro', 12, $j, 'Senior citizen');
        $l->extend($this->stay('206'), 12, $t('10:30'), $r);
        $walkIn('10:45', 'Biscuits', $r);
        $in('11:00', '216', 'Paolo Ramos', 6, $r);
        $sell('11:00', '115', [['Extra towel', 1]], $j);
        $sell('11:01', '115', [['Toothbrush kit', 1]], $j);
        $out('11:10', '209', $j);
        $in('11:15', '116', 'Jun Dimaculangan', 6, $r);
        $out('11:15', '105', $r);
        $walkIn('11:20', '3-in-1 coffee', $r);
        $out('11:20', '102', $r);
        $out('11:25', '3B', $j, ['Broken glass']);
        $out('11:30', '201', $r);
        $l->clean('209', $t('11:30'), $t('11:45'), $d);
        $out('11:35', '208', $j);
        $out('11:40', '101', $j);
        $sell('11:40', '205', [['Cup noodles', 2], ['Bottled water 500 ml', 1]], $r, false);
        $l->printed(PrintDocument::CheckinSlip, $this->stay('205')->id, $r, $t('11:41'), reprint: true);
        $out('11:45', '204', $r);
        $walkIn('11:50', 'Biscuits', $j);
        $out('11:50', '103', $j);
        $out('11:58', '107', $r);
        $out('12:00', '210', $j);
        $l->clean('201', $t('12:00'), $t('12:20'), $d);
        $sell('12:00', '116', [['Chips', 1]], $r, false);
        $sell('12:10', '3A', [['Bread roll', 2]], $r);
        $in('12:15', '219', 'Imelda Sarmiento', 24, $j);
        $walkIn('12:20', '3-in-1 coffee', $r);
        $in('12:30', '117', 'Dennis Abella', 6, $r);
        $l->clean('204', $t('12:30'), $t('12:48'), $y);
        $walkIn('12:45', 'Biscuits', $j);
        $sell('13:00', '219', [['Biscuits', 1], ['Peanuts', 1]], $r);
        $l->clean('208', $t('13:00'), $t('13:15'), $d);
        $this->voidedExpense($t('13:02'), $t('13:03'), $r, $c);
        $this->cashOut($l->shift, CashMovementKind::Expense, 30000, 'Light bulbs × 3 · Room 117, 204, 301', $t('13:04'), $r, 'Repairs and supplies');
        $in('13:10', '118', 'Celso Tumulak', 6, $j, 'Promo');
        $in('13:20', '220', 'Rowena Lagman', 12, $r);
        $out('13:26', '203', $j);
        $in('13:30', '301', 'Antonio Velasco', 24, $j);
        $sell('13:30', '117', [['Beer 330 ml', 1]], $r);
        $walkIn('13:35', '3-in-1 coffee', $j);
        $in('13:45', '119', 'Rosalinda Ebarle', 18, $j);
        $out('13:50', '111', $r);
        $out('13:55', '211', $j);
        $l->transfer($this->stay('108'), '212', 'Aircon not cooling', $t('14:02'), $j, $c);
        $this->s['212'] = $this->stay('108');
        unset($this->s['108']);
        $walkIn('14:10', 'Biscuits', $r);
        $in('14:20', '120', 'Edgardo Lumbao', 6, $r);
        $l->printed(PrintDocument::CheckinSlip, $this->stay('120')->id, $r, $t('14:25'), reprint: true);
        $out('14:30', '112', $j);
        $in('14:40', '221', 'Carmela Ignacio', 6, $j);
        $walkIn('14:45', '3-in-1 coffee', $r);
        $l->extend($this->stay('114'), 6, $t('15:00'), $r);
        $in('15:05', '121', 'Ronaldo Bersabal', 6, $j);
        $in('15:10', '209', 'Divina Alonzo', 6, $r, 'Promo');
        $walkIn('15:15', 'Biscuits', $j);
        $sell('15:20', '206', [['Cup noodles', 2], ['Bottled water 500 ml', 1]], $j);
        $out('15:30', '214', $r);
        $walkIn('15:45', '3-in-1 coffee', $j);
        $in('16:00', '201', 'Enrique Montano', 24, $j);
        $sell('16:00', '119', [['Chocolate bar', 1]], $r);
        $sell('16:01', '119', [['Peanuts', 2]], $r);
        $in('16:10', '122', 'Fe Cabahug', 18, $r);
        $walkIn('16:20', 'Biscuits', $r);
        $sell('16:30', '201', [['Toothbrush kit', 1], ['Shampoo sachet', 2]], $j);
        $sell('16:30', '221', [['Coke in can', 1]], $r, false);
        $out('16:45', '216', $r, ['Stained linen']);
        $walkIn('16:50', '3-in-1 coffee', $r);
        $this->cashOut($l->shift, CashMovementKind::Remittance, 1000000, 'Remittance to the office', $t('17:00'), $j);
        $sell('17:00', '122', [['Canned tuna', 1]], $r);
        $sell('17:01', '122', [['Bread roll', 1]], $r);
        $sell('17:02', '122', [['Energy drink', 1]], $r);
        $out('17:05', '116', $j);
        $in('17:15', '204', 'Mylene Pacquiao', 6, $r);
        $in('17:20', '123', 'Arturo Dagohoy', 6, $j);
        $l->extend($this->stay('3A'), 6, $t('17:30'), $r);
        $walkIn('17:35', 'Biscuits', $j);
        $sell('17:40', '123', [['Toothbrush kit', 1], ['Shampoo sachet', 2]], $r);
        $in('17:50', '208', 'Jonathan Sablay', 6, $j);
        $walkIn('18:05', '3-in-1 coffee', $j);
        $sell('18:20', '220', [['Beer 330 ml', 2]], $j);
        $in('18:30', '124', 'Norberto Calo', 6, $r);
        $reservation = $this->bookReservation($t('18:35'), $r);
        $out('18:40', '118', $j);
        $walkIn('18:45', 'Biscuits', $r);
        $out('18:53', '205', $r);
        $l->extend($this->stay('123'), 6, $t('19:00'), $r);
        $sell('19:10', '124', [['Beer 330 ml', 1]], $r);
        $walkIn('19:15', '3-in-1 coffee', $r);
        $this->cancelReservation($reservation, $t('19:20'), $r, $c);
        $l->extend($this->stay('221'), 6, $t('19:30'), $r);
        $walkIn('19:35', 'Biscuits', $j);
        $l->extend($this->stay('121'), 6, $t('19:40'), $j, paid: false);
        $l->extend($this->stay('209'), 6, $t('19:45'), $j);
        $out('19:50', '120', $r);
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

    /** The ₱3,000 typed by mistake at 1:02 PM, voided a minute later with a supervisor PIN (21). */
    private function voidedExpense(CarbonImmutable $at, CarbonImmutable $voidAt, User $by, User $approver): void
    {
        $shift = $this->ledger->shift;
        $row = $this->cashOut($shift, CashMovementKind::Expense, 300000, 'Light bulbs × 3', $at, $by, 'Repairs and supplies');
        $reason = $this->reason(ReasonKind::VoidCashMovement, 'Typed wrong amount');
        $approval = Approval::query()->create([
            'branch_id' => $shift->branch_id, 'action' => ApprovalAction::VoidCashMovement, 'subject_type' => 'cash_movement', 'subject_id' => $row->id,
            'requested_by' => $by->id, 'approved_by' => $approver->id, 'reason_id' => $reason->id,
            'reason' => 'Typed wrong amount · typed 3,000', 'summary' => 'Expense · Light bulbs × 3 · −₱3,000.00', 'created_at' => $voidAt,
        ]);
        $row->update(['voided_at' => $voidAt, 'voided_by' => $by->id, 'void_reason' => 'typed 3,000', 'approval_id' => $approval->id, 'reason_id' => $reason->id]);
    }

    /** A Deluxe night for 14 Nov, ₱700 paid ahead in cash (58). */
    private function bookReservation(CarbonImmutable $at, User $by): Stay
    {
        $branch = $this->ledger->branch->refresh();
        $number = $branch->next_stay_number;
        $branch->update(['next_stay_number' => $number + 1]);

        $stay = Stay::query()->create([
            'branch_id' => $branch->id,
            'code' => sprintf('%s-26-%06d', $branch->code_prefix, $number),
            'state' => StayState::Reserved,
            'source' => StaySource::Reservation,
            'room_id' => $this->ledger->room('214')->id,
            'room_type_id' => RoomType::query()->where('branch_id', $branch->id)->where('name', 'Deluxe')->value('id'),
            'guest_name' => 'Hazel Montemayor',
            'guest_mobile' => '09171234567',
            'stay_kind' => StayKind::Overnight,
            'nights' => 1,
            'quoted_amount' => 95000,
            'arrival_at' => Ledger::at('14:00', '2026-11-14'),
            'expected_checkout_at' => Ledger::at('12:00', '2026-11-15'),
        ]);

        $this->ledger->payment($stay, null, PaymentKind::DepositIn, 70000, $at, $by, DepositType::Guest, 'Paid ahead');

        return $stay;
    }

    /** Cancelled the same evening; the money paid ahead goes back in cash. */
    private function cancelReservation(Stay $stay, CarbonImmutable $at, User $by, User $approver): void
    {
        $reason = $this->reason(ReasonKind::CancelReservation, 'Guest cancelled');
        Approval::query()->create([
            'branch_id' => $stay->branch_id, 'action' => ApprovalAction::CancelReservation, 'subject_type' => 'stay', 'subject_id' => $stay->id,
            'requested_by' => $by->id, 'approved_by' => $approver->id, 'reason_id' => $reason->id,
            'reason' => 'Guest cancelled', 'summary' => "{$stay->code} · refund ₱700.00", 'created_at' => $at,
        ]);
        $this->ledger->payment($stay, null, PaymentKind::DepositRefund, -70000, $at, $by, DepositType::Guest, 'Paid ahead returned');
        $stay->update(['state' => StayState::Cancelled, 'closed_reason' => 'Guest cancelled']);
    }

    /**
     * Freeze a shift as in DATABASE_SCHEMA §8.10 steps 2–4: count, expected, variance,
     * forwarded stays, Z number, grand totals and the frozen totals.
     *
     * @param  Collection<int, ShiftForwardedStay>  $forwardedIn
     * @return Collection<int, ShiftForwardedStay>
     */
    private function freeze(ShiftSession $shift, CarbonImmutable $at, User $by, int $counted, Collection $forwardedIn, ?int $grandTotalBefore = null,
        ?int $grandTotalAfter = null, ?string $varianceNote = null, ?string $varianceReason = null, ?string $handoverNote = null): Collection
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
            'variance_reason_id' => $varianceReason === null ? null : $this->reason(ReasonKind::Variance, $varianceReason)->id,
            'variance_note' => $varianceNote, 'handover_note' => $handoverNote, 'z_no' => $zNo,
        ]);

        $totals = ShiftTotals::build($shift->refresh(), $forwardedIn, $forwardedOut);
        $before = $grandTotalBefore ?? (int) $grandTotalAfter - $totals['sales']['total'];
        $shift->update([
            'totals' => $totals,
            'grand_total_before' => $before,
            'grand_total_after' => $before + $totals['sales']['total'],
        ]);

        return $forwardedOut;
    }

    private function handOver(ShiftSession $from, ShiftSession $to, User $receiver, int $received, CarbonImmutable $at): void
    {
        ShiftHandover::query()->create([
            'from_shift_id' => $from->id, 'to_shift_id' => $to->id, 'received_by' => $receiver->id,
            'received_cash' => $received, 'difference_amount' => $received - ($from->counted_cash?->centavos() ?? 0), 'created_at' => $at,
        ]);
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

    private function stay(string $room): Stay
    {
        return $this->s[$room] ?? throw new \LogicException("No guest in room {$room}.");
    }

    private function reason(ReasonKind $kind, string $name): Reason
    {
        return Reason::query()->where('branch_id', $this->ledger->branch->id)->where('kind', $kind)->where('name', $name)->firstOrFail();
    }
}
