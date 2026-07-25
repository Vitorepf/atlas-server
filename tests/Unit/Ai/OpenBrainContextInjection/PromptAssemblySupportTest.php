<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\OpenBrainContextInjection;

use App\Services\Ai\OpenBrainContextInjection\PromptAssemblySupport as Support;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pure Support peel for Open Brain injection prompt assembly — no I/O, no host, no DB.
 *
 * Explicit path proof: AtlasOpenBrainContextInjectionService imports Support and no
 * longer declares the peeled private assembly methods.
 */
final class PromptAssemblySupportTest extends TestCase
{
    private const SUPPORT_PATH = 'app/Services/Ai/OpenBrainContextInjection/PromptAssemblySupport.php';

    private const HOST_PATH = 'app/Services/Ai/AtlasOpenBrainContextInjectionService.php';

    /** @var list<string> */
    private const PEELED = [
        'operatorContextWarnings',
        'memoryQualitySummary',
        'memoryQualityTrendSummary',
        'memoryQualityWarnings',
        'orderFusedSourceRefs',
        'fusionCandidateMatchesRef',
        'mergeRefs',
        'isRefProviderSafe',
        'filterProviderUnsafeRefs',
        'programmingContextSummary',
        'promptSection',
        'providerSafeOperatorItemLine',
        'lowerString',
    ];

    #[Test]
    public function explicit_path_proof_support_and_host_files_exist_and_host_calls_support(): void
    {
        $root = dirname(__DIR__, 4);
        $supportAbs = $root.'/'.self::SUPPORT_PATH;
        $hostAbs = $root.'/'.self::HOST_PATH;

        $this->assertFileExists($supportAbs, 'Support peel must live at '.self::SUPPORT_PATH);
        $this->assertFileExists($hostAbs, 'Host must remain at '.self::HOST_PATH);

        $hostSrc = (string) file_get_contents($hostAbs);
        $this->assertStringContainsString(
            'use App\Services\Ai\OpenBrainContextInjection\PromptAssemblySupport;',
            $hostSrc,
            'Host must import PromptAssemblySupport',
        );

        foreach ([
            'PromptAssemblySupport::memoryQualitySummary',
            'PromptAssemblySupport::filterProviderUnsafeRefs',
            'PromptAssemblySupport::orderFusedSourceRefs',
            'PromptAssemblySupport::mergeRefs',
            'PromptAssemblySupport::programmingContextSummary',
            'PromptAssemblySupport::memoryQualityWarnings',
            'PromptAssemblySupport::operatorContextWarnings',
            'PromptAssemblySupport::promptSection',
        ] as $call) {
            $this->assertStringContainsString($call, $hostSrc, "Host must call {$call}");
        }

        foreach (self::PEELED as $method) {
            if ($method === 'lowerString') {
                // Host may keep private string() for policy surface helpers.
                continue;
            }
            $this->assertStringNotContainsString(
                'private function '.$method,
                $hostSrc,
                "Peeled method residual on host: {$method}",
            );
            $this->assertStringNotContainsString(
                'private static function '.$method,
                $hostSrc,
                "Peeled static residual on host: {$method}",
            );
        }

        $support = new ReflectionClass(Support::class);
        foreach (self::PEELED as $method) {
            $this->assertTrue($support->hasMethod($method), "Support must expose {$method}");
            $ref = $support->getMethod($method);
            $this->assertTrue($ref->isPublic() && $ref->isStatic(), "{$method} must be public static");
        }
    }

    #[Test]
    public function operator_context_warnings_flag_unavailable_and_disabled(): void
    {
        $this->assertSame(
            ['operator_context_unavailable'],
            Support::operatorContextWarnings(['status' => 'unavailable']),
        );
        $this->assertSame(
            ['operator_context_disabled'],
            Support::operatorContextWarnings(['status' => 'ok', 'enabled' => false]),
        );
        $this->assertSame([], Support::operatorContextWarnings(['status' => 'ok', 'enabled' => true]));
    }

    #[Test]
    public function memory_quality_summary_and_warnings_project_provider_safe_fields(): void
    {
        $this->assertSame(['included' => false], Support::memoryQualitySummary(null));

        $raw = [
            'ok' => true,
            'status' => 'needs_review',
            'score' => 55,
            'counts' => ['active' => 4, 'provider_safe_active' => 2],
            'latest_snapshot' => ['status' => 'watch', 'score' => 60, 'snapshot_at' => '2026-01-01T00:00:00Z'],
            'trend' => [
                'status' => 'regressed',
                'current_score' => 55,
                'snapshot_count' => 3,
                'current_delta_from_latest' => -5,
                'drivers' => [
                    ['kind' => 'issue', 'key' => 'no_provider_safe_memory', 'severity' => 'warning', 'delta' => -2],
                ],
            ],
            'issues' => [
                ['code' => 'no_provider_safe_memory', 'severity' => 'warning', 'count' => 1],
                ['code' => 'accepted_learning_not_promoted', 'severity' => 'info'],
            ],
        ];

        $summary = Support::memoryQualitySummary($raw);
        $this->assertTrue($summary['included']);
        $this->assertSame('needs_review', $summary['status']);
        $this->assertSame(55, $summary['score']);
        $this->assertSame(2, $summary['provider_safe_active']);
        $this->assertSame('regressed', $summary['trend']['status'] ?? null);
        $this->assertCount(2, $summary['issues']);

        $warnings = Support::memoryQualityWarnings($raw);
        $this->assertContains('memory_quality_needs_review', $warnings);
        $this->assertContains('memory_quality_score_low', $warnings);
        $this->assertContains('memory_quality_trend_regressed', $warnings);
        $this->assertContains('memory_quality_no_provider_safe_memory', $warnings);
        $this->assertContains('memory_quality_accepted_learning_not_promoted', $warnings);
    }

