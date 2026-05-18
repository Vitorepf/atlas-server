<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TEOS-I1 / M3 — Ledger Timeline Extension (Audit Event surface).
 *
 * Mirrors the timeline-field additions onto `ai_audit_events` so scoped
 * timeline reconstruction works whether the producer chose the Ledger
 * (envelope-centric) or the Audit Event (evidence-runtime-centric)
 * surface. All columns are nullable, all writers untouched.
 *
 * Adds:
 *   - scope_type     (string, 40, nullable, indexed)
 *   - scope_id       (string, 80, nullable, indexed)
 *   - correlation_id (string, 120, nullable, indexed)
 *   - causation_id   (string, 80, nullable, indexed)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_audit_events')) {
            return;
        }

        Schema::table('ai_audit_events', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_audit_events', 'scope_type')) {
                $table->string('scope_type', 40)->nullable()->index('ai_audit_events_scope_type_index');
            }
            if (! Schema::hasColumn('ai_audit_events', 'scope_id')) {
                $table->string('scope_id', 80)->nullable()->index('ai_audit_events_scope_id_index');
            }
            if (! Schema::hasColumn('ai_audit_events', 'correlation_id')) {
                $table->string('correlation_id', 120)->nullable()->index('ai_audit_events_correlation_id_index');
            }
            if (! Schema::hasColumn('ai_audit_events', 'causation_id')) {
                $table->string('causation_id', 80)->nullable()->index('ai_audit_events_causation_id_index');
            }
        });

        Schema::table('ai_audit_events', function (Blueprint $table): void {
            $table->index(
                ['scope_type', 'scope_id', 'created_at'],
                'ai_audit_events_scope_created_index',
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_audit_events')) {
            return;
        }

        Schema::table('ai_audit_events', function (Blueprint $table): void {
            $table->dropIndex('ai_audit_events_scope_created_index');
        });

        Schema::table('ai_audit_events', function (Blueprint $table): void {
            foreach (['scope_type', 'scope_id', 'correlation_id', 'causation_id'] as $column) {
                if (Schema::hasColumn('ai_audit_events', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
