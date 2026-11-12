<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ReasonKind;
use App\Exceptions\ActionRefused;
use App\Models\Reason;

/**
 * The text stored for a picked reason: its name plus any typed note (approvals.reason).
 * Refuses a reason of another kind, an inactive one, or a missing note when the reason needs
 * one (TRD §4.12).
 */
final class ReasonText
{
    public static function for(Reason $reason, ReasonKind $kind, ?string $note): string
    {
        if ($reason->kind !== $kind || ! $reason->is_active) {
            throw ActionRefused::because('Pick a reason from the list.');
        }

        $note = $note === null ? '' : trim($note);

        if ($reason->needs_note && $note === '') {
            throw ActionRefused::noteRequired($reason->name);
        }

        return $note === '' ? $reason->name : "{$reason->name} — {$note}";
    }
}
