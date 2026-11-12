<?php

declare(strict_types=1);

namespace App\Http;

use App\Exceptions\DomainException;
use App\Exceptions\NoOpenShift;
use App\Exceptions\PinInvalid;
use App\Exceptions\PinLocked;
use App\Exceptions\RateNotFound;
use App\Exceptions\RoomNotAvailable;
use App\Exceptions\RoomTaken;
use App\Exceptions\ShiftAlreadyOpen;
use App\Exceptions\ShiftClosing;
use App\Exceptions\ShiftStale;
use App\Models\Room;
use App\Queries\RoomQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * The one handler for domain errors (SYSTEM_DESIGN §8). The action's transaction has already
 * rolled back; this turns the refusal into what the screen shows. The form stays on screen
 * with what was typed (the redirect goes back to the same page), and the page reads
 * `flash.error` = { code, message, … } for its inline alert or dialog:
 *
 * - RoomTaken, RoomNotAvailable → the "room just taken" dialog (53), with who took it and the
 *   next free room of the same type
 * - RateNotFound → the missing-rate alert (52)
 * - PinInvalid → the PIN pads (3, 14) with the tries left; on switch user, the third wrong PIN
 *   ends the session and goes to the sign-in (1)
 * - PinLocked → the locked state of every PIN pad
 * - NoOpenShift, ShiftAlreadyOpen → /shift/open (2 or 2b)
 * - anything else → an inline alert with the action's own message
 */
final class DomainErrorResponse
{
    public function __construct(private readonly RoomQuery $rooms) {}

    public function toResponse(DomainException $e, Request $request): RedirectResponse
    {
        if ($e instanceof NoOpenShift || $e instanceof ShiftAlreadyOpen) {
            Inertia::flash('error', ['code' => self::code($e), 'message' => $e->userMessage()]);

            return redirect()->route('shift.open');
        }

        if ($e instanceof PinInvalid && $e->mustSignIn) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            Inertia::flash('error', ['code' => 'signed_out', 'message' => 'Three wrong PINs. Sign in with your username and password.']);

            return redirect()->route('login');
        }

        $error = ['code' => self::code($e), 'message' => $e->userMessage()];

        if ($e instanceof PinInvalid) {
            $error['tries_left'] = $e->triesLeft;
        }

        if ($e instanceof RoomTaken || $e instanceof RoomNotAvailable) {
            $error = [...$error, 'code' => 'room_taken', ...$this->conflict($e->roomId)];
        }

        if ($e instanceof PinLocked) {
            $error['code'] = 'pin_locked';
        }

        if ($e instanceof RateNotFound) {
            $error['code'] = 'rate_not_found';
        }

        if ($e instanceof ShiftClosing || $e instanceof ShiftStale) {
            $error['code'] = self::code($e);
        }

        Inertia::flash('error', $error);

        return redirect()->back(fallback: route('home'));
    }

    /**
     * What dialog 53 says: the room, who took it, and the next free room of the type.
     *
     * @return array<string, mixed>
     */
    private function conflict(?int $roomId): array
    {
        $room = $roomId === null ? null : Room::query()->withoutGlobalScopes()->with(['roomType' => fn ($q) => $q->withoutGlobalScopes()])->find($roomId);

        if ($room === null) {
            return ['room' => null, 'taken_by' => null, 'type' => null, 'next_room_id' => null];
        }

        $next = $this->rooms->nextFree($room->branch_id, $room->room_type_id, $room->id);

        return [
            'room' => $room->number,
            'taken_by' => $this->rooms->takenBy($room->id)['name'] ?? null,
            'type' => $room->roomType->name ?? null,
            'next_room_id' => $next?->id,
            'next_room' => $next?->number,
        ];
    }

    private static function code(DomainException $e): string
    {
        return Str::snake(class_basename($e));
    }
}
