<?php

declare(strict_types=1);

namespace App\Actions\Stays;

use App\Actions\Auth\ApproveByPin;
use App\Enums\ApprovalAction;
use App\Enums\ReasonKind;
use App\Enums\StayState;
use App\Exceptions\ActionRefused;
use App\Models\FolioLine;
use App\Models\Reason;
use App\Models\Stay;
use App\Models\User;
use App\Services\CurrentShift;
use App\Services\StayLedger;
use App\Support\ReasonText;
use App\Support\Transaction;

/**
 * Void one folio line with a reason and a supervisor's approval (dialog 15 → sheet 14,
 * DATABASE_SCHEMA §8.9, APP_FLOW §4.11). Nothing is deleted: a reversing line is posted to
 * the open shift, what had been paid on the line is refunded in cash, and stock comes back.
 */
final class VoidLine
{
    public function __construct(
        private readonly CurrentShift $currentShift,
        private readonly StayLedger $ledger,
        private readonly ApproveByPin $approveByPin,
        private readonly Transaction $transaction,
    ) {}

    public function handle(
        User $by,
        FolioLine $line,
        Reason $reason,
        User $approver,
        string $approverPin,
        string $deviceKey,
        ?string $note = null,
    ): FolioLine {
        $text = ReasonText::for($reason, ReasonKind::VoidLine, $note);

        return $this->transaction->run(function () use ($by, $line, $reason, $approver, $approverPin, $deviceKey, $text): FolioLine {
            $shift = $this->currentShift->forWrite($line->branch_id);

            if ($line->stay_id === null) {
                throw ActionRefused::lineNotVoidable('Walk-in sales are voided from the POS.');
            }

            $stay = Stay::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($line->stay_id);
            $target = FolioLine::query()->withoutGlobalScopes()->withExists('reversal')->findOrFail($line->id);

            if ($stay->state !== StayState::CheckedIn) {
                throw ActionRefused::stayNotInHouse($stay->code);
            }

            if ($target->reversal_of_id !== null) {
                throw ActionRefused::lineNotVoidable('A void cannot be voided.');
            }

            if ((bool) $target->getAttribute('reversal_exists')) {
                throw ActionRefused::lineNotVoidable('This line is already voided.');
            }

            $approval = $this->approveByPin->handle(
                $approver, $approverPin, $deviceKey, $by, $stay->branch_id,
                ApprovalAction::VoidLine, 'folio_line', $target->id, $text,
                "{$stay->code} · {$target->description} · ".($target->amount?->format() ?? ''),
                $reason->id,
            );

            $this->ledger->reverse($shift, $stay, $by, [$target], $text, $approval->id);

            return FolioLine::query()->withoutGlobalScopes()->where('reversal_of_id', $target->id)->sole();
        });
    }
}
