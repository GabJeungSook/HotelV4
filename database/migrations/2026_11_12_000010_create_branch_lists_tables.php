<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DATABASE_SCHEMA §4.6: transfer_reasons, expense_categories, floor_user */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['transfer_reasons', 'expense_categories'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->id();
                $table->unsignedBigInteger('branch_id');
                $table->string('name', 60);
                $table->boolean('needs_note')->default(false);
                $table->boolean('needs_manager')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['branch_id', 'name'], "{$name}_unique");
                $table->foreign('branch_id', "{$name}_branch_fk")->references('id')->on('branches');
            });
        }

        Schema::create('floor_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('floor_id');
            $table->unsignedBigInteger('user_id');

            $table->primary(['floor_id', 'user_id']);
            $table->foreign('floor_id', 'floor_user_floor_fk')->references('id')->on('floors')->cascadeOnDelete();
            $table->foreign('user_id', 'floor_user_user_fk')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('floor_user');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('transfer_reasons');
    }
};
