<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\V4;

use App\Services\Ai\AutonomousEvolution\V4\AtlasLoopV4SelfArchitectureSentinel;
use PHPUnit\Framework\TestCase;

final class AtlasLoopV4SelfArchitectureSentinelTest extends TestCase
{
    public function test_healthy_diverse_window_has_no_alerts(): void
    {
        $result = $this->sentinel()->audit([
            $this->proposal('add_seam', 'app/Services/Ai/AutonomousEvolution/V4/AtlasLoopV4MetaObjectiveOriginator.php'),
            $this->proposal('tighten_gate', 'app/Services/Ai/AutonomousEvolution/V4/AtlasLoopV4ObjectiveToWorkBridge.php'),
            $this->proposal('add_lane', 'app/Services/Ai/AutonomousEvolution/V4/AtlasLoopV4CapabilityDeltaAttribution.php'),
            $this->proposal('add_seam', 'app/Services/Ai/AutonomousEvolution/V4/AtlasLoopV4MetaObjectiveGate.php'),
            $this->proposal('tighten_gate', 'app/Services/Ai/AutonomousEvolution/V4/AtlasLoopV4SelfArchitectureProposer.php'),
        ], [
            'app/Services/Ai/AutonomousEvolution/Verifier/AtlasLoopVerifierKernel.php',
        ]);

        $this->assertTrue($result['healthy']);
        $this->assertSame([], $result['alerts']);
    }

    public function test_single_kind_dominance_raises_monoculture(): void
    {
        $result = $this->sentinel()->audit([
            $this->proposal('tighten_gate', 'app/Services/Ai/AutonomousEvolution/V4/A.php'),
            $this->proposal('tighten_gate', 'app/Services/Ai/AutonomousEvolution/V4/B.php'),
            $this->proposal('tighten_gate', 'app/Services/Ai/AutonomousEvolution/V4/C.php'),
            $this->proposal('tighten_gate', 'app/Services/Ai/AutonomousEvolution/V4/D.php'),
            $this->proposal('tighten_gate', 'app/Services/Ai/AutonomousEvolution/V4/E.php'),
        ], []);

        $this->assertFalse($result['healthy']);
        $this->assertSame('monoculture', $result['alerts'][0]['kind']);
        $this->assertSame('tighten_gate', $result['alerts'][0]['dominant_kind']);
    }

    public function test_forbidden_directory_prefix_raises_proximity_creep_for_target_path(): void
    {
        $target = 'app/Services/Ai/AutonomousEvolution/Verifier/NewProbe.php';
        $result = $this->sentinel()->audit([
            $this->proposal('add_seam', $target),
        ], [
            'app/Services/Ai/AutonomousEvolution/Verifier/AtlasLoopVerifierKernel.php',
        ]);

        $this->assertFalse($result['healthy']);
        $this->assertContains('proximity_creep', $this->alertKinds($result));
        $this->assertSame($target, $this->alertByKind($result, 'proximity_creep')['target_path']);
    }

    public function test_short_median_rationale_raises_rationale_decay(): void
    {
        $result = $this->sentinel()->audit([
            $this->proposal('add_seam', 'app/Services/Ai/AutonomousEvolution/V4/A.php', 'short'),
            $this->proposal('tighten_gate', 'app/Services/Ai/AutonomousEvolution/V4/B.php', 'also short'),
            $this->proposal('add_lane', 'app/Services/Ai/AutonomousEvolution/V4/C.php', 'tiny'),
        ], []);

        $this->assertFalse($result['healthy']);
        $this->assertContains('rationale_decay', $this->alertKinds($result));
    }

    public function test_fingerprint_is_stable_under_reordering(): void
    {
        $proposals = [
            $this->proposal('add_lane', 'app/Services/Ai/AutonomousEvolution/V4/C.php'),
            $this->proposal('add_seam', 'app/Services/Ai/AutonomousEvolution/V4/A.php'),
            $this->proposal('tighten_gate', 'app/Services/Ai/AutonomousEvolution/V4/B.php'),
        ];
        $forbidden = [
            'app/Services/Ai/AutonomousEvolution/Verifier/AtlasLoopVerifierKernel.php',
            'app/Services/Ai/AutonomousEvolution/Certifier/AtlasLoopCertifierKernel.php',
        ];

        $first = $this->sentinel()->audit($proposals, $forbidden);
        $second = $this->sentinel()->audit(array_reverse($proposals), array_reverse($forbidden));

        $this->assertSame($first['fingerprint'], $second['fingerprint']);
    }

    private function sentinel(): AtlasLoopV4SelfArchitectureSentinel
    {
        return new AtlasLoopV4SelfArchitectureSentinel;
    }

    /** @return array{kind:string,target_path:string,rationale:string} */
    private function proposal(string $kind, string $targetPath, ?string $rationale = null): array
    {
        return [
            'kind' => $kind,
            'target_path' => $targetPath,
            'rationale' => $rationale ?? $this->longRationale(),
        ];
    }

    private function longRationale(): string
    {
        return 'This proposal carries enough concrete reasoning to explain the architecture benefit, expected risk, and why the target should be changed now.';
    }

    /** @param array{alerts:list<array<string,string>>} $result */
    private function alertKinds(array $result): array
    {
        return array_column($result['alerts'], 'kind');
    }

    /**
     * @param  array{alerts:list<array<string,string>>}  $result
     * @return array<string,string>
     */
    private function alertByKind(array $result, string $kind): array
    {
        foreach ($result['alerts'] as $alert) {
            if (($alert['kind'] ?? null) === $kind) {
                return $alert;
            }
        }

        self::fail("Expected alert kind {$kind}.");
    }
}
