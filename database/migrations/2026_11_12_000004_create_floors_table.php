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
        Schema::create('floors', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->smallInteger('number');
            $table->string('label', 20);
            $table->timestamps();

            $table->unique(['branch_id', 'number'], 'floors_branch_number_unique');
            $table->foreign('branch_id', 'floors_branch_fk')->references('id')->on('branches');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('floors');
    }
};
