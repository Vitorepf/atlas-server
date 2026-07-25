<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\OpenBrainContextInjection;

use App\Services\Ai\Context\ContextPackSelfReflectionGate;
use App\Services\Ai\OpenBrainContextInjection\ContextInjectionProjectionSupport as Support;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Pure Support peel for Open Brain injection policy/surface/summary —
 * no I/O, no host, no DB, no config.
 *
 * Explicit path proof: AtlasOpenBrainContextInjectionService imports Support
 * and no longer declares the peeled private projection methods.
 */
final class ContextInjectionProjectionSupportTest extends TestCase
{
    private const SUPPORT_PATH = 'app/Services/Ai/OpenBrainContextInjection/ContextInjectionProjectionSupport.php';

    private const HOST_PATH = 'app/Services/Ai/AtlasOpenBrainContextInjectionService.php';

    /** @var list<string> */
    private const PEELED = [
        'payload',
        'lowerString',
        'surface',
        'anySignalRequiresInjection',
        'signalRequiresInjection',
        'engineeringContext',
        'contextDeliveryPolicy',
        'providerSafeContextDeliveryPolicy',
        'contextDeliveryRefs',
        'contextDeliveryPolicySummary',
        'contextDeliveryPolicyWarnings',
        'selfReflectionWarnings',
        'operatorContextRefs',
        'operatorContextSummary',
        'injectionSummary',
        'isPreview',
        'requester',
        'nextActions',
        'skipped',
        'failed',
        'firstNonEmptyString',
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
            'use App\Services\Ai\OpenBrainContextInjection\ContextInjectionProjectionSupport;',
            $hostSrc,
            'Host must import ContextInjectionProjectionSupport',
        );

        foreach ([
            'ContextInjectionProjectionSupport::payload',
            'ContextInjectionProjectionSupport::surface',
            'ContextInjectionProjectionSupport::engineeringContext',
            'ContextInjectionProjectionSupport::contextDeliveryPolicy',
            'ContextInjectionProjectionSupport::contextDeliveryRefs',
            'ContextInjectionProjectionSupport::contextDeliveryPolicySummary',
            'ContextInjectionProjectionSupport::contextDeliveryPolicyWarnings',
            'ContextInjectionProjectionSupport::injectionSummary',
            'ContextInjectionProjectionSupport::operatorContextRefs',
            'ContextInjectionProjectionSupport::operatorContextSummary',
            'ContextInjectionProjectionSupport::selfReflectionWarnings',
            'ContextInjectionProjectionSupport::nextActions',
            'ContextInjectionProjectionSupport::skipped',
            'ContextInjectionProjectionSupport::failed',
            'ContextInjectionProjectionSupport::isPreview',
            'ContextInjectionProjectionSupport::requester',
            'ContextInjectionProjectionSupport::anySignalRequiresInjection',
        ] as $call) {
            $this->assertStringContainsString($call, $hostSrc, "Host must call {$call}");
        }

