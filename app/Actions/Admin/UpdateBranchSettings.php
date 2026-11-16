<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\ExtensionRule;
use App\Enums\PaymentMethod;
use App\Exceptions\ActionRefused;
use App\Models\Branch;
use App\Models\User;
use App\Services\AuditTrail;
use App\Support\Money;
use App\Support\Transaction;
use BackedEnum;
use Illuminate\Support\Collection;

/**
 * Save one group of branch settings (46; DATABASE_SCHEMA §4.1): stays, money, shifts, modules,
 * VAT, or slip and house rules. Only the keys in GROUPS can be written here; the counters
 * (next_*_number), the name, the code prefix and the status are not settings. Refused, with
 * nothing saved: no payment method, no kind of stay switched on, VAT on without a TIN and a
 * registered name. One transaction with its audit_logs row 'settings.updated' holding only the
 * values that changed.
 *
 * Switching hourly stays off removes the hourly options from check-in (6), the kiosk (28) and
 * the rate table (45): each of them reads branches.hourly_enabled.
 */
final class UpdateBranchSettings
{
    /** @var array<string, list<string>> */
    public const GROUPS = [
        'stays' => [
            'hourly_enabled', 'overnight_enabled', 'overnight_checkin_time', 'overnight_checkout_time',
            'long_stay_enabled', 'long_stay_max_days', 'extension_rule', 'extension_cycle_hours',
            'extension_per_hour_amount', 'due_soon_minutes',
        ],
        'money' => ['deposit_enabled', 'deposit_name', 'deposit_amount', 'lost_key_amount', 'lost_remote_amount', 'payment_methods'],
        'shifts' => ['max_frontdesks_per_shift', 'shift_overdue_warn_minutes', 'shift_stale_block_minutes'],
        'modules' => [
            'kiosk_enabled', 'kiosk_hold_minutes', 'reservations_enabled', 'reservation_release_minutes',
            'pos_enabled', 'housekeeping_enabled', 'cleaning_deadline_minutes', 'cleaning_target_minutes', 'cleaning_urgent_minutes',
        ],
        'vat' => ['vat_enabled', 'vat_rate_percent', 'tin', 'registered_name'],
        'printing' => ['slip_header', 'address', 'house_rules'],
    ];

    private const MONEY = ['extension_per_hour_amount', 'deposit_amount', 'lost_key_amount', 'lost_remote_amount'];

    private const TIMES = ['overnight_checkin_time', 'overnight_checkout_time'];

    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Transaction $transaction,
    ) {}

    /** @param  array<string, mixed>  $values  setting → new value; money in centavos, times as HH:MM */
    public function handle(User $by, int $branchId, array $values): Branch
    {
        $allowed = array_merge(...array_values(self::GROUPS));
        $unknown = array_diff(array_keys($values), $allowed);

        if ($unknown !== []) {
            throw ActionRefused::because('These are not branch settings: '.implode(', ', $unknown).'.');
        }

        return $this->transaction->run(function () use ($by, $branchId, $values, $allowed): Branch {
            $branch = Branch::query()->lockForUpdate()->findOrFail($branchId);

            $before = [];
            $after = [];

            foreach ($values as $key => $value) {
                $before[$key] = self::plain($branch->getAttribute($key));
                $after[$key] = self::normalise($key, $value);
            }

            $merged = [...array_map(self::plain(...), $branch->only($allowed)), ...$after];
            $this->refuse($merged);

            [$old, $new] = AuditTrail::diff($before, $after);

            if ($new === []) {
                return $branch;
            }

            $branch->update(self::forModel($new));
            $this->audit->record($by, $branchId, 'settings.updated', 'branch', $branchId, $old, $new);

            return $branch;
        });
    }

    /** @param  array<string, mixed>  $s */
    private function refuse(array $s): void
    {
        if (! $s['hourly_enabled'] && ! $s['overnight_enabled'] && ! $s['long_stay_enabled']) {
            throw ActionRefused::because('Switch on at least one kind of stay: hourly, overnight or long stay.');
        }

        if (! is_array($s['payment_methods']) || $s['payment_methods'] === []) {
            throw ActionRefused::because('Accept at least one payment method.');
        }

        if ($s['vat_enabled'] && (blank($s['tin']) || blank($s['registered_name']))) {
            throw ActionRefused::because('A VAT-registered branch needs its TIN and registered name for the Z-report.');
        }

        if ($s['extension_rule'] === ExtensionRule::PerHour->value && (int) $s['extension_per_hour_amount'] <= 0) {
            throw ActionRefused::because('Per-hour extensions need the amount per hour.');
        }
    }

    private static function normalise(string $key, mixed $value): mixed
    {
        if (str_ends_with($key, '_enabled')) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        if (in_array($key, self::MONEY, true)) {
            return (int) $value;
        }

        if (in_array($key, self::TIMES, true)) {
            return strlen((string) $value) === 5 ? $value.':00' : (string) $value;
        }

        if ($key === 'payment_methods') {
            $methods = array_values(array_unique(array_map('strval', (array) $value)));
            $order = array_map(fn (PaymentMethod $m): string => $m->value, PaymentMethod::cases());

            return array_values(array_intersect($order, $methods));
        }

        if (in_array($key, ['tin', 'registered_name', 'house_rules'], true)) {
            return $value === null || trim((string) $value) === '' ? null : trim((string) $value);
        }

        if (is_bool($value) || is_int($value) || $value === null) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : trim((string) $value);
    }

    /** A model attribute as a plain JSON value: Money as centavos, enums as their value. */
    private static function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Money => $value->centavos(),
            $value instanceof BackedEnum => $value->value,
            $value instanceof Collection => $value->map(fn ($v) => $v instanceof BackedEnum ? $v->value : $v)->values()->all(),
            default => $value,
        };
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function forModel(array $values): array
    {
        foreach (self::MONEY as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = Money::ofCentavos((int) $values[$key]);
            }
        }

        return $values;
    }
}
