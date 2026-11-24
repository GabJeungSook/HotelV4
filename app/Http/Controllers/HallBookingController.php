<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Halls\AddHallDeposit;
use App\Actions\Halls\CancelHallBooking;
use App\Actions\Halls\CompleteHallBooking;
use App\Actions\Halls\ReserveHall;
use App\Enums\HallPricing;
use App\Enums\PaymentMethod;
use App\Exceptions\ActionRefused;
use App\Http\Middleware\EnsureDeviceKey;
use App\Http\Requests\HallBookingRequest;
use App\Models\Branch;
use App\Models\HallBooking;
use App\Models\User;
use App\Queries\HallScreenQuery;
use App\Support\Clock;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Function halls for the front desk (DATABASE_SCHEMA §4.14): book a hall, take a deposit, complete
 * the event (post the charges and settle) or cancel. Actions carry the domain rules; this is thin.
 */
final class HallBookingController extends Controller
{
    public function index(Request $request, HallScreenQuery $screen): Response
    {
        return Inertia::render('Halls/Index', $screen->index($this->branch($request), $this->user($request)));
    }

    public function store(HallBookingRequest $request, ReserveHall $reserve): RedirectResponse
    {
        $paid = $request->integer('deposit_amount');

        $booking = $reserve->handle(
            $this->user($request),
            $this->branch($request)->id,
            $request->integer('hall_id'),
            $request->string('customer_name')->trim()->toString(),
            $request->filled('customer_mobile') ? $request->string('customer_mobile')->trim()->toString() : null,
            $this->at($request->string('event_date')->toString(), $request->string('start_time')->toString()),
            $this->at($request->string('event_date')->toString(), $request->string('end_time')->toString()),
            $request->integer('pax'),
            $this->items($request),
            $request->string('idempotency_key')->toString(),
            $paid > 0 ? PaymentMethod::from($request->string('method')->toString()) : null,
            $paid > 0 ? Money::ofCentavos($paid) : null,
            $request->filled('reference_no') ? $request->string('reference_no')->trim()->toString() : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "Hall booked · {$booking->code}."]);

        return redirect()->route('halls.index');
    }

    public function deposit(Request $request, AddHallDeposit $add, HallBooking $booking): RedirectResponse
    {
        $add->handle(
            $this->user($request), $booking, PaymentMethod::from($request->string('method')->toString()),
            Money::ofCentavos($request->integer('amount')),
            $request->filled('reference_no') ? $request->string('reference_no')->trim()->toString() : null,
            $request->string('idempotency_key')->toString(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Deposit recorded.']);

        return redirect()->route('halls.index');
    }

    public function complete(Request $request, CompleteHallBooking $complete, HallBooking $booking): RedirectResponse
    {
        $given = $request->integer('amount');

        $complete->handle(
            $this->user($request), $booking,
            $given > 0 ? PaymentMethod::from($request->string('method')->toString()) : null,
            $given > 0 ? Money::ofCentavos($given) : null,
            $request->filled('reference_no') ? $request->string('reference_no')->trim()->toString() : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$booking->code} completed."]);

        return redirect()->route('halls.index');
    }

    public function cancel(Request $request, CancelHallBooking $cancel, HallBooking $booking): RedirectResponse
    {
        $approver = User::query()->where('is_active', true)->find($request->integer('approver_id'))
            ?? throw ActionRefused::because('Choose who approves.');

        $cancel->handle(
            $this->user($request), $booking, $request->string('reason')->trim()->toString(),
            $approver, $request->string('pin')->toString(), EnsureDeviceKey::of($request),
            refund: $request->boolean('refund', true),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$booking->code} cancelled."]);

        return redirect()->route('halls.index');
    }

    private function at(string $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i', "{$date} {$time}", Clock::displayTimezone())?->utc()
            ?? throw ActionRefused::because('Type the event date and time.');
    }

    private function branch(Request $request): Branch
    {
        return Branch::query()->findOrFail($this->user($request)->branch_id);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /** @return list<array{package_id: int|null, label: string, pricing: HallPricing, unit_amount: Money, quantity?: int}> */
    private function items(Request $request): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $request->array('items');

        return array_map(fn (array $row): array => [
            'package_id' => isset($row['package_id']) ? (int) $row['package_id'] : null,
            'label' => (string) ($row['label'] ?? ''),
            'pricing' => HallPricing::from((string) $row['pricing']),
            'unit_amount' => Money::ofCentavos((int) $row['unit_amount']),
            'quantity' => isset($row['quantity']) ? (int) $row['quantity'] : 1,
        ], $rows);
    }
}
