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
        Schema::create('product_quantities', function (Blueprint $table) {
            $table->id();

            // itemQuantities is its own Business Central entity, but it is keyed by
            // the same item GUID, which is what joins a row to its product.
            $table->uuid('bc_id')->unique();
            $table->string('sku')->index();

            // Repeated from the item row: a quantity row can arrive before its item,
            // and the type decides whether stock is tracked at all.
            $table->string('type')->nullable();

            // Item-level totals across every location, not just the sellable one.
            $table->decimal('inventory', 18, 5)->default(0);
            $table->decimal('qty_on_purchase_order', 18, 5)->default(0);
            $table->decimal('qty_on_sales_order', 18, 5)->default(0);
            $table->decimal('qty_on_transfer_order', 18, 5)->default(0);

            // Stored exactly as Business Central sends them, including its
            // "0001-01-01" sentinel for "no date". Whether a date is still
            // relevant is a question about now, so it is answered at read time.
            $table->date('next_purchase_receipt_date')->nullable();
            $table->date('next_transfer_receipt_date')->nullable();

            $table->dateTime('bc_modified_at')->nullable()->index();

            $table->json('bc_payload')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_quantities');
    }
};
