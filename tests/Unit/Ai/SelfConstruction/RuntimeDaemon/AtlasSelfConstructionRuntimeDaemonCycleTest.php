<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\RuntimeDaemon;

use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerRecoverableProductionRuntime;
use App\Services\Ai\SelfConstruction\RuntimeDaemon\AtlasSelfConstructionNativeActionExecutor;
use App\Services\Ai\SelfConstruction\RuntimeDaemon\AtlasSelfConstructionRuntimeDaemonCycle;
use Tests\TestCase;

final class AtlasSelfConstructionRuntimeDaemonCycleTest extends TestCase
{
    public function test_claim_only_cycle_resumes_and_renews_without_executing_productive_steps(): void
    {
        $runtime = new class implements AtlasNativeWorkerRecoverableProductionRuntime
        {
            public int $resumes = 0;

            public int $renews = 0;

            public int $claims = 0;

            public int $forbiddenEffects = 0;

            public function resume(string $clientId): ?array
            {
                $this->resumes++;

                return [
                    'task_packet_id' => 'task-recovered-1',
                    'lease_id' => 'lease-recovered-1',
                    'authority_nonce' => 'nonce-recovered-1',
                    'authority_hash' => str_repeat('a', 64),
                    'envelope_hash' => str_repeat('b', 64),
                    'recovered' => true,
                ];
            }

            public function renew(string $clientId, string $taskPacketId, string $leaseId): bool
            {
                $this->renews++;

                return $taskPacketId === 'task-recovered-1' && $leaseId === 'lease-recovered-1';
            }

            public function claim(string $clientId): ?array
            {
                $this->claims++;

                return null;
            }

            public function report(string $clientId, array $outcome): array
            {
                $this->forbiddenEffects++;

                return [];
            }

            public function materialize(array $patchPlan): array
            {
                $this->forbiddenEffects++;

                return [];
            }
        };

        $claim = (new AtlasSelfConstructionRuntimeDaemonCycle(nativeWorker: $runtime))->claim([
            'intent' => 'resume the canonical claim lease',
        ]);

        self::assertSame('claimed', $claim['status']);
        self::assertTrue($claim['recovered']);
        self::assertSame(1, $runtime->resumes);
        self::assertSame(1, $runtime->renews);
        self::assertSame(0, $runtime->claims);
        self::assertSame(0, $runtime->forbiddenEffects);
        self::assertSame('task-recovered-1', $claim['task_packet_id']);
        self::assertSame('lease-recovered-1', $claim['lease_id']);
        self::assertSame('nonce-recovered-1', $claim['authority_nonce']);
        self::assertSame(str_repeat('a', 64), $claim['authority_hash']);
        self::assertSame(str_repeat('b', 64), $claim['envelope_hash']);
        self::assertSame(str_repeat('b', 64), $claim['native_envelope_ref']);
        self::assertNull($claim['native_journey_ref']);
        self::assertSame([], $claim['native_cycle_refs']);
        self::assertSame(['task-recovered-1'], $claim['native_task_refs']);
        self::assertSame(['lease-recovered-1'], $claim['native_lease_refs']);
        self::assertArrayNotHasKey('cycle_receipt_hash', $claim);
        self::assertArrayNotHasKey('daemon_cycle_hash', $claim);
        self::assertSame(0, $claim['provider_calls']);
        self::assertFalse($claim['worker_executed']);
        self::assertFalse($claim['mutation_performed']);
    }

