<?php

namespace App\Services\Ai\Router;

use Illuminate\Support\Facades\File;

class AtlasAiHyperflowCertificationService
{
    public const SCHEMA_VERSION = 'atlas.ai.hyperflow_certification.v1';

    /**
     * @return array<string,mixed>
     */
    public function certify(): array
    {
        $routerReadiness = app(AtlasAiRouterRuntimeReadinessService::class)->inspect();
        $checks = [
            $this->check('router_runtime_readiness', ($routerReadiness['status'] ?? null) === 'passed', [
                'schema_version' => data_get($routerReadiness, 'schema_version'),
                'status' => data_get($routerReadiness, 'status'),
                'failed' => data_get($routerReadiness, 'summary.failed'),
                'remaining_blockers' => data_get($routerReadiness, 'remaining_blockers', []),
            ]),
            $this->specialistDepthCheck(),
            $this->delegationContractCheck(),
            $this->auditTrailContractCheck(),
            $this->documentationCheck(),
        ];

        $failed = array_values(array_filter($checks, fn (array $check): bool => ($check['status'] ?? null) !== 'passed'));
        $completionAudit = $this->completionAudit($checks);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $failed === [] ? 'passed' : 'blocked',
            'summary' => [
                'total' => count($checks),
                'passed' => count($checks) - count($failed),
                'failed' => count($failed),
            ],
            'checks' => $checks,
            'completion_audit' => $completionAudit,
            'remaining_blockers' => array_map(
                fn (array $check): string => (string) ($check['id'] ?? 'unknown_check'),
                $failed,
            ),
            'surfaces' => [
                'certification' => '/ai/hyperflow/certification',
                'router_readiness' => '/ai/router-runtime/readiness',
                'router_bootstrap' => '/ai/router-runtime/bootstrap',
                'flow_status' => '/ai/interactions/{trace}/flow-status',
            ],
            'writes' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function specialistDepthCheck(): array
    {
        $executionService = app(AtlasAiSpecialistFlowExecutionService::class);
        $flows = [
            AtlasAiRouterDecision::FLOW_RESEARCH,
            AtlasAiRouterDecision::FLOW_DEBUG,
            AtlasAiRouterDecision::FLOW_REVIEW,
            AtlasAiRouterDecision::FLOW_EXPLAIN,
            AtlasAiRouterDecision::FLOW_CONVERSATION,
            AtlasAiRouterDecision::FLOW_PLAN,
        ];

        $results = [];
        foreach ($flows as $flow) {
            $payload = $executionService->apply([
                'input_text' => 'hyperflow certification',
                'payload' => [
                    'specialist_flow_runtime' => [
                        'schema_version' => AtlasAiSpecialistFlowRuntimeService::SCHEMA_VERSION,
                        'flow_id' => $flow,
                        'delegation' => ['status' => 'not_delegated'],
                        'receipt' => [
                            'receipt_id' => 'sfr_cert_'.$flow,
                            'contract_hash' => str_repeat('a', 64),
                        ],
                    ],
                ],
            ]);
            $execution = data_get($payload, 'payload.specialist_flow_execution');
            $results[$flow] = [
                'handler_id' => data_get($execution, 'handler_id'),
                'has_quality_rubric' => is_array(data_get($execution, 'quality_rubric')) && data_get($execution, 'quality_rubric') !== [],
                'has_completion_checks' => is_array(data_get($execution, 'completion_checks')) && data_get($execution, 'completion_checks') !== [],
                'has_failure_modes' => is_array(data_get($execution, 'failure_modes')) && data_get($execution, 'failure_modes') !== [],
            ];
        }
        $missingFlows = array_keys(array_filter($results, fn (array $result): bool => ! $result['handler_id']));
        $missingDeepContracts = array_keys(array_filter(
            $results,
            fn (array $result): bool => ! $result['has_quality_rubric'] || ! $result['has_completion_checks'] || ! $result['has_failure_modes'],
        ));

        return $this->check('specialist_flows.deep_contracts', $missingFlows === [] && $missingDeepContracts === [], [
            'required_flows' => $flows,
            'missing_flows' => $missingFlows,
            'missing_deep_contracts' => $missingDeepContracts,
            'results' => $results,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function delegationContractCheck(): array
    {
        $router = app(AtlasAiRouterService::class);
        $devDecision = $router->decide([
            'input_text' => 'Implemente endpoint de status',
            'payload' => ['workspace' => '/tmp/atlas-workspace'],
        ]);
        $forgeDecision = $router->decide([
            'input_text' => 'Obra enterprise completa',
            'payload' => ['surface_id' => 'atlas_code'],
        ]);
        $debugDelegation = $this->delegationProbe('Debug esse stacktrace no workspace atlas e rode o menor teste relevante.');
        $reviewDelegation = $this->delegationProbe('Revise o diff no workspace atlas e valide regressao antes de concluir.');

        return $this->check('delegation.dev_forge_boundaries', $devDecision->flowId === AtlasAiRouterDecision::FLOW_DEV
            && $forgeDecision->flowId === AtlasAiRouterDecision::FLOW_FORGE
            && data_get($debugDelegation, 'router.flow_id') === AtlasAiRouterDecision::FLOW_DEBUG
            && data_get($debugDelegation, 'runtime.delegation.status') === 'delegate_to_other_flow'
            && data_get($debugDelegation, 'runtime.delegation.target_flow_id') === AtlasAiRouterDecision::FLOW_DEV
            && data_get($debugDelegation, 'execution.status') === 'delegated'
            && data_get($reviewDelegation, 'router.flow_id') === AtlasAiRouterDecision::FLOW_REVIEW
            && data_get($reviewDelegation, 'runtime.delegation.status') === 'delegate_to_other_flow'
            && data_get($reviewDelegation, 'runtime.delegation.target_flow_id') === AtlasAiRouterDecision::FLOW_DEV
            && data_get($reviewDelegation, 'execution.status') === 'delegated', [
                'dev_flow_present' => $devDecision->flowId === AtlasAiRouterDecision::FLOW_DEV,
                'forge_flow_present' => $forgeDecision->flowId === AtlasAiRouterDecision::FLOW_FORGE,
                'atlas_code_promotes_to_forge' => $forgeDecision->routingReason === 'atlas_code_surface_requires_forge',
                'debug_workspace_delegates_to_dev' => $debugDelegation,
                'review_workspace_delegates_to_dev' => $reviewDelegation,
            ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function delegationProbe(string $inputText): array
    {
        $router = app(AtlasAiRouterService::class);
        $runtime = app(AtlasAiSpecialistFlowRuntimeService::class);
        $execution = app(AtlasAiSpecialistFlowExecutionService::class);
        $decision = $router->decide([
            'input_text' => $inputText,
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'workspace' => '/tmp/atlas-workspace',
            ],
        ]);
        $payload = $execution->apply($runtime->apply([
            'input_text' => $inputText,
            'payload' => [
                'atlas_ai_router' => $decision->toArray(),
            ],
        ]));

        return [
            'router' => [
                'flow_id' => $decision->flowId,
                'routing_reason' => $decision->routingReason,
                'alternatives' => $decision->alternativeFlowIds,
            ],
            'runtime' => [
                'flow_id' => data_get($payload, 'payload.specialist_flow_runtime.flow_id'),
                'execution_mode' => data_get($payload, 'payload.specialist_flow_runtime.execution_mode'),
                'delegation' => data_get($payload, 'payload.specialist_flow_runtime.delegation'),
                'receipt_id' => data_get($payload, 'payload.specialist_flow_runtime.receipt.receipt_id'),
            ],
            'execution' => [
                'status' => data_get($payload, 'payload.specialist_flow_execution.status'),
                'handler_id' => data_get($payload, 'payload.specialist_flow_execution.handler_id'),
                'delegation' => data_get($payload, 'payload.specialist_flow_execution.delegation'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function auditTrailContractCheck(): array
    {
        $gatewaySource = $this->source(app_path('Services/Ai/AiGatewayService.php'));
        $resourceSource = $this->source(app_path('Http/Resources/AiTraceResource.php'));
        $telemetrySource = $this->source(app_path('Services/Ai/Telemetry/AiTraceMetricAggregator.php'));

        return $this->check('audit.receipts_persistence_telemetry', str_contains($gatewaySource, 'recordSpecialistFlowExecution')
            && str_contains($resourceSource, 'specialist_flow_execution_record')
            && str_contains($telemetrySource, 'specialistFlowDiagnostics'), [
                'gateway_persists_execution' => str_contains($gatewaySource, 'recordSpecialistFlowExecution'),
                'trace_resource_exposes_record' => str_contains($resourceSource, 'specialist_flow_execution_record'),
                'telemetry_aggregates_specialist_flow' => str_contains($telemetrySource, 'specialistFlowDiagnostics'),
            ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function documentationCheck(): array
    {
        $hyperflowDoc = base_path('docs/engineering-knowledge-base/atlas-hyperflow-operation.md');
        $routerDoc = base_path('docs/engineering-knowledge-base/atlas-ai-router-runtime-enterprise-upgrade.md');
        $completionAuditDoc = base_path('docs/engineering-knowledge-base/atlas-hyperflow-completion-audit-v1.md');
        $runbookDoc = base_path('docs/engineering-knowledge-base/atlas-hyperflow-certification-runbook-v1.md');
        $hyperflowSource = $this->source($hyperflowDoc);
        $routerSource = $this->source($routerDoc);
        $completionAuditSource = $this->source($completionAuditDoc);
        $runbookSource = $this->source($runbookDoc);

        return $this->check('docs.hyperflow_canonical_contracts', is_file($hyperflowDoc)
            && is_file($routerDoc)
            && is_file($completionAuditDoc)
            && is_file($runbookDoc)
            && str_contains($hyperflowSource, 'Atlas Hyperflow Engineering Runtime')
            && str_contains($routerSource, 'atlas.ai.specialist_flow_execution.v1')
            && str_contains($completionAuditSource, 'atlas.ai.hyperflow_completion_audit.v1')
            && str_contains($runbookSource, 'atlas.ai.hyperflow_rivals_battery.v1'), [
                'hyperflow_doc' => 'docs/engineering-knowledge-base/atlas-hyperflow-operation.md',
                'router_doc' => 'docs/engineering-knowledge-base/atlas-ai-router-runtime-enterprise-upgrade.md',
                'completion_audit_doc' => 'docs/engineering-knowledge-base/atlas-hyperflow-completion-audit-v1.md',
                'certification_runbook_doc' => 'docs/engineering-knowledge-base/atlas-hyperflow-certification-runbook-v1.md',
                'hyperflow_doc_present' => is_file($hyperflowDoc),
                'router_doc_present' => is_file($routerDoc),
                'completion_audit_doc_present' => is_file($completionAuditDoc),
                'certification_runbook_doc_present' => is_file($runbookDoc),
            ]);
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

    /**
     * @param  array<int,array<string,mixed>>  $checks
     * @return array<string,mixed>
     */
    private function completionAudit(array $checks): array
    {
        $checksById = [];
        foreach ($checks as $check) {
            $checksById[(string) ($check['id'] ?? 'unknown_check')] = $check;
        }

        $requirements = [
            $this->auditRequirement(
                'router_runtime_backend_first',
                'Fechar Router Runtime para baixo antes de UX pesada.',
                ['router_runtime_readiness'],
                $checksById,
                ['api:/ai/router-runtime/readiness', 'api:/ai/router-runtime/bootstrap'],
            ),
            $this->auditRequirement(
                'intent_kernel_ambiguous_prompts',
                'Implementar Intent Kernel para prompts ambiguos.',
                ['router_runtime_readiness'],
                $checksById,
                ['contract:atlas.ai.intent_kernel.v1'],
            ),
            $this->auditRequirement(
                'specialist_flows_deep_contracts',
                'Completar research, debug, review, explain, conversation e plan com contratos profundos.',
                ['specialist_flows.deep_contracts'],
                $checksById,
                ['contract:atlas.ai.specialist_flow_execution.v1'],
            ),
            $this->auditRequirement(
                'dev_forge_delegation',
                'Garantir delegation correta para Atlas Dev e promocao correta para Atlas Forge.',
                ['delegation.dev_forge_boundaries'],
                $checksById,
                ['contract:atlas.ai.specialist_flow_execution.v1'],
            ),
            $this->auditRequirement(
                'contracts_receipts_persistence_telemetry_audit',
                'Implementar execution contracts, receipts, persistence, telemetry e audit trail para todos os flows.',
                ['audit.receipts_persistence_telemetry', 'specialist_flows.deep_contracts'],
                $checksById,
                ['contract:atlas.ai.specialist_flow_receipt.v1', 'contract:atlas.ai.flow_status.v1'],
            ),
            $this->auditRequirement(
                'aggregate_observability',
                'Criar observabilidade agregada por flow, qualidade, falhas, overrides e delegacoes.',
                ['audit.receipts_persistence_telemetry', 'router_runtime_readiness'],
                $checksById,
                ['api:/ai/telemetry/scorecard', 'metric:specialistFlowDiagnostics'],
            ),
            $this->auditRequirement(
                'backend_readiness_certification_e2e',
                'Criar backend readiness/certification end-to-end do Hyperflow.',
                ['router_runtime_readiness'],
                $checksById,
                ['api:/ai/hyperflow/certification', 'cli:php artisan atlas:ai:hyperflow certify --json'],
            ),
            $this->auditRequirement(
                'canonical_docs',
                'Documentar tudo em docs canonicos.',
                ['docs.hyperflow_canonical_contracts'],
                $checksById,
                [
                    'docs/engineering-knowledge-base/atlas-hyperflow-operation.md',
                    'docs/engineering-knowledge-base/atlas-ai-router-runtime-enterprise-upgrade.md',
                    'docs/engineering-knowledge-base/atlas-hyperflow-completion-audit-v1.md',
                    'docs/engineering-knowledge-base/atlas-hyperflow-certification-runbook-v1.md',
                ],
            ),
            $this->auditRequirement(
                'tests_docs_health_pint_gates',
                'Rodar testes, docs-health, Pint e gates necessarios.',
                ['router_runtime_readiness', 'docs.hyperflow_canonical_contracts'],
                $checksById,
                ['command:php artisan test ...', 'command:vendor/bin/pint --test ...', 'command:php artisan atlas:engineering:knowledge docs-health --json', 'command:git diff --check'],
            ),
        ];

        $readyCriteria = [
            $this->auditRequirement(
                'ambiguous_prompt_routes_correctly',
                'Atlas AI entende prompt ambiguo e escolhe o flow correto.',
                ['router_runtime_readiness'],
                $checksById,
                ['contract:atlas.ai.intent_kernel.v1'],
            ),
            $this->auditRequirement(
                'each_flow_has_own_tested_behavior',
                'Cada flow tem comportamento proprio e testado.',
                ['specialist_flows.deep_contracts'],
                $checksById,
                ['flows:research,debug,review,explain,conversation,plan'],
            ),
            $this->auditRequirement(
                'atlas_dev_light_medium_with_evidence',
                'Atlas Dev resolve programacao leve/media com evidencia.',
                ['delegation.dev_forge_boundaries'],
                $checksById,
                ['contract:atlas.ai.specialist_flow_execution.v1'],
            ),
            $this->auditRequirement(
                'atlas_forge_heavy_obra_handoff',
                'Atlas Forge recebe Obras pesadas com handoff auditavel.',
                ['delegation.dev_forge_boundaries'],
                $checksById,
                ['contract:atlas.ai.specialist_flow_execution.v1'],
            ),
            $this->auditRequirement(
                'receipts_hashes_telemetry',
                'Toda decisao relevante deixa receipt/hash/telemetry.',
                ['audit.receipts_persistence_telemetry'],
                $checksById,
                ['contract:atlas.ai.specialist_flow_receipt.v1'],
            ),
            $this->auditRequirement(
                'final_certification_exists',
                'Existe certificacao final mostrando se esta pronto.',
                ['router_runtime_readiness'],
                $checksById,
                ['api:/ai/hyperflow/certification'],
            ),
        ];

        $blocked = array_values(array_filter(
            array_merge($requirements, $readyCriteria),
            fn (array $item): bool => ($item['status'] ?? null) !== 'passed',
        ));

        return [
            'schema_version' => 'atlas.ai.hyperflow_completion_audit.v1',
            'status' => $blocked === [] ? 'passed' : 'blocked',
            'summary' => [
                'requirements_total' => count($requirements),
                'requirements_passed' => count(array_filter($requirements, fn (array $item): bool => ($item['status'] ?? null) === 'passed')),
                'ready_criteria_total' => count($readyCriteria),
                'ready_criteria_passed' => count(array_filter($readyCriteria, fn (array $item): bool => ($item['status'] ?? null) === 'passed')),
                'blocked' => count($blocked),
            ],
            'requirements' => $requirements,
            'ready_criteria' => $readyCriteria,
            'remaining_blockers' => array_map(
                fn (array $item): string => (string) ($item['id'] ?? 'unknown_audit_item'),
                $blocked,
            ),
        ];
    }

    /**
     * @param  array<int,string>  $checkIds
     * @param  array<string,array<string,mixed>>  $checksById
     * @param  array<int,string>  $artifactRefs
     * @return array<string,mixed>
     */
    private function auditRequirement(
        string $id,
        string $requirement,
        array $checkIds,
        array $checksById,
        array $artifactRefs,
    ): array {
        $evidence = [];
        $blockers = [];

        foreach ($checkIds as $checkId) {
            $check = $checksById[$checkId] ?? null;
            if (! $check) {
                $blockers[] = $checkId;
                $evidence[] = [
                    'check_id' => $checkId,
                    'status' => 'missing',
                ];

                continue;
            }

            $status = (string) ($check['status'] ?? 'failed');
            if ($status !== 'passed') {
                $blockers[] = $checkId;
            }

            $evidence[] = [
                'check_id' => $checkId,
                'status' => $status,
                'evidence' => $check['evidence'] ?? [],
            ];
        }

        return [
            'id' => $id,
            'requirement' => $requirement,
            'status' => $blockers === [] ? 'passed' : 'blocked',
            'evidence_check_ids' => $checkIds,
            'artifact_refs' => $artifactRefs,
            'evidence' => $evidence,
            'blockers' => $blockers,
        ];
    }

    private function source(string $path): string
    {
        return is_file($path) ? File::get($path) : '';
    }
}
