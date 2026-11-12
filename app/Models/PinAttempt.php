<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PinContext;
use App\Models\Concerns\AppendOnly;
use Database\Factories\PinAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every PIN try, right or wrong, per computer (DATABASE_SCHEMA §4.16). Append-only.
 * Keyed by computer (device_key), not branch; branch_id is nullable, so not branch-scoped.
 */
class PinAttempt extends Model
{
    use AppendOnly;

    /** @use HasFactory<PinAttemptFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['branch_id', 'device_key', 'user_id', 'context', 'succeeded', 'created_at'];

    protected function casts(): array
    {
        return [
            'context' => PinContext::class,
            'succeeded' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
