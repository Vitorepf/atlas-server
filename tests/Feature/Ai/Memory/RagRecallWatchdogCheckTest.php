<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Memory;

use App\Models\AtlasAurgEdge;
use App\Models\AtlasAurgNode;
use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryQualitySnapshot;
use App\Services\Ai\Memory\AtlasMemoryUsageService;
use App\Services\Ai\Cognition\Watchdog\AtlasAcosWatchdogHealthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

final class RagRecallWatchdogCheckTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.semantic_memory.recall_concentration_window_days', 45);

        $this->createAtlasMemoryEntryTable();
        Schema::dropIfExists('atlas_memory_entry_usages');
        Schema::dropIfExists('atlas_memory_quality_snapshots');
        Schema::dropIfExists('atlas_aurg_edges');
        Schema::dropIfExists('atlas_aurg_nodes');

        (require database_path('migrations/2026_05_02_001000_create_atlas_memory_entry_usages_table.php'))->up();
        (require database_path('migrations/2026_05_03_190000_create_atlas_memory_quality_snapshots_table.php'))->up();
        (require database_path('migrations/2026_06_09_120000_create_atlas_aurg_graph_tables.php'))->up();
        (require database_path('migrations/2026_07_07_181500_add_temporal_truth_to_atlas_aurg_edges.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_aurg_edges');
        Schema::dropIfExists('atlas_aurg_nodes');
        Schema::dropIfExists('atlas_memory_quality_snapshots');
        Schema::dropIfExists('atlas_memory_entry_usages');
        $this->dropAtlasMemoryEntryTable();

        parent::tearDown();
    }

    public function test_rag_watchdog_uses_live_pre_filter_concentration_without_mocking_memory_quality(): void
    {
        $entries = [];
        for ($i = 0; $i < 20; $i++) {
            $entries[] = $this->createActiveEntry('RAG watchdog memory '.$i);
        }

        foreach ($entries as $position => $entryId) {
            $this->insertUsage($entryId, AtlasMemoryUsageService::SOURCE_TYPE_MEMORY_RECALL, $position);
        }

        for ($i = 0; $i < 14; $i++) {
            $this->insertUsage($entries[0], AtlasMemoryUsageService::SOURCE_TYPE_RECALLED_PRE_FILTER, $i);
        }
        for ($i = 1; $i <= 6; $i++) {
            $this->insertUsage($entries[$i], AtlasMemoryUsageService::SOURCE_TYPE_RECALLED_PRE_FILTER, $i);
        }

        $this->seedMeasuredRecallSnapshot();
        $this->seedGreenAurgCoverage();

        $report = app(AtlasAcosWatchdogHealthService::class)->ragDimensionReport();

        $this->assertSame('alert', $report['status']);
        $this->assertContains('concentration_masked_by_delivery_filter', $report['issues']);
        $this->assertNotContains('retrieval_eval_below_floor', $report['issues']);
        $this->assertNotContains('recall_at_5_below_floor_or_unmeasured', $report['issues']);
        $this->assertNotContains('aurg_cross_layer_coverage_below_floor', $report['issues']);
        $this->assertNotContains('improper_floor_discards_present', $report['issues']);
        $this->assertSame(95, $report['raw']['retrieval_eval']);
        $this->assertSame(0.9, $report['raw']['recall_at_5']);
        $this->assertSame(0.7, $report['raw']['pre_filter_concentration_ratio']);
    }

    private function createActiveEntry(string $title): string
    {
        $entry = AtlasMemoryEntry::query()->create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => $title,
            'summary' => $title.' summary with rationale: seeded for RAG watchdog.',
            'body' => $title.' body with motivo: seeded integration evidence.',
            'status' => 'active',
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'redaction_status' => 'clean',
            'source_type' => 'test_fixture',
            'source_id' => 'rag-watchdog-'.substr(hash('sha256', $title), 0, 12),
            'recorded_at' => now(),
        ]);

        return (string) $entry->id;
    }

    private function insertUsage(string $memoryEntryId, string $sourceType, int $position): void
    {
        DB::table('atlas_memory_entry_usages')->insert([
            'id' => (string) Str::uuid(),
            'memory_entry_id' => $memoryEntryId,
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'source_type' => $sourceType,
            'position' => $position,
            'source_ref_json' => '{}',
            'context_payload_json' => '{}',
            'metadata' => '{}',
            'feedback_action' => $sourceType === AtlasMemoryUsageService::SOURCE_TYPE_MEMORY_RECALL ? 'useful' : null,
            'feedback_recorded_at' => $sourceType === AtlasMemoryUsageService::SOURCE_TYPE_MEMORY_RECALL ? now() : null,
            'used_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedMeasuredRecallSnapshot(): void
    {
        AtlasMemoryQualitySnapshot::query()->create([
            'source_type' => 'test_fixture',
            'status' => 'ready',
            'score' => 100,
            'components_json' => ['retrieval_eval' => 100],
            'counts_json' => [],
            'ratios_json' => [],
            'issues_json' => [],
            'recommendations_json' => [],
            'metadata' => [
                'memory_recall_corpus' => [
                    'metrics' => [
                        'recall_at_5' => 0.9,
                        'improper_floor_discards' => 0,
                    ],
                ],
            ],
            'snapshot_at' => now(),
        ]);
    }

    private function seedGreenAurgCoverage(): void
    {
        $this->node('memory:memory_entry:rag-watchdog', 'memory', 'memory_entry', 'rag-watchdog');
        $this->node('code:module:atlas-server/app', 'code', 'module', 'atlas-server/app');
        $this->node('domain:domain:memory', 'domain', 'domain', 'memory');
        $this->node('evidence:ledger:rag-watchdog', 'evidence', 'ledger_event', 'rag-watchdog');

        $this->edge('memory:memory_entry:rag-watchdog', 'code:module:atlas-server/app', 'linker_memory_code');
        $this->edge('memory:memory_entry:rag-watchdog', 'domain:domain:memory', 'linker_memory_domain');
        $this->edge('evidence:ledger:rag-watchdog', 'memory:memory_entry:rag-watchdog', 'linker_evidence');
    }

    private function node(string $id, string $sourceKind, string $kind, string $sourceId): void
    {
        AtlasAurgNode::query()->create([
            'id' => $id,
            'kind' => $kind,
            'source_kind' => $sourceKind,
            'source_id' => $sourceId,
            'label' => $id,
            'workspace_id' => null,
            'provider_safe' => true,
            'sensitive' => false,
            'meta' => [],
            'content_hash' => hash('sha256', $id),
        ]);
    }

    private function edge(string $from, string $to, string $source): void
    {
        AtlasAurgEdge::query()->create([
            'from_node_id' => $from,
            'to_node_id' => $to,
            'kind' => 'references',
            'source' => $source,
            'confidence' => 1.0,
            'meta' => [],
        ]);
    }
}