    public function test_claim_only_cycle_falls_back_to_claim_when_recoverable_runtime_has_no_active_lease(): void
    {
        $runtime = new class implements AtlasNativeWorkerRecoverableProductionRuntime
        {
            public int $resumes = 0;

            public int $renews = 0;

            public int $claims = 0;

            public function resume(string $clientId): ?array
            {
                $this->resumes++;

                return $this->resumes === 1 ? null : [
                    'task_packet_id' => 'task-new-1',
                    'lease_id' => 'lease-new-1',
                    'authority_nonce' => 'nonce-fresh-1',
                    'authority_hash' => str_repeat('a', 64),
                    'envelope_hash' => str_repeat('b', 64),
                ];
            }

            public function renew(string $clientId, string $taskPacketId, string $leaseId): bool
            {
                $this->renews++;

                return false;
            }

            public function claim(string $clientId): ?array
            {
                $this->claims++;

                return [
                    'status' => 'served',
                    'task' => [
                        'task_packet_id' => 'task-new-1',
                        'lease_id' => 'lease-new-1',
                    ],
                ];
            }

            public function report(string $clientId, array $outcome): array
            {
                throw new \LogicException('report_must_not_run');
            }

            public function materialize(array $patchPlan): array
            {
                throw new \LogicException('materialize_must_not_run');
            }
        };

        $claim = (new AtlasSelfConstructionRuntimeDaemonCycle(nativeWorker: $runtime))->claim();

        self::assertSame('claimed', $claim['status']);
        self::assertFalse($claim['recovered']);
        self::assertSame(2, $runtime->resumes);
        self::assertSame(0, $runtime->renews);
        self::assertSame(1, $runtime->claims);
        self::assertSame('task-new-1', $claim['task_packet_id']);
        self::assertSame('lease-new-1', $claim['lease_id']);
        self::assertSame('nonce-fresh-1', $claim['authority_nonce']);
        self::assertSame(str_repeat('a', 64), $claim['authority_hash']);
        self::assertSame(str_repeat('b', 64), $claim['envelope_hash']);
        self::assertSame(str_repeat('b', 64), $claim['native_envelope_ref']);
        self::assertSame(['task-new-1'], $claim['native_task_refs']);
        self::assertSame(['lease-new-1'], $claim['native_lease_refs']);
    }

    public function test_fresh_claim_refuses_when_authoritative_resume_is_missing_provenance(): void
    {
        $runtime = new class implements AtlasNativeWorkerRecoverableProductionRuntime
        {
            public int $resumes = 0;

            public int $renews = 0;

            public function resume(string $clientId): ?array
            {
                $this->resumes++;

                return $this->resumes === 1 ? null : [
                    'task_packet_id' => 'task-fresh-missing-proof',
                    'lease_id' => 'lease-fresh-missing-proof',
                ];
            }

            public function renew(string $clientId, string $taskPacketId, string $leaseId): bool
            {
                $this->renews++;

                return true;
            }

            public function claim(string $clientId): ?array
            {
                return [
                    'status' => 'served',
                    'task' => [
                        'task_packet_id' => 'task-fresh-missing-proof',
                        'lease_id' => 'lease-fresh-missing-proof',
                    ],
                ];
            }

            public function report(string $clientId, array $outcome): array
            {
                throw new \LogicException('report_must_not_run');
            }

            public function materialize(array $patchPlan): array
            {
                throw new \LogicException('materialize_must_not_run');
            }
        };

        $claim = (new AtlasSelfConstructionRuntimeDaemonCycle(nativeWorker: $runtime))->claim();

        self::assertSame('native_claim_provenance_missing', $claim['status']);
        self::assertSame('missing_native_claim_authority_nonce', $claim['reason']);
        self::assertSame(2, $runtime->resumes);
        self::assertSame(0, $runtime->renews);
        self::assertFalse($claim['worker_executed']);
        self::assertSame(0, $claim['provider_calls']);
        self::assertFalse($claim['mutation_performed']);
    }

    public function test_fresh_claim_refuses_when_authoritative_resume_is_revoked_or_mismatched(): void
    {
        foreach ([
            [
                [
                    'task_packet_id' => 'task-fresh-revoked',
                    'lease_id' => 'lease-fresh-revoked',
                    'authority_nonce' => 'nonce-fresh-revoked',
                    'authority_hash' => str_repeat('a', 64),
                    'envelope_hash' => str_repeat('b', 64),
                    'authority_revoked' => true,
                ],
                'native_claim_authority_revoked',
                'native_authority_revoked',
            ],
            [
                [
                    'task_packet_id' => 'task-fresh-mismatch-other',
                    'lease_id' => 'lease-fresh-mismatch-other',
                    'authority_nonce' => 'nonce-fresh-mismatch',
                    'authority_hash' => str_repeat('a', 64),
                    'envelope_hash' => str_repeat('b', 64),
                ],
                'native_claim_provenance_mismatch',
                'fresh_claim_resume_mismatch',
            ],
        ] as [$resumed, $status, $reason]) {
            $runtime = new class($resumed) implements AtlasNativeWorkerRecoverableProductionRuntime
            {
                public int $resumes = 0;

                public int $renews = 0;

                /** @param array<string,mixed> $resumed */
                public function __construct(private readonly array $resumed) {}

                public function resume(string $clientId): ?array
                {
                    $this->resumes++;

                    return $this->resumes === 1 ? null : $this->resumed;
                }

                public function renew(string $clientId, string $taskPacketId, string $leaseId): bool
                {
                    $this->renews++;

                    return true;
                }

                public function claim(string $clientId): ?array
                {
                    return [
                        'status' => 'served',
                        'task' => [
                            'task_packet_id' => 'task-fresh-revoked',
                            'lease_id' => 'lease-fresh-revoked',
                        ],
                    ];
                }

                public function report(string $clientId, array $outcome): array
                {
                    throw new \LogicException('report_must_not_run');
                }

                public function materialize(array $patchPlan): array
                {
                    throw new \LogicException('materialize_must_not_run');
                }
            };

            $claim = (new AtlasSelfConstructionRuntimeDaemonCycle(nativeWorker: $runtime))->claim();

            self::assertSame($status, $claim['status']);
            self::assertSame($reason, $claim['reason']);
            self::assertSame(2, $runtime->resumes);
            self::assertSame(0, $runtime->renews);
            self::assertFalse($claim['worker_executed']);
            self::assertSame(0, $claim['provider_calls']);
            self::assertFalse($claim['mutation_performed']);
        }
    }

