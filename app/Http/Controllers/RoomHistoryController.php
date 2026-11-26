<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Room;
use App\Queries\RoomHistoryQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The all-time history of one room (new screen): a timeline of its stays, cleanings and manual
 * status changes. Read by front desk, supervisor, admin (own branch) and owner (any). Read-only.
 * `offset` loads the next page for "Load more"; the room is bound branch-scoped (BranchScope).
 */
final class RoomHistoryController extends Controller
{
    private const PAGE = 20;

    public function show(Request $request, Room $room, RoomHistoryQuery $query): Response
    {
        Gate::authorize('viewHistory', $room);

        $room->loadMissing('roomType');
        $offset = max(0, $request->integer('offset'));
        $kind = $request->string('type')->toString();
        $type = in_array($kind, ['stay', 'cleaning', 'status'], true) ? $kind : null;
        $q = mb_substr(trim($request->string('q')->toString()), 0, 60);

        return Inertia::render('Rooms/History', [
            'room' => [
                'id' => $room->id,
                'number' => $room->number,
                'type' => $room->roomType->name ?? '',
                'status' => $room->status->value,
                'statusLabel' => $room->status->label(),
            ],
            'page' => fn (): array => $query->timeline($room, self::PAGE, $offset, $type, $q),
            'offset' => $offset,
            'perPage' => self::PAGE,
            'filters' => ['type' => $type ?? 'all', 'q' => $q],
        ]);
    }
}
