<?php

declare(strict_types=1);

namespace App\Actions\Shifts;

use App\Enums\ShiftStatus;
use App\Exceptions\ActionRefused;
use App\Models\CashCount;
use App\Models\CashCountDraft;
use App\Models\User;
use App\Services\CurrentShift;
use App\Support\CashPieces;
use App\Support\Transaction;

/**
 * The count being typed at close (23), saved after each change (debounced 1 s in the browser,
 * PUT /shift/close/draft) so a reload or a power cut restores it (APP_FLOW §6 case 74). Only
 * the pieces are stored; a total sent is ignored. Nothing is saved once the count is confirmed.
 */
final class SaveCountDraft
{
    public function __construct(
        private readonly CurrentShift $currentShift,
        private readonly Transaction $transaction,
    ) {}

    /** @param  array<array-key, mixed>  $pieces */
    public function handle(User $by, int $branchId, array $pieces): CashCountDraft
    {
        return $this->transaction->run(function () use ($by, $branchId, $pieces): CashCountDraft {
            $shift = $this->currentShift->for($branchId);

            if ($shift->status !== ShiftStatus::Closing || CashCount::query()->where('shift_session_id', $shift->id)->exists()) {
                throw ActionRefused::because('There is no count being typed on this shift.');
            }

            $draft = CashCountDraft::query()->firstOrNew(['shift_session_id' => $shift->id]);
            $draft->fill(['denominations' => CashPieces::normalize($pieces), 'updated_by' => $by->id]);
            $draft->save();

            return $draft;
        });
    }
}
