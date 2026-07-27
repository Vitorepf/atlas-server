<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition;

use App\Models\AtlasAurgEdge;
use App\Models\AtlasAurgNode;
use App\Services\Ai\Memory\AtlasMemoryQualityService;
use App\Services\Ai\Cognition\Watchdog\AtlasAcosWatchdogHealthService;
use App\Services\Ai\Reality\AtlasRealityGraphStatusService;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

final class AurgCoverageGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_aurg_edges');
        Schema::dropIfExists('atlas_aurg_nodes');
        (require database_path('migrations/2026_06_09_120000_create_atlas_aurg_graph_tables.php'))->up();
        (require database_path('migrations/2026_07_07_181500_add_temporal_truth_to_atlas_aurg_edges.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_aurg_edges');
        Schema::dropIfExists('atlas_aurg_nodes');

        parent::tearDown();
    }

    public function test_aurg_coverage_gate_alerts_when_memory_cross_layer_coverage_is_below_floor(): void
    {
        $this->node('memory:memory_entry:m1', 'memory', 'memory_entry', 'm1');
        $this->node('memory:memory_entry:m2', 'memory', 'memory_entry', 'm2');
        $this->node('code:module:atlas-server/app', 'code', 'module', 'atlas-server/app');
        AtlasAurgEdge::query()->create([
            'from_node_id' => 'memory:memory_entry:m1',
            'to_node_id' => 'code:module:atlas-server/app',
            'kind' => 'references',
            'source' => 'linker_memory_code',
            'confidence' => 1.0,
            'meta' => [],
        ]);

        $report = app(AtlasAcosWatchdogHealthService::class)->aurgCoverageReport();

        $this->assertSame('alert', $report['status']);
        $this->assertSame(0.5, $report['coverage']['memory_cross_layer_coverage_ratio']);
        $this->assertContains('cross_layer_linker_zero:linker_memory_domain', $report['blocking']);
    }

    public function test_rag10_coverage_count_excludes_doc_code_index_edges(): void
    {
        $this->node('memory:memory_entry:m1', 'memory', 'memory_entry', 'm1');
        $this->node('code:module:atlas-server/app', 'code', 'module', 'atlas-server/app');
        $this->node('doc:doc:docs/engineering-knowledge-base/example.md', 'doc', 'doc', 'docs/engineering-knowledge-base/example.md');
        $this->node('doc:doc:docs/engineering-knowledge-base/owner.md', 'doc', 'doc', 'docs/engineering-knowledge-base/owner.md');
        AtlasAurgEdge::query()->create([
            'from_node_id' => 'memory:memory_entry:m1',
            'to_node_id' => 'code:module:atlas-server/app',
            'kind' => 'references',
            'source' => 'linker_memory_code',
            'confidence' => 1.0,
            'meta' => [],
        ]);
        AtlasAurgEdge::query()->create([
            'from_node_id' => 'doc:doc:docs/engineering-knowledge-base/example.md',
            'to_node_id' => 'code:module:atlas-server/app',
            'kind' => 'references',
            'source' => 'linker_doc_code_index',
            'confidence' => 1.0,
            'meta' => ['link_count' => 10],
        ]);
        AtlasAurgEdge::query()->create([
            'from_node_id' => 'doc:doc:docs/engineering-knowledge-base/owner.md',
            'to_node_id' => 'code:module:atlas-server/app',
            'kind' => 'references',
            'source' => 'linker_doc_authority',
            'confidence' => 1.0,
            'meta' => ['owner_basis' => 'doc_path_fixture'],
        ]);

        $status = app(AtlasRealityGraphStatusService::class)->status();

        $this->assertSame(1, $status['coverage']['cross_layer_linker_edges']);
        $this->assertSame(1.0, $status['coverage']['memory_cross_layer_coverage_ratio']);
        $this->assertSame(1, $status['store']['edges_by_source']['linker_doc_code_index']);
        $this->assertSame(1, $status['store']['edges_by_source']['linker_doc_authority']);
    }

    public function test_rag_dimension_watchdog_reports_concentration_masked_by_delivery_filter(): void
    {
        $quality = Mockery::mock(AtlasMemoryQualityService::class);
        $quality->shouldReceive('scorecard')->once()->andReturn([
            'components' => ['retrieval_eval' => 96],
            'ratios' => ['recall_concentration_ratio' => 0.72],
            'counts' => [
                'retrieval_eval' => [
                    'recall_usage_total' => 25,
                    'recall_negative_feedback' => 0,
                ],
            ],
            'latest_snapshot' => [
                'metadata' => [
                    'memory_recall_corpus' => [
                        'metrics' => ['recall_at_5' => 0.9, 'improper_floor_discards' => 0],
                    ],
                ],
            ],
        ]);
        $this->instance(AtlasMemoryQualityService::class, $quality);

        $aurg = Mockery::mock(AtlasRealityGraphStatusService::class);
        $aurg->shouldReceive('status')->once()->andReturn([
            'coverage' => [
                'available' => true,
                'memory_cross_layer_coverage_ratio' => 0.7,
                'cross_layer_linker_edges' => 3,
            ],
            'store' => ['edges_by_source' => ['linker_memory_code' => 1, 'linker_memory_domain' => 1, 'linker_evidence' => 1]],
        ]);
        $this->instance(AtlasRealityGraphStatusService::class, $aurg);

        $report = app(AtlasAcosWatchdogHealthService::class)->ragDimensionReport();

        $this->assertSame('alert', $report['status']);
        $this->assertContains('concentration_masked_by_delivery_filter', $report['issues']);
        $this->assertSame(0.72, $report['raw']['pre_filter_concentration_ratio']);
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
}
