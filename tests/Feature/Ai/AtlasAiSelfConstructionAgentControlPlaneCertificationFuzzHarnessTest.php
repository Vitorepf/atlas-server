<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneCertificationFuzzHarness;
use App\Services\Ai\SelfConstruction\AgentControlPlaneChainIntegrityAuditService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneDeterministicChainReplayService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReplayDiffService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReplaySnapshotStore;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneCertificationFuzzHarnessTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $cached = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->cached = null;
    }

    public function test_fuzz_returns_schema_v1(): void
    {
        $payload = $this->fuzzResult();
        $this->assertSame(AgentControlPlaneCertificationFuzzHarness::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(AgentControlPlaneCertificationFuzzHarness::MODE, $payload['mode']);
    }

    public function test_seed_default_value(): void
    {
        $payload = $this->fuzzResult();
        $this->assertSame(1337, $payload['seed']);
    }

    public function test_iteration_count_honored(): void
    {
        $payload = $this->newService()->run(['iteration_count' => 6]);
        $this->assertSame(6, $payload['iteration_count']);
        $this->assertCount(6, $payload['fuzz_cases']);
    }

    public function test_default_iteration_count(): void
    {
        $payload = $this->fuzzResult();
        $this->assertSame(8, $payload['iteration_count']);
    }

    public function test_all_mutation_types_listed(): void
    {
        $payload = $this->fuzzResult();
        foreach (AgentControlPlaneCertificationFuzzHarness::MUTATION_TYPES as $t) {
            $this->assertContains($t, $payload['mutation_types']);
        }
    }

    public function test_all_expected_detected(): void
    {
        $payload = $this->fuzzResult();
        $this->assertTrue((bool) $payload['all_expected_detected']);
        $this->assertSame(0, (int) $payload['missed_count']);
    }

    public function test_detected_count_matches_iterations(): void
    {
        $payload = $this->fuzzResult();
        $this->assertSame((int) $payload['iteration_count'], (int) $payload['detected_count']);
    }

    public function test_fuzz_hash_stable_for_same_seed(): void
    {
        $a = $this->newService()->run(['seed' => 4242, 'iteration_count' => 6, 'mutation_types' => ['flip_runtime_flag']]);
        $b = $this->newService()->run(['seed' => 4242, 'iteration_count' => 6, 'mutation_types' => ['flip_runtime_flag']]);
        $this->assertSame($a['fuzz_hash'], $b['fuzz_hash']);
        $this->assertNotSame($a['fuzz_id'], $b['fuzz_id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $a['fuzz_hash']);
    }

    public function test_runtime_flag_mutation_detected(): void
    {
        $payload = $this->newService()->run(['seed' => 1, 'iteration_count' => 4, 'mutation_types' => ['flip_runtime_flag']]);
        $this->assertSame(0, (int) $payload['missed_count']);
    }

    public function test_break_random_edge_detected(): void
    {
        $payload = $this->newService()->run(['seed' => 2, 'iteration_count' => 4, 'mutation_types' => ['break_random_edge']]);
        $this->assertSame(0, (int) $payload['missed_count']);
    }

    public function test_corrupt_doc_anchor_detected(): void
    {
        $payload = $this->newService()->run(['seed' => 3, 'iteration_count' => 4, 'mutation_types' => ['corrupt_doc_anchor']]);
        $this->assertSame(0, (int) $payload['missed_count']);
    }

    public function test_remove_random_capability_detected(): void
    {
        $payload = $this->newService()->run(['seed' => 5, 'iteration_count' => 4, 'mutation_types' => ['remove_random_capability']]);
        $this->assertSame(0, (int) $payload['missed_count']);
    }

    public function test_remove_random_cli_option_detected(): void
    {
        $payload = $this->newService()->run(['seed' => 6, 'iteration_count' => 4, 'mutation_types' => ['remove_random_cli_option']]);
        $this->assertSame(0, (int) $payload['missed_count']);
    }

    public function test_corrupt_next_build_slices_detected(): void
    {
        $payload = $this->newService()->run(['seed' => 7, 'iteration_count' => 4, 'mutation_types' => ['corrupt_next_build_slices']]);
        $this->assertSame(0, (int) $payload['missed_count']);
    }

    public function test_corrupt_not_yet_runtime_capable_detected(): void
    {
        $payload = $this->newService()->run(['seed' => 8, 'iteration_count' => 4, 'mutation_types' => ['corrupt_not_yet_runtime_capable']]);
        $this->assertSame(0, (int) $payload['missed_count']);
    }

    public function test_corrupt_terminal_horizon_detected(): void
    {
        $payload = $this->newService()->run(['seed' => 9, 'iteration_count' => 3, 'mutation_types' => ['corrupt_terminal_horizon']]);
        $this->assertSame(0, (int) $payload['missed_count']);
    }

    public function test_corrupt_cycle_reentry_detected(): void
    {
        $payload = $this->newService()->run(['seed' => 10, 'iteration_count' => 3, 'mutation_types' => ['corrupt_cycle_reentry']]);
        $this->assertSame(0, (int) $payload['missed_count']);
    }

    public function test_corrupt_replay_hash_detected(): void
    {
        $payload = $this->newService()->run(['seed' => 11, 'iteration_count' => 3, 'mutation_types' => ['corrupt_replay_hash']]);
        $this->assertSame(0, (int) $payload['missed_count']);
    }

    public function test_corrupt_proof_bundle_hash_detected(): void
    {
        $payload = $this->newService()->run(['seed' => 12, 'iteration_count' => 3, 'mutation_types' => ['corrupt_proof_bundle_hash']]);
        $this->assertSame(0, (int) $payload['missed_count']);
    }

    public function test_duplicate_random_capability_detected(): void
    {
        $payload = $this->newService()->run(['seed' => 13, 'iteration_count' => 3, 'mutation_types' => ['duplicate_random_capability']]);
        $this->assertSame(0, (int) $payload['missed_count']);
    }

    public function test_fuzz_cases_have_required_fields(): void
    {
        $payload = $this->fuzzResult();
        foreach ($payload['fuzz_cases'] as $case) {
            $this->assertArrayHasKey('iteration', $case);
            $this->assertArrayHasKey('mutation_type', $case);
            $this->assertArrayHasKey('detector', $case);
            $this->assertArrayHasKey('expected_detection', $case);
            $this->assertArrayHasKey('detected', $case);
            $this->assertArrayHasKey('observed_status', $case);
        }
    }

    public function test_fuzz_is_read_only(): void
    {
        $payload = $this->fuzzResult();
        $this->assertTrue((bool) $payload['read_only']);
        $this->assertFalse((bool) $payload['execution_allowed']);
        $this->assertFalse((bool) $payload['dispatch_allowed']);
        $this->assertFalse((bool) $payload['ledger_write_allowed']);
        $this->assertFalse((bool) $payload['runtime_write_allowed']);
        $this->assertFalse((bool) $payload['external_provider_call']);
        $this->assertFalse((bool) $payload['token_spend']);
        $this->assertFalse((bool) $payload['process_started']);
        $this->assertFalse((bool) $payload['self_programming_allowed']);
    }

    public function test_fuzz_does_not_advance_pointer(): void
    {
        $beforePointer = $this->controlPlanePointer();
        $this->newService()->run(['iteration_count' => 4]);
        $afterPointer = $this->controlPlanePointer();

        $this->assertSame($beforePointer, $afterPointer);
    }

    public function test_fuzz_status_cli_works(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-certification-fuzz-harness-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            'atlas.self_construction_agent_control_plane_certification_fuzz_harness_status.v1',
            $payload['schema_version'],
        );
        $this->assertSame('passed', data_get($payload, 'agent_control_plane_certification_fuzz_harness_status.status'));
        $this->assertTrue((bool) data_get($payload, 'agent_control_plane_certification_fuzz_harness_status.all_expected_detected'));
    }

    public function test_fuzz_quartet_cli_works(): void
    {
        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-certification-fuzz-harness-{$stage}" => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame(
                "atlas.self_construction_agent_control_plane_certification_fuzz_harness_{$stageKey}.v1",
                $payload['schema_version'],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fuzzResult(): array
    {
        if ($this->cached === null) {
            $this->cached = $this->newService()->run(['iteration_count' => 8]);
        }

        return $this->cached;
    }

    private function newService(): AgentControlPlaneCertificationFuzzHarness
    {
        $readiness = app(AtlasSelfConstructionReadinessService::class);
        $audit = new AgentControlPlaneChainIntegrityAuditService($readiness);
        $replay = new AgentControlPlaneDeterministicChainReplayService($audit, $readiness);
        $store = new AgentControlPlaneReplaySnapshotStore('local');
        $diff = new AgentControlPlaneReplayDiffService($store, $replay);

        return new AgentControlPlaneCertificationFuzzHarness($audit, $replay, $diff);
    }

    private function controlPlanePointer(): string
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        return (string) data_get($payload, 'control_plane.persistent_runtime.next_required_slice');
    }
}
