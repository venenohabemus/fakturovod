<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archive_objects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();

            // One archived copy per artifact type per invoice.
            $table->string('type'); // 'ubl' | 'source'
            $table->unique(['invoice_id', 'type']);

            $table->string('disk');
            $table->string('path');

            // Integrity proof: at dispute time we can show the stored bytes
            // are exactly what was sent.
            $table->string('sha256', 64);
            $table->unsignedBigInteger('size_bytes');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_objects');
    }
};
