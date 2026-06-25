<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\V4;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\V4\AtlasLoopV4SelfArchitectureProposer;
use PHPUnit\Framework\TestCase;

final class AtlasLoopV4SelfArchitectureProposerTest extends TestCase
{
    // FASE 0 (24/06): V4/ inteiro virou pétreo (o réu não edita o próprio meta-objetivo/auto-arquitetura).
    // Um alvo de auto-arquitetura LEGÍTIMO é harness NÃO-juiz evoluível — ex.: o QueueRefiller. O proposer
    // recusar um alvo V4 agora é o comportamento CORRETO (coberto por test_refuses_real_forbidden_self_target).
    private const SAFE_TARGET = 'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopQueueRefiller.php';

    public function test_fail_closed_without_architect(): void
    {
        $result = (new AtlasLoopV4SelfArchitectureProposer)->propose($this->topology(), AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS);

        $this->assertFalse($result['proposed']);
        $this->assertSame('no_architect', $result['refuse_reason']);
    }

    public function test_refuses_kind_out_of_vocabulary(): void
    {
        $result = $this->proposer(['kind' => 'rewrite_brain'])->propose($this->topology(), AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS);

        $this->assertFalse($result['proposed']);
        $this->assertSame('kind_out_of_vocabulary', $result['refuse_reason']);
    }

    public function test_refuses_target_path_not_in_topology(): void
    {
        $result = $this->proposer(['target_path' => 'app/Services/Ai/AutonomousEvolution/V4/Missing.php'])
            ->propose($this->topology(), AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS);

        $this->assertFalse($result['proposed']);
        $this->assertSame('target_not_in_topology', $result['refuse_reason']);
    }

    public function test_refuses_real_forbidden_self_target(): void
    {
        $forbidden = AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS[0];
        $result = $this->proposer(['target_path' => $forbidden])
            ->propose([...$this->topology(), $forbidden], AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS);

        $this->assertFalse($result['proposed'], 'a real pétreo target never becomes a usable proposal');
        $this->assertSame('constitution_forbids_target', $result['refuse_reason']);
    }

    public function test_refuses_rationale_shorter_than_floor(): void
    {
        $result = $this->proposer(['rationale' => 'too short'])
            ->propose($this->topology(), AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS);

        $this->assertFalse($result['proposed']);
        $this->assertSame('rationale_too_short', $result['refuse_reason']);
    }

    public function test_happy_path_returns_proposal(): void
    {
        $result = $this->proposer()->propose($this->topology(), AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS);

        $this->assertTrue($result['proposed']);
        $this->assertSame('tighten_gate', $result['kind']);
        $this->assertSame(self::SAFE_TARGET, $result['target_path']);
        $this->assertSame($this->longRationale(), $result['rationale']);
        $this->assertNull($result['refuse_reason']);
    }

    private function proposer(array $overrides = []): AtlasLoopV4SelfArchitectureProposer
    {
        return new AtlasLoopV4SelfArchitectureProposer(fn (array $_topology, array $_forbidden): array => array_merge([
            'kind' => 'tighten_gate',
            'target_path' => self::SAFE_TARGET,
            'rationale' => $this->longRationale(),
        ], $overrides));
    }

    /** @return list<string> */
    private function topology(): array
    {
        return [
            self::SAFE_TARGET,
            'app/Services/Ai/AutonomousEvolution/V4/AtlasLoopV4ObjectiveToWorkBridge.php',
        ];
    }

    private function longRationale(): string
    {
        return 'Tighten the V4 bridge because architecture proposals need a deterministic handoff seam.';
    }
}
