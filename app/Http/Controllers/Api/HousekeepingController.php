<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Housekeeping\FinishCleaning;
use App\Actions\Housekeeping\StartCleaning;
use App\Enums\CleaningResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\FinishCleaningRequest;
use App\Models\CleaningTask;
use App\Models\User;
use App\Queries\CleaningQuery;
use App\Support\Presence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Roomboy phone API (mirrors HousekeepingController): the queue, the cleaning screen, start and
 * finish, and "My cleans". Same policies and Actions as the web, returned as JSON.
 */
final class HousekeepingController extends Controller
{
    public function __construct(private readonly CleaningQuery $cleaning) {}

    public function queue(Request $request, Presence $presence): JsonResponse
    {
        Gate::authorize('viewAny', CleaningTask::class);
        $user = $this->user($request);
        $presence->touch($user);

        return response()->json(['user_name' => $user->name, ...$this->cleaning->queue($user)]);
    }

    public function start(Request $request, CleaningTask $task, StartCleaning $start): JsonResponse
    {
        Gate::authorize('start', $task);
        $start->handle($this->user($request), $task);

        return response()->json($this->cleaning->task($task->refresh()));
    }

    /** The cleaning screen: only the task this roomboy has in progress (policy 'finish'). */
    public function show(Request $request, CleaningTask $task): JsonResponse
    {
        Gate::authorize('finish', $task);

        return response()->json($this->cleaning->task($task));
    }

    public function finish(FinishCleaningRequest $request, CleaningTask $task, FinishCleaning $finish): JsonResponse
    {
        Gate::authorize('finish', $task);
        $result = CleaningResult::from($request->string('result')->toString());
        $done = $finish->handle(
            $this->user($request), $task, $result,
            $request->filled('problem_note') ? $request->string('problem_note')->toString() : null,
        );
        $room = $done->room()->value('number');
        $message = $result === CleaningResult::Ready
            ? "Room {$room} is ready for the next guest."
            : "Room {$room} is sent to maintenance.";

        return response()->json(['message' => $message, ...$this->cleaning->queue($this->user($request))]);
    }

    public function history(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CleaningTask::class);

        return response()->json($this->cleaning->history($this->user($request)));
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
