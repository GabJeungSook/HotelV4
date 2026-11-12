<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE_SCHEMA §4.13 (append-only). The reason_id foreign key is added in
 * 000028, after the reasons table exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('shift_session_id')->nullable(); // NULL only for admin adjustments outside a shift
            $table->enum('kind', ['receive', 'sale', 'sale_reversal', 'wastage', 'adjustment']);
            $table->integer('quantity'); // signed
            $table->integer('balance_after');
            $table->unsignedBigInteger('folio_line_id')->nullable();
            $table->unsignedBigInteger('reason_id')->nullable(); // wastage reason
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');

            $table->index(['product_id', 'occurred_at'], 'stock_movements_product_idx');
            $table->index(['branch_id', 'shift_session_id', 'kind'], 'stock_movements_shift_idx');
            $table->foreign('branch_id', 'stock_movements_branch_fk')->references('id')->on('branches');
            $table->foreign('product_id', 'stock_movements_product_fk')->references('id')->on('products');
            $table->foreign('shift_session_id', 'stock_movements_shift_fk')->references('id')->on('shift_sessions');
            $table->foreign('folio_line_id', 'stock_movements_line_fk')->references('id')->on('folio_lines');
            $table->foreign('created_by', 'stock_movements_user_fk')->references('id')->on('users');
        });

        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_balance_chk CHECK (balance_after >= 0)');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_note_chk CHECK (kind NOT IN ('wastage','adjustment') OR note IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
