<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainFrontierExhaustionEscalationLadder;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainFrontierExhaustionEscalationLadderTest extends TestCase
{
    public function test_declining_local_yield_with_quota_escalates_to_all_deeper_fronts(): void
    {
        $result = (new AtlasExternalBrainFrontierExhaustionEscalationLadder)->evaluate([
            'local_findings_per_wave' => 1,
            'quota_remaining' => 20,
        ]);

        self::assertFalse($result['exhausted']);
        self::assertContains('cross_file_invariant_scan', $result['next_fronts']);
        self::assertContains('design_path_mining', $result['next_fronts']);
        self::assertContains('simplification_candidate_search', $result['next_fronts']);
        self::assertContains('research_to_task_digest', $result['next_fronts']);
    }

    public function test_repeated_duplicate_findings_suppress_vein_and_escalate_with_duplicate_yield_reason(): void
    {
        $result = (new AtlasExternalBrainFrontierExhaustionEscalationLadder)->evaluate([
            'local_findings_per_wave' => 10,
            'quota_remaining' => 20,
            'duplicate_yield_ratio' => 0.8,
        ]);

        self::assertFalse($result['exhausted']);
        self::assertNotEmpty($result['next_fronts']);
        self::assertStringContainsString('duplicate_yield', $result['reasons'][0]);
    }

    public function test_all_fronts_attempted_with_evidence_returns_exhausted_with_terminal_evidence_requirement(): void
    {
        $result = (new AtlasExternalBrainFrontierExhaustionEscalationLadder)->evaluate([
            'local_findings_per_wave' => 0,
            'quota_remaining' => 5,
            'attempted_fronts_with_evidence' => [
                'local_grep_bug_hunt',
                'cross_file_invariant_scan',
                'design_path_mining',
                'simplification_candidate_search',
                'research_to_task_digest',
            ],
        ]);

        self::assertTrue($result['exhausted']);
        self::assertNotEmpty($result['evidence_required_for_terminal_stop']);
    }

    public function test_partial_attempted_fronts_does_not_exhaust(): void
    {
        $result = (new AtlasExternalBrainFrontierExhaustionEscalationLadder)->evaluate([
            'local_findings_per_wave' => 1,
            'quota_remaining' => 5,
            'attempted_fronts_with_evidence' => ['local_grep_bug_hunt'],
        ]);

        self::assertFalse($result['exhausted']);
    }

    // ── AC3: output includes next_front, suppressed_fronts, attempted_with_evidence, missing_evidence_fronts ──

    public function test_output_includes_next_front_and_evidence_tracking_fields(): void
    {
        $result = (new AtlasExternalBrainFrontierExhaustionEscalationLadder)->evaluate([
            'local_findings_per_wave' => 1,
            'quota_remaining' => 20,
            'attempted_fronts_with_evidence' => ['local_grep_bug_hunt'],
        ]);

        self::assertArrayHasKey('next_front', $result);
        self::assertArrayHasKey('suppressed_fronts', $result);
        self::assertArrayHasKey('attempted_with_evidence', $result);
        self::assertArrayHasKey('missing_evidence_fronts', $result);
        self::assertSame($result['next_fronts'][0] ?? null, $result['next_front']);
        self::assertSame(['local_grep_bug_hunt'], $result['attempted_with_evidence']);
    }

    public function test_duplicate_yield_suppresses_local_front_in_suppressed_fronts(): void
    {
        $result = (new AtlasExternalBrainFrontierExhaustionEscalationLadder)->evaluate([
            'local_findings_per_wave' => 10,
            'quota_remaining' => 20,
            'duplicate_yield_ratio' => 0.8,
        ]);

        self::assertContains('local_grep_bug_hunt', $result['suppressed_fronts']);
        self::assertNotContains('local_grep_bug_hunt', $result['next_fronts']);
    }

    public function test_exhausted_returns_null_next_front(): void
    {
        $result = (new AtlasExternalBrainFrontierExhaustionEscalationLadder)->evaluate([
            'local_findings_per_wave' => 0,
            'quota_remaining' => 5,
            'attempted_fronts_with_evidence' => [
                'local_grep_bug_hunt',
                'cross_file_invariant_scan',
                'design_path_mining',
                'simplification_candidate_search',
                'research_to_task_digest',
            ],
        ]);

        self::assertNull($result['next_front']);
        self::assertSame([], $result['suppressed_fronts']);
    }
}
