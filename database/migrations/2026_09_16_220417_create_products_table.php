<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            // Business Central identity. bc_id is the GUID and the upsert key;
            // sku (BC "number") is the human-facing code.
            $table->uuid('bc_id')->unique();
            $table->string('sku')->index();

            $table->string('name')->nullable();
            $table->string('name_2')->nullable();
            $table->string('type')->nullable();

            $table->decimal('price', 18, 5)->default(0);
            $table->decimal('inventory', 18, 5)->default(0);
            $table->decimal('weight', 18, 5)->default(0);

            $table->boolean('blocked')->default(false);
            $table->boolean('sales_blocked')->default(false);

            $table->string('gtin')->nullable();
            $table->string('item_category_id')->nullable();

            $table->dateTime('bc_modified_at')->nullable()->index();

            // The complete original row, so nested collections (price list lines,
            // dimensions, attributes, stockkeeping units) survive for later phases.
            $table->json('bc_payload')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
