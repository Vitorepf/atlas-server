<?php

namespace Tests\Feature\Ai\Product;

use App\Services\Ai\Product\AtlasAiAssistedExecutionQualityService;
use Tests\Concerns\CreatesAemorTables;
use Tests\Concerns\CreatesRuntimeEfficiencyTables;
use Tests\TestCase;

class AtlasAiAssistedExecutionQualityServiceTest extends TestCase
{
    use CreatesAemorTables;
    use CreatesRuntimeEfficiencyTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createRuntimeEfficiencyTables();
        $this->createAemorTables();
    }

    protected function tearDown(): void
    {
        $this->dropAemorTables();
        $this->dropRuntimeEfficiencyTables();

        parent::tearDown();
    }

    public function test_login_bug_human_request_builds_control_area_envelope_and_blocks_without_review(): void
    {
        $payload = app(AtlasAiAssistedExecutionQualityService::class)->buildEnvelope([
            'human_request' => 'estou com um bug na tela de login',
            'workspace' => 'atlas-app',
            'surface_id' => 'atlas_app',
        ]);

        $this->assertSame(AtlasAiAssistedExecutionQualityService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('needs_context', $payload['status']);
        $this->assertSame('atlas_dev', $payload['route']['target']);
        $this->assertSame('programming.repair', $payload['route']['flow_id']);
        $this->assertSame('high', $payload['execution_contract']['risk_band']);
        $this->assertSame('debug', $payload['execution_contract']['task_class']);
        $this->assertContains('php artisan test --filter=Login|Auth|Session', $payload['execution_contract']['suggested_tests']);
        $this->assertSame(AtlasAiAssistedExecutionQualityService::REQUIRED_PIPELINE_STEPS, $payload['quality_pipeline']['required_steps']);
        $this->assertSame(AtlasAiAssistedExecutionQualityService::REQUIRED_CONTROL_AREAS, $payload['quality_pipeline']['required_control_areas']);
        $this->assertFalse($payload['quality_pipeline']['provider_may_run_without_context_gate']);
        $this->assertFalse($payload['quality_pipeline']['provider_may_run_without_aedpds_gate']);
        $this->assertFalse($payload['quality_pipeline']['provider_may_run_without_acmf_plan']);
        $this->assertFalse($payload['quality_pipeline']['provider_may_run_without_areg_decision']);
        $this->assertTrue($payload['quality_pipeline']['completion_requires_evidence']);
        $this->assertTrue($payload['quality_pipeline']['completion_requires_aemor_outcome']);
        $this->assertTrue($payload['quality_pipeline']['failed_run_requires_failure_capsule']);
        $this->assertTrue($payload['dev_runtime_preview']['provider_safe']);
        $this->assertSame('atlas.aedpds.execution_doctrine.v1', data_get($payload, 'aedpds.doctrine.schema_version'));
        $this->assertSame('atlas.aedpds.execution_gate.v1', data_get($payload, 'aedpds.gate.schema_version'));
        $this->assertSame('blocked', data_get($payload, 'aedpds.gate.status'));
        $this->assertContains('security_driven', data_get($payload, 'aedpds.gate.selected_drivers'));
        $this->assertContains('missing_senior_review_for_sensitive_change', data_get($payload, 'aedpds.gate.blockers'));
        $this->assertSame('atlas.aucri.cognitive_memory_fabric.v1', data_get($payload, 'aucri_acmf.schema_version'));
        $this->assertSame('ready', data_get($payload, 'aucri_acmf.status'));
        $this->assertSame(1.0, data_get($payload, 'aucri_acmf.working_set.must_keep_coverage'));
        $this->assertSame('atlas.runtime_efficiency_governor.v1', data_get($payload, 'areg.schema_version'));
        $this->assertFalse(data_get($payload, 'areg.writes'));
        $this->assertSame('atlas.aemor.outcome.v1', data_get($payload, 'aemor_outcome_memory.schema_version'));
        $this->assertSame('required_after_execution', data_get($payload, 'aemor_outcome_memory.status'));
        $this->assertContains('driver_effectiveness', data_get($payload, 'aemor_outcome_memory.required_outcome_fields'));
        $this->assertSame('aedpds_gate_blocked', $payload['blockers'][0]['id']);
        $this->assertSame(64, strlen((string) $payload['assisted_execution_hash']));
    }

    public function test_reviewed_login_bug_can_pass_assisted_execution_gate(): void
    {
        $payload = app(AtlasAiAssistedExecutionQualityService::class)->buildEnvelope([
            'human_request' => 'estou com um bug na tela de login',
            'workspace' => 'atlas-app',
            'surface_id' => 'atlas_app',
            'review_refs' => ['senior-security-review:approved'],
        ]);

        $this->assertSame('ready_for_assisted_execution', $payload['status']);
        $this->assertSame('passed', data_get($payload, 'aedpds.gate.status'));
        $this->assertSame([], $payload['blockers']);
        $this->assertSame('passed', data_get($payload, 'aemor_outcome_memory.gate_status'));
    }

    public function test_missing_workspace_blocks_before_provider(): void
    {
        $payload = app(AtlasAiAssistedExecutionQualityService::class)->buildEnvelope([
            'human_request' => 'estou com um bug na tela de login',
        ]);

        $this->assertSame('needs_context', $payload['status']);
        $this->assertSame('workspace_required', $payload['blockers'][0]['id']);
        $this->assertSame('atlas_dev', $payload['route']['target']);
        $this->assertTrue($payload['dev_runtime_preview']['provider_safe']);
        $this->assertSame('atlas.aedpds.execution_gate.v1', data_get($payload, 'aedpds.gate.schema_version'));
    }

    public function test_large_obra_request_routes_to_forge_without_dev_preview(): void
    {
        $payload = app(AtlasAiAssistedExecutionQualityService::class)->buildEnvelope([
            'human_request' => 'crie uma obra para corrigir o sistema inteiro de login e pagamentos',
            'workspace' => 'atlas-server',
            'review_refs' => ['senior-runtime-review:approved'],
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

        $this->assertSame('ready_for_assisted_execution', $payload['status']);
        $this->assertSame('atlas_forge', $payload['route']['target']);
        $this->assertSame('programming.forge', $payload['route']['flow_id']);
        $this->assertNull($payload['dev_runtime_preview']);
        $this->assertTrue($payload['quality_pipeline']['large_or_uncertain_work_escalates_to_forge']);
        $this->assertContains('architecture_driven', data_get($payload, 'aedpds.doctrine.selected_primary_drivers'));
        $this->assertSame('atlas.runtime_efficiency_governor.v1', data_get($payload, 'areg.schema_version'));
        $this->assertSame('atlas.aemor.outcome.v1', data_get($payload, 'aemor_outcome_memory.schema_version'));
    }

    public function test_hash_is_deterministic_for_same_input(): void
    {
        $service = app(AtlasAiAssistedExecutionQualityService::class);
        $input = [
            'human_request' => 'estou com um bug na tela de login',
            'workspace' => 'atlas-app',
            'surface_id' => 'atlas_app',
            'review_refs' => ['senior-security-review:approved'],
        ];

        $first = $service->buildEnvelope($input);
        $second = $service->buildEnvelope($input);

        $this->assertSame($first['assisted_execution_hash'], $second['assisted_execution_hash']);
    }

    public function test_outcome_feedback_records_areg_and_prepares_aemor_without_writes_by_default(): void
    {
        $service = app(AtlasAiAssistedExecutionQualityService::class);
        $envelope = $service->buildEnvelope([
            'human_request' => 'corrija bug pequeno no controller',
            'workspace' => 'atlas-server',
            'surface_id' => 'atlas_ai',
        ]);

        $feedback = $service->recordOutcomeFeedback($envelope, [
            'status' => 'succeeded',
            'quality_score' => 0.91,
            'context_roi_score' => 0.83,
            'evidence_refs' => ['test:assisted-feedback'],
        ]);

        $this->assertSame('atlas.ai.assisted_execution_outcome_feedback.v1', $feedback['schema_version']);
        $this->assertSame('recorded', $feedback['status']);
        $this->assertSame('atlas.runtime_efficiency_outcome.v1', data_get($feedback, 'areg_outcome.schema_version'));
        $this->assertFalse(data_get($feedback, 'areg_outcome.writes'));
        $this->assertSame('ready_to_record', data_get($feedback, 'aemor_outcome.status'));
        $this->assertFalse(data_get($feedback, 'claim_policy.writes'));
        $this->assertTrue(data_get($feedback, 'claim_policy.requires_aemor_judgment_for_learning_promotion'));
        $this->assertContains('test:assisted-feedback', $feedback['evidence_refs']);
        $this->assertNotEmpty($feedback['feedback_hash']);
        $this->assertDatabaseCount('atlas_runtime_efficiency_outcomes', 0);
        $this->assertDatabaseCount('atlas_aemor_execution_episodes', 0);
        $this->assertDatabaseCount('atlas_aemor_outcomes', 0);
    }

    public function test_outcome_feedback_can_persist_areg_and_aemor_when_explicitly_allowed(): void
    {
        $service = app(AtlasAiAssistedExecutionQualityService::class);
        $envelope = $service->buildEnvelope([
            'human_request' => 'corrija bug pequeno no controller',
            'workspace' => 'atlas-server',
            'surface_id' => 'atlas_ai',
        ]);

        $feedback = $service->recordOutcomeFeedback($envelope, [
            'persist' => true,
            'status' => 'succeeded',
            'quality_score' => 0.94,
            'context_roi_score' => 0.86,
            'evidence_refs' => ['test:assisted-feedback:persisted'],
            'summary' => 'Assisted execution finished with focused tests.',
        ]);

        $this->assertSame('recorded', $feedback['status']);
        $this->assertTrue(data_get($feedback, 'areg_outcome.writes'));
        $this->assertTrue(data_get($feedback, 'aemor_outcome.writes'));
        $this->assertSame('succeeded', data_get($feedback, 'aemor_outcome.status'));
        $this->assertSame('effective_pending_judgment', data_get($feedback, 'driver_effectiveness.atdd'));
        $this->assertDatabaseCount('atlas_runtime_efficiency_outcomes', 1);
        $this->assertDatabaseCount('atlas_aemor_execution_episodes', 1);
        $this->assertDatabaseCount('atlas_aemor_outcomes', 1);
    }
}
