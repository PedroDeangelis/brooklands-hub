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
            // The complete website payload currently desired. A hash can say
            // that something moved, but only the payload itself can say which
            // website fields moved, which is what a partial delivery needs.
            $table->json('payload')->nullable()->after('payload_hash');

            // The complete website payload from the last successful delivery,
            // which is the baseline every partial change is computed against.
            $table->json('delivered_payload')->nullable()->after('delivered_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_records', function (Blueprint $table) {
            $table->dropColumn(['payload', 'delivered_payload']);
        });
    }
};
