<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Enums\PrintDocument;
use App\Enums\PrintFormat;
use App\Models\PrintLog;
use App\Models\User;
use App\Services\CurrentShift;
use App\Support\Clock;
use App\Support\Transaction;

/**
 * Every print or export of a slip or report writes one print_logs row (TRD §4.10, APP_FLOW §2
 * rule 6). A second print of the same document is marked is_reprint, so the slip says
 * "REPRINT" and the Z-report counts it under "Slip reprints". The row carries the shift open at
 * that moment (none when the branch has no open shift).
 */
final class LogPrint
{
    public function __construct(
        private readonly CurrentShift $currentShift,
        private readonly Clock $clock,
        private readonly Transaction $transaction,
    ) {}

    public function handle(User $by, int $branchId, PrintDocument $document, ?int $subjectId, PrintFormat $format = PrintFormat::Print): PrintLog
    {
        return $this->transaction->run(function () use ($by, $branchId, $document, $subjectId, $format): PrintLog {
            $earlier = PrintLog::query()->withoutGlobalScopes()
                ->where('branch_id', $branchId)
                ->where('document', $document)
                ->where('subject_id', $subjectId)
                ->lockForUpdate()
                ->exists();

            return PrintLog::query()->create([
                'branch_id' => $branchId,
                'document' => $document,
                'subject_id' => $subjectId,
                'format' => $format,
                'is_reprint' => $earlier,
                'user_id' => $by->id,
                'shift_session_id' => $this->currentShift->find($branchId)?->id,
                'created_at' => $this->clock->now(),
            ]);
        });
    }
}
