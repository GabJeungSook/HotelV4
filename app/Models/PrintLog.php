<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PrintDocument;
use App\Enums\PrintFormat;
use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToBranch;
use Database\Factories\PrintLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Every print and export of a slip or report (DATABASE_SCHEMA §4.16). Append-only. */
class PrintLog extends Model
{
    use AppendOnly;
    use BelongsToBranch;

    /** @use HasFactory<PrintLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['branch_id', 'document', 'subject_id', 'format', 'is_reprint', 'user_id', 'shift_session_id', 'created_at'];

    protected function casts(): array
    {
        return [
            'document' => PrintDocument::class,
            'format' => PrintFormat::class,
            'is_reprint' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ShiftSession, $this> */
    public function shiftSession(): BelongsTo
    {
        return $this->belongsTo(ShiftSession::class);
    }
}
