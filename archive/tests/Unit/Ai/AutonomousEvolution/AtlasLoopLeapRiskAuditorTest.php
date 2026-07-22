<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\AtlasLoopLeapRiskAuditor;
use PHPUnit\Framework\TestCase;

final class AtlasLoopLeapRiskAuditorTest extends TestCase
{
    public function test_rejects_forbidden_path(): void
    {
        $verdict = $this->audit($this->leap([
            'target_path' => AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS[0],
        ]));

        $this->assertSame('reject', $verdict['status']);
        $this->assertContains('forbidden_scope', $verdict['reasons']);
    }

    public function test_rejects_auditor_judge_closure(): void
    {
        $verdict = $this->audit($this->leap([
            'target_capability_delta' => 'Improve the judge and auditor closure so self-edits certify themselves',
        ]));

        $this->assertSame('reject', $verdict['status']);
        $this->assertContains('auditor_judge_closure', $verdict['reasons']);
    }

    public function test_rejects_dangling_evidence_refs(): void
    {
        $verdict = (new AtlasLoopLeapRiskAuditor)->audit([
            $this->leap(['grounded_evidence_refs' => ['missing:evidence']]),
        ], ['trend:bucket:1'])[0];

        $this->assertSame('reject', $verdict['status']);
        $this->assertContains('dangling_evidence_refs', $verdict['reasons']);
    }

    public function test_rejects_proxy_farm_under_anti_farm_floor(): void
    {
        $verdict = $this->audit($this->leap([
            'anti_farm_evidence' => ['wired_proof' => false, 'diff_earned' => false],
        ]));

        $this->assertSame('reject', $verdict['status']);
        $this->assertContains('proxy_farm:not_load_bearing:no_bite_proof', $verdict['reasons']);
    }

    public function test_clean_leap_passes_through(): void
    {
        $leap = $this->leap();
        $verdict = $this->audit($leap);

        $this->assertSame('RiskVerdict', $verdict['record_type']);
        $this->assertSame($leap['leap_id'], $verdict['leap_id']);
        $this->assertSame('pass', $verdict['status']);
        $this->assertSame([], $verdict['reasons']);
    }

    public function test_unknown_leap_shape_rejects_fail_closed(): void
    {
        $verdict = (new AtlasLoopLeapRiskAuditor)->audit([['leap_id' => 'ambition_leap:bad']])[0];

        $this->assertSame('reject', $verdict['status']);
        $this->assertSame(['unknown_leap_shape'], $verdict['reasons']);
    }

    /** @param array<string,mixed> $leap */
    private function audit(array $leap): array
    {
        return (new AtlasLoopLeapRiskAuditor)->audit([$leap], $leap['grounded_evidence_refs'])[0];
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function leap(array $overrides = []): array
    {
        return array_merge([
            'record_type' => 'AmbitionLeap',
            'leap_id' => 'ambition_leap:loop-origination',
            'gap_id' => 'frontier_gap:loop-origination',
            'hypothesis' => 'Turn loop origination starvation into grounded capability supply.',
            'target_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopFrontierGapModel.php',
            'target_capability_delta' => 'Capability multiplier: create evidence-grounded ambition supply.',
            'grounded_evidence_refs' => ['trend:bucket:1', 'pipeline:cycle:1', 'originator:orphan:1'],
            'anti_farm_evidence' => [
                'wired_proof' => true,
                'method_kills' => true,
                'production_caller' => true,
            ],
            'abstain_reason' => null,
        ], $overrides);
    }
}
