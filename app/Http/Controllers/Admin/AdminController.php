<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Services\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Shared by the branch setup controllers (44–48). The routes let in an admin (their own branch)
 * and an owner who opened a branch (EnsureBranchOpened); every action is given that branch id,
 * never one from the browser.
 */
abstract class AdminController extends Controller
{
    public function __construct(protected readonly CurrentBranch $currentBranch) {}

    protected function branch(): Branch
    {
        $id = $this->currentBranch->id();
        abort_if($id === null, 403);

        return Branch::query()->findOrFail($id);
    }

    protected function branchId(): int
    {
        $id = $this->currentBranch->id();
        abort_if($id === null, 403);

        return $id;
    }

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    protected function saved(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect()->back(fallback: route('home'));
    }
}
