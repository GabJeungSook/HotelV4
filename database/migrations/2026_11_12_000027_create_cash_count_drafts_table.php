<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DATABASE_SCHEMA §4.16: the count being typed at close (23) */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_count_drafts', function (Blueprint $table): void {
            $table->unsignedBigInteger('shift_session_id')->primary();
            $table->json('denominations');
            $table->unsignedBigInteger('updated_by');
            $table->timestamp('updated_at');

            $table->foreign('shift_session_id', 'cash_count_drafts_shift_fk')->references('id')->on('shift_sessions');
            $table->foreign('updated_by', 'cash_count_drafts_user_fk')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_count_drafts');
    }
};
