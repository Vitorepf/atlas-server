<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\Support;

use App\Services\Ai\Programming\AtlasCodeForgeUxOrchestratorService as Host;
use App\Services\Ai\Programming\Support\CodeForgeUxProjectionSupport as Support;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pure Support peel for Code Forge UX projection — no I/O, no host DI, no clock.
 *
 * Explicit path proof: host imports Support and no longer declares the peeled
 * private pure helpers (state machine / labels / blockers / finalize / progress).
 */
final class CodeForgeUxProjectionSupportTest extends TestCase
{
    private const SUPPORT_PATH = 'app/Services/Ai/Programming/Support/CodeForgeUxProjectionSupport.php';

    private const HOST_PATH = 'app/Services/Ai/Programming/AtlasCodeForgeUxOrchestratorService.php';

    /** @var list<string> */
    private const PEELED = [
        'resolveState',
        'classifyExecutionBlocked',
        'humanLabel',
        'humanDetail',
        'primaryActionLabel',
        'primaryActionKind',
        'primaryActionEnabled',
        'primaryActionDisabledReason',
        'nextSafeStep',
        'resolveBlockers',
        'resolveBlockerTranslation',
        'translateDefinitionBlocker',
        'definitionStatus',
        'collectFilesOutOfScope',
        'finalize',
        'progressPercent',
    ];

    #[Test]
    public function explicit_path_proof_support_and_host_files_exist_and_host_calls_support(): void
    {
        $root = dirname(__DIR__, 5);
        $supportAbs = $root.'/'.self::SUPPORT_PATH;
        $hostAbs = $root.'/'.self::HOST_PATH;

        $this->assertFileExists($supportAbs, 'Support peel must live at '.self::SUPPORT_PATH);
        $this->assertFileExists($hostAbs, 'Host must remain at '.self::HOST_PATH);

        $hostSrc = (string) file_get_contents($hostAbs);
        $this->assertStringContainsString(
            'use App\Services\Ai\Programming\Support\CodeForgeUxProjectionSupport;',
            $hostSrc,
            'Host must import CodeForgeUxProjectionSupport',
        );

        foreach ([
            'CodeForgeUxProjectionSupport::resolveState',
            'CodeForgeUxProjectionSupport::finalize',
            'CodeForgeUxProjectionSupport::resolveBlockers',
            'CodeForgeUxProjectionSupport::humanLabel',
            'CodeForgeUxProjectionSupport::humanDetail',
            'CodeForgeUxProjectionSupport::primaryActionLabel',
            'CodeForgeUxProjectionSupport::primaryActionKind',
            'CodeForgeUxProjectionSupport::primaryActionEnabled',
            'CodeForgeUxProjectionSupport::primaryActionDisabledReason',
            'CodeForgeUxProjectionSupport::nextSafeStep',
            'CodeForgeUxProjectionSupport::collectFilesOutOfScope',
        ] as $needle) {
            $this->assertStringContainsString($needle, $hostSrc, "Host must call {$needle}");
        }

        $this->assertStringContainsString(
            'function queueStaleSeconds',
            $hostSrc,
            'Host keeps clock-bound queueStaleSeconds residual',
        );

        $support = new ReflectionClass(Support::class);
        foreach (self::PEELED as $method) {
            $this->assertTrue($support->hasMethod($method), "Support must expose {$method}");
            $rm = $support->getMethod($method);
            $this->assertTrue($rm->isPublic() && $rm->isStatic(), "{$method} must be public static");
        }

        $host = new ReflectionClass(Host::class);
        foreach (self::PEELED as $method) {
            $this->assertFalse(
                $host->hasMethod($method),
                "Host must no longer declare private {$method} after peel",
            );
        }

        $this->assertTrue($host->hasMethod('queueStaleSeconds'));
        $this->assertTrue($host->hasMethod('snapshot'));
    }

