<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE_SCHEMA §4.14: function/event halls and their customizable packages. A hall is a bookable
 * venue; a package is a priced item (venue, food, host or other) a booking is built from, per pax or
 * flat. Both are admin-managed config, gated by the branch's event_hall_enabled switch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('halls', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('name', 60);
            $table->unsignedInteger('capacity')->default(0);
            $table->bigInteger('base_rate_amount')->nullable(); // a reference rate only; bookings are quoted from items
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'name'], 'halls_branch_name_unique');
            $table->foreign('branch_id', 'halls_branch_fk')->references('id')->on('branches');
        });

        DB::statement('ALTER TABLE halls ADD CONSTRAINT halls_rate_chk CHECK (base_rate_amount IS NULL OR base_rate_amount > 0)');

        Schema::create('hall_packages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('name', 60);
            $table->enum('kind', ['venue', 'food', 'host', 'other']);
            $table->enum('pricing', ['per_pax', 'flat']);
            $table->bigInteger('unit_amount');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'name'], 'hall_packages_branch_name_unique');
            $table->foreign('branch_id', 'hall_packages_branch_fk')->references('id')->on('branches');
        });

        DB::statement('ALTER TABLE hall_packages ADD CONSTRAINT hall_packages_amount_chk CHECK (unit_amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('hall_packages');
        Schema::dropIfExists('halls');
    }
};
