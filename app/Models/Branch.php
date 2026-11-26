<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BranchStatus;
use App\Enums\ExtensionRule;
use App\Enums\PaymentMethod;
use App\Support\Money;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A hotel branch and all its settings (DATABASE_SCHEMA §4.1). Not branch-scoped:
 * it is the branch. Per-branch values are read from here, never hard-coded.
 *
 * @property Collection<int, PaymentMethod> $payment_methods
 */
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'address', 'slip_header', 'code_prefix', 'status',
        'hourly_enabled', 'hourly_blocks', 'overnight_enabled', 'overnight_checkin_time', 'overnight_checkout_time',
        'long_stay_enabled', 'floor_room_rates_enabled', 'long_stay_max_days', 'extension_rule', 'extension_cycle_hours',
        'extension_per_hour_amount', 'due_soon_minutes',
        'deposit_enabled', 'deposit_name', 'deposit_amount', 'lost_key_amount', 'lost_remote_amount', 'payment_methods', 'denomination_count_enabled',
        'max_frontdesks_per_shift', 'shift_overdue_warn_minutes',
        'kiosk_enabled', 'kiosk_hold_minutes', 'kiosk_checkout_enabled', 'reservations_enabled', 'reservation_release_minutes', 'event_hall_enabled',
        'pos_enabled', 'housekeeping_enabled', 'cleaning_deadline_minutes', 'cleaning_target_minutes', 'cleaning_urgent_minutes',
        'house_rules', 'next_stay_number', 'next_pos_number', 'next_z_number', 'next_day_z_number',
        'shift_stale_block_minutes', 'vat_enabled', 'vat_rate_percent', 'tin', 'registered_name', 'welcome_enabled',
    ];

    protected function casts(): array
    {
        return [
            'status' => BranchStatus::class,
            'extension_rule' => ExtensionRule::class,
            'payment_methods' => AsEnumCollection::of(PaymentMethod::class),
            'hourly_blocks' => 'array',
            'hourly_enabled' => 'boolean',
            'overnight_enabled' => 'boolean',
            'long_stay_enabled' => 'boolean',
            'floor_room_rates_enabled' => 'boolean',
            'deposit_enabled' => 'boolean',
            'denomination_count_enabled' => 'boolean',
            'kiosk_enabled' => 'boolean',
            'kiosk_checkout_enabled' => 'boolean',
            'reservations_enabled' => 'boolean',
            'event_hall_enabled' => 'boolean',
            'pos_enabled' => 'boolean',
            'housekeeping_enabled' => 'boolean',
            'vat_enabled' => 'boolean',
            'welcome_enabled' => 'boolean',
            'extension_per_hour_amount' => Money::class,
            'deposit_amount' => Money::class,
            'lost_key_amount' => Money::class,
            'lost_remote_amount' => Money::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** The hourly rate blocks a branch sells when none is set yet. */
    public const DEFAULT_HOURLY_BLOCKS = [6, 12, 18, 24];

    /**
     * The hourly rate blocks this branch sells, as sorted, de-duplicated hours (1–24).
     *
     * @return list<int>
     */
    public function hourlyBlocks(): array
    {
        $blocks = array_values(array_unique(array_map('intval', $this->hourly_blocks ?? self::DEFAULT_HOURLY_BLOCKS)));
        sort($blocks);

        return array_values(array_filter($blocks, static fn (int $h): bool => $h >= 1 && $h <= 24));
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<ShiftDefinition, $this> */
    public function shiftDefinitions(): HasMany
    {
        return $this->hasMany(ShiftDefinition::class);
    }

    /** @return HasMany<Floor, $this> */
    public function floors(): HasMany
    {
        return $this->hasMany(Floor::class);
    }

    /** @return HasMany<RoomType, $this> */
    public function roomTypes(): HasMany
    {
        return $this->hasMany(RoomType::class);
    }

    /** @return HasMany<Room, $this> */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    /** @return HasMany<Rate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(Rate::class);
    }

    /** @return HasMany<ExtensionRate, $this> */
    public function extensionRates(): HasMany
    {
        return $this->hasMany(ExtensionRate::class);
    }

    /** @return HasMany<Discount, $this> */
    public function discounts(): HasMany
    {
        return $this->hasMany(Discount::class);
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** @return HasMany<ShiftSession, $this> */
    public function shiftSessions(): HasMany
    {
        return $this->hasMany(ShiftSession::class);
    }

    /** @return HasMany<Stay, $this> */
    public function stays(): HasMany
    {
        return $this->hasMany(Stay::class);
    }
}
