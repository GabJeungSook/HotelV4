<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Services\CurrentBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * where branch_id = CurrentBranch, unless no branch is current (owner "All branches", console).
 *
 * @implements Scope<Model>
 */
final class BranchScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $branchId = app(CurrentBranch::class)->id();

        if ($branchId !== null) {
            $builder->where($model->qualifyColumn('branch_id'), $branchId);
        }
    }
}
