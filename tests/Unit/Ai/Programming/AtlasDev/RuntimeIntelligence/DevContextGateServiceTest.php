<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\RuntimeIntelligence;

use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevContextGateService;
use PHPUnit\Framework\TestCase;

final class DevContextGateServiceTest extends TestCase
{
    private DevContextGateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DevContextGateService;
    }

    public function test_focused_unit_test_path_is_same_name_coverage(): void
    {
        $this->assertSame(
            'tests/Unit/Ai/Programming/AtlasDev/RuntimeIntelligence/DevContextGateServiceTest.php',
            DevContextGateService::focusedUnitTestPath(),
        );
    }

    public function test_schema_version_constant_matches_contract(): void
    {
        $this->assertSame('atlas.dev.context_gate.v1', DevContextGateService::SCHEMA_VERSION);
    }

    public function test_evaluate_passes_for_complete_medium_risk_packet(): void
    {
        $result = $this->service->evaluate([
            'run_id' => 'dev-run-1',
            'task_id' => 'task-1',
            'objective' => 'Fix scoped bug in context gate',
            'task_class' => 'feature',
            'risk_band' => 'medium',
            'context_refs' => ['docs/engineering-knowledge-base/atlas-dev-runtime-intelligence.md'],
            'allowed_files' => ['app/Services/Foo.php'],
            'suggested_tests' => ['php artisan test --filter=Foo'],
        ]);

        $this->assertSame(DevContextGateService::STATUS_PASSED, $result['status']);
        $this->assertTrue($result['provider_safe']);
        $this->assertSame([], $result['missing']);
        $this->assertSame(DevContextGateService::SCHEMA_VERSION, $result['schema_version']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $result['context_gate_hash']);
    }

    public function test_evaluate_blocks_high_risk_without_real_context_refs(): void
    {
        $result = $this->service->evaluate([
            'objective' => 'Rewrite critical provider routing',
            'task_class' => 'feature',
            'risk_band' => 'high',
            'allowed_files' => ['app/Services/Provider.php'],
            'suggested_tests' => ['php artisan test --filter=Provider'],
            'acceptance_criteria' => ['routing stays policy-driven'],
        ]);

        $this->assertSame(DevContextGateService::STATUS_BLOCKED, $result['status']);
        $this->assertFalse($result['provider_safe']);
        $this->assertContains('owner_docs_or_context_refs', $result['missing']);
    }

    public function test_aedpds_context_refs_do_not_satisfy_scope_or_owner_docs(): void
    {
        $result = $this->service->evaluate([
            'objective' => 'Ship scoped Dev repair',
            'task_class' => 'feature',
            'risk_band' => 'high',
            'context_refs' => [
                DevContextGateService::AEDPDS_CONTEXT_PREFIX.'owner_or_relevant_context',
            ],
            'suggested_tests' => ['php artisan test --filter=DevContextGate'],
            'acceptance_criteria' => ['provider-safe only with real docs'],
        ]);

        $this->assertContains('context_or_scope', $result['missing']);
        $this->assertContains('owner_docs_or_context_refs', $result['missing']);
        $this->assertSame(DevContextGateService::STATUS_BLOCKED, $result['status']);
    }

    public function test_allowed_files_satisfies_scope_without_real_context_refs_for_medium_risk(): void
    {
        $result = $this->service->evaluate([
            'objective' => 'Fix scoped bug',
            'task_class' => 'feature',
            'risk_band' => 'medium',
            'context_refs' => [DevContextGateService::AEDPDS_CONTEXT_PREFIX.'only_synthetic'],
            'allowed_files' => ['app/Services/Foo.php'],
            'suggested_tests' => ['php artisan test --filter=Foo'],
        ]);

        $this->assertNotContains('context_or_scope', $result['missing']);
        $this->assertSame(DevContextGateService::STATUS_PASSED, $result['status']);
        $this->assertTrue($result['provider_safe']);
    }

    public function test_deferred_verification_handles_satisfy_plan_without_dumping_tests_first(): void
    {
        $result = $this->service->evaluate([
            'objective' => 'Improve provider context retrieval',
            'task_class' => 'feature',
            'risk_band' => 'medium',
            'allowed_files' => ['app/Services/Ai/ContextIntelligence/AtlasContextIntelligenceService.php'],
            'verification_handles' => ['expand:tests:context-intelligence', 'expand:command:atlas:context-intelligence:certify'],
            'expansion_handles' => ['expand:docs:atlas-context-intelligence-engine', 'expand:graph:context-intelligence'],
            'initial_context_kinds' => ['objective', 'owner_doc_ref', 'file_refs'],
            'initial_context_chars' => 4200,
        ]);

        $this->assertSame(DevContextGateService::STATUS_PASSED, $result['status']);
        $this->assertTrue($result['provider_safe']);
        $this->assertSame([], $result['missing']);
        $this->assertTrue($result['context_delivery_policy']['expansion_handles_present']);
        $this->assertTrue($result['context_delivery_policy']['verification_content_deferred']);
        $this->assertSame('minimal_provider_safe', $result['context_delivery_policy']['initial_context_contract']);
    }

    public function test_initial_context_with_full_tests_or_docs_is_not_provider_safe(): void
    {
        $result = $this->service->evaluate([
            'objective' => 'Implement scoped context fix',
            'task_class' => 'feature',
            'risk_band' => 'medium',
            'allowed_files' => ['app/Services/Ai/ContextIntelligence/AtlasContextIntelligenceService.php'],
            'verification_handles' => ['expand:tests:context-intelligence'],
            'initial_context_kinds' => ['objective', 'tests', 'full_doc'],
            'initial_context_chars' => 18000,
            'max_initial_context_chars' => 8000,
        ]);

        $this->assertSame(DevContextGateService::STATUS_NEEDS_REVIEW, $result['status']);
        $this->assertFalse($result['provider_safe']);
        $this->assertContains('initial_context_too_large', $result['missing']);
        $this->assertContains('initial_context_contains_deferred_material', $result['missing']);
    }

    public function test_placeholder_objective_is_not_provider_safe(): void
    {
        $result = $this->service->evaluate([
            'objective' => 'Atlas Dev task',
            'task_class' => 'feature',
            'risk_band' => 'medium',
            'allowed_files' => ['app/Services/Foo.php'],
            'suggested_tests' => ['php artisan test --filter=Foo'],
        ]);

        $this->assertContains('objective', $result['missing']);
        $this->assertSame(DevContextGateService::STATUS_NEEDS_REVIEW, $result['status']);
        $this->assertFalse($result['provider_safe']);
    }

    public function test_trivial_task_skips_context_and_verification_requirements(): void
    {
        $result = $this->service->evaluate([
            'objective' => 'Rename local variable',
            'task_class' => 'trivial',
            'risk_band' => 'medium',
        ]);

        $this->assertSame(DevContextGateService::STATUS_PASSED, $result['status']);
        $this->assertTrue($result['provider_safe']);
        $this->assertSame([], $result['missing']);
    }

    public function test_medium_risk_missing_scope_needs_review_not_blocked(): void
    {
        $result = $this->service->evaluate([
            'objective' => 'Implement small feature',
            'task_class' => 'feature',
            'risk_band' => 'medium',
            'suggested_tests' => ['php artisan test --filter=Feature'],
        ]);

        $this->assertContains('context_or_scope', $result['missing']);
        $this->assertSame(DevContextGateService::STATUS_NEEDS_REVIEW, $result['status']);
        $this->assertFalse($result['provider_safe']);
    }

    public function test_context_gate_hash_is_deterministic_for_same_input(): void
    {
        $packet = [
            'run_id' => 'dev-run-hash',
            'task_id' => 'task-hash',
            'objective' => 'Deterministic hash check',
            'task_class' => 'feature',
            'risk_band' => 'medium',
            'allowed_files' => ['app/Services/Foo.php'],
            'suggested_tests' => ['php artisan test --filter=Foo'],
        ];

        $first = $this->service->evaluate($packet);
        $second = $this->service->evaluate($packet);

        $this->assertSame($first['context_gate_hash'], $second['context_gate_hash']);
    }
}
