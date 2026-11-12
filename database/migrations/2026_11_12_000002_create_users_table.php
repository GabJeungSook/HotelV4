<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE_SCHEMA §4.2, plus Laravel's password_reset_tokens.
 * The sessions table already comes from 0001_01_01_000000 and is not created again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable(); // NULL only for role = owner
            $table->string('name', 80);
            $table->string('username', 40);
            $table->string('password', 255);
            $table->string('pin_hash', 255)->nullable(); // bcrypt of a 4-digit PIN
            $table->enum('role', ['owner', 'admin', 'supervisor', 'frontdesk', 'backoffice', 'housekeeper', 'kiosk']);
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('failed_logins')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->timestamps();

            $table->unique('username', 'users_username_unique');
            $table->index(['branch_id', 'role', 'is_active'], 'users_branch_role_idx');
            $table->foreign('branch_id', 'users_branch_fk')->references('id')->on('branches');
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_owner_branch_chk CHECK ((role = 'owner') = (branch_id IS NULL))");

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
