<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DATABASE_SCHEMA §4.4 (append-only) */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_status_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->string('reason', 40);
            $table->unsignedBigInteger('stay_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('created_at');

            $table->index(['room_id', 'created_at'], 'room_status_logs_room_idx');
            $table->foreign('room_id', 'room_status_logs_room_fk')->references('id')->on('rooms');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_status_logs');
    }
};
