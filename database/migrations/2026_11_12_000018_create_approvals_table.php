<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE_SCHEMA §4.15 (append-only). The reason_id foreign key is added in
 * 000028, after the reasons table exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approvals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->enum('action', [
                'transfer', 'void_line', 'cancel_stay', 'void_cash_movement', 'void_sale', 'force_close_shift',
                'close_without_handover', 'cancel_reservation', 'refund_no_show', 'change_shift_period',
                'approve_variance', 'reason_requires_manager',
            ]);
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('approved_by');
            $table->unsignedBigInteger('reason_id')->nullable();
            $table->string('reason', 255);
            $table->string('summary', 255);
            $table->timestamp('created_at');

            $table->index(['branch_id', 'created_at'], 'approvals_branch_idx');
            $table->foreign('branch_id', 'approvals_branch_fk')->references('id')->on('branches');
            $table->foreign('requested_by', 'approvals_requested_fk')->references('id')->on('users');
            $table->foreign('approved_by', 'approvals_approved_fk')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approvals');
    }
};
