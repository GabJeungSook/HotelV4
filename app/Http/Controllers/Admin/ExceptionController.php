<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Housekeeping\ResetCleaning;
use App\Actions\Shifts\ForceCloseShift;
use App\Enums\ShiftStatus;
use App\Exceptions\ActionRefused;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureDeviceKey;
use App\Models\CleaningTask;
use App\Models\ShiftSession;
use App\Models\User;
use App\Queries\StayScreenQuery;
use App\Services\AttentionList;
use App\Services\CurrentBranch;
use App\Support\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Needs attention (49) at /admin/exceptions: every AttentionList row of the branch, with the
 * button that opens the screen fixing it. Force close (ForceCloseShift: the count of 23, then
 * a PIN on 14) and Reset task (ResetCleaning) are posted from here. Nothing is fixed
 * automatically and there is no bulk fix (mockup 49).
 */
final class ExceptionController extends Controller
{
    public function index(Request $request, CurrentBranch $branches, AttentionList $attention, StayScreenQuery $screens, Clock $clock): Response
    {
        $user = $this->user($request);
        $branch = $branches->branch() ?? abort(403);

        return Inertia::render('Admin/Exceptions', [
            'rows' => $attention->rows([$branch->id], $user),
            'at' => $clock->now()->toIso8601String(),
            'approvers' => $screens->approvers($branch->id, $user),
            'bills' => array_map('intval', (array) config('hotel.cash_count.bills')),
        ]);
    }

    public function forceClose(Request $request, int $shift, CurrentBranch $branches, ForceCloseShift $close): RedirectResponse
    {
        $request->validate([
            'pieces' => ['required', 'array'],
            'pieces.*' => ['integer', 'min:0'],
            'approver_id' => ['required', 'integer'],
            'pin' => ['required', 'string', 'digits:4'],
        ], ['pin.digits' => 'Type the four-digit PIN.', 'pin.required' => 'Type the four-digit PIN.']);

        $user = $this->user($request);
        $branchId = $branches->id() ?? abort(403);
        $session = ShiftSession::query()->withoutGlobalScopes()->where('branch_id', $branchId)->findOrFail($shift);

        if (! in_array($session->status, [ShiftStatus::Open, ShiftStatus::Closing], true)) {
            throw ActionRefused::because('That shift is already closed. Nothing was changed.');
        }

        $approver = User::query()->where('is_active', true)->find($request->integer('approver_id'))
            ?? throw ActionRefused::because('Choose who approves.');

        /** @var array<string, int> $pieces */
        $pieces = (array) $request->input('pieces');
        $closed = $close->handle($user, $branchId, $pieces, $approver, $request->string('pin')->toString(), EnsureDeviceKey::of($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$closed->period_name} shift force-closed as Z-".str_pad((string) $closed->z_no, 4, '0', STR_PAD_LEFT).'.']);

        return redirect()->route('admin.exceptions');
    }

    public function resetTask(Request $request, int $task, CurrentBranch $branches, ResetCleaning $reset): RedirectResponse
    {
        $branchId = $branches->id() ?? abort(403);
        $row = CleaningTask::query()->withoutGlobalScopes()->where('branch_id', $branchId)->findOrFail($task);
        $reset->handle($this->user($request), $row);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'The cleaning is reset. The room is back in the queue.']);

        return redirect()->route('admin.exceptions');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
