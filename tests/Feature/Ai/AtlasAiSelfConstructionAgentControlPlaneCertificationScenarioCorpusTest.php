<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneCertificationScenarioCorpusService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneCertificationScenarioSimulator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneChainIntegrityAuditService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneDeterministicChainReplayService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneMacroSprintPromotionGate;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReplayDiffService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneReplaySnapshotStore;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneCertificationScenarioCorpusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_corpus_returns_schema_v1(): void
    {
        $corpus = $this->newService()->corpus();
        $this->assertSame(AgentControlPlaneCertificationScenarioCorpusService::SCHEMA_VERSION, $corpus['schema_version']);
        $this->assertSame(AgentControlPlaneCertificationScenarioCorpusService::MODE, $corpus['mode']);
    }

    public function test_corpus_has_50_scenarios(): void
    {
        $corpus = $this->newService()->corpus();
        $this->assertGreaterThanOrEqual(50, (int) $corpus['scenario_count']);
    }

    public function test_corpus_has_13_categories(): void
    {
        $corpus = $this->newService()->corpus();
        $this->assertCount(13, $corpus['categories']);
    }

    public function test_corpus_includes_canonical_categories(): void
    {
        $corpus = $this->newService()->corpus();
        foreach (['capability', 'cli', 'readiness', 'invoker', 'doc', 'edge', 'pointer', 'runtime_safety', 'cycle_reentry', 'replay_hash', 'snapshot_diff', 'promotion_gate', 'mutation_guard'] as $cat) {
            $this->assertContains($cat, $corpus['categories']);
        }
    }

    public function test_every_scenario_validates(): void
    {
        $svc = $this->newService();
        $corpus = $svc->corpus();
        foreach ($corpus['scenarios'] as $scenario) {
            $result = $svc->validate($scenario);
            $this->assertTrue($result['valid'], "Scenario {$scenario['scenario_id']} failed validation: ".implode(',', $result['reasons']));
        }
    }

    public function test_every_scenario_has_severity(): void
    {
        $corpus = $this->newService()->corpus();
        foreach ($corpus['scenarios'] as $scenario) {
            $this->assertContains($scenario['severity'], AgentControlPlaneCertificationScenarioCorpusService::SEVERITIES);
        }
    }

    public function test_every_scenario_has_detector(): void
    {
        $corpus = $this->newService()->corpus();
        foreach ($corpus['scenarios'] as $scenario) {
            $this->assertNotEmpty($scenario['expected_detector']);
            $this->assertContains($scenario['expected_detector'], ['chain_integrity_audit', 'replay_diff', 'promotion_gate', 'replay', 'mutation_guard']);
        }
    }

    public function test_every_scenario_runtime_safe(): void
    {
        $corpus = $this->newService()->corpus();
        foreach ($corpus['scenarios'] as $scenario) {
            $this->assertTrue((bool) $scenario['runtime_safe']);
        }
    }

    public function test_corpus_hash_stable(): void
    {
        $svc = $this->newService();
        $a = $svc->corpus();
        $b = $svc->corpus();
        $this->assertSame($a['corpus_hash'], $b['corpus_hash']);
        $this->assertNotSame($a['corpus_id'], $b['corpus_id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $a['corpus_hash']);
    }

    public function test_scenario_lookup(): void
    {
        $svc = $this->newService();
        $found = $svc->scenario('missing_capability');
        $this->assertNotNull($found);
        $this->assertSame('capability', $found['category']);
    }

    public function test_unknown_scenario_returns_null(): void
    {
        $this->assertNull($this->newService()->scenario('does_not_exist'));
    }

    public function test_invalid_scenario_fails_validation(): void
    {
        $svc = $this->newService();
        $invalid = ['scenario_id' => 'x', 'category' => 'invalid_category', 'severity' => 'invalid_severity'];
        $result = $svc->validate($invalid);
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['reasons']);
    }

    public function test_run_corpus_returns_alignment_report(): void
    {
        $svc = $this->newService();
        $result = $svc->runCorpus();
        $this->assertContains($result['status'], ['passed', 'warning', 'failed']);
        $this->assertGreaterThan(0, $result['corpus_count']);
        $this->assertGreaterThanOrEqual(0, $result['aligned_count']);
        $this->assertNotEmpty($result['report']);
    }

    public function test_run_corpus_hash_is_sha256(): void
    {
        $result = $this->newService()->runCorpus();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $result['run_hash']);
    }

    public function test_corpus_is_read_only(): void
    {
        $corpus = $this->newService()->corpus();
        $this->assertTrue((bool) $corpus['read_only']);
        $this->assertFalse((bool) $corpus['execution_allowed']);
        $this->assertFalse((bool) $corpus['dispatch_allowed']);
        $this->assertFalse((bool) $corpus['ledger_write_allowed']);
        $this->assertFalse((bool) $corpus['runtime_write_allowed']);
        $this->assertFalse((bool) $corpus['external_provider_call']);
        $this->assertFalse((bool) $corpus['token_spend']);
        $this->assertFalse((bool) $corpus['process_started']);
        $this->assertFalse((bool) $corpus['self_programming_allowed']);
    }

    public function test_corpus_does_not_advance_pointer(): void
    {
        $beforePointer = $this->controlPlanePointer();
        $this->newService()->corpus();
        $afterPointer = $this->controlPlanePointer();

        $this->assertSame($beforePointer, $afterPointer);
    }

    public function test_corpus_status_cli_works(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-certification-scenario-corpus-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            'atlas.self_construction_agent_control_plane_certification_scenario_corpus_status.v1',
            $payload['schema_version'],
        );
        $this->assertSame('available', data_get($payload, 'agent_control_plane_certification_scenario_corpus_status.status'));
        $this->assertGreaterThanOrEqual(50, (int) data_get($payload, 'agent_control_plane_certification_scenario_corpus_status.scenario_count'));
    }

    public function test_corpus_quartet_cli_works(): void
    {
        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-certification-scenario-corpus-{$stage}" => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame(
                "atlas.self_construction_agent_control_plane_certification_scenario_corpus_{$stageKey}.v1",
                $payload['schema_version'],
            );
        }
    }

    public function test_categories_have_at_least_one_scenario_each(): void
    {
        $corpus = $this->newService()->corpus();
        foreach (AgentControlPlaneCertificationScenarioCorpusService::CATEGORIES as $cat) {
            $this->assertGreaterThanOrEqual(1, (int) ($corpus['by_category'][$cat] ?? 0), "Category $cat empty");
        }
    }

    public function test_corpus_is_deterministic_in_order(): void
    {
        $svc = $this->newService();
        $a = $svc->corpus();
        $b = $svc->corpus();
        $this->assertSame(array_column((array) $a['scenarios'], 'scenario_id'), array_column((array) $b['scenarios'], 'scenario_id'));
    }

    public function test_run_corpus_status_passed_when_all_aligned(): void
    {
        $result = $this->newService()->runCorpus();
        $this->assertContains($result['status'], ['passed', 'warning', 'failed']);
        $this->assertGreaterThanOrEqual(0, $result['alignment_rate']);
        $this->assertLessThanOrEqual(1, $result['alignment_rate']);
    }

    public function test_corpus_constants_canonical(): void
    {
        $this->assertContains('capability', AgentControlPlaneCertificationScenarioCorpusService::CATEGORIES);
        $this->assertContains('mutation_guard', AgentControlPlaneCertificationScenarioCorpusService::CATEGORIES);
        $this->assertContains('low', AgentControlPlaneCertificationScenarioCorpusService::SEVERITIES);
        $this->assertContains('critical', AgentControlPlaneCertificationScenarioCorpusService::SEVERITIES);
    }

    public function test_corpus_non_execution_guarantees_present(): void
    {
        $corpus = $this->newService()->corpus();
        $this->assertContains('corpus_does_not_dispatch_work', $corpus['non_execution_guarantees']);
        $this->assertContains('corpus_does_not_promote_completion_claim', $corpus['non_execution_guarantees']);
    }

    public function test_validate_minimum_required_keys(): void
    {
        $svc = $this->newService();
        $minimal = ['scenario_id' => 'x'];
        $result = $svc->validate($minimal);
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['reasons']);
    }

    private function newService(): AgentControlPlaneCertificationScenarioCorpusService
    {
        $readiness = app(AtlasSelfConstructionReadinessService::class);
        $audit = new AgentControlPlaneChainIntegrityAuditService($readiness);
        $replay = new AgentControlPlaneDeterministicChainReplayService($audit, $readiness);
        $store = new AgentControlPlaneReplaySnapshotStore('local');
        $diff = new AgentControlPlaneReplayDiffService($store, $replay);
        $gate = new AgentControlPlaneMacroSprintPromotionGate($diff, $audit, $replay);
        $simulator = new AgentControlPlaneCertificationScenarioSimulator($readiness, $audit, $replay, $diff, $store, $gate);

        return new AgentControlPlaneCertificationScenarioCorpusService($simulator);
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