    #[Test]
    public function resolve_state_prioritizes_capacity_then_execution_blocked_over_running(): void
    {
        $this->assertSame(
            Host::STATE_BLOCKED_CAPACITY,
            Support::resolveState([
                'capacity_exhausted' => true,
                'execution_blocked' => true,
                'queue_stale' => false,
                'repair_available' => false,
            ]),
        );

        $this->assertSame(
            Host::STATE_BLOCKED_SCOPE,
            Support::resolveState([
                'capacity_exhausted' => false,
                'execution_blocked' => true,
                'queue_stale' => false,
                'repair_available' => false,
                'execution_remaining_blockers' => ['files_outside_task_contract'],
                'files_out_of_scope' => ['app/Foo.php'],
                'execution_status' => 'running',
                'fast_path_status' => 'queued',
            ]),
            'blocked live execution must never report RUNNING',
        );

        $this->assertSame(
            Host::STATE_RUNNING,
            Support::resolveState([
                'capacity_exhausted' => false,
                'execution_blocked' => false,
                'queue_stale' => false,
                'repair_available' => false,
                'execution_status' => 'running',
                'fast_path_status' => 'queued',
            ]),
        );
    }

    #[Test]
    public function classify_execution_blocked_maps_remaining_blocker_codes(): void
    {
        $this->assertSame(
            Host::STATE_BLOCKED_DEFINITION,
            Support::classifyExecutionBlocked([
                'files_out_of_scope' => [],
                'execution_remaining_blockers' => ['blocked_missing_objective'],
            ]),
        );
        $this->assertSame(
            Host::STATE_BLOCKED_DRIVER,
            Support::classifyExecutionBlocked([
                'files_out_of_scope' => [],
                'execution_remaining_blockers' => ['provider_driver_not_configured'],
            ]),
        );
        $this->assertSame(
            Host::STATE_BLOCKED_PROVIDER,
            Support::classifyExecutionBlocked([
                'files_out_of_scope' => [],
                'execution_remaining_blockers' => ['provider_timeout'],
            ]),
        );
        $this->assertSame(
            Host::STATE_BLOCKED_GOVERNANCE,
            Support::classifyExecutionBlocked([
                'files_out_of_scope' => [],
                'execution_remaining_blockers' => [],
            ]),
        );
    }

    #[Test]
    public function human_labels_actions_and_progress_are_pure_maps(): void
    {
        $this->assertSame('Executando', Support::humanLabel(Host::STATE_RUNNING));
        $this->assertSame('Bloqueado por escopo', Support::humanLabel(Host::STATE_BLOCKED_SCOPE));
        $this->assertSame('Estado desconhecido', Support::humanLabel('not_a_state'));

        $this->assertSame('Executar Forge', Support::primaryActionLabel(Host::STATE_READY_TO_EXECUTE));
        $this->assertSame(Host::ACTION_KIND_EXECUTE_FAST_PATH, Support::primaryActionKind(Host::STATE_PREPARED));
        $this->assertSame(Host::ACTION_KIND_OPEN_REVIEW, Support::primaryActionKind(Host::STATE_WAITING_REVIEW));

        $this->assertFalse(Support::primaryActionEnabled(Host::STATE_NO_OBRA, []));
        $this->assertFalse(Support::primaryActionEnabled(Host::STATE_BLOCKED_CAPACITY, []));
        $this->assertTrue(Support::primaryActionEnabled(Host::STATE_RUNNING, []));

        $this->assertSame('obra_not_bound', Support::primaryActionDisabledReason(Host::STATE_NO_OBRA, []));
        $this->assertSame('provider_capacity_exhausted', Support::primaryActionDisabledReason(Host::STATE_BLOCKED_CAPACITY, []));
        $this->assertNull(Support::primaryActionDisabledReason(Host::STATE_RUNNING, []));

        $this->assertSame(65, Support::progressPercent(Host::STATE_RUNNING, []));
        $this->assertSame(100, Support::progressPercent(Host::STATE_COMPLETED, []));
        $this->assertSame(0, Support::progressPercent('unknown', []));
    }

