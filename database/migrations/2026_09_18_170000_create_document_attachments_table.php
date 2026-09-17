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
        Schema::create('document_attachments', function (Blueprint $table) {
            $table->id();

            // The attachment's own Business Central id, and its identity
            // everywhere: it is what the website stores alongside the filename
            // so a re-import updates an entry rather than duplicating it.
            $table->uuid('bc_id')->unique();

            // The record this file hangs off. Both columns are needed to place
            // it: Business Central ids are only unique within a parent type.
            $table->uuid('parent_bc_id');
            $table->string('parent_type', 32);

            // Only the name. The bytes stay in Business Central and are
            // streamed on demand through /bc-doc/{attachmentId}; storing
            // megabytes here to serve something nothing reads would be waste.
            $table->string('file_name');

            $table->dateTime('bc_modified_at')->nullable()->index();

            $table->json('bc_payload')->nullable();

            $table->timestamps();

            // Every read is "the attachments for this parent", which is what
            // builds a parent's payload and what the list page groups by.
            $table->index(['parent_type', 'parent_bc_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_attachments');
    }
};
