<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\ShiftMember;
use Illuminate\Support\Facades\DB;

/**
 * "The outgoing team is then signed out" (APP_FLOW §4.16 step 12). After a close, the sessions
 * of the closed shift's members end (sessions live in the database, TRD §2), except the people
 * kept: the receiver, who now runs the next shift, and the person at this computer, who signs
 * out from the slip (26) once it is printed. The shift itself is not touched.
 */
final class SignOutShiftTeam
{
    /** @param  list<int>  $keep  user ids whose sessions stay */
    public function handle(int $shiftId, array $keep): void
    {
        $members = ShiftMember::query()->where('shift_session_id', $shiftId)->pluck('user_id')->map(fn (mixed $id): int => (int) $id)->all();
        $others = array_values(array_diff($members, $keep));
        $table = config('session.table', 'sessions');

        if ($others !== [] && is_string($table)) {
            DB::table($table)->whereIn('user_id', $others)->delete();
        }
    }
}
