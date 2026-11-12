<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** DATABASE_SCHEMA §4.5 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('room_type_id');
            $table->enum('stay_kind', ['hourly', 'overnight']);
            $table->unsignedTinyInteger('hours')->default(0); // 6,12,18,24 for hourly; 0 for overnight
            $table->bigInteger('amount');
            $table->timestamps();

            $table->unique(['branch_id', 'room_type_id', 'stay_kind', 'hours'], 'rates_unique');
            $table->foreign('branch_id', 'rates_branch_fk')->references('id')->on('branches');
            $table->foreign('room_type_id', 'rates_type_fk')->references('id')->on('room_types');
        });

        DB::statement('ALTER TABLE rates ADD CONSTRAINT rates_amount_chk CHECK (amount > 0)');
        DB::statement("ALTER TABLE rates ADD CONSTRAINT rates_hours_chk CHECK ((stay_kind = 'overnight' AND hours = 0) OR (stay_kind = 'hourly' AND hours BETWEEN 1 AND 24))");
    }

    public function down(): void
    {
        Schema::dropIfExists('rates');
    }
};
