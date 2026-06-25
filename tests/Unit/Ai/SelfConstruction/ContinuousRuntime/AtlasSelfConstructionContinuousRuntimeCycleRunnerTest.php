<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ContinuousRuntime;

use App\Services\Ai\SelfConstruction\ContinuousRuntime\AtlasSelfConstructionContinuousRuntimeCycleRunner;
use Tests\TestCase;

final class AtlasSelfConstructionContinuousRuntimeCycleRunnerTest extends TestCase
{
    private function inspector(array $verdict): object
    {
        return new class($verdict)
        {
            public function __construct(private array $v) {}

            public function inspect(): array
            {
                return $this->v;
            }
        };
    }

    private function replenisher(array $verdict): object
    {
        return new class($verdict)
        {
            public function __construct(private array $v) {}

            public function replenish(array $facts): array
            {
                return $this->v;
            }
        };
    }

    private function workerIntegration(array $verdict): object
    {
        return new class($verdict)
        {
            public function __construct(private array $v) {}

            public function integrate(array $packet): array
            {
                return $this->v;
            }
        };
    }

    private function verifier(array $verdict): object
    {
        return new class($verdict)
        {
            public function __construct(private array $v) {}

            public function verify(array $request): array
            {
                return $this->v;
            }
        };
    }

    private function mergeDecider(array $verdict): object
    {
        return new class($verdict)
        {
            public function __construct(private array $v) {}

            public function decide(array $verification): array
            {
                return $this->v;
            }
        };
    }

    private function learner(array $verdict): object
    {
        return new class($verdict)
        {
            public function __construct(private array $v) {}

            public function record(array $facts): array
            {
                return $this->v;
            }
        };
    }

    public function test_full_successful_cycle_runs_through_all_collaborators(): void
    {
        $runner = new AtlasSelfConstructionContinuousRuntimeCycleRunner(
            $this->inspector([
                'safety_stop' => false,
                'queue_health' => ['malformed_count' => 0, 'claimable_depth' => 5],
                'claimable_packet' => ['task_packet_id' => 'pkt-1', 'lease_id' => 'lease-1'],
            ]),
            $this->replenisher(['action' => 'wait']),
            $this->workerIntegration(['accepted' => true, 'request' => ['task_packet_id' => 'pkt-1'], 'blockers' => []]),
            $this->verifier(['verified' => true, 'reasons' => []]),
            $this->mergeDecider(['decision' => 'merge_approved']),
            $this->learner(['learning' => ['recorded' => true]]),
        );

        $verdict = $runner->run('cyc-1');

        $this->assertFalse($verdict['stopped']);
        $this->assertNull($verdict['stop_reason']);
        $this->assertSame('cyc-1', $verdict['cycle_id']);
        $this->assertSame('merge_approved', $verdict['merge']['decision']);
        $this->assertTrue($verdict['learning']['learning']['recorded']);
    }

    public function test_replenish_only_cycle_when_no_claimable_packet(): void
    {
        $runner = new AtlasSelfConstructionContinuousRuntimeCycleRunner(
            $this->inspector([
                'safety_stop' => false,
                'queue_health' => ['malformed_count' => 0, 'claimable_depth' => 0],
                'claimable_packet' => null,
            ]),
            $this->replenisher(['action' => 'top_up', 'target_new_packet_count' => 4]),
            $this->workerIntegration(['accepted' => false, 'request' => null, 'blockers' => ['unused']]),
            $this->verifier(['verified' => true]),
            $this->mergeDecider(['decision' => 'unused']),
            $this->learner(['learning' => ['unused' => true]]),
        );

        $verdict = $runner->run('cyc-2');

        $this->assertTrue($verdict['stopped']);
        $this->assertSame('no_claimable_task', $verdict['stop_reason']);
        $this->assertSame('top_up', $verdict['replenisher']['action']);
        $this->assertSame(4, $verdict['replenisher']['target_new_packet_count']);
    }

    public function test_repair_first_stop_when_malformed_packets_present(): void
    {
        $runner = new AtlasSelfConstructionContinuousRuntimeCycleRunner(
            $this->inspector([
                'safety_stop' => false,
                'queue_health' => ['malformed_count' => 2, 'claimable_depth' => 5],
                'claimable_packet' => ['task_packet_id' => 'pkt-1'],
            ]),
            $this->replenisher(['action' => 'repair_first']),
            $this->workerIntegration(['accepted' => true, 'request' => []]),
            $this->verifier(['verified' => true]),
            $this->mergeDecider(['decision' => 'unused']),
            $this->learner(['learning' => []]),
        );

        $verdict = $runner->run('cyc-3');

        $this->assertTrue($verdict['stopped']);
        $this->assertSame('repair_first', $verdict['stop_reason']);
        $this->assertSame('repair_first', $verdict['replenisher']['action']);
    }