    public function test_existing_resume_refuses_missing_or_revoked_provenance_before_renewal(): void
    {
        foreach ([
            [
                [
                    'task_packet_id' => 'task-existing-missing',
                    'lease_id' => 'lease-existing-missing',
                ],
                'native_claim_provenance_missing',
                'missing_native_claim_authority_nonce',
            ],
            [
                [
                    'task_packet_id' => 'task-existing-revoked',
                    'lease_id' => 'lease-existing-revoked',
                    'authority_nonce' => 'nonce-existing-revoked',
                    'authority_hash' => str_repeat('a', 64),
                    'envelope_hash' => str_repeat('b', 64),
                    'authority_revoked' => true,
                ],
                'native_claim_authority_revoked',
                'native_authority_revoked',
            ],
        ] as [$resumed, $status, $reason]) {
            $runtime = new class($resumed) implements AtlasNativeWorkerRecoverableProductionRuntime
            {
                public int $renews = 0;

                public int $claims = 0;

                /** @param array<string,mixed> $resumed */
                public function __construct(private readonly array $resumed) {}

                public function resume(string $clientId): ?array
                {
                    return $this->resumed;
                }

                public function renew(string $clientId, string $taskPacketId, string $leaseId): bool
                {
                    $this->renews++;

                    return true;
                }

                public function claim(string $clientId): ?array
                {
                    $this->claims++;

                    return null;
                }

                public function report(string $clientId, array $outcome): array
                {
                    throw new \LogicException('report_must_not_run');
                }

                public function materialize(array $patchPlan): array
                {
                    throw new \LogicException('materialize_must_not_run');
                }
            };

            $claim = (new AtlasSelfConstructionRuntimeDaemonCycle(nativeWorker: $runtime))->claim();

            self::assertSame($status, $claim['status']);
            self::assertSame($reason, $claim['reason']);
            self::assertSame(0, $runtime->renews);
            self::assertSame(0, $runtime->claims);
            self::assertFalse($claim['worker_executed']);
            self::assertSame(0, $claim['provider_calls']);
            self::assertFalse($claim['mutation_performed']);
        }
    }

    public function test_dry_claim_withholds_without_resume_renew_or_claim(): void
    {
        $runtime = new class implements AtlasNativeWorkerRecoverableProductionRuntime
        {
            public int $resumes = 0;

            public int $renews = 0;

            public int $claims = 0;

            public function resume(string $clientId): ?array
            {
                $this->resumes++;

                return ['task_packet_id' => 'must-not-resume', 'lease_id' => 'must-not-renew'];
            }

            public function renew(string $clientId, string $taskPacketId, string $leaseId): bool
            {
                $this->renews++;

                return true;
            }

            public function claim(string $clientId): ?array
            {
                $this->claims++;

                return null;
            }

            public function report(string $clientId, array $outcome): array
            {
                throw new \LogicException('report_must_not_run');
            }

            public function materialize(array $patchPlan): array
            {
                throw new \LogicException('materialize_must_not_run');
            }
        };

        $claim = (new AtlasSelfConstructionRuntimeDaemonCycle(nativeWorker: $runtime))->claim([], true);

        self::assertSame('planned', $claim['status']);
        self::assertTrue($claim['dry_run']);
        self::assertSame('dry_run_claim_withheld', $claim['reason']);
        self::assertSame(0, $runtime->resumes);
        self::assertSame(0, $runtime->renews);
        self::assertSame(0, $runtime->claims);
        self::assertNull($claim['native_journey_ref']);
        self::assertSame([], $claim['native_cycle_refs']);
        self::assertSame([], $claim['native_task_refs']);
        self::assertSame([], $claim['native_lease_refs']);
        self::assertSame(0, $claim['provider_calls']);
        self::assertFalse($claim['mutation_performed']);
    }

