<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Repair governance, privacy and temporal columns on drifted memory tables. */
    public function up(): void
    {
        if (! Schema::hasTable('atlas_memory_entries')) {
            return;
        }

        $columns = Schema::getColumnListing('atlas_memory_entries');
        $add = static function (string $column, callable $definition) use (&$columns): void {
            if (in_array($column, $columns, true)) {
                return;
            }

            Schema::table('atlas_memory_entries', $definition);
            $columns[] = $column;
        };

        $add('redacted_title', static fn (Blueprint $table): mixed => $table->string('redacted_title', 180)->nullable());
        $add('redacted_body', static fn (Blueprint $table): mixed => $table->text('redacted_body')->nullable());
        $add('redacted_summary', static fn (Blueprint $table): mixed => $table->text('redacted_summary')->nullable());
        $add('privacy_class', static fn (Blueprint $table): mixed => $table->string('privacy_class', 24)->default('normal')->index());
        $add('external_ai_allowed', static fn (Blueprint $table): mixed => $table->boolean('external_ai_allowed')->default(true)->index());
        $add('redaction_status', static fn (Blueprint $table): mixed => $table->string('redaction_status', 24)->default('clean')->index());
        $add('content_hash', static fn (Blueprint $table): mixed => $table->string('content_hash', 64)->nullable()->index());
        $add('embedding_model', static fn (Blueprint $table): mixed => $table->string('embedding_model', 120)->nullable());
        $add('embedded_content_hash', static fn (Blueprint $table): mixed => $table->string('embedded_content_hash', 64)->nullable());
        $add('superseded_by_id', static fn (Blueprint $table): mixed => $table->uuid('superseded_by_id')->nullable()->index());
        $add('governance_checked_at', static fn (Blueprint $table): mixed => $table->timestamp('governance_checked_at')->nullable()->index());
        $add('privacy_reviewed_at', static fn (Blueprint $table): mixed => $table->timestamp('privacy_reviewed_at')->nullable()->index());
        $add('valid_from', static fn (Blueprint $table): mixed => $table->timestamp('valid_from')->nullable());
        $add('valid_until', static fn (Blueprint $table): mixed => $table->timestamp('valid_until')->nullable());
        $add('observed_at', static fn (Blueprint $table): mixed => $table->timestamp('observed_at')->nullable());
        $add('verified_at', static fn (Blueprint $table): mixed => $table->timestamp('verified_at')->nullable());
        $add('stale_after', static fn (Blueprint $table): mixed => $table->timestamp('stale_after')->nullable()->index());
        $add('source_hash', static fn (Blueprint $table): mixed => $table->string('source_hash', 64)->nullable());
        $add('authority_level', static fn (Blueprint $table): mixed => $table->string('authority_level', 40)->nullable()->index());
    }

    public function down(): void
    {
        // Repair migrations are intentionally non-destructive.
    }
};
