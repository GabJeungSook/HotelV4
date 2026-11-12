<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** DATABASE_SCHEMA §4.1 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('address', 160);
            $table->string('slip_header', 80);
            $table->char('code_prefix', 3);
            $table->enum('status', ['setting_up', 'active', 'inactive'])->default('setting_up');
            // stays
            $table->boolean('hourly_enabled')->default(true);
            $table->boolean('overnight_enabled')->default(false);
            $table->time('overnight_checkin_time')->default('14:00:00');
            $table->time('overnight_checkout_time')->default('12:00:00');
            $table->boolean('long_stay_enabled')->default(true);
            $table->unsignedTinyInteger('long_stay_max_days')->default(31);
            $table->enum('extension_rule', ['cycle', 'per_hour', 'block'])->default('cycle');
            $table->unsignedTinyInteger('extension_cycle_hours')->default(24);
            $table->bigInteger('extension_per_hour_amount')->default(0);
            $table->unsignedSmallInteger('due_soon_minutes')->default(30);
            // money
            $table->boolean('deposit_enabled')->default(true);
            $table->string('deposit_name', 40)->default('Key & remote');
            $table->bigInteger('deposit_amount')->default(20000);
            $table->bigInteger('lost_key_amount')->default(10000);
            $table->bigInteger('lost_remote_amount')->default(10000);
            $table->json('payment_methods');
            // shifts
            $table->unsignedTinyInteger('max_frontdesks_per_shift')->default(2);
            $table->unsignedSmallInteger('shift_overdue_warn_minutes')->default(30);
            // modules
            $table->boolean('kiosk_enabled')->default(false);
            $table->unsignedTinyInteger('kiosk_hold_minutes')->default(10);
            $table->boolean('reservations_enabled')->default(false);
            $table->unsignedSmallInteger('reservation_release_minutes')->default(120);
            $table->boolean('pos_enabled')->default(true);
            $table->boolean('housekeeping_enabled')->default(true);
            $table->unsignedSmallInteger('cleaning_deadline_minutes')->default(240);
            $table->unsignedSmallInteger('cleaning_target_minutes')->default(15);
            $table->unsignedSmallInteger('cleaning_urgent_minutes')->default(120);
            $table->text('house_rules')->nullable();
            $table->unsignedInteger('next_stay_number')->default(1);
            $table->unsignedInteger('next_pos_number')->default(1);
            $table->unsignedInteger('next_z_number')->default(1);
            $table->unsignedInteger('next_day_z_number')->default(1);
            $table->unsignedSmallInteger('shift_stale_block_minutes')->default(240);
            // tax (off unless the branch is VAT-registered)
            $table->boolean('vat_enabled')->default(false);
            $table->unsignedTinyInteger('vat_rate_percent')->default(12);
            $table->string('tin', 20)->nullable();
            $table->string('registered_name', 120)->nullable();
            $table->boolean('welcome_enabled')->default(true);
            $table->timestamps();

            $table->unique('name', 'branches_name_unique');
            $table->unique('code_prefix', 'branches_code_prefix_unique');
        });

        DB::statement('ALTER TABLE branches ADD CONSTRAINT branches_methods_chk CHECK (JSON_LENGTH(payment_methods) >= 1)');
        DB::statement('ALTER TABLE branches ADD CONSTRAINT branches_amounts_chk CHECK (deposit_amount >= 0 AND lost_key_amount >= 0 AND lost_remote_amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
