<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\ProductMode;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompany\AreaFocusProductModeSurfaceService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionReadModelService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipActiveHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipActiveOperatingService;
use App\Services\Ai\SoftwareCompanyStewardship\AutonomousExecutive\AutonomousExecutiveAllocationHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\AutonomousExecutive\ExecutiveDecisionInboxSurfaceService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\AtlasContinuousStewardshipLoopService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\AtlasContinuousStewardshipRecurringSchedulerService;
use App\Services\Ai\SoftwareCompanyStewardship\PortfolioStewardship\PortfolioStewardshipHealthModelService;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\Support\ProductModeCockpitProjectionSupport;
use App\Services\Ai\SoftwareCompanyStewardship\SelfExpanding\NewAreaProposalGateService;
use App\Services\Ai\SoftwareCompanyStewardship\SelfExpanding\SelfExpandingDomainRuntimeCreationHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\SelfExpanding\SelfExpandingSoftwareCompanyService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOutcomeEvidenceBridgeService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Product Mode cockpit for the Atlas Software Company Stewardship Stack (AP-739).
 *
 * This service is a read-only visual aggregator. It composes existing owners:
 * AP-721 Area Focus Product Mode, AP-736 Executive Decision Inbox, AP-737 New
 * Area Proposal Gate, AP-738 Self-Expanding Software Company v0, AP-740
 * outcome history, AP-741 Domain Runtime Creation Gate handoffs, AP-743
 * Area Stewardship active handoff packets, AP-744 active operation projections,
 * AP-745/AP-746 Continuous Stewardship Loop scheduler state, AP-747
 * Dev/Forge release review state, AP-759 owner sandbox runtime runner state,
 * AP-750 owner runtime results and AP-752 executive allocation handoff packets.
 * It does not
 * record decisions, create branches, invoke providers, dispatch Dev/Forge,
 * install schedulers, create domains or promote runtime state.
 */
