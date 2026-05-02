<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atlas_memory_entries', function (Blueprint $table): void {
            if (! Schema::hasColumn('atlas_memory_entries', 'redacted_title')) {
                $table->string('redacted_title', 180)->nullable()->after('title');
            }
            if (! Schema::hasColumn('atlas_memory_entries', 'redacted_body')) {
                $table->text('redacted_body')->nullable()->after('body');
            }
            if (! Schema::hasColumn('atlas_memory_entries', 'redacted_summary')) {
                $table->text('redacted_summary')->nullable()->after('summary');
            }
            if (! Schema::hasColumn('atlas_memory_entries', 'privacy_class')) {
                $table->string('privacy_class', 24)->default('normal')->index()->after('confidence');
            }
            if (! Schema::hasColumn('atlas_memory_entries', 'external_ai_allowed')) {
                $table->boolean('external_ai_allowed')->default(true)->index()->after('privacy_class');
            }
            if (! Schema::hasColumn('atlas_memory_entries', 'redaction_status')) {
                $table->string('redaction_status', 24)->default('clean')->index()->after('external_ai_allowed');
            }
            if (! Schema::hasColumn('atlas_memory_entries', 'privacy_reviewed_at')) {
                $column = $table->timestamp('privacy_reviewed_at')->nullable()->index();
                if (Schema::hasColumn('atlas_memory_entries', 'governance_checked_at')) {
                    $column->after('governance_checked_at');
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('atlas_memory_entries', function (Blueprint $table): void {
            foreach ([
                'privacy_reviewed_at',
                'redaction_status',
                'external_ai_allowed',
                'privacy_class',
                'redacted_summary',
                'redacted_body',
                'redacted_title',
            ] as $column) {
                if (Schema::hasColumn('atlas_memory_entries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
