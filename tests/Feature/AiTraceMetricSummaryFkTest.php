<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiTraceMetricSummaryFkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'FK is only enforced on PostgreSQL. SQLite cannot ALTER TABLE ADD FOREIGN KEY '
                .'on existing tables, and the test environment uses an in-memory SQLite database. '
                .'Run this test with DB_CONNECTION=pgsql to verify production behavior.'
            );
        }

        if (! Schema::hasTable('ai_trace_metric_summaries')) {
            $this->markTestSkipped(
                'ai_trace_metric_summaries table not present — run "php artisan migrate" first.'
            );
        }
    }

    public function test_trace_id_fk_constraint_exists_with_cascade_delete(): void
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT pg_get_constraintdef(c.oid) AS definition
            FROM pg_constraint c
            WHERE c.conname = 'ai_trace_metric_summaries_trace_id_foreign'
        SQL);

        $this->assertNotNull(
            $row,
            'Expected FK ai_trace_metric_summaries_trace_id_foreign to exist on the database. '
                .'Run "php artisan migrate" to apply 2026_05_01_003000_add_trace_id_fk_to_ai_trace_metric_summaries.'
        );

        $definition = (string) $row->definition;

        $this->assertStringContainsString('FOREIGN KEY (trace_id)', $definition);
        $this->assertStringContainsString('REFERENCES ai_traces(id)', $definition);
        $this->assertStringContainsString('ON DELETE CASCADE', $definition,
            'FK must cascade-delete so a removed trace removes its summary.');
    }

    public function test_trace_id_unique_constraint_still_present(): void
    {
        // Sanity check: the FK migration must not regress the pre-existing UNIQUE constraint.
        $row = DB::selectOne(<<<'SQL'
            SELECT pg_get_constraintdef(c.oid) AS definition
            FROM pg_constraint c
            WHERE c.conname = 'ai_trace_metric_summaries_trace_id_unique'
        SQL);

        $this->assertNotNull($row, 'UNIQUE on trace_id must remain after FK migration.');
        $this->assertStringContainsString('UNIQUE (trace_id)', (string) $row->definition);
    }
}
