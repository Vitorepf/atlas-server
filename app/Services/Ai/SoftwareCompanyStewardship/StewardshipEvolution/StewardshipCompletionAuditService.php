<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * AP-763 · Requirement-by-requirement completion audit for the
 * Atlas Software Company Stewardship Stack.
 *
 * This is a certifier only. It reuses AP-762 live-cycle certification and the
 * Self-Construction completion-audit pattern; it does not create another
 * runtime, schedule work, invoke providers, mutate repos, merge, deploy or
 * approve irreversible actions.
 */
final class StewardshipCompletionAuditService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.completion_audit.v1';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_INCOMPLETE = 'incomplete';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_AREA_ID = StewardshipLiveCycleCertificationService::DEFAULT_AREA_ID;

    public const DEFAULT_PORTFOLIO_ID = StewardshipLiveCycleCertificationService::DEFAULT_PORTFOLIO_ID;

    private ?string $storageRootOverride = null;

    public function __construct(
        private readonly StewardshipLiveCycleCertificationService $liveCycleCertification,
    ) {}

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
        $this->liveCycleCertification->setStorageRootForTesting($dir !== null ? $dir.'/ap762' : null);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function audit(array $input = []): array
    {
        $areaId = (string) ($input['area_id'] ?? self::DEFAULT_AREA_ID);
        $portfolioId = (string) ($input['portfolio_id'] ?? self::DEFAULT_PORTFOLIO_ID);
        $includeExecutionCertification = (bool) ($input['include_execution_certification'] ?? false);

        $projection = is_array($input['projection_certification'] ?? null)
            ? $input['projection_certification']
            : $this->liveCycleCertification->certify($input + [
                'area_id' => $areaId,
                'portfolio_id' => $portfolioId,
                'execute_owner_command' => false,
            ]);

        $execution = null;
        if ($includeExecutionCertification) {
            $execution = is_array($input['execution_certification'] ?? null)
                ? $input['execution_certification']
                : $this->liveCycleCertification->certify($input + [
                    'area_id' => $areaId,
                    'portfolio_id' => $portfolioId,
                    'execute_owner_command' => true,
                ]);
        }

        $requirements = array_map(
            fn (array $requirement): array => $this->evaluateRequirement($requirement, $projection, $execution, $includeExecutionCertification),
            $this->requirementMatrix(),
        );

        $summary = $this->summary($requirements);
        $completionAllowed = $summary['missing_count'] === 0
            && $summary['weak_count'] === 0
            && $summary['contradicted_count'] === 0
            && $this->liveCycleCertified($projection)
            && (! $includeExecutionCertification || $this->liveCycleCertified($execution ?? []));

        $blockers = $this->blockers($requirements, $projection, $execution, $includeExecutionCertification);
        $status = $completionAllowed ? self::STATUS_COMPLETE : self::STATUS_INCOMPLETE;

        if (! $this->liveCycleCertified($projection)) {
            $status = self::STATUS_BLOCKED;
        }

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'status' => $status,
            'ap_contract' => 'AP-763',
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'mode' => $includeExecutionCertification
                ? 'requirement_audit_with_owner_execution_certification'
                : 'requirement_audit_projection_only',
            'completion_claim_allowed' => $completionAllowed,
            'operator_claim' => $completionAllowed
                ? 'practical_requirement_list_fully_proven'
                : 'completion_claim_blocked_until_all_requirements_are_proven',
            'current_practical_number' => $this->currentPracticalNumber($requirements),
            'target_practical_number' => count($requirements),
            'requirement_count' => count($requirements),
            'proven_count' => $summary['proven_count'],
            'weak_count' => $summary['weak_count'],
            'missing_count' => $summary['missing_count'],
            'contradicted_count' => $summary['contradicted_count'],
            'requirements' => $requirements,
            'blockers' => $blockers,
            'projection_certification' => $this->liveCycleSummary($projection),
            'execution_certification' => $execution === null ? [
                'included' => false,
                'status' => 'not_requested',
                'required_for_real_owner_execution_proof' => true,
                'command' => 'php artisan atlas:software-company-stewardship completion-audit --include-execution-certification --json',
            ] : $this->liveCycleSummary($execution) + ['included' => true],
            'duplicate_overlap_resolution' => [
                'reuses_ap762_live_cycle_certification' => true,
                'reuses_self_construction_completion_audit_pattern' => true,
                'creates_parallel_runtime' => false,
                'supersedes_ap762' => false,
                'duplicates_area_or_self_expanding_owners' => false,
            ],
            'claim_policy' => $this->claimPolicy($includeExecutionCertification),
            'non_blocking_maintainability_warnings' => $this->maintainabilityWarnings(),
            'generated_at' => $this->now(),
        ];

        $payload['completion_audit_id'] = 'scsa_'.substr(MissionCanonicalHash::sha256($this->identity($payload)), 0, 24);
        $payload['completion_audit_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));

        return $payload;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function requirementMatrix(): array
    {
        return [
            $this->requirement(1, 'area_focus_read_only', 'Area Focus read-only para agentic_engineering_os.', ['AP-716'], [
                'App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtlasAreaFocusLoopReadModelService',
            ], [
                'tests/Unit/Ai/SoftwareCompanyStewardship/AtlasAreaFocusLoopReadModelServiceTest.php',
            ]),
            $this->requirement(2, 'finding_engine', 'Finding engine: detectar bugs, gaps, riscos e melhorias.', ['AP-717'], [
                'App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AgenticEngineeringOsFindingEngineService',
            ], [
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AgenticEngineeringOsFindingEngineServiceTest.php',
            ]),
            $this->requirement(3, 'morning_inbox', 'Morning Inbox para achados revisáveis.', ['AP-718', 'AP-740'], [
                'App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusInboxService',
                'App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOutcomeEvidenceBridgeService',
            ], [
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusInboxServiceTest.php',
                'tests/Unit/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOutcomeEvidenceBridgeServiceTest.php',
            ]),
            $this->requirement(4, 'spec_drafts', 'Spec drafts automáticos.', ['AP-718'], [
                'App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusSpecDraftBridge',
            ], [
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusSpecDraftBridgeTest.php',
            ]),
            $this->requirement(5, 'safety_gates_by_area', 'Safety gates por área.', ['AP-720'], [
                'App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusGateEvaluatorService',
            ], [
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusGateEvaluatorServiceTest.php',
            ]),
            $this->requirement(6, 'evidence_pack_per_cycle', 'Evidence pack por ciclo.', ['AP-720', 'AP-740'], [
                'App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusEvidencePackService',
                'App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOutcomeEvidenceBridgeService',
            ], [
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusEvidencePackServiceTest.php',
                'tests/Unit/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOutcomeEvidenceBridgeServiceTest.php',
            ]),
            $this->requirement(7, 'routing_dev_forge', 'Routing Dev/Forge.', ['AP-719', 'AP-722'], [
                'App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDevForgeRouterService',
                'App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusLoopOperationalOrchestratorService',
            ], [
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDevForgeRouterServiceTest.php',
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusLoopOperationalOrchestratorServiceTest.php',
            ], ['ap722_area_focus_cycle']),
            $this->requirement(8, 'branch_sandbox_handoff_preflight', 'Branch sandbox handoff/preflight.', ['AP-726'], [
                'App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxHandoffService',
                'App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxPreflightService',
            ], [
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxHandoffServiceTest.php',
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxPreflightServiceTest.php',
            ]),
            $this->requirement(9, 'branch_worktree_materializer_isolated_receipt', 'Branch/worktree materializer isolado com receipt do operador.', ['AP-756', 'AP-757'], [
                'App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxMaterializerService',
            ], [
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxMaterializerServiceTest.php',
            ]),
            $this->requirement(10, 'continuous_stewardship_scheduler_safe_tick', 'Continuous Stewardship scheduler-safe tick.', ['AP-745'], [
                'App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\AtlasContinuousStewardshipLoopService',
            ], [
                'tests/Unit/Ai/SoftwareCompanyStewardship/ContinuousStewardship/AtlasContinuousStewardshipLoopServiceTest.php',
            ], ['ap745_continuous_tick']),
            $this->requirement(11, 'recurring_runner_24h_controls', 'Recurring runner 24h com locks, budgets, rate limit, pause e kill switch.', ['AP-746', 'AP-754'], [
                'App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\AtlasContinuousStewardshipRecurringSchedulerService',
                'App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeOperationalControlsReadModelService',
            ], [
                'tests/Unit/Ai/SoftwareCompanyStewardship/ContinuousStewardship/AtlasContinuousStewardshipRecurringSchedulerServiceTest.php',
                'tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlsReadModelServiceTest.php',
            ], ['ap746_recurring_scheduler']),
            $this->requirement(12, 'product_mode_cockpit_controls', 'Product Mode cockpit com controles operacionais.', ['AP-739', 'AP-754', 'AP-761'], [
                'App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeCockpitSurfaceService',
                'App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeOperationalControlsReadModelService',
            ], [
                'tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceServiceTest.php',
                'tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlsReadModelServiceTest.php',
            ], ['ap739_ap761_product_mode_visibility']),
            $this->requirement(13, 'product_mode_control_receipts', 'Receipts persistentes dos controles do Product Mode.', ['AP-755'], [
                'App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeOperationalControlReceiptService',
            ], [
                'tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlReceiptServiceTest.php',
            ]),
            $this->requirement(14, 'area_stewardship_readiness', 'Area Stewardship readiness.', ['AP-732'], [
                'App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipPromotionReadinessService',
            ], [
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaStewardship/AreaStewardshipPromotionReadinessServiceTest.php',
            ]),
            $this->requirement(15, 'area_stewardship_active_handoff', 'Area Stewardship active handoff.', ['AP-743'], [
                'App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipActiveHandoffService',
            ], [
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaStewardship/AreaStewardshipActiveHandoffServiceTest.php',
            ], ['ap743_active_handoff']),
            $this->requirement(16, 'area_stewardship_active_operating_slice', 'Area Stewardship active operating slice.', ['AP-744'], [
                'App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipActiveOperatingService',
            ], [
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaStewardship/AreaStewardshipActiveOperatingServiceTest.php',
            ], ['ap744_active_operation']),
            $this->requirement(17, 'release_handoffs_to_dev_forge_queues', 'Release de handoffs para filas reais Atlas Dev/Forge.', ['AP-747'], [
                'App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDevForgeReleaseService',
            ], [
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDevForgeReleaseServiceTest.php',
            ], ['ap747_dev_forge_release']),
            $this->requirement(18, 'owner_specific_consumption_gate', 'Owner-specific consumption gate.', ['AP-749'], [
                'App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOwnerQueueConsumptionGateService',
            ], [
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusOwnerQueueConsumptionGateServiceTest.php',
            ], ['ap749_owner_consumption']),
            $this->requirement(19, 'real_dev_forge_execution_inside_isolated_sandbox', 'Execução real Dev/Forge dentro do sandbox isolado.', ['AP-758', 'AP-759'], [
                'App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeExecutionAdapterService',
                'App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerSandboxRuntimeRunnerService',
            ], [
                'tests/Unit/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerRuntimeExecutionAdapterServiceTest.php',
                'tests/Unit/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerSandboxRuntimeRunnerServiceTest.php',
            ], ['ap758_owner_execution_adapter'], ['ap759_owner_sandbox_runner'], true),
            $this->requirement(20, 'owner_runtime_result_bridge', 'Owner runtime result bridge para Evidence/Morning Inbox/Portfolio.', ['AP-750'], [
                'App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeResultBridgeService',
            ], [
                'tests/Unit/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerRuntimeResultBridgeServiceTest.php',
            ], ['ap750_owner_result_bridge']),
            $this->requirement(21, 'portfolio_health_risk_rebalance_intake', 'Portfolio health/risk/rebalance intake.', ['AP-733', 'AP-751'], [
                'App\Services\Ai\SoftwareCompanyStewardship\PortfolioStewardship\PortfolioStewardshipHealthModelService',
            ], [
                'tests/Unit/Ai/SoftwareCompanyStewardship/PortfolioStewardship/PortfolioStewardshipHealthModelServiceTest.php',
            ], ['ap751_portfolio_result_intake']),
            $this->requirement(22, 'portfolio_steward_inbox', 'Portfolio Steward Inbox.', ['AP-734'], [
                'App\Services\Ai\SoftwareCompanyStewardship\PortfolioStewardship\PortfolioStewardshipInboxService',
            ], [
                'tests/Unit/Ai/SoftwareCompanyStewardship/PortfolioStewardship/PortfolioStewardshipInboxServiceTest.php',
            ], ['ap734_portfolio_inbox']),
            $this->requirement(23, 'autonomous_executive_recommendation_pack', 'Autonomous Executive recommendation pack.', ['AP-735'], [
                'App\Services\Ai\SoftwareCompanyStewardship\AutonomousExecutive\AutonomousExecutiveRecommendationService',
            ], [
                'tests/Unit/Ai/SoftwareCompanyStewardship/AutonomousExecutive/AutonomousExecutiveRecommendationServiceTest.php',
            ], ['ap735_executive_recommendations']),
            $this->requirement(24, 'executive_decision_inbox', 'Executive Decision Inbox.', ['AP-736'], [
                'App\Services\Ai\SoftwareCompanyStewardship\AutonomousExecutive\ExecutiveDecisionInboxSurfaceService',
            ], [
                'tests/Unit/Ai/SoftwareCompanyStewardship/AutonomousExecutive/ExecutiveDecisionInboxSurfaceServiceTest.php',
            ]),
            $this->requirement(25, 'executive_allocation_handoff', 'Executive allocation handoff para owners corretos.', ['AP-752'], [
                'App\Services\Ai\SoftwareCompanyStewardship\AutonomousExecutive\AutonomousExecutiveAllocationHandoffService',
            ], [
                'tests/Unit/Ai/SoftwareCompanyStewardship/AutonomousExecutive/AutonomousExecutiveAllocationHandoffServiceTest.php',
            ], ['ap752_executive_allocation']),
            $this->requirement(26, 'product_mode_visibility_for_handoffs', 'Product Mode visibility desses handoffs.', ['AP-739', 'AP-760', 'AP-761'], [
                'App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeCockpitSurfaceService',
            ], [
                'tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceServiceTest.php',
            ], ['ap739_ap761_product_mode_visibility']),
            $this->requirement(27, 'new_area_proposal_gate', 'New Area Proposal Gate.', ['AP-737'], [
                'App\Services\Ai\SoftwareCompanyStewardship\SelfExpanding\NewAreaProposalGateService',
            ], [
                'tests/Unit/Ai/SoftwareCompanyStewardship/SelfExpanding/NewAreaProposalGateServiceTest.php',
            ]),
            $this->requirement(28, 'domain_runtime_creation_handoff', 'Domain Runtime Creation handoff.', ['AP-741'], [
                'App\Services\Ai\SoftwareCompanyStewardship\SelfExpanding\SelfExpandingDomainRuntimeCreationHandoffService',
            ], [
                'tests/Unit/Ai/SoftwareCompanyStewardship/SelfExpanding/SelfExpandingDomainRuntimeCreationHandoffServiceTest.php',
            ]),
            $this->requirement(29, 'self_expanding_software_company_v0_proposal_only', 'Self-Expanding Software Company v0 proposal-only.', ['AP-738'], [
                'App\Services\Ai\SoftwareCompanyStewardship\SelfExpanding\SelfExpandingSoftwareCompanyService',
            ], [
                'tests/Unit/Ai/SoftwareCompanyStewardship/SelfExpanding/SelfExpandingSoftwareCompanyServiceTest.php',
            ]),
        ];
    }

    /**
     * @param  list<string>  $apContracts
     * @param  list<class-string|string>  $serviceClasses
     * @param  list<string>  $testFiles
     * @param  list<string>  $projectionStages
     * @param  list<string>  $executionStages
     * @return array<string,mixed>
     */
    private function requirement(
        int $order,
        string $id,
        string $userText,
        array $apContracts,
        array $serviceClasses,
        array $testFiles,
        array $projectionStages = [],
        array $executionStages = [],
        bool $requiresExecutionCertification = false,
    ): array {
        return [
            'order' => $order,
            'requirement_id' => $id,
            'user_requirement' => $userText,
            'ap_contracts' => $apContracts,
            'service_classes' => $serviceClasses,
            'test_files' => $testFiles,
            'projection_stage_keys' => $projectionStages,
            'execution_stage_keys' => $executionStages,
            'requires_execution_certification' => $requiresExecutionCertification,
        ];
    }

    /**
     * @param  array<string,mixed>  $requirement
     * @param  array<string,mixed>  $projection
     * @param  array<string,mixed>|null  $execution
     * @return array<string,mixed>
     */
    private function evaluateRequirement(array $requirement, array $projection, ?array $execution, bool $includeExecutionCertification): array
    {
        $checks = [];

        foreach ((array) $requirement['ap_contracts'] as $ap) {
            $checks[] = $this->check(
                id: 'ap_contract_'.$ap,
                status: $this->apContractExists((string) $ap) ? 'passed' : 'missing',
                evidence: ['ap_contract' => $ap, 'path' => $this->apContractPath((string) $ap)],
            );
        }

        foreach ((array) $requirement['service_classes'] as $class) {
            $checks[] = $this->check(
                id: 'service_class_'.$this->slug((string) $class),
                status: class_exists((string) $class) ? 'passed' : 'missing',
                evidence: ['class' => $class],
            );
        }

        foreach ((array) $requirement['test_files'] as $path) {
            $checks[] = $this->check(
                id: 'test_file_'.$this->slug((string) $path),
                status: $this->fileExists((string) $path) ? 'passed' : 'missing',
                evidence: ['path' => $path],
            );
        }

        foreach ((array) $requirement['projection_stage_keys'] as $stageKey) {
            $checks[] = $this->liveCycleStageCheck('projection', (string) $stageKey, $projection);
        }

        foreach ((array) $requirement['execution_stage_keys'] as $stageKey) {
            if (! $includeExecutionCertification || $execution === null) {
                $checks[] = $this->check(
                    id: 'execution_stage_'.$stageKey,
                    status: 'weak',
                    evidence: [
                        'stage_key' => $stageKey,
                        'reason' => 'execution_certification_not_requested',
                        'command' => 'php artisan atlas:software-company-stewardship completion-audit --include-execution-certification --json',
                    ],
                );
                continue;
            }
            $checks[] = $this->liveCycleStageCheck('execution', (string) $stageKey, $execution);
        }

        if ((bool) ($requirement['requires_execution_certification'] ?? false)) {
            $commandCompleted = $includeExecutionCertification
                && $execution !== null
                && (string) data_get($execution, 'stages.owner_sandbox_runtime_runner.command_result.status') === 'completed'
                && (int) data_get($execution, 'stages.owner_sandbox_runtime_runner.command_result.exit_code', 1) === 0;
            $checks[] = $this->check(
                id: 'owner_command_completed_inside_sandbox',
                status: $commandCompleted ? 'passed' : 'weak',
                evidence: [
                    'command_result_status' => (string) data_get($execution ?? [], 'stages.owner_sandbox_runtime_runner.command_result.status', 'not_available'),
                    'exit_code' => data_get($execution ?? [], 'stages.owner_sandbox_runtime_runner.command_result.exit_code'),
                    'requires_include_execution_certification' => true,
                ],
            );
        }

        $status = $this->requirementStatus($checks);

        return [
            'order' => (int) $requirement['order'],
            'requirement_id' => (string) $requirement['requirement_id'],
            'status' => $status,
            'proven' => $status === 'proven',
            'user_requirement' => (string) $requirement['user_requirement'],
            'ap_contracts' => (array) $requirement['ap_contracts'],
            'service_classes' => (array) $requirement['service_classes'],
            'test_files' => (array) $requirement['test_files'],
            'checks' => $checks,
            'blockers' => array_values(array_map(
                static fn (array $check): string => (string) $check['id'].':'.(string) $check['status'],
                array_filter($checks, static fn (array $check): bool => (string) $check['status'] !== 'passed'),
            )),
            'claim_boundary' => $this->claimBoundary($requirement),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function liveCycleStageCheck(string $mode, string $stageKey, array $certification): array
    {
        $stage = (array) data_get($certification, 'certification_matrix.'.$stageKey, []);

        return $this->check(
            id: $mode.'_live_cycle_stage_'.$stageKey,
            status: (bool) ($stage['passed'] ?? false) ? 'passed' : 'missing',
            evidence: [
                'mode' => $mode,
                'stage_key' => $stageKey,
                'stage_status' => (string) ($stage['status'] ?? 'missing'),
                'ap_contract' => (string) ($stage['ap_contract'] ?? ''),
                'hash' => (string) ($stage['hash'] ?? ''),
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function check(string $id, string $status, array $evidence): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'passed' => $status === 'passed',
            'evidence' => $evidence,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     */
    private function requirementStatus(array $checks): string
    {
        $statuses = array_values(array_map(static fn (array $check): string => (string) $check['status'], $checks));
        if (in_array('contradicted', $statuses, true)) {
            return 'contradicted';
        }
        if (in_array('missing', $statuses, true)) {
            return 'missing';
        }
        if (in_array('weak', $statuses, true)) {
            return 'weak';
        }

        return 'proven';
    }

    /**
     * @param  list<array<string,mixed>>  $requirements
     * @return array<string,int>
     */
    private function summary(array $requirements): array
    {
        $counts = [
            'proven_count' => 0,
            'weak_count' => 0,
            'missing_count' => 0,
            'contradicted_count' => 0,
        ];

        foreach ($requirements as $requirement) {
            match ((string) $requirement['status']) {
                'proven' => $counts['proven_count']++,
                'weak' => $counts['weak_count']++,
                'missing' => $counts['missing_count']++,
                'contradicted' => $counts['contradicted_count']++,
                default => null,
            };
        }

        return $counts;
    }

    /**
     * @param  list<array<string,mixed>>  $requirements
     */
    private function currentPracticalNumber(array $requirements): int
    {
        $current = 0;
        foreach ($requirements as $requirement) {
            if ((string) ($requirement['status'] ?? '') !== 'proven') {
                break;
            }
            $current = (int) $requirement['order'];
        }

        return $current;
    }

    /**
     * @param  list<array<string,mixed>>  $requirements
     * @return list<string>
     */
    private function blockers(array $requirements, array $projection, ?array $execution, bool $includeExecutionCertification): array
    {
        $blockers = [];
        foreach ($requirements as $requirement) {
            foreach ((array) ($requirement['blockers'] ?? []) as $blocker) {
                $blockers[] = ((int) $requirement['order']).'.'.(string) $requirement['requirement_id'].':'.$blocker;
            }
        }

        if (! $this->liveCycleCertified($projection)) {
            $blockers[] = 'ap762_projection_certification_not_certified:'.(string) ($projection['status'] ?? 'missing');
        }
        if ($includeExecutionCertification && ! $this->liveCycleCertified($execution ?? [])) {
            $blockers[] = 'ap762_execution_certification_not_certified:'.(string) data_get($execution ?? [], 'status', 'missing');
        }

        return array_values(array_unique($blockers));
    }

    private function liveCycleCertified(array $certification): bool
    {
        return (string) ($certification['schema_version'] ?? '') === StewardshipLiveCycleCertificationService::REPORT_SCHEMA
            && (string) ($certification['status'] ?? '') === StewardshipLiveCycleCertificationService::STATUS_CERTIFIED
            && (array) ($certification['blockers'] ?? []) === [];
    }

    /**
     * @return array<string,mixed>
     */
    private function liveCycleSummary(array $certification): array
    {
        return [
            'schema_version' => (string) ($certification['schema_version'] ?? ''),
            'status' => (string) ($certification['status'] ?? 'missing'),
            'mode' => (string) ($certification['mode'] ?? ''),
            'certification_id' => (string) ($certification['certification_id'] ?? ''),
            'certification_hash' => (string) ($certification['certification_hash'] ?? ''),
            'blocker_count' => count((array) ($certification['blockers'] ?? [])),
            'stage_status' => (array) ($certification['stage_status'] ?? []),
        ];
    }

    /**
     * @param  array<string,mixed>  $requirement
     * @return array<string,mixed>
     */
    private function claimBoundary(array $requirement): array
    {
        return [
            'operator_review_required_for_irreversible_actions' => true,
            'auto_merge_allowed' => false,
            'auto_deploy_allowed' => false,
            'secret_access_allowed' => false,
            'new_runtime_creation_allowed_by_this_requirement' => false,
            'execution_proof_requires_ap762_when_applicable' => (bool) ($requirement['requires_execution_certification'] ?? false),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function claimPolicy(bool $includeExecutionCertification): array
    {
        return [
            'certifier_only' => true,
            'completion_claim_requires_all_requirements_proven' => true,
            'projection_certification_required' => true,
            'execution_certification_requested' => $includeExecutionCertification,
            'creates_new_runtime' => false,
            'schedules_recurring_work' => false,
            'direct_provider_call_by_audit' => false,
            'merge_performed_by_audit' => false,
            'deploy_performed_by_audit' => false,
            'secret_access_by_audit' => false,
            'destructive_change_by_audit' => false,
            'operator_approval_bypassed' => false,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function maintainabilityWarnings(): array
    {
        $warnings = [];
        foreach ([
            'docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md',
            'docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md',
            'docs/engineering-knowledge-base/atlas-self-directed-evolution-layer.md',
            'docs/engineering-knowledge-base/atlas-agentic-engineering-os-department-contract.md',
        ] as $path) {
            if (! $this->fileExists($path)) {
                continue;
            }
            $lines = count(file($this->absolutePath($path), FILE_IGNORE_NEW_LINES) ?: []);
            if ($lines > 520) {
                $warnings[] = [
                    'type' => 'canonical_doc_split_recommended',
                    'path' => $path,
                    'line_count' => $lines,
                    'blocking_completion_claim' => false,
                ];
            }
        }

        return $warnings;
    }

    private function apContractExists(string $ap): bool
    {
        return $this->fileExists($this->apContractPath($ap));
    }

    private function apContractPath(string $ap): string
    {
        $prefixes = [$ap.'-', strtolower($ap).'-'];
        foreach ($prefixes as $prefix) {
            foreach (glob($this->absolutePath('docs/ap/'.$prefix.'*.md')) ?: [] as $path) {
                return $this->relativePath((string) $path);
            }
        }

        foreach (glob($this->absolutePath('docs/ap/'.strtoupper($ap).'-*.md')) ?: [] as $path) {
            return $this->relativePath((string) $path);
        }

        return 'docs/ap/'.$ap.'-*.md';
    }

    private function fileExists(string $path): bool
    {
        return is_file($this->absolutePath($path));
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return function_exists('base_path')
            ? base_path($path)
            : getcwd().DIRECTORY_SEPARATOR.$path;
    }

    private function relativePath(string $path): string
    {
        $base = function_exists('base_path') ? base_path() : (string) getcwd();
        if (str_starts_with($path, $base.DIRECTORY_SEPARATOR)) {
            return substr($path, strlen($base) + 1);
        }

        return $path;
    }

    private function slug(string $value): string
    {
        $value = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $value) ?? '');

        return trim($value, '_');
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        unset($payload['generated_at'], $payload['completion_audit_id'], $payload['completion_audit_hash']);

        return $payload;
    }
}
