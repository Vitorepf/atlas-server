<?php

namespace Tests\Feature\ProgrammingGovernance;

use App\Models\AtlasProgrammingWorkItem;
use App\Services\Ai\Kernel\Architecture\AtlasFeaturePlacementService;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesAtlasProgrammingGovernanceTables;
use Tests\TestCase;

/**
 * REST surface that the Atlas Code SCOR-1 cockpit (and any other consumer)
 * uses to render WorkItem timelines as live objects.
 */
class ApiSurfaceTest extends TestCase
{
    use CreatesAtlasProgrammingGovernanceTables;

    private string $token = 'test-atlas-token-with-enough-length-1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasProgrammingGovernanceTables();
        config(['atlas.token' => $this->token]);
        $this->bindStubServices();
    }

    protected function tearDown(): void
    {
        $this->dropAtlasProgrammingGovernanceTables();
        parent::tearDown();
    }

    public function test_index_requires_token(): void
    {
        $this->getJson('/atlas-code/programming/work-items')
            ->assertStatus(401);
    }

    public function test_index_returns_work_items_filtered_by_status(): void
    {
        $codeA = $this->intake('Refatorar runner para Forge OS');
        $codeB = $this->intake('Conserte typo no comentario');

        $response = $this->withHeader('X-Atlas-Token', $this->token)
            ->getJson('/atlas-code/programming/work-items?status=spec_required')
            ->assertOk()
            ->json();

        $codes = collect($response['work_items'])->pluck('code')->all();
        $this->assertContains($codeA, $codes);
        $this->assertNotContains($codeB, $codes);
    }

    public function test_show_returns_full_snapshot_with_gate_runs_and_reviews(): void
    {
        $code = $this->intake('Refatorar runner para Forge OS');

        // Run verify so we have gate runs.
        Artisan::call('atlas:programming:verify', ['work_item' => $code, '--json' => true]);

        $payload = $this->withHeader('X-Atlas-Token', $this->token)
            ->getJson("/atlas-code/programming/work-items/{$code}")
            ->assertOk()
            ->json();

        $this->assertSame($code, $payload['code']);
        $this->assertSame('atlas.programming.work_item.v1', $payload['schema_version']);
        $this->assertNotEmpty($payload['gate_runs']);
        $this->assertSame([], $payload['reviews']);
    }

    public function test_show_returns_404_for_missing_work_item(): void
    {
        $this->withHeader('X-Atlas-Token', $this->token)
            ->getJson('/atlas-code/programming/work-items/NOPE-XYZ')
            ->assertStatus(404);
    }

    public function test_gate_runs_endpoint_returns_chronological_runs(): void
    {
        $code = $this->intake('Refatorar runner para Forge OS');

        Artisan::call('atlas:programming:verify', ['work_item' => $code, '--gate' => ['evidence-required'], '--json' => true]);

        $payload = $this->withHeader('X-Atlas-Token', $this->token)
            ->getJson("/atlas-code/programming/work-items/{$code}/gate-runs")
            ->assertOk()
            ->json();

        $this->assertSame($code, $payload['work_item']);
        $this->assertSame('atlas.programming.gate_runs_index.v1', $payload['schema_version']);
        $this->assertNotEmpty($payload['gate_runs']);
        $this->assertSame('evidence-required', $payload['gate_runs'][0]['gate_name']);
    }

    public function test_adaptive_control_plane_endpoint_returns_v2_to_v5_for_atlas_code(): void
    {
        $code = $this->intake('Refatorar runner para Forge OS com controle adaptativo');

        $payload = $this->withHeader('X-Atlas-Token', $this->token)
            ->getJson("/atlas-code/programming/work-items/{$code}/adaptive-control-plane")
            ->assertOk()
            ->json();

        $this->assertSame('atlas.programming.adaptive_control_plane_response.v1', $payload['schema_version']);
        $this->assertSame($code, $payload['work_item']);
        $this->assertSame('atlas.programming.adaptive_hierarchical_control_plane.v1', data_get($payload, 'adaptive_control_plane.schema_version'));
        $this->assertSame('atlas.programming.ahcl.live_session_control.v2', data_get($payload, 'adaptive_control_plane.live_session_control_v2.schema_version'));
        $this->assertSame('atlas.programming.ahcl.forge_multi_agent_control.v3', data_get($payload, 'adaptive_control_plane.forge_multi_agent_control_v3.schema_version'));
        $this->assertSame('atlas.programming.ahcl.predictive_replay_learning.v4', data_get($payload, 'adaptive_control_plane.predictive_replay_learning_v4.schema_version'));
        $this->assertSame('atlas.programming.ahcl.optimization_control_twin.v5', data_get($payload, 'adaptive_control_plane.optimization_control_twin_v5.schema_version'));

        $v3 = $this->withHeader('X-Atlas-Token', $this->token)
            ->getJson("/atlas-code/programming/work-items/{$code}/adaptive-control-plane?level=v3")
            ->assertOk()
            ->json();

        $this->assertSame('v3', $v3['level']);
        $this->assertSame('atlas.programming.ahcl.forge_multi_agent_control.v3', data_get($v3, 'adaptive_control_plane.schema_version'));
    }

    public function test_spec_compile_endpoint_returns_compiled_spec_and_critique(): void
    {
        $code = $this->intake('Refatorar runner para suportar cobertura completa de testes');

        $payload = $this->withHeader('X-Atlas-Token', $this->token)
            ->getJson("/atlas-code/programming/work-items/{$code}/spec-compile")
            ->assertOk()
            ->json();

        $this->assertSame($code, $payload['work_item']);
        $this->assertSame('atlas.programming.spec_compile_response.v1', $payload['schema_version']);
        $this->assertArrayHasKey('compiled', $payload);
        $this->assertArrayHasKey('critique', $payload);
        $this->assertNotEmpty($payload['compiled']['spec']['objective']);
    }

    private function intake(string $intent): string
    {
        Artisan::call('atlas:programming:intake', ['intent' => $intent, '--json' => true]);

        return (string) json_decode(Artisan::output(), true)['code'];
    }

    private function bindStubServices(): void
    {
        $this->app->bind(AtlasFeaturePlacementService::class, function (): AtlasFeaturePlacementService {
            return new class extends AtlasFeaturePlacementService
            {
                public function __construct() {}

                public function place(string $intent, array $hints = []): array
                {
                    return ['status' => 'ok', 'placement' => ['layer' => 'kernel', 'domain' => 'programming'], 'owner_docs' => []];
                }
            };
        });

        $this->app->bind(EngineeringCodeIntelligenceService::class, function (): EngineeringCodeIntelligenceService {
            return new class extends EngineeringCodeIntelligenceService
            {
                public function __construct() {}

                public function summary(array $options = []): array
                {
                    return ['status' => 'ready', 'module_count' => 23, 'symbol_count' => 39419, 'doc_link_count' => 61791];
                }
            };
        });
    }
}
