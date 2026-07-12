<?php

declare(strict_types=1);

use App\Services\Ai\Cognition\ImmuneSignatureStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable(ImmuneSignatureStore::TABLE)) {
            return;
        }

        Schema::create(ImmuneSignatureStore::TABLE, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 80)->default(ImmuneSignatureStore::SCHEMA_VERSION);
            $table->string('signature', 64)->unique();
            $table->string('content_hash', 64)->index();
            $table->json('signature_family');
            $table->string('origin_ref', 200)->index();
            $table->string('origin_kind', 40)->index();
            $table->string('hostile_class', 60)->index();
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestampTz('first_seen')->index();
            $table->timestampTz('last_hit_at')->nullable()->index();
            $table->string('status', 20)->default('active')->index();
            $table->string('reverse_handle', 240)->nullable();
            $table->json('metadata')->default('{}');
            $table->timestampsTz();

            $table->index(['status', 'content_hash'], 'immune_signature_store_status_content_idx');
            $table->index(['status', 'hit_count'], 'immune_signature_store_status_hits_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(ImmuneSignatureStore::TABLE);
    }
};
