<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kiosk-selectable discounts (PWD / Senior citizen). A discount with `needs_id`
 * is offered at the kiosk as a guest toggle instead of being applied
 * automatically: the guest ticks it, it goes on the bill, and the stay's
 * `discount_unverified` flag is raised until the front desk checks the ID and
 * verifies or revokes it. An auto discount (a promo) has needs_id = false and is
 * never flagged unverified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discounts', function (Blueprint $table): void {
            // Guest-selectable at the kiosk; requires an ID checked at the front desk.
            $table->boolean('needs_id')->default(false)->after('kiosk_enabled');
        });

        Schema::table('stays', function (Blueprint $table): void {
            // The applied discount was claimed by the guest at the kiosk and the
            // ID has not been verified at the desk yet.
            $table->boolean('discount_unverified')->default(false)->after('discount_id');
        });
    }

    public function down(): void
    {
        Schema::table('stays', function (Blueprint $table): void {
            $table->dropColumn('discount_unverified');
        });

        Schema::table('discounts', function (Blueprint $table): void {
            $table->dropColumn('needs_id');
        });
    }
};
