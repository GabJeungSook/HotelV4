<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE_SCHEMA §4.11 and §6 (000040): the extend form (12) carries a UUID made when it opens;
 * a repeated key returns the first extension line and writes nothing, so a double tap or a retry
 * does not extend the stay or charge the guest twice (APP_FLOW §4.9). Only ExtendStay sets it;
 * every other folio line leaves it NULL (a unique index allows many NULLs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folio_lines', function (Blueprint $table): void {
            $table->char('idempotency_key', 36)->nullable()->after('reversal_reason');
            $table->unique('idempotency_key', 'folio_lines_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('folio_lines', function (Blueprint $table): void {
            $table->dropUnique('folio_lines_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
