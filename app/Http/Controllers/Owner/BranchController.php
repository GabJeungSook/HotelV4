<?php

declare(strict_types=1);

namespace App\Http\Controllers\Owner;

use App\Actions\Owner\CreateBranch;
use App\Actions\Owner\SetBranchStatus;
use App\Enums\BranchStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\BranchStatusRequest;
use App\Http\Requests\CreateBranchRequest;
use App\Models\Branch;
use App\Models\User;
use App\Queries\OwnerBranchQuery;
use App\Services\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Owner branches (55), add branch (56), open a branch and go back to "All branches"
 * (APP_FLOW §2, §4.20, §8.1). Owner only (route group). The opened branch is kept in the server
 * session by CurrentBranch; a branch id from the browser is only ever an Open request.
 */
final class BranchController extends Controller
{
    public function __construct(
        private readonly OwnerBranchQuery $branches,
        private readonly CurrentBranch $currentBranch,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Owner/Branches', [
            'branches' => $this->branches->list(),
            'opened_id' => $this->currentBranch->id(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Owner/BranchForm', [
            'sources' => $this->branches->copySources(),
        ]);
    }

    public function store(CreateBranchRequest $request, CreateBranch $create): RedirectResponse
    {
        $result = $create->handle(self::user($request), $request->branchData());

        Inertia::flash('created', [
            'kind' => 'branch',
            'branch' => $result['branch']->name,
            'name' => $result['admin']->name,
            'username' => $result['admin']->username,
            'password' => $result['password'],
        ]);

        return redirect()->route('owner.branches.index');
    }

    public function update(BranchStatusRequest $request, Branch $branch, SetBranchStatus $status): RedirectResponse
    {
        $to = $request->status();
        $status->handle(self::user($request), $branch->id, $to);

        Inertia::flash('toast', ['type' => 'success', 'message' => $to === BranchStatus::Inactive
            ? "{$branch->name} is deactivated. Its history stays readable."
            : "{$branch->name} is active again."]);

        return redirect()->route('owner.branches.index');
    }

    /** "Open": work inside that branch with admin rights, landing on its dashboard (61). */
    public function open(Branch $branch): RedirectResponse
    {
        $this->currentBranch->open($branch->id);

        return redirect()->route('dashboard');
    }

    /** Back to "All branches". */
    public function close(): RedirectResponse
    {
        $this->currentBranch->close();

        return redirect()->route('owner.dashboard');
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
