<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueLeaseCertificationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneTaskQueueLeaseCertificationTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $cached = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->cached = null;
    }

    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_control_plane_task_queue_lease_certification.v1', AgentControlPlaneTaskQueueLeaseCertificationService::SCHEMA_VERSION);
        $this->assertSame('persistent_local_agent_control_plane_task_queue_lease_certification', AgentControlPlaneTaskQueueLeaseCertificationService::MODE);
    }

    public function test_certification_status_available(): void
    {
        $result = $this->certification();
        $this->assertSame('available', $result['status']);
        $this->assertSame(0, $result['violation_count']);
        $this->assertTrue($result['invariants_all_true']);
    }

    public function test_queue_and_lease_repos_invariants(): void
    {
        $result = $this->certification();
        $invariants = $result['invariants'];
        $this->assertTrue($invariants['queue_repository_available']);
        $this->assertTrue($invariants['lease_repository_available']);
        $this->assertTrue($invariants['scope_lock_validator_available']);
        $this->assertTrue($invariants['allowed_statuses_canonical']);
        $this->assertTrue($invariants['forbidden_axes_set']);
    }

    public function test_idempotency_and_single_owner_probes(): void
    {
        $result = $this->certification();
        $probes = $result['probe_evidence']['probes'];
        $this->assertTrue($probes['queue_enqueue_ok']);
        $this->assertTrue($probes['queue_idempotent']);
        $this->assertTrue($probes['claim_single_owner']);
        $this->assertTrue($probes['claim_double_blocked']);
    }

    public function test_owner_only_renew_release_probes(): void
    {
        $result = $this->certification();
        $probes = $result['probe_evidence']['probes'];
        $this->assertTrue($probes['renew_owner_only']);
        $this->assertTrue($probes['renew_owner_succeeds']);
        $this->assertTrue($probes['release_owner_only']);
        $this->assertTrue($probes['release_owner_succeeds']);
    }

    public function test_conflict_and_axis_probes(): void
    {
        $result = $this->certification();
        $probes = $result['probe_evidence']['probes'];
        $this->assertTrue($probes['conflict_detection_ok']);
        $this->assertTrue($probes['validator_blocks_forbidden_axis']);
        $this->assertTrue($probes['validator_blocks_path_traversal']);
        $this->assertTrue($probes['lease_has_receipts']);
        $this->assertTrue($probes['conflict_check_clear_when_no_overlap']);
    }

    public function test_runtime_safety_all_false(): void
    {
        $result = $this->certification();
        $this->assertTrue($result['runtime_safety']['runtime_safety_all_false']);
        foreach ($result['runtime_safety']['queue_runtime_flags'] as $flag => $value) {
            $this->assertFalse((bool) $value, "queue flag {$flag} should be false");
        }
        foreach ($result['runtime_safety']['lease_runtime_flags'] as $flag => $value) {
            $this->assertFalse((bool) $value, "lease flag {$flag} should be false");
        }
        $this->assertFalse($result['runtime_execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
        $this->assertFalse($result['completion_real_allowed']);
    }

    public function test_violation_count_zero(): void
    {
        $result = $this->certification();
        $this->assertSame(0, $result['violation_count']);
        $this->assertSame([], $result['violations']);
        $this->assertSame(0, $result['warning_count']);
    }

    public function test_storage_prefixes_canonical(): void
    {
        $result = $this->certification();
        $this->assertSame('atlas/self-construction/agent-control-plane/task-queue', $result['queue_summary']['storage_prefix']);
        $this->assertSame('atlas/self-construction/agent-control-plane/leases', $result['lease_summary']['storage_prefix']);
    }

    public function test_non_execution_guarantees(): void
    {
        $result = $this->certification();
        foreach ([
            'task_queue_lease_certification_does_not_start_codex',
            'task_queue_lease_certification_does_not_call_codex_cli_or_app',
            'task_queue_lease_certification_does_not_spawn_subprocess',
            'task_queue_lease_certification_does_not_invoke_adapter',
            'task_queue_lease_certification_does_not_call_provider',
            'task_queue_lease_certification_does_not_dispatch_work',
            'task_queue_lease_certification_does_not_spend_tokens',
            'task_queue_lease_certification_does_not_enable_self_programming',
            'task_queue_lease_certification_does_not_write_ledger',
            'task_queue_lease_certification_does_not_mutate_pointer',
            'task_queue_lease_certification_does_not_mark_real_completion',
        ] as $expected) {
            $this->assertContains($expected, $result['non_execution_guarantees']);
        }
    }

    public function test_cli_status_returns_payload(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-task-queue-lease-certification-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.self_construction_agent_control_plane_task_queue_lease_certification_status.v1', $payload['schema_version']);
        $this->assertSame('available', data_get($payload, 'agent_control_plane_task_queue_lease_certification_status.status'));
        $this->assertTrue((bool) data_get($payload, 'agent_control_plane_task_queue_lease_certification_status.invariants_all_true'));
    }

    public function test_cli_quartet_works(): void
    {
        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-task-queue-lease-certification-{$stage}" => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame("atlas.self_construction_agent_control_plane_task_queue_lease_certification_{$stageKey}.v1", $payload['schema_version']);
        }
    }

    public function test_certification_hash_present(): void
    {
        $result = $this->certification();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['certification_hash']);
        $this->assertNotEmpty($result['certification_id']);
    }

    public function test_probe_count(): void
    {
        $result = $this->certification();
        $this->assertGreaterThanOrEqual(13, $result['probe_evidence']['probe_count']);
    }

    public function test_each_probe_individually_asserted(): void
    {
        $result = $this->certification();
        $probes = $result['probe_evidence']['probes'];
        $expected = [
            'scope_lock_runtime_validator_valid', 'queue_enqueue_ok', 'queue_idempotent',
            'queue_multi_tag_filter_requires_all_tags',
            'claim_single_owner', 'claim_double_blocked', 'renew_owner_only', 'renew_owner_succeeds',
            'release_owner_only', 'release_owner_succeeds', 'conflict_detection_ok',
            'validator_blocks_forbidden_axis', 'validator_blocks_path_traversal', 'lease_has_receipts',
            'conflict_check_clear_when_no_overlap',
        ];
        foreach ($expected as $name) {
            $this->assertArrayHasKey($name, $probes, "Missing probe $name");
            $this->assertTrue((bool) $probes[$name], "Probe $name must pass");
        }
    }

    public function test_each_invariant_present_and_true(): void
    {
        $result = $this->certification();
        foreach ([
            'queue_repository_available', 'lease_repository_available', 'scope_lock_validator_available',
            'allowed_statuses_canonical', 'forbidden_axes_set',
            'probe_scope_lock_runtime_validator_valid', 'probe_queue_enqueue_ok', 'probe_queue_idempotent',
            'probe_queue_multi_tag_filter_requires_all_tags',
            'probe_claim_single_owner', 'probe_claim_double_blocked',
            'probe_renew_owner_only', 'probe_renew_owner_succeeds',
            'probe_release_owner_only', 'probe_release_owner_succeeds',
            'probe_conflict_detection_ok', 'probe_validator_blocks_forbidden_axis',
            'probe_validator_blocks_path_traversal', 'probe_lease_has_receipts',
            'probe_conflict_check_clear_when_no_overlap',
        ] as $invariant) {
            $this->assertArrayHasKey($invariant, $result['invariants'], "Missing invariant $invariant");
            $this->assertTrue($result['invariants'][$invariant], "Invariant $invariant must be true");
        }
    }

    public function test_full_certification_payload_shape(): void
    {
        $result = $this->certification();
        foreach ([
            'schema_version', 'mode', 'certification_id', 'generated_at', 'status',
            'invariants', 'invariants_all_true', 'violation_count', 'warning_count',
            'violations', 'warnings', 'queue_summary', 'lease_summary', 'runtime_safety',
            'probe_evidence', 'next_action', 'runtime_execution_allowed', 'dispatch_allowed',
            'provider_call_allowed', 'token_spend_allowed', 'self_programming_allowed',
            'ledger_write_allowed', 'completion_real_allowed', 'non_execution_guarantees',
            'human_summary', 'certification_hash',
        ] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing $key");
        }
        foreach (['active_lease_count', 'storage_prefix', 'default_ttl_seconds', 'min_ttl_seconds', 'max_ttl_seconds'] as $key) {
            $this->assertArrayHasKey($key, $result['lease_summary'], "Missing lease_summary $key");
        }
        foreach (['entry_count', 'total_count', 'status_counts', 'corrupt', 'storage_prefix'] as $key) {
            $this->assertArrayHasKey($key, $result['queue_summary'], "Missing queue_summary $key");
        }
        foreach (['probe_count', 'probes', 'probe_task_packet_id', 'probe_runtime_execution_allowed'] as $key) {
            $this->assertArrayHasKey($key, $result['probe_evidence'], "Missing probe $key");
        }
    }

    public function test_all_runtime_safety_flags_explicitly_false(): void
    {
        $result = $this->certification();
        foreach ([
            'runtime_execution_allowed', 'dispatch_allowed', 'provider_call_allowed',
            'token_spend_allowed', 'self_programming_allowed', 'ledger_write_allowed',
            'completion_real_allowed',
        ] as $flag) {
            $this->assertFalse($result[$flag], "Result-level $flag must be false");
            $this->assertFalse($result['runtime_safety']['queue_runtime_flags'][$flag], "Queue $flag must be false");
            $this->assertFalse($result['runtime_safety']['lease_runtime_flags'][$flag], "Lease $flag must be false");
        }
    }

    public function test_invariants_for_each_runtime_flag(): void
    {
        $result = $this->certification();
        foreach (['runtime_execution_allowed', 'dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'self_programming_allowed', 'ledger_write_allowed', 'completion_real_allowed'] as $flag) {
            $this->assertArrayHasKey("queue_{$flag}_is_false", $result['invariants']);
            $this->assertArrayHasKey("lease_{$flag}_is_false", $result['invariants']);
            $this->assertTrue($result['invariants']["queue_{$flag}_is_false"]);
            $this->assertTrue($result['invariants']["lease_{$flag}_is_false"]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function certification(): array
    {
        if ($this->cached === null) {
            $svc = new AgentControlPlaneTaskQueueLeaseCertificationService(
                new AgentControlPlaneTaskPacketBuilder,
                new AgentControlPlaneScopeLockRuntimeValidator,
                new AgentControlPlaneTaskPacketQueueRepository,
                new AgentControlPlaneClaimLeaseRepository,
            );
            $this->cached = $svc->certify();
        }

        return $this->cached;
    }
}
