<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainLeverageBrief;
use Tests\TestCase;

/**
 * FROZEN proof of the leverage brief — the consolidated action hint over the assembled scope_signals
 * block. Proves the rule order (refusal surge > router > compounding > frontier > default), each rule's
 * evidence ties to its inputs, and the brief is pétreo.
 */
final class AtlasBrainLeverageBriefTest extends TestCase
{
    public function test_refusal_surge_beats_every_other_signal(): void
    {
        $brief = (new AtlasBrainLeverageBrief)->brief([
            'orphans' => ['App\\Foo'],
            'recommended_path' => 'comprehension-deepening',
            'compounding' => ['success_streak' => 9],
            'frontier_candidates' => [['title' => 'X', 'source' => 'github']],
            'metrics' => [['id' => 'recent_refusal_count', 'value' => 5]], // > REFUSAL_SURGE_THRESHOLD
        ]);

        self::assertSame(AtlasBrainLeverageBrief::HINT_ROTATE_PATH, $brief['action_hint']);
        self::assertStringContainsString('5', $brief['rationale']);
    }

    public function test_gate_regression_beats_every_other_signal_including_perseveration(): void
    {
        $priorBriefs = [
            ['kind' => 'note', 'reflection' => 'leverage_brief: rotate_path — x'],
            ['kind' => 'note', 'reflection' => 'leverage_brief: rotate_path — x'],
            ['kind' => 'note', 'reflection' => 'leverage_brief: rotate_path — x'],
        ];

        $brief = (new AtlasBrainLeverageBrief)->brief([
            'gate_health' => ['inspector_holes' => 1, 'seed_gate_holes' => 0],
            'recommended_path' => 'comprehension-deepening',
            'drafted_candidates' => [['task_packet_id' => 'x']],
            'compounding' => ['success_streak' => 9],
            'metrics' => [['id' => 'recent_refusal_count', 'value' => 99]],
        ], $priorBriefs);

        self::assertSame(AtlasBrainLeverageBrief::HINT_FIX_GATE_REGRESSION, $brief['action_hint']);
        self::assertStringContainsString('1 inspector hole', $brief['rationale']);
    }

    public function test_gate_health_zero_holes_does_not_trigger_regression_hint(): void
    {
        $brief = (new AtlasBrainLeverageBrief)->brief([
            'gate_health' => ['inspector_holes' => 0, 'seed_gate_holes' => 0],
            'recommended_path' => 'pattern-design',
            'metrics' => [['id' => 'recent_refusal_count', 'value' => 0]],
        ]);

        self::assertNotSame(AtlasBrainLeverageBrief::HINT_FIX_GATE_REGRESSION, $brief['action_hint']);
    }

    public function test_perseveration_streak_beats_every_other_signal(): void
    {
        $priorBriefs = [
            ['kind' => 'note', 'reflection' => 'leverage_brief: rotate_path — origination misfiring'],
            ['kind' => 'note', 'reflection' => 'leverage_brief: rotate_path — origination misfiring'],
            ['kind' => 'note', 'reflection' => 'leverage_brief: rotate_path — origination misfiring'],
        ];

        $brief = (new AtlasBrainLeverageBrief)->brief([
            // every other signal is present — perseveration STILL wins.
            'recommended_path' => 'comprehension-deepening',
            'drafted_candidates' => [['task_packet_id' => 'x']],
            'compounding' => ['success_streak' => 9],
            'metrics' => [['id' => 'recent_refusal_count', 'value' => 99]],
        ], $priorBriefs);

        self::assertSame(AtlasBrainLeverageBrief::HINT_ESCALATE_PERSEVERATION, $brief['action_hint']);
        self::assertStringContainsString('rotate_path', $brief['rationale']);
    }

