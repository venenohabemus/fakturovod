<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('direction')->default('outbound');
            $table->string('status')->index();

            // Idempotency: one source document = one invoice, re-ingest is a no-op.
            $table->string('external_id');
            $table->unique(['direction', 'external_id']);

            $table->string('number')->nullable();
            $table->string('receiver_peppol_id')->nullable();

            // Audit trail of the data as it moves through the pipe:
            // raw source rows → canonical model → UBL XML.
            $table->json('source_payload')->nullable();
            $table->json('mapping_definition')->nullable();
            $table->json('canonical')->nullable();
            $table->longText('ubl_xml')->nullable();
            $table->json('validation_report')->nullable();

            $table->string('postar_document_id')->nullable()->index();
            $table->text('error_message')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
