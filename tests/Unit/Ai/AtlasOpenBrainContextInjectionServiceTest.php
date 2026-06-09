<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AtlasMemoryQualityService;
use App\Services\Ai\AtlasOpenBrainContextInjectionService;
use App\Services\Ai\ValueObjects\AiContextPack;
use App\Services\Ai\ValueObjects\AiTaskRequest;
use App\Services\Engineering\CodeGraph\CodeGraphContextRetriever;
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

    // --- AP-815 I-4 (Stage 2): code-graph pack reaches the provider prompt, flag-gated ---

    public function test_code_graph_pack_reaches_the_provider_prompt_when_flag_on(): void
    {
        config()->set('atlas.code_graph.auto_context', true);

        [$service, $retriever] = $this->serviceWithCodeGraph([
            'included' => [[
                'id' => 'sym:App\\Services\\Engineering\\CodeGraph\\CodeGraphSecretScanner',
                'tokens' => 42,
                'signature' => 'class CodeGraphSecretScanner',
                'symbol_type' => 'class',
                'file_path' => 'app/Services/Engineering/CodeGraph/CodeGraphSecretScanner.php',
            ]],
            'excluded' => [],
            'estimated_tokens' => 42,
            'budget' => 4000,
            'truncated' => false,
            'count' => 1,
        ]);

        $result = $service->inject(
            'fix the secret scanner',
            $this->task('dev'),
            $this->pack(),
            ['payload' => ['atlas_workflow_mode' => 'dev', 'workspace' => base_path()]],
        );

        // The retriever (the proven atlas:ctx path) was consulted exactly once with the
        // operator's prompt as the query.
        $this->assertSame(1, $retriever->calls, 'flag ON must consult the shared retriever once');
        $this->assertSame('fix the secret scanner', $retriever->lastQuery);

        // The ranked symbol reaches the assembled provider prompt string (no provider call).
        $this->assertNotNull($result['prompt_section']);
        $this->assertStringContainsString('## Code Graph Context', $result['prompt_section']);
        $this->assertStringContainsString('sym:App\\Services\\Engineering\\CodeGraph\\CodeGraphSecretScanner', $result['prompt_section']);
        $this->assertStringContainsString('class CodeGraphSecretScanner', $result['prompt_section']);

        // It is also exposed as a context ref so downstream consumers can enumerate it.
        $codeGraphRefIds = collect($result['context_refs'])
            ->filter(fn (array $ref): bool => str_starts_with((string) ($ref['id'] ?? ''), 'sym:'))
            ->map(fn (array $ref): string => (string) $ref['id'])
            ->all();
        $this->assertContains('sym:App\\Services\\Engineering\\CodeGraph\\CodeGraphSecretScanner', $codeGraphRefIds);
    }

    public function test_code_graph_pack_is_a_byte_identical_noop_when_flag_off(): void
    {
        // Control: flag OFF (default) with a spy retriever that MUST never be touched.
        config()->set('atlas.code_graph.auto_context', false);
        [$serviceOff, $retrieverOff] = $this->serviceWithCodeGraph([
            'included' => [[
                'id' => 'sym:ShouldNeverAppear',
                'tokens' => 10,
                'signature' => 'class ShouldNeverAppear',
                'symbol_type' => 'class',
                'file_path' => 'app/ShouldNeverAppear.php',
            ]],
            'excluded' => [], 'estimated_tokens' => 10, 'budget' => 4000, 'truncated' => false, 'count' => 1,
        ]);

        $args = [
            'fix the secret scanner',
            $this->task('dev'),
            $this->pack(),
            ['payload' => ['atlas_workflow_mode' => 'dev', 'workspace' => base_path()]],
        ];

        $offResult = $serviceOff->inject(...$args);

        // The flag-OFF path must NOT resolve/consult the retriever at all (true no-op).
        $this->assertSame(0, $retrieverOff->calls, 'flag OFF must never touch the retriever');
        $this->assertStringNotContainsString('## Code Graph Context', (string) $offResult['prompt_section']);
        $this->assertStringNotContainsString('sym:ShouldNeverAppear', (string) $offResult['prompt_section']);

        // Byte-identity: a baseline service WITHOUT any code-graph wiring produces the exact
        // same prompt + hash for the same input, proving the off-path is a pure no-op.
        $baseline = new AtlasOpenBrainContextInjectionService($this->knowledge, $this->code);
        $baseResult = $baseline->inject(...$args);

        $this->assertSame($baseResult['prompt_section'], $offResult['prompt_section'], 'flag-OFF prompt must be byte-identical to the no-wiring baseline');
        $this->assertSame($baseResult['context_pack_hash'], $offResult['context_pack_hash'], 'flag-OFF context hash must be byte-identical');
    }

    public function test_code_graph_retrieval_is_biased_by_selected_changed_files(): void
    {
        config()->set('atlas.code_graph.auto_context', true);
        [$service, $retriever] = $this->serviceWithCodeGraph([
            'included' => [], 'excluded' => [], 'estimated_tokens' => 0, 'budget' => 4000, 'truncated' => false, 'count' => 0,
        ]);

        $service->inject(
            'repair the failing thing',
            $this->task('dev'),
            $this->pack(),
            ['payload' => [
                'atlas_workflow_mode' => 'dev',
                'workspace' => base_path(),
                'dev_execution_plan' => [
                    'selected_files' => ['app/Services/Engineering/CodeGraph/CodeGraphSecretScanner.php'],
                    'engineering_contract' => ['likely_files' => ['tests/Unit/CodeGraph/CodeGraphSecretScannerTest.php']],
                ],
            ]],
        );

        // The same selected/likely files programmingContextSummary() uses bias the BM25
        // retrieval (passed verbatim as the changedFiles argument).
        $this->assertSame(1, $retriever->calls);
        $this->assertContains('app/Services/Engineering/CodeGraph/CodeGraphSecretScanner.php', $retriever->lastChangedFiles);
        $this->assertContains('tests/Unit/CodeGraph/CodeGraphSecretScannerTest.php', $retriever->lastChangedFiles);
        $this->assertSame(4000, $retriever->lastBudget, 'budget defaults to the 4000 auto_context_budget');
    }

    public function test_inject_for_programming_repair_routing_task_even_when_mode_is_direct(): void
    {
        $result = $this->service->inject(
            'corrigir falha da bateria de testes',
            $this->task('direct'),
            $this->pack(),
            ['payload' => [
                'routing_task' => 'programming.repair',
                'dev_execution_plan' => [
                    'plan_id' => 'plan-repair-1',
                    'parent_plan_id' => 'plan-dev-1',
                    'programming_flow' => 'programming.repair',
                    'programming_profile' => 'dev',
                    'agentic_rag_plan' => [
                        'schema_version' => 'atlas.programming.agentic_rag.plan.v1',
                        'status' => 'ready',
                        'required_sources' => ['code_symbols', 'related_tests'],
                        'missing_required_sources' => [],
                        'retrieval_receipt' => ['receipt_id' => 'rag-receipt-1'],
                        'context_sufficiency_gate' => ['status' => 'passed'],
                        'semantic_code_graph' => ['node_count' => 3, 'edge_count' => 2],
                    ],
                    'operator_options' => ['auto_test' => true],
                    'engineering_contract' => [
                        'likely_files' => ['app/Services/Ai/Foo.php', 'tests/Unit/FooTest.php'],
                    ],
                ],
                'programming_repair' => ['enabled' => true],
            ]],
        );

        $this->assertContains($result['status'], ['injected', 'degraded']);
        $this->assertSame('programming.repair', data_get($result, 'summary.programming_context.flow'));
        $this->assertTrue(data_get($result, 'summary.programming_context.stage_contract.repair'));
        $this->assertTrue(data_get($result, 'summary.programming_context.stage_contract.test'));
        $this->assertSame(2, data_get($result, 'summary.programming_context.selected_file_count'));
        $this->assertSame('ready', data_get($result, 'summary.programming_context.agentic_rag.status'));
        $this->assertSame('rag-receipt-1', data_get($result, 'summary.programming_context.agentic_rag.retrieval_receipt_id'));
        $this->assertStringContainsString('## Programming Context', $result['prompt_section'] ?? '');
        $this->assertStringContainsString('flow: programming.repair', $result['prompt_section'] ?? '');
        $this->assertStringContainsString('agentic_rag: status=ready; context_gate=passed; receipt=rag-receipt-1', $result['prompt_section'] ?? '');
        $this->assertStringContainsString('stages: plan=true; review=false; patch=true; test=true; repair=true', $result['prompt_section'] ?? '');
    }

    public function test_programming_context_summarizes_history_and_prior_decisions(): void
    {
        $result = $this->service->inject(
            'continuar tarefa quebrada',
            $this->task('dev'),
            new AiContextPack(
                data: [
                    'task' => ['type' => 'dev', 'desired_mode' => 'dev', 'risk_level' => 'high', 'domain' => 'developer', 'objective' => 'continue'],
                    'surface' => ['kind' => 'mac_cli', 'workspace' => base_path()],
                    'prior_runs' => [
                        ['id' => 'run-1', 'status' => 'failed'],
                    ],
                    'evidence' => [
                        'previous_traces' => [
                            ['id' => 'trace-1', 'status' => 'failed'],
                        ],
                    ],
                    'continuity' => [
                        'active_state' => [
                            'decisions' => [
                                ['text' => 'Usar Tool Runtime para validar antes de provider.'],
                            ],
                        ],
                    ],
                    'memory' => ['semantic' => []],
                    'constraints' => [],
                ],
                contextRefs: [],
            ),
            ['payload' => [
                'atlas_workflow_mode' => 'dev',
                'routing_task' => 'programming.dev',
                'dev_execution_plan' => [
                    'plan_id' => 'plan-2',
                    'resumed_at' => now()->toJSON(),
                    'parent_plan_id' => 'plan-1',
                    'programming_flow' => 'programming.dev',
                    'programming_profile' => 'dev',
                ],
            ]],
        );

        $this->assertTrue(data_get($result, 'summary.programming_context.resume.resumed'));
        $this->assertSame(1, data_get($result, 'summary.programming_context.prior_run_count'));
        $this->assertSame(1, data_get($result, 'summary.programming_context.previous_trace_count'));
        $this->assertSame(1, data_get($result, 'summary.programming_context.prior_decision_count'));
        $this->assertStringContainsString('history: prior_runs=1; previous_traces=1; prior_decisions=1', $result['prompt_section'] ?? '');
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
        $this->assertStringContainsString('## Context Pack Self-Reflection Gate', $section);
        $this->assertSame('insufficient', data_get($result, 'summary.self_reflection.status'));
        $this->assertContains('context_pack_insufficient', $result['warnings']);
        $this->assertNotEmpty($result['context_pack_hash']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $result['context_pack_hash']);
    }

    public function test_retrieval_plan_is_summarized_for_open_brain_audit_and_prompt_header(): void
    {
        $result = $this->service->inject(
            'implementar feature X',
            $this->task('dev'),
            new AiContextPack(
                data: [
                    'task' => ['type' => 'dev', 'desired_mode' => 'dev', 'risk_level' => 'low', 'domain' => 'developer', 'objective' => 'test'],
                    'surface' => ['kind' => 'mac_cli', 'workspace' => base_path()],
                    'retrieval' => [
                        'schema_version' => 'atlas.context.retrieval_plan.v1',
                        'mode' => 'audit_heavy',
                        'selected_sources' => [
                            ['type' => 'evidence_replay', 'required' => true, 'limit' => 8],
                            ['type' => 'code_intelligence', 'required' => false, 'limit' => 12],
                            ['type' => 'memory_signals', 'required' => false, 'limit' => 8],
                        ],
                        'budgets' => ['max_context_refs' => 28],
                        'policy' => ['provider_safe_only' => true],
                    ],
                    'memory' => ['semantic' => [['title' => 'Atlas']]],
                    'constraints' => [],
                ],
                contextRefs: [['type' => 'semantic_note', 'id' => 1]],
            ),
            ['payload' => ['atlas_workflow_mode' => 'dev', 'workspace' => base_path()]],
        );

        $this->assertSame('audit_heavy', data_get($result, 'summary.retrieval_plan.mode'));
        $this->assertSame(['evidence_replay', 'code_intelligence', 'memory_signals'], data_get($result, 'summary.retrieval_plan.selected_sources'));
        $this->assertSame(['evidence_replay'], data_get($result, 'summary.retrieval_plan.required_sources'));
        $this->assertSame(['memory_signals'], data_get($result, 'summary.retrieval_plan.available_sources'));
        $this->assertSame(['evidence_replay'], data_get($result, 'summary.retrieval_plan.required_unavailable_sources'));
        $this->assertSame('blocking', data_get($result, 'summary.retrieval_plan.review_signal.status'));
        $this->assertSame('refresh_evidence_replay_or_attach_trace_before_retry', data_get($result, 'summary.retrieval_plan.review_signal.recommended_action'));
        $this->assertSame(28, data_get($result, 'summary.retrieval_plan.max_context_refs'));
        $this->assertContains('retrieval_required_source_unavailable', $result['warnings']);
        $this->assertContains('Refresh evidence replay or attach trace/envelope evidence before retrying.', $result['next_actions']);
        $this->assertStringContainsString('retrieval_plan: mode=audit_heavy; selected=evidence_replay,code_intelligence,memory_signals; required=evidence_replay', $result['prompt_section'] ?? '');
    }

    public function test_replay_refs_satisfy_required_evidence_replay_source(): void
    {
        $result = $this->service->inject(
            'validar deploy com replay anexado',
            $this->task('dev'),
            new AiContextPack(
                data: [
                    'task' => ['type' => 'dev', 'desired_mode' => 'dev', 'risk_level' => 'low', 'domain' => 'developer', 'objective' => 'test'],
                    'surface' => ['kind' => 'mac_cli', 'workspace' => base_path()],
                    'retrieval' => [
                        'schema_version' => 'atlas.context.retrieval_plan.v1',
                        'mode' => 'audit_heavy',
                        'selected_sources' => [
                            ['type' => 'evidence_replay', 'required' => true, 'limit' => 8],
                        ],
                        'budgets' => ['max_context_refs' => 8],
                        'policy' => ['provider_safe_only' => true],
                    ],
                    'evidence' => [
                        'replay_refs' => [
                            ['type' => 'ledger_event', 'id' => '01HREPLAYREF000000000001'],
                        ],
                    ],
                    'memory' => ['semantic' => [['title' => 'Atlas']]],
                    'constraints' => [],
                ],
                contextRefs: [['type' => 'semantic_note', 'id' => 1]],
            ),
            ['payload' => [
                'atlas_workflow_mode' => 'dev',
                'workspace' => base_path(),
                'open_brain' => ['mode' => 'auto'],
            ]],
        );

        $this->assertNotSame('failed_closed', $result['status']);
        $this->assertSame(['evidence_replay'], data_get($result, 'summary.retrieval_plan.available_sources'));
        $this->assertSame([], data_get($result, 'summary.retrieval_plan.required_unavailable_sources'));
        $this->assertSame('ok', data_get($result, 'summary.retrieval_plan.review_signal.status'));
        $this->assertNotContains('retrieval_required_source_unavailable', $result['warnings']);
    }

    public function test_required_open_brain_fails_closed_when_required_retrieval_source_is_unavailable(): void
    {
        $result = $this->service->inject(
            'validar deploy com evidencia obrigatoria',
            $this->task('dev'),
            new AiContextPack(
                data: [
                    'task' => ['type' => 'dev', 'desired_mode' => 'dev', 'risk_level' => 'high', 'domain' => 'developer', 'objective' => 'test'],
                    'surface' => ['kind' => 'mac_cli', 'workspace' => base_path()],
                    'retrieval' => [
                        'schema_version' => 'atlas.context.retrieval_plan.v1',
                        'mode' => 'audit_heavy',
                        'selected_sources' => [
                            ['type' => 'evidence_replay', 'required' => true, 'limit' => 8, 'unavailable_action' => 'fail_closed_or_request_review'],
                        ],
                        'budgets' => ['max_context_refs' => 8],
                        'policy' => ['provider_safe_only' => true],
                    ],
                    'memory' => ['semantic' => [['title' => 'Atlas']]],
                    'constraints' => [],
                ],
                contextRefs: [['type' => 'semantic_note', 'id' => 1]],
            ),
            ['payload' => [
                'atlas_workflow_mode' => 'dev',
                'workspace' => base_path(),
                'open_brain' => ['mode' => 'required'],
            ]],
        );

        $this->assertSame('failed_closed', $result['status']);
        $this->assertContains('retrieval_required_source_unavailable', $result['warnings']);
        $this->assertSame(['evidence_replay'], data_get($result, 'summary.retrieval_plan.required_unavailable_sources'));
        $this->assertSame('blocking', data_get($result, 'summary.retrieval_plan.review_signal.status'));
        $this->assertContains('Refresh evidence replay or attach trace/envelope evidence before retrying.', $result['next_actions']);
        $this->assertNull($result['prompt_section']);
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

    public function test_truncation_summary_is_explicit_and_replayable(): void
    {
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

        $budget = 2001;
        $payload = ['payload' => ['open_brain' => ['budget_chars' => $budget], 'atlas_workflow_mode' => 'dev']];

        $first = $service->inject('implementar feature X', $this->task('dev'), $this->pack(), $payload);
        $second = $service->inject('implementar feature X', $this->task('dev'), $this->pack(), $payload);

        $truncationFirst = data_get($first, 'summary.truncation');
        $truncationSecond = data_get($second, 'summary.truncation');

        $this->assertIsArray($truncationFirst);
        $this->assertTrue($truncationFirst['truncated']);
        $this->assertSame($budget, $truncationFirst['budget_chars']);
        $this->assertGreaterThan($budget, $truncationFirst['original_chars']);
        $this->assertGreaterThan(0, $truncationFirst['dropped_chars']);
        $this->assertSame(
            $truncationFirst['original_chars'] - max(0, $truncationFirst['used_chars'] - mb_strlen((string) $truncationFirst['marker'])),
            $truncationFirst['dropped_chars'],
        );
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $truncationFirst['pre_truncation_hash']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $truncationFirst['post_truncation_hash']);
        $this->assertNotSame($truncationFirst['pre_truncation_hash'], $truncationFirst['post_truncation_hash']);
        $this->assertSame("\n[TRUNCATED_BY_ATLAS_OPEN_BRAIN_BUDGET]", $truncationFirst['marker']);
        $this->assertStringContainsString('[TRUNCATED_BY_ATLAS_OPEN_BRAIN_BUDGET]', $first['prompt_section']);

        $this->assertSame($truncationFirst, $truncationSecond, 'Truncation metadata must be deterministic for replay');
    }

    public function test_truncation_summary_is_inert_when_budget_not_exceeded(): void
    {
        $result = $this->service->inject(
            'implementar feature X',
            $this->task('dev'),
            $this->pack(),
            ['payload' => ['atlas_workflow_mode' => 'dev']],
        );

        $truncation = data_get($result, 'summary.truncation');
        $this->assertIsArray($truncation);
        $this->assertFalse($truncation['truncated']);
        $this->assertSame(0, $truncation['dropped_chars']);
        $this->assertNull($truncation['marker']);
        $this->assertSame($truncation['pre_truncation_hash'], $truncation['post_truncation_hash']);
        $this->assertStringNotContainsString('[TRUNCATED_BY_ATLAS_OPEN_BRAIN_BUDGET]', (string) $result['prompt_section']);
    }

    // --- failure modes ---

    public function test_knowledge_exception_is_swallowed_and_results_in_degraded(): void
    {
        // knowledgeRefs() has its own try-catch: exceptions from knowledge service
        // are swallowed and return [] → no_engineering_knowledge_refs warning → degraded
        $knowledge = $this->createMock(EngineeringKnowledgeBaseService::class);
        $knowledge->method('contextRefs')->willThrowException(new RuntimeException('db down'));
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
        $pack->method('toArray')->willThrowException(new RuntimeException('pack corrupted'));
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
        $knowledge->method('contextRefs')->willThrowException(new RuntimeException('db down'));
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

    public function test_prompt_section_includes_memory_quality_gate_when_available(): void
    {
        $memoryQuality = $this->createMock(AtlasMemoryQualityService::class);
        $memoryQuality->method('scorecard')->willReturn([
            'ok' => true,
            'status' => 'ready',
            'score' => 97,
            'counts' => [
                'active' => 5,
                'provider_safe_active' => 5,
            ],
            'issues' => [],
            'latest_snapshot' => [
                'status' => 'ready',
                'score' => 97,
                'snapshot_at' => '2026-05-03T19:00:00Z',
            ],
            'trend' => [
                'status' => 'stable',
                'current_score' => 97,
                'latest_snapshot_score' => 97,
                'snapshot_count' => 1,
                'current_delta_from_latest' => 0,
                'latest_delta_from_previous' => null,
                'window_delta' => null,
            ],
        ]);

        $service = new AtlasOpenBrainContextInjectionService($this->knowledge, $this->code, $memoryQuality);
        $result = $service->inject(
            'dev task',
            $this->task('dev'),
            $this->pack(),
            ['payload' => ['atlas_workflow_mode' => 'dev', 'workspace' => base_path()]],
        );

        $this->assertSame('ready', data_get($result, 'summary.memory_quality.status'));
        $this->assertSame(97, data_get($result, 'summary.memory_quality.score'));
        $this->assertSame('stable', data_get($result, 'summary.memory_quality.trend.status'));
        $this->assertStringContainsString('## Memory Quality Gate', $result['prompt_section'] ?? '');
        $this->assertStringContainsString('status: ready; score=97', $result['prompt_section'] ?? '');
        $this->assertStringContainsString('trend: status=stable', $result['prompt_section'] ?? '');
    }

    public function test_memory_quality_critical_warns_and_fails_closed_when_required(): void
    {
        $memoryQuality = $this->createMock(AtlasMemoryQualityService::class);
        $memoryQuality->method('scorecard')->willReturn([
            'ok' => false,
            'status' => 'critical',
            'score' => 31,
            'counts' => [
                'active' => 2,
                'provider_safe_active' => 0,
            ],
            'issues' => [
                ['code' => 'no_provider_safe_memory', 'severity' => 'critical'],
            ],
            'latest_snapshot' => null,
        ]);

        $service = new AtlasOpenBrainContextInjectionService($this->knowledge, $this->code, $memoryQuality);
        $result = $service->inject(
            'dev task',
            $this->task('dev'),
            $this->pack(),
            ['payload' => [
                'atlas_workflow_mode' => 'dev',
                'open_brain' => ['mode' => 'required'],
            ]],
        );

        $this->assertSame('failed_closed', $result['status']);
        $this->assertContains('memory_quality_critical', $result['warnings']);
        $this->assertContains('memory_quality_no_provider_safe_memory', $result['warnings']);
        $this->assertContains('context_pack_insufficient', $result['warnings']);
        $this->assertSame('critical', data_get($result, 'summary.memory_quality.status'));
    }

    public function test_memory_quality_regression_trend_warns_without_failed_closed_in_auto_mode(): void
    {
        $memoryQuality = $this->createMock(AtlasMemoryQualityService::class);
        $memoryQuality->method('scorecard')->willReturn([
            'ok' => true,
            'status' => 'ready',
            'score' => 86,
            'counts' => [
                'active' => 5,
                'provider_safe_active' => 5,
            ],
            'issues' => [],
            'latest_snapshot' => null,
            'trend' => [
                'status' => 'regressed',
                'current_score' => 86,
                'latest_snapshot_score' => 99,
                'snapshot_count' => 2,
                'current_delta_from_latest' => -13,
                'drivers' => [
                    [
                        'kind' => 'component_drop',
                        'key' => 'provider_safety',
                        'severity' => 'warning',
                        'delta' => -20,
                        'current' => 80,
                        'previous' => 100,
                    ],
                ],
            ],
        ]);

        $service = new AtlasOpenBrainContextInjectionService($this->knowledge, $this->code, $memoryQuality);
        $result = $service->inject(
            'dev task',
            $this->task('dev'),
            $this->pack(),
            ['payload' => ['atlas_workflow_mode' => 'dev']],
        );

        $this->assertSame('degraded', $result['status']);
        $this->assertContains('memory_quality_trend_regressed', $result['warnings']);
        $this->assertSame('regressed', data_get($result, 'summary.memory_quality.trend.status'));
        $this->assertSame('provider_safety', data_get($result, 'summary.memory_quality.trend.drivers.0.key'));
        $this->assertStringContainsString('trend: status=regressed', $result['prompt_section'] ?? '');
        $this->assertStringContainsString('trend_drivers: provider_safety:-20', $result['prompt_section'] ?? '');
    }

    public function test_memory_quality_generated_at_does_not_make_context_hash_drift(): void
    {
        $memoryQuality = $this->createMock(AtlasMemoryQualityService::class);
        $memoryQuality->method('scorecard')->willReturnOnConsecutiveCalls(
            [
                'ok' => true,
                'status' => 'ready',
                'score' => 97,
                'counts' => ['active' => 5, 'provider_safe_active' => 5],
                'issues' => [],
                'latest_snapshot' => null,
                'generated_at' => '2026-05-03T19:00:00Z',
            ],
            [
                'ok' => true,
                'status' => 'ready',
                'score' => 97,
                'counts' => ['active' => 5, 'provider_safe_active' => 5],
                'issues' => [],
                'latest_snapshot' => null,
                'generated_at' => '2026-05-03T19:00:05Z',
            ],
        );

        $service = new AtlasOpenBrainContextInjectionService($this->knowledge, $this->code, $memoryQuality);
        $first = $service->inject('dev task', $this->task('dev'), $this->pack(), ['payload' => ['atlas_workflow_mode' => 'dev']]);
        $second = $service->inject('dev task', $this->task('dev'), $this->pack(), ['payload' => ['atlas_workflow_mode' => 'dev']]);

        $this->assertSame($first['context_pack_hash'], $second['context_pack_hash']);
    }

    // --- helpers ---

    /**
     * Build the service with a SPY {@see CodeGraphContextRetriever} that records each
     * packFor() call and returns a fixed pack — so the code-graph seam is proven against
     * the assembled prompt string with NO DB and NO provider tokens.
     *
     * @param  array<string,mixed>  $pack  the E-3-shaped pack the spy returns from packFor()
     * @return array{0: AtlasOpenBrainContextInjectionService, 1: object}
     */
    private function serviceWithCodeGraph(array $pack): array
    {
        $retriever = new class($pack) extends CodeGraphContextRetriever
        {
            public int $calls = 0;

            public string $lastQuery = '';

            public string $lastWorkspaceId = '';

            public int $lastBudget = 0;

            /** @var array<int,string> */
            public array $lastChangedFiles = [];

            /** @param array<string,mixed> $pack */
            public function __construct(private array $pack)
            {
                parent::__construct(new \App\Services\Engineering\CodeGraph\CodeGraphContextPackAssembler);
            }

            public function packFor(string $query, string $workspaceId, int $budget = self::DEFAULT_BUDGET, array $changedFiles = []): array
            {
                $this->calls++;
                $this->lastQuery = $query;
                $this->lastWorkspaceId = $workspaceId;
                $this->lastBudget = $budget;
                $this->lastChangedFiles = $changedFiles;

                return $this->pack;
            }
        };

        $service = new AtlasOpenBrainContextInjectionService(
            $this->knowledge,
            $this->code,
            null,
            null,
            null,
            $retriever,
        );

        return [$service, $retriever];
    }

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
