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
        Schema::table('sales_invoices', function (Blueprint $table) {
            // Invoice or credit memo. The website stores both as one post type
            // told apart by status, and so does this table. Every row that
            // exists before this column does is an invoice.
            $table->string('kind', 16)->default('invoice')->after('number')->index();

            // A credit memo's GUID on the standard API, which can differ from
            // the custom page's id. Its lines are read by it, and the website
            // builds the customer's PDF link from it. Null for an invoice.
            $table->string('document_api_id', 36)->nullable()->after('bc_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->dropColumn(['kind', 'document_api_id']);
        });
    }
};
