<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** DATABASE_SCHEMA §4.8: cash_counts, shift_handovers, shift_forwarded_stays */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_counts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shift_session_id');
            $table->json('denominations');
            $table->bigInteger('total_cash');
            $table->unsignedBigInteger('counted_by');
            $table->unsignedBigInteger('witness_id')->nullable();
            $table->timestamp('created_at');

            $table->unique('shift_session_id', 'cash_counts_shift_unique');
            $table->foreign('shift_session_id', 'cash_counts_shift_fk')->references('id')->on('shift_sessions');
            $table->foreign('counted_by', 'cash_counts_user_fk')->references('id')->on('users');
        });

        DB::statement('ALTER TABLE cash_counts ADD CONSTRAINT cash_counts_total_chk CHECK (total_cash >= 0)');

        Schema::create('shift_handovers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('from_shift_id');
            $table->unsignedBigInteger('to_shift_id')->nullable(); // NULL when closed without handover
            $table->unsignedBigInteger('received_by')->nullable();
            $table->bigInteger('received_cash')->nullable();
            $table->bigInteger('difference_amount')->default(0);
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at');

            $table->unique('from_shift_id', 'shift_handovers_from_unique');
            $table->unique('to_shift_id', 'shift_handovers_to_unique');
            $table->foreign('from_shift_id', 'shift_handovers_from_fk')->references('id')->on('shift_sessions');
            $table->foreign('to_shift_id', 'shift_handovers_to_fk')->references('id')->on('shift_sessions');
            $table->foreign('received_by', 'shift_handovers_user_fk')->references('id')->on('users');
        });

        Schema::create('shift_forwarded_stays', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shift_session_id'); // the shift that CLOSED and forwarded this stay out
            $table->unsignedBigInteger('stay_id');
            $table->unsignedBigInteger('room_id');
            $table->string('guest_name', 80);
            $table->timestamp('expected_checkout_at');
            $table->bigInteger('unpaid_amount');
            $table->bigInteger('key_deposit_held');
            $table->bigInteger('guest_deposit_held');
            $table->timestamp('created_at');

            $table->unique(['shift_session_id', 'stay_id'], 'shift_forwarded_stays_unique');
            $table->foreign('shift_session_id', 'sfs_shift_fk')->references('id')->on('shift_sessions');
            $table->foreign('stay_id', 'sfs_stay_fk')->references('id')->on('stays');
            $table->foreign('room_id', 'sfs_room_fk')->references('id')->on('rooms');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_forwarded_stays');
        Schema::dropIfExists('shift_handovers');
        Schema::dropIfExists('cash_counts');
    }
};
