<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The hourly rate blocks a branch sells (e.g. 3, 6, 12, 24) are now per-branch and editable in
 * Settings, instead of the fixed 6/12/18/24. The rate table shows a column per block and prices
 * are set there; the kiosk and front desk offer whatever has a price. Existing branches keep the
 * old set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->json('hourly_blocks')->nullable()->after('hourly_enabled');
        });

        DB::table('branches')->update(['hourly_blocks' => json_encode([6, 12, 18, 24])]);
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropColumn('hourly_blocks');
        });
    }
};
