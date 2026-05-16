<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneOneShotWorkerPacketService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Atlas Self-Construction OS · Agent Control Plane · One-Shot Worker Packet
 * (`atlas.self_construction.agent_control_plane_one_shot_worker_packet.v1`).
 *
 * Locks down the canonical envelope a worker (Claude, Codex, Gemini, local
 * agent) receives so it can implement a claimed task without external
 * context. Read-only: no provider invocation, no token spend, no real
 * dispatch, no ledger write.
 */
final class AtlasAiSelfConstructionAgentControlPlaneOneShotWorkerPacketTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_blocks_when_task_packet_id_missing(): void
    {
        $service = $this->makeService();
        $result = $service->generate(['lease_id' => 'lease_x']);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('missing_task_packet_id', $result['reason']);
        $this->assertFalse($result['runtime_safety']['runtime_execution_allowed']);
    }

    public function test_blocks_when_lease_id_missing(): void
    {
        $service = $this->makeService();
        $result = $service->generate(['task_packet_id' => 'AIP-test']);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('missing_lease_id', $result['reason']);
    }

    public function test_blocks_when_lease_belongs_to_other_packet(): void
    {
        [$queue, $leases] = $this->wirings();
        $packetA = $this->seedTaskPacket($queue, 'AIP-aaa');
        $packetB = $this->seedTaskPacket($queue, 'AIP-bbb');
        $leaseA = $leases->claim('AIP-aaa', 'agent-1', $this->scope(['app/A.php']));
        $this->assertSame('ok', $leaseA['status']);

        $service = new AgentControlPlaneOneShotWorkerPacketService($leases, $queue);
        $result = $service->generate([
            'task_packet_id' => $packetB['task_packet_id'],
            'lease_id' => $leaseA['lease_id'],
            'actor' => 'agent-1',
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('lease_task_packet_mismatch', $result['reason']);
    }

    public function test_blocks_when_lease_not_active(): void
    {
        [$queue, $leases] = $this->wirings();
        $packet = $this->seedTaskPacket($queue, 'AIP-released');
        $lease = $leases->claim($packet['task_packet_id'], 'agent-r', $this->scope(['app/R.php']));
        $this->assertSame('ok', $lease['status']);
        $release = $leases->release($lease['lease_id'], 'agent-r');
        $this->assertSame('ok', $release['status']);

        $service = new AgentControlPlaneOneShotWorkerPacketService($leases, $queue);
        $result = $service->generate([
            'task_packet_id' => $packet['task_packet_id'],
            'lease_id' => $lease['lease_id'],
            'actor' => 'agent-r',
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('lease_not_active', $result['reason']);
    }

    public function test_blocks_when_actor_is_not_lease_owner(): void
    {
        [$queue, $leases] = $this->wirings();
        $packet = $this->seedTaskPacket($queue, 'AIP-owner');
        $lease = $leases->claim($packet['task_packet_id'], 'agent-owner', $this->scope(['app/O.php']));

        $service = new AgentControlPlaneOneShotWorkerPacketService($leases, $queue);
        $result = $service->generate([
            'task_packet_id' => $packet['task_packet_id'],
            'lease_id' => $lease['lease_id'],
            'actor' => 'intruder',
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('lease_owner_mismatch', $result['reason']);
    }

    public function test_blocks_when_task_packet_not_in_queue(): void
    {
        $leases = new AgentControlPlaneClaimLeaseRepository;
        $lease = $leases->claim('AIP-no-queue', 'agent-z', $this->scope(['app/Z.php']));
        $queue = new AgentControlPlaneTaskPacketQueueRepository;

        $service = new AgentControlPlaneOneShotWorkerPacketService($leases, $queue);
        $result = $service->generate([
            'task_packet_id' => 'AIP-no-queue',
            'lease_id' => $lease['lease_id'],
            'actor' => 'agent-z',
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('task_packet_not_found', $result['reason']);
    }

    public function test_returns_full_envelope_when_packet_and_lease_match(): void
    {
        [$queue, $leases] = $this->wirings();
        $packet = $this->seedTaskPacket($queue, 'AIP-full', objective: 'Implementar contrato XYZ');
        $lease = $leases->claim($packet['task_packet_id'], 'agent-full', $this->scope(['app/Foo.php']));

        $service = new AgentControlPlaneOneShotWorkerPacketService($leases, $queue);
        $result = $service->generate([
            'task_packet_id' => $packet['task_packet_id'],
            'lease_id' => $lease['lease_id'],
            'actor' => 'agent-full',
        ]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_one_shot_worker_packet.v1',
            $result['schema_version'],
        );
        $this->assertSame('claimed_task', $result['mode']);
        $this->assertSame('agent-full', $result['actor']);
        $this->assertSame($packet['task_packet_id'], $result['task_packet_id']);
        $this->assertSame($lease['lease_id'], $result['lease_id']);
        $this->assertNotEmpty($result['worker_prompt_full']);
        $this->assertNotEmpty($result['worker_prompt_goal_short']);
        $this->assertStringContainsString('Implementar contrato XYZ', $result['worker_prompt_full']);
        $this->assertContains('app/Foo.php', $result['allowed_files']);
        $this->assertNotEmpty($result['required_docs']);
        $this->assertNotEmpty($result['acceptance_criteria']);
        $this->assertNotEmpty($result['required_tests']);
        $this->assertArrayHasKey('evidence_contract', $result);
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_worker_resumption_contract.v1',
            data_get($result, 'resumption_contract.schema_version'),
        );
        $this->assertFalse((bool) data_get($result, 'resumption_contract.can_resume_without_new_lease'));
        $this->assertTrue((bool) data_get($result, 'resumption_contract.resume_requires_active_lease'));
        $this->assertStringContainsString(
            '--agent-control-plane-task-lease-recovery-status',
            data_get($result, 'resumption_contract.resume_commands.inspect_or_recover_current_packet'),
        );
    }

    public function test_prompt_includes_completion_command_with_packet_and_lease(): void
    {
        [$queue, $leases] = $this->wirings();
        $packet = $this->seedTaskPacket($queue, 'AIP-complete');
        $lease = $leases->claim($packet['task_packet_id'], 'agent-c', $this->scope(['app/X.php']));

        $service = new AgentControlPlaneOneShotWorkerPacketService($leases, $queue);
        $result = $service->generate([
            'task_packet_id' => $packet['task_packet_id'],
            'lease_id' => $lease['lease_id'],
            'actor' => 'agent-c',
        ]);

        $this->assertStringContainsString('--packet='.$packet['task_packet_id'], $result['completion_command']);
        $this->assertStringContainsString('--lease-id='.$lease['lease_id'], $result['completion_command']);
        $this->assertStringContainsString('agent-c', $result['completion_command']);
        $this->assertStringContainsString($result['completion_command'], $result['worker_prompt_full']);
        $this->assertStringContainsString('Se você for interrompido ou a lease expirar', $result['worker_prompt_full']);
        $this->assertStringContainsString('--agent-control-plane-task-lease-recovery-status', $result['worker_prompt_full']);
        $this->assertStringContainsString('--agent-control-plane-terminal-worker-bootstrap-status', $result['worker_prompt_full']);
    }

    public function test_prompt_includes_preserve_worktree_and_forbidden_axes(): void
    {
        [$queue, $leases] = $this->wirings();
        $packet = $this->seedTaskPacket($queue, 'AIP-preserve');
        $lease = $leases->claim($packet['task_packet_id'], 'agent-p', $this->scope(['app/P.php']));

        $service = new AgentControlPlaneOneShotWorkerPacketService($leases, $queue);
        $result = $service->generate([
            'task_packet_id' => $packet['task_packet_id'],
            'lease_id' => $lease['lease_id'],
            'actor' => 'agent-p',
        ]);

        $prompt = $result['worker_prompt_full'];
        $this->assertStringContainsString('Preserve a worktree', $prompt);
        $this->assertStringContainsString('git reset --hard', $prompt);
        $this->assertStringContainsString('app/Services/Ai/Voice/**', $prompt);
        $this->assertStringContainsString('external_rivals_certification', $prompt);
    }

    public function test_hash_is_deterministic_for_same_input(): void
    {
        [$queue, $leases] = $this->wirings();
        $packet = $this->seedTaskPacket($queue, 'AIP-det');
        $lease = $leases->claim($packet['task_packet_id'], 'agent-d', $this->scope(['app/D.php']));

        $service = new AgentControlPlaneOneShotWorkerPacketService($leases, $queue);
        $first = $service->generate([
            'task_packet_id' => $packet['task_packet_id'],
            'lease_id' => $lease['lease_id'],
            'actor' => 'agent-d',
        ]);
        $second = $service->generate([
            'task_packet_id' => $packet['task_packet_id'],
            'lease_id' => $lease['lease_id'],
            'actor' => 'agent-d',
        ]);

        $this->assertNotEmpty($first['one_shot_packet_hash']);
        $this->assertSame($first['one_shot_packet_hash'], $second['one_shot_packet_hash']);
    }

    public function test_runtime_safety_flags_are_all_false(): void
    {
        [$queue, $leases] = $this->wirings();
        $packet = $this->seedTaskPacket($queue, 'AIP-safety');
        $lease = $leases->claim($packet['task_packet_id'], 'agent-s', $this->scope(['app/S.php']));

        $service = new AgentControlPlaneOneShotWorkerPacketService($leases, $queue);
        $result = $service->generate([
            'task_packet_id' => $packet['task_packet_id'],
            'lease_id' => $lease['lease_id'],
            'actor' => 'agent-s',
        ]);

        foreach ([
            'runtime_execution_allowed',
            'dispatch_allowed',
            'provider_call_allowed',
            'token_spend_allowed',
            'self_programming_allowed',
            'ledger_write_allowed',
            'completion_real_allowed',
            'external_rivals_unlock_allowed',
        ] as $flag) {
            $this->assertFalse($result['runtime_safety'][$flag], "{$flag} must be false");
        }
    }

    public function test_cli_status_returns_json_envelope(): void
    {
        [$queue, $leases] = $this->wirings();
        $packet = $this->seedTaskPacket($queue, 'AIP-cli');
        $lease = $leases->claim($packet['task_packet_id'], 'agent-cli', $this->scope(['app/Cli.php']));

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-one-shot-worker-packet-status' => true,
            '--packet' => $packet['task_packet_id'],
            '--lease-id' => $lease['lease_id'],
            '--actor' => 'agent-cli',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($payload);
        $inner = $payload['agent_control_plane_one_shot_worker_packet'] ?? null;
        $this->assertIsArray($inner, 'CLI must surface inner one-shot packet');
        $this->assertSame('ok', $inner['status']);
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_one_shot_worker_packet.v1',
            $inner['schema_version'],
        );
        $this->assertSame($packet['task_packet_id'], $inner['task_packet_id']);
        $this->assertSame($lease['lease_id'], $inner['lease_id']);
        $this->assertNotEmpty($inner['worker_prompt_full']);
        $this->assertNotEmpty($inner['one_shot_packet_hash']);
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_worker_resumption_contract.v1',
            data_get($inner, 'resumption_contract.schema_version'),
        );
    }

    // ---------- helpers ----------

    /**
     * @return array{0:AgentControlPlaneTaskPacketQueueRepository,1:AgentControlPlaneClaimLeaseRepository}
     */
    private function wirings(): array
    {
        return [new AgentControlPlaneTaskPacketQueueRepository, new AgentControlPlaneClaimLeaseRepository];
    }

    private function makeService(): AgentControlPlaneOneShotWorkerPacketService
    {
        [$queue, $leases] = $this->wirings();

        return new AgentControlPlaneOneShotWorkerPacketService($leases, $queue);
    }

    /**
     * @return array<string,mixed>
     */
    private function seedTaskPacket(
        AgentControlPlaneTaskPacketQueueRepository $queue,
        string $packetId,
        string $objective = 'Implementar a próxima fatia canônica',
    ): array {
        $packet = [
            'task_packet_id' => $packetId,
            'task_packet_hash' => hash('sha256', $packetId),
            'objective' => $objective,
            'rationale' => 'Próximo passo no roadmap canônico do Self-Construction OS.',
            'lane' => 'self_construction',
            'allowed_files' => [
                'app/Foo.php',
                'app/Bar.php',
            ],
            'forbidden_files' => [
                'app/Services/Ai/Programming/ForgeRivals/**',
            ],
            'required_docs' => [
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'acceptance_criteria' => [
                'service_emits_schema_v1',
                'tests_green',
            ],
            'required_tests' => [
                'php artisan test --filter=ScopedSuite',
            ],
        ];
        $enqueueResult = $queue->enqueue($packet);
        $this->assertSame('ok', $enqueueResult['status']);

        return $packet;
    }

    /**
     * @param  list<string>  $writeSet
     * @return array<string,mixed>
     */
    private function scope(array $writeSet): array
    {
        return [
            'write_set' => $writeSet,
            'read_set' => $writeSet,
            'scope_lock_plan_hash' => 'test_scope_lock_one_shot',
        ];
    }
}