    public function test_native_executor_cannot_resolve_before_independent_canary_receipt(): void
    {
        $executor = new class extends AtlasSelfConstructionNativeActionExecutor
        {
            public function execute(array $action, array $state): array
            {
                return ['status' => 'resolved', 'verification_receipt' => 'self-certified'];
            }
        };

        $out = (new AtlasSelfConstructionRuntimeDaemonCycle(actionExecutor: $executor))
            ->tick($this->readyFacts(), ['apply' => true]);

        $this->assertSame([], $out['applied_actions']);
        $this->assertSame('independent_verification_receipt_missing', $out['blocked_actions'][0]['error']);
        $this->assertTrue($out['action_feedback'][0]['retryable']);
    }

    public function test_provider_timeout_is_held_and_never_resolved(): void
    {
        $executor = new class extends AtlasSelfConstructionNativeActionExecutor
        {
            public function execute(array $action, array $state): array
            {
                throw new \RuntimeException('provider_timeout');
            }
        };

        $out = (new AtlasSelfConstructionRuntimeDaemonCycle(actionExecutor: $executor))
            ->tick($this->readyFacts(), ['apply' => true]);

        $this->assertSame([], $out['applied_actions']);
        $this->assertSame('provider_timeout', $out['blocked_actions'][0]['error']);
        $this->assertTrue($out['action_feedback'][0]['retryable']);
    }

    public function test_quality_foundry_24_7_fixture_covers_dry_rotation_outage_restart_authority_and_direct_commit(): void
    {
        $cycle = new AtlasSelfConstructionRuntimeDaemonCycle;

        $dry = $cycle->tick($this->readyFacts(), []);
        self::assertTrue($dry['dry_run']);
        self::assertSame([], $dry['applied_actions']);

        $outage = $cycle->tick($this->readyFacts(['planned_actions' => [
            ['kind' => 'native_tick', 'idempotency_key' => 'outage-1'],
        ]]), [
            'apply' => true,
            'action_callbacks' => ['native_tick' => static function (): array {
                throw new \RuntimeException('provider_outage');
            }],
        ]);
        self::assertSame('provider_outage', $outage['blocked_actions'][0]['error']);
        self::assertTrue($outage['action_feedback'][0]['retryable']);

        $restart = $cycle->tick($this->readyFacts([
            'completed_action_keys' => ['authority-1'],
            'planned_actions' => [['kind' => 'native_tick', 'idempotency_key' => 'authority-1']],
        ]), ['apply' => true, 'action_callbacks' => ['native_tick' => static fn (): array => ['ok' => true]]]);
        self::assertSame('idempotency_replay', $restart['withheld_actions'][0]['reason']);

        $directCommit = $cycle->tick($this->readyFacts([
            'planned_actions' => [['kind' => 'git', 'idempotency_key' => 'direct-commit-1']],
        ]), ['apply' => true, 'action_callbacks' => ['git' => static fn (): array => ['ok' => true]]]);
        self::assertSame('refused_action_kind:git', $directCommit['withheld_actions'][0]['reason']);

        $zeroHuman = $cycle->tick($this->readyFacts([
            'planned_actions' => [
                ['kind' => 'human_action'], ['kind' => 'external_provider_call'],
            ],
        ]), ['apply' => true, 'action_callbacks' => [
            'human_action' => static fn (): array => ['ok' => true],
            'external_provider_call' => static fn (): array => ['ok' => true],
        ]]);
        self::assertSame([], $zeroHuman['applied_actions']);
        self::assertCount(2, $zeroHuman['withheld_actions']);

        for ($round = 1; $round <= 3; $round++) {
            $continuous = $cycle->tick($this->readyFacts([
                'planned_actions' => [['kind' => 'native_tick', 'idempotency_key' => 'continuous-'.$round]],
            ]), ['apply' => true, 'action_callbacks' => ['native_tick' => static fn (): array => ['ok' => true]]]);
            self::assertCount(1, $continuous['applied_actions']);
            self::assertSame([], $continuous['blocked_actions']);
        }
    }

    public function test_executor_held_result_remains_retryable_not_applied_success(): void
    {
        $executor = new class extends AtlasSelfConstructionNativeActionExecutor
        {
            public function execute(array $action, array $state): array
            {
                return ['status' => 'held', 'reason' => 'release_pending', 'retryable' => true];
            }
        };

        $out = (new AtlasSelfConstructionRuntimeDaemonCycle(actionExecutor: $executor))
            ->tick($this->readyFacts(), ['apply' => true]);

        self::assertSame('held', $out['action_feedback'][0]['outcome_class']);
        self::assertTrue($out['action_feedback'][0]['retryable']);
        self::assertSame('retry_native_action:native_tick', $out['action_feedback'][0]['next_safe_action']);
    }

