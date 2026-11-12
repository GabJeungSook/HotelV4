<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\CurrentBranch;
use App\Support\Clock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Clock::class);

        // One per request or job: the branch an owner opened lives in that request's session.
        $this->app->scoped(CurrentBranch::class);
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
    }
}
