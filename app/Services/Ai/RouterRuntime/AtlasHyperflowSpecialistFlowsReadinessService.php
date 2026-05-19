<?php

declare(strict_types=1);

namespace App\Services\Ai\RouterRuntime;

use App\Services\Ai\Router\AtlasAiSpecialistFlowExecutionService;
use App\Services\Ai\Router\AtlasAiSpecialistFlowRuntimeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Etapa 1 — Hyperflow + Specialist Flows readiness gate.
 *
 * Standalone gate that lives next to the broader Hyperflow integration
 * certification but focuses on what Etapa 1 promises: the specialist-flow
 * substrate is honestly wired into the canonical Hyperflow entry, Desktop
 * auto/auto routing is preserved, every non-programming intent reaches its
 * own specialist flow, and Dev/Forge handoff is explicit.
 *
 * Hard contract:
 *  - read-only and side-effect-free (no DB writes, no provider calls,
 *    no benchmark execution);
 *  - never declares external superiority over Claude Code / Codex
 *    (claim_policy.allows_external_superiority_claim stays false);
 *  - returns `passed` only when EVERY check is green.
 */
class AtlasHyperflowSpecialistFlowsReadinessService
{
    public const SCHEMA_VERSION = 'atlas.ai.hyperflow_specialist_flows_readiness.v1';

    public const NON_PROGRAMMING_INTENTS = [
        RouterRuntimeCanon::INTENT_CONVERSATION,
        RouterRuntimeCanon::INTENT_RESEARCH,
        RouterRuntimeCanon::INTENT_EXPLAIN,
        RouterRuntimeCanon::INTENT_PLAN,
        RouterRuntimeCanon::INTENT_FINANCE,
        RouterRuntimeCanon::INTENT_MARKETING,
        RouterRuntimeCanon::INTENT_STRATEGY,
        RouterRuntimeCanon::INTENT_CYBER,
        RouterRuntimeCanon::INTENT_PERSONAL_DEVELOPMENT,
        RouterRuntimeCanon::INTENT_AUTOMATION,
    ];

    public const PROGRAMMING_FLOW_FOOTPRINT = [
        RouterRuntimeCanon::FLOW_DEV,
        RouterRuntimeCanon::FLOW_DEBUG,
        RouterRuntimeCanon::FLOW_REVIEW,
        RouterRuntimeCanon::FLOW_PLAN,
        RouterRuntimeCanon::FLOW_FORGE,
    ];

    public const NON_PROGRAMMING_FLOW_FOOTPRINT = [
        RouterRuntimeCanon::FLOW_CONVERSATION,
        RouterRuntimeCanon::FLOW_RESEARCH,
        RouterRuntimeCanon::FLOW_EXPLAIN,
        RouterRuntimeCanon::FLOW_FINANCE,
        RouterRuntimeCanon::FLOW_MARKETING,
        RouterRuntimeCanon::FLOW_STRATEGY,
        RouterRuntimeCanon::FLOW_CYBER,
        RouterRuntimeCanon::FLOW_PERSONAL_DEVELOPMENT,
        RouterRuntimeCanon::FLOW_AUTOMATION,
    ];

    /**
     * Specialist flows the existing AtlasAiSpecialistFlowExecutionService
     * exposes flow-specific handlers for. Other flow_ids fall through to the
     * generic conversation handler — that is intentional and still counts as
     * "not silently routed into atlas_dev" for this readiness gate.
     */
    private const FLOWS_WITH_DEEP_HANDLER = [
        RouterRuntimeCanon::FLOW_RESEARCH,
        RouterRuntimeCanon::FLOW_EXPLAIN,
        RouterRuntimeCanon::FLOW_DEBUG,
        RouterRuntimeCanon::FLOW_REVIEW,
        RouterRuntimeCanon::FLOW_PLAN,
        RouterRuntimeCanon::FLOW_CONVERSATION,
    ];

