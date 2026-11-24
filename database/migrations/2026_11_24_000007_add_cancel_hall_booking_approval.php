<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** approvals.action gains 'cancel_hall_booking' (DATABASE_SCHEMA §4.14): cancelling a hall booking
 * that forfeits a deposit takes a supervisor PIN, like cancelling a reservation. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE approvals MODIFY action ENUM('transfer','void_line','cancel_stay','void_cash_movement','void_sale','force_close_shift','close_without_handover','cancel_reservation','refund_no_show','change_shift_period','approve_variance','reason_requires_manager','cancel_hall_booking') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE approvals MODIFY action ENUM('transfer','void_line','cancel_stay','void_cash_movement','void_sale','force_close_shift','close_without_handover','cancel_reservation','refund_no_show','change_shift_period','approve_variance','reason_requires_manager') NOT NULL");
    }
};