    private function readyState(): array
    {
        return [
            'status' => 'planned',
            'safety_stop' => false,
            'pause_requested' => false,
            'stop_requested' => false,
        ];
    }

    private function readyFacts(array $overrides = []): array
    {
        return array_replace([
            'daemon_state' => $this->readyState(),
            'heartbeat_event' => ['type' => 'heartbeat', 'now_at' => '2026-06-25T05:30:00+00:00'],
            'planned_actions' => [['kind' => 'native_tick']],
        ], $overrides);
    }

    public function test_dry_run_does_not_invoke_callback_and_emits_planned_envelope(): void
    {
        $called = 0;
        $cycle = new AtlasSelfConstructionRuntimeDaemonCycle;
        $out = $cycle->tick($this->readyFacts(), [
            'action_callbacks' => ['native_tick' => function () use (&$called) {
                $called++;
            }],
        ]);

        $this->assertSame(AtlasSelfConstructionRuntimeDaemonCycle::SCHEMA, $out['schema_version']);
        $this->assertTrue($out['dry_run']);
        $this->assertSame(0, $called);
        $this->assertSame([], $out['applied_actions']);
        $this->assertNotEmpty($out['daemon_cycle_hash']);
    }

    public function test_apply_mode_runs_injected_callback_and_isolates_failures(): void
    {
        $cycle = new AtlasSelfConstructionRuntimeDaemonCycle;
        $out = $cycle->tick($this->readyFacts(['planned_actions' => [
            ['kind' => 'native_tick'],
            ['kind' => 'native_audit'],
        ]]), [
            'apply' => true,
            'action_callbacks' => [
                'native_tick' => static fn (array $a, array $s): array => ['ok' => true],
                'native_audit' => static function (): array {
                    throw new \RuntimeException('audit boom');
                },
            ],
        ]);

        $this->assertCount(1, $out['applied_actions']);
        $this->assertSame('native_tick', $out['applied_actions'][0]['kind']);
        $this->assertCount(1, $out['blocked_actions']);
        $this->assertSame('audit boom', $out['blocked_actions'][0]['error']);
    }

    public function test_duplicate_idempotent_actions_execute_once_and_replay_is_withheld_after_restart(): void
    {
        $calls = 0;
        $cycle = new AtlasSelfConstructionRuntimeDaemonCycle;
        $facts = $this->readyFacts(['planned_actions' => [
            ['kind' => 'native_tick', 'idempotency_key' => 'action-1'],
            ['kind' => 'native_tick', 'idempotency_key' => 'action-1'],
        ]]);
        $callback = static function () use (&$calls): array {
            $calls++;

            return ['ok' => true];
        };

        $first = $cycle->tick($facts, ['apply' => true, 'action_callbacks' => ['native_tick' => $callback]]);
        self::assertSame(1, $calls);
        self::assertCount(1, $first['applied_actions']);
        self::assertSame('duplicate_action', $first['withheld_actions'][0]['reason']);
        self::assertSame(['action-1'], $first['idempotency']['duplicate_action_keys']);

        $restarted = $cycle->tick(
            $this->readyFacts([
                'completed_action_keys' => ['action-1'],
                'planned_actions' => [['kind' => 'native_tick', 'idempotency_key' => 'action-1']],
            ]),
            ['apply' => true, 'action_callbacks' => ['native_tick' => $callback]],
        );
        self::assertSame(1, $calls);
        self::assertSame([], $restarted['applied_actions']);
        self::assertSame('idempotency_replay', $restarted['withheld_actions'][0]['reason']);
        self::assertSame(['action-1'], $restarted['idempotency']['replayed_action_keys']);
    }

