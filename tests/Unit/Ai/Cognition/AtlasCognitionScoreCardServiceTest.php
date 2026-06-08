<?php

namespace Tests\Unit\Ai\Cognition;

use App\Services\Ai\Cognition\AtlasCognitionEvidenceResolver;
use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use App\Services\Ai\Cognition\CognitiveImmuneCheckContract;
use Tests\TestCase;

/**
 * Atlas Cognition Operating System — scorecard service tests (v3).
 */
class AtlasCognitionScoreCardServiceTest extends TestCase
{
    public function test_canonical_subsystem_count_is_73(): void
    {
        $this->assertSame(73, AtlasCognitionScoreCardService::canonicalSubsystemCount());
    }

    public function test_swarm_executor_present(): void
    {
        $acronyms = array_map(fn ($r) => $r['acronym'], (new AtlasCognitionScoreCardService)->build()['subsystems']);
        $this->assertContains('ASWE', $acronyms);
    }

    public function test_subsystem_auto_rebalance_present(): void
    {
        $acronyms = array_map(fn ($r) => $r['acronym'], (new AtlasCognitionScoreCardService)->build()['subsystems']);
        $this->assertContains('ASAR', $acronyms);
    }

    public function test_nightly_counterfactuals_present(): void
    {
        $acronyms = array_map(fn ($r) => $r['acronym'], (new AtlasCognitionScoreCardService)->build()['subsystems']);
        $this->assertContains('ANCF', $acronyms);
    }

    public function test_constitutional_vault_present(): void
    {
        $acronyms = array_map(fn ($r) => $r['acronym'], (new AtlasCognitionScoreCardService)->build()['subsystems']);
        $this->assertContains('ACVS', $acronyms);
    }

    public function test_trust_budget_service_present(): void
    {
        $acronyms = array_map(fn ($r) => $r['acronym'], (new AtlasCognitionScoreCardService)->build()['subsystems']);
        $this->assertContains('ATBS', $acronyms);
    }

    public function test_cartography_truth_guard_present(): void
    {
        $acronyms = array_map(fn ($r) => $r['acronym'], (new AtlasCognitionScoreCardService)->build()['subsystems']);
        $this->assertContains('ACTG', $acronyms);
    }

    public function test_gateway_preflight_present(): void
    {
        $acronyms = array_map(fn ($r) => $r['acronym'], (new AtlasCognitionScoreCardService)->build()['subsystems']);
        $this->assertContains('AGPF', $acronyms);
    }

    public function test_atlas_decide_live_outcome_feedback_present(): void
    {
        $acronyms = array_map(fn ($r) => $r['acronym'], (new AtlasCognitionScoreCardService)->build()['subsystems']);
        $this->assertContains('ADLF', $acronyms);
    }

    public function test_build_returns_canonical_envelope(): void
    {
        $r = (new AtlasCognitionScoreCardService)->build();

        $this->assertSame('atlas.cognition.scorecard.v3', $r['schema_version']);
        $this->assertSame(73, $r['subsystem_count']);
        $this->assertCount(73, $r['subsystems']);
        $this->assertArrayHasKey('score', $r);
        $this->assertArrayHasKey('scorecard_hash', $r);
        $this->assertStringStartsWith('sha256:', $r['scorecard_hash']);
        $this->assertArrayHasKey('notes', $r);
    }

    public function test_each_row_has_three_structural_dimensions(): void
    {
        foreach ((new AtlasCognitionScoreCardService)->build()['subsystems'] as $row) {
            foreach (['acronym', 'name', 'group', 'code_status', 'doc_status', 'pipeline_status'] as $k) {
                $this->assertArrayHasKey($k, $row);
            }
            $this->assertArrayNotHasKey('volume_status', $row);
            foreach (['code_status', 'doc_status', 'pipeline_status'] as $k) {
                $this->assertContains($row[$k], ['ready', 'partial', 'building', 'blocked']);
            }
        }
    }

