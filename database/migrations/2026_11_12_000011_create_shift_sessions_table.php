<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE_SCHEMA §4.7. The variance_reason_id foreign key is added in 000028,
 * after the reasons table exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('shift_definition_id');
            $table->string('period_name', 20); // copied from the definition at open
            $table->date('business_date');
            $table->enum('status', ['open', 'closing', 'closed'])->default('open');
            $table->timestamp('opened_at');
            $table->unsignedBigInteger('opened_by');
            $table->bigInteger('opening_cash');
            $table->string('opening_note', 255)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->boolean('is_force_closed')->default(false);
            $table->bigInteger('expected_cash')->nullable();
            $table->bigInteger('counted_cash')->nullable();
            $table->bigInteger('variance_amount')->nullable(); // counted − expected; negative = short
            $table->unsignedBigInteger('variance_reason_id')->nullable();
            $table->string('variance_note', 255)->nullable();
            $table->string('handover_note', 500)->nullable();
            $table->json('totals')->nullable(); // frozen report figures
            $table->unsignedInteger('z_no')->nullable();
            $table->bigInteger('grand_total_before')->nullable();
            $table->bigInteger('grand_total_after')->nullable();
            $table->unsignedBigInteger('open_branch_id')->nullable()
                ->storedAs("IF(status IN ('open','closing'), branch_id, NULL)");
            $table->timestamps();

            $table->unique('open_branch_id', 'shift_sessions_one_open');
            $table->unique(['branch_id', 'z_no'], 'shift_sessions_z_unique');
            $table->unique(['branch_id', 'business_date', 'shift_definition_id'], 'shift_sessions_period_unique');
            $table->index(['branch_id', 'business_date'], 'shift_sessions_date_idx');
            $table->foreign('branch_id', 'shift_sessions_branch_fk')->references('id')->on('branches');
            $table->foreign('shift_definition_id', 'shift_sessions_def_fk')->references('id')->on('shift_definitions');
            $table->foreign('opened_by', 'shift_sessions_opened_by_fk')->references('id')->on('users');
            $table->foreign('closed_by', 'shift_sessions_closed_by_fk')->references('id')->on('users');
        });

        DB::statement("ALTER TABLE shift_sessions ADD CONSTRAINT shift_sessions_closed_chk CHECK ((status = 'closed') = (closed_at IS NOT NULL))");
        DB::statement('ALTER TABLE shift_sessions ADD CONSTRAINT shift_sessions_variance_chk CHECK (variance_amount IS NULL OR variance_amount = 0 OR variance_note IS NOT NULL OR is_force_closed = 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_sessions');
    }
};
