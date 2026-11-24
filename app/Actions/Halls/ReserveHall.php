<?php

declare(strict_types=1);

namespace App\Actions\Halls;

use App\Enums\DepositType;
use App\Enums\HallBookingStatus;
use App\Enums\HallPricing;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Exceptions\ActionRefused;
use App\Models\Branch;
use App\Models\Hall;
use App\Models\HallBooking;
use App\Models\User;
use App\Queries\HallQuery;
use App\Services\CurrentShift;
use App\Services\HallLedger;
use App\Support\Clock;
use App\Support\DuplicateKey;
use App\Support\Money;
use App\Support\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Book a function hall for a time slot (DATABASE_SCHEMA §4.14, §8.14). One transaction: the open
 * shift and the hall are locked, the slot is checked free (no overlapping live booking), the
 * booking is written `reserved` with its quote lines (per pax or flat), and any money paid ahead is
 * a guest deposit_in on the open shift. A repeated idempotency key returns the first booking.
 *
 * @phpstan-type ItemInput array{package_id: int|null, label: string, pricing: HallPricing, unit_amount: Money, quantity?: int}
 */
final class ReserveHall
{
    public function __construct(
        private readonly Clock $clock,
        private readonly CurrentShift $currentShift,
        private readonly HallQuery $halls,
        private readonly HallLedger $ledger,
        private readonly Transaction $transaction,
    ) {}

    /**
     * @param  list<ItemInput>  $items
     */
    public function handle(
        User $by,
        int $branchId,
        int $hallId,
        string $customerName,
        ?string $customerMobile,
        CarbonImmutable $startAt,
        CarbonImmutable $endAt,
        int $pax,
        array $items,
        string $idempotencyKey,
        ?PaymentMethod $depositMethod = null,
        ?Money $deposit = null,
        ?string $referenceNo = null,
    ): HallBooking {
        $existing = HallBooking::query()->withoutGlobalScopes()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        $name = trim((string) preg_replace('/\s+/', ' ', $customerName));

        if (preg_match_all('/\p{L}/u', $name) < 3) {
            throw ActionRefused::because('Type the customer’s name.');
        }

        if ($endAt->lessThanOrEqualTo($startAt)) {
            throw ActionRefused::because('The end time must be after the start time.');
        }

        if ($endAt->lessThan($this->clock->now())) {
            throw ActionRefused::because('That time slot has already passed. Pick a later one.');
        }

        if ($items === []) {
            throw ActionRefused::because('Add at least one item to the booking.');
        }

        $paying = $deposit !== null && $deposit->isPositive();

        if ($paying && ($depositMethod === null || $depositMethod === PaymentMethod::Deposit)) {
            throw ActionRefused::because('Pick how the deposit was paid.');
        }

        try {
            return $this->transaction->run(function () use ($by, $branchId, $hallId, $name, $customerMobile, $startAt, $endAt, $pax, $items, $idempotencyKey, $paying, $depositMethod, $deposit, $referenceNo): HallBooking {
                $shift = $paying ? $this->currentShift->forPayment($branchId) : $this->currentShift->forWrite($branchId);
                $branch = Branch::query()->findOrFail($branchId);

                if (! $branch->event_hall_enabled) {
                    throw ActionRefused::because('Event halls are switched off for this branch.');
                }

                $hall = Hall::query()->withoutGlobalScopes()->where('branch_id', $branchId)->lockForUpdate()->findOrFail($hallId);

                if (! $hall->is_active) {
                    throw ActionRefused::because("{$hall->name} is not available for booking.");
                }

                if ($this->halls->overlaps($hall->id, $startAt, $endAt)) {
                    throw ActionRefused::because("{$hall->name} is already booked for an overlapping time.");
                }

                $rows = $this->priceItems($items, $pax, $branchId);
                $quoted = array_reduce($rows, static fn (Money $sum, array $r): Money => $sum->plus($r['amount']), Money::zero());

                if ($paying) {
                    $this->ledger->assertAccepted($branchId, $depositMethod, $referenceNo);

                    if ($deposit->greaterThan($quoted)) {
                        throw ActionRefused::because('The deposit cannot be more than the quoted total.');
                    }
                }

                $booking = HallBooking::query()->create([
                    'branch_id' => $branchId,
                    'hall_id' => $hall->id,
                    'code' => 'HALL-TMP',
                    'status' => HallBookingStatus::Reserved,
                    'customer_name' => $name,
                    'customer_mobile' => $customerMobile,
                    'event_date' => $this->clock->toLocal($startAt)->toDateString(),
                    'start_at' => $startAt,
                    'end_at' => $endAt,
                    'pax' => $pax,
                    'quoted_amount' => $quoted,
                    'reserve_shift_id' => $shift->id,
                    'created_by' => $by->id,
                    'idempotency_key' => $idempotencyKey,
                ]);
                $booking->update(['code' => 'HALL-'.str_pad((string) $booking->id, 5, '0', STR_PAD_LEFT)]);

                foreach ($rows as $r) {
                    $booking->items()->create([
                        'branch_id' => $branchId,
                        'hall_package_id' => $r['package_id'],
                        'label' => $r['label'],
                        'pricing' => $r['pricing'],
                        'quantity' => $r['quantity'],
                        'unit_amount' => $r['unit_amount'],
                        'amount' => $r['amount'],
                    ]);
                }

                if ($paying) {
                    $this->ledger->payment($shift, $booking, $by, PaymentKind::DepositIn, $depositMethod, $deposit, DepositType::Guest, referenceNo: $referenceNo, note: 'deposit');
                }

                return $booking;
            });
        } catch (QueryException $e) {
            if (DuplicateKey::on($e, 'hall_bookings_idempotency_unique')) {
                return HallBooking::query()->withoutGlobalScopes()->where('idempotency_key', $idempotencyKey)->first() ?? throw $e;
            }

            throw $e;
        }
    }

    /**
     * @param  list<ItemInput>  $items
     * @return list<array{package_id: int|null, label: string, pricing: HallPricing, quantity: int, unit_amount: Money, amount: Money}>
     */
    private function priceItems(array $items, int $pax, int $branchId): array
    {
        $rows = [];

        foreach ($items as $item) {
            $pricing = $item['pricing'];
            $unit = $item['unit_amount'];
            $quantity = $pricing === HallPricing::PerPax ? $pax : (int) ($item['quantity'] ?? 1);

            if ($quantity < 1) {
                throw $pricing === HallPricing::PerPax
                    ? ActionRefused::because('Enter the number of guests for a per-pax item.')
                    : ActionRefused::because('Each item needs a quantity of at least 1.');
            }

            if (! $unit->isPositive()) {
                throw ActionRefused::because('Each item needs a price above ₱0.');
            }

            $label = trim($item['label']);

            if ($label === '') {
                throw ActionRefused::because('Each item needs a label.');
            }

            $rows[] = [
                'package_id' => $item['package_id'],
                'label' => mb_substr($label, 0, 120),
                'pricing' => $pricing,
                'quantity' => $quantity,
                'unit_amount' => $unit,
                'amount' => $unit->times($quantity),
            ];
        }

        return $rows;
    }
}
