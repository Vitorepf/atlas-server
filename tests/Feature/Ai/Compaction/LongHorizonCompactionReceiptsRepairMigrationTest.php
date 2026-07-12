<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Compaction;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class LongHorizonCompactionReceiptsRepairMigrationTest extends TestCase
{
    private const MIGRATION = 'migrations/2026_07_11_190000_repair_missing_atlas_long_horizon_compaction_receipts_table.php';

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_long_horizon_compaction_receipts');

        parent::tearDown();
    }

    public function test_it_recreates_missing_receipts_table_with_retention_score_idempotently(): void
    {
        Schema::dropIfExists('atlas_long_horizon_compaction_receipts');
        $before = $this->migrationLedger();
        $migration = require database_path(self::MIGRATION);

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasTable('atlas_long_horizon_compaction_receipts'));
        foreach ($this->expectedColumns() as $column) {
            $this->assertTrue(
                Schema::hasColumn('atlas_long_horizon_compaction_receipts', $column),
                "Missing atlas_long_horizon_compaction_receipts.{$column}",
            );
        }
        $this->assertSame($before, $this->migrationLedger());
    }

    /**
     * @return list<string>
     */
    private function expectedColumns(): array
    {
        return [
            'id',
            'schema_version',
            'uuid',
            'scope_type',
            'scope_id',
            'source_context_refs',
            'retained_items',
            'discarded_items',
            'discarded_reason',
            'must_keep_items',
            'must_keep_coverage',
            'unresolved_loss',
            'loss_risk',
            'recovery_queries',
            'evidence_refs',
            'summary_hash',
            'context_retention_score',
            'quality_score',
            'detected_contradictions',
            'stale_risks',
            'receipt_hash',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * @return list<string>
     */
    private function migrationLedger(): array
    {
        return Schema::hasTable('migrations')
            ? DB::table('migrations')->orderBy('migration')->pluck('migration')->all()
            : [];
    }
}
