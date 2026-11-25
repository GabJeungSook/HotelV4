<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Shifts\CloseShift;
use App\Actions\Shifts\HandOverShift;
use App\Actions\Shifts\OpenShift;
use App\Actions\Shifts\RecordCashMovement;
use App\Actions\Stays\CheckInGuest;
use App\Enums\CashMovementKind;
use App\Enums\PaymentMethod;
use App\Enums\ReasonKind;
use App\Enums\StayKind;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\Discount;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\Reason;
use App\Models\Room;
use App\Models\ShiftSession;
use App\Models\Stay;
use App\Models\TransferReason;
use App\Models\User;
use App\Services\CurrentShift;
use App\Services\Pricing\DiscountTerms;
use App\Services\Pricing\RateCard;
use App\Services\PricingService;
use App\Support\Clock;
use App\Support\Money;
use Database\Seeders\DemoBranch;
use Illuminate\Support\Str;

/**
 * Arrange helpers for the Phase 3 Feature tests, on the seeded "Hotel Dalisay GenSan"
 * (DatabaseSeeder without the demo shift). Seeded staff have PIN 1234 and password "password".
 * Every stay is made through the real actions, so the invariants hold after each test.
 */
final class Hotel
{
    public const PIN = '1234';

    public static function branch(): Branch
    {
        return DemoBranch::get();
    }

    public static function user(string $username): User
    {
        return User::query()->where('username', $username)->firstOrFail();
    }

    public static function frontdesk(): User
    {
        return self::user('jessa.delacruz');
    }

    public static function supervisor(): User
    {
        return self::user('carol.villanueva');
    }

    public static function room(string $number): Room
    {
        return Room::query()->where('number', $number)->firstOrFail();
    }

    public static function product(string $name): Product
    {
        return Product::query()->where('name', $name)->firstOrFail();
    }

    public static function discount(string $name): Discount
    {
        return Discount::query()->where('name', $name)->firstOrFail();
    }

    public static function reason(ReasonKind $kind, string $name = 'Other'): Reason
    {
        return Reason::query()->where('kind', $kind)->where('name', $name)->firstOrFail();
    }

    public static function transferReason(string $name = 'Aircon not cooling'): TransferReason
    {
        return TransferReason::query()->where('name', $name)->firstOrFail();
    }

    public static function device(): string
    {
        return (string) Str::uuid();
    }

    public static function openShift(?User $by = null, int $openingCash = 500000): ShiftSession
    {
        return app(OpenShift::class)->handle($by ?? self::frontdesk(), self::branch()->id, Money::ofCentavos($openingCash));
    }

    /** A walk-in check-in paid in exact cash (room − discount + ₱200 key deposit) unless told otherwise. */
    public static function checkIn(
        string $room = '205',
        int $quantity = 12,
        StayKind $kind = StayKind::Hourly,
        ?int $tendered = null,
        ?User $by = null,
        ?Discount $discount = null,
        bool $keepChange = false,
        ?string $key = null,
        string $guest = 'Rico Manalastas',
    ): Stay {
        $action = app(CheckInGuest::class);
        $roomRow = self::room($room);
        $tenderedMoney = $tendered === null ? null : Money::ofCentavos($tendered);

        if ($tenderedMoney === null) {
            $quote = app(PricingService::class)->quoteStay(
                RateCard::forBranch(self::branch()), $roomRow->room_type_id, $kind, $quantity,
                app(Clock::class)->now(), $discount === null ? null : DiscountTerms::of($discount),
            );
            $tenderedMoney = $quote->totalToCollect();
        }

        return $action->handle(
            $by ?? self::frontdesk(), $roomRow, $guest, $kind, $quantity, PaymentMethod::Cash,
            $key ?? (string) Str::uuid(), $tenderedMoney, keepChangeAsDeposit: $keepChange, discount: $discount,
        );
    }

    /** An expense (default), remittance or shortage payment through RecordCashMovement. */
    public static function movement(
        CashMovementKind $kind,
        int $amount,
        ?User $by = null,
        string $note = 'Test entry',
        string $category = 'Drinking water',
        ?int $forShiftId = null,
        ?string $key = null,
    ): CashMovement {
        return app(RecordCashMovement::class)->handle(
            $by ?? self::frontdesk(), self::branch()->id, $kind, Money::ofCentavos($amount), $note, $key ?? (string) Str::uuid(),
            $kind === CashMovementKind::Expense ? ExpenseCategory::query()->where('name', $category)->firstOrFail() : null,
            $forShiftId,
        );
    }

    /** The cash expected in the open shift's drawer (for arranging counts; never shown before the count). */
    public static function expectedCash(): Money
    {
        $shift = app(CurrentShift::class)->find(self::branch()->id) ?? throw new \RuntimeException('No open shift');

        return app(CloseShift::class)->expected($shift);
    }

    /**
     * Steps 1–3 of the close: start, count the expected cash plus $offset as coins, and save the
     * result (a variance gets "Wrong change given" with a note).
     */
    public static function closeToStep4(?User $by = null, int $offset = 0): ShiftSession
    {
        $by ??= self::frontdesk();
        $close = app(CloseShift::class);
        $branchId = self::branch()->id;
        $close->begin($by, $branchId);
        // Works whether the branch counts by denomination (pieces) or by total: both are this amount.
        $amount = self::expectedCash()->centavos() + $offset;
        $close->count($by, $branchId, ['coins' => $amount], total: Money::ofCentavos($amount));
        $variance = $offset === 0 ? null : self::reason(ReasonKind::Variance, 'Wrong change given');

        return $close->result($by, $branchId, $variance, $offset === 0 ? null : 'Gave wrong change');
    }

    /** The whole close with a handover to $receiver, who counts what was counted unless told. Returns the new shift. */
    public static function closeAndHandOver(User $receiver, ?User $by = null, int $offset = 0, ?int $received = null, ?string $differenceNote = null): ShiftSession
    {
        $by ??= self::frontdesk();
        $shift = self::closeToStep4($by, $offset);

        return app(HandOverShift::class)->toReceiver(
            $by, self::branch()->id, $receiver, self::PIN,
            Money::ofCentavos($received ?? ($shift->counted_cash?->centavos() ?? 0)), self::device(), 'Handover note', $differenceNote,
        );
    }
}
