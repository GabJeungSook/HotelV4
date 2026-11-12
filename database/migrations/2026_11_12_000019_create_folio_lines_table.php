<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** DATABASE_SCHEMA §4.11: the sales ledger (append-only) */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folio_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('shift_session_id');
            $table->unsignedBigInteger('stay_id')->nullable(); // NULL for walk-in POS
            $table->unsignedBigInteger('pos_order_id')->nullable();
            $table->unsignedBigInteger('room_id')->nullable(); // room at the time of the charge
            $table->enum('kind', ['room', 'extension', 'transfer_difference', 'food_drink', 'amenity', 'damage', 'discount', 'forfeited_deposit']);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('description', 120);
            $table->integer('quantity')->default(1);
            $table->bigInteger('unit_amount');
            $table->bigInteger('amount'); // signed: discounts and reversals are negative
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->string('reversal_reason', 255)->nullable();
            $table->unsignedBigInteger('approval_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');

            $table->unique('reversal_of_id', 'folio_lines_reversal_unique');
            $table->index(['stay_id', 'occurred_at'], 'folio_lines_stay_idx');
            $table->index(['branch_id', 'shift_session_id', 'kind'], 'folio_lines_shift_idx');
            $table->index(['product_id', 'occurred_at'], 'folio_lines_product_idx');
            $table->foreign('branch_id', 'folio_lines_branch_fk')->references('id')->on('branches');
            $table->foreign('shift_session_id', 'folio_lines_shift_fk')->references('id')->on('shift_sessions');
            $table->foreign('stay_id', 'folio_lines_stay_fk')->references('id')->on('stays');
            $table->foreign('pos_order_id', 'folio_lines_pos_fk')->references('id')->on('pos_orders');
            $table->foreign('room_id', 'folio_lines_room_fk')->references('id')->on('rooms');
            $table->foreign('product_id', 'folio_lines_product_fk')->references('id')->on('products');
            $table->foreign('reversal_of_id', 'folio_lines_reversal_fk')->references('id')->on('folio_lines');
            $table->foreign('created_by', 'folio_lines_user_fk')->references('id')->on('users');
        });

        DB::statement('ALTER TABLE folio_lines ADD CONSTRAINT folio_lines_owner_chk CHECK (stay_id IS NOT NULL OR pos_order_id IS NOT NULL)');
        DB::statement('ALTER TABLE folio_lines ADD CONSTRAINT folio_lines_math_chk CHECK (amount = quantity * unit_amount)');
        DB::statement('ALTER TABLE folio_lines ADD CONSTRAINT folio_lines_reversal_chk CHECK ((reversal_of_id IS NULL) = (reversal_reason IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('folio_lines');
    }
};
