<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ShiftMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Who was on each shift (DATABASE_SCHEMA §4.8). Scoped through its shift. */
class ShiftMember extends Model
{
    /** @use HasFactory<ShiftMemberFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['shift_session_id', 'user_id', 'joined_at'];

    protected function casts(): array
    {
        return [
            'joined_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ShiftSession, $this> */
    public function shiftSession(): BelongsTo
    {
        return $this->belongsTo(ShiftSession::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
