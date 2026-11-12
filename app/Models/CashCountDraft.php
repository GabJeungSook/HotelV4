<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CashCountDraftFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The count being typed at close (DATABASE_SCHEMA §4.16, screen 23). Keyed by its
 * shift; deleted in the transaction that saves cash_counts. Holds pieces, never a total.
 */
class CashCountDraft extends Model
{
    /** @use HasFactory<CashCountDraftFactory> */
    use HasFactory;

    public const CREATED_AT = null;

    public $incrementing = false;

    protected $primaryKey = 'shift_session_id';

    protected $fillable = ['shift_session_id', 'denominations', 'updated_by'];

    protected function casts(): array
    {
        return [
            'denominations' => 'array',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ShiftSession, $this> */
    public function shiftSession(): BelongsTo
    {
        return $this->belongsTo(ShiftSession::class);
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
