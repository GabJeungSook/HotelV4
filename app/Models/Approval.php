<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApprovalAction;
use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToBranch;
use Database\Factories\ApprovalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Supervisor PIN approvals (DATABASE_SCHEMA §4.15). Append-only. `reason` is the
 * text (reason name plus typed note); `pickedReason` is the reasons row, if any.
 */
class Approval extends Model
{
    use AppendOnly;
    use BelongsToBranch;

    /** @use HasFactory<ApprovalFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'branch_id', 'action', 'subject_type', 'subject_id', 'requested_by', 'approved_by',
        'reason_id', 'reason', 'summary', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'action' => ApprovalAction::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<Reason, $this> */
    public function pickedReason(): BelongsTo
    {
        return $this->belongsTo(Reason::class, 'reason_id');
    }
}
