<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\PersistentContext;

use App\Models\AiMemoryDelta;
use App\Models\AtlasPersistentContextPack;
use App\Services\Ai\AiContextPackBuilder;
use App\Services\Ai\ContextIntelligence\AtlasContextIntelligenceService;
use App\Services\Ai\ContextIntelligence\AtlasContextOperationsRuntimeService;
use App\Services\Ai\Kernel\Architecture\AtlasSessionBootstrapService;
use App\Services\Ai\PersistentContext\AtlasPersistentContextRuntimeService;
use App\Services\Ai\ValueObjects\AiContextPack;
use Mockery;
use Tests\Concerns\CreatesPersistentContextTables;
use Tests\TestCase;

final class AtlasPersistentContextRuntimeServiceTest extends TestCase
{
    use CreatesPersistentContextTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPersistentContextTables();
    }

    protected function tearDown(): void
    {
        $this->dropPersistentContextTables();
        parent::tearDown();
    }

    public function test_build_persists_sufficient_context_pack_with_provider_handoff(): void
    {
        $runtime = $this->runtime();

        $payload = $runtime->build([
            'prompt' => 'implemente o APCR no Atlas Dev com evidencia',
            'workspace' => base_path(),
            'surface_id' => 'atlas_desktop_ai',
            'domain' => 'programming',
            'flow_id' => 'atlas_dev',
            'provider' => 'auto',
            'payload' => ['context_refs' => ['doc:atlas']],
            'evidence_refs' => ['receipt:router'],
        ]);

        $this->assertSame(AtlasPersistentContextRuntimeService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(AtlasPersistentContextRuntimeService::STATUS_READY, $payload['status']);
        $this->assertSame('sufficient', data_get($payload, 'sufficiency.status'));
        $this->assertTrue(data_get($payload, 'provider_handoff.execution_allowed'));
        $this->assertFalse(data_get($payload, 'claim_policy.provider_calls_made'));
        $this->assertNotEmpty($payload['persistent_context_pack_id']);
        $this->assertDatabaseCount('atlas_persistent_context_packs', 1);
        $this->assertSame('atlas_dev', AtlasPersistentContextPack::query()->firstOrFail()->flow_id);
    }

    public function test_empty_prompt_blocks_execution_handoff(): void
    {
        $payload = $this->runtime()->build([
            'prompt' => '',
            'workspace' => base_path(),
            'domain' => 'programming',
            'flow_id' => 'atlas_dev',
        ]);

        $this->assertSame(AtlasPersistentContextRuntimeService::STATUS_BLOCKED, $payload['status']);
        $this->assertSame('blocked', data_get($payload, 'sufficiency.status'));
        $this->assertFalse(data_get($payload, 'provider_handoff.execution_allowed'));
    }

    public function test_record_outcome_with_evidence_creates_pending_memory_delta(): void
    {
        $runtime = $this->runtime();
        $context = $runtime->build([
            'prompt' => 'registre decisao APCR',
            'workspace' => base_path(),
            'domain' => 'programming',
            'flow_id' => 'atlas_dev',
        ]);

        $receipt = $runtime->recordOutcome($context, [
            'summary' => 'APCR must run before provider handoff.',
            'evidence_refs' => ['test:apcr'],
        ]);

        $this->assertSame('recorded', $receipt['status']);
        $this->assertFalse($receipt['promotion_allowed']);
        $this->assertSame('pending', AiMemoryDelta::query()->firstOrFail()->status);
        $this->assertTrue(AiMemoryDelta::query()->firstOrFail()->requires_confirmation);
        $this->assertSame($receipt['memory_delta_id'], AtlasPersistentContextPack::query()->firstOrFail()->memory_delta_id);
    }

    public function test_record_outcome_without_evidence_blocks_memory_update(): void
    {
        $runtime = $this->runtime();
        $context = $runtime->build([
            'prompt' => 'registre sem evidencia',
            'workspace' => base_path(),
            'domain' => 'programming',
            'flow_id' => 'atlas_dev',
        ]);

        $receipt = $runtime->recordOutcome($context, [
            'summary' => 'Sem evidencia nao pode virar memoria.',
            'evidence_refs' => [],
        ]);

        $this->assertSame('blocked', $receipt['status']);
        $this->assertSame('missing_evidence_refs', data_get($receipt, 'blockers.0.id'));
        $this->assertDatabaseCount('ai_memory_deltas', 0);
    }

    private function runtime(): AtlasPersistentContextRuntimeService
    {
        $bootstrap = Mockery::mock(AtlasSessionBootstrapService::class);
        $bootstrap->shouldReceive('bootstrap')->andReturn([
            'schema_version' => 'atlas.session_bootstrap.v1',
            'status' => 'ready',
            'read_first' => ['docs/engineering-knowledge-base/atlas-persistent-context-runtime.md'],
            'placement' => ['runtime' => 'apcr'],
            'blocked_when' => [],
            'risks' => ['missing_context'],
            'required_validation' => ['evidence_refs'],
        ]);

        $contextBuilder = Mockery::mock(AiContextPackBuilder::class);
        $contextBuilder->shouldReceive('build')->andReturnUsing(fn () => new AiContextPack([
            'task' => ['objective' => 'APCR test'],
            'surface' => ['workspace' => base_path()],
            'project_state' => ['repo' => 'atlas'],
            'memory' => [
                'decisions' => [['id' => 'd1', 'value' => 'APCR before provider']],
            ],
            'retrieval' => ['included_sources' => ['doc:apcr']],
            'constraints' => ['provider_without_context_forbidden'],
            'open_questions' => [],
        ], [
            ['type' => 'doc', 'id' => 'apcr', 'path' => 'docs/engineering-knowledge-base/atlas-persistent-context-runtime.md'],
        ]));

        return new AtlasPersistentContextRuntimeService(
            $bootstrap,
            $contextBuilder,
            app(AtlasContextIntelligenceService::class),
            app(AtlasContextOperationsRuntimeService::class),
        );
    }
}
