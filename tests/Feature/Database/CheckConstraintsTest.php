<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\CashCount;
use App\Models\CashMovement;
use App\Models\Discount;
use App\Models\ExtensionRate;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PosOrder;
use App\Models\Product;
use App\Models\ProductIngredient;
use App\Models\Rate;
use App\Models\ShiftSession;
use App\Models\Stay;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| Every CHECK constraint in DATABASE_SCHEMA §4 rejects a bad row on MySQL.
| A valid row is made by the model's factory, one column is broken, and the
| row is inserted directly so only the database is being tested.
*/

/**
 * @param  class-string<Model>  $model
 * @param  array<string, mixed>  $broken
 */
function insertBroken(string $model, array $broken): void
{
    /** @var Model $row */
    $row = $model::factory()->make();
    $attributes = array_merge($row->getAttributes(), $broken);

    DB::table($row->getTable())->insert($attributes);
}

it('rejects a row that breaks a CHECK constraint', function (string $model, array $broken, string $constraint): void {
    try {
        insertBroken($model, $broken);
    } catch (QueryException $e) {
        expect($e->errorInfo[1] ?? null)->toBe(3819)
            ->and($e->getMessage())->toContain("'{$constraint}'");

        return;
    }

    $this->fail("Expected CHECK constraint {$constraint} to reject the row.");
})->with([
    'branch with no payment method' => [Branch::class, ['payment_methods' => '[]'], 'branches_methods_chk'],
    'branch with a negative deposit' => [Branch::class, ['deposit_amount' => -1], 'branches_amounts_chk'],
    'owner tied to a branch' => [User::class, ['role' => 'owner'], 'users_owner_branch_chk'],
    'staff without a branch' => [User::class, ['branch_id' => null], 'users_owner_branch_chk'],
    'rate of ₱0' => [Rate::class, ['amount' => 0], 'rates_amount_chk'],
    'overnight rate with hours' => [Rate::class, ['stay_kind' => 'overnight', 'hours' => 6], 'rates_hours_chk'],
    'hourly rate of 25 hours' => [Rate::class, ['hours' => 25], 'rates_hours_chk'],
    'extension of 0 hours' => [ExtensionRate::class, ['hours' => 0], 'extension_rates_chk'],
    'discount of 101%' => [Discount::class, ['kind' => 'percent', 'value' => 101], 'discounts_value_chk'],
    'fixed discount of ₱0' => [Discount::class, ['value' => 0], 'discounts_value_chk'],
    'closed shift without closed_at' => [ShiftSession::class, ['status' => 'closed'], 'shift_sessions_closed_chk'],
    'open shift with closed_at' => [ShiftSession::class, ['closed_at' => '2026-11-11 12:00:00'], 'shift_sessions_closed_chk'],
    'variance without a note' => [ShiftSession::class, ['variance_amount' => -5000], 'shift_sessions_variance_chk'],
    'checked-in stay without a shift' => [Stay::class, ['checkin_shift_id' => null], 'stays_in_chk'],
    'checked-in stay with a check-out time' => [Stay::class, ['checked_out_at' => '2026-11-11 05:00:00'], 'stays_out_chk'],
    'checked-out stay without a check-out time' => [Stay::class, ['state' => 'checked_out'], 'stays_out_chk'],
    'hold without an expiry' => [Stay::class, ['state' => 'held', 'hold_expires_at' => null], 'stays_hold_chk'],
    'guest name under 3 letters' => [Stay::class, ['guest_name' => 'Al'], 'stays_name_chk'],
    'product at ₱0' => [Product::class, ['price_amount' => 0], 'products_price_chk'],
    'negative stock on hand' => [Product::class, ['stock_on_hand' => -1], 'products_stock_chk'],
    'recipe line of 0' => [ProductIngredient::class, ['quantity' => 0], 'product_ingredients_qty_chk'],
    'rate with no scope' => [Rate::class, ['room_type_id' => null], 'rates_scope_chk'],
    'room charge without a stay' => [PosOrder::class, ['mode' => 'room_charge'], 'pos_orders_mode_chk'],
    'folio line without stay or order' => [FolioLine::class, ['stay_id' => null], 'folio_lines_owner_chk'],
    'folio line with wrong maths' => [FolioLine::class, ['amount' => 1], 'folio_lines_math_chk'],
    'reversal reason without a reversed line' => [FolioLine::class, ['reversal_reason' => 'Typed wrong amount'], 'folio_lines_reversal_chk'],
    'payment without stay or order' => [Payment::class, ['stay_id' => null], 'payments_owner_chk'],
    'negative payment' => [Payment::class, ['amount' => -100], 'payments_sign_chk'],
    'positive refund' => [Payment::class, ['kind' => 'refund', 'amount' => 100], 'payments_sign_chk'],
    'deposit without a type' => [Payment::class, ['kind' => 'deposit_in'], 'payments_deposit_chk'],
    'payment with a deposit type' => [Payment::class, ['deposit_type' => 'key'], 'payments_deposit_chk'],
    'deposit applied in cash' => [Payment::class, ['kind' => 'deposit_applied', 'deposit_type' => 'guest'], 'payments_method_chk'],
    'GCash without a reference' => [Payment::class, ['method' => 'gcash'], 'payments_ref_chk'],
    'stock balance below zero' => [StockMovement::class, ['kind' => 'sale', 'quantity' => -80, 'balance_after' => -6], 'stock_movements_balance_chk'],
    'wastage without a note' => [StockMovement::class, ['kind' => 'wastage', 'quantity' => -1, 'balance_after' => 49], 'stock_movements_note_chk'],
    'cash movement of ₱0' => [CashMovement::class, ['amount' => 0], 'cash_movements_amount_chk'],
    'expense without a category' => [CashMovement::class, ['expense_category_id' => null], 'cash_movements_cat_chk'],
    'void without a reason' => [CashMovement::class, ['voided_at' => '2026-11-11 03:00:00'], 'cash_movements_void_chk'],
    'negative cash count' => [CashCount::class, ['total_cash' => -1], 'cash_counts_total_chk'],
]);

