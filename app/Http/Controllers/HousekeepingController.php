<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Housekeeping\FinishCleaning;
use App\Actions\Housekeeping\StartCleaning;
use App\Enums\CleaningResult;
use App\Http\Requests\FinishCleaningRequest;
use App\Models\CleaningTask;
use App\Models\User;
use App\Queries\CleaningQuery;
use App\Support\Presence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The housekeeper's phone (32–35, APP_FLOW §4.17): the queue and its empty state, the cleaning
 * screen, and "My cleans". The queue reloads every 20 s and each load touches last_seen_at.
 */
final class HousekeepingController extends Controller
{
    public function __construct(private readonly CleaningQuery $cleaning) {}

    public function index(Request $request, Presence $presence): Response
    {
        Gate::authorize('viewAny', CleaningTask::class);
        $user = $this->user($request);
        $presence->touch($user);

        return Inertia::render('Housekeeping/Queue', [
            'user_name' => $user->name,
            ...$this->cleaning->queue($user),
        ]);
    }

    public function start(Request $request, CleaningTask $task, StartCleaning $start): RedirectResponse
    {
        Gate::authorize('start', $task);
        $start->handle($this->user($request), $task);

        return redirect()->route('housekeeping.show', $task);
    }

    /** The cleaning screen (33); a task that is no longer this user's goes back to the queue. */
    public function show(Request $request, CleaningTask $task): Response|RedirectResponse
    {
        Gate::authorize('viewAny', CleaningTask::class);

        if (! Gate::allows('finish', $task)) {
            return redirect()->route('housekeeping.index');
        }

        return Inertia::render('Housekeeping/Cleaning', $this->cleaning->task($task));
    }

    public function finish(FinishCleaningRequest $request, CleaningTask $task, FinishCleaning $finish): RedirectResponse
    {
        Gate::authorize('finish', $task);
        $result = CleaningResult::from($request->string('result')->toString());
        $done = $finish->handle(
            $this->user($request), $task, $result,
            $request->filled('problem_note') ? $request->string('problem_note')->toString() : null,
        );
        $room = $done->room()->value('number');

        Inertia::flash('toast', $result === CleaningResult::Ready
            ? ['type' => 'success', 'message' => "Room {$room} is ready for the next guest."]
            : ['type' => 'info', 'message' => "Room {$room} is sent to maintenance."]);

        return redirect()->route('housekeeping.index');
    }

    public function history(Request $request): Response
    {
        Gate::authorize('viewAny', CleaningTask::class);

        return Inertia::render('Housekeeping/History', $this->cleaning->history($this->user($request)));
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
