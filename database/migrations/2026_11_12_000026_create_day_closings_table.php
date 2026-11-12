<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DATABASE_SCHEMA §4.16: one row per closed business day (38b) */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('day_closings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->date('business_date');
            $table->unsignedInteger('day_z_no');
            $table->bigInteger('net_sales_amount');
            $table->bigInteger('short_amount')->default(0); // sum of negative variances, as a positive number
            $table->bigInteger('over_amount')->default(0);
            $table->bigInteger('grand_total_before');
            $table->bigInteger('grand_total_after');
            $table->unsignedInteger('shift_z_from');
            $table->unsignedInteger('shift_z_to');
            $table->unsignedBigInteger('closed_by');
            $table->timestamp('created_at');

            $table->unique(['branch_id', 'business_date'], 'day_closings_date_unique');
            $table->unique(['branch_id', 'day_z_no'], 'day_closings_no_unique');
            $table->foreign('branch_id', 'day_closings_branch_fk')->references('id')->on('branches');
            $table->foreign('closed_by', 'day_closings_user_fk')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('day_closings');
    }
};
