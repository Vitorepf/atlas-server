<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Core\RivalsClaimAuthority;
use App\Services\Ai\Rivals\Core\RivalsCurriculumLadder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * P2g-CURR: claim authority curriculum gate (strong multiplier only).
 */
final class RivalsClaimAuthorityCurriculumTest extends TestCase
{
    public function test_legacy_world_10x_claim_without_curriculum_still_issues(): void
    {
        $claim = (new RivalsClaimAuthority)->issue($this->legacyEvidence());

        self::assertSame('issued', $claim['status']);
        self::assertSame('world_10x_quality_proven', $claim['level']);
    }

    public function test_multiplier_proven_without_curriculum_metadata_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('curriculum_metadata_required_for_multiplier_claim');

        $evidence = $this->legacyEvidence();
        $evidence['claim_level'] = 'multiplier_proven';
        $evidence['m_excellence'] = 50.0;
        $evidence['effect'] = 50.0;
        $evidence['ci_low'] = 40.0;
        $evidence['ci_high'] = 60.0;

        (new RivalsClaimAuthority)->issue($evidence);
    }

    public function test_anti_ceiling_argument_cannot_issue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('anti_ceiling_fallacy');

        $evidence = $this->legacyEvidence();
        $evidence['anti_ceiling_argument'] = true;

        (new RivalsClaimAuthority)->issue($evidence);
    }

    public function test_scope_curriculum_role_is_honored(): void
    {
        $evidence = $this->legacyEvidence();
        $evidence['claim_level'] = 'multiplier_proven';
        $evidence['effect'] = 50.0;
        $evidence['ci_low'] = 40.0;
        $evidence['ci_high'] = 60.0;
        $evidence['scope']['curriculum_role'] = RivalsCurriculumLadder::ROLE_FRONTIER;
        $evidence['scope']['frontier_saturated'] = false;

        $claim = (new RivalsClaimAuthority)->issue($evidence);
        self::assertSame('issued', $claim['status']);
    }

    /** @return array<string,mixed> */
    private function legacyEvidence(): array
    {
        return [
            'adjudication_status' => 'passed', 'claim_level' => 'world_10x_quality_proven',
            'run_state' => 'bundled', 'metric_weights' => ['quality_loss' => 1.0, 'cost' => 0.0],
            'scope' => ['suite' => 'atlas-bench', 'mode' => 'atlas', 'risk' => 'R3', 'duration' => 'durable_task', 'stack' => 'php_laravel', 'unit_population' => 'atlas_bench_cases', 'cases' => ['c1', 'c2', 'c3']],
            'baseline_hash' => str_repeat('a', 64), 'evidence_pack_hash' => str_repeat('b', 64),
            'experiment_hash' => str_repeat('c', 64), 'effect' => 0.20, 'ci_low' => 0.08, 'ci_high' => 0.32,
            'exposure' => ['campaigns' => 3, 'attempts' => 300, 'distinct_cases' => 3],
            'issued_at' => '2026-07-12T00:00:00Z', 'expires_at' => '2026-10-10T00:00:00Z',
            'invalidators' => ['frontier_change', 'late_adverse_outcome'],
            'evidence_refs' => ['rivals://evidence-pack/b', 'ledger://experiment/c'],
        ];
    }
}
