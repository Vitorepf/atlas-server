<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\RuntimeDaemon;

use App\Services\Ai\SelfConstruction\RuntimeDaemon\AtlasSelfConstructionRuntimeSoakScenarioBuilder;
use Tests\TestCase;

class AtlasSelfConstructionRuntimeSoakScenarioBuilderTest extends TestCase
{
    public function test_default_scenario_covers_all_required_cases(): void
    {
        $verdict = (new AtlasSelfConstructionRuntimeSoakScenarioBuilder)->build();

        $kinds = array_unique(array_column($verdict['virtual_ticks'], 'kind'));
        foreach ([
            'green_cycle',
            'empty_queue_replenish',
            'give_back_repair',
            'failed_gate_hold',
            'stale_heartbeat_recovery',
            'pause_resume',
            'safety_stop',
            'scope_expansion_hold',
        ] as $required) {
            self::assertContains($required, $kinds, "scenario must include {$required}");
        }
        self::assertSame(AtlasSelfConstructionRuntimeSoakScenarioBuilder::SCHEMA, $verdict['schema_version']);
    }

    public function test_scenario_is_bounded_by_max_ticks(): void
    {
        $verdict = (new AtlasSelfConstructionRuntimeSoakScenarioBuilder)->build(['max_ticks' => 3]);

        self::assertSame(3, $verdict['tick_count']);
        self::assertCount(3, $verdict['virtual_ticks']);
    }

    public function test_scenario_is_bounded_by_max_virtual_seconds(): void
    {
        $verdict = (new AtlasSelfConstructionRuntimeSoakScenarioBuilder)->build([
            'max_ticks' => 1000,
            'max_virtual_seconds' => 120,
            'tick_step_seconds' => 60,
        ]);

        self::assertSame(2, $verdict['tick_count']);
    }

    public function test_scenario_never_sleeps_or_waits_for_wall_clock(): void
    {
        $start = microtime(true);
        (new AtlasSelfConstructionRuntimeSoakScenarioBuilder)->build(['max_ticks' => 200]);
        $elapsedSeconds = microtime(true) - $start;

        self::assertLessThan(1.0, $elapsedSeconds, 'builder must not sleep or wait for wall clock');
    }

    public function test_each_tick_declares_expected_outcome_required_evidence_and_forbidden_flags(): void
    {
        $verdict = (new AtlasSelfConstructionRuntimeSoakScenarioBuilder)->build();

        foreach ($verdict['virtual_ticks'] as $tick) {
            self::assertNotEmpty($tick['expected_outcome']);
            self::assertNotEmpty($tick['required_evidence']);
            foreach (['requires_operator', 'requires_human', 'requires_external_provider'] as $flag) {
                self::assertContains($flag, $tick['forbidden_dependency_flags']);
            }
        }
    }

    public function test_scenario_includes_failure_and_recovery_cases(): void
    {
        $verdict = (new AtlasSelfConstructionRuntimeSoakScenarioBuilder)->build();
        $kinds = array_column($verdict['virtual_ticks'], 'kind');
        self::assertContains('give_back_repair', $kinds, 'must include failure case');
        self::assertContains('failed_gate_hold', $kinds, 'must include failure case');
        self::assertContains('stale_heartbeat_recovery', $kinds, 'must include recovery case');
        self::assertContains('replenisher_recovery', $kinds, 'must include recovery case');
    }

    public function test_scenario_hash_is_deterministic_for_identical_options(): void
    {
        $builder = new AtlasSelfConstructionRuntimeSoakScenarioBuilder();
        $a = $builder->build(['max_ticks' => 10]);
        $b = $builder->build(['max_ticks' => 10]);

        self::assertSame($a['scenario_hash'], $b['scenario_hash']);
        self::assertStringStartsWith('scenario_', $a['scenario_hash']);
    }

    public function test_malformed_scenario_is_included_with_give_back_outcome(): void
    {
        $verdict = (new AtlasSelfConstructionRuntimeSoakScenarioBuilder)->build();
        $byKind = array_column($verdict['virtual_ticks'], null, 'kind');

        self::assertArrayHasKey('malformed_packet', $byKind, 'malformed_packet scenario must be present');
        self::assertSame('give_back_malformed', $byKind['malformed_packet']['expected_outcome']);
        self::assertContains('malformed_packet_diagnostic', $byKind['malformed_packet']['required_evidence']);
    }

    public function test_stale_evidence_scenario_is_included_with_rejection_outcome(): void
    {
        $verdict = (new AtlasSelfConstructionRuntimeSoakScenarioBuilder)->build();
        $byKind = array_column($verdict['virtual_ticks'], null, 'kind');

        self::assertArrayHasKey('stale_evidence_rejection', $byKind, 'stale_evidence_rejection scenario must be present');
        self::assertSame('evidence_rejected_stale', $byKind['stale_evidence_rejection']['expected_outcome']);
        self::assertContains('stale_evidence_receipt', $byKind['stale_evidence_rejection']['required_evidence']);
    }

    public function test_idle_worker_scenario_is_included_with_recycled_outcome(): void
    {
        $verdict = (new AtlasSelfConstructionRuntimeSoakScenarioBuilder)->build();
        $byKind = array_column($verdict['virtual_ticks'], null, 'kind');

        self::assertArrayHasKey('idle_worker_timeout', $byKind, 'idle_worker_timeout scenario must be present');
        self::assertSame('worker_recycled', $byKind['idle_worker_timeout']['expected_outcome']);
        self::assertContains('idle_timeout_receipt', $byKind['idle_worker_timeout']['required_evidence']);
    }

    public function test_scenario_source_does_not_touch_fs_provider_process_git_or_scheduler(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/RuntimeDaemon/AtlasSelfConstructionRuntimeSoakScenarioBuilder.php'));
        foreach (['sleep(', 'usleep(', 'file_put_contents', 'fopen(', 'shell_exec', 'system(', 'proc_open', '`git ', 'Http::', 'curl_', 'DB::', 'Schedule::'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $src, "builder must not contain {$forbidden}");
        }
    }
}
