<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\AtlasLoopLeapDecompositionSeeder;
use PHPUnit\Framework\TestCase;

final class AtlasLoopLeapDecompositionSeederTest extends TestCase
{
    public function test_refuses_when_risk_verdict_rejects(): void
    {
        $result = (new AtlasLoopLeapDecompositionSeeder)->seed($this->leap(), [
            'record_type' => 'RiskVerdict',
            'leap_id' => 'ambition_leap:loop',
            'status' => 'reject',
            'reasons' => ['forbidden_scope'],
        ]);

        $this->assertSame([], $result['task_packets']);
        $this->assertSame('risk_verdict_reject', $result['refuse_reason']);
    }

    public function test_pass_verdict_seeds_wave_respecting_shape_prior_width(): void
    {
        $result = (new AtlasLoopLeapDecompositionSeeder)->seed($this->leap([
            'shape_history' => ['certified' => 12, 'total' => 12],
            'consumer_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopAmbitionLeapProposer.php',
            'seed_targets' => [
                'app/Services/Ai/AutonomousEvolution/AtlasLoopLeapRiskAuditor.php',
                'app/Services/Ai/AutonomousEvolution/AtlasLoopFrontierGapModel.php',
            ],
        ]), $this->passVerdict());

        $this->assertSame('ok', $result['shape_prior']['verdict']);
        $this->assertCount(3, $result['task_packets'], 'ok prior caps the first wave at three packets, not an unbounded sibling flood');
        $this->assertNull($result['refuse_reason']);
    }

    public function test_every_packet_has_provenance_allowed_scope_acceptance_and_evidence(): void
    {
        $result = (new AtlasLoopLeapDecompositionSeeder)->seed($this->leap(), $this->passVerdict());
        $packet = $result['task_packets'][0];

        $this->assertSame($this->leap()['leap_id'], $packet['provenance']['leap_id']);
        $this->assertGreaterThanOrEqual(2, count($packet['acceptance_criteria']));
        $this->assertNotEmpty($packet['required_evidence']);
        foreach ($packet['allowed_files'] as $allowedFile) {
            if (str_starts_with($allowedFile, 'app/Services/Ai/AutonomousEvolution/')) {
                $this->assertTrue(true);

                continue;
            }
            $this->assertStringStartsWith('tests/Unit/Ai/AutonomousEvolution/', $allowedFile);
        }
    }

    public function test_refuses_forbidden_target(): void
    {
        $result = (new AtlasLoopLeapDecompositionSeeder)->seed($this->leap([
            'target_path' => AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS[0],
        ]), $this->passVerdict());

        $this->assertSame([], $result['task_packets']);
        $this->assertSame('forbidden_or_non_loop_target', $result['refuse_reason']);
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
            'abstain_reason' => null,
        ], $overrides);
    }

    /** @return array<string,mixed> */
    private function passVerdict(): array
    {
        return [
            'record_type' => 'RiskVerdict',
            'leap_id' => 'ambition_leap:loop-origination',
            'status' => 'pass',
            'reasons' => [],
        ];
    }
}
