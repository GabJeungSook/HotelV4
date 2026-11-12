<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DATABASE_SCHEMA §4.3 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_definitions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('name', 20);
            $table->time('starts_at');
            $table->time('ends_at'); // may be earlier than starts_at (crosses midnight)
            $table->unsignedTinyInteger('sort_order');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'name'], 'shift_definitions_branch_name_unique');
            $table->foreign('branch_id', 'shift_definitions_branch_fk')->references('id')->on('branches');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_definitions');
    }
};
