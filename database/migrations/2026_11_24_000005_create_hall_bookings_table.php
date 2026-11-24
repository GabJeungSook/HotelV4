<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE_SCHEMA §4.14: a hall booking and its quote lines. A booking holds a time slot (overlap is
 * barred in ReserveHall, not by an index, since future slots can't use a unique), a customer, pax and
 * the quoted total. Its items are editable config while reserved (replaced on save); once completed,
 * the money lives in folio_lines/payments. deposit → complete → or cancel mirrors a room reservation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hall_bookings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('hall_id');
            $table->string('code', 24);
            $table->enum('status', ['reserved', 'completed', 'cancelled'])->default('reserved');
            $table->string('customer_name', 120);
            $table->string('customer_mobile', 20)->nullable();
            $table->date('event_date');
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->unsignedInteger('pax')->default(0);
            $table->bigInteger('quoted_amount')->default(0);
            $table->unsignedBigInteger('reserve_shift_id')->nullable();
            $table->unsignedBigInteger('complete_shift_id')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->string('idempotency_key', 64)->nullable();
            $table->timestamps();

            $table->unique('idempotency_key', 'hall_bookings_idempotency_unique');
            $table->index(['branch_id', 'hall_id', 'start_at'], 'hall_bookings_slot_idx');
            $table->foreign('branch_id', 'hall_bookings_branch_fk')->references('id')->on('branches');
            $table->foreign('hall_id', 'hall_bookings_hall_fk')->references('id')->on('halls');
            $table->foreign('reserve_shift_id', 'hall_bookings_reserve_shift_fk')->references('id')->on('shift_sessions');
            $table->foreign('complete_shift_id', 'hall_bookings_complete_shift_fk')->references('id')->on('shift_sessions');
            $table->foreign('created_by', 'hall_bookings_user_fk')->references('id')->on('users');
        });

        DB::statement('ALTER TABLE hall_bookings ADD CONSTRAINT hall_bookings_time_chk CHECK (end_at > start_at)');
        DB::statement("ALTER TABLE hall_bookings ADD CONSTRAINT hall_bookings_cancel_chk CHECK ((status = 'cancelled') = (cancel_reason IS NOT NULL))");
        DB::statement("ALTER TABLE hall_bookings ADD CONSTRAINT hall_bookings_complete_chk CHECK (status <> 'completed' OR complete_shift_id IS NOT NULL)");

        Schema::create('hall_booking_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('hall_booking_id');
            $table->unsignedBigInteger('hall_package_id')->nullable();
            $table->string('label', 120);
            $table->enum('pricing', ['per_pax', 'flat']);
            $table->integer('quantity')->default(1);
            $table->bigInteger('unit_amount');
            $table->bigInteger('amount');
            $table->timestamps();

            $table->index('hall_booking_id', 'hall_booking_items_booking_idx');
            $table->foreign('branch_id', 'hall_booking_items_branch_fk')->references('id')->on('branches');
            $table->foreign('hall_booking_id', 'hall_booking_items_booking_fk')->references('id')->on('hall_bookings')->cascadeOnDelete();
            $table->foreign('hall_package_id', 'hall_booking_items_package_fk')->references('id')->on('hall_packages');
        });

        DB::statement('ALTER TABLE hall_booking_items ADD CONSTRAINT hall_booking_items_amount_chk CHECK (amount = quantity * unit_amount AND quantity > 0 AND unit_amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('hall_booking_items');
        Schema::dropIfExists('hall_bookings');
    }
};
