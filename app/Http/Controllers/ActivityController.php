<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Queries\ActivityQuery;
use App\Services\CurrentBranch;
use App\Support\Clock;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Activity log: every action of a business day in one read-only feed (ActivityQuery). The admin
 * sees their branch; the superadmin (owner) sees every active branch. Navigable by date and
 * filterable by person.
 */
final class ActivityController extends Controller
{
    public function __construct(
        private readonly ActivityQuery $activity,
        private readonly CurrentBranch $branches,
        private readonly Clock $clock,
    ) {}

    /** Admin, and an owner who has opened a branch: that branch's activity. */
    public function index(Request $request): Response
    {
        $branch = $this->branches->branch() ?? abort(403);

        return $this->render($request, [$branch->id]);
    }

    /** Superadmin: every active branch, by branch. */
    public function all(Request $request): Response
    {
        $branchIds = Branch::query()->withoutGlobalScopes()->where('status', 'active')->orderBy('name')
            ->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        return $this->render($request, $branchIds);
    }

    /** @param  list<int>  $branchIds */
    private function render(Request $request, array $branchIds): Response
    {
        $date = $request->filled('date') ? $request->string('date')->toString() : $this->clock->localDate();
        $userId = $request->filled('user') ? $request->integer('user') : null;

        return Inertia::render('Activity/Index', [
            'rows' => fn (): array => $this->activity->feed($branchIds, $date, $userId),
            'people' => fn (): array => $this->activity->people($branchIds),
            'date' => $date,
            'user_id' => $userId,
            'cross_branch' => count($branchIds) > 1,
        ]);
    }
}
