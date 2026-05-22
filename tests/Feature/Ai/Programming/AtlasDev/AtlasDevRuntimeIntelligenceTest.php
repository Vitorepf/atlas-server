<?php

namespace Tests\Feature\Ai\Programming\AtlasDev;

use App\Models\AtlasDevContextGate;
use App\Models\AtlasDevDecisionMaterialization;
use App\Models\AtlasDevOutcomeMemory;
use App\Models\AtlasDevRunCertification;
use App\Models\AtlasDevTaskPacket;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevContextGateService;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevDecisionMaterializationService;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevFailureCapsuleRuntimeService;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevNativeCapabilityOrchestrator;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevOutcomeMemoryService;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevRunCertificationService;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevRuntimeIntelligenceService;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevTaskPacketRuntimeService;
use App\Services\Ai\Programming\AtlasDevRuntimeService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasDevRuntimeIntelligenceTest extends TestCase
{
    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migration = require base_path('database/migrations/2026_05_22_160000_create_atlas_dev_runtime_intelligence_tables.php');
        $this->migration->down();
        $this->migration->up();
    }

    protected function tearDown(): void
    {
        $this->migration->down();
        parent::tearDown();
    }

    public function test_builds_and_persists_dev_task_packet_with_deterministic_hash(): void
    {
        $service = new DevTaskPacketRuntimeService;
        $input = $this->readyTaskInput();

        $a = $service->build($input);
        $b = $service->build($input);
        $model = $service->persist($input);

        $this->assertSame('atlas.dev.task_packet.v1', $a['schema_version']);
        $this->assertSame($a['task_packet_hash'], $b['task_packet_hash']);
        $this->assertSame(64, strlen((string) $a['task_packet_hash']));
        $this->assertSame($a['task_packet_hash'], $model->task_packet_hash);
        $this->assertSame(['app/Services/Ai/Programming/AtlasDevRuntimeService.php'], $model->expected_files);
    }

    public function test_context_gate_blocks_high_risk_dev_task_without_context(): void
    {
        $packet = (new DevTaskPacketRuntimeService)->persist([
            'run_id' => 'dev-run-high-risk',
            'task_id' => 'task-without-context',
            'objective' => 'Reescrever roteamento central do Atlas Dev',
            'risk_band' => 'high',
            'task_class' => 'feature',
            'suggested_tests' => ['php artisan test --filter=AtlasDev'],
        ]);

        $gate = (new DevContextGateService)->persist($packet);

        $this->assertSame(DevContextGateService::STATUS_BLOCKED, $gate->status);
        $this->assertFalse($gate->provider_safe);
        $this->assertContains('owner_docs_or_context_refs', $gate->missing);
        $this->assertSame(64, strlen((string) $gate->context_gate_hash));
    }

    public function test_failure_capsule_persists_error_excerpt_repair_signal_and_forge_escalation(): void
    {
        $packet = (new DevTaskPacketRuntimeService)->persist($this->readyTaskInput());

        $capsule = (new DevFailureCapsuleRuntimeService)->persist([
            'run_id' => $packet->run_id,
            'task_id' => $packet->task_id,
            'failing_gate' => 'scope_guard',
            'error' => 'Forbidden file touched outside allowed scope',
            'changed_files' => ['app/Services/Ai/Programming/Forge/Unexpected.php'],
        ], $packet);

        $this->assertSame('atlas.dev.failure_capsule.v1', $capsule->schema_version);
        $this->assertSame('scope_violation', $capsule->failure_class);
        $this->assertTrue($capsule->escalate_to_forge);
        $this->assertStringContainsString('allowed files', $capsule->suggested_repair);
        $this->assertSame(64, strlen((string) $capsule->failure_hash));
    }

    public function test_outcome_memory_persists_success_and_failure_outcomes(): void
    {
        $packet = (new DevTaskPacketRuntimeService)->persist($this->readyTaskInput());
        $outcomes = new DevOutcomeMemoryService;

        $success = $outcomes->persist([
            'outcome_status' => 'success',
            'evidence_kinds' => ['phpunit', 'pint'],
            'selected_tests' => ['php artisan test tests/Feature/Ai/Programming/AtlasDev/AtlasDevRuntimeIntelligenceTest.php'],
            'changed_files' => ['app/Services/Ai/Programming/AtlasDevRuntimeService.php'],
        ], $packet);

        $failure = (new DevFailureCapsuleRuntimeService)->persist([
            'run_id' => $packet->run_id,
            'task_id' => $packet->task_id,
            'error' => 'PHPUnit failed assertion',
        ], $packet);
        $failedOutcome = $outcomes->persist(['outcome_status' => 'failed'], $packet, $failure);

        $this->assertSame('success', $success->outcome_status);
        $this->assertFalse($success->human_review_required);
        $this->assertSame('failed', $failedOutcome->outcome_status);
        $this->assertTrue($failedOutcome->human_review_required);
        $this->assertContains('failure_class:test_failure', $failedOutcome->learning_candidates);
    }

    public function test_run_certification_ready_when_packet_context_and_outcome_are_valid(): void
    {
        $packet = (new DevTaskPacketRuntimeService)->persist($this->readyTaskInput());
        $gate = (new DevContextGateService)->persist($packet);
        $outcome = (new DevOutcomeMemoryService)->persist([
            'outcome_status' => 'success',
            'evidence_kinds' => ['phpunit', 'docs_health'],
            'selected_tests' => $packet->suggested_tests,
            'changed_files' => $packet->expected_files,
            'diff_clean' => true,
        ], $packet);
        $decisions = $this->persistNativeDecisions($packet, $gate, ['outcome_status' => 'success', 'evidence_kinds' => ['phpunit'], 'selected_tests' => $packet->suggested_tests, 'changed_files' => $packet->expected_files, 'diff_clean' => true]);

        $certification = (new DevRunCertificationService)->persist($packet, $gate, $outcome, decisionMaterializations: $decisions);

        $this->assertSame(DevRunCertificationService::STATUS_READY, $certification->status);
        $this->assertSame(17, $certification->summary['total']);
        $this->assertSame(0, $certification->summary['fail']);
        $this->assertSame(64, strlen((string) $certification->certification_hash));
    }

    public function test_failed_dev_run_requires_failure_capsule_for_certification(): void
    {
        $packet = (new DevTaskPacketRuntimeService)->persist($this->readyTaskInput());
        $gate = (new DevContextGateService)->persist($packet);
        $outcome = (new DevOutcomeMemoryService)->persist([
            'outcome_status' => 'failed',
            'evidence_kinds' => ['phpunit'],
            'selected_tests' => $packet->suggested_tests,
        ], $packet);

        $certification = (new DevRunCertificationService)->persist($packet, $gate, $outcome);

        $this->assertSame(DevRunCertificationService::STATUS_NEEDS_REVIEW, $certification->status);
        $this->assertContains('failed_run_has_failure_capsule', array_column($certification->blockers, 'id'));
    }

    public function test_orchestrator_materializes_all_fifteen_dev_blocks(): void
    {
        $result = (new DevRuntimeIntelligenceService)->materialize(
            task: $this->readyTaskInput(),
            outcome: [
                'outcome_status' => 'success',
                'evidence_kinds' => ['phpunit'],
                'selected_tests' => ['php artisan test --filter=AtlasDevRuntimeIntelligence'],
                'changed_files' => ['app/Services/Ai/Programming/AtlasDevRuntimeService.php'],
                'diff_clean' => true,
                'senior_review_evidence' => ['senior_review:not_required_for_medium_runtime_task'],
            ],
        );

        $this->assertSame(1, AtlasDevTaskPacket::query()->count());
        $this->assertSame(1, AtlasDevContextGate::query()->count());
        $this->assertSame(1, AtlasDevOutcomeMemory::query()->count());
        $this->assertSame(10, AtlasDevDecisionMaterialization::query()->count());
        $this->assertSame(1, AtlasDevRunCertification::query()->count());
        $this->assertNull($result['failure_capsule']);
        $this->assertSame(DevRunCertificationService::STATUS_READY, $result['run_certification']['status']);
        $this->assertSame(15, $result['native_capabilities']['coverage']['total_blocks']);
        $this->assertSameCanonicalDecisionKinds(array_column($result['decision_materializations'], 'decision_kind'));
    }

    public function test_atlas_dev_runtime_payload_includes_provider_safe_intelligence_preview(): void
    {
        $data = (new AtlasDevRuntimeService)->apply([
            'payload' => [
                'surface_id' => 'atlas_app',
                'atlas_mode' => 'programming',
                'routing_task' => 'dev',
                'workspace' => 'atlas-server',
                'input_text' => 'Implementar ajuste pequeno no Atlas Dev',
                'context_refs' => ['docs/engineering-knowledge-base/atlas-dev-runtime-intelligence.md'],
                'expected_files' => ['app/Services/Ai/Programming/AtlasDevRuntimeService.php'],
                'suggested_tests' => ['php artisan test --filter=AtlasDevRuntimeServiceTest'],
                'acceptance_criteria' => ['payload contains runtime intelligence preview'],
            ],
        ]);

        $preview = $data['payload']['atlas_dev_runtime_intelligence'] ?? null;

        $this->assertIsArray($preview);
        $this->assertSame('atlas.dev.runtime_intelligence_preview.v1', $preview['schema_version']);
        $this->assertTrue($preview['provider_safe']);
        $this->assertSame('passed', $preview['context_gate']['status']);
        $this->assertSame(15, $preview['native_capabilities']['coverage']['total_blocks']);
        $this->assertArrayHasKey('test_impact', $preview['native_capabilities']['decisions']);
        $this->assertTrue($data['payload']['atlas_dev_runtime']['provider_execution_allowed']);
        $this->assertSame('needs_review', $data['payload']['atlas_dev_runtime']['native_capability_status']);
    }

    public function test_high_risk_sensitive_task_requires_senior_review_and_simulation(): void
    {
        $preview = (new DevRuntimeIntelligenceService)->preview([
            ...$this->readyTaskInput(),
            'run_id' => 'dev-run-sensitive',
            'task_id' => 'task-sensitive',
            'objective' => 'Alterar provider runtime critico com migration',
            'risk_band' => 'high',
            'expected_files' => [
                'app/Services/Ai/Programming/AtlasDev/Provider/SonnetClaudeCliAdapter.php',
                'database/migrations/2026_05_22_170000_change_provider_table.php',
            ],
        ]);

        $decisions = $preview['native_capabilities']['decisions'];

        $this->assertSame('needs_review', $decisions['senior_review']['status']);
        $this->assertContains('provider', $decisions['senior_review']['sensitive_reasons']);
        $this->assertSame('ready', $decisions['simulation']['status']);
        $this->assertSame('worker_subagent', $decisions['delegation_route']['route']);
    }

    public function test_scope_guard_materialization_blocks_forbidden_file_change(): void
    {
        $packet = (new DevTaskPacketRuntimeService)->persist($this->readyTaskInput());
        $gate = (new DevContextGateService)->persist($packet);
        $native = (new DevNativeCapabilityOrchestrator)->evaluate($packet->toArray(), [
            'context_gate' => $gate->toArray(),
            'changed_files' => ['vendor/package/Unsafe.php'],
            'scope_status' => 'blocked',
        ]);

        $this->assertSame('blocked', $native['decisions']['scope_guard']['status']);
        $this->assertSame('forbidden_file_match', $native['decisions']['scope_guard']['violations'][0]['reason']);
    }

    public function test_provider_capacity_memory_is_observation_not_routing_override(): void
    {
        $preview = (new DevRuntimeIntelligenceService)->preview([
            ...$this->readyTaskInput(),
            'run_id' => 'dev-run-provider-memory',
            'task_id' => 'task-provider-memory',
        ]);

        $memory = $preview['native_capabilities']['decisions']['provider_capacity_memory'];

        $this->assertSame('record_observation_only', $memory['memory_action']);
        $this->assertFalse($memory['routing_override_allowed']);
    }

    public function test_escalation_policy_routes_large_uncertain_task_to_forge(): void
    {
        $packet = (new DevTaskPacketRuntimeService)->build([
            ...$this->readyTaskInput(),
            'run_id' => 'dev-run-escalate',
            'task_id' => 'task-escalate',
            'risk_band' => 'critical',
            'expected_files' => [
                'app/A.php',
                'app/B.php',
                'app/C.php',
                'app/D.php',
                'app/E.php',
                'app/F.php',
                'app/G.php',
            ],
        ]);

        $native = (new DevNativeCapabilityOrchestrator)->evaluate($packet, [
            'context_gate' => ['provider_safe' => true],
            'domains' => ['programming', 'operations'],
            'repeat_failures' => 2,
        ]);

        $this->assertTrue($native['decisions']['forge_escalation_policy']['escalate']);
        $this->assertContains('too_many_files_for_dev', $native['decisions']['forge_escalation_policy']['reasons']);
        $this->assertContains('critical_risk', $native['decisions']['forge_escalation_policy']['reasons']);
    }

    public function test_dev_run_certify_command_emits_latest_certification(): void
    {
        (new DevRuntimeIntelligenceService)->materialize(
            task: [
                ...$this->readyTaskInput(),
                'run_id' => 'dev-run-command-cert',
                'task_id' => 'task-command-cert',
            ],
            outcome: [
                'outcome_status' => 'success',
                'evidence_kinds' => ['phpunit'],
                'selected_tests' => ['php artisan test --filter=AtlasDevRuntimeIntelligence'],
                'changed_files' => ['app/Services/Ai/Programming/AtlasDevRuntimeService.php'],
                'diff_clean' => true,
                'senior_review_evidence' => ['not_required'],
            ],
        );

        $exit = Artisan::call('atlas:dev:run-certify', [
            '--run' => 'dev-run-command-cert',
            '--task' => 'task-command-cert',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.dev.run_certify_command.v1', $payload['schema_version']);
        $this->assertSame(DevRunCertificationService::STATUS_READY, $payload['status']);
        $this->assertSame('dev-run-command-cert', $payload['run_id']);
    }

    /**
     * @return array<string,mixed>
     */
    private function readyTaskInput(): array
    {
        return [
            'run_id' => 'dev-run-runtime-intelligence',
            'task_id' => 'task-runtime-intelligence',
            'objective' => 'Implementar Dev Runtime Intelligence no fluxo Atlas Dev',
            'task_class' => 'feature',
            'risk_band' => 'medium',
            'workspace_slug' => 'atlas-server',
            'allowed_files' => ['app/Services/Ai/Programming/AtlasDevRuntimeService.php'],
            'forbidden_files' => ['vendor/'],
            'context_refs' => ['docs/engineering-knowledge-base/atlas-dev-runtime-intelligence.md'],
            'expected_files' => ['app/Services/Ai/Programming/AtlasDevRuntimeService.php'],
            'suggested_tests' => ['php artisan test tests/Feature/Ai/Programming/AtlasDev/AtlasDevRuntimeIntelligenceTest.php'],
            'acceptance_criteria' => ['all five Dev runtime intelligence blocks are persisted and certified'],
            'required_evidence' => ['phpunit', 'pint', 'docs_health'],
        ];
    }

    /**
     * @return list<AtlasDevDecisionMaterialization>
     */
    private function persistNativeDecisions(AtlasDevTaskPacket $packet, AtlasDevContextGate $gate, array $outcome): array
    {
        $native = (new DevNativeCapabilityOrchestrator)->evaluate($packet->toArray(), [
            'context_gate' => $gate->toArray(),
            'outcome' => $outcome,
            'changed_files' => $outcome['changed_files'] ?? [],
            'scope_status' => 'ready',
            'diff_clean' => true,
            'senior_review_evidence' => ['not_required'],
        ]);
        $materializer = new DevDecisionMaterializationService;

        return array_values(array_map(
            fn (string $kind, array $decision): AtlasDevDecisionMaterialization => $materializer->persist($packet, $kind, $decision),
            array_keys($native['decisions']),
            $native['decisions'],
        ));
    }

    /**
     * @param  list<string>  $actual
     */
    private function assertSameCanonicalDecisionKinds(array $actual): void
    {
        sort($actual);
        $expected = DevNativeCapabilityOrchestrator::DECISION_KINDS;
        sort($expected);

        $this->assertSame($expected, $actual);
    }
}
