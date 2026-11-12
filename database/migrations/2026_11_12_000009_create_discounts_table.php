<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** DATABASE_SCHEMA §4.5: discounts and discount_rate */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('name', 40);
            $table->enum('kind', ['fixed', 'percent']);
            $table->unsignedInteger('value'); // centavos if fixed; whole percent if percent
            $table->boolean('all_rates')->default(true); // false = only rates in discount_rate
            $table->boolean('kiosk_enabled')->default(false);
            $table->boolean('is_vat_exempt')->default(false); // senior citizen, PWD
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'name'], 'discounts_branch_name_unique');
            $table->foreign('branch_id', 'discounts_branch_fk')->references('id')->on('branches');
        });

        DB::statement("ALTER TABLE discounts ADD CONSTRAINT discounts_value_chk CHECK ((kind = 'percent' AND value BETWEEN 1 AND 100) OR (kind = 'fixed' AND value > 0))");

        Schema::create('discount_rate', function (Blueprint $table): void {
            $table->unsignedBigInteger('discount_id');
            $table->unsignedBigInteger('rate_id');

            $table->primary(['discount_id', 'rate_id']);
            $table->foreign('discount_id', 'discount_rate_discount_fk')->references('id')->on('discounts')->cascadeOnDelete();
            $table->foreign('rate_id', 'discount_rate_rate_fk')->references('id')->on('rates')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_rate');
        Schema::dropIfExists('discounts');
    }
};