    #[Test]
    public function human_detail_and_next_safe_step_use_signal_context(): void
    {
        $detail = Support::humanDetail(Host::STATE_BLOCKED_DEFINITION, [
            'intake_blockers' => ['blocked_missing_objective'],
            'intake_missing_fields' => [],
        ]);
        $this->assertStringContainsString('objetivo', strtolower($detail));

        $scopeDetail = Support::humanDetail(Host::STATE_BLOCKED_SCOPE, [
            'files_out_of_scope' => ['app/X.php'],
        ]);
        $this->assertStringContainsString('app/X.php', $scopeDetail);

        $step = Support::nextSafeStep(Host::STATE_WAITING_WORKER, [
            'queue_stale_seconds' => 120,
            'files_out_of_scope' => [],
        ]);
        $this->assertStringContainsString('120', $step);
    }

    #[Test]
    public function resolve_blockers_and_definition_status_are_pure(): void
    {
        $blockers = Support::resolveBlockers(Host::STATE_BLOCKED_DEFINITION, [
            'capacity_exhausted' => false,
            'intake_blockers' => ['spec_or_context_insufficient'],
            'intake_missing_fields' => ['objective'],
        ]);
        $this->assertContains('spec_or_context_insufficient', $blockers);
        $this->assertContains('blocked_missing_objective', $blockers);

        $this->assertSame(
            'blocking_execution',
            Support::definitionStatus([
                'intake_blockers' => ['x'],
                'intake_missing_fields' => [],
                'intake_ready' => false,
            ]),
        );
        $this->assertSame('ready', Support::definitionStatus([
            'intake_blockers' => [],
            'intake_missing_fields' => [],
            'intake_ready' => true,
        ]));
        $this->assertSame('incomplete', Support::definitionStatus([
            'intake_blockers' => [],
            'intake_missing_fields' => [],
            'intake_ready' => false,
        ]));
    }

    #[Test]
    public function translate_definition_blocker_and_blocker_translation(): void
    {
        [$title, $detail, $action] = Support::translateDefinitionBlocker('blocked_missing_objective');
        $this->assertStringContainsString('objetivo', strtolower($title));
        $this->assertNotSame('', $detail);
        $this->assertNotSame('', $action);

        $translation = Support::resolveBlockerTranslation(Host::STATE_BLOCKED_SCOPE, [
            'files_out_of_scope' => ['a.php'],
            'execution_remaining_blockers' => ['files_outside_task_contract'],
        ]);
        $this->assertSame('blocked_scope', $translation['kind']);
        $this->assertTrue($translation['is_blocking']);
        $this->assertSame(Host::ACTION_KIND_FIX_SCOPE, $translation['suggested_action_kind']);
        $this->assertSame(['a.php'], $translation['files_out_of_scope']);

        $capacity = Support::resolveBlockerTranslation(Host::STATE_BLOCKED_CAPACITY, [
            'files_out_of_scope' => [],
            'execution_remaining_blockers' => [],
        ]);
        $this->assertSame('blocked_capacity', $capacity['kind']);
        $this->assertTrue($capacity['is_blocking']);
    }

    #[Test]
    public function collect_files_out_of_scope_merges_unique_paths(): void
    {
        $files = Support::collectFilesOutOfScope(
            [
                'issues' => [
                    ['code' => 'files_out_of_scope', 'path' => 'app/A.php'],
                    ['code' => 'other', 'path' => 'app/Skip.php'],
                ],
                'scope_violations' => ['app/B.php', ['path' => 'app/C.php']],
                'rejected_files' => ['app/A.php', 'app/D.php'],
            ],
            [
                'issues' => [
                    ['code' => 'out_of_scope_write', 'path' => 'app/E.php'],
                ],
            ],
        );

        $this->assertSame(
            ['app/A.php', 'app/B.php', 'app/C.php', 'app/D.php', 'app/E.php'],
            $files,
        );
    }