    public function test_refused_action_kinds_never_fire_even_with_callback(): void
    {
        $touched = false;
        $cycle = new AtlasSelfConstructionRuntimeDaemonCycle;
        $out = $cycle->tick($this->readyFacts(['planned_actions' => [
            ['kind' => 'git'],
            ['kind' => 'operator_action'],
            ['kind' => 'human_action'],
            ['kind' => 'external_provider_call'],
            ['kind' => 'claude_code'],
            ['kind' => 'codex'],
            ['kind' => 'cursor'],
            ['kind' => 'network'],
            ['kind' => 'unrestricted_shell'],
        ]]), [
            'apply' => true,
            'action_callbacks' => [
                'git' => function () use (&$touched) {
                    $touched = true;
                },
                'operator_action' => function () use (&$touched) {
                    $touched = true;
                },
            ],
        ]);

        $this->assertFalse($touched);
        $this->assertSame([], $out['applied_actions']);
        $kinds = array_column($out['withheld_actions'], 'kind');
        foreach (AtlasSelfConstructionRuntimeDaemonCycle::REFUSED_ACTION_KINDS as $r) {
            $this->assertContains($r, $kinds, "{$r} must be refused");
        }
    }

    public function test_pause_blocks_apply_and_records_cycle_blocked_reason(): void
    {
        $cycle = new AtlasSelfConstructionRuntimeDaemonCycle;
        $facts = $this->readyFacts();
        $facts['daemon_state']['pause_requested'] = true;
        $facts['daemon_state']['status'] = 'paused';

        $out = $cycle->tick($facts, [
            'apply' => true,
            'action_callbacks' => ['native_tick' => static fn () => ['ok' => true]],
        ]);

        $this->assertSame([], $out['applied_actions']);
        $this->assertContains('paused', [$out['daemon_status']]);
        $this->assertNotEmpty($out['cycle_blocked_reasons']);
    }

    public function test_safety_stop_blocks_apply(): void
    {
        $cycle = new AtlasSelfConstructionRuntimeDaemonCycle;
        $facts = $this->readyFacts();
        $facts['daemon_state']['safety_stop'] = true;
        $facts['daemon_state']['status'] = 'safety_stopped';

        $out = $cycle->tick($facts, [
            'apply' => true,
            'action_callbacks' => ['native_tick' => static fn () => ['ok' => true]],
        ]);

        $this->assertSame('safety_stopped', $out['daemon_status']);
        $this->assertSame([], $out['applied_actions']);
        $this->assertContains('cycle_blocked:daemon_state_blocks_tick:safety_stopped', array_column($out['withheld_actions'], 'reason'));
    }

    public function test_stale_heartbeat_blocks_apply(): void
    {
        $cycle = new AtlasSelfConstructionRuntimeDaemonCycle;
        $facts = $this->readyFacts(['heartbeat_event' => ['type' => 'staleness_check', 'now_at' => '2026-06-25T06:00:00+00:00']]);
        // last_heartbeat_at far in the past — heartbeat will be stale (>3min default).
        $facts['daemon_state']['last_heartbeat_at'] = '2026-06-25T05:00:00+00:00';
        $facts['daemon_state']['status'] = 'running';

        $out = $cycle->tick($facts, [
            'apply' => true,
            'action_callbacks' => ['native_tick' => static fn () => ['ok' => true]],
        ]);

        $this->assertSame([], $out['applied_actions']);
        $this->assertSame('degraded', $out['daemon_status']);
        $this->assertNotEmpty($out['cycle_blocked_reasons']);
    }

    public function test_unattended_critical_blocker_stops_apply(): void
    {
        $cycle = new AtlasSelfConstructionRuntimeDaemonCycle;
        $out = $cycle->tick($this->readyFacts(['unattended_verdict' => ['critical_blocker' => true]]), [
            'apply' => true,
            'action_callbacks' => ['native_tick' => static fn () => ['ok' => true]],
        ]);

        $this->assertSame([], $out['applied_actions']);
        $this->assertContains('unattended_supervisor_critical_blocker', $out['cycle_blocked_reasons']);
    }

    public function test_brain_recovery_verdict_injects_atlas_native_action_and_fires_with_callback(): void
    {
        $cycle = new AtlasSelfConstructionRuntimeDaemonCycle;
        $out = $cycle->tick($this->readyFacts([
            'planned_actions' => [],
            'unattended_verdict' => [
                'recovery_needed' => true,
                'critical_blocker' => false,
                'classification' => 'stale_brain_heartbeat',
                'severity' => 'medium',
                'reasons' => ['brain_quota_stall_reason_stale_brain_heartbeat'],
            ],
        ]), [
            'apply' => true,
            'action_callbacks' => [
                'atlas_native_brain_recovery' => static fn (array $a): array => [
                    'recovered' => true,
                    'class' => $a['verdict_classification'],
                ],
            ],
        ]);

        $this->assertCount(1, $out['applied_actions']);
        $this->assertSame('atlas_native_brain_recovery', $out['applied_actions'][0]['kind']);
        $this->assertSame('stale_brain_heartbeat', $out['applied_actions'][0]['result']['class']);
        $this->assertSame([], $out['withheld_actions']);
    }