    #[Test]
    public function provider_safe_choke_point_drops_unsafe_refs_and_records_markers(): void
    {
        $this->assertTrue(Support::isRefProviderSafe(['id' => 'ok']));
        $this->assertFalse(Support::isRefProviderSafe(['quarantine' => true]));
        $this->assertFalse(Support::isRefProviderSafe(['provider_safe' => false]));

        $reasons = [];
        $omitted = 0;
        $kept = Support::filterProviderUnsafeRefs([
            ['id' => 'a', 'type' => 'doc'],
            ['id' => 'b', 'quarantine' => true, 'hostile_memory' => true],
            ['id' => 'c', 'provider_safe' => false],
            'not-an-array-kept',
        ], $reasons, $omitted);

        $this->assertSame(2, $omitted);
        $this->assertSame(['a', 'not-an-array-kept'], array_map(
            static fn (mixed $ref): string => is_array($ref) ? (string) ($ref['id'] ?? '') : (string) $ref,
            $kept,
        ));
        $this->assertContains('quarantine', $reasons);
        $this->assertContains('hostile_memory', $reasons);
        $this->assertContains('provider_safe_false', $reasons);
    }

    #[Test]
    public function merge_and_order_fused_source_refs_are_deterministic(): void
    {
        $merged = Support::mergeRefs(
            [['type' => 'doc', 'id' => 'd1']],
            [['type' => 'doc', 'id' => 'd1'], ['type' => 'code', 'id' => 'c1']],
        );
        $this->assertCount(2, $merged);
        $this->assertSame(['d1', 'c1'], array_column($merged, 'id'));

        $this->assertTrue(Support::fusionCandidateMatchesRef('code', 'sym:1', 'sym:1'));
        $this->assertTrue(Support::fusionCandidateMatchesRef('memory', 'abc', 'prefix:abc'));
        $this->assertFalse(Support::fusionCandidateMatchesRef('memory', 'abc', 'other'));

        $ordered = Support::orderFusedSourceRefs(
            [['id' => 'code-late'], ['id' => 'code-first']],
            [['id' => 'ws:mem-1']],
            [['id' => 'reality-1']],
            [
                ['source' => 'code', 'ref' => 'code-first'],
                ['source' => 'memory', 'ref' => 'mem-1'],
                ['source' => 'unknown', 'ref' => 'x'],
            ],
        );

        $this->assertSame(
            ['code-first', 'ws:mem-1', 'code-late', 'reality-1'],
            array_column($ordered, 'id'),
        );
    }

    #[Test]
    public function programming_context_summary_projects_flow_stage_and_files(): void
    {
        $summary = Support::programmingContextSummary(
            [
                'programming_flow' => 'Programming.Repair',
                'programming_profile' => 'Forge',
                'programming_intent' => 'fix-tests',
                'programming_repair' => ['enabled' => true],
                'dev_execution_plan' => [
                    'plan_id' => 'p-1',
                    'parent_plan_id' => 'p-0',
                    'resumed_at' => '2026-01-01',
                    'selected_files' => ['app/A.php'],
                    'operator_options' => ['auto_test' => true],
                    'agentic_rag_plan' => [
                        'schema_version' => 'v1',
                        'status' => 'ready',
                        'required_sources' => ['code'],
                        'missing_required_sources' => [],
                        'retrieval_receipt' => ['receipt_id' => 'r1'],
                        'context_sufficiency_gate' => ['status' => 'pass'],
                        'semantic_code_graph' => ['node_count' => 3, 'edge_count' => 2],
                    ],
                ],
            ],
            [
                'selected_files' => ['app/B.php'],
                'prior_runs' => [['id' => 1]],
                'evidence' => ['previous_traces' => [['id' => 't1']]],
                'decisions' => ['ship-it'],
            ],
        );

        $this->assertSame('atlas.programming.open_brain_context.v1', $summary['schema_version']);
        $this->assertSame('programming.repair', $summary['flow']);
        $this->assertSame('forge', $summary['profile']);
        $this->assertTrue($summary['resume']['resumed']);
        $this->assertSame('p-1', $summary['resume']['plan_id']);
        $this->assertTrue($summary['stage_contract']['repair']);
        $this->assertTrue($summary['stage_contract']['test']);
        $this->assertSame(['app/B.php', 'app/A.php'], $summary['selected_files']);
        $this->assertSame('ready', $summary['agentic_rag']['status'] ?? null);
        $this->assertSame('r1', $summary['agentic_rag']['retrieval_receipt_id'] ?? null);
    }

