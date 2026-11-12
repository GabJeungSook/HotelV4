<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StayKind;
use App\Enums\StaySource;
use App\Enums\StayState;
use App\Models\Branch;
use App\Models\Room;
use App\Models\ShiftSession;
use App\Models\Stay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A walk-in, 12-hour stay checked in on the branch's open shift by default.
 * The room is not set to occupied here; that is RoomStatusService's job.
 *
 * @extends Factory<Stay>
 */
class StayFactory extends Factory
{
    private static int $number = 0;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'code' => fn () => 'TST-26-'.str_pad((string) ++self::$number, 6, '0', STR_PAD_LEFT),
            'state' => StayState::CheckedIn,
            'source' => StaySource::WalkIn,
            'room_id' => fn (array $a) => Room::factory()->state(['branch_id' => $a['branch_id']]),
            'room_type_id' => fn (array $a) => Room::query()->withoutGlobalScopes()->whereKey($a['room_id'])->value('room_type_id'),
            'guest_name' => fake()->name(),
            'stay_kind' => StayKind::Hourly,
            'hours' => 12,
            'quoted_amount' => 50000,
            'checked_in_at' => '2026-11-11 01:00:00',
            'expected_checkout_at' => '2026-11-11 13:00:00',
            'cycle_hours_used' => 12,
            'checkin_shift_id' => fn (array $a) => ShiftSessionFactory::openFor($a['branch_id']),
            'checked_in_by' => fn (array $a) => ShiftSession::query()->withoutGlobalScopes()->whereKey($a['checkin_shift_id'])->value('opened_by'),
        ];
    }

    public function checkedIn(): static
    {
        return $this->state(['state' => StayState::CheckedIn]);
    }

    public function checkedOut(): static
    {
        return $this->state(fn (array $a): array => [
            'state' => StayState::CheckedOut,
            'checked_out_at' => '2026-11-11 11:00:00',
            'checkout_shift_id' => fn (array $x) => $x['checkin_shift_id'],
            'checked_out_by' => fn (array $x) => $x['checked_in_by'],
        ]);
    }

    public function held(): static
    {
        return $this->state([
            'state' => StayState::Held,
            'source' => StaySource::Kiosk,
            'hold_expires_at' => '2026-11-11 01:10:00',
            'checked_in_at' => null,
            'expected_checkout_at' => null,
            'checkin_shift_id' => null,
            'checked_in_by' => null,
            'cycle_hours_used' => 0,
        ]);
    }

    public function reserved(): static
    {
        return $this->state([
            'state' => StayState::Reserved,
            'source' => StaySource::Reservation,
            'arrival_at' => '2026-11-13 06:00:00',
            'checked_in_at' => null,
            'expected_checkout_at' => '2026-11-13 18:00:00',
            'checkin_shift_id' => null,
            'checked_in_by' => null,
            'cycle_hours_used' => 0,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(['state' => StayState::Cancelled, 'closed_reason' => 'Guest changed mind']);
    }
}
