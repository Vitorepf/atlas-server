<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneChainIntegrityAuditService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneDeterministicChainReplayService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneMultiSnapshotComparisonService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReplaySnapshotStore;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneMultiSnapshotComparisonTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_compare_returns_schema_v1(): void
    {
        $payload = $this->newService()->compare(['include_fresh_replay' => false]);
        $this->assertSame(AgentControlPlaneMultiSnapshotComparisonService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(AgentControlPlaneMultiSnapshotComparisonService::MODE, $payload['mode']);
    }

    public function test_no_snapshots_status_no_snapshots(): void
    {
        $payload = $this->newService()->compare(['include_fresh_replay' => false]);
        $this->assertSame('no_snapshots', $payload['status']);
        $this->assertSame('no_snapshots', $payload['trend_status']);
        $this->assertSame(0, (int) $payload['snapshot_count']);
    }

    public function test_single_point_status_when_one_snapshot(): void
    {
        $payload = $this->newService()->compare(['include_fresh_replay' => true]);
        $this->assertSame('available', $payload['status']);
        $this->assertSame('single_point', $payload['trend_status']);
        $this->assertSame(1, (int) $payload['snapshot_count']);
    }

    public function test_two_identical_replays_unchanged(): void
    {
        $store = $this->newStore();
        $replay = $this->freshReplay();
        $store->put($replay);
        $store->put($replay);

        $payload = $this->newService()->compare(['include_fresh_replay' => false]);
        $this->assertContains($payload['trend_status'], ['stable', 'single_point']);
        $this->assertGreaterThanOrEqual(2, (int) $payload['snapshot_count']);
    }

    public function test_pointer_timeline_present(): void
    {
        $store = $this->newStore();
        $store->put($this->freshReplay());
        $payload = $this->newService()->compare(['include_fresh_replay' => false]);
        $this->assertNotEmpty($payload['pointer_timeline']);
        $this->assertArrayHasKey('pointer', $payload['pointer_timeline'][0]);
    }

    public function test_violation_timeline_present(): void
    {
        $store = $this->newStore();
        $store->put($this->freshReplay());
        $payload = $this->newService()->compare(['include_fresh_replay' => false]);
        $this->assertNotEmpty($payload['violation_timeline']);
        $this->assertArrayHasKey('count', $payload['violation_timeline'][0]);
    }

    public function test_runtime_safety_timeline_present(): void
    {
        $store = $this->newStore();
        $store->put($this->freshReplay());
        $payload = $this->newService()->compare(['include_fresh_replay' => false]);
        $this->assertNotEmpty($payload['runtime_safety_timeline']);
        $this->assertArrayHasKey('all_false', $payload['runtime_safety_timeline'][0]);
    }

    public function test_coverage_timeline_present(): void
    {
        $store = $this->newStore();
        $store->put($this->freshReplay());
        $payload = $this->newService()->compare(['include_fresh_replay' => false]);
        $this->assertNotEmpty($payload['coverage_timeline']);
        $this->assertArrayHasKey('replayed_slice_count', $payload['coverage_timeline'][0]);
    }

    public function test_warning_timeline_present(): void
    {
        $store = $this->newStore();
        $store->put($this->freshReplay());
        $payload = $this->newService()->compare(['include_fresh_replay' => false]);
        $this->assertNotEmpty($payload['warning_timeline']);
    }

    public function test_regression_window_detected_via_snapshot_with_runtime_safety_drop(): void
    {
        $store = $this->newStore();
        $first = $this->freshReplay();
        $first['runtime_safety']['runtime_safety_all_false'] = true;
        $store->put($first);
        $second = $this->freshReplay();
        $second['runtime_safety']['runtime_safety_all_false'] = false;
        $store->put($second);
        $payload = $this->newService()->compare(['include_fresh_replay' => false]);
        $this->assertGreaterThanOrEqual(1, (int) $payload['regression_window_count']);
        $this->assertSame('regression_detected', $payload['trend_status']);
    }

    public function test_improvement_window_detected_via_warning_drop(): void
    {
        $store = $this->newStore();
        $first = $this->freshReplay();
        $existingWarnings = (array) data_get($first, 'warnings', []);
        $first['warnings'] = array_merge($existingWarnings, ['warn1', 'warn2', 'warn3']);
        $store->put($first);
        $second = $this->freshReplay();
        // freshReplay returns the canonical payload without our synthetic warnings
        $store->put($second);
        $payload = $this->newService()->compare(['include_fresh_replay' => false]);
        $this->assertGreaterThanOrEqual(1, (int) $payload['improvement_window_count']);
    }

    public function test_trend_hash_stable_for_same_state(): void
    {
        $store = $this->newStore();
        $store->put($this->freshReplay());
        $svc = $this->newService();
        $a = $svc->compare(['include_fresh_replay' => false]);
        $b = $svc->compare(['include_fresh_replay' => false]);
        $this->assertSame($a['trend_hash'], $b['trend_hash']);
        $this->assertNotSame($a['comparison_id'], $b['comparison_id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $a['trend_hash']);
    }

    public function test_unique_deterministic_hash_count(): void
    {
        $store = $this->newStore();
        $store->put($this->freshReplay());
        $store->put($this->freshReplay());
        $payload = $this->newService()->compare(['include_fresh_replay' => false]);
        $this->assertGreaterThanOrEqual(1, (int) $payload['unique_deterministic_hash_count']);
    }

    public function test_max_snapshots_option(): void
    {
        $store = $this->newStore();
        for ($i = 0; $i < 4; $i++) {
            $store->put($this->freshReplay());
        }
        $payload = $this->newService()->compare(['include_fresh_replay' => false, 'max_snapshots' => 2]);
        $this->assertSame(2, (int) $payload['snapshot_count']);
    }

    public function test_compare_is_read_only(): void
    {
        $payload = $this->newService()->compare(['include_fresh_replay' => false]);
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

    public function test_compare_does_not_advance_pointer(): void
    {
        $beforePointer = $this->controlPlanePointer();
        $this->newService()->compare(['include_fresh_replay' => false]);
        $afterPointer = $this->controlPlanePointer();

        $this->assertSame($beforePointer, $afterPointer);
    }

    public function test_compare_status_cli_works(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-multi-snapshot-comparison-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            'atlas.self_construction_agent_control_plane_multi_snapshot_comparison_status.v1',
            $payload['schema_version'],
        );
        $this->assertContains(data_get($payload, 'agent_control_plane_multi_snapshot_comparison_status.status'), ['available', 'no_snapshots']);
    }

    public function test_compare_quartet_cli_works(): void
    {
        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-multi-snapshot-comparison-{$stage}" => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame(
                "atlas.self_construction_agent_control_plane_multi_snapshot_comparison_{$stageKey}.v1",
                $payload['schema_version'],
            );
        }
    }

    private function newService(): AgentControlPlaneMultiSnapshotComparisonService
    {
        $readiness = app(AtlasSelfConstructionReadinessService::class);
        $audit = new AgentControlPlaneChainIntegrityAuditService($readiness);
        $replay = new AgentControlPlaneDeterministicChainReplayService($audit, $readiness);
        $store = $this->newStore();

        return new AgentControlPlaneMultiSnapshotComparisonService($store, $replay);
    }

    private function newStore(): AgentControlPlaneReplaySnapshotStore
    {
        return new AgentControlPlaneReplaySnapshotStore('local');
    }

    /**
     * @return array<string, mixed>
     */
    private function freshReplay(): array
    {
        $readiness = app(AtlasSelfConstructionReadinessService::class);
        $audit = new AgentControlPlaneChainIntegrityAuditService($readiness);

        return (new AgentControlPlaneDeterministicChainReplayService($audit, $readiness))->replay();
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
