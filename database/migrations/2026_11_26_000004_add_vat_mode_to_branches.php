<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VAT can be set as a percentage (the existing vat_rate_percent, split per line on the Z-report)
 * or as a fixed peso amount per taxable transaction (vat_amount, reported as the VAT figure).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->string('vat_mode', 16)->default('percent')->after('vat_rate_percent');
            $table->unsignedBigInteger('vat_amount')->default(0)->after('vat_mode');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropColumn(['vat_mode', 'vat_amount']);
        });
    }
};
