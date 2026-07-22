<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Context\Retrieval;

use App\Services\Ai\Context\Retrieval\RagxChainMechanismService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class RagxChainMechanismServiceTest extends TestCase
{
    public function test_default_flags_keep_ragx_chain_disabled_without_claiming_green(): void
    {
        $service = new RagxChainMechanismService;

        $report = $service->stageReport();

        $this->assertSame(RagxChainMechanismService::SCHEMA, $report['schema_version']);
        $this->assertFalse($report['ab_green_claimed']);
        $this->assertSame('default_off', $report['mode']);
        $this->assertSame('disabled', $report['stages']['RAGX-01']['status']);
        $this->assertSame('atlas.aobg.ragx_late_chunk_index', $report['stages']['RAGX-01']['flag']);
        $this->assertSame('disabled', $report['stages']['RAGX-02']['status']);
        $this->assertSame('disabled', $report['stages']['RAGX-06']['status']);
        $this->assertSame('disabled', $report['stages']['RAGX-03']['status']);
        $this->assertSame('disabled', $report['stages']['RAGX-11']['status']);
        $this->assertSame('disabled', $report['stages']['RAGX-05']['status']);
        $this->assertSame('disabled', $report['stages']['RAGX-07']['status']);
        $this->assertSame('disabled', $report['stages']['MAXD-05']['status']);
        $this->assertSame('disabled', $report['stages']['RAGX-10']['status']);
    }

    public function test_ragx01_late_chunk_shadow_refuses_until_maxa04_is_promoted(): void
    {
        config()->set('atlas.aobg.ragx_late_chunk_index', true);
        config()->set('atlas.aobg.ragx_late_chunk_maxa04_promoted', false);

        $service = new RagxChainMechanismService;

        $result = $service->lateChunkIndexShadow('mid document query', 5);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame(['MAXA-04'], $result['blocked_by']);
        $this->assertSame(['jina_v3_dual_read_benchmark_window'], $result['pending_window']);
        $this->assertSame([], $result['documents']);
        $this->assertFalse($result['ab_green_claimed']);
    }

    public function test_ragx07_ab_registrar_records_only_and_never_fabricates_results(): void
    {
        $ledgerPath = storage_path('framework/testing/ragx-ab-registrar.jsonl');
        File::delete($ledgerPath);

        $service = new RagxChainMechanismService(abLedgerPath: $ledgerPath);

        $record = $service->registerAb([
            'experiment_id' => 'ragx01-shadow-001',
            'slice' => 'RAGX-01',
            'baseline' => 'single_vector',
            'candidate' => 'late_chunk_shadow',
        ]);

        $this->assertSame(RagxChainMechanismService::AB_SCHEMA, $record['schema_version']);
        $this->assertSame('registered', $record['status']);
        $this->assertFalse($record['ab_green_claimed']);
        $this->assertNull($record['result']);
        $this->assertSame(['golden_v2_or_live_window_not_run'], $record['pending_window']);
        $this->assertFileExists($ledgerPath);

        $line = trim((string) file_get_contents($ledgerPath));
        $stored = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ragx01-shadow-001', $stored['experiment_id']);
        $this->assertFalse($stored['ab_green_claimed']);
        $this->assertNull($stored['result']);
    }

    public function test_maxd05_louvain_over_chunks_requires_maxa06_fase2_backfill_then_clusters_real_edges(): void
    {
        config()->set('atlas.aobg.ragx_louvain_chunks', true);
        config()->set('atlas.aobg.ragx_maxa06_fase2_backfilled', false);

        $service = new RagxChainMechanismService;

        $blocked = $service->louvainOverChunks(
            chunks: [['id' => 'c1'], ['id' => 'c2']],
            edges: [['source' => 'c1', 'target' => 'c2', 'weight' => 0.9]],
        );

        $this->assertSame('blocked', $blocked['status']);
        $this->assertSame(['MAXA-06(fase 2)'], $blocked['blocked_by']);

        config()->set('atlas.aobg.ragx_maxa06_fase2_backfilled', true);

        $clustered = $service->louvainOverChunks(
            chunks: [['id' => 'c1'], ['id' => 'c2'], ['id' => 'c3']],
            edges: [['source' => 'c1', 'target' => 'c2', 'weight' => 0.9]],
        );

        $this->assertSame('ok', $clustered['status']);
        $this->assertSame('louvain_deterministic_local', $clustered['algorithm']);
        $this->assertContains(['c1', 'c2'], $clustered['communities']);
        $this->assertContains(['c3'], $clustered['communities']);
    }

    public function test_ragx10_raptor_lite_requires_louvain_and_maxf09_and_never_generates_fake_summaries(): void
    {
        config()->set('atlas.aobg.ragx_raptor_lite', true);
        $service = new RagxChainMechanismService;

        $missingLouvain = $service->raptorLite(
            communities: [['c1', 'c2']],
            verifiedSummaries: [],
            deps: ['maxd05_louvain' => false, 'maxf09_l2_summaries' => true],
        );
        $this->assertSame('blocked', $missingLouvain['status']);
        $this->assertSame(['MAXD-05'], $missingLouvain['blocked_by']);

        $missingSummaries = $service->raptorLite(
            communities: [['c1', 'c2']],
            verifiedSummaries: [],
            deps: ['maxd05_louvain' => true, 'maxf09_l2_summaries' => false],
        );
        $this->assertSame('blocked', $missingSummaries['status']);
        $this->assertSame(['MAXF-09'], $missingSummaries['blocked_by']);

        $noEvidence = $service->raptorLite(
            communities: [['c1', 'c2']],
            verifiedSummaries: [],
            deps: ['maxd05_louvain' => true, 'maxf09_l2_summaries' => true],
        );
        $this->assertSame('insufficient_signal', $noEvidence['status']);
        $this->assertSame([], $noEvidence['nodes']);
        $this->assertFalse($noEvidence['generated_summary']);
    }

    public function test_m3b_retrieval_owners_must_resolve_from_context_retrieval(): void
    {
        foreach ([
            'AsefChunkIndexService',
            'AtlasCodeSymbolEmbeddingCoverageService',
            'AtlasKnowledgeItemEmbeddingCoverageService',
            'CitationGroundingMeter',
            'DomainLexicalNormalizer',
            'GatedCorpusCandidateMiner',
            'GoldenCounterfactualReplayService',
            'Maxa04JinaV3DualReadLedger',
            'Maxa04JinaV3DualReadService',
            'ProvenanceWeightCalculator',
            'RagxChainMechanismService',
            'RecallGapAggregator',
        ] as $owner) {
            $canonicalFqcn = 'App\\Services\\Ai\\Context\\Retrieval\\'.$owner;

            $this->assertTrue(class_exists($canonicalFqcn), $canonicalFqcn.' must resolve from the canonical Context Retrieval namespace.');
        }
    }

    public function test_legacy_acosmax_retrieval_fqcns_remain_autoloadable_during_m3_compatibility_cycle(): void
    {
        foreach ([
            'AsefChunkIndexService',
            'AtlasCodeSymbolEmbeddingCoverageService',
            'AtlasKnowledgeItemEmbeddingCoverageService',
            'CitationGroundingMeter',
            'DomainLexicalNormalizer',
            'GatedCorpusCandidateMiner',
            'GoldenCounterfactualReplayService',
            'Maxa04JinaV3DualReadLedger',
            'Maxa04JinaV3DualReadService',
            'ProvenanceWeightCalculator',
            'RagxChainMechanismService',
            'RecallGapAggregator',
        ] as $owner) {
            $legacyFqcn = 'App\\Services\\Ai\\AcosMax\\'.$owner;

            $this->assertTrue(class_exists($legacyFqcn), $legacyFqcn.' must remain autoloadable through the M3 compatibility cycle.');
        }
    }
}
