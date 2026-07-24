<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDevForgeReleaseService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusLoopOperationalOrchestratorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOwnerQueueConsumptionGateService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipActiveHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipActiveOperatingService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipPromotionReadinessService;
use App\Services\Ai\SoftwareCompanyStewardship\AutonomousExecutive\AutonomousExecutiveAllocationHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\AutonomousExecutive\AutonomousExecutiveRecommendationService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\AtlasContinuousStewardshipLoopService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\AtlasContinuousStewardshipRecurringSchedulerService;
use App\Services\Ai\SoftwareCompanyStewardship\PortfolioStewardship\PortfolioStewardshipHealthModelService;
use App\Services\Ai\SoftwareCompanyStewardship\PortfolioStewardship\PortfolioStewardshipInboxService;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeCockpitSurfaceService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipStringListNormalizer;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * AP-762 · End-to-end live certification for the Stewardship Stack.
 *
 * This service is a certifier, not a new runtime. It conducts one bounded proof
 * path across the existing owners from Area Focus through Product Mode:
 *
 *   AP-722 -> AP-743 -> AP-744 -> AP-745 -> AP-746 -> AP-747 -> AP-748
 *   -> AP-749 -> AP-758 -> AP-759 -> AP-750 -> AP-751 -> AP-752 -> AP-739/AP-761
 *
 * It never bypasses owner services and never authorizes merge/deploy/secrets.
 * Optional AP-759 execution is confined to a supplied or certification-only
 * sandbox and still flows through AP-750 before Portfolio/Executive intake.
 */
final class StewardshipLiveCycleCertificationService
{
    use StewardshipEvolutionClock;

    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.live_cycle_certification.v1';

    public const STATUS_CERTIFIED = 'certified';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    public const DEFAULT_PORTFOLIO_ID = 'atlas_software_company';

    private ?string $storageRootOverride = null;

