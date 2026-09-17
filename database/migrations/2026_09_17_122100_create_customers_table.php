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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            // Business Central identity. bc_id is the GUID and the upsert key;
            // number (BC "No.") is the human-facing code, and is what ship-to
            // addresses and the customerContacts link carry instead of the
            // GUID — so it is indexed for those joins.
            $table->uuid('bc_id')->unique();
            $table->string('number')->index();

            $table->string('display_name')->nullable();
            $table->string('type')->nullable();

            $table->string('address_1')->nullable();
            $table->string('address_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            $table->string('shipment_method_code')->nullable();
            $table->string('shipping_location_code')->nullable();

            // Normalised on import: Business Central sends "_x0020_" (an OData
            // encoded space) for "not blocked", which is stored as "none".
            $table->string('blocked', 16)->default('none');

            $table->string('customer_price_group')->nullable();
            $table->string('customer_disc_group')->nullable();
            $table->string('salesperson_code')->nullable();

            // The customer's ship-to addresses, attached by the ship-to import
            // and normalised to a sorted list so a reorder from Business
            // Central never reads as a change.
            $table->json('shipping_addresses')->nullable();

            // Millisecond precision: the incremental filter compares against it.
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
        Schema::dropIfExists('customers');
    }
};
