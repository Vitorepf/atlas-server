<?php

namespace Tests\Feature\Ai\Product;

use App\Services\Ai\Product\AtlasAiAssistedExecutionQualityService;
use Tests\TestCase;

class AtlasAiAssistedExecutionQualityServiceTest extends TestCase
{
    public function test_login_bug_human_request_builds_provider_safe_dev_envelope(): void
    {
        $payload = app(AtlasAiAssistedExecutionQualityService::class)->buildEnvelope([
            'human_request' => 'estou com um bug na tela de login',
            'workspace' => 'atlas-app',
            'surface_id' => 'atlas_app',
        ]);

        $this->assertSame(AtlasAiAssistedExecutionQualityService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready_for_assisted_execution', $payload['status']);
        $this->assertSame('atlas_dev', $payload['route']['target']);
        $this->assertSame('programming.repair', $payload['route']['flow_id']);
        $this->assertSame('high', $payload['execution_contract']['risk_band']);
        $this->assertSame('debug', $payload['execution_contract']['task_class']);
        $this->assertContains('php artisan test --filter=Login|Auth|Session', $payload['execution_contract']['suggested_tests']);
        $this->assertSame(AtlasAiAssistedExecutionQualityService::REQUIRED_PIPELINE_STEPS, $payload['quality_pipeline']['required_steps']);
        $this->assertFalse($payload['quality_pipeline']['provider_may_run_without_context_gate']);
        $this->assertTrue($payload['quality_pipeline']['completion_requires_evidence']);
        $this->assertTrue($payload['quality_pipeline']['failed_run_requires_failure_capsule']);
        $this->assertTrue($payload['dev_runtime_preview']['provider_safe']);
        $this->assertSame([], $payload['blockers']);
        $this->assertSame(64, strlen((string) $payload['assisted_execution_hash']));
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
    }

    public function test_large_obra_request_routes_to_forge_without_dev_preview(): void
    {
        $payload = app(AtlasAiAssistedExecutionQualityService::class)->buildEnvelope([
            'human_request' => 'crie uma obra para corrigir o sistema inteiro de login e pagamentos',
            'workspace' => 'atlas-server',
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
    }

    public function test_hash_is_deterministic_for_same_input(): void
    {
        $service = app(AtlasAiAssistedExecutionQualityService::class);
        $input = [
            'human_request' => 'estou com um bug na tela de login',
            'workspace' => 'atlas-app',
            'surface_id' => 'atlas_app',
        ];

        $first = $service->buildEnvelope($input);
        $second = $service->buildEnvelope($input);

        $this->assertSame($first['assisted_execution_hash'], $second['assisted_execution_hash']);
    }
}
