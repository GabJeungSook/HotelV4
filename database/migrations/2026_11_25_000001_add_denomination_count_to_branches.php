<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A per-branch switch for how the blind count (23) is entered: off (default) means the front desk
 * types one counted total, which is faster; on means they type the pieces of each bill and the
 * coins and the server adds them up. Either way one cash_counts row holds the counted total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->boolean('denomination_count_enabled')->default(false)->after('payment_methods');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropColumn('denomination_count_enabled');
        });
    }
};