it('has every CHECK constraint of the schema in the database', function (): void {
    $names = collect(DB::select('SELECT constraint_name AS name FROM information_schema.check_constraints WHERE constraint_schema = DATABASE()'))
        ->pluck('name')->sort()->values()->all();

    expect($names)->toBe([
        'branches_amounts_chk', 'branches_methods_chk', 'cash_counts_total_chk', 'cash_movements_amount_chk',
        'cash_movements_cat_chk', 'cash_movements_void_chk', 'discounts_value_chk', 'extension_rates_chk',
        'folio_lines_math_chk', 'folio_lines_owner_chk', 'folio_lines_reversal_chk',
        'hall_booking_items_amount_chk', 'hall_bookings_cancel_chk', 'hall_bookings_complete_chk', 'hall_bookings_time_chk',
        'hall_packages_amount_chk', 'halls_rate_chk', 'payments_deposit_chk',
        'payments_method_chk', 'payments_owner_chk', 'payments_ref_chk', 'payments_sign_chk', 'pos_orders_mode_chk',
        'product_ingredients_qty_chk', 'product_ingredients_self_chk',
        'products_price_chk', 'products_stock_chk', 'rates_amount_chk', 'rates_hours_chk', 'rates_scope_chk', 'shift_sessions_closed_chk',
        'shift_sessions_variance_chk', 'stays_hold_chk', 'stays_in_chk', 'stays_name_chk', 'stays_out_chk',
        'stock_movements_balance_chk', 'stock_movements_note_chk', 'users_owner_branch_chk',
    ]);
});

it('accepts a variance with a note, and an unexplained variance only on a force close', function (): void {
    insertBroken(ShiftSession::class, ['variance_amount' => -5000, 'variance_note' => 'Gave wrong change to Room 203, ₱50.']);
    insertBroken(ShiftSession::class, ['variance_amount' => -5000, 'is_force_closed' => 1]);

    expect(ShiftSession::query()->count())->toBe(2);
});
