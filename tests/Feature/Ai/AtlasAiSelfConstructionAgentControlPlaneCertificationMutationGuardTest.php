<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneCertificationMutationGuard;
use App\Services\Ai\SelfConstruction\AgentControlPlaneChainIntegrityAuditService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneDeterministicChainReplayService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReplaySnapshotStore;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneCertificationMutationGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_guard_returns_schema_v1(): void
    {
        $guard = $this->newGuard()->guard();
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_certification_mutation_guard.v1',
            $guard['schema_version'],
        );
        $this->assertSame('read_only_agent_control_plane_certification_mutation_guard', $guard['mode']);
    }

    public function test_guard_passed_for_no_op(): void
    {
        $guard = $this->newGuard()->guard();
        $this->assertSame('passed', $guard['status']);
        $this->assertTrue((bool) $guard['guard_passed']);
        $this->assertSame(0, (int) $guard['mutation_count']);
        $this->assertEmpty($guard['forbidden_mutations']);
    }

    public function test_guard_passed_for_read_only_replay_call(): void
    {
        $readiness = app(AtlasSelfConstructionReadinessService::class);
        $audit = new AgentControlPlaneChainIntegrityAuditService($readiness);
        $replayService = new AgentControlPlaneDeterministicChainReplayService($audit, $readiness);

        $guard = $this->newGuard()->guard(static function () use ($replayService) {
            $replayService->replay();
        });

        $this->assertSame('passed', $guard['status']);
        $this->assertTrue((bool) $guard['guard_passed']);
    }

    public function test_pointer_not_mutated(): void
    {
        $guard = $this->newGuard()->guard();
        $this->assertFalse((bool) $guard['pointer_mutated']);
    }

    public function test_next_build_slices_not_mutated(): void
    {
        $guard = $this->newGuard()->guard();
        $this->assertSame($guard['before']['next_build_slices'], $guard['after']['next_build_slices']);
    }

    public function test_not_yet_runtime_capable_not_mutated(): void
    {
        $guard = $this->newGuard()->guard();
        $this->assertSame($guard['before']['not_yet_runtime_capable'], $guard['after']['not_yet_runtime_capable']);
    }

    public function test_runtime_safety_not_mutated(): void
    {
        $guard = $this->newGuard()->guard();
        $this->assertFalse((bool) $guard['runtime_safety_mutated']);
    }

    public function test_ledger_not_mutated(): void
    {
        $guard = $this->newGuard()->guard();
        $this->assertFalse((bool) $guard['ledger_mutated']);
    }

    public function test_storage_not_mutated_for_read_only_services(): void
    {
        $guard = $this->newGuard()->guard();
        $this->assertFalse((bool) $guard['storage_mutated']);
    }

    public function test_guard_detects_pointer_mutation_via_override(): void
    {
        $guard = $this->newGuard()->guard(null, [
            'after_override' => ['pointer' => 'synthetic_advanced_pointer'],
        ]);

        $this->assertTrue((bool) $guard['pointer_mutated']);
        $this->assertSame('failed', $guard['status']);
        $this->assertContains('pointer_mutated_from_'.$guard['before']['pointer'].'_to_synthetic_advanced_pointer', $guard['forbidden_mutations']);
    }

    public function test_guard_detects_runtime_safety_mutation_via_override(): void
    {
        $guard = $this->newGuard()->guard(null, [
            'after_override' => ['runtime_safety_all_false' => false],
        ]);

        $this->assertTrue((bool) $guard['runtime_safety_mutated']);
        $this->assertSame('failed', $guard['status']);
    }

    public function test_guard_detects_ledger_mutation_via_override(): void
    {
        $guard = $this->newGuard()->guard(null, [
            'after_override' => ['ledger_count' => 999],
        ]);

        $this->assertTrue((bool) $guard['ledger_mutated']);
        $this->assertSame('failed', $guard['status']);
    }

    public function test_guard_detects_forbidden_storage_mutation_via_override(): void
    {
        $guard = $this->newGuard()->guard(null, [
            'after_override' => ['storage_outside_prefix_count' => 1],
        ]);

        $this->assertTrue((bool) $guard['storage_mutated']);
        $this->assertSame('failed', $guard['status']);
    }

    public function test_guard_ignores_storage_writes_outside_self_construction_scope(): void
    {
        $guard = $this->newGuard()->guard(static function () {
            Storage::disk('local')->put('forge-rivals-corpus/synthetic/receipt.json', '{}');
        });

        $this->assertFalse((bool) $guard['storage_mutated']);
        $this->assertSame('passed', $guard['status']);
        $this->assertSame(0, (int) $guard['mutation_count']);
    }

    public function test_guard_detects_self_construction_storage_writes_outside_allowed_snapshot_prefix(): void
    {
        $guard = $this->newGuard()->guard(static function () {
            Storage::disk('local')->put('atlas/self-construction/operator-submissions/synthetic.json', '{}');
        });

        $this->assertTrue((bool) $guard['storage_mutated']);
        $this->assertSame('failed', $guard['status']);
        $this->assertContains(
            'storage_outside_allowed_prefix_changed_from_0_to_1',
            $guard['forbidden_mutations'],
        );
    }

    public function test_guard_allows_snapshot_prefix_mutation_only_when_configured(): void
    {
        $guard = $this->newGuard()->guard(null, [
            'allow_snapshot_mutation' => true,
            'after_override' => ['snapshot_entry_count' => 5],
        ]);

        $this->assertTrue((bool) $guard['snapshot_registry_mutated']);
        $this->assertSame('passed', $guard['status']);
        $this->assertContains('snapshot_registry_count_changed_from_0_to_5', $guard['allowed_mutations']);
    }

    public function test_guard_blocks_snapshot_mutation_when_not_authorized(): void
    {
        $guard = $this->newGuard()->guard(null, [
            'allow_snapshot_mutation' => false,
            'after_override' => ['snapshot_entry_count' => 1],
        ]);

        $this->assertSame('failed', $guard['status']);
        $this->assertContains('snapshot_registry_count_changed_without_authorization', $guard['forbidden_mutations']);
    }

    public function test_mutation_hash_is_sha256(): void
    {
        $guard = $this->newGuard()->guard();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $guard['mutation_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $guard['before_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $guard['after_hash']);
    }

    public function test_guard_status_cli_works(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-certification-mutation-guard-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            'atlas.self_construction_agent_control_plane_certification_mutation_guard_status.v1',
            $payload['schema_version'],
        );
        $this->assertSame('passed', data_get($payload, 'agent_control_plane_certification_mutation_guard_status.status'));
        $this->assertTrue((bool) data_get($payload, 'agent_control_plane_certification_mutation_guard_status.guard_passed'));
    }

    public function test_guard_quartet_cli_works(): void
    {
        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-certification-mutation-guard-{$stage}" => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame(
                "atlas.self_construction_agent_control_plane_certification_mutation_guard_{$stageKey}.v1",
                $payload['schema_version'],
            );
        }
    }

    public function test_guard_is_read_only(): void
    {
        $guard = $this->newGuard()->guard();
        $this->assertTrue((bool) $guard['read_only']);
        $this->assertFalse((bool) $guard['execution_allowed']);
        $this->assertFalse((bool) $guard['dispatch_allowed']);
        $this->assertFalse((bool) $guard['ledger_write_allowed']);
        $this->assertFalse((bool) $guard['runtime_write_allowed']);
    }

    public function test_guard_payload_shape(): void
    {
        $guard = $this->newGuard()->guard();
        foreach (['before', 'after', 'before_hash', 'after_hash', 'mutated', 'mutation_count', 'allowed_mutations', 'forbidden_mutations', 'pointer_mutated', 'runtime_safety_mutated', 'ledger_mutated', 'storage_mutated', 'snapshot_registry_mutated', 'mutation_hash', 'guard_passed', 'options_applied', 'non_execution_guarantees'] as $key) {
            $this->assertArrayHasKey($key, $guard);
        }
    }

    private function newGuard(): AgentControlPlaneCertificationMutationGuard
    {
        $readiness = app(AtlasSelfConstructionReadinessService::class);
        $audit = new AgentControlPlaneChainIntegrityAuditService($readiness);
        $replay = new AgentControlPlaneDeterministicChainReplayService($audit, $readiness);
        $store = new AgentControlPlaneReplaySnapshotStore('local');

        return new AgentControlPlaneCertificationMutationGuard($readiness, $replay, $store);
    }
}
