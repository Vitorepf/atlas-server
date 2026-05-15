<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneCertificationStatusBatchService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneCertificationStatusBatchTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $cached = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->cached = null;
    }

    public function test_batch_returns_schema_v1(): void
    {
        $payload = $this->batchResult();
        $this->assertSame(AgentControlPlaneCertificationStatusBatchService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(AgentControlPlaneCertificationStatusBatchService::MODE, $payload['mode']);
    }

    public function test_batch_checks_all_services(): void
    {
        $payload = $this->batchResult();
        $expectedKeys = ['chain_integrity_certification', 'deterministic_chain_replay', 'replay_snapshot_store', 'replay_diff', 'macro_sprint_promotion_gate', 'certification_baseline', 'certification_scenario_simulator', 'release_dossier', 'certification_mutation_guard', 'certification_evidence_query', 'certification_scenario_corpus', 'certification_fuzz_harness', 'multi_snapshot_comparison', 'release_dossier_exporter', 'certification_coverage_report'];
        $statusKeys = array_column($payload['statuses'], 'key');
        foreach ($expectedKeys as $expected) {
            $this->assertContains($expected, $statusKeys, "Status key '$expected' missing from batch");
        }
    }

    public function test_batch_passed_count_consistent(): void
    {
        $payload = $this->batchResult();
        $this->assertSame((int) $payload['checked_count'], (int) ($payload['passed_count'] + $payload['failed_count']));
        $this->assertContains($payload['status'], ['passed', 'failed']);
    }

    public function test_batch_hash_stable(): void
    {
        $svc = $this->newService();
        $a = $svc->run();
        $b = $svc->run();
        $this->assertSame($a['batch_hash'], $b['batch_hash']);
        $this->assertNotSame($a['batch_id'], $b['batch_id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $a['batch_hash']);
    }

    public function test_batch_status_per_projection(): void
    {
        $payload = $this->batchResult();
        foreach ($payload['statuses'] as $entry) {
            $this->assertArrayHasKey('key', $entry);
            $this->assertArrayHasKey('method', $entry);
            $this->assertArrayHasKey('status', $entry);
            $this->assertArrayHasKey('passed', $entry);
        }
    }

    public function test_no_shell_invocation(): void
    {
        $payload = $this->batchResult();
        $this->assertContains('status_batch_does_not_invoke_shell', $payload['non_execution_guarantees']);
    }

    public function test_batch_does_not_advance_pointer(): void
    {
        $beforePointer = $this->controlPlanePointer();
        $this->newService()->run();
        $afterPointer = $this->controlPlanePointer();

        $this->assertSame($beforePointer, $afterPointer);
    }

    public function test_batch_is_read_only(): void
    {
        $payload = $this->batchResult();
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

    public function test_batch_status_cli_works(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-certification-status-batch-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            'atlas.self_construction_agent_control_plane_certification_status_batch_status.v1',
            $payload['schema_version'],
        );
        $this->assertContains(data_get($payload, 'agent_control_plane_certification_status_batch_status.status'), ['passed', 'failed']);
        $this->assertGreaterThanOrEqual(15, (int) data_get($payload, 'agent_control_plane_certification_status_batch_status.checked_count'));
    }

    public function test_batch_quartet_cli_works(): void
    {
        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-certification-status-batch-{$stage}" => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame(
                "atlas.self_construction_agent_control_plane_certification_status_batch_{$stageKey}.v1",
                $payload['schema_version'],
            );
        }
    }

    public function test_skip_batch_self_default(): void
    {
        $payload = $this->batchResult();
        $statusKeys = array_column($payload['statuses'], 'key');
        $this->assertNotContains('certification_status_batch', $statusKeys, 'Status batch should not call itself recursively');
    }

    public function test_status_projections_constant(): void
    {
        $this->assertGreaterThanOrEqual(15, count(AgentControlPlaneCertificationStatusBatchService::STATUS_PROJECTIONS));
    }

    public function test_batch_does_not_invoke_runtime_or_provider(): void
    {
        $payload = $this->batchResult();
        $this->assertContains('status_batch_does_not_call_provider', $payload['non_execution_guarantees']);
        $this->assertContains('status_batch_does_not_invoke_adapter', $payload['non_execution_guarantees']);
        $this->assertContains('status_batch_does_not_dispatch_work', $payload['non_execution_guarantees']);
    }

    public function test_batch_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_control_plane_certification_status_batch.v1', AgentControlPlaneCertificationStatusBatchService::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_control_plane_certification_status_batch', AgentControlPlaneCertificationStatusBatchService::MODE);
    }

    public function test_each_status_carries_status_string(): void
    {
        $payload = $this->batchResult();
        foreach ($payload['statuses'] as $entry) {
            $this->assertNotEmpty($entry['status']);
            $this->assertIsString($entry['method']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function batchResult(): array
    {
        if ($this->cached === null) {
            $this->cached = $this->newService()->run();
        }

        return $this->cached;
    }

    private function newService(): AgentControlPlaneCertificationStatusBatchService
    {
        $readiness = app(AtlasSelfConstructionReadinessService::class);

        return new AgentControlPlaneCertificationStatusBatchService($readiness);
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
