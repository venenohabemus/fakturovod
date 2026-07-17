<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_meters', function (Blueprint $table) {
            $table->id();

            // One counter per metric per calendar month. Tenant column comes
            // with multi-tenancy; single client for the MVP.
            $table->string('period', 7); // 'YYYY-MM'
            $table->string('metric');    // e.g. 'documents_sent'
            $table->unique(['period', 'metric']);

            $table->unsignedBigInteger('count')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_meters');
    }
};