    #[Test]
    public function finalize_envelope_preserves_schema_and_safety_gates(): void
    {
        $state = Host::STATE_WAITING_REVIEW;
        $signals = [
            'provider_called' => false,
            'external_provider_call' => false,
            'completion_claim_promoted' => false,
            'review_required' => true,
            'final_completion_allowed' => false,
            'review_status' => 'pending',
            'human_approved' => false,
            'intake_ready' => true,
            'spec_plan_ready' => true,
            'provider' => 'openai',
            'model' => 'gpt',
            'decision_source' => 'atlas_decide',
            'capacity_state' => 'ok',
            'driver_configured_for_selected' => true,
            'evidence_ref_count' => 2,
            'ledger_event_count' => 3,
            'execution_status' => 'passed',
            'files_out_of_scope' => [],
            'execution_remaining_blockers' => [],
            'intake_blockers' => [],
            'intake_missing_fields' => [],
        ];

        $out = Support::finalize(
            state: $state,
            obraId: 'obra-1',
            obraPresent: true,
            generatedAt: '2026-07-25T00:00:00+00:00',
            blockers: Support::resolveBlockers($state, $signals),
            signals: $signals,
            humanLabel: Support::humanLabel($state),
            humanDetail: Support::humanDetail($state, $signals),
            primaryActionLabel: Support::primaryActionLabel($state),
            primaryActionKind: Support::primaryActionKind($state),
            primaryActionEnabled: Support::primaryActionEnabled($state, $signals),
            primaryActionDisabledReason: Support::primaryActionDisabledReason($state, $signals),
            nextSafeStep: Support::nextSafeStep($state, $signals),
            advancedRefs: ['fast_path_run_id' => 'fp-1'],
        );

        $this->assertSame(Host::SCHEMA_VERSION, $out['schema_version']);
        $this->assertSame('obra-1', $out['obra_id']);
        $this->assertTrue($out['obra_present']);
        $this->assertSame($state, $out['state']);
        $this->assertSame(Host::ACTION_KIND_OPEN_REVIEW, $out['primary_action_kind']);
        $this->assertTrue($out['completion_gating']['review_required']);
        $this->assertTrue($out['completion_gating']['approve_button_visible']);
        $this->assertFalse($out['safety_summary']['completion_claim_promoted']);
        $this->assertFalse($out['safety_summary']['external_provider_call']);
        $this->assertTrue($out['safety_summary']['review_completion_gate_preserved']);
        $this->assertSame('ready', $out['definition_status']);
        $this->assertSame(85, $out['progress_percent']);
        $this->assertSame(['fast_path_run_id' => 'fp-1'], $out['advanced_refs']);
        $this->assertSame(
            Host::canonicalChatMessageKinds(),
            $out['chat_message_kinds'],
        );
        $this->assertArrayHasKey('blocker_translation', $out);
        $this->assertArrayHasKey('checklist', $out);
        $this->assertTrue($out['checklist']['intake']);
        $this->assertTrue($out['checklist']['execution']);
    }

    #[Test]
    public function resolve_state_waiting_confirmation_and_ready_paths(): void
    {
        $this->assertSame(
            Host::STATE_WAITING_PROVIDER_CONFIRMATION,
            Support::resolveState([
                'capacity_exhausted' => false,
                'execution_blocked' => false,
                'queue_stale' => false,
                'repair_available' => false,
                'invocation_status' => 'blocked',
                'invocation_blockers' => ['operator_provider_approval_required'],
            ]),
        );

        $this->assertSame(
            Host::STATE_READY_TO_EXECUTE,
            Support::resolveState([
                'capacity_exhausted' => false,
                'execution_blocked' => false,
                'queue_stale' => false,
                'repair_available' => false,
                'fast_path_status' => 'prepared',
                'execution_status' => '',
                'execution_async_status' => '',
                'review_required' => false,
                'completion_status' => '',
                'review_status' => '',
                'rollback_state' => '',
                'intake_ready' => true,
                'spec_plan_ready' => true,
            ]),
        );

        $this->assertSame(
            Host::STATE_BLOCKED_DEFINITION,
            Support::resolveState([
                'capacity_exhausted' => false,
                'execution_blocked' => false,
                'queue_stale' => false,
                'repair_available' => false,
                'fast_path_status' => 'idle',
                'execution_status' => '',
                'execution_async_status' => '',
                'review_required' => false,
                'completion_status' => '',
                'review_status' => '',
                'rollback_state' => '',
                'intake_ready' => false,
                'spec_plan_ready' => false,
                'intake_blockers' => ['blocked_missing_objective'],
                'intake_missing_fields' => [],
            ]),
        );
    }
}
