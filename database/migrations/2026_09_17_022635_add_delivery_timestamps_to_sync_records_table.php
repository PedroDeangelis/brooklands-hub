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
            // When a delivery job claimed this record. Distinguishes a job that
            // is running from one that never started.
            $table->timestamp('started_at')->nullable()->after('dispatched_at');

            $table->timestamp('failed_at')->nullable()->after('synced_at');

            // How many times delivery has been attempted for the current state.
            $table->unsignedSmallInteger('attempts')->default(0)->after('failed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_records', function (Blueprint $table) {
            $table->dropColumn(['started_at', 'failed_at', 'attempts']);
        });
    }
};
