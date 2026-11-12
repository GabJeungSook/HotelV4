<?php

declare(strict_types=1);

namespace App\Actions\Stays;

use App\Enums\StayState;
use App\Exceptions\ActionRefused;
use App\Models\Stay;
use App\Models\User;
use App\Support\Transaction;

/**
 * "Release room" on the holds sheet (5) or the confirm screen (7), after the confirm dialog
 * (TRD §4.9, APP_FLOW §4.6): the held stay becomes cancelled with a closed reason. No approval
 * and no money; the room never left available, so it is on sale again at once. A hold that has
 * already expired or was released is left as it is.
 */
final class ReleaseKioskHold
{
    public function __construct(private readonly Transaction $transaction) {}

    public function handle(User $by, Stay $stay): Stay
    {
        return $this->transaction->run(function () use ($by, $stay): Stay {
            $locked = Stay::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($stay->id);

            if (in_array($locked->state, [StayState::Expired, StayState::Cancelled], true)) {
                return $locked;
            }

            if ($locked->state !== StayState::Held) {
                throw ActionRefused::because("{$locked->code} is not a kiosk hold any more.");
            }

            $locked->update(['state' => StayState::Cancelled, 'closed_reason' => mb_substr("Released at the front desk by {$by->name}", 0, 255)]);

            return $locked;
        });
    }
}
