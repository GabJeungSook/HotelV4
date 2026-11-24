<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE_SCHEMA §4.13: a product can be a stock item that is NOT sold at the counter — a
 * supply (plastic spoon, plate) consumed only through a meal's recipe. `sellable` splits "shown
 * on the POS / sold directly" from "is a stock item", so a supply may carry no price while an
 * ingredient sold on its own (extra egg) keeps one. The price check is relaxed accordingly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('sellable')->default(true)->after('price_amount');
        });

        DB::statement('ALTER TABLE products DROP CHECK products_price_chk');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_chk CHECK (price_amount >= 0 AND (sellable = 0 OR price_amount > 0))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE products DROP CHECK products_price_chk');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_chk CHECK (price_amount > 0)');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('sellable');
        });
    }
};
