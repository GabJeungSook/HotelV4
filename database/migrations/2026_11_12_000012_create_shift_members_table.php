<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DATABASE_SCHEMA §4.8 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_members', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shift_session_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('joined_at');

            $table->unique(['shift_session_id', 'user_id'], 'shift_members_unique');
            $table->foreign('shift_session_id', 'shift_members_shift_fk')->references('id')->on('shift_sessions');
            $table->foreign('user_id', 'shift_members_user_fk')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_members');
    }
};
