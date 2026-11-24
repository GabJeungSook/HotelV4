<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE_SCHEMA §4.13: the recipe (bill of materials). One row per component of a meal: selling
 * the meal (product_id) deducts `quantity` of the ingredient (ingredient_id) from its own stock.
 * Flat (an ingredient is a raw stock item, never itself a meal) and editable config — not a money
 * ledger, so it is replaced on save rather than appended.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_ingredients', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('product_id');    // the meal
            $table->unsignedBigInteger('ingredient_id'); // the stock item it consumes
            $table->integer('quantity');                 // whole units per one meal
            $table->timestamps();

            $table->unique(['product_id', 'ingredient_id'], 'product_ingredients_unique');
            $table->index('product_id', 'product_ingredients_product_idx');
            $table->foreign('branch_id', 'product_ingredients_branch_fk')->references('id')->on('branches');
            $table->foreign('product_id', 'product_ingredients_product_fk')->references('id')->on('products');
            $table->foreign('ingredient_id', 'product_ingredients_ingredient_fk')->references('id')->on('products');
        });

        DB::statement('ALTER TABLE product_ingredients ADD CONSTRAINT product_ingredients_qty_chk CHECK (quantity > 0)');
        DB::statement('ALTER TABLE product_ingredients ADD CONSTRAINT product_ingredients_self_chk CHECK (product_id <> ingredient_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_ingredients');
    }
};
