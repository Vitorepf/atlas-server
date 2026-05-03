<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AtlasOpenBrainContextInjectionService;
use App\Services\Ai\ValueObjects\AiContextPack;
use App\Services\Ai\ValueObjects\AiTaskRequest;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use App\Services\Engineering\EngineeringKnowledgeBaseService;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class AtlasOpenBrainContextInjectionServiceTest extends TestCase
{
    private EngineeringKnowledgeBaseService $knowledge;
    private EngineeringCodeIntelligenceService $code;
    private AtlasOpenBrainContextInjectionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->knowledge = $this->createMock(EngineeringKnowledgeBaseService::class);
        $this->code = $this->createMock(EngineeringCodeIntelligenceService::class);
        $this->knowledge->method('contextRefs')->willReturn([]);
        $this->code->method('contextRefs')->willReturn([]);

        Schema::shouldReceive('hasTable')->andReturn(false);

        $this->service = new AtlasOpenBrainContextInjectionService($this->knowledge, $this->code);
    }

    // --- policy: skip paths ---

    public function test_skip_when_mode_is_off(): void
    {
        $result = $this->service->inject(
            'implementar feature X',
            $this->task('dev'),
            $this->pack(),
            ['payload' => ['open_brain' => ['mode' => 'off']]],
        );

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('policy_off', $result['reason']);
        $this->assertNull($result['prompt_section']);
    }

    public function test_skip_when_global_injection_disabled(): void
    {
        config(['atlas.open_brain.injection.enabled' => false]);

        $result = $this->service->inject(
            'implementar feature X',
            $this->task('dev'),
            $this->pack(),
            [],
        );

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('global_disabled', $result['reason']);
    }

    public function test_skip_when_task_type_is_direct(): void
    {
        $result = $this->service->inject(
            'qual e a capital da franca',
            $this->task('direct'),
            $this->pack(),
            [],
        );

        // direct mode: requiresInjection returns false → policy auto-selects 'off' → reason=policy_off
        $this->assertSame('skipped', $result['status']);
        $this->assertSame('policy_off', $result['reason']);
    }

    public function test_skip_reason_is_mode_not_open_brain_when_auto_mode_forced_but_task_is_direct(): void
    {
        $result = $this->service->inject(
            'qual e a capital da franca',
            $this->task('direct'),
            $this->pack(),
            ['payload' => ['open_brain' => ['mode' => 'auto']]],
        );

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('mode_not_open_brain', $result['reason']);
    }

    // --- injection: active paths ---

    public function test_inject_for_dev_mode(): void
    {
        $result = $this->service->inject(
            'implementar feature X',
            $this->task('dev'),
            $this->pack(),
            ['payload' => ['atlas_workflow_mode' => 'dev', 'workspace' => base_path()]],
        );

        $this->assertContains($result['status'], ['injected', 'degraded']);
        $this->assertTrue($result['enabled']);
        $this->assertSame('mode_requires_open_brain', $result['reason']);
        $this->assertNotNull($result['prompt_section']);
        $this->assertStringContainsString('# Atlas Open Brain Context', $result['prompt_section']);
    }

    public function test_inject_for_debug_mode(): void
    {
        $result = $this->service->inject(
            'debug do servico X',
            $this->task('debug'),
            $this->pack(),
            ['payload' => ['atlas_workflow_mode' => 'debug']],
        );

        $this->assertContains($result['status'], ['injected', 'degraded']);
        $this->assertNotNull($result['prompt_section']);
    }

    public function test_inject_for_review_mode(): void
    {
        $result = $this->service->inject(
            'revisar o PR',
            $this->task('review'),
            $this->pack(),
            ['payload' => ['atlas_workflow_mode' => 'review']],
        );

        $this->assertContains($result['status'], ['injected', 'degraded']);
        $this->assertNotNull($result['prompt_section']);
    }

    public function test_inject_for_programming_task_type(): void
    {
        $result = $this->service->inject(
            'construir feature Y',
            $this->task('programming'),
            $this->pack(),
            ['payload' => ['routing_task' => 'programming']],
        );

        $this->assertContains($result['status'], ['injected', 'degraded']);
    }

    // --- prompt section shape ---

    public function test_prompt_section_contains_hash_and_surface(): void
    {
        $result = $this->service->inject(
            'implementar feature X',
            $this->task('dev'),
            $this->pack(),
            ['payload' => ['atlas_workflow_mode' => 'dev', 'app_surface' => 'atlas_cli']],
        );

        $section = $result['prompt_section'] ?? '';
        $this->assertStringContainsString('context_pack_hash:', $section);
        $this->assertStringContainsString('surface: cli_chat', $section);
        $this->assertNotEmpty($result['context_pack_hash']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $result['context_pack_hash']);
    }

    public function test_prompt_section_not_duplicated_when_knowledge_refs_empty(): void
    {
        $result = $this->service->inject(
            'implementar feature X',
            $this->task('dev'),
            $this->pack(),
            ['payload' => ['atlas_workflow_mode' => 'dev']],
        );

        $section = $result['prompt_section'] ?? '';
        $count = substr_count($section, '# Atlas Open Brain Context');
        $this->assertSame(1, $count, 'Open Brain header must appear exactly once');
    }

    // --- budget truncation ---

    public function test_prompt_section_truncated_when_budget_exceeded(): void
    {
        // budget is clamped to max(2000, input); to trigger truncation the section must exceed 2000 chars
        $longSummary = str_repeat('contexto canonico de engenharia relevante para esta tarefa. ', 40);
        $manyRefs = array_map(
            fn (int $i): array => ['title' => "Doc Atlas {$i}", 'canonical_path' => "docs/doc-{$i}.md", 'summary' => $longSummary],
            range(1, 20),
        );

        $knowledge = $this->createMock(EngineeringKnowledgeBaseService::class);
        $knowledge->method('contextRefs')->willReturn($manyRefs);
        $code = $this->createMock(EngineeringCodeIntelligenceService::class);
        $code->method('contextRefs')->willReturn([]);
        Schema::shouldReceive('hasTable')->andReturn(false);

        $service = new AtlasOpenBrainContextInjectionService($knowledge, $code);

        $result = $service->inject(
            'implementar feature X',
            $this->task('dev'),
            $this->pack(),
            ['payload' => ['atlas_workflow_mode' => 'dev']],
        );

        // section must exceed 2000 chars so we can verify truncation at default budget
        $section = $result['prompt_section'] ?? '';
        $this->assertGreaterThan(2000, mb_strlen($section), 'Section with many refs should exceed minimum budget');

        // now test truncation with explicit budget slightly above minimum
        $budget = 2001;
        $result2 = $service->inject(
            'implementar feature X',
            $this->task('dev'),
            $this->pack(),
            ['payload' => ['open_brain' => ['budget_chars' => $budget], 'atlas_workflow_mode' => 'dev']],
        );

        $this->assertContains('open_brain_context_budget_exceeded', $result2['warnings']);
    }

    // --- failure modes ---

    public function test_knowledge_exception_is_swallowed_and_results_in_degraded(): void
    {
        // knowledgeRefs() has its own try-catch: exceptions from knowledge service
        // are swallowed and return [] → no_engineering_knowledge_refs warning → degraded
        $knowledge = $this->createMock(EngineeringKnowledgeBaseService::class);
        $knowledge->method('contextRefs')->willThrowException(new \RuntimeException('db down'));
        $code = $this->createMock(EngineeringCodeIntelligenceService::class);
        $code->method('contextRefs')->willReturn([]);

        $service = new AtlasOpenBrainContextInjectionService($knowledge, $code);

        $result = $service->inject(
            'implementar feature X',
            $this->task('dev'),
            $this->pack(),
            ['payload' => ['atlas_workflow_mode' => 'dev']],
        );

        $this->assertSame('degraded', $result['status']);
        $this->assertContains('no_engineering_knowledge_refs', $result['warnings']);
    }

    public function test_failed_open_when_context_pack_throws(): void
    {
        // failed_open is triggered by an exception that escapes buildInjection()
        // AiContextPack::toArray() is not wrapped in a try-catch inside the service
        $pack = $this->createMock(AiContextPack::class);
        $pack->method('toArray')->willThrowException(new \RuntimeException('pack corrupted'));
        $pack->method('contextRefs')->willReturn([]);

        $result = $this->service->inject(
            'implementar feature X',
            $this->task('dev'),
            $pack,
            ['payload' => ['atlas_workflow_mode' => 'dev']],
        );

        $this->assertSame('failed_open', $result['status']);
        $this->assertNull($result['prompt_section']);
    }

    public function test_failed_closed_when_exception_and_mode_required(): void
    {
        $knowledge = $this->createMock(EngineeringKnowledgeBaseService::class);
        $knowledge->method('contextRefs')->willThrowException(new \RuntimeException('db down'));
        $code = $this->createMock(EngineeringCodeIntelligenceService::class);
        $code->method('contextRefs')->willReturn([]);

        $service = new AtlasOpenBrainContextInjectionService($knowledge, $code);

        $result = $service->inject(
            'implementar feature X',
            $this->task('dev'),
            $this->pack(),
            ['payload' => ['open_brain' => ['mode' => 'required'], 'atlas_workflow_mode' => 'dev']],
        );

        $this->assertSame('failed_closed', $result['status']);
    }

    // --- assertAllowed ---

    public function test_assert_allowed_throws_on_failed_closed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Open Brain context injection is required but not ready.');

        $this->service->assertAllowed(['status' => 'failed_closed']);
    }

    public function test_assert_allowed_does_not_throw_on_injected(): void
    {
        $this->service->assertAllowed(['status' => 'injected']);
        $this->assertTrue(true);
    }

    public function test_assert_allowed_does_not_throw_on_skipped(): void
    {
        $this->service->assertAllowed(['status' => 'skipped']);
        $this->assertTrue(true);
    }

    // --- surface detection ---

    public function test_surface_resolves_to_cli_dev_when_atlas_cli_has_dev_plan(): void
    {
        $result = $this->service->inject(
            'implementar X',
            $this->task('dev'),
            $this->pack(),
            ['payload' => [
                'atlas_workflow_mode' => 'dev',
                'app_surface' => 'atlas_cli',
                'dev_execution_plan' => ['plan_id' => 'abc123'],
            ]],
        );

        $this->assertSame('cli_dev', $result['surface']);
    }

    public function test_surface_resolves_to_cli_continue_when_atlas_cli_has_resumed_plan(): void
    {
        $result = $this->service->inject(
            'implementar X',
            $this->task('dev'),
            $this->pack(),
            ['payload' => [
                'atlas_workflow_mode' => 'dev',
                'app_surface' => 'atlas_cli',
                'dev_execution_plan' => [
                    'plan_id' => 'abc123',
                    'resumed_at' => now()->toJSON(),
                ],
            ]],
        );

        $this->assertSame('cli_continue', $result['surface']);
    }

    public function test_surface_resolves_to_app_ai(): void
    {
        $result = $this->service->inject(
            'implementar X',
            $this->task('dev'),
            $this->pack(),
            ['payload' => [
                'atlas_workflow_mode' => 'dev',
                'app_surface' => 'atlas_ai_app',
            ]],
        );

        $this->assertSame('app_ai', $result['surface']);
    }

    // --- summary shape ---

    public function test_summary_keys_are_present(): void
    {
        $result = $this->service->inject(
            'dev task',
            $this->task('dev'),
            $this->pack(),
            ['payload' => ['atlas_workflow_mode' => 'dev']],
        );

        $summary = $result['summary'];
        $this->assertArrayHasKey('context_refs', $summary);
        $this->assertArrayHasKey('memory_refs', $summary);
        $this->assertArrayHasKey('knowledge_refs', $summary);
        $this->assertArrayHasKey('code_refs', $summary);
        $this->assertArrayHasKey('budget_chars', $summary);
        $this->assertArrayHasKey('used_chars', $summary);
        $this->assertArrayHasKey('provider_safe', $summary);
        $this->assertTrue($summary['provider_safe']);
    }

    // --- knowledge/code refs in prompt ---

    public function test_prompt_section_includes_knowledge_refs_when_present(): void
    {
        $knowledge = $this->createMock(EngineeringKnowledgeBaseService::class);
        $knowledge->method('contextRefs')->willReturn([
            ['title' => 'Atlas Engineering Blueprint', 'canonical_path' => 'docs/engineering-blueprint.md', 'summary' => 'Blueprint doc'],
        ]);
        $code = $this->createMock(EngineeringCodeIntelligenceService::class);
        $code->method('contextRefs')->willReturn([]);

        $service = new AtlasOpenBrainContextInjectionService($knowledge, $code);

        $result = $service->inject(
            'dev task',
            $this->task('dev'),
            $this->pack(),
            ['payload' => ['atlas_workflow_mode' => 'dev']],
        );

        $this->assertStringContainsString('## Canonical Engineering Knowledge', $result['prompt_section'] ?? '');
        $this->assertStringContainsString('Atlas Engineering Blueprint', $result['prompt_section'] ?? '');
    }

    public function test_prompt_section_includes_code_refs_when_present(): void
    {
        $knowledge = $this->createMock(EngineeringKnowledgeBaseService::class);
        $knowledge->method('contextRefs')->willReturn([]);
        $code = $this->createMock(EngineeringCodeIntelligenceService::class);
        $code->method('contextRefs')->willReturn([
            ['name' => 'Atlas AI Services', 'root_path' => 'app/Services/Ai', 'layer' => 'service', 'symbol_count' => 100, 'test_count' => 10, 'reason' => 'important'],
        ]);

        $service = new AtlasOpenBrainContextInjectionService($knowledge, $code);

        $result = $service->inject(
            'dev task',
            $this->task('dev'),
            $this->pack(),
            ['payload' => ['atlas_workflow_mode' => 'dev']],
        );

        $this->assertStringContainsString('## Code Intelligence Refs', $result['prompt_section'] ?? '');
        $this->assertStringContainsString('Atlas AI Services', $result['prompt_section'] ?? '');
    }

    // --- helpers ---

    private function task(string $type): AiTaskRequest
    {
        return AiTaskRequest::fromInput(
            'tarefa de teste',
            ['payload' => ['atlas_workflow_mode' => $type]],
            ['agent' => 'desenvolvedor', 'intent' => 'test'],
        );
    }

    private function pack(): AiContextPack
    {
        return new AiContextPack(
            data: [
                'task' => ['type' => 'dev', 'desired_mode' => 'dev', 'risk_level' => 'low', 'domain' => 'developer', 'objective' => 'test'],
                'surface' => ['kind' => 'mac_cli', 'workspace' => base_path()],
                'memory' => ['recall' => [], 'registry' => [], 'verbatim' => [], 'semantic' => []],
                'constraints' => [],
            ],
            contextRefs: [],
        );
    }
}
