<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DATABASE_SCHEMA §4.4 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('floor_id');
            $table->unsignedBigInteger('room_type_id');
            $table->string('number', 8);
            $table->string('area', 20)->default('Main');
            $table->enum('status', ['available', 'occupied', 'uncleaned', 'cleaning', 'maintenance'])->default('available');
            $table->boolean('is_kiosk_enabled')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_checkout_at')->nullable(); // kiosk rotation (28)
            $table->timestamps();

            $table->unique(['branch_id', 'number'], 'rooms_branch_number_unique');
            $table->index(['branch_id', 'is_active', 'status'], 'rooms_board_idx');
            $table->foreign('branch_id', 'rooms_branch_fk')->references('id')->on('branches');
            $table->foreign('floor_id', 'rooms_floor_fk')->references('id')->on('floors');
            $table->foreign('room_type_id', 'rooms_type_fk')->references('id')->on('room_types');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
