<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** DATABASE_SCHEMA §4.13 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('shift_session_id');
            $table->unsignedBigInteger('stay_id')->nullable(); // set when charged to a room
            $table->enum('mode', ['walk_in', 'room_charge']);
            $table->bigInteger('total_amount');
            $table->unsignedBigInteger('created_by');
            $table->string('order_no', 12); // 'P-008821', from branches.next_pos_number
            $table->char('idempotency_key', 36)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');

            $table->unique(['branch_id', 'order_no'], 'pos_orders_no_unique');
            $table->unique('idempotency_key', 'pos_orders_idempotency_unique');
            $table->index(['branch_id', 'shift_session_id'], 'pos_orders_shift_idx');
            $table->foreign('branch_id', 'pos_orders_branch_fk')->references('id')->on('branches');
            $table->foreign('shift_session_id', 'pos_orders_shift_fk')->references('id')->on('shift_sessions');
            $table->foreign('stay_id', 'pos_orders_stay_fk')->references('id')->on('stays');
            $table->foreign('created_by', 'pos_orders_user_fk')->references('id')->on('users');
        });

        DB::statement("ALTER TABLE pos_orders ADD CONSTRAINT pos_orders_mode_chk CHECK ((mode = 'room_charge') = (stay_id IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_orders');
    }
};