    public function test_brain_recovery_verdict_does_not_unblock_refused_action_kinds(): void
    {
        $cycle = new AtlasSelfConstructionRuntimeDaemonCycle;
        $out = $cycle->tick($this->readyFacts([
            'planned_actions' => [['kind' => 'git']],
            'unattended_verdict' => [
                'recovery_needed' => true,
                'critical_blocker' => false,
                'classification' => 'stale_brain_heartbeat',
            ],
        ]), [
            'apply' => true,
            'action_callbacks' => [
                'git' => static fn () => ['ok' => true],
                'atlas_native_brain_recovery' => static fn () => ['ok' => true],
            ],
        ]);

        $appliedKinds = array_column($out['applied_actions'], 'kind');
        $withheldKinds = array_column($out['withheld_actions'], 'kind');

        $this->assertNotContains('git', $appliedKinds);
        $this->assertContains('git', $withheldKinds);
        $this->assertContains('atlas_native_brain_recovery', $appliedKinds);
    }

    // --- action_feedback tests ---

    public function test_output_has_action_feedback_key(): void
    {
        $out = (new AtlasSelfConstructionRuntimeDaemonCycle)->tick($this->readyFacts());

        $this->assertArrayHasKey('action_feedback', $out);
        $this->assertIsArray($out['action_feedback']);
    }

    public function test_dry_run_action_feedback_has_withheld_retryable_entry(): void
    {
        $out = (new AtlasSelfConstructionRuntimeDaemonCycle)->tick($this->readyFacts());

        $fb = $out['action_feedback'];
        $this->assertCount(1, $fb);
        $this->assertSame('native_tick', $fb[0]['kind']);
        $this->assertSame('withheld', $fb[0]['outcome_class']);
        $this->assertTrue($fb[0]['retryable']);
        $this->assertStringContainsString('native_tick', $fb[0]['next_safe_action']);
        $this->assertIsArray($fb[0]['receipt_refs']);
    }

    public function test_applied_action_feedback_has_applied_entry_not_retryable(): void
    {
        $out = (new AtlasSelfConstructionRuntimeDaemonCycle)->tick(
            $this->readyFacts(),
            ['apply' => true, 'action_callbacks' => ['native_tick' => static fn () => ['ok' => true]]],
        );

        $fb = array_column($out['action_feedback'], null, 'outcome_class');
        $this->assertArrayHasKey('applied', $fb);
        $this->assertSame('native_tick', $fb['applied']['kind']);
        $this->assertFalse($fb['applied']['retryable']);
        $this->assertStringContainsString('verify_applied_outcome', $fb['applied']['next_safe_action']);
    }

    public function test_refused_action_kind_feedback_is_withheld_not_retryable(): void
    {
        $out = (new AtlasSelfConstructionRuntimeDaemonCycle)->tick(
            $this->readyFacts(['planned_actions' => [['kind' => 'git']]]),
            ['apply' => true, 'action_callbacks' => ['git' => static fn () => []]],
        );

        $fb = $out['action_feedback'];
        $this->assertCount(1, $fb);
        $this->assertSame('git', $fb[0]['kind']);
        $this->assertSame('withheld', $fb[0]['outcome_class']);
        $this->assertFalse($fb[0]['retryable']);
        $this->assertStringContainsString('permanently_refused', $fb[0]['next_safe_action']);
    }

    public function test_blocked_action_feedback_is_retryable(): void
    {
        $out = (new AtlasSelfConstructionRuntimeDaemonCycle)->tick(
            $this->readyFacts(),
            [
                'apply' => true,
                'action_callbacks' => [
                    'native_tick' => static function (): never {
                        throw new \RuntimeException('simulated callback failure');
                    },
                ],
            ],
        );

        $fb = array_column($out['action_feedback'], null, 'outcome_class');
        $this->assertArrayHasKey('blocked', $fb);
        $this->assertSame('native_tick', $fb['blocked']['kind']);
        $this->assertTrue($fb['blocked']['retryable']);
        $this->assertStringContainsString('inspect_native_executor_error', $fb['blocked']['next_safe_action']);
    }

    public function test_feedback_entry_has_all_required_keys(): void
    {
        $out = (new AtlasSelfConstructionRuntimeDaemonCycle)->tick($this->readyFacts());

        foreach ($out['action_feedback'] as $entry) {
            foreach (['kind', 'outcome_class', 'retryable', 'next_safe_action', 'receipt_refs'] as $key) {
                $this->assertArrayHasKey($key, $entry);
            }
            $this->assertIsBool($entry['retryable']);
            $this->assertIsArray($entry['receipt_refs']);
        }
    }

