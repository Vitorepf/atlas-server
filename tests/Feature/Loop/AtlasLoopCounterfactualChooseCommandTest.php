<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Twin\AtlasLoopSimulableTwinOrchestrator;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the counterfactual edit evaluator is live at the operator surface and emits deterministic facts:
 * among candidates simulated through the twin (with an in-memory snapshotter so no real sandbox is touched),
 * a non-breaking candidate wins over a breaking one; when every candidate breaks there is no winner. A
 * missing --candidates is a usage error.
 */
final class AtlasLoopCounterfactualChooseCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // In-memory twin: a snapshotter that needs no real sandbox and a no-op rollback. The behavior
        // snapshot is constant, so the net delta is 0 for every candidate and ranking turns on `breaks`.
        $this->app->instance(AtlasLoopSimulableTwinOrchestrator::class, new AtlasLoopSimulableTwinOrchestrator(
            new class
            {
                /** @return array<string,mixed> */
                public function snapshot(string $executionId, array $executionPlan): array
                {
                    return ['behavior_snapshot' => ['symbols' => []]];
                }
            },
            new class
            {
                /** @param array<string,string> $postExecutionShas */
                public function execute(string $executionId, array $postExecutionShas): void
                {
                    // no-op rollback
                }
            },
        ));
    }

    public function test_requires_candidates(): void
    {
        $exit = Artisan::call('atlas:loop:counterfactual-choose', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_non_breaking_candidate_wins_over_breaking(): void
    {
        $decoded = $this->choose([
            ['candidate_id' => 'A', 'breaks' => 0, 'simulated_outcome' => []],
            ['candidate_id' => 'B', 'breaks' => 3, 'simulated_outcome' => []],
        ]);

        $this->assertSame('winner_selected', $decoded['reason']);
        $this->assertSame('A', $decoded['winner_id']);
        $this->assertCount(2, $decoded['ranked']);
    }

    public function test_all_breaking_candidates_have_no_winner(): void
    {
        $decoded = $this->choose([
            ['candidate_id' => 'X', 'breaks' => 1, 'simulated_outcome' => []],
            ['candidate_id' => 'Y', 'breaks' => 2, 'simulated_outcome' => []],
        ]);

        $this->assertNull($decoded['winner_id']);
        $this->assertSame('all_candidates_break', $decoded['reason']);
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return array<string,mixed>
     */
    private function choose(array $candidates): array
    {
        $exit = Artisan::call('atlas:loop:counterfactual-choose', [
            '--candidates' => json_encode($candidates),
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
