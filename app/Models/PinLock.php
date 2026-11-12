<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PinLockFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A computer whose PIN entry is locked after 10 wrong tries in a row (DATABASE_SCHEMA §4.16).
 * Never deleted; the unlock sets unlocked_at and unlocked_by (TRD §4.12), so this model is
 * not append-only. open_device_key is a generated column (one open lock per computer).
 */
class PinLock extends Model
{
    /** @use HasFactory<PinLockFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['device_key', 'locked_at', 'unlocked_at', 'unlocked_by'];

    protected function casts(): array
    {
        return [
            'locked_at' => 'immutable_datetime',
            'unlocked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function unlocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unlocked_by');
    }
}
