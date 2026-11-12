<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DATABASE_SCHEMA §4.16: pin_attempts (append-only) and pin_locks */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pin_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->char('device_key', 36); // long-lived cookie identifying the computer
            $table->unsignedBigInteger('user_id')->nullable(); // the account the PIN was tried against
            $table->enum('context', ['switch_user', 'approval', 'handover']);
            $table->boolean('succeeded');
            $table->timestamp('created_at');

            $table->index(['device_key', 'created_at'], 'pin_attempts_device_idx');
        });

        Schema::create('pin_locks', function (Blueprint $table): void {
            $table->id();
            $table->char('device_key', 36);
            $table->timestamp('locked_at');
            $table->timestamp('unlocked_at')->nullable();
            $table->unsignedBigInteger('unlocked_by')->nullable();
            $table->char('open_device_key', 36)->nullable()
                ->storedAs('IF(unlocked_at IS NULL, device_key, NULL)');

            $table->unique('open_device_key', 'pin_locks_one_open');
            $table->foreign('unlocked_by', 'pin_locks_user_fk')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pin_locks');
        Schema::dropIfExists('pin_attempts');
    }
};
