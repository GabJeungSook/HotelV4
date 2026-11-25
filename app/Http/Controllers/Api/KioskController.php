<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Stays\HoldRoom;
use App\Enums\StayKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\KioskHoldRequest;
use App\Models\Branch;
use App\Models\Stay;
use App\Models\User;
use App\Queries\KioskScreenQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Lobby kiosk API (mirrors KioskController): welcome, the rooms and hours to pick, the offer for
 * a choice, holding a room, and the held screen. Prices and rooms come from KioskScreenQuery; the
 * only write is HoldRoom, which is never sent a price. Same Action, request and policy as the web.
 */
final class KioskController extends Controller
{
    public function __construct(private readonly KioskScreenQuery $screens) {}

    public function welcome(Request $request): JsonResponse
    {
        return response()->json($this->screens->welcome($this->branch($request)));
    }

    /** The rooms and hours to choose from. */
    public function rooms(Request $request): JsonResponse
    {
        return response()->json($this->screens->start($this->branch($request)));
    }

    /** The offer (price + countdown) for a chosen type and stay; 409 if that type is full now. */
    public function offer(Request $request): JsonResponse
    {
        $kind = $request->string('kind')->toString() === 'long_stay' ? StayKind::LongStay : StayKind::Hourly;
        $quantity = $request->integer('quantity') ?: $request->integer('hours');
        $offer = $this->screens->details($this->branch($request), $request->integer('room_type'), $kind, $quantity);

        if ($offer === null) {
            return response()->json(['code' => 'room_taken', 'message' => 'That room type is full right now. Please pick another.'], 409);
        }

        return response()->json(['offer' => $offer]);
    }

    public function hold(KioskHoldRequest $request, HoldRoom $holdRoom): JsonResponse
    {
        Gate::authorize('hold', Stay::class);
        /** @var array{room_type_id: int|string, kind?: string|null, hours?: int|string|null, days?: int|string|null, guest_name: string, guest_mobile?: string|null, idempotency_key: string} $data */
        $data = $request->validated();
        $kind = ($data['kind'] ?? null) === 'long_stay' ? StayKind::LongStay : StayKind::Hourly;
        $quantity = $kind === StayKind::LongStay ? (int) ($data['days'] ?? 0) : (int) ($data['hours'] ?? 0);

        $stay = $holdRoom->handle(
            $this->user($request),
            (int) $data['room_type_id'],
            $kind,
            $quantity,
            $data['guest_name'],
            $data['guest_mobile'] ?? null,
            $data['idempotency_key'],
        );

        return response()->json(['code' => $stay->code, 'hold' => $this->screens->held($stay)], 201);
    }

    public function held(Request $request, string $code): JsonResponse
    {
        $stay = Stay::query()->where('code', $code)->where('source', 'kiosk')->firstOrFail();

        return response()->json(['hold' => $this->screens->held($stay)]);
    }

    private function branch(Request $request): Branch
    {
        return Branch::query()->findOrFail($this->user($request)->branch_id);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
