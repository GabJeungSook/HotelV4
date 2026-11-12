<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE_SCHEMA §4.9. The reason_id foreign key is added in 000028, after
 * the reasons table exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('shift_session_id');
            $table->enum('kind', ['expense', 'remittance', 'shortage_payment']);
            $table->unsignedBigInteger('expense_category_id')->nullable();
            $table->unsignedBigInteger('for_shift_id')->nullable(); // shortage_payment: the short shift being repaid
            $table->bigInteger('amount'); // always positive; sign comes from kind
            $table->string('note', 255);
            $table->unsignedBigInteger('created_by');
            $table->timestamp('occurred_at');
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->unsignedBigInteger('approval_id')->nullable();
            $table->unsignedBigInteger('reason_id')->nullable();
            $table->char('idempotency_key', 36)->nullable();
            $table->timestamps();

            $table->unique('idempotency_key', 'cash_movements_idempotency_unique');
            $table->index(['shift_session_id', 'kind'], 'cash_movements_shift_idx');
            $table->foreign('branch_id', 'cash_movements_branch_fk')->references('id')->on('branches');
            $table->foreign('shift_session_id', 'cash_movements_shift_fk')->references('id')->on('shift_sessions');
            $table->foreign('expense_category_id', 'cash_movements_cat_fk')->references('id')->on('expense_categories');
            $table->foreign('for_shift_id', 'cash_movements_for_shift_fk')->references('id')->on('shift_sessions');
            $table->foreign('created_by', 'cash_movements_user_fk')->references('id')->on('users');
        });

        DB::statement('ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_amount_chk CHECK (amount > 0)');
        DB::statement("ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_cat_chk CHECK (kind <> 'expense' OR expense_category_id IS NOT NULL)");
        DB::statement('ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_void_chk CHECK ((voided_at IS NULL) = (void_reason IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
