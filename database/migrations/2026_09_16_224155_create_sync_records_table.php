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
        Schema::create('sync_records', function (Blueprint $table) {
            $table->id();

            // One row per Business Central record per delivery channel.
            $table->string('channel', 32);
            $table->string('bc_id', 64);

            $table->string('status', 16)->default('pending');

            // Which normalised fields moved since the last delivery.
            $table->json('changed_fields')->nullable();

            // Hash of the state a delivery would send, so an unchanged payload
            // can be recognised without re-reading the product.
            $table->char('payload_hash', 64)->nullable();

            $table->dateTime('bc_modified_at')->nullable();

            $table->text('last_error')->nullable();

            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            $table->unique(['channel', 'bc_id']);
            $table->index(['channel', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_records');
    }
};
