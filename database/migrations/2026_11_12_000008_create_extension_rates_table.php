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
        Schema::create('extension_rates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedTinyInteger('hours');
            $table->bigInteger('amount');
            $table->timestamps();

            $table->unique(['branch_id', 'hours'], 'extension_rates_unique');
            $table->foreign('branch_id', 'extension_rates_branch_fk')->references('id')->on('branches');
        });

        DB::statement('ALTER TABLE extension_rates ADD CONSTRAINT extension_rates_chk CHECK (amount > 0 AND hours BETWEEN 1 AND 24)');
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_rates');
    }
};
