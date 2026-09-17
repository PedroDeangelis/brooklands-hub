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
            // What collided on the website, as the receiver reported it. Kept
            // structured rather than folded into last_error so the dashboard can
            // explain each conflict and link to the occupying product.
            $table->json('conflict_details')->nullable()->after('last_error');

            $table->timestamp('conflicted_at')->nullable()->after('failed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_records', function (Blueprint $table) {
            $table->dropColumn(['conflict_details', 'conflicted_at']);
        });
    }
};
