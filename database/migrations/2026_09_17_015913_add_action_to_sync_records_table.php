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
        Schema::table('sync_records', function (Blueprint $table) {
            // What the website should be asked to do with this record. A product
            // that stops qualifying needs removing rather than ignoring, so the
            // action is part of the delivery intent and can change on its own.
            $table->string('action', 16)->default('upsert')->after('status');

            // The action last delivered successfully. Comparing it with `action`
            // is what makes an eligibility change create work even though none
            // of the Business Central fields moved.
            $table->string('delivered_action', 16)->nullable()->after('payload_hash');

            // The payload hash last delivered successfully, so an unchanged
            // record is recognised without re-reading the product.
            $table->char('delivered_hash', 64)->nullable()->after('delivered_action');

            $table->index(['channel', 'action']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_records', function (Blueprint $table) {
            $table->dropIndex(['channel', 'action']);
            $table->dropColumn(['action', 'delivered_action', 'delivered_hash']);
        });
    }
};
