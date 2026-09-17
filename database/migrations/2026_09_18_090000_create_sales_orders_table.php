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
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();

            // Business Central identity. bc_id is the GUID and the upsert key;
            // number (BC "No.") is the human-facing code and is what shipments
            // and invoices are looked up by.
            $table->uuid('bc_id')->unique();
            $table->string('number')->index();

            // The customer the order belongs to, by the customer's GUID. The
            // website links the order post to its customer post from this.
            $table->uuid('customer_bc_id')->nullable()->index();
            $table->string('customer_name')->nullable();

            $table->date('order_date')->nullable();

            // Business Central's own status (Draft, Open, Released, …) and the
            // website status derived from it and the lines at import.
            $table->string('bc_status')->nullable();
            $table->string('website_status', 32)->default('processing')->index();

            // The customer's PO number.
            $table->string('external_document_number')->nullable();

            $table->decimal('total_amount_excluding_tax', 18, 5)->default(0);
            $table->decimal('total_tax_amount', 18, 5)->default(0);
            $table->decimal('total_amount_including_tax', 18, 5)->default(0);
            $table->boolean('fully_shipped')->default(false);

            $table->string('ship_to_address_1')->nullable();
            $table->string('ship_to_address_2')->nullable();
            $table->string('ship_to_city')->nullable();
            $table->string('ship_to_state')->nullable();
            $table->string('ship_to_post_code')->nullable();

            // Free text on the order; the customer's note is lifted out of it.
            $table->text('work_description')->nullable();

            // The documents behind the header, from the standard API, normalised
            // to sorted lists so a reorder from Business Central never reads as
            // a change.
            $table->json('lines')->nullable();
            $table->json('shipments')->nullable();
            $table->json('invoices')->nullable();

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
        Schema::dropIfExists('sales_orders');
    }
};
