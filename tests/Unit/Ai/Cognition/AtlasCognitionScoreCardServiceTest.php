<?php

namespace Tests\Unit\Ai\Cognition;

use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use Tests\TestCase;

/**
 * Atlas Cognition Operating System — scorecard service tests (v3).
 */
class AtlasCognitionScoreCardServiceTest extends TestCase
{
    public function test_canonical_subsystem_count_is_31(): void
    {
        $this->assertSame(31, AtlasCognitionScoreCardService::canonicalSubsystemCount());
    }

    public function test_build_returns_canonical_envelope(): void
    {
        $r = (new AtlasCognitionScoreCardService)->build();

        $this->assertSame('atlas.cognition.scorecard.v3', $r['schema_version']);
        $this->assertSame(31, $r['subsystem_count']);
        $this->assertCount(31, $r['subsystems']);
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

    public function test_overall_reaches_10_when_all_three_dimensions_ready(): void
    {
        $score = (new AtlasCognitionScoreCardService)->build()['score'];

        $this->assertSame(10.0, (float) $score['dimensions']['code']['score_out_of_10']);
        $this->assertSame(10.0, (float) $score['dimensions']['doc']['score_out_of_10']);
        $this->assertSame(10.0, (float) $score['dimensions']['pipeline']['score_out_of_10']);
        $this->assertSame(10.0, (float) $score['overall_out_of_10']);
        $this->assertArrayNotHasKey('volume', $score['dimensions']);
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

    public function test_acronyms_are_unique(): void
    {
        $acronyms = array_map(fn ($r) => $r['acronym'], (new AtlasCognitionScoreCardService)->build()['subsystems']);
        $this->assertSame(count($acronyms), count(array_unique($acronyms)));
    }
}
