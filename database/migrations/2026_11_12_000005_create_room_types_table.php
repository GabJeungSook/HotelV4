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
        Schema::create('room_types', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('name', 40);
            $table->string('description', 120)->nullable(); // shown on kiosk (28)
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'name'], 'room_types_branch_name_unique');
            $table->foreign('branch_id', 'room_types_branch_fk')->references('id')->on('branches');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_types');
    }
};