    public function test_no_callback_supplied_yields_withheld_not_retryable(): void
    {
        $out = (new AtlasSelfConstructionRuntimeDaemonCycle)->tick(
            $this->readyFacts(),
            ['apply' => true],  // no callbacks
        );

        $fb = $out['action_feedback'];
        $this->assertCount(1, $fb);
        $this->assertSame('withheld', $fb[0]['outcome_class']);
        $this->assertFalse($fb[0]['retryable']);
        $this->assertStringContainsString('supply_callback', $fb[0]['next_safe_action']);
    }

    // ── AC: selected_action / action_kind / proof_required / retry_policy / safety_blockers ──

    public function test_output_has_single_action_summary_keys(): void
    {
        $out = (new AtlasSelfConstructionRuntimeDaemonCycle)->tick($this->readyFacts());

        foreach (['selected_action', 'action_kind', 'proof_required', 'retry_policy', 'safety_blockers'] as $key) {
            $this->assertArrayHasKey($key, $out);
        }
    }

    public function test_safe_action_applied_has_no_safety_blockers_and_not_retryable(): void
    {
        $out = (new AtlasSelfConstructionRuntimeDaemonCycle)->tick(
            $this->readyFacts(),
            ['apply' => true, 'action_callbacks' => ['native_tick' => static fn () => ['ok' => true]]],
        );

        $this->assertSame('native_tick', $out['action_kind']);
        $this->assertSame([], $out['safety_blockers']);
        $this->assertFalse($out['proof_required']);
        $this->assertFalse($out['retry_policy']['retryable']);
    }

    public function test_blocked_cycle_state_surfaces_in_safety_blockers(): void
    {
        $cycle = new AtlasSelfConstructionRuntimeDaemonCycle;
        $facts = $this->readyFacts();
        $facts['daemon_state']['safety_stop'] = true;
        $facts['daemon_state']['status'] = 'safety_stopped';

        $out = $cycle->tick($facts, [
            'apply' => true,
            'action_callbacks' => ['native_tick' => static fn () => ['ok' => true]],
        ]);

        $this->assertNotEmpty($out['safety_blockers']);
        $this->assertContains('daemon_state_blocks_tick:safety_stopped', $out['safety_blockers']);
    }

    public function test_manual_action_refusal_surfaces_in_safety_blockers(): void
    {
        $out = (new AtlasSelfConstructionRuntimeDaemonCycle)->tick(
            $this->readyFacts(['planned_actions' => [['kind' => 'operator_action']]]),
            ['apply' => true, 'action_callbacks' => ['operator_action' => static fn () => ['ok' => true]]],
        );

        $this->assertSame('operator_action', $out['action_kind']);
        $this->assertContains('refused_action_kind:operator_action', $out['safety_blockers']);
    }

    public function test_missing_proof_withholds_action_and_flags_proof_required(): void
    {
        $out = (new AtlasSelfConstructionRuntimeDaemonCycle)->tick(
            $this->readyFacts(['planned_actions' => [['kind' => 'native_tick', 'requires_proof' => true]]]),
            ['apply' => true, 'action_callbacks' => ['native_tick' => static fn () => ['ok' => true]]],
        );

        $this->assertSame([], $out['applied_actions']);
        $this->assertContains('missing_proof', array_column($out['withheld_actions'], 'reason'));
        $this->assertTrue($out['proof_required']);
        $this->assertContains('missing_proof', $out['safety_blockers']);
    }

    public function test_proof_ref_present_allows_action_to_apply(): void
    {
        $out = (new AtlasSelfConstructionRuntimeDaemonCycle)->tick(
            $this->readyFacts(['planned_actions' => [
                ['kind' => 'native_tick', 'requires_proof' => true, 'proof_ref' => 'phpunit:t1'],
            ]]),
            ['apply' => true, 'action_callbacks' => ['native_tick' => static fn () => ['ok' => true]]],
        );

        $this->assertCount(1, $out['applied_actions']);
        $this->assertTrue($out['proof_required']);
        $this->assertNotContains('missing_proof', $out['safety_blockers']);
    }

    public function test_retry_policy_reflects_dry_run_retryable(): void
    {
        $out = (new AtlasSelfConstructionRuntimeDaemonCycle)->tick($this->readyFacts());

        $this->assertTrue($out['retry_policy']['retryable']);
        $this->assertStringContainsString('retry_with_apply_true', $out['retry_policy']['next_safe_action']);
    }
}
