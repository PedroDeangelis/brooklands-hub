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
        Schema::create('sync_checkpoints', function (Blueprint $table) {
            $table->id();

            // One row per Business Central entity. Entities change at their own
            // rates and are fetched independently, so each keeps its own
            // position rather than sharing one global watermark.
            $table->string('entity', 64)->unique();

            // The latest lastModifiedDateTime known to have been fetched
            // completely. Null means nothing has been fetched yet, which is
            // what makes a first run a complete catalogue sync.
            //
            // Millisecond precision, because the incremental filter compares
            // against this value with a strict "gt". Business Central sends
            // milliseconds, and at whole seconds the stored checkpoint would
            // sit earlier than the record it came from, so that record would
            // be re-fetched on every run for as long as it stayed the newest.
            $table->dateTime('last_modified_at', 3)->nullable();

            // Bookkeeping for the last completed run, so a person can see
            // whether the schedule is working without reading logs.
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('last_run_rows')->default(0);
            $table->unsignedInteger('last_run_pages')->default(0);
            $table->timestamp('last_full_sync_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_checkpoints');
    }
};
