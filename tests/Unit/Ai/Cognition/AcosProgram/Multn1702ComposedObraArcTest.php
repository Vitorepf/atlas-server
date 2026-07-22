<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition\AcosProgram;

use App\Services\Ai\Cognition\AcosProgram\ComposedObraArcComposer;
use App\Services\Ai\Cognition\AcosProgram\ComposedObraArcLifecycle;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathPriorityRank;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathSignalAggregator;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathYieldMomentum;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Multn1702ComposedObraArcTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ComposedObraArcLifecycle::reset();
    }

    #[Test]
    public function three_neighbor_candidates_compose_one_serialized_arc(): void
    {
        $candidates = [
            $this->candidate('a', AtlasBrainPathPriorityRank::class, 'Wire priority rank', 8.0),
            $this->candidate('b', AtlasBrainPathSignalAggregator::class, 'Wire signal aggregator', 7.0),
            $this->candidate('c', AtlasBrainPathYieldMomentum::class, 'Wire yield momentum', 6.0),
        ];

        $out = ComposedObraArcComposer::compose($candidates, [], ['enabled' => true]);

        $this->assertTrue($out['composed']);
        $this->assertSame(1, $out['arc_count']);
        $arc = $out['arcs'][0];
        $this->assertNotEmpty($arc['arc_id']);
        $this->assertNotEmpty($arc['obra_id']);
        $this->assertNotEmpty($arc['thesis']['claim']);
        $this->assertNotEmpty($arc['thesis']['falsified_when']);
        $this->assertCount(3, $arc['tasks']);
        $this->assertSame(1, $arc['tasks'][0]['order']);
        $this->assertNotEmpty($arc['completion_criterion']['executable']);
        $this->assertSame(ComposedObraArcComposer::KILL_GATE_CONSECUTIVE_FAILURES, $arc['kill_gate']['consecutive_failures_k']);
    }

    #[Test]
    public function author_neq_judge_on_thesis_and_completion_criterion(): void
    {
        $arc = ComposedObraArcComposer::compose([
            $this->candidate('a', AtlasBrainPathPriorityRank::class, 'A', 1.0),
            $this->candidate('b', AtlasBrainPathSignalAggregator::class, 'B', 1.0),
            $this->candidate('c', AtlasBrainPathYieldMomentum::class, 'C', 1.0),
        ], [], ['enabled' => true])['arcs'][0];

        $this->assertNotSame($arc['thesis']['author_engine_id'], $arc['completion_criterion']['certifier_engine_id']);
        $this->assertTrue($arc['source']['author_neq_judge']);
    }

    #[Test]
    public function kill_gate_archives_arc_and_never_serves_remaining_tasks(): void
    {
        $arc = ComposedObraArcComposer::compose([
            $this->candidate('a', AtlasBrainPathPriorityRank::class, 'A', 1.0),
            $this->candidate('b', AtlasBrainPathSignalAggregator::class, 'B', 1.0),
            $this->candidate('c', AtlasBrainPathYieldMomentum::class, 'C', 1.0),
        ], [], ['enabled' => true])['arcs'][0];

        ComposedObraArcLifecycle::register($arc);
        $arcId = (string) $arc['arc_id'];
        $tasks = $arc['tasks'];

        ComposedObraArcLifecycle::recordTaskFailure($arcId, (string) $tasks[0]['task_id']);
        ComposedObraArcLifecycle::recordTaskFailure($arcId, (string) $tasks[1]['task_id']);
        $final = ComposedObraArcLifecycle::recordTaskFailure($arcId, (string) $tasks[2]['task_id']);

        $this->assertSame('archived', $final['status']);
        $this->assertNotNull($final['archive_receipt']);
        $this->assertFalse($final['remaining_servable']);
        $this->assertNull(ComposedObraArcLifecycle::nextServableTask($arcId));
    }

    #[Test]
    public function seed_gate_rejection_does_not_collapse_arc(): void
    {
        $arc = ComposedObraArcComposer::compose([
            $this->candidate('a', AtlasBrainPathPriorityRank::class, 'A', 1.0),
            $this->candidate('b', AtlasBrainPathSignalAggregator::class, 'B', 1.0),
            $this->candidate('c', AtlasBrainPathYieldMomentum::class, 'C', 1.0),
        ], [], ['enabled' => true])['arcs'][0];

        ComposedObraArcLifecycle::register($arc);
        $arcId = (string) $arc['arc_id'];
        $firstTaskId = (string) $arc['tasks'][0]['task_id'];

        $status = ComposedObraArcLifecycle::recordSeedGateRejection($arcId, $firstTaskId);

        $this->assertSame('active', $status['status']);
        $this->assertSame(0, $status['consecutive_failures']);
        $next = ComposedObraArcLifecycle::nextServableTask($arcId);
        $this->assertNotNull($next);
        $this->assertSame((string) $arc['tasks'][1]['task_id'], $next['task_id']);
    }

    #[Test]
    public function flag_off_yields_zero_arcs(): void
    {
        $out = ComposedObraArcComposer::compose([
            $this->candidate('a', AtlasBrainPathPriorityRank::class, 'A', 1.0),
            $this->candidate('b', AtlasBrainPathSignalAggregator::class, 'B', 1.0),
            $this->candidate('c', AtlasBrainPathYieldMomentum::class, 'C', 1.0),
        ], [], ['enabled' => false]);

        $this->assertFalse($out['composed']);
        $this->assertSame(0, $out['arc_count']);
        $this->assertSame([], $out['arcs']);
    }

    #[Test]
    public function each_arc_task_requires_individual_gates(): void
    {
        $arc = ComposedObraArcComposer::compose([
            $this->candidate('a', AtlasBrainPathPriorityRank::class, 'A', 1.0),
            $this->candidate('b', AtlasBrainPathSignalAggregator::class, 'B', 1.0),
            $this->candidate('c', AtlasBrainPathYieldMomentum::class, 'C', 1.0),
        ], [], ['enabled' => true])['arcs'][0];

        foreach ($arc['tasks'] as $task) {
            $this->assertTrue($task['individual_gate_required']);
            $this->assertTrue($task['architect_phase_gate']);
            $this->assertTrue($task['seed_gate']);
        }
        $this->assertFalse(ComposedObraArcComposer::compose([], [], ['enabled' => true])['source']['arc_buys_gate_wholesale']);
    }

    /**
     * @return array<string,mixed>
     */
    private function candidate(string $id, string $organClass, string $summary, float $leverage): array
    {
        $short = class_basename($organClass);

        return [
            'id' => $id,
            'kind' => 'orphan_wiring',
            'summary' => $summary,
            'target_path' => 'app/Services/Ai/AutonomousEvolution/Brain/'.$short.'.php',
            'target_fqcn' => $organClass,
            'leverage' => $leverage,
        ];
    }
}