    public function test_verification_failure_stops_cycle_with_exact_reason(): void
    {
        $runner = new AtlasSelfConstructionContinuousRuntimeCycleRunner(
            $this->inspector([
                'safety_stop' => false,
                'queue_health' => ['malformed_count' => 0, 'claimable_depth' => 5],
                'claimable_packet' => ['task_packet_id' => 'pkt-1'],
            ]),
            $this->replenisher(['action' => 'wait']),
            $this->workerIntegration(['accepted' => true, 'request' => ['task_packet_id' => 'pkt-1']]),
            $this->verifier(['verified' => false, 'reasons' => ['phpunit_red']]),
            $this->mergeDecider(['decision' => 'unused']),
            $this->learner(['learning' => []]),
        );

        $verdict = $runner->run('cyc-4');

        $this->assertTrue($verdict['stopped']);
        $this->assertSame('verification_failed', $verdict['stop_reason']);
        $this->assertSame(['phpunit_red'], $verdict['verification']['reasons']);
    }

    private function happyRunner(): AtlasSelfConstructionContinuousRuntimeCycleRunner
    {
        return new AtlasSelfConstructionContinuousRuntimeCycleRunner(
            $this->inspector([
                'safety_stop' => false,
                'queue_health' => ['malformed_count' => 0, 'claimable_depth' => 5],
                'claimable_packet' => ['task_packet_id' => 'pkt-1', 'lease_id' => 'lease-1'],
            ]),
            $this->replenisher(['action' => 'wait']),
            $this->workerIntegration(['accepted' => true, 'request' => [], 'blockers' => []]),
            $this->verifier(['verified' => true]),
            $this->mergeDecider(['decision' => 'merge_approved']),
            $this->learner(['learning' => []]),
        );
    }

    public function test_no_scope_expansion_when_no_facts_supplied_is_skipped(): void
    {
        $verdict = $this->happyRunner()->run('cyc-noexp');

        $this->assertSame('skipped', $verdict['scope_expansion']['status']);
        $this->assertSame('no_scope_expansion_facts_supplied', $verdict['scope_expansion']['reason']);
    }

    public function test_dry_run_governor_facts_are_recorded_with_hash(): void
    {
        $verdict = $this->happyRunner()->run('cyc-dry', [
            'facts' => [
                'candidates' => [[
                    'scope_id' => 'scope-z',
                    'evidence_refs' => ['doc:source.md'],
                    'atlas_native_owner' => true,
                    'requires_operator' => false,
                    'requires_human' => false,
                    'requires_external_provider' => false,
                    'proven_leverage_tier' => 3,
                    'autonomy_readiness_tier' => 2,
                    'risk' => 1,
                ]],
                'risk_budget' => ['max_risk' => 10],
                'readiness_facts' => [
                    'scope-z' => [
                        'current_scope_green' => false,
                    ],
                ],
                'lane_facts' => [],
            ],
        ]);

        $this->assertSame('ok', $verdict['scope_expansion']['status']);
        $this->assertTrue($verdict['scope_expansion']['dry_run']);
        $this->assertNotEmpty($verdict['scope_expansion']['governor_cycle_hash']);
    }

    public function test_governor_holds_when_candidate_requires_operator(): void
    {
        $verdict = $this->happyRunner()->run('cyc-op', [
            'facts' => [
                'candidates' => [[
                    'scope_id' => 'op-scope',
                    'evidence_refs' => ['doc:source.md'],
                    'atlas_native_owner' => true,
                    'requires_operator' => true,
                    'requires_human' => false,
                    'requires_external_provider' => false,
                    'proven_leverage_tier' => 2,
                    'autonomy_readiness_tier' => 2,
                    'risk' => 2,
                ]],
                'risk_budget' => ['max_risk' => 10],
            ],
        ]);

        $this->assertSame('hold', $verdict['scope_expansion']['status']);
        $this->assertContains('scope_expansion_requires_non_atlas_actor', $verdict['scope_expansion']['blockers']);
    }

    public function test_safety_stop_short_circuits_before_replenisher_or_worker(): void
    {
        $runner = new AtlasSelfConstructionContinuousRuntimeCycleRunner(
            $this->inspector([
                'safety_stop' => true,
                'safety_reasons' => ['master_switch_off'],
            ]),
            $this->replenisher(['action' => 'must_not_be_called']),
            $this->workerIntegration(['accepted' => true, 'request' => []]),
            $this->verifier(['verified' => true]),
            $this->mergeDecider(['decision' => 'unused']),
            $this->learner(['learning' => []]),
        );

        $verdict = $runner->run('cyc-5');

        $this->assertTrue($verdict['stopped']);
        $this->assertSame('safety_stop', $verdict['stop_reason']);
        $this->assertSame(['master_switch_off'], $verdict['safety_reasons']);
        $this->assertArrayNotHasKey('replenisher', $verdict);
        $this->assertArrayNotHasKey('worker_integration', $verdict);
    }
}
