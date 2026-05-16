<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskAutoReplenishmentService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
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

        $objectives = implode("\n", array_map(
            static fn (array $entry): string => (string) $entry['task_packet_id'],
            $result['generated_tasks'],
        ));
        $this->assertStringContainsString('runtime_gap_matrix_all_runtime_y', $objectives);
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
        $this->service()->replenish($this->context([
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
        $this->assertGreaterThanOrEqual(4, count($records));

        $seen = [];
        foreach ($records as $record) {
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
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-task-auto-replenishment-status' => true,
            '--actor' => 'cli-replenisher',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.self_construction_agent_control_plane_task_auto_replenishment_status.v1', $payload['schema_version']);
        $this->assertSame('available', data_get($payload, 'agent_control_plane_task_auto_replenishment_status.status'));
        $this->assertGreaterThanOrEqual(1, (int) data_get($payload, 'agent_control_plane_task_auto_replenishment_status.claimable_task_count_after'));
        $this->assertNotEmpty(data_get($payload, 'agent_control_plane_task_auto_replenishment_status.replenishment_plan_hash'));
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