    /**
     * HONEST replacement for the old `test_overall_reaches_10_when_all_three_dimensions_ready`.
     *
     * doc_status/pipeline_status are no longer hardcoded 'ready' — they are RESOLVED from
     * real evidence at runtime (FQN-bound doc ownership + a fresh B3 green-run receipt).
     * So this asserts the HONEST resolved shape, recomputed from the SAME rows the service
     * returns (self-checking, not a frozen magic number):
     *   - code dimension == 10.0 (still all 73 classes exist — the one already-real dim);
     *   - doc dimension  < 10.0 AND EXACTLY the per-row resolved doc-status weighting;
     *   - pipeline dim   <= doc dimension AND EXACTLY the resolved pipeline weighting;
     *   - overall        < 10.0 AND == the mean of the three resolved dimensions.
     * The over-claim being killed is the gap from 10/10 — it is the deliverable, not a
     * regression. (No frozen overall is asserted because partial-credit + the live receipt
     * count make it a runtime-computed value — we assert it EQUALS the resolved counts.)
     */
    public function test_dimensions_are_resolved_from_real_evidence_not_hardcoded_ten(): void
    {
        $envelope = (new AtlasCognitionScoreCardService)->build();
        $score = $envelope['score'];
        $rows = $envelope['subsystems'];

        $this->assertArrayNotHasKey('volume', $score['dimensions']);

        // code stays the one already-real dimension: every class_exists -> 10.0.
        $this->assertSame(10.0, (float) $score['dimensions']['code']['score_out_of_10']);

        // doc + pipeline are RESOLVED, so they must be BELOW the old hardcoded 10/10.
        $this->assertLessThan(
            10.0,
            (float) $score['dimensions']['doc']['score_out_of_10'],
            'doc dimension must be < 10 now that it is resolved from real doc ownership, not a hardcoded ready.',
        );
        $this->assertLessThan(
            10.0,
            (float) $score['dimensions']['pipeline']['score_out_of_10'],
            'pipeline dimension must be < 10 now that it requires a real green-run receipt.',
        );
        // pipeline (green receipts: ~0 live) is the hardest-hit dimension — never above doc.
        $this->assertLessThanOrEqual(
            (float) $score['dimensions']['doc']['score_out_of_10'],
            (float) $score['dimensions']['pipeline']['score_out_of_10'],
        );

        // EQUALITY-TO-RESOLVED-COUNT companion (the anti-weakening guard): each reported
        // dimension must EXACTLY equal an independent recomputation from the resolved
        // per-row statuses + the same partial-credit weighting. A loosened "< 10" alone
        // could hide drift; pinning to the resolved count cannot.
        foreach (['code', 'doc', 'pipeline'] as $dim) {
            $this->assertSame(
                $this->expectedDimensionScore($rows, $dim.'_status'),
                (float) $score['dimensions'][$dim]['score_out_of_10'],
                "{$dim} dimension must equal the value resolved from the per-row statuses.",
            );
        }

        // overall is the mean of the three resolved dimensions, and < the old 10/10.
        $expectedOverall = round((
            (float) $score['dimensions']['code']['score_out_of_10']
            + (float) $score['dimensions']['doc']['score_out_of_10']
            + (float) $score['dimensions']['pipeline']['score_out_of_10']
        ) / 3, 2);
        $this->assertSame($expectedOverall, (float) $score['overall_out_of_10']);
        $this->assertLessThan(10.0, (float) $score['overall_out_of_10']);
    }

