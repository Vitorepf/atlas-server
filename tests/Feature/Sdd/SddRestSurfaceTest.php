<?php

namespace Tests\Feature\Sdd;

use App\Models\AtlasOperation;
use App\Services\Ai\Kernel\Architecture\AtlasFeaturePlacementService;
use App\Services\Ai\Programming\Sdd\AtlasSddPipeline;
use App\Services\Ai\Programming\Sdd\Pipeline\SddPipelineOperationEnvelope as OperationEnvelope;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use Tests\Concerns\CreatesAtlasSddTables;
use Tests\TestCase;

class SddRestSurfaceTest extends TestCase
{
    use CreatesAtlasSddTables;

    private string $token = 'sdd-test-token-with-enough-length-1234567890';

    private string $workspace = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasSddTables();
        config(['atlas.token' => $this->token]);
        $this->workspace = sys_get_temp_dir().'/sdd_rest_'.bin2hex(random_bytes(4));
        mkdir($this->workspace.'/app', 0755, true);
        $this->bindStubs();
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->workspace);
        $this->dropAtlasSddTables();
        parent::tearDown();
    }

    public function test_unauthenticated_requests_return_401(): void
    {
        $this->getJson('/atlas-code/sdd/specs')->assertStatus(401);
    }

    public function test_specs_index_returns_recently_created_specs(): void
    {
        $envelope = new OperationEnvelope('Adicionar feature de export', userId: 'vitor', workspace: $this->workspace);
        app(AtlasSddPipeline::class)->run($envelope);

        $payload = $this->withHeader('X-Atlas-Token', $this->token)
            ->getJson('/atlas-code/sdd/specs')
            ->assertOk()
            ->json();

        $this->assertSame('atlas.sdd_specs_index.v1', $payload['schema_version']);
        $this->assertGreaterThanOrEqual(1, $payload['count']);
    }

    public function test_decision_receipts_endpoint_returns_signed_receipt(): void
    {
        $envelope = new OperationEnvelope('Refatorar runner para suportar cobertura completa de testes', workspace: $this->workspace);
        $output = app(AtlasSddPipeline::class)->run($envelope);
        $receiptId = $output->payload['receipt_id'] ?? null;
        $this->assertNotNull($receiptId);

        $payload = $this->withHeader('X-Atlas-Token', $this->token)
            ->getJson("/atlas-code/sdd/decision-receipts/{$receiptId}")
            ->assertOk()
            ->json();

        $this->assertSame('atlas.sdd_decision_receipt.v1', $payload['schema_version']);
        $this->assertSame($receiptId, $payload['receipt_id']);
        $this->assertNotEmpty($payload['allowed_actions']);
        $this->assertNotEmpty($payload['signature']);
    }

    public function test_drift_reports_endpoint_lists_reports(): void
    {
        $envelope = new OperationEnvelope('Refatorar runner para Forge', workspace: $this->workspace);
        app(AtlasSddPipeline::class)->run($envelope);

        $payload = $this->withHeader('X-Atlas-Token', $this->token)
            ->getJson('/atlas-code/sdd/drift-reports')
            ->assertOk()
            ->json();

        $this->assertSame('atlas.sdd_drift_reports_index.v1', $payload['schema_version']);
        $this->assertGreaterThanOrEqual(1, $payload['count']);
    }

    public function test_operations_endpoint_returns_routed_records(): void
    {
        AtlasOperation::query()->create([
            'raw_input' => 'manual', 'status' => 'routed', 'risk_level' => 'medium',
            'domain' => 'programming', 'routing_metadata_json' => [],
        ]);

        $payload = $this->withHeader('X-Atlas-Token', $this->token)
            ->getJson('/atlas-code/sdd/operations')
            ->assertOk()
            ->json();

        $this->assertSame('atlas.sdd_operations_index.v1', $payload['schema_version']);
        $this->assertGreaterThanOrEqual(1, $payload['count']);
    }

    public function test_show_spec_includes_requirements_and_assumptions_arrays(): void
    {
        $envelope = new OperationEnvelope('Refatorar runner para Forge', workspace: $this->workspace);
        $output = app(AtlasSddPipeline::class)->run($envelope);
        $specId = $output->payload['spec_id'] ?? null;
        $this->assertNotNull($specId);

        $payload = $this->withHeader('X-Atlas-Token', $this->token)
            ->getJson("/atlas-code/sdd/specs/{$specId}")
            ->assertOk()
            ->json();

        $this->assertSame('atlas.sdd_spec.v1', $payload['schema_version']);
        $this->assertArrayHasKey('requirements', $payload);
        $this->assertArrayHasKey('assumptions', $payload);
        $this->assertArrayHasKey('plans', $payload);
    }

    private function bindStubs(): void
    {
        $this->app->bind(AtlasFeaturePlacementService::class, function () {
            return new class extends AtlasFeaturePlacementService
            {
                public function __construct() {}

                public function place(string $intent, array $hints = []): array
                {
                    return ['status' => 'ok', 'placement' => ['layer' => 'kernel', 'domain' => 'programming']];
                }
            };
        });
        $this->app->bind(EngineeringCodeIntelligenceService::class, function () {
            return new class extends EngineeringCodeIntelligenceService
            {
                public function __construct() {}

                public function summary(array $options = []): array
                {
                    return ['status' => 'ready', 'module_count' => 23, 'symbol_count' => 39419];
                }
            };
        });
    }

    private function rmrf(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        foreach (@scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->rmrf($path.'/'.$item);
        }
        @rmdir($path);
    }
}
