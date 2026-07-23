<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskAutoReplenishmentService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneWorkerTaskEligibilityCertificationService;
use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneTaskAutoReplenishmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_constants_are_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_control_plane_task_auto_replenishment.v1', AgentControlPlaneTaskAutoReplenishmentService::SCHEMA_VERSION);
        $this->assertSame('persistent_local_agent_control_plane_task_auto_replenishment', AgentControlPlaneTaskAutoReplenishmentService::MODE);
    }

    public function test_replenishes_empty_queue_from_current_pointer(): void
    {
        $result = $this->service()->replenish($this->context(), [
            'target_min_claimable_tasks' => 2,
            'max_new_tasks' => 2,
            'actor' => 'test-replenisher',
        ]);

        $this->assertSame('auto_replenishment_completed', $result['event']);
        $this->assertSame('available', $result['status']);
        $this->assertSame(0, $result['claimable_task_count_before']);
        $this->assertSame(2, $result['generated_task_count']);
        $this->assertSame(2, $result['claimable_task_count_after']);
        $this->assertNotEmpty($result['replenishment_plan_hash']);
        $this->assertStringContainsString('post_start_receipt_contract', (string) data_get($result, 'generated_tasks.0.reference'));
    }

    public function test_does_not_duplicate_when_target_already_satisfied(): void
    {
        $svc = $this->service();
        $first = $svc->replenish($this->context(), [
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 5,
        ]);
        $second = $svc->replenish($this->context(), [
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 5,
        ]);

        $this->assertSame(1, $first['generated_task_count']);
        $this->assertSame(0, $second['generated_task_count']);
        $this->assertSame(1, $second['claimable_task_count_before']);
        $this->assertSame(1, $second['claimable_task_count_after']);
    }

    public function test_queue_tags_isolate_replenishment_targets(): void
    {
        $svc = $this->service();
        $first = $svc->replenish($this->context(), [
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => ['lane_a'],
        ]);
        $second = $svc->replenish($this->context(), [
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => ['lane_b'],
        ]);

        $this->assertSame(1, $first['generated_task_count']);
        $this->assertSame(1, $second['generated_task_count']);
        $this->assertSame(['lane_a'], $first['queue_tags']);
        $this->assertSame(['lane_b'], $second['queue_tags']);
        $this->assertCount(1, (new AgentControlPlaneTaskPacketQueueRepository)->list(['status' => 'claimable', 'tag' => 'lane_a']));
        $this->assertCount(1, (new AgentControlPlaneTaskPacketQueueRepository)->list(['status' => 'claimable', 'tag' => 'lane_b']));
    }

    public function test_respects_max_new_tasks(): void
    {
        $result = $this->service()->replenish($this->context(), [
            'target_min_claimable_tasks' => 5,
            'max_new_tasks' => 2,
        ]);

        $this->assertSame(2, $result['generated_task_count']);
        $this->assertSame(2, $result['claimable_task_count_after']);
        $this->assertSame(['claimable_queue_below_target_after_replenishment'], $result['blockers']);
    }

    public function test_does_not_duplicate_active_seed_when_claimed_task_drops_claimable_count(): void
    {
        $svc = $this->service();
        $first = $svc->replenish($this->context(), [
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => ['lane_long_loop'],
        ]);

        $taskPacketId = (string) data_get($first, 'generated_tasks.0.task_packet_id');
        $this->assertNotSame('', $taskPacketId);
        (new AgentControlPlaneTaskPacketQueueRepository)->updateStatus($taskPacketId, 'claimed', [
            'lease_id' => 'lease_long_loop_1',
            'agent_id' => 'claude-long-loop-1',
        ]);

        $second = $svc->replenish($this->context(), [
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => ['lane_long_loop'],
        ]);

        $this->assertSame(0, $second['generated_task_count']);
        $this->assertSame(1, $second['skipped_duplicate_seed_count']);
        $this->assertSame(1, $second['active_seed_count']);
        $this->assertSame(['all_candidate_replenishment_seeds_already_active'], $second['blockers']);
        $this->assertSame('all_candidate_seeds_already_active', data_get($second, 'plan_evaluation.status'));
        $this->assertSame('auto_replenishment_seed_already_active', data_get($second, 'plan_evaluation.skipped_duplicate_seeds.0.reason'));
        $this->assertSame($taskPacketId, data_get($second, 'plan_evaluation.skipped_duplicate_seeds.0.existing_task_packet_id'));
    }

    public function test_released_auto_replenishment_seed_does_not_starve_future_supply(): void
    {
        $svc = $this->service();
        $first = $svc->replenish($this->context(), [
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => ['lane_released_loop'],
        ]);

        $taskPacketId = (string) data_get($first, 'generated_tasks.0.task_packet_id');
        $this->assertNotSame('', $taskPacketId);
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $queue->updateStatus($taskPacketId, 'claimed', [
            'lease_id' => 'lease_released_loop_1',
            'agent_id' => 'claude-released-loop-1',
        ]);
        $queue->updateStatus($taskPacketId, 'released', [
            'lease_id' => 'lease_released_loop_1',
            'agent_id' => 'claude-released-loop-1',
            'release_reason' => 'worker_stopped_before_completion',
        ]);

        $second = $svc->replenish($this->context(), [
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => ['lane_released_loop'],
        ]);

        $this->assertSame(1, $second['generated_task_count']);
        $this->assertSame(0, $second['skipped_duplicate_seed_count']);
        $this->assertSame(0, $second['active_seed_count']);
        $this->assertSame(1, $second['claimable_task_count_after']);
        $this->assertSame([], $second['blockers']);
        $this->assertContains('released', data_get($second, 'replenishment_loop_contract.terminal_statuses_not_blocking_future_replenishment'));
        $this->assertNotSame($taskPacketId, data_get($second, 'generated_tasks.0.task_packet_id'));
    }

    public function test_avoids_stale_task_file_id_collision_beyond_registry_cap(): void
    {
        $staleTaskPacketId = 'acp-auto-0001-current_pointer_activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_';
        Storage::disk('local')->put(
            AgentControlPlaneTaskPacketQueueRepository::STORAGE_PREFIX.'/task_'.$staleTaskPacketId.'.json',
            json_encode([
                'schema_version' => AgentControlPlaneTaskPacketQueueRepository::SCHEMA_VERSION,
                'task_packet_id' => $staleTaskPacketId,
                'task_packet_hash' => str_repeat('a', 64),
                'status' => 'completed_dry_run',
                'tags' => ['old_capped_registry_entry'],
                'task_packet' => [
                    'task_packet_id' => $staleTaskPacketId,
                    'task_packet_hash' => str_repeat('a', 64),
                    'continuation_context' => [
                        'auto_replenishment_seed_key' => 'current_pointer_stale_old_seed',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        $result = $this->service()->replenish($this->context(), [
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
        ]);

        $generatedTaskPacketId = (string) data_get($result, 'generated_tasks.0.task_packet_id');
        $this->assertSame(1, $result['generated_task_count']);
        $this->assertSame('enqueued', data_get($result, 'generated_tasks.0.queue_event'));
        $this->assertNotSame($staleTaskPacketId, $generatedTaskPacketId);
        $this->assertStringStartsWith($staleTaskPacketId.'-', $generatedTaskPacketId);
        $this->assertSame(1, $result['claimable_task_count_after']);
        $this->assertSame([], $result['blockers']);
    }

    public function test_loop_contract_explains_sources_stop_conditions_and_evidence_policy(): void
    {
        $result = $this->service()->replenish($this->context(), [
            'target_min_claimable_tasks' => 2,
            'max_new_tasks' => 2,
        ]);

        $this->assertSame(
            'atlas.self_construction.agent_control_plane_task_auto_replenishment_loop_contract.v1',
            data_get($result, 'replenishment_loop_contract.schema_version'),
        );
        $this->assertSame(
            'task_packet.continuation_context.auto_replenishment_seed_key scoped by queue_tags/lane',
            data_get($result, 'replenishment_loop_contract.dedupe_key'),
        );
        $this->assertContains('all_candidate_replenishment_seeds_already_active', data_get($result, 'replenishment_loop_contract.stop_conditions'));
        $this->assertTrue((bool) data_get($result, 'replenishment_loop_contract.claim_before_work_required'));
        $this->assertTrue((bool) data_get($result, 'replenishment_loop_contract.completion_evidence_required'));
        $this->assertContains('current_pointer', array_column((array) $result['source_catalog'], 'source'));
        $this->assertSame(2, data_get($result, 'plan_evaluation.accepted_seed_count'));
        $this->assertIsString(data_get($result, 'plan_evaluation.active_seed_index_hash'));
    }

    public function test_includes_completion_audit_failed_criteria_seed(): void
    {
        $result = $this->service()->replenish($this->context([
            'completion_audit' => [
                'failed_criteria' => ['runtime_gap_matrix_all_runtime_y'],
            ],
        ]), [
            'target_min_claimable_tasks' => 2,
            'max_new_tasks' => 2,
        ]);

        $this->assertSame(1, data_get($result, 'operator_handoff_seed_count'));
        $this->assertSame(
            ['completion_audit_runtime_gap_matrix_all_runtime_y'],
            data_get($result, 'plan_evaluation.operator_handoff_seed_keys'),
        );
        $handoff = (array) data_get($result, 'operator_handoff_tasks.0');
        $this->assertSame('runtime_gap_matrix_all_runtime_y', $handoff['reference']);
        $this->assertFalse((bool) $handoff['worker_executable']);
        $this->assertTrue((bool) $handoff['operator_handoff_required']);
        $this->assertFalse((bool) $handoff['claimable_task_created']);
        $this->assertStringContainsString('operator_signed_runtime_promotion_receipt', (string) $handoff['operator_handoff_reason']);
    }

    public function test_operator_only_completion_blockers_are_not_claimable_worker_tasks(): void
    {
        $result = $this->service()->replenish($this->context([
            'completion_audit' => [
                'failed_criteria' => [
                    'runtime_gap_matrix_all_runtime_y',
                    'end_to_end_real_provider_smoke_green',
                    'human_signed_os_complete_receipt_present',
                ],
            ],
            'control_plane' => [
                'control_plane' => [
                    'not_yet_runtime_capable' => [],
                ],
            ],
        ]), [
            'target_min_claimable_tasks' => 3,
            'max_new_tasks' => 3,
            'queue_tags' => ['operator_only_guard_lane'],
        ]);

        $this->assertSame(3, data_get($result, 'operator_handoff_seed_count'));
        $this->assertSame('evaluated', data_get($result, 'plan_evaluation.status'));
        $this->assertContains('task_auto_replenishment_does_not_assign_operator_only_blockers_to_workers', $result['non_execution_guarantees']);

        $handoffReferences = array_map(
            static fn (array $entry): string => (string) $entry['reference'],
            (array) $result['operator_handoff_tasks'],
        );
        $this->assertContains('runtime_gap_matrix_all_runtime_y', $handoffReferences);
        $this->assertContains('end_to_end_real_provider_smoke_green', $handoffReferences);
        $this->assertContains('human_signed_os_complete_receipt_present', $handoffReferences);

        $records = (new AgentControlPlaneTaskPacketQueueRepository)->list(['status' => 'claimable', 'tag' => 'operator_only_guard_lane']);
        foreach ($records as $record) {
            $reference = (string) data_get($record, 'task_packet.continuation_context.auto_replenishment_reference');
            $this->assertNotContains($reference, [
                'runtime_gap_matrix_all_runtime_y',
                'end_to_end_real_provider_smoke_green',
                'human_signed_os_complete_receipt_present',
            ]);
            $this->assertFalse((bool) data_get($record, 'task_packet.continuation_context.operator_handoff_required'));
            $this->assertTrue((bool) data_get($record, 'task_packet.continuation_context.worker_executable'));
        }
    }

    public function test_operator_only_completion_blockers_remain_visible_when_claimable_target_is_already_satisfied(): void
    {
        $svc = $this->service();
        $svc->replenish($this->context(), [
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => ['operator_handoff_visibility_lane'],
        ]);

        $result = $svc->replenish($this->context([
            'completion_audit' => [
                'status' => 'incomplete',
                'failed_count' => 3,
                'failed_criteria' => [
                    'runtime_gap_matrix_all_runtime_y',
                    'end_to_end_real_provider_smoke_green',
                    'human_signed_os_complete_receipt_present',
                ],
            ],
        ]), [
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => ['operator_handoff_visibility_lane'],
        ]);

        $this->assertSame(1, $result['claimable_task_count_before']);
        $this->assertSame(0, $result['generated_task_count']);
        $this->assertSame('operator_handoff_required', data_get($result, 'plan_evaluation.status'));
        $this->assertSame(3, data_get($result, 'operator_handoff_seed_count'));
        $this->assertEqualsCanonicalizing([
            'completion_audit_runtime_gap_matrix_all_runtime_y',
            'completion_audit_end_to_end_real_provider_smoke_green',
            'completion_audit_human_signed_os_complete_receipt_present',
        ], data_get($result, 'plan_evaluation.operator_handoff_seed_keys'));
    }

    public function test_extracts_operator_handoff_blockers_from_completion_audit_status_wrapper(): void
    {
        $result = $this->service()->replenish($this->context([
            'completion_audit' => [
                'schema_version' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_os_completion_audit_status.v1',
                'status' => 'incomplete',
                'agent_control_plane_atlas_self_construction_os_completion_audit' => [
                    'status' => 'incomplete',
                    'failed_count' => 2,
                    'failed_criteria_detailed' => [
                        [
                            'id' => 'runtime_gap_matrix_all_runtime_y',
                            'blocker_type' => 'human',
                            'why_blocking' => 'runtime promotion must be operator signed against current hashes',
                            'expected_receipt_schema' => 'atlas.self_construction.runtime_promotion_receipt.v1',
                            'remediation_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-draft-status --json',
                            'current_evidence_context' => [
                                'runtime_gap_matrix_hash' => str_repeat('a', 64),
                            ],
                        ],
                        [
                            'id' => 'end_to_end_real_provider_smoke_green',
                            'blocker_type' => 'real_provider',
                            'why_blocking' => 'operator must run the provider smoke outside Atlas',
                            'expected_receipt_schema' => 'atlas.self_construction.real_provider_smoke_certification.v1',
                            'remediation_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-draft-status --json',
                            'current_evidence_context' => [
                                'smoke_hash' => '',
                            ],
                        ],
                    ],
                    'blocker_classification' => [
                        'human_blockers' => ['runtime_gap_matrix_all_runtime_y'],
                        'real_provider_blockers' => ['end_to_end_real_provider_smoke_green'],
                    ],
                ],
            ],
            'control_plane' => [
                'control_plane' => [
                    'persistent_runtime' => [
                        'next_required_slice' => '',
                    ],
                    'not_yet_runtime_capable' => [],
                ],
            ],
        ]), [
            'target_min_claimable_tasks' => 2,
            'max_new_tasks' => 2,
            'queue_tags' => ['status_wrapper_operator_lane'],
        ]);

        $this->assertSame(2, data_get($result, 'operator_handoff_seed_count'));
        $this->assertSame('operator_handoff_required', data_get($result, 'plan_evaluation.status'));
        $this->assertSame(0, data_get($result, 'generated_task_count'));
        $this->assertEqualsCanonicalizing([
            'completion_audit_runtime_gap_matrix_all_runtime_y',
            'completion_audit_end_to_end_real_provider_smoke_green',
        ], data_get($result, 'plan_evaluation.operator_handoff_seed_keys'));

        $runtimeHandoff = collect((array) $result['operator_handoff_tasks'])
            ->firstWhere('reference', 'runtime_gap_matrix_all_runtime_y');
        $smokeHandoff = collect((array) $result['operator_handoff_tasks'])
            ->firstWhere('reference', 'end_to_end_real_provider_smoke_green');

        $this->assertSame('human', data_get($runtimeHandoff, 'blocker_type'));
        $this->assertSame('atlas.self_construction.runtime_promotion_receipt.v1', data_get($runtimeHandoff, 'expected_receipt_schema'));
        $this->assertStringContainsString('runtime-promotion-receipt-draft-status', (string) data_get($runtimeHandoff, 'remediation_command'));
        $this->assertSame(str_repeat('a', 64), data_get($runtimeHandoff, 'current_evidence_context.runtime_gap_matrix_hash'));
        $this->assertSame('real_provider', data_get($smokeHandoff, 'blocker_type'));
        $this->assertSame('atlas.self_construction.real_provider_smoke_certification.v1', data_get($smokeHandoff, 'expected_receipt_schema'));

        $records = (new AgentControlPlaneTaskPacketQueueRepository)->list(['status' => 'claimable', 'tag' => 'status_wrapper_operator_lane']);
        $this->assertSame([], $records);
    }

    public function test_worker_task_eligibility_certification_accepts_safe_worker_queue_and_operator_handoff_blockers(): void
    {
        $this->service()->replenish($this->context([
            'completion_audit' => [
                'status' => 'incomplete',
                'failed_count' => 3,
                'failed_criteria' => [
                    'runtime_gap_matrix_all_runtime_y',
                    'end_to_end_real_provider_smoke_green',
                    'human_signed_os_complete_receipt_present',
                ],
            ],
        ]), [
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => ['eligibility_safe_lane'],
        ]);
        $safePacket = [
            'schema_version' => 'atlas.self_construction.agent_control_plane_task_packet.v1',
            'task_packet_id' => 'eligibility_safe_worker_task',
            'status' => 'planned',
            'objective' => 'Safe worker task for eligibility certification.',
            'worker_executable' => true,
            'operator_handoff_required' => false,
            'normalized_scope' => [
                'allowed_files' => ['app/Services/Ai/SelfConstruction/SafeEligibilityProbe.php'],
                'scope_in' => ['app/Services/Ai/SelfConstruction/'],
                'scope_out' => [],
            ],
            'continuation_context' => [
                'auto_replenishment_seed_key' => 'safe_worker_eligibility_probe',
                'auto_replenishment_reference' => 'safe_worker_eligibility_probe',
            ],
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'completion_real_allowed' => false,
        ];
        $safePacket['task_packet_hash'] = hash('sha256', json_encode($safePacket, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        (new AgentControlPlaneTaskPacketQueueRepository)->enqueue($safePacket, [
            'tags' => ['eligibility_safe_lane'],
        ]);

        $payload = $this->workerEligibility()->certify([
            'queue_tags' => ['eligibility_safe_lane'],
            'completion_audit' => [
                'status' => 'incomplete',
                'failed_count' => 3,
                'failed_criteria' => [
                    'runtime_gap_matrix_all_runtime_y',
                    'end_to_end_real_provider_smoke_green',
                    'human_signed_os_complete_receipt_present',
                ],
            ],
        ]);

        $this->assertSame(AgentControlPlaneWorkerTaskEligibilityCertificationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('available', $payload['status']);
        $this->assertTrue($payload['checks_all_true']);
        $this->assertSame(0, $payload['violation_count']);
        $this->assertGreaterThanOrEqual(1, $payload['claimable_task_count']);
        $this->assertSame(3, $payload['operator_handoff_seed_count']);
        $this->assertEqualsCanonicalizing([
            'runtime_gap_matrix_all_runtime_y',
            'end_to_end_real_provider_smoke_green',
            'human_signed_os_complete_receipt_present',
        ], $payload['operator_only_failed_criteria']);
        $this->assertSame([], $payload['missing_operator_handoff_criteria']);
        $this->assertContains('worker_task_eligibility_certification_does_not_create_tasks', $payload['non_execution_guarantees']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['certification_hash']);
    }

    public function test_worker_task_eligibility_certification_blocks_operator_handoff_task_in_claimable_queue(): void
    {
        $taskPacket = [
            'schema_version' => 'atlas.self_construction.agent_control_plane_task_packet.v1',
            'task_packet_id' => 'malicious_operator_handoff_worker_task',
            'status' => 'planned',
            'objective' => 'Invalid worker task carrying operator-only blocker.',
            'normalized_scope' => [
                'allowed_files' => ['app/Services/Ai/SelfConstruction/Invalid.php'],
                'scope_in' => ['app/Services/Ai/SelfConstruction/'],
                'scope_out' => [],
            ],
            'continuation_context' => [
                'auto_replenishment_seed_key' => 'completion_audit_runtime_gap_matrix_all_runtime_y',
                'auto_replenishment_reference' => 'runtime_gap_matrix_all_runtime_y',
                'worker_executable' => false,
                'operator_handoff_required' => true,
            ],
        ];
        $taskPacket['task_packet_hash'] = hash('sha256', json_encode($taskPacket, JSON_THROW_ON_ERROR));
        (new AgentControlPlaneTaskPacketQueueRepository)->enqueue($taskPacket, [
            'tags' => ['eligibility_blocked_lane'],
        ]);

        $payload = $this->workerEligibility()->certify([
            'queue_tags' => ['eligibility_blocked_lane'],
            'completion_audit' => [
                'failed_criteria' => ['runtime_gap_matrix_all_runtime_y'],
            ],
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse($payload['checks_all_true']);
        $this->assertGreaterThanOrEqual(3, $payload['violation_count']);
        $codes = array_column($payload['violations'], 'code');
        $this->assertContains('claimable_or_claimed_task_not_worker_executable', $codes);
        $this->assertContains('claimable_or_claimed_task_requires_operator_handoff', $codes);
        $this->assertContains('claimable_or_claimed_task_references_operator_only_completion_blocker', $codes);
    }

    public function test_worker_task_eligibility_certification_blocks_operator_handoff_task_before_claimable_state(): void
    {
        $taskPacket = [
            'schema_version' => 'atlas.self_construction.agent_control_plane_task_packet.v1',
            'task_packet_id' => 'malicious_operator_handoff_queued_task',
            'status' => 'queued',
            'objective' => 'Invalid queued task carrying operator-only blocker.',
            'normalized_scope' => [
                'allowed_files' => ['app/Services/Ai/SelfConstruction/InvalidQueued.php'],
                'scope_in' => ['app/Services/Ai/SelfConstruction/'],
                'scope_out' => [],
            ],
            'continuation_context' => [
                'auto_replenishment_seed_key' => 'completion_audit_end_to_end_real_provider_smoke_green',
                'auto_replenishment_reference' => 'end_to_end_real_provider_smoke_green',
                'worker_executable' => false,
                'operator_handoff_required' => true,
            ],
        ];
        $taskPacket['task_packet_hash'] = hash('sha256', json_encode($taskPacket, JSON_THROW_ON_ERROR));
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $repo->enqueue($taskPacket, [
            'tags' => ['eligibility_preclaim_blocked_lane'],
        ]);

        $payload = $this->workerEligibility()->certify([
            'queue_tags' => ['eligibility_preclaim_blocked_lane'],
            'completion_audit' => [
                'failed_criteria_detailed' => [
                    [
                        'id' => 'end_to_end_real_provider_smoke_green',
                        'blocker_type' => 'real_provider',
                    ],
                ],
            ],
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('queued', $payload['worker_candidate_statuses']);
        $this->assertContains('lease_expired', $payload['worker_candidate_statuses']);
        $codes = array_column($payload['violations'], 'code');
        $this->assertContains('claimable_or_claimed_task_not_worker_executable', $codes);
        $this->assertContains('claimable_or_claimed_task_requires_operator_handoff', $codes);
        $this->assertContains('claimable_or_claimed_task_references_operator_only_completion_blocker', $codes);
        $this->assertSame('queued', data_get($payload, 'active_worker_tasks.0.status', ''));
    }

    public function test_includes_not_yet_runtime_capable_gap_seeds(): void
    {
        $result = $this->service()->replenish($this->context(), [
            'target_min_claimable_tasks' => 4,
            'max_new_tasks' => 4,
        ]);

        $references = implode("\n", array_map(
            static fn (array $entry): string => (string) $entry['reference'],
            $result['generated_tasks'],
        ));
        $this->assertStringContainsString('adapter_execution_runtime', $references);
        $this->assertStringContainsString('automatic_cost_import_runtime', $references);

        $records = (new AgentControlPlaneTaskPacketQueueRepository)->list(['status' => 'claimable']);
        $runtimeGapRecords = array_values(array_filter(
            $records,
            static fn (array $record): bool => in_array('not_yet_runtime_capable', (array) ($record['tags'] ?? []), true),
        ));
        $this->assertCount(2, $runtimeGapRecords);
        foreach ($runtimeGapRecords as $record) {
            $this->assertSame('not_yet_runtime_capable', data_get($record, 'task_packet.continuation_context.auto_replenishment_source'));
            $this->assertNotEmpty(data_get($record, 'task_packet.continuation_context.auto_replenishment_reference'));
            $allowedFiles = (array) data_get($record, 'task_packet.normalized_scope.allowed_files');
            $scopeIn = (array) data_get($record, 'task_packet.normalized_scope.scope_in');
            $this->assertContains($allowedFiles[0], $scopeIn);
            $this->assertContains($allowedFiles[1], $scopeIn);
            $this->assertNotContains('app/Services/Ai/SelfConstruction/', $scopeIn);
        }
    }

    public function test_generated_task_packets_are_claimable_and_scope_safe(): void
    {
        $this->service()->replenish($this->context(), [
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
        ]);

        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $records = $queue->list(['status' => 'claimable']);

        $this->assertCount(1, $records);
        $this->assertSame('claimable', $records[0]['status']);
        $this->assertFalse((bool) $records[0]['dispatch_allowed']);
        $this->assertFalse((bool) $records[0]['provider_call_allowed']);
        $this->assertContains('docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md', data_get($records[0], 'task_packet.normalized_scope.scope_in'));
    }

    public function test_generated_parallel_lanes_have_disjoint_write_sets(): void
    {
        $result = $this->service()->replenish($this->context([
            'completion_audit' => [
                'failed_criteria' => [
                    'runtime_gap_matrix_all_runtime_y',
                    'end_to_end_real_provider_smoke_green',
                ],
            ],
        ]), [
            'target_min_claimable_tasks' => 4,
            'max_new_tasks' => 4,
        ]);

        $records = (new AgentControlPlaneTaskPacketQueueRepository)->list(['status' => 'claimable']);
        $this->assertGreaterThanOrEqual(2, count($records));
        $this->assertSame(2, data_get($result, 'operator_handoff_seed_count'));

        $seen = [];
        foreach ($records as $record) {
            $this->assertFalse((bool) data_get($record, 'task_packet.continuation_context.operator_handoff_required'));
            $writeSet = (array) data_get($record, 'task_packet.normalized_scope.allowed_files', []);
            $this->assertNotEmpty($writeSet);
            $this->assertSame([], array_values(array_intersect($seen, $writeSet)));
            $seen = array_values(array_unique(array_merge($seen, $writeSet)));
        }
    }

    public function test_runtime_flags_remain_false(): void
    {
        $result = $this->service()->replenish($this->context());

        foreach ([
            'runtime_execution_allowed',
            'dispatch_allowed',
            'provider_call_allowed',
            'token_spend_allowed',
            'self_programming_allowed',
            'ledger_write_allowed',
            'completion_real_allowed',
        ] as $flag) {
            $this->assertFalse((bool) $result[$flag], $flag);
        }
        $this->assertContains('task_auto_replenishment_does_not_dispatch_work', $result['non_execution_guarantees']);
    }

    public function test_cli_status_replenishes_queue(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $queueCountBefore = (int) $queue->registry()['total_count'];

        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-task-auto-replenishment-status' => true,
            '--actor' => 'cli-replenisher',
            '--target-min-claimable-tasks' => 1,
            '--max-new-tasks' => 1,
            '--queue-tag' => ['cli_writer_status_contract_lane'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.self_construction_agent_control_plane_task_auto_replenishment_status.v1', $payload['schema_version']);
        // A1-SC-0003 fix: this run generates a task (a durable write) and says so.
        $this->assertSame('mutating_agent_control_plane_task_auto_replenishment_status', $payload['mode']);
        $this->assertTrue((bool) $payload['runtime_write_allowed']);
        $this->assertTrue((bool) $payload['runtime_write_performed']);
        $this->assertFalse((bool) $payload['execution_allowed']);
        $this->assertFalse((bool) $payload['dispatch_allowed']);
        $this->assertFalse((bool) $payload['ledger_write_allowed']);
        $this->assertSame('available', data_get($payload, 'agent_control_plane_task_auto_replenishment_status.status'));
        $this->assertGreaterThanOrEqual(1, (int) data_get($payload, 'agent_control_plane_task_auto_replenishment_status.claimable_task_count_after'));
        $this->assertIsInt(data_get($payload, 'agent_control_plane_task_auto_replenishment_status.operator_handoff_seed_count'));
        $this->assertIsArray(data_get($payload, 'agent_control_plane_task_auto_replenishment_status.operator_handoff_seed_keys'));
        $this->assertIsArray(data_get($payload, 'agent_control_plane_task_auto_replenishment_status.operator_handoff_tasks'));
        $this->assertIsArray(data_get($payload, 'agent_control_plane_task_auto_replenishment_status.accepted_seed_keys'));
        $this->assertIsString(data_get($payload, 'agent_control_plane_task_auto_replenishment_status.completion_audit_context_status'));
        $this->assertIsInt(data_get($payload, 'agent_control_plane_task_auto_replenishment_status.completion_audit_context_failed_count'));
        $this->assertIsArray(data_get($payload, 'agent_control_plane_task_auto_replenishment_status.completion_audit_context_failed_criteria'));
        $this->assertNotEmpty(data_get($payload, 'agent_control_plane_task_auto_replenishment_status.replenishment_plan_hash'));
        $this->assertSame(1, data_get($payload, 'agent_control_plane_task_auto_replenishment_status.generated_task_count'));
        $this->assertGreaterThan($queueCountBefore, (int) $queue->registry()['total_count']);
        $records = $queue->list(['status' => 'claimable', 'tag' => 'cli_writer_status_contract_lane']);
        $this->assertCount(1, $records);
        $this->assertNotEmpty(data_get($records, '0.task_packet_id'));
        $this->assertContains(
            data_get($records, '0.task_packet_id'),
            array_column((array) data_get($payload, 'agent_control_plane_task_auto_replenishment.generated_tasks', []), 'task_packet_id'),
        );
    }

    public function test_cli_status_accepts_queue_tag_lane_isolation(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-task-auto-replenishment-status' => true,
            '--actor' => 'cli-replenisher',
            '--target-min-claimable-tasks' => 1,
            '--max-new-tasks' => 1,
            '--queue-tag' => ['cli_lane_a'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['cli_lane_a'], data_get($payload, 'agent_control_plane_task_auto_replenishment_status.queue_tags'));
        $this->assertSame(1, data_get($payload, 'agent_control_plane_task_auto_replenishment_status.target_min_claimable_tasks'));
        $this->assertSame(1, data_get($payload, 'agent_control_plane_task_auto_replenishment.generated_task_count'));
        $laneRecords = (new AgentControlPlaneTaskPacketQueueRepository)->list(['status' => 'claimable', 'tag' => 'cli_lane_a']);
        $this->assertNotEmpty($laneRecords);
        foreach ($laneRecords as $record) {
            $this->assertContains('cli_lane_a', (array) ($record['tags'] ?? []));
        }
    }

    public function test_cli_quartet_works(): void
    {
        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-task-auto-replenishment-{$stage}" => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame("atlas.self_construction_agent_control_plane_task_auto_replenishment_{$stageKey}.v1", $payload['schema_version']);
        }
    }

    public function test_worker_task_eligibility_certification_cli_quartet_works(): void
    {
        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-worker-task-eligibility-certification-{$stage}" => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame("atlas.self_construction_agent_control_plane_worker_task_eligibility_certification_{$stageKey}.v1", $payload['schema_version']);
        }

        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-worker-task-eligibility-certification-status' => true,
            '--max-new-tasks' => 0,
            '--queue-tag' => ['eligibility_cli_lane'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.self_construction_agent_control_plane_worker_task_eligibility_certification_status.v1', $payload['schema_version']);
        $this->assertContains(data_get($payload, 'agent_control_plane_worker_task_eligibility_certification_status.status'), ['available', 'blocked']);
        $this->assertIsBool(data_get($payload, 'agent_control_plane_worker_task_eligibility_certification_status.checks_all_true'));
        $this->assertIsInt(data_get($payload, 'agent_control_plane_worker_task_eligibility_certification_status.violation_count'));
        $this->assertContains('queued', data_get($payload, 'agent_control_plane_worker_task_eligibility_certification_status.worker_candidate_statuses'));
        $this->assertIsArray(data_get($payload, 'agent_control_plane_worker_task_eligibility_certification_status.active_worker_tasks'));
        $this->assertIsArray(data_get($payload, 'agent_control_plane_worker_task_eligibility_certification_status.operator_only_failed_criteria'));
    }

    public function test_respects_target_min_claimable_tasks_stops_at_target(): void
    {
        // target=2 + max_new=10 should still only generate 2 tasks (target wins).
        $result = $this->service()->replenish($this->context(), [
            'target_min_claimable_tasks' => 2,
            'max_new_tasks' => 10,
        ]);
        $this->assertSame(2, $result['generated_task_count']);
        $this->assertSame(2, $result['claimable_task_count_after']);
        $this->assertSame([], $result['blockers']);
    }

    public function test_seeds_never_open_forbidden_axes(): void
    {
        $result = $this->service()->replenish($this->context(), [
            'target_min_claimable_tasks' => 5,
            'max_new_tasks' => 5,
        ]);

        // The seed templates declared by the service must never list any
        // forbidden axis (routes/api.php, SelfImprovement, Programming,
        // atlas-desktop) inside `allowed_files`. We inspect every queued
        // packet's normalized_scope.allowed_files and assert that no path
        // starts with a forbidden prefix.
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $forbiddenPrefixes = [
            'routes/api.php',
            'app/Services/Ai/SelfImprovement/',
            'app/Services/Ai/Programming/',
            'atlas-desktop/',
        ];
        $records = $queue->list();
        $this->assertNotEmpty($records);
        foreach ($records as $record) {
            $allowed = (array) data_get($record, 'task_packet.normalized_scope.allowed_files', []);
            foreach ($allowed as $path) {
                foreach ($forbiddenPrefixes as $forbidden) {
                    $this->assertFalse(
                        str_starts_with((string) $path, $forbidden),
                        'auto-replenished packet must not allow path under forbidden axis: '.(string) $path,
                    );
                }
            }
            $this->assertSame('claimable', $record['status']);
        }
        $this->assertNotEmpty($result['generated_tasks']);
    }

    public function test_replenishment_plan_hash_is_deterministic_for_same_input(): void
    {
        $a = $this->service()->replenish($this->context(), [
            'target_min_claimable_tasks' => 2,
            'max_new_tasks' => 2,
            'actor' => 'a',
        ]);
        Storage::fake('local'); // reset on-disk queue so second pass replans from zero
        $b = $this->service()->replenish($this->context(), [
            'target_min_claimable_tasks' => 2,
            'max_new_tasks' => 2,
            'actor' => 'b',
        ]);
        $this->assertNotEmpty($a['replenishment_plan_hash']);
        $this->assertSame($a['replenishment_plan_hash'], $b['replenishment_plan_hash']);
    }

    public function test_canonical_contract_doc_is_a_source_and_exists_on_disk(): void
    {
        $result = $this->service()->replenish($this->context(), [
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
        ]);
        $contract = array_values(array_filter(
            (array) $result['sources'],
            static fn (array $s): bool => ($s['source'] ?? '') === 'canonical_contract',
        ));
        $this->assertNotEmpty($contract);
        $this->assertSame(
            'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            (string) $contract[0]['value'],
        );
        $this->assertTrue((bool) $contract[0]['available']);
        // The doc itself must be present on disk so the contract source is
        // never a fabricated reference.
        $this->assertFileExists(base_path('docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md'));
    }

    // ── AC: evaluateWorkerFeedRisk() exposes feed_risk_reasons (granular, possibly-multi-cause) ──

    public function test_feed_risk_reasons_empty_when_no_top_up_required(): void
    {
        $result = $this->service()->evaluateWorkerFeedRisk([
            'active_leases' => 0,
            'claimable_depth' => 0,
        ]);

        $this->assertFalse($result['top_up_required']);
        $this->assertSame([], $result['feed_risk_reasons']);
    }

    public function test_feed_risk_reasons_names_claimable_per_worker_below_floor(): void
    {
        $result = $this->service()->evaluateWorkerFeedRisk([
            'active_leases' => 5,
            'claimable_depth' => 5, // 1.0 per worker, below the 2.0 default floor
        ]);

        $this->assertTrue($result['top_up_required']);
        $this->assertSame(['claimable_per_worker_below_floor'], $result['feed_risk_reasons']);
    }

    public function test_feed_risk_reasons_names_replenish_recommendation_soon(): void
    {
        $result = $this->service()->evaluateWorkerFeedRisk([
            'active_leases' => 6,
            'claimable_depth' => 60, // comfortably above the floor
            'replenish_recommendation' => 'replenish_soon',
        ]);

        $this->assertTrue($result['top_up_required']);
        $this->assertSame(['replenish_recommendation_soon'], $result['feed_risk_reasons']);
    }

    public function test_feed_risk_reasons_lists_both_causes_when_both_trigger(): void
    {
        $result = $this->service()->evaluateWorkerFeedRisk([
            'active_leases' => 5,
            'claimable_depth' => 5, // below floor
            'replenish_recommendation' => 'replenish_soon',
        ]);

        $this->assertTrue($result['top_up_required']);
        $this->assertSame(
            ['claimable_per_worker_below_floor', 'replenish_recommendation_soon'],
            $result['feed_risk_reasons'],
        );
        $this->assertSame('worker_feed_risk', $result['reason'], 'the single reason constant stays for backward compatibility');
    }

    private function service(): AgentControlPlaneTaskAutoReplenishmentService
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;

        return new AgentControlPlaneTaskAutoReplenishmentService(
            new AgentControlPlaneTaskQueueOrchestrator(
                new AgentControlPlaneTaskPacketBuilder,
                new AgentControlPlaneScopeLockRuntimeValidator,
                $queue,
                new AgentControlPlaneClaimLeaseRepository,
                new AgentControlPlaneEvidenceLedgerDryRun,
                new AgentControlPlaneContinuationSummaryBuilder,
            ),
            $queue,
        );
    }

    private function workerEligibility(): AgentControlPlaneWorkerTaskEligibilityCertificationService
    {
        return new AgentControlPlaneWorkerTaskEligibilityCertificationService(
            app(AtlasSelfConstructionReadinessService::class),
            new AgentControlPlaneTaskPacketQueueRepository,
        );
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function context(array $override = []): array
    {
        return array_replace_recursive([
            'control_plane' => [
                'control_plane' => [
                    'persistent_runtime' => [
                        'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract',
                    ],
                    'not_yet_runtime_capable' => [
                        'adapter_execution_runtime',
                        'automatic_cost_import_runtime',
                    ],
                ],
            ],
            'completion_audit' => [
                'failed_criteria' => [],
            ],
            'chain_integrity' => [
                'violations' => [],
            ],
        ], $override);
    }
}
