<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSemanticDuplicateDetector;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainSemanticDuplicateDetectorTest extends TestCase
{
    private function detector(): AtlasExternalBrainSemanticDuplicateDetector
    {
        return new AtlasExternalBrainSemanticDuplicateDetector;
    }

    // ── AC: semantic_duplicate_case — multiple channels agree ─────────────────

    public function test_semantic_duplicate_case_when_purpose_and_io_contract_agree(): void
    {
        $r = $this->detector()->detect([
            'candidate_a' => [
                'name' => 'FooDeduper',
                'normalized_purpose' => 'deduplicate incoming task packets',
                'io_contract' => ['inputs' => ['packet'], 'outputs' => ['dedup_result']],
            ],
            'candidate_b' => [
                'name' => 'BarDeduplicationService',
                'normalized_purpose' => 'deduplicate incoming task packets',
                'io_contract' => ['inputs' => ['packet'], 'outputs' => ['dedup_result']],
            ],
        ]);

        $this->assertTrue($r['duplicate_confirmed']);
        $this->assertContains('normalized_purpose', $r['matching_channels']);
        $this->assertContains('io_contract', $r['matching_channels']);
        $this->assertGreaterThanOrEqual(2, $r['confidence']);
    }

    public function test_semantic_duplicate_case_when_purpose_and_consumer_overlap_agree(): void
    {
        $r = $this->detector()->detect([
            'candidate_a' => [
                'normalized_purpose' => 'rank leverage candidates',
                'consumers' => ['AtlasLoopNextWorkDecider', 'AtlasMaestroPriorityService'],
            ],
            'candidate_b' => [
                'normalized_purpose' => 'rank leverage candidates',
                'consumers' => ['AtlasMaestroPriorityService'],
            ],
        ]);

        $this->assertTrue($r['duplicate_confirmed']);
        $this->assertContains('consumer_overlap', $r['matching_channels']);
    }

    public function test_semantic_duplicate_case_when_consumer_and_evidence_overlap_agree(): void
    {
        $r = $this->detector()->detect([
            'candidate_a' => [
                'consumers' => ['WorkerX'],
                'evidence_refs' => ['evidence://1', 'evidence://2'],
            ],
            'candidate_b' => [
                'consumers' => ['WorkerX'],
                'evidence_refs' => ['evidence://2'],
            ],
        ]);

        $this->assertTrue($r['duplicate_confirmed']);
        $this->assertSame(['consumer_overlap', 'evidence_refs'], $r['matching_channels']);
    }

    // ── AC: same_name_only_rejection_case — name similarity never counts ──────

    public function test_same_name_only_rejection_case(): void
    {
        $r = $this->detector()->detect([
            'candidate_a' => [
                'name' => 'FooService',
                'normalized_purpose' => 'compress leverage portfolios',
                'io_contract' => ['inputs' => ['portfolio'], 'outputs' => ['compressed_plan']],
                'consumers' => ['WorkerA'],
                'evidence_refs' => ['evidence://a'],
            ],
            'candidate_b' => [
                // Exact same name, but every other signal differs.
                'name' => 'FooService',
                'normalized_purpose' => 'validate task packet schema',
                'io_contract' => ['inputs' => ['packet'], 'outputs' => ['validation_result']],
                'consumers' => ['WorkerB'],
                'evidence_refs' => ['evidence://b'],
            ],
        ]);

        $this->assertFalse($r['duplicate_confirmed']);
        $this->assertSame([], $r['matching_channels']);
    }

    public function test_suffix_only_name_variant_rejection_case(): void
    {
        $r = $this->detector()->detect([
            'candidate_a' => [
                'name' => 'FooService',
                'normalized_purpose' => 'compress leverage portfolios',
                'consumers' => ['WorkerA'],
            ],
            'candidate_b' => [
                'name' => 'FooServiceV2',
                'normalized_purpose' => 'validate task packet schema',
                'consumers' => ['WorkerB'],
            ],
        ]);

        $this->assertFalse($r['duplicate_confirmed']);
    }

    public function test_single_matching_channel_is_not_enough(): void
    {
        // Only normalized_purpose agrees — one channel alone must not confirm duplicate.
        $r = $this->detector()->detect([
            'candidate_a' => ['normalized_purpose' => 'shared purpose text', 'consumers' => ['WorkerA']],
            'candidate_b' => ['normalized_purpose' => 'shared purpose text', 'consumers' => ['WorkerB']],
        ]);

        $this->assertFalse($r['duplicate_confirmed']);
        $this->assertSame(1, $r['confidence']);
    }

    public function test_empty_io_contract_never_matches(): void
    {
        $r = $this->detector()->detect([
            'candidate_a' => ['normalized_purpose' => 'x'],
            'candidate_b' => ['normalized_purpose' => 'x'],
        ]);

        $this->assertNotContains('io_contract', $r['matching_channels']);
    }

    // ── Determinism ─────────────────────────────────────────────────────────

    public function test_detect_is_deterministic(): void
    {
        $facts = [
            'candidate_a' => ['normalized_purpose' => 'x', 'consumers' => ['W1']],
            'candidate_b' => ['normalized_purpose' => 'x', 'consumers' => ['W1']],
        ];
        $a = $this->detector()->detect($facts);
        $b = $this->detector()->detect($facts);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_schema_version_present(): void
    {
        $r = $this->detector()->detect([]);
        $this->assertSame(AtlasExternalBrainSemanticDuplicateDetector::SCHEMA, $r['schema']);
    }
}
