<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** DATABASE_SCHEMA §4.13: products and product_price_logs */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('name', 60);
            $table->enum('kind', ['food_drink', 'amenity', 'damage']);
            $table->string('category', 30);
            $table->enum('report_group', ['foods', 'drinks', 'misc']); // column on the Z-report (37b)
            $table->enum('outlet', ['frontdesk', 'kitchen', 'pub'])->default('frontdesk');
            $table->bigInteger('price_amount');
            $table->boolean('tracks_stock')->default(true);
            $table->integer('stock_on_hand')->default(0);
            $table->integer('low_stock_at')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'name'], 'products_branch_name_unique');
            $table->foreign('branch_id', 'products_branch_fk')->references('id')->on('branches');
        });

        DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_chk CHECK (price_amount > 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_stock_chk CHECK (stock_on_hand >= 0)');

        Schema::create('product_price_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->bigInteger('old_amount');
            $table->bigInteger('new_amount');
            $table->unsignedBigInteger('changed_by');
            $table->timestamp('created_at');

            $table->foreign('product_id', 'product_price_logs_product_fk')->references('id')->on('products');
            $table->foreign('changed_by', 'product_price_logs_user_fk')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_logs');
        Schema::dropIfExists('products');
    }
};
