<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE_SCHEMA §4.16: rebuildable summary tables for dashboards (60, 61)
 * and Stats (63). Nothing here is a ledger; RebuildSummaries replaces a
 * branch-day at a time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('summary_days', function (Blueprint $table): void {
            $table->unsignedBigInteger('branch_id');
            $table->date('business_date');
            $table->timestamp('dirty_at')->nullable();
            $table->timestamp('rebuilt_at')->nullable();

            $table->primary(['branch_id', 'business_date']);
        });

        Schema::create('daily_summaries', function (Blueprint $table): void {
            $table->unsignedBigInteger('branch_id');
            $table->date('business_date');
            foreach ([
                'gross_sales_amount', 'discount_amount', 'net_sales_amount', 'room_sales_amount', 'other_sales_amount',
                'collected_amount', 'refund_amount', 'short_amount', 'over_amount',
            ] as $column) {
                $table->bigInteger($column)->default(0);
            }
            foreach (['checkins', 'checkouts', 'stays_in_house_end', 'room_count', 'cleans', 'late_cleans'] as $column) {
                $table->integer($column)->default(0);
            }

            $table->primary(['branch_id', 'business_date']);
        });

        Schema::create('hourly_sales_summaries', function (Blueprint $table): void {
            $table->unsignedBigInteger('branch_id');
            $table->date('business_date');
            $table->date('local_date'); // calendar date of the hour, branch local time
            $table->unsignedTinyInteger('hour'); // 0–23, branch local time
            $table->bigInteger('net_sales_amount')->default(0);
            $table->integer('checkins')->default(0);

            $table->primary(['branch_id', 'business_date', 'local_date', 'hour']);
        });

        Schema::create('daily_payment_summaries', function (Blueprint $table): void {
            $table->unsignedBigInteger('branch_id');
            $table->date('business_date');
            $table->enum('method', ['cash', 'gcash', 'card', 'deposit']);
            $table->unsignedBigInteger('user_id');
            $table->integer('payment_count')->default(0);
            $table->bigInteger('amount')->default(0);
            $table->bigInteger('refund_amount')->default(0);

            $table->primary(['branch_id', 'business_date', 'method', 'user_id']);
        });

        Schema::create('daily_report_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->date('business_date');
            $table->enum('section', ['room_type', 'stay_option', 'product', 'discount']);
            $table->string('label', 80);
            $table->integer('quantity')->default(0);
            $table->bigInteger('amount')->default(0);

            $table->unique(['branch_id', 'business_date', 'section', 'label'], 'daily_report_lines_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_report_lines');
        Schema::dropIfExists('daily_payment_summaries');
        Schema::dropIfExists('hourly_sales_summaries');
        Schema::dropIfExists('daily_summaries');
        Schema::dropIfExists('summary_days');
    }
};
