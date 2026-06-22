<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopComprehensionOriginator;
use App\Services\Ai\AutonomousEvolution\AtlasLoopOriginationPipeline;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use Tests\TestCase;

/**
 * §5.6 · LAYER 2 — "decide by ORIGINATING, then DESIGN". The originator proposes a grounded evolution; the
 * pipeline resolves its cited symbol to a real scope path and runs the architect gate, so the origination
 * carries a converged design contract or is suppressed. A hallucinated citation never originates; a citation
 * resolving to a pétreo cert organ is suppressed at the design gate.
 */
final class AtlasLoopOriginationPipelineTest extends TestCase
{
    private function model(bool $withForbidden = false): AtlasLoopScopeComprehensionModel
    {
        $inv = [
            ['rel_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopResearchOriginator.php', 'fqcn' => 'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopResearchOriginator', 'public_methods' => ['derive'], 'is_orphan' => true, 'is_forbidden' => false, 'clone_cluster_id' => null],
        ];
        $forbidden = [];
        if ($withForbidden) {
            $inv[] = ['rel_path' => 'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php', 'fqcn' => 'App\\Services\\Ai\\AutonomousEvolution\\AtlasEvolutionFrozenJudge', 'public_methods' => ['score'], 'is_orphan' => false, 'is_forbidden' => true, 'clone_cluster_id' => null];
            $forbidden = ['app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php'];
        }

        return new AtlasLoopScopeComprehensionModel(
            inventory: $inv, edges: [], orphans: ['App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopResearchOriginator'],
            cloneClusters: [], forbidden: $forbidden, docPurposes: [], docStatedGaps: [], snapshotId: 'snap',
        );
    }

    private function pipeline(callable $writer): AtlasLoopOriginationPipeline
    {
        return new AtlasLoopOriginationPipeline(new AtlasLoopComprehensionOriginator($writer));
    }

    public function test_originates_then_designs_a_real_evolution(): void
    {
        $writer = fn (string $p): array => ['objective' => 'Wire AtlasLoopResearchOriginator into the discovery feed.', 'cited_symbols' => ['AtlasLoopResearchOriginator']];
        $res = $this->pipeline($writer)->produce($this->model(), sys_get_temp_dir());

        $this->assertTrue($res['produced'], (string) ($res['reason'] ?? ''));
        $this->assertSame('app/Services/Ai/AutonomousEvolution/AtlasLoopResearchOriginator.php', $res['target_path'], 'the cited symbol resolved to its real scope path');
        $this->assertContains('red_to_green', array_column($res['obligations'], 'kind'), 'a feature origination carries its mandatory red→green proof');
    }

    public function test_greenfield_origination_abstains_and_asks_the_operator(): void
    {
        // §5 ABSTAIN-AND-ASK wired live: the cited target is an ORPHAN (no consumers ⇒ no precedent), so this
        // free origination is a greenfield frontier decision — the pipeline PARKS + ASKS, never auto-proceeds.
        $writer = fn (string $p): array => ['objective' => 'Wire AtlasLoopResearchOriginator into the discovery feed.', 'cited_symbols' => ['AtlasLoopResearchOriginator']];
        $res = $this->pipeline($writer)->produce($this->model(), sys_get_temp_dir());

        $this->assertTrue($res['produced']);
        $this->assertSame('abstain', $res['action'], 'a greenfield origination asks the operator, never guesses');
        $this->assertNotNull($res['operator_question']);
        $this->assertStringContainsString('ABSTAINED', (string) $res['operator_question']);
    }

    public function test_a_hallucinated_origination_never_produces_work(): void
    {
        $writer = fn (string $p): array => ['objective' => 'Wire AtlasLoopGhost.', 'cited_symbols' => ['AtlasLoopGhost']];
        $res = $this->pipeline($writer)->produce($this->model(), sys_get_temp_dir());
        $this->assertFalse($res['produced'], 'the inventory judge refuses the hallucinated origination upstream');
    }

    public function test_an_origination_targeting_a_cert_organ_is_suppressed_at_the_design_gate(): void
    {
        // the writer cites a REAL symbol (it grounds) — but it is a pétreo cert organ, so the architect gate
        // suppresses the design: the brain can never originate its way into editing its own judge.
        $writer = fn (string $p): array => ['objective' => 'Refactor AtlasEvolutionFrozenJudge.', 'cited_symbols' => ['AtlasEvolutionFrozenJudge']];
        $res = $this->pipeline($writer)->produce($this->model(withForbidden: true), sys_get_temp_dir());

        $this->assertFalse($res['produced']);
        $this->assertSame('forbidden_target_petreo', $res['reason']);
    }
}
