<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE_SCHEMA §4.11/§4.12/§4.14: a hall booking is a third ledger owner, alongside a stay and a
 * POS order. Both money ledgers gain a nullable hall_booking_id and the owner CHECK admits it; the
 * folio_lines.kind enum gains 'event_hall'. Plus the branch's event_hall_enabled module switch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folio_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('hall_booking_id')->nullable()->after('pos_order_id');
            $table->foreign('hall_booking_id', 'folio_lines_hall_fk')->references('id')->on('hall_bookings');
        });

        DB::statement("ALTER TABLE folio_lines MODIFY kind ENUM('room','extension','transfer_difference','food_drink','amenity','damage','event_hall','discount','forfeited_deposit') NOT NULL");
        DB::statement('ALTER TABLE folio_lines DROP CHECK folio_lines_owner_chk');
        DB::statement('ALTER TABLE folio_lines ADD CONSTRAINT folio_lines_owner_chk CHECK (stay_id IS NOT NULL OR pos_order_id IS NOT NULL OR hall_booking_id IS NOT NULL)');

        Schema::table('payments', function (Blueprint $table): void {
            $table->unsignedBigInteger('hall_booking_id')->nullable()->after('pos_order_id');
            $table->foreign('hall_booking_id', 'payments_hall_fk')->references('id')->on('hall_bookings');
        });

        DB::statement('ALTER TABLE payments DROP CHECK payments_owner_chk');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_owner_chk CHECK (stay_id IS NOT NULL OR pos_order_id IS NOT NULL OR hall_booking_id IS NOT NULL)');

        Schema::table('branches', function (Blueprint $table): void {
            $table->boolean('event_hall_enabled')->default(false)->after('reservations_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('folio_lines', function (Blueprint $table): void {
            $table->dropForeign('folio_lines_hall_fk');
            $table->dropColumn('hall_booking_id');
        });

        DB::statement("ALTER TABLE folio_lines MODIFY kind ENUM('room','extension','transfer_difference','food_drink','amenity','damage','discount','forfeited_deposit') NOT NULL");
        DB::statement('ALTER TABLE folio_lines DROP CHECK folio_lines_owner_chk');
        DB::statement('ALTER TABLE folio_lines ADD CONSTRAINT folio_lines_owner_chk CHECK (stay_id IS NOT NULL OR pos_order_id IS NOT NULL)');

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign('payments_hall_fk');
            $table->dropColumn('hall_booking_id');
        });

        DB::statement('ALTER TABLE payments DROP CHECK payments_owner_chk');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_owner_chk CHECK (stay_id IS NOT NULL OR pos_order_id IS NOT NULL)');

        Schema::table('branches', function (Blueprint $table): void {
            $table->dropColumn('event_hall_enabled');
        });
    }
};
