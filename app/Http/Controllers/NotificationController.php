<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Queries\NotificationQuery;
use App\Support\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * All notifications (the full list behind the top-bar bell): the live feed from NotificationQuery
 * for the viewer's branch, read-only. Fixing an item happens on the screen it links to (the force
 * close and reset actions stay on the admin "Needs attention" screen, 49).
 */
final class NotificationController extends Controller
{
    public function index(Request $request, NotificationQuery $feed, Clock $clock): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Notifications/Index', [
            'rows' => fn (): array => $feed->for($user),
            'at' => $clock->now()->toIso8601String(),
        ]);
    }

    /** Mark the feed seen (bell opened or list viewed): the badge counts only items newer than this. */
    public function seen(Request $request, Clock $clock): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['notifications_seen_at' => $clock->now()])->save();

        return back();
    }
}
