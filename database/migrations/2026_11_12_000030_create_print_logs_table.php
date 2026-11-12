<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DATABASE_SCHEMA §4.16: every print and export (append-only) */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->enum('document', ['checkin_slip', 'receipt', 'shift_slip', 'z_report', 'day_z', 'report']);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->enum('format', ['print', 'pdf', 'csv']);
            $table->boolean('is_reprint')->default(false);
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('shift_session_id')->nullable();
            $table->timestamp('created_at');

            $table->index(['branch_id', 'created_at'], 'print_logs_branch_idx');
            $table->foreign('branch_id', 'print_logs_branch_fk')->references('id')->on('branches');
            $table->foreign('user_id', 'print_logs_user_fk')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_logs');
    }
};
