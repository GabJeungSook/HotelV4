<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Floor & room rate overrides (DATABASE_SCHEMA §4.5). A rate row is scoped to exactly one of:
 * a room type (the base rate, as before), a whole floor (flat for every room on it), or a single
 * room. Pricing resolves most-specific-first: room → floor → room type. Opt-in per branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        // rates_unique backs rates_branch_fk (branch_id is its leftmost column), so the FKs go first.
        Schema::table('rates', function (Blueprint $table): void {
            $table->dropForeign('rates_branch_fk');
            $table->dropForeign('rates_type_fk');
            $table->dropUnique('rates_unique');
        });

        Schema::table('rates', function (Blueprint $table): void {
            $table->unsignedBigInteger('room_type_id')->nullable()->change();
            $table->unsignedBigInteger('floor_id')->nullable()->after('room_type_id');
            $table->unsignedBigInteger('room_id')->nullable()->after('floor_id');
        });

        // Nullable scope columns make a plain composite unique allow duplicates (NULLs compare
        // distinct), so uniqueness folds the three scope ids to 0 when absent.
        DB::statement('ALTER TABLE rates ADD UNIQUE rates_scope_unique (branch_id, stay_kind, hours, (COALESCE(room_type_id, 0)), (COALESCE(floor_id, 0)), (COALESCE(room_id, 0)))');
        DB::statement('ALTER TABLE rates ADD CONSTRAINT rates_scope_chk CHECK (((room_type_id IS NOT NULL) + (floor_id IS NOT NULL) + (room_id IS NOT NULL)) = 1)');

        Schema::table('rates', function (Blueprint $table): void {
            $table->foreign('branch_id', 'rates_branch_fk')->references('id')->on('branches');
            $table->foreign('room_type_id', 'rates_type_fk')->references('id')->on('room_types');
            $table->foreign('floor_id', 'rates_floor_fk')->references('id')->on('floors');
            $table->foreign('room_id', 'rates_room_fk')->references('id')->on('rooms');
        });

        Schema::table('branches', function (Blueprint $table): void {
            $table->boolean('floor_room_rates_enabled')->default(false)->after('long_stay_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('rates', function (Blueprint $table): void {
            $table->dropForeign('rates_branch_fk');
            $table->dropForeign('rates_type_fk');
            $table->dropForeign('rates_floor_fk');
            $table->dropForeign('rates_room_fk');
        });

        DB::statement('ALTER TABLE rates DROP CHECK rates_scope_chk');
        DB::statement('ALTER TABLE rates DROP INDEX rates_scope_unique');

        Schema::table('rates', function (Blueprint $table): void {
            $table->dropColumn(['floor_id', 'room_id']);
        });

        Schema::table('rates', function (Blueprint $table): void {
            $table->unsignedBigInteger('room_type_id')->nullable(false)->change();
            $table->unique(['branch_id', 'room_type_id', 'stay_kind', 'hours'], 'rates_unique');
            $table->foreign('branch_id', 'rates_branch_fk')->references('id')->on('branches');
            $table->foreign('room_type_id', 'rates_type_fk')->references('id')->on('room_types');
        });

        Schema::table('branches', function (Blueprint $table): void {
            $table->dropColumn('floor_room_rates_enabled');
        });
    }
};
