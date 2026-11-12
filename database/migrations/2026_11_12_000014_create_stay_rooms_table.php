<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DATABASE_SCHEMA §4.10 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stay_rooms', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('stay_id');
            $table->unsignedBigInteger('room_id');
            $table->timestamp('from_at');
            $table->timestamp('to_at')->nullable();
            $table->unsignedBigInteger('transfer_reason_id')->nullable(); // reason for LEAVING this room
            $table->timestamp('created_at');

            $table->index(['stay_id', 'from_at'], 'stay_rooms_stay_idx');
            $table->index(['room_id', 'from_at'], 'stay_rooms_room_idx');
            $table->foreign('stay_id', 'stay_rooms_stay_fk')->references('id')->on('stays');
            $table->foreign('room_id', 'stay_rooms_room_fk')->references('id')->on('rooms');
            $table->foreign('transfer_reason_id', 'stay_rooms_reason_fk')->references('id')->on('transfer_reasons');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stay_rooms');
    }
};