    /**
     * RESOLUTION-MOVES pin (anti-hardcode): the doc + pipeline dimensions are a FUNCTION
     * of the injected resolver, not the SUBSYSTEMS constant. Flip one input (a stub that
     * marks every row doc-owned + green) and the dimensions + overall MOVE to 10/10; a
     * hardcoded scorecard could never differ from the real-resolver build above.
     */
    public function test_doc_and_pipeline_dimensions_track_the_resolver(): void
    {
        $allReady = new class extends AtlasCognitionEvidenceResolver
        {
            public function __construct() {}

            public function resolveDocStatus(?string $serviceClass): string
            {
                return self::STATUS_READY;
            }

            public function resolvePipelineStatus(?string $serviceClass): string
            {
                return self::STATUS_READY;
            }
        };

        $real = (new AtlasCognitionScoreCardService)->build()['score'];
        $forced = (new AtlasCognitionScoreCardService($allReady))->build()['score'];

        // With the resolver forced all-ready, doc + pipeline reach 10.0 — proving the
        // scorecard reads them FROM the resolver. The real build differs -> resolved.
        $this->assertSame(10.0, (float) $forced['dimensions']['doc']['score_out_of_10']);
        $this->assertSame(10.0, (float) $forced['dimensions']['pipeline']['score_out_of_10']);
        $this->assertSame(10.0, (float) $forced['overall_out_of_10']);
        $this->assertNotSame(
            (float) $real['overall_out_of_10'],
            (float) $forced['overall_out_of_10'],
            'A hardcoded scorecard would yield the same overall for both resolvers — it must not.',
        );
    }

    /**
     * Independent recomputation of a dimension score from the resolved per-row statuses,
     * using the SAME partial-credit weighting the service uses (ready=10, partial=6,
     * building=3, blocked=0; out of count*10, scaled to /10). Keeps the assertion
     * self-checking against drift without hardcoding a magic number.
     *
     * @param  array<int,array<string,mixed>>  $rows
     */
    private function expectedDimensionScore(array $rows, string $statusKey): float
    {
        $points = ['ready' => 10, 'partial' => 6, 'building' => 3, 'blocked' => 0];
        $sum = 0;
        foreach ($rows as $row) {
            $sum += $points[$row[$statusKey]] ?? 0;
        }
        $max = count($rows) * 10;

        return $max > 0 ? round(($sum / $max) * 10, 2) : 0.0;
    }

    public function test_hash_is_deterministic_across_invocations(): void
    {
        $a = (new AtlasCognitionScoreCardService)->build()['scorecard_hash'];
        $b = (new AtlasCognitionScoreCardService)->build()['scorecard_hash'];
        $this->assertSame($a, $b);
    }

    public function test_claim_policy_locks_external_claims(): void
    {
        $cp = (new AtlasCognitionScoreCardService)->build()['claim_policy'];

        $this->assertFalse($cp['benchmark_claim_allowed']);
        $this->assertFalse($cp['rivals_claim_allowed']);
        $this->assertFalse($cp['superiority_claim_allowed']);
        $this->assertFalse($cp['external_rivals_certification_touched']);
        $this->assertTrue($cp['cognitive_immune_law_enforced']);
        $this->assertTrue($cp['must_keep_coverage_invariant']);
        $this->assertTrue($cp['provider_safe_only_enforced']);
    }

    public function test_canonical_groups_are_present(): void
    {
        $groups = [];
        foreach ((new AtlasCognitionScoreCardService)->build()['subsystems'] as $row) {
            $groups[$row['group']] = true;
        }
        $this->assertArrayHasKey('cognitive_immune', $groups);
        $this->assertArrayHasKey('memory_core', $groups);
        $this->assertArrayHasKey('aucri', $groups);
        $this->assertArrayHasKey('self_improvement', $groups);
    }

    public function test_cognitive_immune_check_contract_aligns_with_scorecard_gates(): void
    {
        $gateAcronyms = array_values(array_map(
            fn (array $row): string => $row['acronym'],
            array_filter(
                (new AtlasCognitionScoreCardService)->build()['subsystems'],
                fn (array $row): bool => $row['group'] === 'cognitive_immune'
                    && preg_match('/^G[0-8]$/', (string) $row['acronym']) === 1,
            ),
        ));
        sort($gateAcronyms);

        $this->assertSame(CognitiveImmuneCheckContract::GATE_IDS, $gateAcronyms);
    }

    public function test_acronyms_are_unique(): void
    {
        $acronyms = array_map(fn ($r) => $r['acronym'], (new AtlasCognitionScoreCardService)->build()['subsystems']);
        $this->assertSame(count($acronyms), count(array_unique($acronyms)));
    }
}
