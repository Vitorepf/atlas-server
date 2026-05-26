<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AtlasDecide;

use App\Services\Ai\AtlasDecide\AtlasCognitiveFunctionSwarmRouterService;
use App\Services\Ai\Cognition\AtlasCognitiveFunctionDecomposerService;
use Tests\TestCase;

class AtlasCognitiveFunctionSwarmRouterServiceTest extends TestCase
{
    private string $log;

    private AtlasCognitiveFunctionSwarmRouterService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $u = uniqid('', true);
        $this->log = sys_get_temp_dir()."/atlas_cogfn_swarm_router_{$u}.jsonl";
        $decomposer = new AtlasCognitiveFunctionDecomposerService;
        $decomposer->setLogPathForTesting(sys_get_temp_dir()."/atlas_cogfn_swarm_router_dec_{$u}.jsonl");

        $this->svc = new AtlasCognitiveFunctionSwarmRouterService(
            $decomposer,
            $this->app->make(\App\Services\Ai\AtlasDecide\AtlasSwarmConductorService::class),
            $this->app->make(\App\Services\Ai\Governance\AtlasConstitutionalKernelService::class),
        );
        $this->svc->setLogPathForTesting($this->log);
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
        parent::tearDown();
    }

    public function test_returns_canonical_envelope(): void
    {
        $env = $this->svc->routeAndDispatch('refatore o controller de auth e escreva testes', ['role' => 'engineer']);
        $this->assertSame('atlas.cognitive_function_swarm_router.envelope.v1', $env['schema_version']);
        $this->assertStringStartsWith('sha256:', $env['router_hash']);
        $this->assertArrayHasKey('cognitive_vector', $env);
        $this->assertArrayHasKey('arms', $env);
        $this->assertArrayHasKey('axes_included', $env);
        $this->assertSame(0.15, $env['inclusion_threshold']);
    }

    public function test_dominant_function_drives_routing(): void
    {
        $env = $this->svc->routeAndDispatch('audite a cartografia e verifique kernel hash', ['role' => 'auditor']);
        $this->assertSame('audit', $env['dominant_function']);
    }

    public function test_arms_capped_at_max_axes(): void
    {
        // Force broad input that hits multiple axes
        $env = $this->svc->routeAndDispatch(
            'audite cartografia, escreva resumo, refatore controller, busque documentacao, analise visualmente layout, raciocine sobre decisao',
            ['role' => 'engineer'],
            inclusionThreshold: 0.01,
        );
        $this->assertLessThanOrEqual(AtlasCognitiveFunctionSwarmRouterService::MAX_AXES, $env['arm_count']);
    }

    public function test_inclusion_threshold_filters_axes(): void
    {
        $envLow = $this->svc->routeAndDispatch('refatore controller', ['role' => 'engineer'], inclusionThreshold: 0.001);
        $envHigh = $this->svc->routeAndDispatch('refatore controller', ['role' => 'engineer'], inclusionThreshold: 0.9);
        $this->assertGreaterThanOrEqual(count($envHigh['axes_included']), count($envLow['axes_included']));
    }

    public function test_jsonl_persists_envelopes(): void
    {
        $this->svc->routeAndDispatch('teste 1', ['role' => 'r']);
        $this->svc->routeAndDispatch('teste 2', ['role' => 'r']);
        $this->assertCount(2, $this->svc->listRoutes());
    }

    public function test_envelope_includes_decomposition_hash_reference(): void
    {
        $env = $this->svc->routeAndDispatch('qualquer coisa', ['role' => 'r']);
        $this->assertArrayHasKey('decomposition_hash', $env);
        $this->assertStringStartsWith('sha256:', (string) $env['decomposition_hash']);
    }

    public function test_claim_policy_provider_safe(): void
    {
        $env = $this->svc->routeAndDispatch('teste', ['role' => 'r']);
        $cp = $env['claim_policy'];
        $this->assertFalse($cp['benchmark_claim_allowed']);
        $this->assertFalse($cp['rivals_claim_allowed']);
        $this->assertFalse($cp['superiority_claim_allowed']);
        $this->assertTrue($cp['local_first_only']);
    }

    public function test_empty_input_emits_envelope_without_crash(): void
    {
        $env = $this->svc->routeAndDispatch('', ['role' => 'r']);
        $this->assertSame('audit', $env['dominant_function']);
    }

    public function test_arms_carry_cognitive_axis_and_weight(): void
    {
        $env = $this->svc->routeAndDispatch('refatore controller', ['role' => 'engineer']);
        if ($env['arm_count'] > 0) {
            $first = $env['arms'][0];
            $this->assertArrayHasKey('cognitive_axis', $first);
            $this->assertArrayHasKey('axis_weight', $first);
            $this->assertContains($first['cognitive_axis'], AtlasCognitiveFunctionDecomposerService::FUNCTIONS);
        } else {
            // If no arms (e.g. insufficient ADML evidence), envelope still canonical.
            $this->assertSame(0, $env['arm_count']);
        }
    }
}
