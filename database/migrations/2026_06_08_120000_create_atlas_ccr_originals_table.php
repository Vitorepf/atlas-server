<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AP-813 · Atlas Compression Layer — CCR original store.
 *
 * Content-addressed (sha256) durable store of the originals that the compression
 * layer replaced with a compressed form. Lossless-by-governance: rows are never
 * auto-expired; the original is always recoverable by `original_hash`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_ccr_originals', function (Blueprint $table) {
            $table->id();
            $table->string('original_hash', 64)->unique();
            $table->string('content_type', 40)->default('text');
            $table->string('codec', 16)->default('gzip');
            $table->unsignedBigInteger('original_bytes')->default(0);
            $table->unsignedBigInteger('compressed_bytes')->default(0);
            // base64(codec(original)) — portable across sqlite/postgres.
            $table->longText('compressed_blob');
            $table->string('privacy_class', 40)->default('internal');
            $table->string('ledger_event_id', 32)->nullable()->index();
            $table->string('scope_type', 40)->nullable();
            $table->string('scope_id', 80)->nullable();
            $table->string('recorded_by', 120)->default('atlas.compression');
            $table->unsignedInteger('retrieved_count')->default(0);
            $table->timestamp('last_retrieved_at')->nullable();
            $table->timestamps();

            $table->index(['content_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_ccr_originals');
    }
};
