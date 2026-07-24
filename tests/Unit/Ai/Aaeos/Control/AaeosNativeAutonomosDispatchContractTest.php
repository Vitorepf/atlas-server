<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Control;

use App\Services\Ai\Aaeos\Control\Dispatch\AutonomosLiveDispatcher;
use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerProductionRuntime;
use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerRecoverableProductionRuntime;
use App\Services\Ai\SelfConstruction\RuntimeDaemon\AtlasSelfConstructionRuntimeDaemon;
use Tests\TestCase;

final class AaeosNativeAutonomosDispatchContractTest extends TestCase
{
    public function test_served_native_task_is_claimed_without_brain_provider_worker_or_write_execution(): void
    {
        $claimer = new class implements AtlasNativeWorkerRecoverableProductionRuntime
        {
            public int $claims = 0;

            public int $resumes = 0;

            public int $forbiddenEffects = 0;

            public function claim(string $clientId): ?array
            {
                $this->claims++;

                return [
                    'schema' => 'atlas.task_serving.envelope.v1',
                    'status' => 'served',
                    'client_id' => $clientId,
                    'task' => [
                        'task_packet_id' => 'task-p1a-1',
                        'lease_id' => 'lease-p1a-1',
                    ],
                ];
            }

            public function resume(string $clientId): ?array
            {
                $this->resumes++;

                return $this->resumes === 1 ? null : [
                    'task_packet_id' => 'task-p1a-1',
                    'lease_id' => 'lease-p1a-1',
                    'authority_nonce' => 'nonce-p1a-1',
                    'authority_hash' => str_repeat('a', 64),
                    'envelope_hash' => str_repeat('b', 64),
                ];
            }

            public function renew(string $clientId, string $taskPacketId, string $leaseId): bool
            {
                throw new \LogicException('renew_must_not_run_for_fresh_claim');
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
        $dispatcher = new AutonomosLiveDispatcher(
            daemon: new AtlasSelfConstructionRuntimeDaemon(nativeWorker: $claimer),
        );

        $result = $dispatcher->liveDispatch($this->cyclePlan(), ['workspace' => 'native-p1a-workspace']);

        self::assertSame(1, $claimer->claims);
        self::assertSame(2, $claimer->resumes);
        self::assertSame(0, $claimer->forbiddenEffects);
        self::assertSame('claimed', $result['status']);
        self::assertSame('native_task_claimed', $result['effects'][0]['kind']);
        self::assertSame('task-p1a-1', $result['task']['task_packet_id']);
        self::assertSame('lease-p1a-1', $result['task']['lease_id']);
        self::assertNull($result['native_journey_ref']);
        self::assertSame([], $result['native_cycle_refs']);
        self::assertSame(['task-p1a-1'], $result['native_task_refs']);
        self::assertSame(['lease-p1a-1'], $result['native_lease_refs']);
        self::assertSame('nonce-p1a-1', $result['effects'][0]['result']['authority_nonce']);
        self::assertSame(str_repeat('a', 64), $result['effects'][0]['result']['authority_hash']);
        self::assertSame(str_repeat('b', 64), $result['effects'][0]['result']['envelope_hash']);
        self::assertSame(str_repeat('b', 64), $result['effects'][0]['result']['native_envelope_ref']);
        self::assertSame('native-p1a-workspace', $result['effects'][0]['result']['workspace']);
        self::assertSame($result['native_journey_ref'], $result['effects'][0]['result']['native_journey_ref']);
        self::assertSame($result['native_cycle_refs'], $result['effects'][0]['result']['native_cycle_refs']);
        self::assertFalse($result['task']['worker_executed']);
        self::assertSame(0, $result['provider_calls']);
        self::assertFalse($result['mutation_performed']);
        self::assertFalse($this->containsKey($result, 'human_in_engineering_loop'));
        self::assertStringNotContainsString('brain:next', (string) file_get_contents(
            base_path('app/Services/Ai/Aaeos/Control/Dispatch/AutonomosLiveDispatcher.php'),
        ));
    }

    public function test_native_claim_diagnostics_are_preserved_in_terminal_refusals(): void
    {
        foreach ([
            [null, 'no_claimable_task', 'no_claimable_task'],
            [
                ['status' => 'served', 'task' => ['task_packet_id' => 'task-without-lease']],
                'invalid_native_claim_envelope',
                'invalid_native_claim_envelope',
            ],
            [
                ['status' => 'waiting_on_dependencies', 'reason' => 'prerequisite_tasks_not_yet_completed', 'task' => null],
                'waiting_on_dependencies',
                'prerequisite_tasks_not_yet_completed',
            ],
            [
                ['status' => 'disabled', 'reason' => 'task_serving_switch_off', 'task' => null],
                'disabled',
                'task_serving_switch_off',
            ],
            [
                ['status' => 'refused', 'reason' => 'native_policy_refused', 'task' => null],
                'refused',
                'native_policy_refused',
            ],
            [
                ['status' => 'error', 'reason' => 'task_serving_error', 'task' => null],
                'error',
                'task_serving_error',
            ],
        ] as [$envelope, $expectedStatus, $expectedReason]) {
            $claimer = new class($envelope) implements AtlasNativeWorkerProductionRuntime
            {
                public function __construct(private readonly ?array $envelope) {}

                public function claim(string $clientId): ?array
                {
                    return $this->envelope;
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
            $result = (new AutonomosLiveDispatcher(
                daemon: new AtlasSelfConstructionRuntimeDaemon(nativeWorker: $claimer),
            ))->liveDispatch($this->cyclePlan());

            self::assertSame('dispatch_refused', $result['status']);
            self::assertSame('blocked', $result['effect_level']);
            self::assertSame($expectedStatus, $result['effects'][0]['result']['status']);
            self::assertSame($expectedReason, $result['effects'][0]['result']['reason']);
            self::assertNull($result['native_journey_ref']);
            self::assertSame([], $result['native_cycle_refs']);
            self::assertSame([], $result['native_task_refs']);
            self::assertFalse($result['task']['worker_executed']);
            self::assertSame(0, $result['provider_calls']);
            self::assertFalse($result['mutation_performed']);
        }
    }

    public function test_native_claim_exception_preserves_authority_unavailable_diagnostic(): void
    {
        $claimer = new class implements AtlasNativeWorkerProductionRuntime
        {
            public function claim(string $clientId): ?array
            {
                throw new \RuntimeException('unavailable');
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

        $result = (new AutonomosLiveDispatcher(
            daemon: new AtlasSelfConstructionRuntimeDaemon(nativeWorker: $claimer),
        ))->liveDispatch($this->cyclePlan());

        self::assertSame('dispatch_refused', $result['status']);
        self::assertSame('native_claim_authority_unavailable', $result['effects'][0]['result']['status']);
        self::assertSame('native_claim_authority_unavailable', $result['effects'][0]['result']['reason']);
        self::assertSame(0, $result['provider_calls']);
        self::assertFalse($result['task']['worker_executed']);
        self::assertFalse($result['mutation_performed']);
    }

    public function test_prohibited_legacy_request_flags_refuse_before_any_claim(): void
    {
        foreach ([
            ['execute_provider' => true, 'reason' => 'p1a_execute_provider_forbidden'],
            ['run_worker_once' => true, 'reason' => 'p1a_run_worker_once_forbidden'],
            ['max_seeds' => 1, 'reason' => 'p1a_max_seeds_forbidden'],
            ['scope' => 'atlas', 'reason' => 'p1a_scope_forbidden'],
        ] as $options) {
            $claimer = new class implements AtlasNativeWorkerProductionRuntime
            {
                public int $claims = 0;

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
            $result = (new AutonomosLiveDispatcher(
                daemon: new AtlasSelfConstructionRuntimeDaemon(nativeWorker: $claimer),
            ))->liveDispatch($this->cyclePlan(), $options);

            self::assertSame('dispatch_refused', $result['status']);
            self::assertSame($options['reason'], $result['error']);
            self::assertSame(0, $claimer->claims);
            self::assertSame(0, $result['provider_calls']);
            self::assertFalse($result['mutation_performed']);
        }
    }

    public function test_dry_run_does_not_claim_or_resume_native_work(): void
    {
        $claimer = new class implements AtlasNativeWorkerProductionRuntime
        {
            public int $claims = 0;

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
        $result = (new AutonomosLiveDispatcher(
            daemon: new AtlasSelfConstructionRuntimeDaemon(nativeWorker: $claimer),
        ))->liveDispatch($this->cyclePlan(), ['dry_run' => true]);

        self::assertSame('plan_only', $result['status']);
        self::assertSame('native_task_claim_planned', $result['effects'][0]['kind']);
        self::assertSame(0, $claimer->claims);
        self::assertNull($result['native_journey_ref']);
        self::assertSame([], $result['native_cycle_refs']);
        self::assertSame([], $result['native_task_refs']);
        self::assertSame([], $result['native_lease_refs']);
        self::assertSame(0, $result['provider_calls']);
        self::assertFalse($result['mutation_performed']);
    }

    public function test_autonomos_refuses_fresh_claims_without_authoritative_provenance(): void
    {
        foreach ([
            [
                [
                    'task_packet_id' => 'task-autonomos-1',
                    'lease_id' => 'lease-autonomos-1',
                ],
                'native_claim_provenance_missing',
                'missing_native_claim_authority_nonce',
            ],
            [
                [
                    'task_packet_id' => 'task-autonomos-1',
                    'lease_id' => 'lease-autonomos-1',
                    'authority_nonce' => 'nonce-autonomos-1',
                    'authority_hash' => str_repeat('a', 64),
                    'envelope_hash' => str_repeat('b', 64),
                    'authority_revoked' => true,
                ],
                'native_claim_authority_revoked',
                'native_authority_revoked',
            ],
            [
                [
                    'task_packet_id' => 'task-autonomos-other',
                    'lease_id' => 'lease-autonomos-other',
                    'authority_nonce' => 'nonce-autonomos-other',
                    'authority_hash' => str_repeat('a', 64),
                    'envelope_hash' => str_repeat('b', 64),
                ],
                'native_claim_provenance_mismatch',
                'fresh_claim_resume_mismatch',
            ],
        ] as [$authoritativeResume, $expectedStatus, $expectedReason]) {
            $claimer = new class($authoritativeResume) implements AtlasNativeWorkerRecoverableProductionRuntime
            {
                public int $claims = 0;

                public int $resumes = 0;

                public int $forbiddenEffects = 0;

                /** @param array<string,mixed> $authoritativeResume */
                public function __construct(private readonly array $authoritativeResume) {}

                public function claim(string $clientId): ?array
                {
                    $this->claims++;

                    return [
                        'status' => 'served',
                        'task' => [
                            'task_packet_id' => 'task-autonomos-1',
                            'lease_id' => 'lease-autonomos-1',
                        ],
                    ];
                }

                public function resume(string $clientId): ?array
                {
                    $this->resumes++;

                    return $this->resumes === 1 ? null : $this->authoritativeResume;
                }

                public function renew(string $clientId, string $taskPacketId, string $leaseId): bool
                {
                    throw new \LogicException('renew_must_not_run_for_fresh_claim');
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
            $result = (new AutonomosLiveDispatcher(
                daemon: new AtlasSelfConstructionRuntimeDaemon(nativeWorker: $claimer),
            ))->liveDispatch($this->cyclePlan());

            self::assertSame('dispatch_refused', $result['status']);
            self::assertSame($expectedStatus, $result['effects'][0]['result']['status']);
            self::assertSame($expectedReason, $result['error']);
            self::assertSame(1, $claimer->claims);
            self::assertSame(2, $claimer->resumes);
            self::assertSame(0, $claimer->forbiddenEffects);
            self::assertSame(0, $result['provider_calls']);
            self::assertFalse($result['task']['worker_executed']);
            self::assertFalse($result['mutation_performed']);
        }
    }

    /** @param array<string,mixed> $payload */
    private function containsKey(array $payload, string $needle): bool
    {
        foreach ($payload as $key => $value) {
            if ($key === $needle || (is_array($value) && $this->containsKey($value, $needle))) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    private function cyclePlan(): array
    {
        return [
            'objective' => ['objective' => 'continue autonomous queue'],
            'difficulty' => ['level' => 3],
            'admission' => ['allows_execution' => true],
            'world' => [],
        ];
    }
}