final class ProductModeCockpitSurfaceService
{
    public const SURFACE_SCHEMA = 'atlas.software_company.product_mode_cockpit.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    public function __construct(
        private readonly AreaFocusProductModeSurfaceService $areaFocus,
        private readonly ExecutiveDecisionInboxSurfaceService $executiveInbox,
        private readonly AutonomousExecutiveAllocationHandoffService $executiveAllocationHandoff,
        private readonly NewAreaProposalGateService $newAreaGate,
        private readonly SelfExpandingSoftwareCompanyService $selfExpanding,
        private readonly StewardshipOutcomeEvidenceBridgeService $outcomeHistory,
        private readonly SelfExpandingDomainRuntimeCreationHandoffService $domainRuntimeCreationHandoff,
        private readonly AreaStewardshipActiveHandoffService $areaStewardshipActiveHandoff,
        private readonly AreaStewardshipActiveOperatingService $areaStewardshipActiveOperating,
        private readonly AtlasContinuousStewardshipLoopService $continuousStewardshipLoop,
        private readonly AtlasContinuousStewardshipRecurringSchedulerService $continuousStewardshipScheduler,
        private readonly ProductModeOperationalControlsReadModelService $operationalControls,
        private readonly AutonomousEvolutionSessionReadModelService $loop24hObservability,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(string $portfolioId = PortfolioStewardshipHealthModelService::DEFAULT_PORTFOLIO_ID, array $input = []): array
    {
        $areaId = ProductModeCockpitProjectionSupport::areaId($input);
        $portfolioId = ProductModeCockpitProjectionSupport::portfolioId($portfolioId, $input);

        $areaFocus = $this->areaFocus->project($areaId, $input);
        if (($areaFocus['status'] ?? null) === self::STATUS_BLOCKED) {
            return $this->finalize([
                'schema_version' => self::SURFACE_SCHEMA,
                'status' => self::STATUS_BLOCKED,
                'reason' => 'area_focus_product_mode_blocked',
                'ap_contract' => 'AP-739',
                'area_id' => $areaId,
                'portfolio_id' => $portfolioId,
                'read_only' => true,
                'blockers' => [
                    (string) ($areaFocus['reason'] ?? 'area_focus_product_mode_not_ready'),
                ],
                'area_focus' => $areaFocus,
                'claim_policy' => ProductModeCockpitProjectionSupport::claimPolicy(),
            ]);
        }

        $executive = is_array($input['executive_decision_inbox'] ?? null)
            ? $input['executive_decision_inbox']
            : $this->executiveInbox->project($portfolioId, $input + ['area_id' => $areaId]);
        $newAreaGate = $this->newAreaGate->evaluate($input + ['area_id' => $areaId, 'portfolio_id' => $portfolioId]);
        $selfExpanding = $this->selfExpanding->project($input + [
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'proposal_gate' => $newAreaGate,
        ]);
        $outcomeHistory = is_array($input['stewardship_outcome_history'] ?? null)
            ? $input['stewardship_outcome_history']
            : $this->outcomeHistory->project($input + [
                'area_id' => $areaId,
                'portfolio_id' => $portfolioId,
                'self_expanding' => $selfExpanding,
                'record_evidence' => false,
                'emit_inbox' => false,
            ]);
        $domainRuntimeCreationHandoff = is_array($input['domain_runtime_creation_handoff'] ?? null)
            ? $input['domain_runtime_creation_handoff']
            : $this->domainRuntimeCreationHandoff->project($input + [
                'area_id' => $areaId,
                'portfolio_id' => $portfolioId,
                'proposal_gate' => $newAreaGate,
                'self_expanding' => $selfExpanding,
                'outcome_bridge' => $outcomeHistory,
                'record_handoff' => false,
                'require_recorded_evidence' => true,
            ]);
        $areaStewardshipActiveHandoff = is_array($input['area_stewardship_active_handoff'] ?? null)
            ? $input['area_stewardship_active_handoff']
            : $this->areaStewardshipActiveHandoff->project($input + [
                'area_id' => $areaId,
                'record_active_handoff' => false,
            ]);
        $areaStewardshipActiveOperation = is_array($input['area_stewardship_active_operation'] ?? null)
            ? $input['area_stewardship_active_operation']
            : $this->areaStewardshipActiveOperating->operate($input + [
                'area_id' => $areaId,
                'active_handoff_report' => $areaStewardshipActiveHandoff,
                'record_active_operation' => false,
            ]);
        $continuousLoop = is_array($input['continuous_stewardship_loop'] ?? null)
            ? $input['continuous_stewardship_loop']
            : $this->continuousStewardshipLoop->project($input + [
                'area_id' => $areaId,
                'enabled' => false,
            ]);
        $continuousScheduler = is_array($input['continuous_stewardship_scheduler'] ?? null)
            ? $input['continuous_stewardship_scheduler']
            : $this->continuousStewardshipScheduler->project(array_merge($input, [
                'area_id' => $areaId,
                'enabled' => false,
                'continuous_loop_enabled' => false,
                'continuous_loop_state' => $continuousLoop,
            ]));
        $devForgeRelease = is_array($input['dev_forge_release'] ?? null)
            ? $input['dev_forge_release']
            : ProductModeCockpitProjectionSupport::defaultDevForgeRelease($areaId);
        $ownerSandboxRuntime = is_array($input['owner_sandbox_runtime_runner'] ?? null)
            ? $input['owner_sandbox_runtime_runner']
            : ProductModeCockpitProjectionSupport::defaultOwnerSandboxRuntimeRunner($areaId);
        $ownerRuntimeResult = is_array($input['owner_runtime_result_bridge'] ?? null)
            ? $input['owner_runtime_result_bridge']
            : ProductModeCockpitProjectionSupport::defaultOwnerRuntimeResultBridge($areaId);
        $executiveAllocationHandoff = is_array($input['executive_allocation_handoff'] ?? null)
            ? $input['executive_allocation_handoff']
            : $this->executiveAllocationHandoff->project($input + [
                'area_id' => $areaId,
                'portfolio_id' => $portfolioId,
                'record_allocation_handoff' => false,
            ]);
        $productModeOperationalControls = is_array($input['product_mode_operational_controls'] ?? null)
            ? $input['product_mode_operational_controls']
            : $this->operationalControls->project($areaId, $portfolioId, $input);
        $loop24h = is_array($input['loop_24h_observability'] ?? null)
            ? $input['loop_24h_observability']
            : $this->loop24hObservability->project24hObservability([
                'area_id' => $areaId,
                'repo_root' => (string) ($input['repo_root'] ?? ''),
            ]);

        $reviewQueue = ProductModeCockpitProjectionSupport::reviewQueue($executive, $newAreaGate, $selfExpanding, $outcomeHistory, $domainRuntimeCreationHandoff, $areaStewardshipActiveHandoff, $areaStewardshipActiveOperation, $continuousLoop, $continuousScheduler, $devForgeRelease, $ownerSandboxRuntime, $ownerRuntimeResult, $executiveAllocationHandoff, $productModeOperationalControls);
        $counters = ProductModeCockpitProjectionSupport::counters($areaFocus, $executive, $newAreaGate, $selfExpanding, $outcomeHistory, $domainRuntimeCreationHandoff, $areaStewardshipActiveHandoff, $areaStewardshipActiveOperation, $continuousLoop, $continuousScheduler, $devForgeRelease, $ownerSandboxRuntime, $ownerRuntimeResult, $executiveAllocationHandoff, $productModeOperationalControls, $reviewQueue);

        return $this->finalize([
            'schema_version' => self::SURFACE_SCHEMA,
            'status' => self::STATUS_READY,
            'ap_contract' => 'AP-739',
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'read_only' => true,
            'stack' => [
                'name' => 'Atlas Software Company Stewardship Stack',
                'parent_runtime' => 'Atlas Autonomous Software Company Runtime',
                'current_visual_level' => 'Night Shift Product Mode',
                'continuous_motor' => 'Atlas Continuous Stewardship Loop',
                'area_focus' => 'Area Focus Loop',
                'ceiling' => 'Self-Expanding Software Company',
                'not_a_new_os' => true,
            ],
            'source_ap_contracts' => ['AP-721', 'AP-736', 'AP-737', 'AP-738', 'AP-740', 'AP-741', 'AP-742', 'AP-743', 'AP-744', 'AP-745', 'AP-746', 'AP-747', 'AP-748', 'AP-749', 'AP-759', 'AP-750', 'AP-752', 'AP-754'],
            'counters' => $counters,
            'health' => [
                'overall' => ProductModeCockpitProjectionSupport::overallHealth($counters, $executive, $newAreaGate, $selfExpanding, $outcomeHistory, $domainRuntimeCreationHandoff, $areaStewardshipActiveHandoff, $areaStewardshipActiveOperation, $continuousLoop, $continuousScheduler, $devForgeRelease, $ownerSandboxRuntime, $ownerRuntimeResult, $executiveAllocationHandoff, $productModeOperationalControls),
                'area_focus' => (string) data_get($areaFocus, 'health.overall', 'unknown'),
                'executive_review_pending' => (int) data_get($executive, 'decision_summary.pending_operator_review', 0),
                'new_area_blocked' => (int) data_get($newAreaGate, 'decision_summary.blocked_awaiting_operator_review', 0),
                'self_expanding_status' => (string) ($selfExpanding['status'] ?? 'unknown'),
                'outcome_history_status' => (string) ($outcomeHistory['status'] ?? 'unknown'),
                'domain_runtime_creation_handoff_status' => (string) ($domainRuntimeCreationHandoff['status'] ?? 'unknown'),
                'area_stewardship_active_handoff_status' => (string) ($areaStewardshipActiveHandoff['status'] ?? 'unknown'),
                'area_stewardship_active_operation_status' => (string) ($areaStewardshipActiveOperation['status'] ?? 'unknown'),
                'continuous_stewardship_loop_status' => (string) ($continuousLoop['status'] ?? 'unknown'),
                'continuous_stewardship_scheduler_status' => (string) ($continuousScheduler['status'] ?? 'unknown'),
                'dev_forge_release_status' => (string) ($devForgeRelease['status'] ?? 'unknown'),
                'owner_sandbox_runtime_runner_status' => (string) ($ownerSandboxRuntime['status'] ?? 'unknown'),
                'owner_runtime_result_bridge_status' => (string) ($ownerRuntimeResult['status'] ?? 'unknown'),
                'executive_allocation_handoff_status' => (string) ($executiveAllocationHandoff['status'] ?? 'unknown'),
                'product_mode_operational_controls_status' => (string) ($productModeOperationalControls['status'] ?? 'unknown'),
            ],
            'area_focus' => ProductModeCockpitProjectionSupport::areaFocusSection($areaFocus),
            'executive_decision_inbox' => ProductModeCockpitProjectionSupport::executiveSection($executive),
            'new_area_proposal_gate' => ProductModeCockpitProjectionSupport::newAreaSection($newAreaGate),
            'self_expanding_company' => ProductModeCockpitProjectionSupport::selfExpandingSection($selfExpanding),
            'stewardship_outcome_history' => ProductModeCockpitProjectionSupport::outcomeHistorySection($outcomeHistory),
            'domain_runtime_creation_handoff' => ProductModeCockpitProjectionSupport::domainRuntimeCreationHandoffSection($domainRuntimeCreationHandoff),
            'area_stewardship_active_handoff' => ProductModeCockpitProjectionSupport::areaStewardshipActiveHandoffSection($areaStewardshipActiveHandoff),
            'area_stewardship_active_operation' => ProductModeCockpitProjectionSupport::areaStewardshipActiveOperationSection($areaStewardshipActiveOperation),
            'continuous_stewardship_loop' => ProductModeCockpitProjectionSupport::continuousStewardshipLoopSection($continuousLoop),
            'continuous_stewardship_scheduler' => ProductModeCockpitProjectionSupport::continuousStewardshipSchedulerSection($continuousScheduler),
            'dev_forge_release' => ProductModeCockpitProjectionSupport::devForgeReleaseSection($devForgeRelease),
            'owner_sandbox_runtime_runner' => ProductModeCockpitProjectionSupport::ownerSandboxRuntimeRunnerSection($ownerSandboxRuntime),
            'owner_runtime_result_bridge' => ProductModeCockpitProjectionSupport::ownerRuntimeResultBridgeSection($ownerRuntimeResult),
            'executive_allocation_handoff' => ProductModeCockpitProjectionSupport::executiveAllocationHandoffSection($executiveAllocationHandoff),
            'product_mode_operational_controls' => ProductModeCockpitProjectionSupport::productModeOperationalControlsSection($productModeOperationalControls),
            'loop_24h_observability' => ProductModeCockpitProjectionSupport::loop24hObservabilitySection($loop24h),
            'review_queue' => $reviewQueue,
            'operator_controls' => [
                'read_only_surface' => true,
                'decision_recording_owner' => 'AP-731 Stewardship Evolution Decision Ledger',
                'executive_decision_command' => (string) data_get($executive, 'operator_controls.decision_command', ''),
                'new_area_decision_command' => (string) data_get($newAreaGate, 'operator_controls.decision_command', ''),
                'outcome_evidence_command' => 'php artisan atlas:software-company-stewardship outcome-evidence --record-evidence --actor=<operator> --json',
                'morning_inbox_emit_command' => 'php artisan atlas:software-company-stewardship outcome-evidence --emit-inbox --json',
                'release_outcome_evidence_command' => 'php artisan atlas:software-company-stewardship outcome-evidence --release-file=<ap747.jsonl> --record-evidence --actor=<operator> --json',
                'owner_queue_consumption_gate_command' => 'php artisan atlas:software-company-stewardship owner-queue-consumption-gate --release-file=<ap747.jsonl> --outcome-file=<ap748.json> --execution-receipt-file=<ap749.json> --json',
                'owner_queue_consumption_record_command' => 'php artisan atlas:software-company-stewardship owner-queue-consumption-gate --release-file=<ap747.jsonl> --outcome-file=<ap748.json> --execution-receipt-file=<ap749.json> --record-consumption --json',
                'owner_sandbox_runtime_plan_command' => 'php artisan atlas:software-company-stewardship owner-sandbox-runtime-run --execution-file=<ap758.jsonl> --owner-execution-id=<owner_execution_id> --runtime-command-receipt-file=<ap759-command-receipt.json> --json',
                'owner_sandbox_runtime_execute_command' => 'php artisan atlas:software-company-stewardship owner-sandbox-runtime-run --execution-file=<ap758.jsonl> --owner-execution-id=<owner_execution_id> --runtime-command-receipt-file=<ap759-command-receipt.json> --execute-owner-command --json',
                'owner_sandbox_runtime_record_command' => 'php artisan atlas:software-company-stewardship owner-sandbox-runtime-run --execution-file=<ap758.jsonl> --owner-execution-id=<owner_execution_id> --runtime-command-receipt-file=<ap759-command-receipt.json> --execute-owner-command --record-owner-run --json',
                'owner_runtime_result_bridge_command' => 'php artisan atlas:software-company-stewardship owner-runtime-result-bridge --consumption-file=<ap749.jsonl> --result-file=<owner-result.json> --json',
                'owner_runtime_result_record_command' => 'php artisan atlas:software-company-stewardship owner-runtime-result-bridge --consumption-file=<ap749.jsonl> --result-file=<owner-result.json> --record-result --json',
                'executive_allocation_handoff_command' => 'php artisan atlas:software-company-stewardship executive-allocation-handoff --pack-id=<ap735> --recommendation-id=<recommendation> --json',
                'executive_allocation_handoff_record_command' => 'php artisan atlas:software-company-stewardship executive-allocation-handoff --pack-id=<ap735> --recommendation-id=<recommendation> --record-allocation-handoff --json',
                'product_mode_controls_command' => 'php artisan atlas:software-company-stewardship product-mode-controls --json',
                'domain_handoff_record_command' => 'php artisan atlas:software-company-stewardship domain-runtime-creation-handoff --record-handoff --proposal-id=<proposal> --json',
                'area_active_handoff_record_command' => 'php artisan atlas:software-company-stewardship area-stewardship-active-handoff --record-active-handoff --area=<area> --json',
                'area_active_operation_record_command' => 'php artisan atlas:software-company-stewardship area-stewardship-active-operate --record-active-operation --area=<area> --json',
                'continuous_loop_tick_command' => 'php artisan atlas:software-company-stewardship continuous-stewardship-loop --enable-continuous-loop --area=<area> --json',
                'continuous_loop_record_command' => 'php artisan atlas:software-company-stewardship continuous-stewardship-loop --enable-continuous-loop --record-continuous-cycle --area=<area> --json',
                'continuous_scheduler_run_command' => 'php artisan atlas:software-company-stewardship continuous-stewardship-scheduler --enable-continuous-scheduler --area=<area> --json',
                'continuous_scheduler_record_command' => 'php artisan atlas:software-company-stewardship continuous-stewardship-scheduler --enable-continuous-scheduler --record-scheduler-run --record-continuous-cycle --area=<area> --json',
                'dev_forge_release_command' => 'php artisan atlas:software-company-stewardship area-focus-dev-forge-release --preflight-file=<ap726.json> --release-receipt-file=<ap747.json> --json',
                'dev_forge_release_record_command' => 'php artisan atlas:software-company-stewardship area-focus-dev-forge-release --preflight-file=<ap726.json> --release-receipt-file=<ap747.json> --record-release --json',
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ],
            'next_actions' => ProductModeCockpitProjectionSupport::nextActions($counters),
            'claim_policy' => ProductModeCockpitProjectionSupport::claimPolicy(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $hashPayload = ProductModeCockpitProjectionSupport::withoutGeneratedAt($payload);
        unset($hashPayload['surface_hash']);

        $payload['surface_hash'] = 'sha256:'.MissionCanonicalHash::sha256($hashPayload);
        $payload['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);

        return $payload;
    }
}