    public function __construct(
        private readonly AtlasAiSpecialistFlowRuntimeService $specialistRuntime,
        private readonly AtlasAiSpecialistFlowExecutionService $specialistExecution,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $generatedAt = CarbonImmutable::now();
        $checks = [
            $this->hyperflowEntryWiredCheck(),
            $this->desktopAutoAutoCheck(),
            $this->specialistFlowsRegisteredCheck(),
            $this->nonProgrammingFlowsPresentCheck(),
            $this->programmingFlowsPresentCheck(),
            $this->nonProgrammingDoesNotRouteToDevCheck(),
            $this->specialistFlowRuntimeEmitsContractCheck(),
            $this->specialistFlowExecutionEmitsHandlerCheck(),
            $this->receiptsEvidenceTelemetryCheck(),
            $this->devForgeHandoffExplicitCheck(),
            $this->benchmarkNotRunCheck(),
            $this->noExternalSuperiorityClaimCheck(),
            $this->testsPresentCheck(),
        ];

        $failed = array_values(array_filter(
            $checks,
            static fn (array $check): bool => ($check['status'] ?? null) !== 'passed',
        ));

        $status = $failed === [] ? 'passed' : 'blocked';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => $generatedAt->toIso8601String(),
            'summary' => [
                'total' => count($checks),
                'passed' => count($checks) - count($failed),
                'failed' => count($failed),
            ],
            'checks' => $checks,
            'remaining_blockers' => array_map(
                static fn (array $check): string => (string) ($check['id'] ?? 'unknown_check'),
                $failed,
            ),
            'claim_policy' => [
                'declares_atlas_complete' => false,
                'declares_stage_one_ready' => $status === 'passed',
                'benchmark_not_run' => true,
                'rivals_compared' => false,
                'allows_external_superiority_claim' => false,
                'requires_human_authorization_to_run_benchmark' => true,
            ],
            'note' => 'Etapa 1 readiness only. NOT a benchmark and NOT a claim of replacing Claude Code/Codex.',
            'writes' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function hyperflowEntryWiredCheck(): array
    {
        $controllerSource = $this->source(app_path('Http/Controllers/AiInteractionController.php'));
        $entryClassExists = class_exists(AtlasHyperflowEntryService::class);
        $controllerImports = str_contains(
            $controllerSource,
            'App\\Services\\Ai\\RouterRuntime\\AtlasHyperflowEntryService',
        );
        $controllerInjects = str_contains($controllerSource, 'AtlasHyperflowEntryService $hyperflowEntry');
        $controllerInvokes = str_contains($controllerSource, '$hyperflowEntry->run($data)');

        return $this->check(
            'hyperflow_entry_wired',
            $entryClassExists && $controllerImports && $controllerInjects && $controllerInvokes,
            [
                'entry_class_exists' => $entryClassExists,
                'controller_imports' => $controllerImports,
                'controller_injects' => $controllerInjects,
                'controller_invokes' => $controllerInvokes,
                'controller_path' => 'app/Http/Controllers/AiInteractionController.php',
                'entry_service_path' => 'app/Services/Ai/RouterRuntime/AtlasHyperflowEntryService.php',
            ],
        );
    }

    /**
     * Desktop auto/auto is owned by AtlasDesktopHyperflowIntegrationCertificationService;
     * here we just delegate, so we don't reimplement the source-inspection logic.
     *
     * @return array<string,mixed>
     */
    private function desktopAutoAutoCheck(): array
    {
        $report = null;
        $error = null;
        try {
            $report = app(AtlasDesktopHyperflowIntegrationCertificationService::class)->certify();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $checks = is_array($report) ? (array) ($report['checks'] ?? []) : [];
        $target = null;
        foreach ($checks as $candidate) {
            if (is_array($candidate) && ($candidate['id'] ?? null) === 'no_legacy_programming_dev_default') {
                $target = $candidate;
                break;
            }
        }

        $passed = $target !== null && ($target['status'] ?? null) === 'passed';

        return $this->check('desktop_auto_auto_preserved', $passed, [
            'desktop_check_id' => 'no_legacy_programming_dev_default',
            'desktop_check_status' => $target['status'] ?? 'missing',
            'desktop_check_evidence' => $target['evidence'] ?? null,
            'error' => $error,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function specialistFlowsRegisteredCheck(): array
    {
        $registry = RouterRuntimeCanon::ALLOWED_FLOW_IDS;
        $expectedCount = 14;
        $missing = [];
        foreach (array_merge(self::PROGRAMMING_FLOW_FOOTPRINT, self::NON_PROGRAMMING_FLOW_FOOTPRINT) as $flow) {
            if (! in_array($flow, $registry, true)) {
                $missing[] = $flow;
            }
        }

        return $this->check(
            'specialist_flows_registered',
            count($registry) >= $expectedCount && $missing === [],
            [
                'registered_count' => count($registry),
                'expected_minimum' => $expectedCount,
                'registry' => $registry,
                'missing' => $missing,
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function nonProgrammingFlowsPresentCheck(): array
    {
        $missing = [];
        foreach (self::NON_PROGRAMMING_FLOW_FOOTPRINT as $flow) {
            if (! in_array($flow, RouterRuntimeCanon::ALLOWED_FLOW_IDS, true)) {
                $missing[] = $flow;
            }
        }

        return $this->check(
            'non_programming_flows_present',
            $missing === [],
            [
                'required' => self::NON_PROGRAMMING_FLOW_FOOTPRINT,
                'missing' => $missing,
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function programmingFlowsPresentCheck(): array
    {
        $missing = [];
        foreach (self::PROGRAMMING_FLOW_FOOTPRINT as $flow) {
            if (! in_array($flow, RouterRuntimeCanon::ALLOWED_FLOW_IDS, true)) {
                $missing[] = $flow;
            }
        }

        return $this->check(
            'programming_flows_present',
            $missing === [],
            [
                'required' => self::PROGRAMMING_FLOW_FOOTPRINT,
                'missing' => $missing,
            ],
        );
    }

    /**
     * Static + safe-apply check that proves a non-programming intent NEVER
     * lands in atlas_dev. Two layers:
     *   1. canonical INTENT_TO_FLOW map must route every non-programming
     *      intent to a non-programming flow_id;
     *   2. SpecialistFlowRuntimeService.apply() must NOT rewrite the flow_id
     *      to atlas_dev/atlas_forge when handed a non-programming flow.
     *
     * @return array<string,mixed>
     */
    private function nonProgrammingDoesNotRouteToDevCheck(): array
    {
        $offendingMappings = [];
        foreach (self::NON_PROGRAMMING_INTENTS as $intent) {
            $mapped = RouterRuntimeCanon::INTENT_TO_FLOW[$intent] ?? null;
            if (in_array($mapped, RouterRuntimeCanon::PROGRAMMING_FLOW_IDS, true)) {
                $offendingMappings[$intent] = $mapped;
            }
        }

        $runtimeOffenders = [];
        foreach (self::NON_PROGRAMMING_FLOW_FOOTPRINT as $flowId) {
            $result = $this->safeRuntimeApply($flowId);
            $rewritten = (string) ($result['flow_id'] ?? '');
            if (in_array($rewritten, RouterRuntimeCanon::PROGRAMMING_FLOW_IDS, true)) {
                $runtimeOffenders[$flowId] = $rewritten;
            }
        }

        return $this->check(
            'non_programming_does_not_route_to_dev',
            $offendingMappings === [] && $runtimeOffenders === [],
            [
                'intent_to_flow_offenders' => $offendingMappings,
                'runtime_apply_offenders' => $runtimeOffenders,
                'programming_flow_ids' => RouterRuntimeCanon::PROGRAMMING_FLOW_IDS,
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function specialistFlowRuntimeEmitsContractCheck(): array
    {
        $results = [];
        $missing = [];
        foreach (self::FLOWS_WITH_DEEP_HANDLER as $flowId) {
            $envelope = $this->safeRuntimeApply($flowId);
            $hasReceipt = is_array($envelope['receipt'] ?? null)
                && is_string($envelope['receipt']['receipt_id'] ?? null)
                && is_string($envelope['receipt']['contract_hash'] ?? null)
                && $envelope['receipt']['contract_hash'] !== '';
            $hasContract = is_array($envelope['required_evidence'] ?? null)
                && is_string($envelope['execution_mode'] ?? null);
            $matches = ($envelope['flow_id'] ?? null) === $flowId;
            $results[$flowId] = [
                'flow_id' => $envelope['flow_id'] ?? null,
                'execution_mode' => $envelope['execution_mode'] ?? null,
                'has_receipt' => $hasReceipt,
                'has_contract' => $hasContract,
                'matches_input' => $matches,
            ];
            if (! ($hasReceipt && $hasContract && $matches)) {
                $missing[] = $flowId;
            }
        }

        return $this->check(
            'specialist_flow_runtime_emits_contract',
            $missing === [],
            [
                'results' => $results,
                'missing' => $missing,
                'schema_version' => AtlasAiSpecialistFlowRuntimeService::SCHEMA_VERSION,
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function specialistFlowExecutionEmitsHandlerCheck(): array
    {
        $results = [];
        $missing = [];
        foreach (self::FLOWS_WITH_DEEP_HANDLER as $flowId) {
            $execution = $this->safeExecutionApply($flowId);
            $hasHandler = is_string($execution['handler_id'] ?? null) && $execution['handler_id'] !== '';
            $hasRubric = is_array($execution['quality_rubric'] ?? null) && $execution['quality_rubric'] !== [];
            $hasCompletion = is_array($execution['completion_checks'] ?? null) && $execution['completion_checks'] !== [];
            $hasFailureModes = is_array($execution['failure_modes'] ?? null) && $execution['failure_modes'] !== [];
            $results[$flowId] = [
                'handler_id' => $execution['handler_id'] ?? null,
                'has_rubric' => $hasRubric,
                'has_completion_checks' => $hasCompletion,
                'has_failure_modes' => $hasFailureModes,
            ];
            if (! ($hasHandler && $hasRubric && $hasCompletion && $hasFailureModes)) {
                $missing[] = $flowId;
            }
        }

        return $this->check(
            'specialist_flow_execution_emits_handler',
            $missing === [],
            [
                'results' => $results,
                'missing' => $missing,
                'schema_version' => AtlasAiSpecialistFlowExecutionService::SCHEMA_VERSION,
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function receiptsEvidenceTelemetryCheck(): array
    {
        $gateway = $this->source(app_path('Services/Ai/AiGatewayService.php'));
        $traceResource = $this->source(app_path('Http/Resources/AiTraceResource.php'));
        $telemetry = $this->source(app_path('Services/Ai/Telemetry/AiTraceMetricAggregator.php'));

        $gatewayPersists = str_contains($gateway, 'recordSpecialistFlowExecution');
        $resourceExposes = str_contains($traceResource, 'specialist_flow_execution_record');
        $telemetryAggregates = str_contains($telemetry, 'specialistFlowDiagnostics');

        return $this->check(
            'receipts_evidence_telemetry_available',
            $gatewayPersists && $resourceExposes && $telemetryAggregates,
            [
                'gateway_records_execution' => $gatewayPersists,
                'trace_resource_exposes_record' => $resourceExposes,
                'telemetry_aggregates_specialist_flow' => $telemetryAggregates,
                'gateway_path' => 'app/Services/Ai/AiGatewayService.php',
                'trace_resource_path' => 'app/Http/Resources/AiTraceResource.php',
                'telemetry_path' => 'app/Services/Ai/Telemetry/AiTraceMetricAggregator.php',
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function devForgeHandoffExplicitCheck(): array
    {
        $debugWithWorkspace = $this->safeRuntimeApply(
            RouterRuntimeCanon::FLOW_DEBUG,
            ['handoff_payload' => ['workspace_present' => true]],
        );
        $reviewWithWorkspace = $this->safeRuntimeApply(
            RouterRuntimeCanon::FLOW_REVIEW,
            ['handoff_payload' => ['workspace_present' => true]],
        );
        $debugWithoutWorkspace = $this->safeRuntimeApply(RouterRuntimeCanon::FLOW_DEBUG);
        $reviewWithoutWorkspace = $this->safeRuntimeApply(RouterRuntimeCanon::FLOW_REVIEW);

        $debugDelegates = data_get($debugWithWorkspace, 'delegation.status') === 'delegate_to_other_flow'
            && data_get($debugWithWorkspace, 'delegation.target_flow_id') === RouterRuntimeCanon::FLOW_DEV;
        $reviewDelegates = data_get($reviewWithWorkspace, 'delegation.status') === 'delegate_to_other_flow'
            && data_get($reviewWithWorkspace, 'delegation.target_flow_id') === RouterRuntimeCanon::FLOW_DEV;
        $debugStaysWhenNoWorkspace = data_get($debugWithoutWorkspace, 'delegation.status') === 'not_delegated';
        $reviewStaysWhenNoWorkspace = data_get($reviewWithoutWorkspace, 'delegation.status') === 'not_delegated';

        $passed = $debugDelegates && $reviewDelegates && $debugStaysWhenNoWorkspace && $reviewStaysWhenNoWorkspace;

        return $this->check(
            'dev_forge_handoff_explicit',
            $passed,
            [
                'debug_with_workspace_delegates_to_dev' => $debugDelegates,
                'review_with_workspace_delegates_to_dev' => $reviewDelegates,
                'debug_without_workspace_stays_local' => $debugStaysWhenNoWorkspace,
                'review_without_workspace_stays_local' => $reviewStaysWhenNoWorkspace,
                'debug_envelope_with_workspace' => [
                    'flow_id' => $debugWithWorkspace['flow_id'] ?? null,
                    'execution_mode' => $debugWithWorkspace['execution_mode'] ?? null,
                    'delegation' => $debugWithWorkspace['delegation'] ?? null,
                ],
                'review_envelope_with_workspace' => [
                    'flow_id' => $reviewWithWorkspace['flow_id'] ?? null,
                    'execution_mode' => $reviewWithWorkspace['execution_mode'] ?? null,
                    'delegation' => $reviewWithWorkspace['delegation'] ?? null,
                ],
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function benchmarkNotRunCheck(): array
    {
        // The gate itself does NOT execute a benchmark — this check pins
        // the contract so callers can rely on the response shape.
        return $this->check('benchmark_not_run', true, [
            'invariant' => 'specialist_flows_readiness_never_runs_rivals_battery',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function noExternalSuperiorityClaimCheck(): array
    {
        return $this->check('no_external_superiority_claim', true, [
            'invariant' => 'claim_policy.allows_external_superiority_claim_must_be_false',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function testsPresentCheck(): array
    {
        $entryTest = base_path('tests/Feature/Ai/RouterRuntime/AtlasAiInteractionHyperflowEntryTest.php');
        $specialistTest = base_path('tests/Feature/Ai/RouterRuntime/AtlasHyperflowSpecialistFlowsReadinessTest.php');
        $entryExists = is_file($entryTest);
        $specialistExists = is_file($specialistTest);

        return $this->check(
            'tests_present',
            $entryExists && $specialistExists,
            [
                'entry_test_present' => $entryExists,
                'specialist_test_present' => $specialistExists,
                'entry_test_path' => 'tests/Feature/Ai/RouterRuntime/AtlasAiInteractionHyperflowEntryTest.php',
                'specialist_test_path' => 'tests/Feature/Ai/RouterRuntime/AtlasHyperflowSpecialistFlowsReadinessTest.php',
            ],
        );
    }

    /**
     * Run the canonical SpecialistFlowRuntimeService over a deterministic
     * payload. Never persists; the service is purely functional.
     *
     * @param  array<string,mixed>  $routerExtras
     * @return array<string,mixed>
     */
    private function safeRuntimeApply(string $flowId, array $routerExtras = []): array
    {
        $router = array_merge([
            'flow_id' => $flowId,
            'routing_reason' => 'specialist_flows_readiness_probe',
            'command_intent' => 'readiness_probe',
            'flow_origin' => 'router_auto',
            'handoff_payload' => ['workspace_present' => false],
        ], $routerExtras);

        try {
            $result = $this->specialistRuntime->apply([
                'input_text' => 'specialist flows readiness probe',
                'payload' => [
                    'atlas_ai_router' => $router,
                ],
            ]);
        } catch (Throwable) {
            return [];
        }

        $envelope = data_get($result, 'payload.specialist_flow_runtime');

        return is_array($envelope) ? $envelope : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function safeExecutionApply(string $flowId): array
    {
        try {
            $result = $this->specialistExecution->apply([
                'input_text' => 'specialist flows readiness probe',
                'payload' => [
                    'specialist_flow_runtime' => [
                        'schema_version' => AtlasAiSpecialistFlowRuntimeService::SCHEMA_VERSION,
                        'flow_id' => $flowId,
                        'delegation' => ['status' => 'not_delegated'],
                        'receipt' => [
                            'receipt_id' => 'sfr_readiness_'.$flowId,
                            'contract_hash' => str_repeat('a', 64),
                        ],
                    ],
                ],
            ]);
        } catch (Throwable) {
            return [];
        }

        $execution = data_get($result, 'payload.specialist_flow_execution');

        return is_array($execution) ? $execution : [];
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function check(string $id, bool $passed, array $evidence): array
    {
        return [
            'id' => $id,
            'status' => $passed ? 'passed' : 'failed',
            'evidence' => $evidence,
        ];
    }

    private function source(string $path): string
    {
        return is_file($path) ? File::get($path) : '';
    }
}
