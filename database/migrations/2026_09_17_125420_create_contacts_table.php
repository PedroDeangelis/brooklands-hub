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
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();

            // Business Central identity. bc_id is the GUID and the upsert key;
            // number (BC "No.") is the human-facing code and is what decides a
            // duplicate-email tie, so it is indexed.
            $table->uuid('bc_id')->unique();
            $table->string('number')->index();

            $table->string('display_name')->nullable();
            $table->string('type')->nullable();

            // The contact-company this person belongs to. Not a customer: the
            // customer link arrives separately from customerContacts.
            $table->string('company_number')->nullable();
            $table->string('company_name')->nullable();

            // The two legacy query rules, kept as plain columns so eligibility
            // can evaluate and explain them locally.
            $table->string('organisational_level_code')->nullable();
            $table->string('contact_business_relation')->nullable();

            $table->string('address_1')->nullable();
            $table->string('address_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->nullable();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();

            // Lowercased on import. It becomes the WordPress login, and the
            // duplicate rule compares it, so case must not create false pairs.
            $table->string('email')->nullable()->index();

            $table->boolean('privacy_blocked')->default(false);

            // Set by the customerContacts sweep, never by the contact import.
            $table->uuid('customer_bc_id')->nullable()->index();

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
        Schema::dropIfExists('contacts');
    }
};