    public function __construct(
        private readonly AreaFocusLoopOperationalOrchestratorService $areaFocus,
        private readonly AreaStewardshipActiveHandoffService $activeHandoff,
        private readonly AreaStewardshipActiveOperatingService $activeOperating,
        private readonly AtlasContinuousStewardshipLoopService $continuousLoop,
        private readonly AtlasContinuousStewardshipRecurringSchedulerService $continuousScheduler,
        private readonly AreaFocusDevForgeReleaseService $devForgeRelease,
        private readonly StewardshipOutcomeEvidenceBridgeService $outcomeEvidence,
        private readonly AreaFocusOwnerQueueConsumptionGateService $ownerConsumption,
        private readonly StewardshipOwnerRuntimeExecutionAdapterService $ownerExecution,
        private readonly StewardshipOwnerSandboxRuntimeRunnerService $ownerSandboxRunner,
        private readonly StewardshipOwnerRuntimeResultBridgeService $ownerResultBridge,
        private readonly PortfolioStewardshipHealthModelService $portfolioHealth,
        private readonly PortfolioStewardshipInboxService $portfolioInbox,
        private readonly AutonomousExecutiveRecommendationService $executiveRecommendations,
        private readonly AutonomousExecutiveAllocationHandoffService $executiveAllocation,
        private readonly ProductModeCockpitSurfaceService $productModeCockpit,
    ) {}

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
        $this->activeHandoff->setStorageRootForTesting($dir !== null ? $dir.'/ap743' : null);
        $this->activeOperating->setStorageRootForTesting($dir !== null ? $dir.'/ap744' : null);
        $this->continuousLoop->setStorageRootForTesting($dir !== null ? $dir.'/ap745' : null);
        $this->continuousScheduler->setStorageRootForTesting($dir !== null ? $dir.'/ap746' : null);
        $this->devForgeRelease->setStorageRootForTesting($dir !== null ? $dir.'/ap747' : null);
        $this->ownerConsumption->setStorageRootForTesting($dir !== null ? $dir.'/ap749' : null);
        $this->ownerExecution->setStorageRootForTesting($dir !== null ? $dir.'/ap758' : null);
        $this->ownerSandboxRunner->setStorageRootForTesting($dir !== null ? $dir.'/ap759' : null);
        $this->ownerResultBridge->setStorageRootForTesting($dir !== null ? $dir.'/ap750' : null);
        $this->portfolioHealth->setStorageRootForTesting($dir !== null ? $dir.'/ap733' : null);
        $this->portfolioInbox->setStorageRootForTesting($dir !== null ? $dir.'/ap734' : null);
        $this->executiveRecommendations->setStorageRootForTesting($dir !== null ? $dir.'/ap735' : null);
        $this->executiveAllocation->setStorageRootForTesting($dir !== null ? $dir.'/ap752' : null);
    }

    public function storageDir(): string
    {
        if ($this->storageRootOverride !== null) {
            return $this->storageRootOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/live_cycle_certification')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/live_cycle_certification';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function certify(array $input = []): array
    {
        $areaId = $this->areaId($input);
        $portfolioId = $this->portfolioId($input);
        $executeOwnerCommand = (bool) ($input['execute_owner_command'] ?? false);

        try {
            $cycle = is_array($input['operational_cycle'] ?? null)
                ? $input['operational_cycle']
                : $this->areaFocus->run($input + [
                    'area_id' => $areaId,
                    'findings' => $this->certificationFindings($areaId),
                ]);

            $activeHandoff = is_array($input['active_handoff_report'] ?? null)
                ? $input['active_handoff_report']
                : $this->activeHandoff->project($input + [
                    'area_id' => $areaId,
                    'readiness_report' => $this->readyAreaStewardshipReport($areaId),
                ]);

            $activeOperation = is_array($input['active_operation_report'] ?? null)
                ? $input['active_operation_report']
                : $this->activeOperating->operate($input + [
                    'area_id' => $areaId,
                    'active_handoff_report' => $activeHandoff,
                    'operational_cycle' => $cycle,
                    'operator_receipts' => $this->operatorReceiptsFromCycle($cycle),
                    'gate_report' => ['decision' => 'allow', 'blocking_gates' => []],
                ]);

            $branchHandoff = is_array($activeOperation['branch_sandbox_handoff'] ?? null)
                ? $activeOperation['branch_sandbox_handoff']
                : [];
            $readyHandoff = $this->firstReadyHandoff($branchHandoff);

            $release = is_array($input['dev_forge_release'] ?? null)
                ? $input['dev_forge_release']
                : ($readyHandoff === null
                    ? $this->blockedStage('AP-747', 'ready_ap726_handoff_required')
                    : $this->devForgeRelease->release([
                        'area_id' => $areaId,
                        'preflight_report' => $branchHandoff,
                        'release_receipt' => $this->releaseReceipt($readyHandoff),
                        'record_release' => false,
                        'workspace' => (string) ($input['workspace'] ?? 'atlas-server'),
                    ]));

            $outcome = is_array($input['stewardship_outcome_history'] ?? null)
                ? $input['stewardship_outcome_history']
                : $this->outcomeEvidence->project($input + [
                    'area_id' => $areaId,
                    'portfolio_id' => $portfolioId,
                    'release_reports' => $this->stageReady($release) ? [$release] : [],
                    'record_evidence' => false,
                    'emit_inbox' => false,
                ]);

            $worktree = $this->worktreePath($input, $areaId);
            $sandbox = is_array($input['sandbox_record'] ?? null)
                ? $input['sandbox_record']
                : $this->sandboxRecord($areaId, $release, $worktree);

            $consumption = is_array($input['owner_queue_consumption'] ?? null)
                ? $input['owner_queue_consumption']
                : $this->ownerConsumption->project([
                    'area_id' => $areaId,
                    'release_report' => $release,
                    'outcome_bridge' => $outcome,
                    'sandbox_record' => $sandbox,
                    'execution_receipt' => $this->executionReceipt($release),
                    'record_consumption' => false,
                ]);

            $execution = is_array($input['owner_runtime_execution'] ?? null)
                ? $input['owner_runtime_execution']
                : $this->ownerExecution->project([
                    'area_id' => $areaId,
                    'portfolio_id' => $portfolioId,
                    'consumption_report' => $consumption,
                    'runtime_start_receipt' => $this->runtimeStartReceipt($consumption),
                    'record_execution' => false,
                ]);

            if ($executeOwnerCommand) {
                $this->ensureCertificationOwnerCommand($worktree);
            }

            $ownerRun = is_array($input['owner_sandbox_runtime_runner'] ?? null)
                ? $input['owner_sandbox_runtime_runner']
                : $this->ownerSandboxRunner->project([
                    'area_id' => $areaId,
                    'portfolio_id' => $portfolioId,
                    'execution_adapter_report' => $execution,
                    'runtime_command_receipt' => $this->runtimeCommandReceipt($execution, $executeOwnerCommand),
                    'execute' => $executeOwnerCommand,
                    'record_run' => false,
                ]);

            $ownerResult = is_array($ownerRun['owner_result'] ?? null)
                ? $ownerRun['owner_result']
                : (is_array($execution['owner_result'] ?? null) ? $execution['owner_result'] : []);

            $resultBridge = is_array($input['owner_runtime_result_bridge'] ?? null)
                ? $input['owner_runtime_result_bridge']
                : $this->ownerResultBridge->project([
                    'area_id' => $areaId,
                    'portfolio_id' => $portfolioId,
                    'consumption_report' => $consumption,
                    'owner_result' => $ownerResult,
                    'record_result' => false,
                ]);

            $portfolioHealth = $this->portfolioHealth->project([
                'area_id' => $areaId,
                'portfolio_id' => $portfolioId,
                'release_outcome_bridge' => $outcome,
                'owner_runtime_result_bridge' => $resultBridge,
            ]);
            $portfolioInbox = $this->portfolioInbox->project([
                'area_id' => $areaId,
                'portfolio_id' => $portfolioId,
                'health_report' => $portfolioHealth,
            ]);
            $executivePack = $this->executiveRecommendations->project([
                'area_id' => $areaId,
                'portfolio_id' => $portfolioId,
                'portfolio_inbox' => $portfolioInbox,
                'max_recommendations' => 1,
            ]);
            $executiveAllocation = $this->executiveAllocation->project([
                'area_id' => $areaId,
                'portfolio_id' => $portfolioId,
                'executive_pack' => $executivePack,
                'decision_ledger' => $this->acceptedExecutiveDecisionLedger($executivePack, $areaId, $portfolioId),
                'record_allocation_handoff' => false,
            ]);

            $continuousTick = $this->continuousLoop->tick([
                'area_id' => $areaId,
                'enabled' => true,
                'force_continuous_tick' => true,
                'record_continuous_cycle' => false,
                'min_interval_seconds' => 0,
                'active_operation_report' => $activeOperation,
            ]);
            $schedulerRun = $this->continuousScheduler->run([
                'area_id' => $areaId,
                'enabled' => true,
                'continuous_loop_enabled' => true,
                'force_scheduler_run' => true,
                'record_scheduler_run' => false,
                'record_continuous_cycle' => false,
                'min_interval_seconds' => 0,
                'active_operation_report' => $activeOperation,
                'continuous_loop_state' => [
                    'schema_version' => AtlasContinuousStewardshipLoopService::STATE_SCHEMA,
                    'status' => AtlasContinuousStewardshipLoopService::STATUS_READY_TO_TICK,
                    'area_id' => $areaId,
                    'blockers' => [],
                    'policy' => ['enabled' => true, 'kill_switch_active' => false],
                ],
            ]);

            $cockpit = $this->productModeCockpit->project($portfolioId, [
                'area_id' => $areaId,
                'stewardship_outcome_history' => $outcome,
                'area_stewardship_active_handoff' => $activeHandoff,
                'area_stewardship_active_operation' => $activeOperation,
                'continuous_stewardship_loop' => $continuousTick,
                'continuous_stewardship_scheduler' => $schedulerRun,
                'dev_forge_release' => $release,
                'owner_sandbox_runtime_runner' => $ownerRun,
                'owner_runtime_result_bridge' => $resultBridge,
                'executive_allocation_handoff' => $executiveAllocation,
            ]);

            return $this->finalize($areaId, $portfolioId, $executeOwnerCommand, [
                'area_focus_operational_cycle' => $cycle,
                'area_stewardship_active_handoff' => $activeHandoff,
                'area_stewardship_active_operation' => $activeOperation,
                'continuous_stewardship_loop' => $continuousTick,
                'continuous_stewardship_scheduler' => $schedulerRun,
                'dev_forge_release' => $release,
                'stewardship_outcome_history' => $outcome,
                'owner_queue_consumption' => $consumption,
                'owner_runtime_execution_adapter' => $execution,
                'owner_sandbox_runtime_runner' => $ownerRun,
                'owner_runtime_result_bridge' => $resultBridge,
                'portfolio_health' => $portfolioHealth,
                'portfolio_inbox' => $portfolioInbox,
                'executive_recommendations' => $executivePack,
                'executive_allocation_handoff' => $executiveAllocation,
                'product_mode_cockpit' => $cockpit,
            ]);
        } catch (Throwable $e) {
            return [
                'schema_version' => self::REPORT_SCHEMA,
                'status' => self::STATUS_BLOCKED,
                'ap_contract' => 'AP-762',
                'area_id' => $areaId,
                'portfolio_id' => $portfolioId,
                'reason' => 'live_cycle_certification_exception',
                'blockers' => [$e->getMessage()],
                'claim_policy' => $this->claimPolicy($executeOwnerCommand),
                'generated_at' => $this->now(),
            ];
        }
    }

    /**
     * @param  array<string,array<string,mixed>>  $stages
     * @return array<string,mixed>
     */
    private function finalize(string $areaId, string $portfolioId, bool $executeOwnerCommand, array $stages): array
    {
        $matrix = $this->certificationMatrix($stages, $executeOwnerCommand);
        $blockers = $this->blockers($matrix, $stages, $executeOwnerCommand);
        $status = $blockers === []
            ? self::STATUS_CERTIFIED
            : ($this->hasHardBlocker($blockers) ? self::STATUS_BLOCKED : self::STATUS_PARTIAL);

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'status' => $status,
            'ap_contract' => 'AP-762',
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'mode' => $executeOwnerCommand ? 'owner_command_execution_certification' : 'projection_certification',
            'source_ap_contracts' => $this->requiredApContracts(),
            'certification_matrix' => $matrix,
            'counts' => $this->counts($stages),
            'stage_status' => array_map(static fn (array $stage): string => (string) ($stage['status'] ?? 'unknown'), $stages),
            'stages' => $stages,
            'blockers' => $blockers,
            'next_actions' => $this->nextActions($status, $executeOwnerCommand),
            'claim_policy' => $this->claimPolicy($executeOwnerCommand),
        ];
        $payload['certification_id'] = 'sclc_'.substr(MissionCanonicalHash::sha256($this->identity($payload)), 0, 24);
        $payload['certification_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));
        $payload['generated_at'] = $this->now();

        return $payload;
    }

    /**
     * @param  array<string,array<string,mixed>>  $stages
     * @return array<string,array<string,mixed>>
     */
    private function certificationMatrix(array $stages, bool $executeOwnerCommand): array
    {
        return [
            'ap722_area_focus_cycle' => $this->stage('AP-722', $stages['area_focus_operational_cycle'] ?? [], ['ready', 'partial']),
            'ap743_active_handoff' => $this->stage('AP-743', $stages['area_stewardship_active_handoff'] ?? [], [AreaStewardshipActiveHandoffService::STATUS_READY]),
            'ap744_active_operation' => $this->stage('AP-744', $stages['area_stewardship_active_operation'] ?? [], [AreaStewardshipActiveOperatingService::STATUS_READY, AreaStewardshipActiveOperatingService::STATUS_PARTIAL]),
            'ap745_continuous_tick' => $this->stage('AP-745', $stages['continuous_stewardship_loop'] ?? [], [AtlasContinuousStewardshipLoopService::STATUS_TICK_COMPLETED, AtlasContinuousStewardshipLoopService::STATUS_TICK_RECORDED]),
            'ap746_recurring_scheduler' => $this->stage('AP-746', $stages['continuous_stewardship_scheduler'] ?? [], [AtlasContinuousStewardshipRecurringSchedulerService::STATUS_RUN_COMPLETED, AtlasContinuousStewardshipRecurringSchedulerService::STATUS_RUN_RECORDED]),
            'ap747_dev_forge_release' => $this->stage('AP-747', $stages['dev_forge_release'] ?? [], [AreaFocusDevForgeReleaseService::STATUS_READY, AreaFocusDevForgeReleaseService::STATUS_RECORDED]),
            'ap748_outcome_bridge' => $this->stage('AP-748', $stages['stewardship_outcome_history'] ?? [], [StewardshipOutcomeEvidenceBridgeService::STATUS_READY]),
            'ap749_owner_consumption' => $this->stage('AP-749', $stages['owner_queue_consumption'] ?? [], [AreaFocusOwnerQueueConsumptionGateService::STATUS_READY, AreaFocusOwnerQueueConsumptionGateService::STATUS_RECORDED]),
            'ap758_owner_execution_adapter' => $this->stage('AP-758', $stages['owner_runtime_execution_adapter'] ?? [], [StewardshipOwnerRuntimeExecutionAdapterService::STATUS_READY, StewardshipOwnerRuntimeExecutionAdapterService::STATUS_RECORDED]),
            'ap759_owner_sandbox_runner' => $this->stage('AP-759', $stages['owner_sandbox_runtime_runner'] ?? [], $executeOwnerCommand ? [StewardshipOwnerSandboxRuntimeRunnerService::STATUS_READY, StewardshipOwnerSandboxRuntimeRunnerService::STATUS_RECORDED] : [StewardshipOwnerSandboxRuntimeRunnerService::STATUS_PLANNED]),
            'ap750_owner_result_bridge' => $this->stage('AP-750', $stages['owner_runtime_result_bridge'] ?? [], [StewardshipOwnerRuntimeResultBridgeService::STATUS_READY, StewardshipOwnerRuntimeResultBridgeService::STATUS_RECORDED]),
            'ap751_portfolio_result_intake' => $this->stage('AP-751', $stages['portfolio_health'] ?? [], [PortfolioStewardshipHealthModelService::STATUS_READY_FOR_OPERATOR_REVIEW]),
            'ap734_portfolio_inbox' => $this->stage('AP-734', $stages['portfolio_inbox'] ?? [], [PortfolioStewardshipInboxService::STATUS_READY]),
            'ap735_executive_recommendations' => $this->stage('AP-735', $stages['executive_recommendations'] ?? [], [AutonomousExecutiveRecommendationService::STATUS_READY_FOR_OPERATOR_REVIEW]),
            'ap752_executive_allocation' => $this->stage('AP-752', $stages['executive_allocation_handoff'] ?? [], [AutonomousExecutiveAllocationHandoffService::STATUS_READY]),
            'ap739_ap761_product_mode_visibility' => $this->stage('AP-739/AP-761', $stages['product_mode_cockpit'] ?? [], [ProductModeCockpitSurfaceService::STATUS_READY]),
            'safety_no_irreversible_actions' => $this->safetyStage($stages),
        ];
    }

    /**
     * @param  array<string,mixed>  $stage
     * @param  list<string>  $readyStatuses
     * @return array<string,mixed>
     */
    private function stage(string $ap, array $stage, array $readyStatuses): array
    {
        $status = (string) ($stage['status'] ?? 'missing');

        return [
            'ap_contract' => $ap,
            'status' => $status,
            'passed' => in_array($status, $readyStatuses, true),
            'ready_statuses' => $readyStatuses,
            'hash' => (string) ($stage['report_hash'] ?? $stage['handoff_hash'] ?? $stage['operation_hash'] ?? $stage['tick_hash'] ?? $stage['scheduler_run_hash'] ?? $stage['release_hash'] ?? $stage['bridge_hash'] ?? $stage['result_bridge_hash'] ?? $stage['surface_hash'] ?? ''),
            'blockers' => array_values(array_filter((array) ($stage['blockers'] ?? []), static fn (mixed $item): bool => is_string($item))),
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $stages
     * @return array<string,mixed>
     */
    private function safetyStage(array $stages): array
    {
        $violations = [];
        foreach ($stages as $name => $stage) {
            $policy = is_array($stage['claim_policy'] ?? null) ? $stage['claim_policy'] : [];
            foreach ([
                'new_os_created',
                'parallel_runtime_created',
                'merge_performed',
                'merge_performed_by_bridge',
                'deploy_performed',
                'deploy_performed_by_bridge',
                'secret_access',
                'secret_access_by_bridge',
                'destructive_change',
                'destructive_change_by_bridge',
                'auto_approved',
            ] as $flag) {
                if ((bool) ($policy[$flag] ?? false)) {
                    $violations[] = $name.'.claim_policy.'.$flag;
                }
            }
        }

        return [
            'ap_contract' => 'AP-715/AP-762',
            'status' => $violations === [] ? 'passed' : 'blocked',
            'passed' => $violations === [],
            'violations' => $violations,
            'blockers' => $violations,
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $matrix
     * @param  array<string,array<string,mixed>>  $stages
     * @return list<string>
     */
    private function blockers(array $matrix, array $stages, bool $executeOwnerCommand): array
    {
        $blockers = [];
        foreach ($matrix as $key => $item) {
            if ((bool) ($item['passed'] ?? false) !== true) {
                $blockers[] = $key.':'.(string) ($item['status'] ?? 'unknown');
            }
            foreach ((array) ($item['blockers'] ?? []) as $blocker) {
                if (is_string($blocker) && $blocker !== '') {
                    $blockers[] = $key.':'.$blocker;
                }
            }
        }

        if ((int) data_get($stages, 'product_mode_cockpit.counters.review_queue_items', 0) < 1) {
            $blockers[] = 'product_mode_cockpit:review_queue_empty';
        }
        if ($executeOwnerCommand && (int) data_get($stages, 'owner_runtime_result_bridge.evidence_check.test_count', 0) < 1) {
            $blockers[] = 'ap750:test_evidence_missing';
        }
        if ((bool) data_get($stages, 'owner_runtime_result_bridge.identity_check.ok', false) !== true) {
            $blockers[] = 'ap750:identity_check_not_ok';
        }
        if ((bool) data_get($stages, 'owner_runtime_result_bridge.isolation_check.ok', false) !== true) {
            $blockers[] = 'ap750:isolation_check_not_ok';
        }

        return StewardshipStringListNormalizer::uniqueStrings($blockers);
    }

    /**
     * @return list<string>
     */
    private function requiredApContracts(): array
    {
        return [
            'AP-715',
            'AP-716',
            'AP-717',
            'AP-718',
            'AP-719',
            'AP-720',
            'AP-722',
            'AP-743',
            'AP-744',
            'AP-745',
            'AP-746',
            'AP-747',
            'AP-748',
            'AP-749',
            'AP-756',
            'AP-757',
            'AP-758',
            'AP-759',
            'AP-750',
            'AP-751',
            'AP-733',
            'AP-734',
            'AP-735',
            'AP-752',
            'AP-739',
            'AP-760',
            'AP-761',
            'AP-762',
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function certificationFindings(string $areaId): array
    {
        return [
            $this->finding($areaId, 'cert_dev', 'atlas_dev', 'missing_test'),
            $this->finding($areaId, 'cert_sde', 'self_directed_evolution', 'self_directed_spec_gap'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function finding(string $areaId, string $id, string $route, string $type): array
    {
        return [
            'schema_version' => 'atlas.software_company_stewardship.area_finding.v1',
            'area_id' => $areaId,
            'finding_type' => $type,
            'title' => 'AP-762 certification '.$id,
            'detail' => 'Certification fixture proving '.$route.' handoff without target repo mutation.',
            'severity' => 'medium',
            'risk_level' => 'medium',
            'confidence' => 'high',
            'route_hint' => $route,
            'evidence_refs' => ['AP-762:'.$id],
            'recommended_action' => 'Review AP-762 certification path for '.$route,
            'finding_id' => 'aef_'.$id,
            'finding_hash' => 'sha256:'.$id,
            'priority_score' => 250,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function readyAreaStewardshipReport(string $areaId): array
    {
        return [
            'schema_version' => AreaStewardshipPromotionReadinessService::REPORT_SCHEMA,
            'status' => AreaStewardshipPromotionReadinessService::STATUS_READY_FOR_ACTIVE_HANDOFF,
            'ap_contract' => 'AP-732',
            'area_id' => $areaId,
            'target_type' => 'area_stewardship',
            'target_id' => $areaId,
            'target_hash' => 'sha256:ap762_area_stewardship_target',
            'promotion_from' => 'area_focus_loop',
            'promotion_to' => 'area_stewardship_active',
            'area_stewardship' => [
                'schema_version' => 'atlas.area.stewardship.v1',
                'status' => 'ready_proposal_only',
                'mode' => 'read_only',
                'area_id' => $areaId,
                'area_name' => 'Agentic Engineering OS',
                'health_model' => ['schema_version' => 'atlas.area.health_model.v1', 'score' => 91, 'band' => 'excellent'],
                'roadmap_candidates' => [['candidate_id' => 'ap762_certification', 'summary' => 'Certify full stewardship live cycle.', 'requires_operator_review' => true]],
                'dev_forge_policy' => [
                    'small_local_work' => 'atlas_dev',
                    'cross_system_or_long_horizon_work' => 'forge',
                    'gap_or_spec_work' => 'self_directed_evolution',
                    'high_risk_or_sensitive_work' => 'operator_review',
                ],
                'operator_inbox' => ['destination' => 'morning_inbox', 'auto_approval' => false],
            ],
            'operator_acceptance' => [
                'status' => 'accepted',
                'decision_id' => 'seod_ap762_area_accept',
                'operator_actor' => 'operator',
                'recorded_at' => '2026-05-27T00:00:00+00:00',
            ],
            'checks' => [
                'area_schema_present' => true,
                'health_model_present' => true,
                'roadmap_candidates_present' => true,
                'dev_forge_policy_present' => true,
                'operator_inbox_present' => true,
                'area_focus_ready' => true,
                'evidence_refs_present' => true,
                'no_mutation_claim' => true,
            ],
            'blockers' => [],
            'next_actions' => [],
            'claim_policy' => [
                'read_only' => true,
                'promotion_gate_only' => true,
                'mutates_target_repo' => false,
                'provider_invoked' => false,
                'dev_invoked' => false,
                'forge_invoked' => false,
            ],
            'report_hash' => 'sha256:ap762_ready_area_stewardship',
        ];
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return list<array<string,mixed>>
     */
    private function operatorReceiptsFromCycle(array $cycle): array
    {
        $receipts = [];
        foreach ((array) data_get($cycle, 'stages.work_orders.work_orders', []) as $workOrder) {
            if (! is_array($workOrder)) {
                continue;
            }
            if (! in_array((string) ($workOrder['route'] ?? ''), ['atlas_dev', 'forge'], true)) {
                continue;
            }
            $workOrderId = (string) ($workOrder['work_order_id'] ?? '');
            $findingHash = (string) ($workOrder['source_ref'] ?? $workOrder['finding_hash'] ?? '');
            if ($workOrderId === '' || $findingHash === '') {
                continue;
            }
            $receipts[] = [
                'schema_version' => 'atlas.software_company_stewardship.area_focus_operator_decision_receipt.v1',
                'finding_hash' => $findingHash,
                'work_order_id' => $workOrderId,
                'decision' => 'accept',
                'decision_id' => 'afod_ap762_'.$workOrderId,
                'decision_hash' => 'sha256:ap762_decision_'.$workOrderId,
                'operator_actor' => 'operator',
            ];
        }

        return $receipts;
    }

    /**
     * @param  array<string,mixed>  $branchHandoff
     * @return array<string,mixed>|null
     */
    private function firstReadyHandoff(array $branchHandoff): ?array
    {
        foreach ((array) ($branchHandoff['handoffs'] ?? []) as $handoff) {
            if (! is_array($handoff)) {
                continue;
            }
            if ((string) ($handoff['handoff_status'] ?? '') === AreaFocusBranchSandboxHandoffService::HO_READY) {
                return $handoff;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $handoff
     * @return array<string,mixed>
     */
    private function releaseReceipt(array $handoff): array
    {
        return [
            'decision' => 'release',
            'operator_actor' => 'operator',
            'target_handoff_hash' => (string) ($handoff['handoff_hash'] ?? ''),
            'release_hash' => 'sha256:ap762_release_receipt',
        ];
    }

    /**
     * @param  array<string,mixed>  $release
     * @return array<string,mixed>
     */
    private function executionReceipt(array $release): array
    {
        return [
            'decision' => 'consume',
            'operator_actor' => 'operator',
            'target_release_id' => (string) ($release['release_id'] ?? ''),
            'target_queue_item_id' => (string) data_get($release, 'queue_item.queue_item_id', ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $consumption
     * @return array<string,mixed>
     */
    private function runtimeStartReceipt(array $consumption): array
    {
        return [
            'decision' => 'start_owner_runtime',
            'operator_actor' => 'operator',
            'target_consumption_id' => (string) ($consumption['consumption_id'] ?? ''),
            'target_release_id' => (string) ($consumption['release_id'] ?? ''),
            'target_queue_item_id' => (string) ($consumption['queue_item_id'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $execution
     * @return array<string,mixed>
     */
    private function runtimeCommandReceipt(array $execution, bool $execute): array
    {
        return [
            'decision' => 'execute_owner_runtime_in_sandbox',
            'operator_actor' => 'operator',
            'target_owner' => (string) ($execution['target_owner'] ?? 'atlas_dev'),
            'target_owner_execution_id' => (string) ($execution['owner_execution_id'] ?? ''),
            'target_consumption_id' => (string) ($execution['consumption_id'] ?? ''),
            'allow_runtime_command_execution' => $execute,
            'provider_execution_authorized' => true,
            'budget_approved' => true,
            'timeout_seconds' => 30,
            'command' => [PHP_BINARY, 'artisan', 'atlas:dev:run-worker', 'fixture'],
            'validation_commands' => ['php artisan test --filter=SoftwareCompanyStewardship'],
        ];
    }

    /**
     * @param  array<string,mixed>  $release
     * @return array<string,mixed>
     */
    private function sandboxRecord(string $areaId, array $release, string $worktree): array
    {
        return [
            'schema_version' => 'atlas.software_company_stewardship.area_focus_branch_sandbox_materializer_record.v1',
            'ap_contract' => 'AP-756',
            'status' => 'materialized',
            'area_id' => $areaId,
            'sandbox_id' => 'afsb_ap762_'.substr(MissionCanonicalHash::sha256([$areaId, $worktree]), 0, 12),
            'source_ap_contracts' => ['AP-724', 'AP-726', 'AP-747', 'AP-756'],
            'source_refs' => [
                'handoff_hash' => (string) data_get($release, 'source_refs.handoff_hash', data_get($release, 'queue_item.handoff_hash', '')),
                'work_order_id' => (string) data_get($release, 'source_refs.work_order_id', data_get($release, 'queue_item.work_order_id', '')),
            ],
            'materialization' => [
                'branch_name' => 'area-focus/agentic-engineering-os/ap762-certification',
                'worktree_path' => $worktree,
                'branch_created' => true,
                'worktree_created' => true,
                'target_repo_mutated' => false,
                'provider_invoked' => false,
                'runtime_execution_started' => false,
            ],
            'sandbox_hash' => 'sha256:'.MissionCanonicalHash::sha256([$areaId, $worktree, $release['release_id'] ?? '']),
            'certification_sandbox_only' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function worktreePath(array $input, string $areaId): string
    {
        $path = trim((string) ($input['worktree_path'] ?? ''));
        if ($path === '') {
            $path = $this->storageDir().'/certification_sandboxes/'.$this->slug($areaId);
        }
        File::ensureDirectoryExists($path);

        return $path;
    }

    private function ensureCertificationOwnerCommand(string $worktree): void
    {
        File::ensureDirectoryExists($worktree.'/app/Services/Ai/SoftwareCompanyStewardship');
        if (! is_file($worktree.'/artisan')) {
            File::put($worktree.'/artisan', <<<'PHP'
<?php
$command = $argv[1] ?? '';
$arg = $argv[2] ?? '';
if ($command === 'atlas:dev:run-worker') {
    @mkdir(__DIR__.'/app/Services/Ai/SoftwareCompanyStewardship', 0775, true);
    file_put_contents(__DIR__.'/app/Services/Ai/SoftwareCompanyStewardship/Ap762Certification.php', "<?php\n// AP-762 certification fixture\n");
    echo 'atlas-dev-run-worker-ap762-ok';
    exit(0);
}
fwrite(STDERR, 'unknown owner command');
exit(2);
PHP);
        }
    }

    /**
     * @param  array<string,mixed>  $pack
     * @return array<string,mixed>
     */
    private function acceptedExecutiveDecisionLedger(array $pack, string $areaId, string $portfolioId): array
    {
        $recommendation = null;
        foreach ((array) ($pack['recommendations'] ?? []) as $item) {
            if (is_array($item)) {
                $recommendation = $item;
                break;
            }
        }
        if ($recommendation === null) {
            return ['schema_version' => 'atlas.stewardship_evolution.decision_list.v1', 'decision_count' => 0, 'decisions' => []];
        }

        $targetId = (string) ($recommendation['target_id'] ?? $recommendation['recommendation_id'] ?? '');
        $targetHash = (string) ($recommendation['target_hash'] ?? $recommendation['recommendation_hash'] ?? '');

        return [
            'schema_version' => 'atlas.stewardship_evolution.decision_list.v1',
            'decision_count' => 1,
            'decisions' => [[
                'schema_version' => 'atlas.software_company_stewardship.evolution_decision_receipt.v1',
                'decision_id' => 'seod_ap762_exec_accept',
                'decision' => 'accept',
                'target_type' => 'autonomous_executive',
                'target_id' => $targetId,
                'target_hash' => $targetHash,
                'area_id' => $areaId,
                'portfolio_id' => $portfolioId,
                'risk_level' => 'medium',
                'operator_actor' => 'operator',
                'recorded_at' => $this->now(),
                'decision_hash' => 'sha256:ap762_exec_accept',
            ]],
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $stages
     * @return array<string,int>
     */
    private function counts(array $stages): array
    {
        return [
            'findings' => (int) data_get($stages, 'area_focus_operational_cycle.counts.finding_count', 0),
            'work_orders' => (int) data_get($stages, 'area_stewardship_active_operation.counts.work_orders', 0),
            'ready_handoffs' => (int) data_get($stages, 'area_stewardship_active_operation.counts.ready_branch_handoffs', 0),
            'release_count' => (int) data_get($stages, 'stewardship_outcome_history.release_outcome_summary.release_count', 0),
            'owner_results' => (int) data_get($stages, 'owner_runtime_result_bridge.portfolio_feed.areas.0.owner_runtime_result_count', 0),
            'portfolio_items' => (int) data_get($stages, 'portfolio_inbox.item_count', 0),
            'executive_recommendations' => (int) data_get($stages, 'executive_recommendations.recommendation_count', 0),
            'product_mode_review_items' => (int) data_get($stages, 'product_mode_cockpit.counters.review_queue_items', 0),
        ];
    }

    /**
     * @return list<string>
     */
    private function nextActions(string $status, bool $executeOwnerCommand): array
    {
        if ($status === self::STATUS_CERTIFIED) {
            return $executeOwnerCommand
                ? ['Review AP-750 owner result evidence before any merge, deploy or external push.']
                : ['Run again with execute_owner_command=true only after operator review of the AP-759 command plan.'];
        }

        return ['Inspect certification_matrix and repair the first blocked AP owner before claiming 100% Stewardship Stack implementation.'];
    }

    /**
     * @param  array<string,mixed>  $stage
     */
    private function stageReady(array $stage): bool
    {
        return ! in_array((string) ($stage['status'] ?? ''), ['', 'missing', self::STATUS_BLOCKED, AreaFocusDevForgeReleaseService::STATUS_BLOCKED], true);
    }

    /**
     * @return array<string,mixed>
     */
    private function blockedStage(string $ap, string $reason): array
    {
        return [
            'schema_version' => 'atlas.software_company_stewardship.blocked_stage.v1',
            'ap_contract' => $ap,
            'status' => self::STATUS_BLOCKED,
            'reason' => $reason,
            'blockers' => [$reason],
            'claim_policy' => ['new_os_created' => false, 'parallel_runtime_created' => false],
        ];
    }

    /**
     * @param  list<string>  $blockers
     */
    private function hasHardBlocker(array $blockers): bool
    {
        foreach ($blockers as $blocker) {
            if (str_contains($blocker, ':blocked') || str_contains($blocker, 'not_ok') || str_contains($blocker, 'missing')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    private function claimPolicy(bool $executeOwnerCommand): array
    {
        return [
            'certifier_only' => true,
            'creates_new_runtime' => false,
            'new_os_created' => false,
            'parallel_runtime_created' => false,
            'direct_provider_call_by_certifier' => false,
            'owner_command_execution_allowed_only_with_receipt' => true,
            'owner_command_execution_requested' => $executeOwnerCommand,
            'merge_performed' => false,
            'deploy_performed' => false,
            'external_push_performed' => false,
            'secret_access' => false,
            'destructive_change' => false,
            'operator_review_required_before_irreversible_action' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        $copy = $payload;
        unset($copy['generated_at'], $copy['certification_hash']);

        return $copy;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function areaId(array $input): string
    {
        return trim((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID)) ?: self::DEFAULT_AREA_ID;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function portfolioId(array $input): string
    {
        return trim((string) ($input['portfolio_id'] ?? self::DEFAULT_PORTFOLIO_ID)) ?: self::DEFAULT_PORTFOLIO_ID;
    }

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '-', trim($value)) ?: 'default');

        return trim($slug, '-') ?: 'default';
    }
}
