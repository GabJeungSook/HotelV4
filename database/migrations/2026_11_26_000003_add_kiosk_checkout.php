<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kiosk checkout (reference: HotelV2). When a branch turns it on, a guest can request checkout at
 * the lobby kiosk: they enter their room and stay code, review the bill, and confirm. That only
 * flags the stay (checkout_requested_at) so the front desk knows to process it — the desk still
 * takes payment, returns the deposit and key, and does the real checkout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->boolean('kiosk_checkout_enabled')->default(false)->after('kiosk_hold_minutes');
        });

        Schema::table('stays', function (Blueprint $table): void {
            // When the guest asked to check out at the kiosk; the desk still finalises it.
            $table->timestamp('checkout_requested_at')->nullable()->after('checked_out_at');
        });
    }

    public function down(): void
    {
        Schema::table('stays', function (Blueprint $table): void {
            $table->dropColumn('checkout_requested_at');
        });

        Schema::table('branches', function (Blueprint $table): void {
            $table->dropColumn('kiosk_checkout_enabled');
        });
    }
};
