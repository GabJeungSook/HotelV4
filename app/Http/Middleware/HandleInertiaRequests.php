<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\User;
use App\Queries\ShiftQuery;
use App\Services\CurrentBranch;
use App\Services\PinGuard;
use App\Support\Navigation;
use Illuminate\Http\Request;
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
            'shift' => fn (): ?array => $this->shift($user),
            'nav' => fn (): array => $user instanceof User ? Navigation::for($user, app(CurrentBranch::class)->branch()) : [],
            'home' => fn (): ?string => $user instanceof User ? route('home', absolute: false) : null,
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
            ],
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
