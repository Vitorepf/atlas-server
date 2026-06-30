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

    public function test_guard_counts_only_monitored_storage_prefixes_without_root_scan(): void
    {
        Storage::disk('local')->put('unrelated-heavy-area/file-a.json', '{}');
        Storage::disk('local')->put('atlas/self-construction/replay-snapshots/allowed.json', '{}');
        Storage::disk('local')->put('atlas/self-construction/operator-submissions/runtime-promotion.json', '{}');

        $guard = $this->newGuard()->guard();

        $this->assertSame(1, (int) data_get($guard, 'before.storage_outside_prefix_count'));
        $this->assertContains('atlas/self-construction/operator-submissions', data_get($guard, 'before.monitored_storage_prefixes'));
        $this->assertFalse((bool) $guard['storage_mutated']);
        $this->assertSame('passed', $guard['status']);
    }

    public function test_storage_scan_uses_counter_instead_of_path_hashmap(): void
    {
        // Synthesises a moderately large monitored workspace and confirms the
        // mutation guard returns the expected count without OOM under a
        // bounded memory budget. We use a modest fixture count to keep the
        // suite fast; the production hard cap of 50_000 protects the audit
        // under PHP's default 128M memory_limit.
        for ($i = 0; $i < 1000; $i++) {
            Storage::disk('local')->put(
                "atlas/self-construction/operator-submissions/saturated/draft-{$i}.json",
                '{}',
            );
        }

        $guard = $this->newGuard()->guard();

        $this->assertSame(1000, (int) data_get($guard, 'before.storage_outside_prefix_count'));
        $this->assertSame(1000, (int) data_get($guard, 'after.storage_outside_prefix_count'));
        $this->assertFalse((bool) $guard['storage_mutated']);
        $this->assertSame('passed', $guard['status']);
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

    // ── evaluateMutations() ──────────────────────────────────────────────────

    public function test_removed_critical_check_survives(): void
    {
        $result = $this->newGuard()->evaluateMutations(['mutations' => [
            ['mutation_id' => 'm1', 'target_check' => 'scope_check', 'kind' => 'removed', 'still_blocking' => false],
        ]]);

        $this->assertFalse($result['results'][0]['killed']);
        $this->assertTrue($result['results'][0]['survived']);
        $this->assertNotEmpty($result['results'][0]['required_test_gap']);
        $this->assertSame(1, $result['survived_count']);
        $this->assertSame(0, $result['killed_count']);
    }

    public function test_weakened_to_advisory_check_survives(): void
    {
        $result = $this->newGuard()->evaluateMutations(['mutations' => [
            ['mutation_id' => 'm1', 'target_check' => 'proof_check', 'kind' => 'weakened_to_advisory', 'still_blocking' => false],
        ]]);

        $this->assertTrue($result['results'][0]['survived']);
        $this->assertStringContainsString('advisory', $result['results'][0]['required_test_gap']);
    }

    public function test_still_blocking_critical_check_is_killed(): void
    {
        $result = $this->newGuard()->evaluateMutations(['mutations' => [
            ['mutation_id' => 'm1', 'target_check' => 'freshness_check', 'kind' => 'removed', 'still_blocking' => true],
        ]]);

        $this->assertTrue($result['results'][0]['killed']);
        $this->assertFalse($result['results'][0]['survived']);
        $this->assertNull($result['results'][0]['required_test_gap']);
        $this->assertSame(1, $result['killed_count']);
    }

    public function test_harmless_refactor_excluded_from_killed_and_survived(): void
    {
        $result = $this->newGuard()->evaluateMutations(['mutations' => [
            ['mutation_id' => 'm1', 'target_check' => 'conflict_check', 'kind' => 'harmless_refactor', 'still_blocking' => true],
        ]]);

        $this->assertTrue($result['results'][0]['harmless_refactor']);
        $this->assertFalse($result['results'][0]['killed']);
        $this->assertFalse($result['results'][0]['survived']);
        $this->assertSame(1, $result['harmless_refactor_count']);
        $this->assertSame(0, $result['killed_count']);
        $this->assertSame(0, $result['survived_count']);
    }

    public function test_all_five_critical_checks_covered(): void
    {
        $mutations = array_map(
            fn (string $check) => ['mutation_id' => "m-$check", 'target_check' => $check, 'kind' => 'removed', 'still_blocking' => false],
            AgentControlPlaneCertificationMutationGuard::CRITICAL_CHECK_IDS,
        );

        $result = $this->newGuard()->evaluateMutations(['mutations' => $mutations]);

        $this->assertCount(5, $result['results']);
        $this->assertSame(5, $result['survived_count']);
        $this->assertSame(0.0, $result['kill_ratio']);
    }

    public function test_kill_ratio_computed_correctly(): void
    {
        $result = $this->newGuard()->evaluateMutations(['mutations' => [
            ['mutation_id' => 'm1', 'target_check' => 'scope_check', 'kind' => 'removed', 'still_blocking' => true],
            ['mutation_id' => 'm2', 'target_check' => 'proof_check', 'kind' => 'removed', 'still_blocking' => false],
        ]]);

        $this->assertSame(0.5, $result['kill_ratio']);
    }
}
