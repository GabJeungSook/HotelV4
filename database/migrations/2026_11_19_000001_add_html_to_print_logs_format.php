<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The Z-report can be exported as a standalone HTML file, logged like the PDF and CSV. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE print_logs MODIFY format ENUM('print', 'pdf', 'csv', 'html') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE print_logs MODIFY format ENUM('print', 'pdf', 'csv') NOT NULL");
    }
};
