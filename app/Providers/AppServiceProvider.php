<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\CurrentBranch;
use App\Services\ShiftCalendar;
use App\Support\Clock;
use App\Support\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Clock::class);

        // One per request or job: the branch an owner opened lives in that request's session.
        $this->app->scoped(CurrentBranch::class);

        // Tracks the outermost action transaction and the writes that must outlive a rollback.
        $this->app->scoped(Transaction::class);

        $this->app->singleton(ShiftCalendar::class, fn (): ShiftCalendar => new ShiftCalendar(Clock::displayTimezone()));
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
    }
}
