<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use App\Queries\NotificationQuery;
use App\Queries\ShiftQuery;
use App\Services\CurrentBranch;
use App\Services\PinGuard;
use App\Support\Navigation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Middleware;

/**
 * Shared props on every page (SYSTEM_DESIGN §4.3): the user, the branch and its modules, the
 * open shift for front desk roles, the server-built navigation and whether PIN entry is
 * locked on this computer. Never expected cash, collected amounts or sales totals.
 */
class HandleInertiaRequests extends Middleware
{
    /**
     * The root template loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'app' => [
                'name' => config('hotel.name'),
                'heartbeat' => config('hotel.polling.heartbeat'),
            ],
            'auth' => fn (): ?array => $user instanceof User ? [
                'user' => ['id' => $user->id, 'name' => $user->name, 'username' => $user->username],
                'role' => $user->role->value,
                'roleLabel' => $user->role->label(),
            ] : null,
            'branch' => fn (): ?array => $this->branch(),
            // The branch switcher card in the sidebar: "46 rooms · General Santos City" (mockup v6).
            // Its own key, because some pages send a `branch` prop of their own.
            'branchCard' => fn (): ?array => $this->branchCard(),
            'shift' => fn (): ?array => $this->shift($user),
            // The top-bar bell: a live count and the first items. The desk, back office, admin and
            // owner have a feed; the kiosk and housekeeper phones do not (so no bell shows).
            'notifications' => fn (): ?array => $this->notifications($user),
            'nav' => fn (): array => $user instanceof User ? Navigation::for($user, app(CurrentBranch::class)->branch()) : [],
            'home' => fn (): ?string => $user instanceof User ? route('home', absolute: false) : null,
            // The branch picker on reports and dashboards (ROADMAP Phase 9): owners only.
            'ownerBranches' => fn (): array => $user instanceof User && $user->role === Role::Owner
                ? Branch::query()->withoutGlobalScopes()->withCount(['rooms' => fn ($q) => $q->withoutGlobalScopes()->where('is_active', true)])->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Branch $b): array => ['id' => $b->id, 'name' => $b->name, 'rooms' => (int) $b->getAttribute('rooms_count')])->all()
                : [],
            'pinLocked' => fn (): bool => $user instanceof User && app(PinGuard::class)->isLocked(EnsureDeviceKey::of($request)),
        ];
    }

    /** @return array{id: int, name: string, status: string, modules: array<string, bool>}|null */
    private function branch(): ?array
    {
        $branch = app(CurrentBranch::class)->branch();

        if ($branch === null) {
            return null;
        }

        return [
            'id' => $branch->id,
            'name' => $branch->name,
            'status' => $branch->status->value,
            'modules' => [
                'kiosk_enabled' => $branch->kiosk_enabled,
                'reservations_enabled' => $branch->reservations_enabled,
                'pos_enabled' => $branch->pos_enabled,
                'housekeeping_enabled' => $branch->housekeeping_enabled,
                'event_hall_enabled' => $branch->event_hall_enabled,
            ],
        ];
    }

    /** @return array{name: string, rooms: int, place: string}|null */
    private function branchCard(): ?array
    {
        $branch = app(CurrentBranch::class)->branch();

        return $branch === null ? null : [
            'name' => $branch->name,
            'rooms' => $branch->rooms()->withoutGlobalScopes()->where('is_active', true)->count(),
            'place' => trim(Str::afterLast($branch->address, ',')),
        ];
    }

    /** @return array{count: int, unread: int, items: list<array<string, mixed>>, href: string}|null */
    private function notifications(mixed $user): ?array
    {
        if (! $user instanceof User || ! in_array($user->role, [Role::Frontdesk, Role::Supervisor, Role::Backoffice, Role::Admin, Role::Owner], true)) {
            return null;
        }

        $feed = app(NotificationQuery::class)->for($user);

        return [
            'count' => count($feed), // total open items — the panel header ("N open")
            'unread' => NotificationQuery::unread($feed, $user->notifications_seen_at), // the bell badge
            'items' => array_slice($feed, 0, 8),
            'href' => route('notifications', absolute: false),
        ];
    }

    /** @return array<string, mixed>|null */
    private function shift(mixed $user): ?array
    {
        if (! $user instanceof User || $user->branch_id === null || ! in_array($user->role, [Role::Frontdesk, Role::Supervisor], true)) {
            return null;
        }

        return app(ShiftQuery::class)->current($user->branch_id);
    }
}
