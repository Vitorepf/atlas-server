<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopSelfImprovementObjectiveBuilder;
use Tests\TestCase;

/**
 * Lever 4 — the self-improvement seam ("ADEP improves ADEP"). The loop may refactor its OWN over-complex
 * pipeline, but the contract is doubly safe: the petreous set is NEVER a target, self-edits need BOTH meta
 * flags ON, and an admitted self-edit is stamped with the ≥9 per-task quality bar the certifier enforces.
 */
final class AtlasLoopSelfImprovementObjectiveBuilderTest extends TestCase
{
    private function enableMeta(): void
    {
        config([
            'atlas.loop.meta_harness_targets' => true,
            'atlas.loop.meta_harness_self_improve.enabled' => true,
            'atlas.loop.quality_bar' => 9.0,
        ]);
    }

    public function test_refuses_a_petreous_self_target_even_with_meta_on(): void
    {
        $this->enableMeta();

        $out = (new AtlasLoopSelfImprovementObjectiveBuilder)->build(
            'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php',
            'tests/Unit/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudgeTest.php',
            'evaluate',
            24,
        );

        $this->assertFalse($out['admitted'], 'the defendant can never be sent to edit the judge');
        $this->assertSame('forbidden', $out['admission']);
        $this->assertArrayNotHasKey('payload', $out, 'nothing is built for a petreous target');
    }

    public function test_refuses_a_non_harness_target(): void
    {
        $this->enableMeta();

        $out = (new AtlasLoopSelfImprovementObjectiveBuilder)->build(
            'app/Http/Controllers/ExportController.php',
            'tests/Feature/Export/ExportTest.php',
            'handle',
            22,
        );

        $this->assertFalse($out['admitted'], 'an ordinary app file is not a self-edit; it uses the normal lane');
        $this->assertSame('not_harness', $out['admission']);
    }

    public function test_refuses_a_harness_target_when_meta_flags_off(): void
    {
        config(['atlas.loop.meta_harness_targets' => false]);

        $out = (new AtlasLoopSelfImprovementObjectiveBuilder)->build(
            'app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php',
            'tests/Feature/Loop/AtlasLoopWorkerPoolTest.php',
            'grind',
            22,
        );

        $this->assertFalse($out['admitted'], 'self-improvement is anti-runaway: both meta flags must be ON');
        $this->assertSame('harness_gated', $out['admission']);
    }

    public function test_admits_a_harness_target_and_stamps_the_9_bar_on_the_extract_class_contract(): void
    {
        $this->enableMeta();

        $out = (new AtlasLoopSelfImprovementObjectiveBuilder)->build(
            'app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php',
            'tests/Feature/Loop/AtlasLoopWorkerPoolTest.php',
            'grindDispatch',
            22,
            'hermes_cli',
        );

        $this->assertTrue($out['admitted']);
        $this->assertSame('admissible', $out['admission']);
        $this->assertSame(9.0, $out['quality_bar']);

        $payload = $out['payload'];
        $acceptance = $payload['acceptance'];
        // The ≥9 per-task bar the certifier enforces even when the global flag is OFF.
        $this->assertTrue($acceptance['quality_bar_gate']);
        $this->assertSame(9.0, $acceptance['quality_bar']);
        $this->assertTrue($payload['is_self_improvement']);
        // It rides the PROVEN extract-class refactor contract (behaviour-preserving + complexity-dropping
        // — exactly the axis the grader scores), and objective_kind is UNCHANGED so materializer/diff-earned
        // routing stays intact.
        $this->assertSame(AtlasEvolutionFrozenJudge::METRIC_MINIMIZE, $acceptance['metric_kind']);
        $this->assertTrue($acceptance['complexity_proof']);
        $this->assertSame('refactor_extract_class', $payload['objective_kind']);
        $this->assertContains('app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php', $payload['allowed_files']);
        $this->assertSame('hermes_cli', $payload['provider']);
        $this->assertStringContainsString('SUPPORT', strtoupper((string) $out['objective']), 'the extract-class objective text is present');
    }
}
