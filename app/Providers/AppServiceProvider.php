<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\CurrentBranch;
use App\Services\ShiftCalendar;
use App\Support\Clock;
use App\Support\Transaction;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        // A guard per computer against guessing many accounts; each account also locks after
        // five wrong passwords for 15 minutes (users.locked_until, TRD §9).
        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(30)->by((string) $request->ip()));
    }
}
