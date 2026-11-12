<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** DATABASE_SCHEMA §4.12: the cash ledger, payments and payment_allocations (append-only) */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('shift_session_id');
            $table->unsignedBigInteger('stay_id')->nullable();
            $table->unsignedBigInteger('pos_order_id')->nullable();
            $table->enum('kind', ['payment', 'deposit_in', 'deposit_refund', 'deposit_applied', 'refund']);
            $table->enum('method', ['cash', 'gcash', 'card', 'deposit']);
            $table->enum('deposit_type', ['key', 'guest'])->nullable();
            $table->bigInteger('amount'); // signed: money to the hotel is positive
            $table->bigInteger('tendered_amount')->nullable();
            $table->bigInteger('change_amount')->nullable();
            $table->string('reference_no', 40)->nullable();
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->unsignedBigInteger('approval_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');

            $table->index(['branch_id', 'shift_session_id', 'method', 'kind'], 'payments_shift_idx');
            $table->index(['stay_id', 'kind'], 'payments_stay_idx');
            $table->foreign('branch_id', 'payments_branch_fk')->references('id')->on('branches');
            $table->foreign('shift_session_id', 'payments_shift_fk')->references('id')->on('shift_sessions');
            $table->foreign('stay_id', 'payments_stay_fk')->references('id')->on('stays');
            $table->foreign('pos_order_id', 'payments_pos_fk')->references('id')->on('pos_orders');
            $table->foreign('reversal_of_id', 'payments_reversal_fk')->references('id')->on('payments');
            $table->foreign('created_by', 'payments_user_fk')->references('id')->on('users');
        });

        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_owner_chk CHECK (stay_id IS NOT NULL OR pos_order_id IS NOT NULL)');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_sign_chk CHECK ((kind IN ('payment','deposit_in','deposit_applied') AND amount > 0) OR (kind IN ('deposit_refund','refund') AND amount < 0))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_deposit_chk CHECK ((kind IN ('deposit_in','deposit_refund','deposit_applied')) = (deposit_type IS NOT NULL))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_chk CHECK ((kind = 'deposit_applied') = (method = 'deposit'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_ref_chk CHECK (method NOT IN ('gcash','card') OR reference_no IS NOT NULL)");

        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('folio_line_id');
            $table->bigInteger('amount');
            $table->timestamp('created_at');

            $table->index('folio_line_id', 'payment_allocations_line_idx');
            $table->index('payment_id', 'payment_allocations_payment_idx');
            $table->foreign('payment_id', 'payment_allocations_payment_fk')->references('id')->on('payments');
            $table->foreign('folio_line_id', 'payment_allocations_line_fk')->references('id')->on('folio_lines');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};
