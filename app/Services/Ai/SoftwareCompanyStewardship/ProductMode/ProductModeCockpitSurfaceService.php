<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\ProductMode;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompany\AreaFocusProductModeSurfaceService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDevForgeReleaseService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionReadModelService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipActiveHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipActiveOperatingService;
use App\Services\Ai\SoftwareCompanyStewardship\AutonomousExecutive\AutonomousExecutiveAllocationHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\AutonomousExecutive\ExecutiveDecisionInboxSurfaceService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\AtlasContinuousStewardshipLoopService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\AtlasContinuousStewardshipRecurringSchedulerService;
use App\Services\Ai\SoftwareCompanyStewardship\PortfolioStewardship\PortfolioStewardshipHealthModelService;
use App\Services\Ai\SoftwareCompanyStewardship\SelfExpanding\NewAreaProposalGateService;
use App\Services\Ai\SoftwareCompanyStewardship\SelfExpanding\SelfExpandingDomainRuntimeCreationHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\SelfExpanding\SelfExpandingSoftwareCompanyService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionReadModelService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOutcomeEvidenceBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeResultBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\Support\ProductModeCockpitProjectionSupport;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerSandboxRuntimeRunnerService;
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
        $areaId = $this->areaId($input);
        $portfolioId = $this->portfolioId($portfolioId, $input);

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
            : $this->defaultDevForgeRelease($areaId);
        $ownerSandboxRuntime = is_array($input['owner_sandbox_runtime_runner'] ?? null)
            ? $input['owner_sandbox_runtime_runner']
            : $this->defaultOwnerSandboxRuntimeRunner($areaId);
        $ownerRuntimeResult = is_array($input['owner_runtime_result_bridge'] ?? null)
            ? $input['owner_runtime_result_bridge']
            : $this->defaultOwnerRuntimeResultBridge($areaId);
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
            'area_focus' => $this->areaFocusSection($areaFocus),
            'executive_decision_inbox' => $this->executiveSection($executive),
            'new_area_proposal_gate' => $this->newAreaSection($newAreaGate),
            'self_expanding_company' => $this->selfExpandingSection($selfExpanding),
            'stewardship_outcome_history' => $this->outcomeHistorySection($outcomeHistory),
            'domain_runtime_creation_handoff' => $this->domainRuntimeCreationHandoffSection($domainRuntimeCreationHandoff),
            'area_stewardship_active_handoff' => $this->areaStewardshipActiveHandoffSection($areaStewardshipActiveHandoff),
            'area_stewardship_active_operation' => $this->areaStewardshipActiveOperationSection($areaStewardshipActiveOperation),
            'continuous_stewardship_loop' => $this->continuousStewardshipLoopSection($continuousLoop),
            'continuous_stewardship_scheduler' => $this->continuousStewardshipSchedulerSection($continuousScheduler),
            'dev_forge_release' => $this->devForgeReleaseSection($devForgeRelease),
            'owner_sandbox_runtime_runner' => $this->ownerSandboxRuntimeRunnerSection($ownerSandboxRuntime),
            'owner_runtime_result_bridge' => $this->ownerRuntimeResultBridgeSection($ownerRuntimeResult),
            'executive_allocation_handoff' => $this->executiveAllocationHandoffSection($executiveAllocationHandoff),
            'product_mode_operational_controls' => $this->productModeOperationalControlsSection($productModeOperationalControls),
            'loop_24h_observability' => $this->loop24hObservabilitySection($loop24h),
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
        $hashPayload = $this->withoutGeneratedAt($payload);
        unset($hashPayload['surface_hash']);

        $payload['surface_hash'] = 'sha256:'.MissionCanonicalHash::sha256($hashPayload);
        $payload['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function withoutGeneratedAt(array $payload): array
    {
        unset($payload['generated_at']);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->withoutGeneratedAt($value);
            }
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function areaId(array $input): string
    {
        $value = trim((string) ($input['area_id'] ?? $input['area'] ?? ''));

        return $value !== '' ? $value : StewardshipEvolutionReadModelService::DEFAULT_AREA_ID;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function portfolioId(string $portfolioId, array $input): string
    {
        $value = trim((string) ($input['portfolio_id'] ?? $portfolioId));

        return $value !== '' ? $value : PortfolioStewardshipHealthModelService::DEFAULT_PORTFOLIO_ID;
    }

    /**
     * @param  array<string,mixed>  $areaFocus
     * @return array<string,mixed>
     */
    private function areaFocusSection(array $areaFocus): array
    {
        return [
            'schema_version' => (string) ($areaFocus['schema_version'] ?? ''),
            'status' => (string) ($areaFocus['status'] ?? 'unknown'),
            'area_summary' => $areaFocus['area_summary'] ?? [],
            'health' => $areaFocus['health'] ?? [],
            'findings' => $areaFocus['findings'] ?? [],
            'inbox_items' => array_values(array_filter((array) ($areaFocus['inbox_items'] ?? []), 'is_array')),
            'work_orders' => array_values(array_filter((array) ($areaFocus['work_orders'] ?? []), 'is_array')),
            'budgets' => $areaFocus['budgets'] ?? [],
            'kill_switch_state' => $areaFocus['kill_switch_state'] ?? [],
            'next_actions' => array_values(array_filter((array) ($areaFocus['next_actions'] ?? []), 'is_string')),
            'surface_hash' => (string) ($areaFocus['surface_hash'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $executive
     * @return array<string,mixed>
     */
    private function executiveSection(array $executive): array
    {
        return [
            'schema_version' => (string) ($executive['schema_version'] ?? ''),
            'status' => (string) ($executive['status'] ?? 'unknown'),
            'ap_contract' => (string) ($executive['ap_contract'] ?? 'AP-736'),
            'source_pack_id' => (string) ($executive['source_pack_id'] ?? ''),
            'source_pack_hash' => (string) ($executive['source_pack_hash'] ?? ''),
            'item_count' => (int) ($executive['item_count'] ?? 0),
            'decision_summary' => is_array($executive['decision_summary'] ?? null) ? $executive['decision_summary'] : [],
            'items' => array_values(array_filter((array) ($executive['items'] ?? []), 'is_array')),
            'operator_controls' => is_array($executive['operator_controls'] ?? null) ? $executive['operator_controls'] : [],
            'surface_hash' => (string) ($executive['surface_hash'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $newAreaGate
     * @return array<string,mixed>
     */
    private function newAreaSection(array $newAreaGate): array
    {
        return [
            'schema_version' => (string) ($newAreaGate['schema_version'] ?? ''),
            'status' => (string) ($newAreaGate['status'] ?? 'unknown'),
            'ap_contract' => (string) ($newAreaGate['ap_contract'] ?? 'AP-737'),
            'mode' => (string) ($newAreaGate['mode'] ?? 'proposal_only'),
            'proposal_count' => (int) ($newAreaGate['proposal_count'] ?? 0),
            'gate_item_count' => (int) ($newAreaGate['gate_item_count'] ?? 0),
            'decision_summary' => is_array($newAreaGate['decision_summary'] ?? null) ? $newAreaGate['decision_summary'] : [],
            'gate_items' => array_values(array_filter((array) ($newAreaGate['gate_items'] ?? []), 'is_array')),
            'operator_controls' => is_array($newAreaGate['operator_controls'] ?? null) ? $newAreaGate['operator_controls'] : [],
            'gate_hash' => (string) ($newAreaGate['gate_hash'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $selfExpanding
     * @return array<string,mixed>
     */
    private function selfExpandingSection(array $selfExpanding): array
    {
        return [
            'schema_version' => (string) ($selfExpanding['schema_version'] ?? ''),
            'status' => (string) ($selfExpanding['status'] ?? 'unknown'),
            'ap_contract' => (string) ($selfExpanding['ap_contract'] ?? 'AP-738'),
            'mode' => (string) ($selfExpanding['mode'] ?? 'v0_proposal_only'),
            'expansion_summary' => is_array($selfExpanding['expansion_summary'] ?? null) ? $selfExpanding['expansion_summary'] : [],
            'operator_inbox' => is_array($selfExpanding['operator_inbox'] ?? null) ? $selfExpanding['operator_inbox'] : [],
            'promotion_boundary' => is_array($selfExpanding['promotion_boundary'] ?? null) ? $selfExpanding['promotion_boundary'] : [],
            'expansion_loop' => array_values(array_filter((array) ($selfExpanding['expansion_loop'] ?? []), 'is_string')),
            'report_hash' => (string) ($selfExpanding['report_hash'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $outcomeHistory
     * @return array<string,mixed>
     */
    private function outcomeHistorySection(array $outcomeHistory): array
    {
        return [
            'schema_version' => (string) ($outcomeHistory['schema_version'] ?? ''),
            'status' => (string) ($outcomeHistory['status'] ?? 'unknown'),
            'ap_contract' => (string) ($outcomeHistory['ap_contract'] ?? 'AP-740'),
            'decision_count' => (int) ($outcomeHistory['decision_count'] ?? 0),
            'self_expanding_status' => (string) ($outcomeHistory['self_expanding_status'] ?? 'unknown'),
            'evidence_item_count' => (int) ($outcomeHistory['evidence_item_count'] ?? 0),
            'morning_inbox_item_count' => (int) ($outcomeHistory['morning_inbox_item_count'] ?? 0),
            'release_outcome_summary' => is_array($outcomeHistory['release_outcome_summary'] ?? null) ? $outcomeHistory['release_outcome_summary'] : [],
            'portfolio_feed' => is_array($outcomeHistory['portfolio_feed'] ?? null) ? $outcomeHistory['portfolio_feed'] : [],
            'record_evidence_requested' => (bool) ($outcomeHistory['record_evidence_requested'] ?? false),
            'emit_inbox_requested' => (bool) ($outcomeHistory['emit_inbox_requested'] ?? false),
            'next_handoff_boundary' => is_array($outcomeHistory['next_handoff_boundary'] ?? null) ? $outcomeHistory['next_handoff_boundary'] : [],
            'evidence_items' => array_values(array_filter((array) ($outcomeHistory['evidence_items'] ?? []), 'is_array')),
            'morning_inbox_items' => array_values(array_filter((array) ($outcomeHistory['morning_inbox_items'] ?? []), 'is_array')),
            'bridge_hash' => (string) ($outcomeHistory['bridge_hash'] ?? ''),
            'claim_policy' => is_array($outcomeHistory['claim_policy'] ?? null) ? $outcomeHistory['claim_policy'] : [],
        ];
    }

    /**
     * @param  array<string,mixed>  $handoff
     * @return array<string,mixed>
     */
    private function domainRuntimeCreationHandoffSection(array $handoff): array
    {
        return [
            'schema_version' => (string) ($handoff['schema_version'] ?? ''),
            'status' => (string) ($handoff['status'] ?? 'unknown'),
            'ap_contract' => (string) ($handoff['ap_contract'] ?? 'AP-741'),
            'target_owner' => (string) ($handoff['target_owner'] ?? 'Atlas Domain Runtime Creation Gate'),
            'target_owner_doc' => (string) ($handoff['target_owner_doc'] ?? 'docs/engineering-knowledge-base/atlas-domain-runtime-creation-gate.md'),
            'accepted_candidate_count' => (int) ($handoff['accepted_candidate_count'] ?? 0),
            'ready_handoff_count' => (int) ($handoff['ready_handoff_count'] ?? 0),
            'blocked_handoff_count' => (int) ($handoff['blocked_handoff_count'] ?? 0),
            'record_handoff_requested' => (bool) ($handoff['record_handoff_requested'] ?? false),
            'require_recorded_evidence' => (bool) ($handoff['require_recorded_evidence'] ?? true),
            'handoff_packets' => array_values(array_filter((array) ($handoff['handoff_packets'] ?? []), 'is_array')),
            'next_actions' => array_values(array_filter((array) ($handoff['next_actions'] ?? []), 'is_string')),
            'handoff_hash' => (string) ($handoff['handoff_hash'] ?? ''),
            'claim_policy' => is_array($handoff['claim_policy'] ?? null) ? $handoff['claim_policy'] : [],
        ];
    }

    /**
     * @param  array<string,mixed>  $handoff
     * @return array<string,mixed>
     */
    private function areaStewardshipActiveHandoffSection(array $handoff): array
    {
        return [
            'schema_version' => (string) ($handoff['schema_version'] ?? ''),
            'status' => (string) ($handoff['status'] ?? 'unknown'),
            'ap_contract' => (string) ($handoff['ap_contract'] ?? 'AP-743'),
            'target_owner' => (string) ($handoff['target_owner'] ?? 'Atlas Area Stewardship Layer'),
            'target_owner_doc' => (string) ($handoff['target_owner_doc'] ?? 'docs/engineering-knowledge-base/atlas-area-stewardship-layer.md'),
            'readiness_status' => (string) ($handoff['readiness_status'] ?? 'unknown'),
            'record_active_handoff_requested' => (bool) ($handoff['record_active_handoff_requested'] ?? false),
            'active_handoff_count' => (int) ($handoff['active_handoff_count'] ?? 0),
            'active_handoff_packets' => array_values(array_filter((array) ($handoff['active_handoff_packets'] ?? []), 'is_array')),
            'blockers' => array_values(array_filter((array) ($handoff['blockers'] ?? []), 'is_string')),
            'next_actions' => array_values(array_filter((array) ($handoff['next_actions'] ?? []), 'is_string')),
            'handoff_hash' => (string) ($handoff['handoff_hash'] ?? ''),
            'claim_policy' => is_array($handoff['claim_policy'] ?? null) ? $handoff['claim_policy'] : [],
        ];
    }

    /**
     * @param  array<string,mixed>  $operation
     * @return array<string,mixed>
     */
    private function areaStewardshipActiveOperationSection(array $operation): array
    {
        return [
            'schema_version' => (string) ($operation['schema_version'] ?? ''),
            'status' => (string) ($operation['status'] ?? 'unknown'),
            'ap_contract' => (string) ($operation['ap_contract'] ?? 'AP-744'),
            'operation_id' => (string) ($operation['operation_id'] ?? ''),
            'operation_hash' => (string) ($operation['operation_hash'] ?? ''),
            'active_handoff_status' => (string) ($operation['active_handoff_status'] ?? 'unknown'),
            'operational_cycle_id' => (string) ($operation['operational_cycle_id'] ?? ''),
            'operational_cycle_hash' => (string) ($operation['operational_cycle_hash'] ?? ''),
            'record_active_operation_requested' => (bool) ($operation['record_active_operation_requested'] ?? false),
            'counts' => is_array($operation['counts'] ?? null) ? $operation['counts'] : [],
            'operation_queue' => is_array($operation['operation_queue'] ?? null) ? $operation['operation_queue'] : [],
            'blockers' => array_values(array_filter((array) ($operation['blockers'] ?? []), 'is_string')),
            'next_actions' => array_values(array_filter((array) ($operation['next_actions'] ?? []), 'is_string')),
            'claim_policy' => is_array($operation['claim_policy'] ?? null) ? $operation['claim_policy'] : [],
        ];
    }

    /**
     * @param  array<string,mixed>  $loop
     * @return array<string,mixed>
     */
    private function continuousStewardshipLoopSection(array $loop): array
    {
        return [
            'schema_version' => (string) ($loop['schema_version'] ?? ''),
            'status' => (string) ($loop['status'] ?? 'unknown'),
            'ap_contract' => (string) ($loop['ap_contract'] ?? 'AP-745'),
            'tick_id' => (string) ($loop['tick_id'] ?? ''),
            'tick_hash' => (string) ($loop['tick_hash'] ?? ''),
            'mode' => (string) ($loop['mode'] ?? 'scheduler_safe_projection'),
            'active_operation_status' => (string) ($loop['active_operation_status'] ?? ''),
            'active_operation_hash' => (string) ($loop['active_operation_hash'] ?? ''),
            'record_continuous_cycle_requested' => (bool) ($loop['record_continuous_cycle_requested'] ?? false),
            'policy' => is_array($loop['policy'] ?? null) ? $loop['policy'] : [],
            'last_tick' => is_array($loop['last_tick'] ?? null) ? $loop['last_tick'] : null,
            'next_allowed_at' => $loop['next_allowed_at'] ?? null,
            'blockers' => array_values(array_filter((array) ($loop['blockers'] ?? []), 'is_string')),
            'next_actions' => array_values(array_filter((array) ($loop['next_actions'] ?? []), 'is_string')),
            'claim_policy' => is_array($loop['claim_policy'] ?? null) ? $loop['claim_policy'] : [],
        ];
    }

    /**
     * @param  array<string,mixed>  $scheduler
     * @return array<string,mixed>
     */
    private function continuousStewardshipSchedulerSection(array $scheduler): array
    {
        return [
            'schema_version' => (string) ($scheduler['schema_version'] ?? ''),
            'status' => (string) ($scheduler['status'] ?? 'unknown'),
            'ap_contract' => (string) ($scheduler['ap_contract'] ?? 'AP-746'),
            'scheduler_id' => (string) ($scheduler['scheduler_id'] ?? ''),
            'scheduler_run_id' => (string) ($scheduler['scheduler_run_id'] ?? ''),
            'run_hash' => (string) ($scheduler['run_hash'] ?? ''),
            'mode' => (string) ($scheduler['mode'] ?? 'recurring_scheduler_safe_projection'),
            'tick_status' => (string) ($scheduler['tick_status'] ?? ''),
            'tick_id' => (string) ($scheduler['tick_id'] ?? ''),
            'tick_hash' => (string) ($scheduler['tick_hash'] ?? ''),
            'record_scheduler_run_requested' => (bool) ($scheduler['record_scheduler_run_requested'] ?? false),
            'record_continuous_cycle_requested' => (bool) ($scheduler['record_continuous_cycle_requested'] ?? false),
            'policy' => is_array($scheduler['policy'] ?? null) ? $scheduler['policy'] : [],
            'continuous_loop' => is_array($scheduler['continuous_loop'] ?? null) ? $scheduler['continuous_loop'] : [],
            'last_scheduler_run' => is_array($scheduler['last_scheduler_run'] ?? null) ? $scheduler['last_scheduler_run'] : null,
            'next_allowed_at' => $scheduler['next_allowed_at'] ?? null,
            'blockers' => array_values(array_filter((array) ($scheduler['blockers'] ?? []), 'is_string')),
            'next_actions' => array_values(array_filter((array) ($scheduler['next_actions'] ?? []), 'is_string')),
            'claim_policy' => is_array($scheduler['claim_policy'] ?? null) ? $scheduler['claim_policy'] : [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultDevForgeRelease(string $areaId): array
    {
        return [
            'schema_version' => AreaFocusDevForgeReleaseService::REPORT_SCHEMA,
            'status' => 'not_requested',
            'ap_contract' => 'AP-747',
            'area_id' => $areaId,
            'target_owner' => '',
            'release_id' => '',
            'queue_item' => null,
            'blockers' => [],
            'claim_policy' => [
                'released_to_dev_or_forge_queue' => false,
                'provider_invoked' => false,
                'branch_created' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $release
     * @return array<string,mixed>
     */
    private function devForgeReleaseSection(array $release): array
    {
        return [
            'schema_version' => (string) ($release['schema_version'] ?? ''),
            'status' => (string) ($release['status'] ?? 'unknown'),
            'ap_contract' => (string) ($release['ap_contract'] ?? 'AP-747'),
            'mode' => (string) ($release['mode'] ?? 'operator_owned_release'),
            'area_id' => (string) ($release['area_id'] ?? ''),
            'target_owner' => (string) ($release['target_owner'] ?? ''),
            'target_runtime_schema' => (string) ($release['target_runtime_schema'] ?? ''),
            'release_id' => (string) ($release['release_id'] ?? ''),
            'release_hash' => (string) ($release['release_hash'] ?? ''),
            'record_release_requested' => (bool) ($release['record_release_requested'] ?? false),
            'queue_item' => is_array($release['queue_item'] ?? null) ? $release['queue_item'] : null,
            'blockers' => array_values(array_filter((array) ($release['blockers'] ?? []), 'is_string')),
            'next_actions' => array_values(array_filter((array) ($release['next_actions'] ?? []), 'is_string')),
            'claim_policy' => is_array($release['claim_policy'] ?? null) ? $release['claim_policy'] : [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultOwnerSandboxRuntimeRunner(string $areaId): array
    {
        return [
            'schema_version' => StewardshipOwnerSandboxRuntimeRunnerService::REPORT_SCHEMA,
            'status' => 'not_requested',
            'ap_contract' => 'AP-759',
            'mode' => 'owner_sandbox_runtime_runner',
            'area_id' => $areaId,
            'portfolio_id' => 'atlas_software_company',
            'owner_sandbox_run_id' => '',
            'owner_execution_id' => '',
            'target_owner' => '',
            'command_plan' => null,
            'command_result' => null,
            'owner_result' => null,
            'ap750_bridge_input' => null,
            'blockers' => [],
            'claim_policy' => [
                'owner_runtime_command_executed' => false,
                'provider_invoked' => false,
                'merge_performed' => false,
                'deploy_performed' => false,
                'secret_access' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $runner
     * @return array<string,mixed>
     */
    private function ownerSandboxRuntimeRunnerSection(array $runner): array
    {
        $commandPlan = is_array($runner['command_plan'] ?? null) ? $runner['command_plan'] : [];
        $commandResult = is_array($runner['command_result'] ?? null) ? $runner['command_result'] : [];
        $ownerResult = is_array($runner['owner_result'] ?? null) ? $runner['owner_result'] : [];

        return [
            'schema_version' => (string) ($runner['schema_version'] ?? ''),
            'status' => (string) ($runner['status'] ?? 'unknown'),
            'ap_contract' => (string) ($runner['ap_contract'] ?? 'AP-759'),
            'mode' => (string) ($runner['mode'] ?? 'owner_sandbox_runtime_runner'),
            'area_id' => (string) ($runner['area_id'] ?? ''),
            'portfolio_id' => (string) ($runner['portfolio_id'] ?? ''),
            'owner_sandbox_run_id' => (string) ($runner['owner_sandbox_run_id'] ?? $commandPlan['run_id'] ?? ''),
            'owner_execution_id' => (string) ($runner['owner_execution_id'] ?? ''),
            'consumption_id' => (string) ($runner['consumption_id'] ?? ''),
            'release_id' => (string) ($runner['release_id'] ?? ''),
            'queue_item_id' => (string) ($runner['queue_item_id'] ?? ''),
            'target_owner' => (string) ($runner['target_owner'] ?? ''),
            'command_display' => (string) ($commandPlan['command_display'] ?? ''),
            'command_hash' => (string) ($commandPlan['command_hash'] ?? ''),
            'requires_provider_authority' => (bool) ($commandPlan['requires_provider_authority'] ?? false),
            'execute_requested' => (bool) ($commandPlan['execute_requested'] ?? false),
            'exit_code' => array_key_exists('exit_code', $commandResult) ? (int) $commandResult['exit_code'] : null,
            'owner_result_id' => (string) ($ownerResult['result_id'] ?? data_get($runner, 'ap750_bridge_input.owner_result_id', '')),
            'owner_result_status' => (string) ($ownerResult['status'] ?? ''),
            'run_storage_status' => (string) ($runner['run_storage_status'] ?? ''),
            'record_run_requested' => (bool) ($runner['record_run_requested'] ?? false),
            'changed_file_count' => count((array) ($runner['changed_files'] ?? [])),
            'blockers' => array_values(array_filter((array) ($runner['blockers'] ?? []), 'is_string')),
            'next_actions' => array_values(array_filter((array) ($runner['next_actions'] ?? []), 'is_string')),
            'claim_policy' => is_array($runner['claim_policy'] ?? null) ? $runner['claim_policy'] : [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultOwnerRuntimeResultBridge(string $areaId): array
    {
        return [
            'schema_version' => StewardshipOwnerRuntimeResultBridgeService::REPORT_SCHEMA,
            'status' => 'not_reported',
            'ap_contract' => 'AP-750',
            'area_id' => $areaId,
            'target_owner' => '',
            'consumption_id' => '',
            'owner_result_id' => '',
            'evidence_items' => [],
            'morning_inbox_items' => [],
            'portfolio_feed' => [
                'schema_version' => 'atlas.software_company.stewardship_owner_runtime_result_portfolio_feed.v1',
                'areas' => [],
            ],
            'blockers' => [],
            'claim_policy' => [
                'owner_runtime_invoked_by_bridge' => false,
                'provider_invoked_by_bridge' => false,
                'merge_performed_by_bridge' => false,
                'deploy_performed_by_bridge' => false,
                'secret_access_by_bridge' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $bridge
     * @return array<string,mixed>
     */
    private function ownerRuntimeResultBridgeSection(array $bridge): array
    {
        return [
            'schema_version' => (string) ($bridge['schema_version'] ?? ''),
            'status' => (string) ($bridge['status'] ?? 'unknown'),
            'ap_contract' => (string) ($bridge['ap_contract'] ?? 'AP-750'),
            'mode' => (string) ($bridge['mode'] ?? 'owner_runtime_result_bridge'),
            'area_id' => (string) ($bridge['area_id'] ?? ''),
            'target_owner' => (string) ($bridge['target_owner'] ?? ''),
            'consumption_id' => (string) ($bridge['consumption_id'] ?? ''),
            'owner_result_id' => (string) ($bridge['owner_result_id'] ?? ''),
            'owner_result_status' => (string) ($bridge['owner_result_status'] ?? ''),
            'evidence_item_count' => count((array) ($bridge['evidence_items'] ?? [])),
            'morning_inbox_item_count' => count((array) ($bridge['morning_inbox_items'] ?? [])),
            'portfolio_feed_area_count' => count((array) data_get($bridge, 'portfolio_feed.areas', [])),
            'record_result_requested' => (bool) ($bridge['record_result_requested'] ?? false),
            'blockers' => array_values(array_filter((array) ($bridge['blockers'] ?? []), 'is_string')),
            'next_actions' => array_values(array_filter((array) ($bridge['next_actions'] ?? []), 'is_string')),
            'claim_policy' => is_array($bridge['claim_policy'] ?? null) ? $bridge['claim_policy'] : [],
        ];
    }

    /**
     * @param  array<string,mixed>  $handoff
     * @return array<string,mixed>
     */
    private function executiveAllocationHandoffSection(array $handoff): array
    {
        return [
            'schema_version' => (string) ($handoff['schema_version'] ?? ''),
            'status' => (string) ($handoff['status'] ?? 'unknown'),
            'reason' => (string) ($handoff['reason'] ?? ''),
            'ap_contract' => (string) ($handoff['ap_contract'] ?? 'AP-752'),
            'portfolio_id' => (string) ($handoff['portfolio_id'] ?? ''),
            'area_id' => (string) ($handoff['area_id'] ?? ''),
            'source_pack_id' => (string) ($handoff['source_pack_id'] ?? ''),
            'source_pack_hash' => (string) ($handoff['source_pack_hash'] ?? ''),
            'source_recommendation_id' => (string) ($handoff['source_recommendation_id'] ?? ''),
            'source_decision_id' => (string) ($handoff['source_decision_id'] ?? ''),
            'target_id' => (string) ($handoff['target_id'] ?? ''),
            'target_hash' => (string) ($handoff['target_hash'] ?? ''),
            'recommended_action' => (string) ($handoff['recommended_action'] ?? ''),
            'target_area' => (string) ($handoff['target_area'] ?? ''),
            'record_allocation_handoff_requested' => (bool) ($handoff['record_allocation_handoff_requested'] ?? false),
            'allocation_handoff_count' => (int) ($handoff['allocation_handoff_count'] ?? count((array) ($handoff['allocation_handoff_packets'] ?? []))),
            'allocation_handoff_packets' => array_values(array_filter((array) ($handoff['allocation_handoff_packets'] ?? []), 'is_array')),
            'blockers' => array_values(array_filter((array) ($handoff['blockers'] ?? []), 'is_string')),
            'next_actions' => array_values(array_filter((array) ($handoff['next_actions'] ?? []), 'is_string')),
            'handoff_hash' => (string) ($handoff['handoff_hash'] ?? ''),
            'claim_policy' => is_array($handoff['claim_policy'] ?? null) ? $handoff['claim_policy'] : [],
        ];
    }

    /**
     * @param  array<string,mixed>  $controls
     * @return array<string,mixed>
     */
    private function productModeOperationalControlsSection(array $controls): array
    {
        return [
            'schema_version' => (string) ($controls['schema_version'] ?? ''),
            'status' => (string) ($controls['status'] ?? 'unknown'),
            'ap_contract' => (string) ($controls['ap_contract'] ?? 'AP-754'),
            'mode' => (string) ($controls['mode'] ?? 'read_only_control_projection'),
            'area_id' => (string) ($controls['area_id'] ?? ''),
            'portfolio_id' => (string) ($controls['portfolio_id'] ?? ''),
            'repo_onboarding' => is_array($controls['repo_onboarding'] ?? null) ? $controls['repo_onboarding'] : [],
            'autonomy_tiers' => is_array($controls['autonomy_tiers'] ?? null) ? $controls['autonomy_tiers'] : [],
            'budget_policy' => is_array($controls['budget_policy'] ?? null) ? $controls['budget_policy'] : [],
            'safety_controls' => is_array($controls['safety_controls'] ?? null) ? $controls['safety_controls'] : [],
            'branch_review_center' => is_array($controls['branch_review_center'] ?? null) ? $controls['branch_review_center'] : [],
            'evidence_inspector' => is_array($controls['evidence_inspector'] ?? null) ? $controls['evidence_inspector'] : [],
            'risk_policy' => is_array($controls['risk_policy'] ?? null) ? $controls['risk_policy'] : [],
            'operator_controls' => is_array($controls['operator_controls'] ?? null) ? $controls['operator_controls'] : [],
            'next_actions' => array_values(array_filter((array) ($controls['next_actions'] ?? []), 'is_string')),
            'blockers' => array_values(array_filter((array) ($controls['blockers'] ?? []), 'is_string')),
            'controls_hash' => (string) ($controls['controls_hash'] ?? ''),
            'claim_policy' => is_array($controls['claim_policy'] ?? null) ? $controls['claim_policy'] : [],
        ];
    }

    /**
     * @param  array<string,mixed>  $observability
     * @return array<string,mixed>
     */
    private function loop24hObservabilitySection(array $observability): array
    {
        $metrics = is_array($observability['metrics'] ?? null) ? $observability['metrics'] : [];

        return [
            'schema_version' => (string) ($observability['schema_version'] ?? ''),
            'ap_contract' => (string) ($observability['ap_contract'] ?? 'AP-790'),
            'read_only' => true,
            'area_id' => (string) ($observability['area_id'] ?? ''),
            'focus' => (string) ($observability['focus'] ?? 'dev_forge'),
            'metrics' => $metrics,
            'blocked_by_reason' => is_array($observability['blocked_by_reason'] ?? null)
                ? $observability['blocked_by_reason']
                : (is_array($metrics['blocked_by_reason'] ?? null) ? $metrics['blocked_by_reason'] : []),
            'latest_commit' => $observability['latest_commit'] ?? $metrics['latest_commit'] ?? null,
            'latest_inbox_item' => $observability['latest_inbox_item'] ?? $metrics['latest_inbox_item'] ?? null,
            'active_worktrees' => array_values(array_filter((array) ($observability['active_worktrees'] ?? []), 'is_array')),
            'quarantined_count' => (int) ($observability['quarantined_count'] ?? 0),
            'cycle_inbox_summaries' => array_values(array_filter((array) ($observability['cycle_inbox_summaries'] ?? []), 'is_array')),
            'lock' => is_array($observability['lock'] ?? null) ? $observability['lock'] : [],
            'kill_switch' => is_array($observability['kill_switch'] ?? null) ? $observability['kill_switch'] : [],
            'backlog' => is_array($observability['backlog'] ?? null) ? $observability['backlog'] : [],
            'observability_hash' => (string) ($observability['observability_hash'] ?? ''),
            'claim_policy' => is_array($observability['claim_policy'] ?? null) ? $observability['claim_policy'] : ['read_only' => true],
        ];
    }
}
