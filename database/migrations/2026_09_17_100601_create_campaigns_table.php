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
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();

            // Business Central identity. bc_id is the GUID and the upsert key;
            // code (BC "Campaign No.") is the human-facing code, and is what
            // an item's price list lines carry as their salesCode. That is how
            // a promotion reaches products, so it is indexed for lookup.
            $table->uuid('bc_id')->unique();
            $table->string('code')->index();

            $table->string('description')->nullable();

            $table->date('starting_date')->nullable();
            $table->date('ending_date')->nullable()->index();

            $table->boolean('activated')->default(false);

            // The campaign's audience: BC customer GUIDs, normalised on import
            // to a blank-free, de-duplicated, sorted list. Normalising before
            // storage is what stops the same audience in a different BC array
            // order reading as a change.
            $table->json('customers')->nullable();

            // Millisecond precision, matching sync_checkpoints: this is the
            // value the incremental filter compares against.
            $table->dateTime('bc_modified_at', 3)->nullable()->index();

            // The complete original row, so anything not promoted to a column
            // survives for later phases.
            $table->json('bc_payload')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