    #[Test]
    public function prompt_section_renders_provider_safe_header_and_ref_blocks(): void
    {
        $text = Support::promptSection(
            taskType: 'coding',
            desiredMode: 'open_brain',
            contextPackPromptSection: "## Pack\npack body",
            contextPackHash: 'hash123',
            summary: [
                'memory_refs' => 1,
                'verbatim_refs' => 0,
                'semantic_refs' => 0,
                'operator_profile_refs' => 0,
                'knowledge_refs' => 1,
                'code_refs' => 1,
                'retrieval_plan' => [
                    'mode' => 'hybrid',
                    'selected_sources' => ['code', 'memory'],
                    'required_sources' => ['code'],
                ],
            ],
            policy: ['surface' => 'cli_dev'],
            memoryQuality: [
                'ok' => false,
                'status' => 'critical',
                'score' => 10,
                'counts' => ['active' => 1, 'provider_safe_active' => 0],
                'issues' => [['code' => 'no_provider_safe_memory', 'severity' => 'critical']],
            ],
            knowledgeRefs: [[
                'title' => 'Gov',
                'canonical_path' => 'docs/gov.md',
                'summary' => 'rules',
            ]],
            codeRefs: [[
                'name' => 'Module',
                'root_path' => 'app/X',
                'layer' => 'service',
                'symbol_count' => 3,
                'test_count' => 1,
                'reason' => 'match',
            ]],
            codeGraphRefs: [[
                'id' => 'sym:Foo',
                'file_path' => 'app/Foo.php',
                'symbol_type' => 'class',
                'tokens' => 12,
                'signature' => 'final class Foo',
            ]],
            memoryRecallRefs: [[
                'title' => 'Decision',
                'memory_type' => 'decision',
                'scope' => 'global',
                'summary' => 'Prefer pure Support peels',
                'reason' => 'semantic',
            ]],
            realityGraphRefs: [[
                'chain_label' => 'doc->code',
                'cross_layer' => true,
                'confidence_min' => 0.8,
                'nodes' => [
                    ['label' => 'Doc A', 'source_kind' => 'doc'],
                    ['label' => 'Svc B', 'source_kind' => 'code'],
                ],
            ]],
            contextDeliveryPolicy: [
                'schema_version' => 'atlas.token_economy.context_delivery_policy.v1',
                'delivery_mode' => 'feedback_shrunk_initial_expand_on_demand',
                'source' => 'latest_flow_feedback',
                'initial_context_token_budget' => 1000,
                'initial_ref_limit' => 8,
                'expansion_token_reserve' => 200,
                'deferred_source_types' => ['test_symbols'],
                'guarded_required_source_types' => ['canonical_doc'],
                'quality_gate_hint' => 'expand_when_needed',
            ],
            warnings: ['open_brain_context_budget_exceeded'],
        );

        $this->assertStringContainsString('# Atlas Open Brain Context', $text);
        $this->assertStringContainsString('- task_type: coding', $text);
        $this->assertStringContainsString('- desired_mode: open_brain', $text);
        $this->assertStringContainsString('- context_pack_hash: hash123', $text);
        $this->assertStringContainsString('- warnings: open_brain_context_budget_exceeded', $text);
        $this->assertStringContainsString('## Memory Quality Gate', $text);
        $this->assertStringContainsString('## Atlas Unified Reality Graph', $text);
        $this->assertStringContainsString('Doc A [src=doc] -> Svc B [src=code]', $text);
        $this->assertStringContainsString('## Atlas Memory Recall', $text);
        $this->assertStringContainsString('[type=decision', $text);
        $this->assertStringContainsString('## Canonical Engineering Knowledge', $text);
        $this->assertStringContainsString('## Code Intelligence Refs', $text);
        $this->assertStringContainsString('## Code Graph Context', $text);
        $this->assertStringContainsString('sig=final class Foo', $text);
        $this->assertStringContainsString('## Context Delivery Policy', $text);
        $this->assertStringContainsString('expansion_handles: expand:test_symbols, recheck:canonical_doc', $text);
        $this->assertStringContainsString("## Pack\npack body", $text);
        $this->assertSame(
            'profile_key=tone; taxonomy=t1; effect=bias; confidence=0.9; automation=suggest; summary=Be concise',
            Support::providerSafeOperatorItemLine([
                'profile_key' => 'tone',
                'taxonomy_item_id' => 't1',
                'effect' => 'bias',
                'confidence' => 0.9,
                'automation_level' => 'suggest',
                'summary' => 'Be concise',
            ]),
        );
    }

    #[Test]
    public function support_is_final_with_private_constructor_only_static_api(): void
    {
        $ref = new ReflectionClass(Support::class);
        $this->assertTrue($ref->isFinal());
        $ctor = $ref->getConstructor();
        $this->assertNotNull($ctor);
        $this->assertTrue($ctor->isPrivate());

        $public = array_values(array_filter(
            $ref->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === Support::class,
        ));
        foreach ($public as $method) {
            $this->assertTrue($method->isStatic(), $method->getName().' must be static');
        }
    }
}
