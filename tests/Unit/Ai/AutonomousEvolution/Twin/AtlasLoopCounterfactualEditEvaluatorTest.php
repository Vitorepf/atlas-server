<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Twin;

use App\Services\Ai\AutonomousEvolution\Twin\AtlasLoopCounterfactualEditEvaluator;
use App\Services\Ai\AutonomousEvolution\Twin\AtlasLoopSimulableTwinOrchestrator;
use PHPUnit\Framework\TestCase;

final class AtlasLoopCounterfactualEditEvaluatorTest extends TestCase
{
    public function test_choose_best_ranks_no_breaks_first_then_net_behavior_delta_and_picks_winner(): void
    {
        $result = (new AtlasLoopCounterfactualEditEvaluator)->chooseBest([
            $this->candidate('breaks-high-delta', delta: 7, breaks: 1),
            $this->candidate('safe-low-delta', delta: 2, breaks: 0),
            $this->candidate('safe-high-delta', delta: 5, breaks: 0),
        ], $this->twin(), $this->applierFor());

        $this->assertSame(AtlasLoopCounterfactualEditEvaluator::SCHEMA_VERSION, $result['schema']);
        $this->assertSame('safe-high-delta', $result['winner_id']);
        $this->assertSame('winner_selected', $result['reason']);
        $this->assertSame([
            ['candidate_id' => 'safe-high-delta', 'net_behavior_delta' => 5, 'breaks' => 0],
            ['candidate_id' => 'safe-low-delta', 'net_behavior_delta' => 2, 'breaks' => 0],
            ['candidate_id' => 'breaks-high-delta', 'net_behavior_delta' => 7, 'breaks' => 1],
        ], $result['ranked']);
    }

    public function test_when_every_candidate_breaks_winner_is_null_with_honest_reason(): void
    {
        $result = (new AtlasLoopCounterfactualEditEvaluator)->chooseBest([
            $this->candidate('break-a', delta: 10, breaks: 2),
            $this->candidate('break-b', delta: 3, breaks: 1),
        ], $this->twin(), $this->applierFor());

        $this->assertNull($result['winner_id']);
        $this->assertSame('all_candidates_break', $result['reason']);
    }

    public function test_ranking_is_deterministic_for_fixed_simulate_outputs(): void
    {
        $candidates = [
            $this->candidate('b', delta: 4, breaks: 0),
            $this->candidate('a', delta: 4, breaks: 0),
            $this->candidate('c', delta: 1, breaks: 0),
        ];
        $evaluator = new AtlasLoopCounterfactualEditEvaluator;

        $this->assertSame(
            $evaluator->chooseBest($candidates, $this->twin(), $this->applierFor()),
            $evaluator->chooseBest(array_reverse($candidates), $this->twin(), $this->applierFor()),
        );
    }

    /** @return array<string,mixed> */
    private function candidate(string $id, int $delta, int $breaks): array
    {
        return [
            'candidate_id' => $id,
            'delta' => $delta,
            'breaks' => $breaks,
            'execution_id' => 'eval-'.$id,
            'mirror' => [
                'id' => 'mirror-'.$id,
                'execution_plan' => [
                    'allowed_files' => ['app/Services/Ai/AutonomousEvolution/Twin/Mirror/'.$id.'.php'],
                ],
            ],
        ];
    }

    private function twin(): AtlasLoopSimulableTwinOrchestrator
    {
        return new AtlasLoopSimulableTwinOrchestrator(
            new class
            {
                public function snapshot(string $executionId, array $executionPlan): array
                {
                    return ['schema' => 'atlas.loop.behavior_delta_snapshot.v1', 'symbols' => []];
                }
            },
            new class
            {
                public function execute(string $executionId, array $recordedPostExecutionShas = []): array
                {
                    return ['status' => 'restored', 'execution_id' => $executionId];
                }
            },
        );
    }

    private function applierFor(): callable
    {
        return static function (array $candidate): callable {
            return static function (array $mirror, array $candidateEdit) use ($candidate): array {
                $symbols = [];
                for ($i = 1; $i <= (int) $candidate['delta']; $i++) {
                    $symbols[] = [
                        'fqcn' => 'Demo\\Generated'.$i,
                        'public_api_signature_hash' => 'sig-'.$i,
                        'caller_fqcns' => [],
                    ];
                }

                return [
                    'after_snapshot' => [
                        'schema' => 'atlas.loop.behavior_delta_snapshot.v1',
                        'symbols' => $symbols,
                        'would_break' => array_fill(0, (int) $candidate['breaks'], ['consumer_fqcn' => 'Demo\\Break']),
                    ],
                ];
            };
        };
    }
}
