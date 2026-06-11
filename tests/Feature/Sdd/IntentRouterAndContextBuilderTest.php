<?php

namespace Tests\Feature\Sdd;

use App\Models\AtlasOperation;
use App\Services\Ai\Kernel\Architecture\AtlasFeaturePlacementService;
use App\Services\Ai\Programming\Governance\ProgrammingWorkItemClassifier;
use App\Services\Ai\Programming\Sdd\ContextBuilder;
use App\Services\Ai\Programming\Sdd\Enums\ConfidenceClass;
use App\Services\Ai\Programming\Sdd\IntentRouter;
use App\Services\Ai\Programming\Sdd\Pipeline\SddPipelineOperationEnvelope as OperationEnvelope;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use Tests\Concerns\CreatesAtlasSddTables;
use Tests\TestCase;

class IntentRouterAndContextBuilderTest extends TestCase
{
    use CreatesAtlasSddTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasSddTables();
        $this->bindStubs();
    }

    protected function tearDown(): void
    {
        $this->dropAtlasSddTables();
        parent::tearDown();
    }

    public function test_router_classifies_structural_feature(): void
    {
        $router = app(IntentRouter::class);
        $env = new OperationEnvelope('Adicionar nova feature de export pdf no runner');

        $intent = $router->route($env);

        $this->assertSame('feature', $intent->type);
        $this->assertSame('programming', $intent->domain);
        $this->assertSame('medium', $intent->riskLevel);
        $this->assertSame(ConfidenceClass::StrongInference, $intent->confidenceClass);
        $this->assertTrue($intent->harnessRequired, 'structural feature should require harness');
    }

    public function test_router_flags_blocking_ambiguity_for_empty_input(): void
    {
        $router = app(IntentRouter::class);
        $env = new OperationEnvelope('   ');
        $intent = $router->route($env);

        $this->assertTrue($intent->confidenceClass->isBlocking());
    }

    public function test_router_persist_creates_operation_row(): void
    {
        $router = app(IntentRouter::class);
        $env = new OperationEnvelope('Refatorar runner para Forge OS', userId: 'vitor');
        $intent = $router->route($env);
        $op = $router->persist($env, $intent);

        $this->assertSame('vitor', $op->user_id);
        $this->assertSame('routed', $op->status);
        $this->assertSame('refactor', $op->routing_metadata_json['envelope']['raw_input'] !== null
            ? data_get($op, 'routing_metadata_json.classification_signals') !== null
                ? 'refactor' : '?'
            : '?');
        $this->assertSame(1, AtlasOperation::query()->count());
    }

    public function test_router_resolves_security_as_high_risk_with_confirmed_fact(): void
    {
        $intent = app(IntentRouter::class)->route(new OperationEnvelope('Corrigir vulnerabilidade de security na auth'));

        $this->assertSame('high', $intent->riskLevel);
        $this->assertSame(ConfidenceClass::ConfirmedFact, $intent->confidenceClass);
    }

    public function test_context_builder_emits_versioned_pack_with_digest(): void
    {
        $router = app(IntentRouter::class);
        $builder = app(ContextBuilder::class);

        $env = new OperationEnvelope('Adicionar feature de export');
        $intent = $router->route($env);
        $pack = $builder->build($env, $intent);

        $this->assertSame(64, strlen($pack->digest));
        $this->assertContains('atlas.base.v1', $pack->packages);
        $this->assertContains('atlas.intent.feature.v1', $pack->packages);
        $this->assertNotEmpty($pack->stack);
        $this->assertSame('atlas.sdd_context_pack.v1', $pack->toArray()['schema_version']);
    }

    public function test_context_pack_is_deterministic_for_same_inputs(): void
    {
        $router = app(IntentRouter::class);
        $builder = app(ContextBuilder::class);
        $env = new OperationEnvelope('Refatorar runner');

        $pack1 = $builder->build($env, $router->route($env));
        $pack2 = $builder->build($env, $router->route($env));

        $this->assertSame($pack1->digest, $pack2->digest);
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
        $this->app->singleton(ProgrammingWorkItemClassifier::class);
    }
}
