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
        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->id();

            // Business Central identity. bc_id is the GUID and the upsert key;
            // number (BC "No.") is the human-facing code.
            $table->uuid('bc_id')->unique();
            $table->string('number')->index();

            // The order this invoice was posted from, by the order's number.
            // Indexed because the order page and the payload look it up.
            $table->string('order_number')->nullable()->index();

            $table->uuid('customer_bc_id')->nullable()->index();
            $table->string('customer_name')->nullable();

            $table->date('invoice_date')->nullable();
            $table->date('order_date')->nullable();

            // The customer's PO number.
            $table->string('external_document_number')->nullable();

            $table->decimal('total_amount_excluding_tax', 18, 5)->default(0);
            $table->decimal('total_tax_amount', 18, 5)->default(0);
            $table->decimal('total_amount_including_tax', 18, 5)->default(0);

            $table->string('ship_to_address_1')->nullable();
            $table->string('ship_to_address_2')->nullable();
            $table->string('ship_to_city')->nullable();
            $table->string('ship_to_state')->nullable();
            $table->string('ship_to_post_code')->nullable();

            $table->text('work_description')->nullable();

            // The invoice lines from the standard API, normalised to a sorted
            // list so a reorder from Business Central never reads as a change.
            $table->json('lines')->nullable();

            // Millisecond precision: the incremental filter compares against it.
            $table->dateTime('bc_created_at', 3)->nullable();
            $table->dateTime('bc_modified_at', 3)->nullable()->index();

            $table->json('bc_payload')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_invoices');
    }
};
