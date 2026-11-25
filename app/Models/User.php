<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Staff, owner accounts and kiosk devices (DATABASE_SCHEMA §4.2).
 * Not branch-scoped: sign-in looks users up before any branch is known,
 * and owners have no branch. No shift state is stored here.
 *
 * @property Role $role
 * @property int|null $branch_id
 */
class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    protected $fillable = [
        'branch_id', 'name', 'username', 'password', 'must_change_password', 'pin_hash', 'role',
        'is_active', 'failed_logins', 'locked_until', 'last_seen_at',
    ];

    protected $hidden = [
        'password',
        'pin_hash',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'pin_hash' => 'hashed',
            'role' => Role::class,
            'must_change_password' => 'boolean',
            'is_active' => 'boolean',
            'locked_until' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Housekeeper floor assignment (47).
     *
     * @return BelongsToMany<Floor, $this>
     */
    public function floors(): BelongsToMany
    {
        return $this->belongsToMany(Floor::class);
    }

    /** @return HasMany<ShiftMember, $this> */
    public function shiftMemberships(): HasMany
    {
        return $this->hasMany(ShiftMember::class);
    }
}
