<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mapping definitions are data, not code: one versioned JSON per
        // client system. Saving bumps the version; invoices keep their own
        // snapshot, so old invoices are unaffected by later edits.
        Schema::create('mappings', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('version')->default(1);
            $table->json('definition');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mappings');
    }
};
