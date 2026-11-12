<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE_SCHEMA §4.16: reason lists, plus the foreign keys from the rows
 * a picked reason is stored on ("Where a reason is stored").
 */
return new class extends Migration
{
    /** @var array<string, string> table => foreign key name */
    private const REASON_FOREIGN_KEYS = [
        'approvals' => 'approvals_reason_fk',
        'cash_movements' => 'cash_movements_reason_fk',
        'stock_movements' => 'stock_movements_reason_fk',
    ];

    public function up(): void
    {
        Schema::create('reasons', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->enum('kind', ['void_line', 'cancel_stay', 'variance', 'void_cash_movement', 'wastage', 'cancel_reservation']);
            $table->string('name', 60);
            $table->boolean('needs_note')->default(false);
            $table->boolean('needs_manager')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'kind', 'name'], 'reasons_unique');
            $table->foreign('branch_id', 'reasons_branch_fk')->references('id')->on('branches');
        });

        foreach (self::REASON_FOREIGN_KEYS as $tableName => $key) {
            Schema::table($tableName, function (Blueprint $table) use ($key): void {
                $table->foreign('reason_id', $key)->references('id')->on('reasons');
            });
        }

        Schema::table('shift_sessions', function (Blueprint $table): void {
            $table->foreign('variance_reason_id', 'shift_sessions_variance_reason_fk')->references('id')->on('reasons');
        });
    }

    public function down(): void
    {
        Schema::table('shift_sessions', function (Blueprint $table): void {
            $table->dropForeign('shift_sessions_variance_reason_fk');
        });

        foreach (self::REASON_FOREIGN_KEYS as $tableName => $key) {
            Schema::table($tableName, function (Blueprint $table) use ($key): void {
                $table->dropForeign($key);
            });
        }

        Schema::dropIfExists('reasons');
    }
};
