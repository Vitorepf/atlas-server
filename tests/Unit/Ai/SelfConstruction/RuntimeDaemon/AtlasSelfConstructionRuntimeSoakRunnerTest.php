<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\RuntimeDaemon;

use App\Services\Ai\SelfConstruction\RuntimeDaemon\AtlasSelfConstructionRuntimeSoakRunner;
use App\Services\Ai\SelfConstruction\RuntimeDaemon\AtlasSelfConstructionRuntimeSoakScenarioBuilder;
use Tests\TestCase;

class AtlasSelfConstructionRuntimeSoakRunnerTest extends TestCase
{
    private function defaultScenario(): array
    {
        return (new AtlasSelfConstructionRuntimeSoakScenarioBuilder)->build(['max_ticks' => 10]);
    }

    public function test_default_dry_run_does_not_invoke_callback(): void
    {
        $called = 0;
        $verdict = (new AtlasSelfConstructionRuntimeSoakRunner)->run(
            $this->defaultScenario(),
            ['tick_callback' => function () use (&$called) { $called++; }],
        );

        self::assertTrue($verdict['dry_run']);
        self::assertSame(0, $called);
        self::assertSame(10, $verdict['tick_count']);
        self::assertNotEmpty($verdict['planned_outcomes']);
    }

    public function test_apply_invokes_callback_one_tick_at_a_time(): void
    {
        $received = [];
        $verdict = (new AtlasSelfConstructionRuntimeSoakRunner)->run(
            $this->defaultScenario(),
            [
                'apply' => true,
                'tick_callback' => function (array $tick) use (&$received): array {
                    $received[] = (int) $tick['index'];

                    return ['ok' => true];
                },
            ],
        );

        self::assertFalse($verdict['dry_run']);
        self::assertSame(10, count($received));
        self::assertSame(range(0, 9), $received);
        foreach ($verdict['tick_results'] as $row) {
            self::assertTrue($row['applied']);
        }
    }

    public function test_apply_isolates_callback_failure_without_aborting_other_ticks(): void
    {
        $called = 0;
        $verdict = (new AtlasSelfConstructionRuntimeSoakRunner)->run(
            $this->defaultScenario(),
            [
                'apply' => true,
                'tick_callback' => function (array $tick) use (&$called): array {
                    $called++;
                    if ((int) $tick['index'] === 3) {
                        throw new \RuntimeException('boom');
                    }

                    return ['ok' => true];
                },
            ],
        );

        self::assertSame(10, $called, 'all 10 ticks must run despite a tick-3 failure');
        self::assertSame(1, $verdict['failed_count']);
        self::assertFalse($verdict['passed']);
        self::assertSame('boom', $verdict['tick_results'][3]['error']);
    }

    public function test_dependency_violation_on_ordinary_tick_fails_soak(): void
    {
        $scenario = [
            'virtual_ticks' => [
                ['index' => 0, 'kind' => 'green_cycle', 'expected_outcome' => 'success', 'requires' => ['requires_operator' => true]],
                ['index' => 1, 'kind' => 'green_cycle', 'expected_outcome' => 'success'],
            ],
        ];
        $verdict = (new AtlasSelfConstructionRuntimeSoakRunner)->run($scenario);

        self::assertFalse($verdict['passed']);
        self::assertNotEmpty($verdict['dependency_violations']);
        $violations = array_column($verdict['dependency_violations'], 'violation');
        self::assertContains('requires_operator', $violations);
    }

    public function test_clean_scenario_passes_soak(): void
    {
        $verdict = (new AtlasSelfConstructionRuntimeSoakRunner)->run($this->defaultScenario());

        self::assertTrue($verdict['passed']);
        self::assertSame(0, $verdict['failed_count']);
        self::assertSame([], $verdict['dependency_violations']);
    }

    public function test_counts_sum_correctly(): void
    {
        $verdict = (new AtlasSelfConstructionRuntimeSoakRunner)->run($this->defaultScenario());

        $sum = $verdict['green_count'] + $verdict['recovered_count'] + $verdict['held_count'];
        self::assertSame($verdict['tick_count'], $sum, 'green+recovered+held must equal tick_count');
    }

    public function test_soak_run_hash_is_deterministic(): void
    {
        $runner = new AtlasSelfConstructionRuntimeSoakRunner();
        $a = $runner->run($this->defaultScenario());
        $b = $runner->run($this->defaultScenario());

        self::assertSame($a['soak_run_hash'], $b['soak_run_hash']);
        self::assertStringStartsWith('soak_run_', $a['soak_run_hash']);
    }
}
