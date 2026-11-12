<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE_SCHEMA §4.12 and §6 (000032): the take payment form (11) carries a UUID made when
 * it opens; a repeated key returns the first payment (APP_FLOW §4.8, TRD §3.11; Gab, 12 Nov).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->char('idempotency_key', 36)->nullable()->after('note');
            $table->unique('idempotency_key', 'payments_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique('payments_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
