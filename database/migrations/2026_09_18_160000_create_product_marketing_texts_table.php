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
        Schema::create('product_marketing_texts', function (Blueprint $table) {
            $table->id();

            // marketingTextExt is its own Business Central entity, but it is
            // keyed by the same item GUID, which is what joins a row to its
            // product. Business Central calls it itemId on this page.
            $table->uuid('bc_id')->unique();
            $table->string('sku')->index();

            // Sanitised on the way in, so what is stored is what is delivered.
            // longText because the copy is authored prose with markup and has
            // no meaningful length limit; TEXT's 64KB is close enough to real
            // entries to be worth avoiding.
            $table->longText('marketing_text')->nullable();

            // The lead sentence, derived once at import rather than at every
            // read, so the website and any local view agree on it.
            $table->text('short_description')->nullable();

            $table->dateTime('bc_modified_at')->nullable()->index();

            $table->json('bc_payload')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_marketing_texts');
    }
};