        foreach (self::PEELED as $method) {
            // Host may still hold config/IO methods with different names.
            if (in_array($method, ['signalRequiresInjection', 'firstNonEmptyString', 'lowerString', 'providerSafeContextDeliveryPolicy'], true)) {
                // Called only via Support or nested Support; may not appear as host private.
            }
            $this->assertStringNotContainsString(
                'private function '.$method.'(',
                $hostSrc,
                "Peeled method residual on host: {$method}",
            );
            $this->assertStringNotContainsString(
                'private static function '.$method.'(',
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
    public function surface_resolves_explicit_cli_and_app_signals(): void
    {
        $this->assertSame('cli_dev', Support::surface(
            ['open_brain' => ['surface' => 'cli_dev']],
            [],
        ));
        $this->assertSame('cli_continue', Support::surface(
            [],
            [
                'app_surface' => 'atlas_cli',
                'dev_execution_plan' => ['resumed_at' => '2026-01-01T00:00:00Z'],
            ],
        ));
        $this->assertSame('cli_dev', Support::surface(
            [],
            [
                'app_surface' => 'atlas_cli',
                'dev_execution_plan' => ['goal' => 'x'],
            ],
        ));
        $this->assertSame('cli_chat', Support::surface([], ['app_surface' => 'atlas_cli']));
        $this->assertSame('app_ai', Support::surface(['source_type' => 'app'], []));
        $this->assertSame('api', Support::surface([], []));
        $this->assertSame('cli_chat', Support::surface(['source_type' => 'manual'], []));
    }

    #[Test]
    public function injection_signal_tables_match_programming_and_generic_modes(): void
    {
        $this->assertTrue(Support::signalRequiresInjection('dev'));
        $this->assertTrue(Support::signalRequiresInjection('programming.debug'));
        $this->assertFalse(Support::signalRequiresInjection('programming.unknown'));
        $this->assertFalse(Support::signalRequiresInjection('direct'));
        $this->assertTrue(Support::anySignalRequiresInjection(['direct', 'review']));
        $this->assertFalse(Support::anySignalRequiresInjection(['direct', 'chat']));
    }

    #[Test]
    public function provider_safe_context_delivery_policy_chokes_unsafe_and_inactive(): void
    {
        $this->assertNull(Support::providerSafeContextDeliveryPolicy(['status' => 'inactive']));
        $this->assertNull(Support::providerSafeContextDeliveryPolicy([
            'status' => 'active',
            'policy' => ['raw_text_exposed' => true],
        ]));
        $this->assertNull(Support::providerSafeContextDeliveryPolicy([
            'status' => 'active',
            'policy' => ['provider_safe_only' => false],
        ]));

        $safe = Support::providerSafeContextDeliveryPolicy([
            'status' => 'active',
            'source' => 'test',
            'delivery_mode' => 'feedback_shrunk_initial_expand_on_demand',
            'initial_context_token_budget' => 1200,
            'expansion_token_reserve' => 400,
            'initial_ref_limit' => 8,
            'initial_source_types' => ['code_graph', 'memory'],
            'deferred_source_types' => ['current_tests'],
            'guarded_required_source_types' => ['canonical_doc'],
            'expansion_triggers' => ['expand_missing_source_types'],
            'quality_gate_hint' => 'expand_when_needed',
        ]);

        $this->assertIsArray($safe);
        $this->assertSame('active', $safe['status']);
        $this->assertTrue($safe['advisory_only']);
        $this->assertSame(['code_graph', 'memory'], $safe['initial_source_types']);
        $this->assertSame(['current_tests'], $safe['deferred_source_types']);
        $this->assertSame(['canonical_doc'], $safe['guarded_required_source_types']);
        $this->assertTrue($safe['policy']['provider_safe_only']);
        $this->assertFalse($safe['policy']['raw_text_exposed']);
    }

    #[Test]
    public function context_delivery_refs_and_summary_project_handles(): void
    {
        $policy = [
            'schema_version' => 'atlas.token_economy.context_delivery_policy.v1',
            'delivery_mode' => 'feedback_shrunk',
            'source' => 'pack',
            'initial_context_token_budget' => 1000,
            'expansion_token_reserve' => 200,
            'initial_ref_limit' => 4,
            'initial_source_types' => ['code_graph'],
            'deferred_source_types' => ['current_tests', 'graph'],
            'guarded_required_source_types' => ['canonical_doc'],
            'quality_gate_hint' => 'expand',
        ];

        $refs = Support::contextDeliveryRefs($policy);
        $types = array_column($refs, 'type');
        $this->assertContains('atlas_context_initial_source', $types);
        $this->assertContains('atlas_context_expansion_handle', $types);
        $this->assertContains('atlas_context_required_recheck', $types);
        $this->assertTrue(collect($refs)->every(fn (array $ref): bool => ($ref['provider_safe'] ?? false) === true));

        $summary = Support::contextDeliveryPolicySummary($policy);
        $this->assertSame(3, $summary['expansion_handle_count']);
        $this->assertSame(['code_graph'], $summary['initial_source_types']);
        $this->assertTrue($summary['advisory_only']);

        $this->assertSame(
            ['context_delivery_required_source_recheck'],
            Support::contextDeliveryPolicyWarnings($policy),
        );
        $this->assertSame([], Support::contextDeliveryPolicyWarnings(null));
        $this->assertSame([], Support::contextDeliveryPolicyWarnings(['guarded_required_source_types' => []]));
    }

    #[Test]
    public function context_delivery_policy_reads_first_active_candidate_from_payload_or_pack(): void
    {
        $active = [
            'status' => 'active',
            'source' => 'payload',
            'initial_source_types' => ['memory'],
        ];
        $fromPayload = Support::contextDeliveryPolicy(
            ['context_delivery_policy' => $active],
            [],
        );
        $this->assertSame('payload', $fromPayload['source'] ?? null);

        $fromPack = Support::contextDeliveryPolicy(
            [],
            ['token_economy' => ['context_delivery_policy' => array_merge($active, ['source' => 'pack'])]],
        );
        $this->assertSame('pack', $fromPack['source'] ?? null);

        $this->assertNull(Support::contextDeliveryPolicy([], []));
    }

    #[Test]
    public function operator_context_refs_and_summary_are_provider_safe(): void
    {
        $operator = [
            'enabled' => true,
            'status' => 'ready',
            'reason' => 'composed',
            'operator_id' => 'op-1',
            'flow' => 'programming.dev',
            'provider_external' => true,
            'items' => [
                ['id' => 'i1', 'profile_key' => 'tone', 'taxonomy_item_id' => 't1', 'effect' => 'prefer'],
                ['id' => '', 'profile_key' => 'drop'],
                'skip-me',
            ],
            'omitted' => [['id' => 'x']],
        ];

        $refs = Support::operatorContextRefs($operator);
        $this->assertCount(1, $refs);
        $this->assertSame('operator_profile_item', $refs[0]['type']);
        $this->assertSame('i1', $refs[0]['id']);
        $this->assertTrue($refs[0]['provider_safe']);

        $summary = Support::operatorContextSummary($operator);
        // item_count counts array items (including empty-id); refs filter empty ids.
        $this->assertSame(2, $summary['item_count']);
        $this->assertSame(1, $summary['omitted_count']);
        $this->assertSame(['tone', 'drop'], $summary['profile_keys']);
        $this->assertSame(['prefer'], $summary['effects']);
        $this->assertSame(hash('sha256', 'op-1'), $summary['operator_id_hash']);
        $this->assertStringNotContainsString('"op-1"', json_encode($summary) ?: '');
    }

    #[Test]
    public function injection_summary_counts_ref_types_and_keeps_retrieval_plan(): void
    {
        $summary = Support::injectionSummary(
            [
                ['type' => 'atlas_memory_entry'],
                ['type' => 'semantic_note'],
                ['type' => 'atlas_memory_recall'],
                ['type' => 'atlas_reality_path'],
                ['type' => 'operator_profile_item'],
                ['type' => 'atlas_context_expansion_handle'],
                ['type' => 'atlas_context_required_recheck'],
            ],
            [['id' => 'k1'], ['id' => 'k2']],
            [['id' => 'c1']],
            ['budget_chars' => 9000],
            ['mode' => 'selective'],
        );

        $this->assertSame(7, $summary['context_refs']);
        $this->assertSame(1, $summary['memory_refs']);
        $this->assertSame(1, $summary['semantic_refs']);
        $this->assertSame(1, $summary['memory_recall_refs']);
        $this->assertSame(1, $summary['reality_graph_refs']);
        $this->assertSame(1, $summary['operator_profile_refs']);
        $this->assertSame(2, $summary['context_expansion_handles']);
        $this->assertSame(2, $summary['knowledge_refs']);
        $this->assertSame(1, $summary['code_refs']);
        $this->assertSame(9000, $summary['budget_chars']);
        $this->assertSame(['mode' => 'selective'], $summary['retrieval_plan']);
        $this->assertTrue($summary['provider_safe']);
    }

    #[Test]
    public function self_reflection_warnings_map_gate_statuses(): void
    {
        $this->assertSame(
            ['context_pack_insufficient'],
            Support::selfReflectionWarnings(['status' => ContextPackSelfReflectionGate::STATUS_INSUFFICIENT]),
        );
        $this->assertSame(
            ['context_pack_contradictory'],
            Support::selfReflectionWarnings(['status' => ContextPackSelfReflectionGate::STATUS_CONTRADICTORY]),
        );
        $this->assertSame(
            ['context_pack_risky'],
            Support::selfReflectionWarnings(['status' => ContextPackSelfReflectionGate::STATUS_RISKY]),
        );
        $this->assertSame([], Support::selfReflectionWarnings(['status' => ContextPackSelfReflectionGate::STATUS_SUFFICIENT]));
    }

    #[Test]
    public function envelopes_next_actions_requester_and_preview_are_deterministic(): void
    {
        $policy = ['surface' => 'cli_dev', 'mode' => 'auto', 'budget_chars' => 2000];
        $skipped = Support::skipped($policy, 'policy_off');
        $this->assertSame('skipped', $skipped['status']);
        $this->assertSame('policy_off', $skipped['reason']);
        $this->assertNull($skipped['prompt_section']);
        $this->assertSame(0, $skipped['summary']['context_refs']);

        $failedOpen = Support::failed($policy, new RuntimeException('boom'));
        $this->assertSame('failed_open', $failedOpen['status']);
        $this->assertSame(['open_brain_exception:RuntimeException'], $failedOpen['warnings']);

        $failedClosed = Support::failed(['mode' => 'required', 'surface' => 'api'], new RuntimeException('x'));
        $this->assertSame('failed_closed', $failedClosed['status']);
        $this->assertSame('required_open_brain_failed', $failedClosed['reason']);

        $this->assertSame('atlas-dev', Support::requester(['surface' => 'cli_dev']));
        $this->assertSame('atlas', Support::requester(['surface' => 'api']));
        $this->assertTrue(Support::isPreview(['open_brain' => ['preview' => true]], []));
        $this->assertFalse(Support::isPreview([], []));

        $actions = Support::nextActions(
            ['no_engineering_knowledge_refs', 'context_delivery_required_source_recheck'],
            [
                'retrieval_plan' => ['review_signal' => ['recommended_action' => 'refresh_code_intelligence_before_retry']],
                'context_delivery_policy' => ['expansion_token_reserve' => 50],
            ],
        );
        $this->assertContains('Refresh code intelligence before retrying.', $actions);
        $this->assertContains('Run atlas memory maintain to sync docs and code intelligence.', $actions);
        $this->assertContains('Call atlas_context_expand for guarded required sources before implementation.', $actions);
        $this->assertContains('Call atlas_context_expand for deferred sources before dumping full docs, tests or graph output.', $actions);

        $this->assertSame('dev', Support::firstNonEmptyString(['', '  ', 'dev', 'other']));
        $this->assertNull(Support::firstNonEmptyString([null, '', 1]));
        $this->assertSame('hello', Support::lowerString(' Hello '));
        $this->assertNull(Support::lowerString('   '));
        $this->assertSame(['x' => 1], Support::payload(['payload' => ['x' => 1]]));
        $this->assertSame([], Support::payload([]));

        $eng = Support::engineeringContext('/tmp/ws', [
            'project_id' => 'p1',
            'task_id' => 't1',
            'run_id' => 'r1',
            'atlas_workflow_mode' => 'dev',
            'routing_task' => 12,
        ]);
        $this->assertSame('p1', $eng['project_id']);
        $this->assertSame('r1', $eng['engineering_run_id']);
        $this->assertSame(['dev'], $eng['tags']);
        $this->assertSame('/tmp/ws', $eng['workspace']);
    }
}