    public function test_perseveration_streak_with_mixed_hints_does_not_fire(): void
    {
        $priorBriefs = [
            ['kind' => 'note', 'reflection' => 'leverage_brief: rotate_path — x'],
            ['kind' => 'note', 'reflection' => 'leverage_brief: harvest_frontier — x'],
            ['kind' => 'note', 'reflection' => 'leverage_brief: rotate_path — x'],
        ];

        $brief = (new AtlasBrainLeverageBrief)->brief([
            'recommended_path' => 'pattern-design',
            'metrics' => [['id' => 'recent_refusal_count', 'value' => 0]],
        ], $priorBriefs);

        self::assertNotSame(AtlasBrainLeverageBrief::HINT_ESCALATE_PERSEVERATION, $brief['action_hint']);
    }

    public function test_drafted_candidates_beat_router_recommendation(): void
    {
        $brief = (new AtlasBrainLeverageBrief)->brief([
            'recommended_path' => 'pattern-design',
            'drafted_candidates' => [['task_packet_id' => 'brain:drafter:abc']],
            'compounding' => ['success_streak' => 0],
            'metrics' => [['id' => 'recent_refusal_count', 'value' => 1]],
        ]);

        self::assertSame(AtlasBrainLeverageBrief::HINT_USE_DRAFTED_CANDIDATE, $brief['action_hint']);
        self::assertStringContainsString('1', $brief['rationale']);
    }

    public function test_router_recommendation_wins_when_no_refusal_surge(): void
    {
        $brief = (new AtlasBrainLeverageBrief)->brief([
            'orphans' => ['App\\Foo'],
            'recommended_path' => 'pattern-design',
            'compounding' => ['success_streak' => 0],
            'frontier_candidates' => [],
            'metrics' => [['id' => 'recent_refusal_count', 'value' => 1]],
        ]);

        self::assertSame(AtlasBrainLeverageBrief::HINT_USE_ROUTED_PATH, $brief['action_hint']);
        self::assertStringContainsString('pattern-design', $brief['rationale']);
    }

    public function test_compound_wins_when_streak_is_at_threshold_and_no_router_hint(): void
    {
        $brief = (new AtlasBrainLeverageBrief)->brief([
            'recommended_path' => null,
            'compounding' => ['success_streak' => 3],
            'metrics' => [['id' => 'recent_refusal_count', 'value' => 0]],
        ]);

        self::assertSame(AtlasBrainLeverageBrief::HINT_COMPOUND, $brief['action_hint']);
    }

    public function test_frontier_wins_when_no_router_or_compound(): void
    {
        $brief = (new AtlasBrainLeverageBrief)->brief([
            'recommended_path' => null,
            'compounding' => ['success_streak' => 0],
            'frontier_candidates' => [['title' => 'X', 'source' => 'github'], ['title' => 'Y', 'source' => 'arxiv']],
            'metrics' => [['id' => 'recent_refusal_count', 'value' => 0]],
        ]);

        self::assertSame(AtlasBrainLeverageBrief::HINT_HARVEST_FRONTIER, $brief['action_hint']);
        self::assertStringContainsString('2', $brief['rationale']);
    }

    public function test_default_originate_fresh_when_no_signal_dominates(): void
    {
        $brief = (new AtlasBrainLeverageBrief)->brief([
            'recommended_path' => null,
            'compounding' => null,
            'frontier_candidates' => [],
            'metrics' => [],
        ]);

        self::assertSame(AtlasBrainLeverageBrief::HINT_ORIGINATE_FRESH, $brief['action_hint']);
    }

    public function test_evidence_is_bounded_to_three_cues(): void
    {
        $brief = (new AtlasBrainLeverageBrief)->brief([
            'orphans' => ['A', 'B', 'C'],
            'clone_clusters' => ['c1', 'c2'],
            'doc_stated_gaps' => ['g1'],
            'frontier_candidates' => [['title' => 'X', 'source' => 'g']],
            'recommended_path' => 'pattern-design',
            'metrics' => [['id' => 'recent_refusal_count', 'value' => 0]],
        ]);

        self::assertLessThanOrEqual(3, count($brief['evidence']));
    }

    public function test_brief_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainLeverageBrief.php',
            true
        );

        self::assertSame('forbidden', $verdict);
    }
}
