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
            // Which kind of Business Central record this row tracks. The
            // channel says where a record is going; the entity says what it
            // is. Overloading the channel to mean both would make "website"
            // unavailable as the one destination both entities share.
            //
            // Defaulted to 'product' so every existing row keeps its meaning
            // without a backfill: until campaigns arrive, that is what they
            // all are.
            $table->string('entity', 16)->default('product')->after('channel');
        });

        Schema::table('sync_records', function (Blueprint $table) {
            // Identity is the triple. A product and a campaign could carry the
            // same bc_id without colliding, and the old pair would have
            // rejected the second one as a duplicate of the first.
            $table->dropUnique(['channel', 'bc_id']);
            $table->unique(['channel', 'entity', 'bc_id']);

            $table->dropIndex(['channel', 'status']);
            $table->index(['channel', 'entity', 'status']);

            $table->dropIndex(['channel', 'action']);
            $table->index(['channel', 'entity', 'action']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_records', function (Blueprint $table) {
            $table->dropUnique(['channel', 'entity', 'bc_id']);
            $table->unique(['channel', 'bc_id']);

            $table->dropIndex(['channel', 'entity', 'status']);
            $table->index(['channel', 'status']);

            $table->dropIndex(['channel', 'entity', 'action']);
            $table->index(['channel', 'action']);

            $table->dropColumn('entity');
        });
    }
};
