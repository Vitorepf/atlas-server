<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricTemplateFarmSimilarityGate;
use Tests\TestCase;

final class AtlasTaskFabricTemplateFarmSimilarityGateTest extends TestCase
{
    private function svc(): AtlasTaskFabricTemplateFarmSimilarityGate
    {
        return new AtlasTaskFabricTemplateFarmSimilarityGate;
    }

    private function packet(string $objective, array $acceptance = [], array $files = []): array
    {
        return [
            'objective' => $objective,
            'acceptance_criteria' => $acceptance,
            'allowed_files' => $files,
        ];
    }

    // ── single packet ─────────────────────────────────────────────────────────

    public function test_single_packet_gives_zero_similarity(): void
    {
        $r = $this->svc()->assess([
            $this->packet('Implement AtlasFooBar to compute something useful.'),
        ]);

        $this->assertEqualsWithDelta(0.0, $r['similarity_score'], 0.001);
        $this->assertFalse($r['blocking']);
        $this->assertSame([], $r['repeated_stems']);
    }

    // ── template farm detection ───────────────────────────────────────────────

    public function test_identical_objectives_produce_repeated_stems(): void
    {
        $sharedStem = 'implement to compute a score and return a result';
        $r = $this->svc()->assess([
            $this->packet("Implement AtlasFooBar to compute a score and return a result"),
            $this->packet("Implement AtlasBazQux to compute a score and return a result"),
            $this->packet("Implement AtlasZapWow to compute a score and return a result"),
        ]);

        $this->assertNotEmpty($r['repeated_stems']);
        $this->assertGreaterThan(0.0, $r['similarity_score']);
    }

    public function test_high_stem_overlap_triggers_blocking(): void
    {
        // 3/3 packets with same stem → score = 1.0 → blocking
        $packets = [];
        for ($i = 0; $i < 3; $i++) {
            $packets[] = $this->packet(
                "Implement AtlasVariant{$i} to evaluate the packet and return a score for the task",
                ['the gate must reject packets that fail validation and return a reason'],
            );
        }

        $r = $this->svc()->assess($packets);

        $this->assertTrue($r['blocking']);
        $this->assertGreaterThanOrEqual(AtlasTaskFabricTemplateFarmSimilarityGate::BLOCKING_THRESHOLD, $r['similarity_score']);
    }

    public function test_repeated_acceptance_fragments_listed(): void
    {
        $sharedCriterion = 'the implementation must be pure and must not call external services';
        $r = $this->svc()->assess([
            $this->packet('Implement AtlasFoo for discovery.', [$sharedCriterion]),
            $this->packet('Implement AtlasBar for origination.', [$sharedCriterion]),
        ]);

        $this->assertNotEmpty($r['repeated_acceptance_fragments']);
    }

    // ── diverse batch allowed ─────────────────────────────────────────────────

    public function test_diverse_batch_with_distinct_objectives_not_blocked(): void
    {
        $r = $this->svc()->assess([
            $this->packet(
                'Implement AtlasFoo to detect gate holes in the certification pipeline',
                ['given a gate report the system must surface missing gates'],
            ),
            $this->packet(
                'Implement AtlasBar to compress evidence records using adaptive sampling',
                ['given an evidence set the compressor must reduce size by thirty percent'],
            ),
            $this->packet(
                'Implement AtlasBaz to route stale capabilities to the retirement queue',
                ['given a capability with age above threshold the router must emit retire action'],
            ),
        ]);

        $this->assertFalse($r['blocking']);
    }

    public function test_macro_batch_shared_thesis_distinct_proof_paths_not_blocked(): void
    {
        // All say "implement to plan X" but each has a different failure mode / proof path
        $r = $this->svc()->assess([
            $this->packet(
                'Implement AtlasAlpha to plan the dependency resolution for missing owners',
                ['given a missing owner the system produces an unblock action with wiring evidence'],
            ),
            $this->packet(
                'Implement AtlasBeta to plan the merge schedule for redundant capabilities',
                ['given two overlapping organs the merger selects canonical owner by evidence delta'],
            ),
            $this->packet(
                'Implement AtlasGamma to plan the retirement path for zero-consumer capabilities',
                ['given an orphaned capability the planner emits retire with stall evidence attached'],
            ),
        ]);

        $this->assertFalse($r['blocking']);
    }

    // ── verdict fields ────────────────────────────────────────────────────────

    public function test_verdict_includes_all_required_fields(): void
    {
        $r = $this->svc()->assess([
            $this->packet('Implement AtlasFoo to do something.'),
            $this->packet('Implement AtlasBar to do something.'),
        ]);

        $this->assertArrayHasKey('similarity_score', $r);
        $this->assertArrayHasKey('repeated_stems', $r);
        $this->assertArrayHasKey('repeated_acceptance_fragments', $r);
        $this->assertArrayHasKey('blocking', $r);
        $this->assertArrayHasKey('packet_count', $r);
        $this->assertSame(2, $r['packet_count']);
    }

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->assess([]);

        $this->assertSame(AtlasTaskFabricTemplateFarmSimilarityGate::SCHEMA, $r['schema_version']);
    }
}
