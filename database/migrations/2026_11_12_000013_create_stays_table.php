<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** DATABASE_SCHEMA §4.10 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stays', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('code', 16);
            $table->enum('state', ['reserved', 'held', 'checked_in', 'checked_out', 'cancelled', 'expired', 'no_show']);
            $table->enum('source', ['walk_in', 'kiosk', 'reservation']);
            $table->unsignedBigInteger('room_id')->nullable(); // NULL for a reservation of "any" room
            $table->unsignedBigInteger('room_type_id');
            $table->string('guest_name', 80);
            $table->string('guest_mobile', 11)->nullable();
            $table->string('plate_number', 12)->nullable();
            $table->enum('stay_kind', ['hourly', 'overnight', 'long_stay']);
            $table->unsignedTinyInteger('hours')->nullable();
            $table->unsignedTinyInteger('nights')->nullable();
            $table->unsignedTinyInteger('days')->nullable();
            $table->unsignedBigInteger('discount_id')->nullable();
            $table->bigInteger('quoted_amount'); // room price after discount, for reserved/held stays
            $table->timestamp('arrival_at')->nullable();
            $table->timestamp('hold_expires_at')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('expected_checkout_at')->nullable();
            $table->timestamp('checked_out_at')->nullable(); // ACTUAL check-out only
            $table->unsignedSmallInteger('cycle_hours_used')->default(0);
            $table->unsignedBigInteger('checkin_shift_id')->nullable();
            $table->unsignedBigInteger('checkout_shift_id')->nullable();
            $table->unsignedBigInteger('checked_in_by')->nullable();
            $table->unsignedBigInteger('checked_out_by')->nullable();
            $table->string('closed_reason', 255)->nullable();
            $table->char('idempotency_key', 36)->nullable();
            $table->unsignedBigInteger('active_room_id')->nullable()
                ->storedAs("IF(state IN ('held','checked_in'), room_id, NULL)");
            $table->timestamps();

            $table->unique('code', 'stays_code_unique');
            $table->unique('active_room_id', 'stays_one_active_per_room');
            $table->unique('idempotency_key', 'stays_idempotency_unique');
            $table->index(['branch_id', 'state', 'expected_checkout_at'], 'stays_board_idx');
            $table->index(['branch_id', 'checked_in_at'], 'stays_checkin_idx');
            $table->index(['branch_id', 'checked_out_at'], 'stays_checkout_idx');
            $table->index(['branch_id', 'state', 'arrival_at'], 'stays_arrival_idx');
            $table->foreign('branch_id', 'stays_branch_fk')->references('id')->on('branches');
            $table->foreign('room_id', 'stays_room_fk')->references('id')->on('rooms');
            $table->foreign('room_type_id', 'stays_type_fk')->references('id')->on('room_types');
            $table->foreign('discount_id', 'stays_discount_fk')->references('id')->on('discounts');
            $table->foreign('checkin_shift_id', 'stays_checkin_shift_fk')->references('id')->on('shift_sessions');
            $table->foreign('checkout_shift_id', 'stays_checkout_shift_fk')->references('id')->on('shift_sessions');
        });

        DB::statement("ALTER TABLE stays ADD CONSTRAINT stays_in_chk CHECK (state NOT IN ('checked_in','checked_out') OR (checked_in_at IS NOT NULL AND expected_checkout_at IS NOT NULL AND checkin_shift_id IS NOT NULL AND room_id IS NOT NULL))");
        DB::statement("ALTER TABLE stays ADD CONSTRAINT stays_out_chk CHECK ((state = 'checked_out') = (checked_out_at IS NOT NULL))");
        DB::statement("ALTER TABLE stays ADD CONSTRAINT stays_hold_chk CHECK (state <> 'held' OR (hold_expires_at IS NOT NULL AND room_id IS NOT NULL))");
        DB::statement('ALTER TABLE stays ADD CONSTRAINT stays_name_chk CHECK (CHAR_LENGTH(guest_name) >= 3)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stays');
    }
};
