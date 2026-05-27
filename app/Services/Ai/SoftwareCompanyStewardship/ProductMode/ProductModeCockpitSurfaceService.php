<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\ProductMode;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompany\AreaFocusProductModeSurfaceService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDevForgeReleaseService;
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
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeResultBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerSandboxRuntimeRunnerService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOutcomeEvidenceBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionReadModelService;
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
                'claim_policy' => $this->claimPolicy(),
            ]);
        }

        $executive = $this->executiveInbox->project($portfolioId, $input + ['area_id' => $areaId]);
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

        $reviewQueue = $this->reviewQueue($executive, $newAreaGate, $selfExpanding, $outcomeHistory, $domainRuntimeCreationHandoff, $areaStewardshipActiveHandoff, $areaStewardshipActiveOperation, $continuousLoop, $continuousScheduler, $devForgeRelease, $ownerSandboxRuntime, $ownerRuntimeResult, $executiveAllocationHandoff, $productModeOperationalControls);
        $counters = $this->counters($areaFocus, $executive, $newAreaGate, $selfExpanding, $outcomeHistory, $domainRuntimeCreationHandoff, $areaStewardshipActiveHandoff, $areaStewardshipActiveOperation, $continuousLoop, $continuousScheduler, $devForgeRelease, $ownerSandboxRuntime, $ownerRuntimeResult, $executiveAllocationHandoff, $productModeOperationalControls, $reviewQueue);

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
                'overall' => $this->overallHealth($counters, $executive, $newAreaGate, $selfExpanding, $outcomeHistory, $domainRuntimeCreationHandoff, $areaStewardshipActiveHandoff, $areaStewardshipActiveOperation, $continuousLoop, $continuousScheduler, $devForgeRelease, $ownerSandboxRuntime, $ownerRuntimeResult, $executiveAllocationHandoff, $productModeOperationalControls),
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
            'next_actions' => $this->nextActions($counters),
            'claim_policy' => $this->claimPolicy(),
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
     * @param  array<string,mixed>  $executive
     * @param  array<string,mixed>  $newAreaGate
     * @param  array<string,mixed>  $selfExpanding
     * @param  array<string,mixed>  $outcomeHistory
     * @param  array<string,mixed>  $handoff
     * @param  array<string,mixed>  $areaActiveHandoff
     * @param  array<string,mixed>  $areaActiveOperation
     * @param  array<string,mixed>  $continuousLoop
     * @param  array<string,mixed>  $continuousScheduler
     * @param  array<string,mixed>  $devForgeRelease
     * @param  array<string,mixed>  $ownerSandboxRuntime
     * @param  array<string,mixed>  $ownerRuntimeResult
     * @param  array<string,mixed>  $executiveAllocationHandoff
     * @param  array<string,mixed>  $productModeControls
     * @return list<array<string,mixed>>
     */
    private function reviewQueue(array $executive, array $newAreaGate, array $selfExpanding, array $outcomeHistory, array $handoff, array $areaActiveHandoff, array $areaActiveOperation, array $continuousLoop, array $continuousScheduler, array $devForgeRelease, array $ownerSandboxRuntime, array $ownerRuntimeResult, array $executiveAllocationHandoff, array $productModeControls): array
    {
        $queue = [];

        if (in_array((string) ($productModeControls['status'] ?? ''), [
            ProductModeOperationalControlsReadModelService::STATUS_BLOCKED,
            ProductModeOperationalControlsReadModelService::STATUS_REVIEW,
        ], true)) {
            $blockers = array_values(array_filter((array) ($productModeControls['blockers'] ?? []), 'is_string'));
            $queue[] = [
                'schema_version' => 'atlas.software_company.product_mode_cockpit.review_item.v1',
                'source_ap' => 'AP-754',
                'kind' => 'product_mode_operational_controls',
                'id' => (string) ($productModeControls['controls_hash'] ?? ''),
                'title' => 'Review Product Mode operational controls',
                'status' => (string) ($productModeControls['status'] ?? ''),
                'risk_level' => $blockers === [] ? 'medium' : 'critical',
                'target_area' => (string) ($productModeControls['area_id'] ?? ''),
                'priority_score' => 103,
                'decision_anchor' => [
                    'source_ap' => 'AP-754',
                    'controls_hash' => (string) ($productModeControls['controls_hash'] ?? ''),
                    'repo' => (string) data_get($productModeControls, 'repo_onboarding.repository', ''),
                    'autonomy_tier' => (int) data_get($productModeControls, 'autonomy_tiers.current_tier', 0),
                    'kill_switch_active' => (bool) data_get($productModeControls, 'safety_controls.kill_switch_active', false),
                ],
                'blockers' => $blockers,
                'recommended_operator_action' => $blockers === []
                    ? 'review_product_mode_controls_before_enabling_higher_autonomy'
                    : 'resolve_product_mode_control_blockers_before_any_stewardship_execution',
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ];
        }

        foreach (array_values(array_filter((array) ($executive['items'] ?? []), 'is_array')) as $item) {
            $queue[] = [
                'schema_version' => 'atlas.software_company.product_mode_cockpit.review_item.v1',
                'source_ap' => 'AP-736',
                'kind' => 'autonomous_executive_recommendation',
                'id' => (string) ($item['inbox_item_id'] ?? ''),
                'title' => (string) ($item['title'] ?? 'Review executive recommendation'),
                'status' => (string) ($item['status'] ?? 'pending_operator_review'),
                'risk_level' => (string) ($item['risk_level'] ?? 'medium'),
                'target_area' => (string) ($item['target_area'] ?? ''),
                'priority_score' => (int) ($item['priority_score'] ?? 0),
                'decision_anchor' => is_array($item['stable_decision_anchor'] ?? null) ? $item['stable_decision_anchor'] : [],
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ];
        }

        foreach (array_values(array_filter((array) ($outcomeHistory['morning_inbox_items'] ?? []), 'is_array')) as $item) {
            $sourceAp = (string) ($item['bridge_ap_contract'] ?? $item['source_ap_contract'] ?? 'AP-740');
            $queue[] = [
                'schema_version' => 'atlas.software_company.product_mode_cockpit.review_item.v1',
                'source_ap' => $sourceAp,
                'kind' => (string) ($item['kind'] ?? 'stewardship_outcome_history'),
                'id' => (string) ($item['dedupe_key'] ?? $item['decision_id'] ?? $item['proposal_id'] ?? ''),
                'title' => (string) ($item['title'] ?? 'Review stewardship outcome history'),
                'status' => (string) ($item['inbox_status'] ?? 'projected'),
                'risk_level' => (string) data_get($item, 'review_signal.severity', 'medium'),
                'target_area' => (string) ($item['candidate_area'] ?? $item['area_id'] ?? ''),
                'priority_score' => 0,
                'decision_anchor' => [
                    'source_ap' => $sourceAp,
                    'recommended_action' => (string) ($item['recommended_action'] ?? ''),
                    'source_refs' => array_values(array_filter((array) ($item['source_refs'] ?? []), 'is_array')),
                ],
                'recommended_operator_action' => (string) ($item['recommended_action'] ?? ''),
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ];
        }

        foreach (array_values(array_filter((array) ($handoff['handoff_packets'] ?? []), 'is_array')) as $packet) {
            $blockers = array_values(array_filter((array) ($packet['blockers'] ?? []), 'is_string'));
            $queue[] = [
                'schema_version' => 'atlas.software_company.product_mode_cockpit.review_item.v1',
                'source_ap' => 'AP-741',
                'kind' => 'domain_runtime_creation_handoff',
                'id' => (string) ($packet['handoff_packet_id'] ?? ''),
                'title' => 'Review Domain Runtime Creation Gate handoff: '.(string) ($packet['candidate_area'] ?? ''),
                'status' => (string) ($packet['handoff_status'] ?? 'blocked'),
                'risk_level' => $blockers === [] ? 'high' : 'critical',
                'target_area' => (string) ($packet['candidate_area'] ?? ''),
                'priority_score' => $blockers === [] ? 90 : 10,
                'decision_anchor' => [
                    'source_ap' => 'AP-741',
                    'handoff_packet_id' => (string) ($packet['handoff_packet_id'] ?? ''),
                    'packet_hash' => (string) ($packet['packet_hash'] ?? ''),
                    'target_gate' => is_array($packet['target_gate'] ?? null) ? $packet['target_gate'] : [],
                ],
                'blockers' => $blockers,
                'recommended_operator_action' => $blockers === []
                    ? 'submit_recorded_packet_to_domain_runtime_creation_gate_review'
                    : 'resolve_handoff_blockers_before_domain_runtime_creation_gate',
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ];
        }

        foreach (array_values(array_filter((array) ($areaActiveHandoff['active_handoff_packets'] ?? []), 'is_array')) as $packet) {
            $blockers = array_values(array_filter((array) ($packet['blockers'] ?? []), 'is_string'));
            $queue[] = [
                'schema_version' => 'atlas.software_company.product_mode_cockpit.review_item.v1',
                'source_ap' => 'AP-743',
                'kind' => 'area_stewardship_active_handoff',
                'id' => (string) ($packet['handoff_packet_id'] ?? ''),
                'title' => 'Review Area Stewardship active handoff: '.(string) ($packet['area_id'] ?? ''),
                'status' => (string) ($packet['handoff_status'] ?? 'blocked'),
                'risk_level' => $blockers === [] ? 'high' : 'critical',
                'target_area' => (string) ($packet['area_id'] ?? ''),
                'priority_score' => $blockers === [] ? 95 : 10,
                'decision_anchor' => [
                    'source_ap' => 'AP-743',
                    'handoff_packet_id' => (string) ($packet['handoff_packet_id'] ?? ''),
                    'packet_hash' => (string) ($packet['packet_hash'] ?? ''),
                    'target_owner' => (string) ($areaActiveHandoff['target_owner'] ?? 'Atlas Area Stewardship Layer'),
                    'source_readiness_report_hash' => (string) ($packet['source_readiness_report_hash'] ?? ''),
                ],
                'blockers' => $blockers,
                'recommended_operator_action' => $blockers === []
                    ? 'record_or_accept_area_stewardship_active_handoff_before_operating_active_mode'
                    : 'resolve_area_stewardship_active_handoff_blockers',
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ];
        }

        if (in_array((string) ($areaActiveOperation['status'] ?? ''), [
            AreaStewardshipActiveOperatingService::STATUS_READY,
            AreaStewardshipActiveOperatingService::STATUS_PARTIAL,
        ], true)) {
            $queue[] = [
                'schema_version' => 'atlas.software_company.product_mode_cockpit.review_item.v1',
                'source_ap' => 'AP-744',
                'kind' => 'area_stewardship_active_operation',
                'id' => (string) ($areaActiveOperation['operation_id'] ?? ''),
                'title' => 'Review Area Stewardship active operation: '.(string) ($areaActiveOperation['area_id'] ?? ''),
                'status' => (string) ($areaActiveOperation['status'] ?? ''),
                'risk_level' => 'high',
                'target_area' => (string) ($areaActiveOperation['area_id'] ?? ''),
                'priority_score' => 96,
                'decision_anchor' => [
                    'source_ap' => 'AP-744',
                    'operation_id' => (string) ($areaActiveOperation['operation_id'] ?? ''),
                    'operation_hash' => (string) ($areaActiveOperation['operation_hash'] ?? ''),
                    'active_handoff_hash' => (string) ($areaActiveOperation['active_handoff_hash'] ?? ''),
                    'operational_cycle_hash' => (string) ($areaActiveOperation['operational_cycle_hash'] ?? ''),
                ],
                'counts' => is_array($areaActiveOperation['counts'] ?? null) ? $areaActiveOperation['counts'] : [],
                'recommended_operator_action' => 'review_active_operation_queue_and_record_ap724_decisions_before_any_dev_forge_release',
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ];
        }

        if (in_array((string) ($continuousLoop['status'] ?? ''), [
            AtlasContinuousStewardshipLoopService::STATUS_READY_TO_TICK,
            AtlasContinuousStewardshipLoopService::STATUS_TICK_COMPLETED,
            AtlasContinuousStewardshipLoopService::STATUS_TICK_RECORDED,
            AtlasContinuousStewardshipLoopService::STATUS_RATE_LIMITED,
            AtlasContinuousStewardshipLoopService::STATUS_LOCKED,
        ], true)) {
            $queue[] = [
                'schema_version' => 'atlas.software_company.product_mode_cockpit.review_item.v1',
                'source_ap' => 'AP-745',
                'kind' => 'continuous_stewardship_loop_tick',
                'id' => (string) ($continuousLoop['tick_id'] ?? ''),
                'title' => 'Review Continuous Stewardship Loop: '.(string) ($continuousLoop['area_id'] ?? ''),
                'status' => (string) ($continuousLoop['status'] ?? ''),
                'risk_level' => 'high',
                'target_area' => (string) ($continuousLoop['area_id'] ?? ''),
                'priority_score' => 97,
                'decision_anchor' => [
                    'source_ap' => 'AP-745',
                    'tick_id' => (string) ($continuousLoop['tick_id'] ?? ''),
                    'tick_hash' => (string) ($continuousLoop['tick_hash'] ?? ''),
                    'active_operation_hash' => (string) ($continuousLoop['active_operation_hash'] ?? ''),
                    'next_allowed_at' => $continuousLoop['next_allowed_at'] ?? null,
                ],
                'blockers' => array_values(array_filter((array) ($continuousLoop['blockers'] ?? []), 'is_string')),
                'recommended_operator_action' => 'review_continuous_loop_tick_before_any_dev_forge_release_or_scheduler_promotion',
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ];
        }

        if (in_array((string) ($continuousScheduler['status'] ?? ''), [
            AtlasContinuousStewardshipRecurringSchedulerService::STATUS_SCHEDULED,
            AtlasContinuousStewardshipRecurringSchedulerService::STATUS_RUN_COMPLETED,
            AtlasContinuousStewardshipRecurringSchedulerService::STATUS_RUN_RECORDED,
            AtlasContinuousStewardshipRecurringSchedulerService::STATUS_NOT_DUE,
            AtlasContinuousStewardshipRecurringSchedulerService::STATUS_RATE_LIMITED,
            AtlasContinuousStewardshipRecurringSchedulerService::STATUS_LOCKED,
        ], true)) {
            $queue[] = [
                'schema_version' => 'atlas.software_company.product_mode_cockpit.review_item.v1',
                'source_ap' => 'AP-746',
                'kind' => 'continuous_stewardship_scheduler_run',
                'id' => (string) ($continuousScheduler['scheduler_run_id'] ?? ''),
                'title' => 'Review recurring Continuous Stewardship scheduler: '.(string) ($continuousScheduler['area_id'] ?? ''),
                'status' => (string) ($continuousScheduler['status'] ?? ''),
                'risk_level' => 'high',
                'target_area' => (string) ($continuousScheduler['area_id'] ?? ''),
                'priority_score' => 98,
                'decision_anchor' => [
                    'source_ap' => 'AP-746',
                    'scheduler_id' => (string) ($continuousScheduler['scheduler_id'] ?? ''),
                    'scheduler_run_id' => (string) ($continuousScheduler['scheduler_run_id'] ?? ''),
                    'run_hash' => (string) ($continuousScheduler['run_hash'] ?? ''),
                    'tick_hash' => (string) ($continuousScheduler['tick_hash'] ?? ''),
                    'next_allowed_at' => $continuousScheduler['next_allowed_at'] ?? null,
                ],
                'blockers' => array_values(array_filter((array) ($continuousScheduler['blockers'] ?? []), 'is_string')),
                'recommended_operator_action' => in_array((string) ($continuousScheduler['status'] ?? ''), [
                    AtlasContinuousStewardshipRecurringSchedulerService::STATUS_RUN_COMPLETED,
                    AtlasContinuousStewardshipRecurringSchedulerService::STATUS_RUN_RECORDED,
                ], true)
                    ? 'review_recurring_scheduler_run_before_any_dev_forge_release_or_scheduler_expansion'
                    : 'review_recurring_scheduler_policy_before_external_scheduler_invocation',
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ];
        }

        if (in_array((string) ($devForgeRelease['status'] ?? ''), [
            AreaFocusDevForgeReleaseService::STATUS_READY,
            AreaFocusDevForgeReleaseService::STATUS_RECORDED,
            AreaFocusDevForgeReleaseService::STATUS_BLOCKED,
        ], true)) {
            $blockers = array_values(array_filter((array) ($devForgeRelease['blockers'] ?? []), 'is_string'));
            $queue[] = [
                'schema_version' => 'atlas.software_company.product_mode_cockpit.review_item.v1',
                'source_ap' => 'AP-747',
                'kind' => 'area_focus_dev_forge_release',
                'id' => (string) ($devForgeRelease['release_id'] ?? ''),
                'title' => 'Review Area Focus Dev/Forge release: '.(string) ($devForgeRelease['area_id'] ?? ''),
                'status' => (string) ($devForgeRelease['status'] ?? ''),
                'risk_level' => $blockers === [] ? 'high' : 'critical',
                'target_area' => (string) ($devForgeRelease['area_id'] ?? ''),
                'priority_score' => 99,
                'decision_anchor' => [
                    'source_ap' => 'AP-747',
                    'release_id' => (string) ($devForgeRelease['release_id'] ?? ''),
                    'release_hash' => (string) ($devForgeRelease['release_hash'] ?? ''),
                    'target_owner' => (string) ($devForgeRelease['target_owner'] ?? ''),
                    'queue_item_id' => (string) data_get($devForgeRelease, 'queue_item.queue_item_id', ''),
                ],
                'blockers' => $blockers,
                'recommended_operator_action' => $blockers === []
                    ? 'review_owner_queue_item_before_dev_forge_runtime_execution'
                    : 'resolve_ap747_release_blockers_before_any_dev_forge_queue_release',
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ];
        }

        if ((int) data_get($outcomeHistory, 'release_outcome_summary.release_count', 0) > 0) {
            $queue[] = [
                'schema_version' => 'atlas.software_company.product_mode_cockpit.review_item.v1',
                'source_ap' => 'AP-749',
                'kind' => 'owner_queue_consumption_gate',
                'id' => (string) data_get($outcomeHistory, 'release_outcome_summary.queue_item_ids.0', ''),
                'title' => 'Run AP-749 owner queue consumption gate before Dev/Forge runtime input',
                'status' => 'operator_review_required',
                'risk_level' => 'high',
                'target_area' => (string) ($outcomeHistory['area_id'] ?? ''),
                'priority_score' => 100,
                'decision_anchor' => [
                    'source_ap' => 'AP-749',
                    'release_file' => '<ap747.jsonl>',
                    'outcome_file' => '<ap748.json>',
                    'queue_item_ids' => array_values((array) data_get($outcomeHistory, 'release_outcome_summary.queue_item_ids', [])),
                ],
                'recommended_operator_action' => 'run_owner_queue_consumption_gate_before_owner_runtime_input',
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ];
        }

        if (in_array((string) ($ownerSandboxRuntime['status'] ?? ''), [
            StewardshipOwnerSandboxRuntimeRunnerService::STATUS_PLANNED,
            StewardshipOwnerSandboxRuntimeRunnerService::STATUS_READY,
            StewardshipOwnerSandboxRuntimeRunnerService::STATUS_RECORDED,
            StewardshipOwnerSandboxRuntimeRunnerService::STATUS_BLOCKED,
        ], true)) {
            $blockers = array_values(array_filter((array) ($ownerSandboxRuntime['blockers'] ?? []), 'is_string'));
            $commandPlan = is_array($ownerSandboxRuntime['command_plan'] ?? null) ? $ownerSandboxRuntime['command_plan'] : [];
            $queue[] = [
                'schema_version' => 'atlas.software_company.product_mode_cockpit.review_item.v1',
                'source_ap' => 'AP-759',
                'kind' => 'owner_sandbox_runtime_runner',
                'id' => (string) ($ownerSandboxRuntime['owner_sandbox_run_id'] ?? $commandPlan['run_id'] ?? ''),
                'title' => 'Review AP-759 sandboxed owner runtime command',
                'status' => (string) ($ownerSandboxRuntime['status'] ?? ''),
                'risk_level' => $blockers === [] ? 'high' : 'critical',
                'target_area' => (string) ($ownerSandboxRuntime['area_id'] ?? ''),
                'target_owner' => (string) ($ownerSandboxRuntime['target_owner'] ?? ''),
                'priority_score' => 101,
                'decision_anchor' => [
                    'source_ap' => 'AP-759',
                    'owner_sandbox_run_id' => (string) ($ownerSandboxRuntime['owner_sandbox_run_id'] ?? $commandPlan['run_id'] ?? ''),
                    'owner_execution_id' => (string) ($ownerSandboxRuntime['owner_execution_id'] ?? ''),
                    'command_hash' => (string) ($commandPlan['command_hash'] ?? ''),
                    'command_display' => (string) ($commandPlan['command_display'] ?? ''),
                    'requires_provider_authority' => (bool) ($commandPlan['requires_provider_authority'] ?? false),
                    'worktree_path_hash' => (string) ($commandPlan['worktree_path_hash'] ?? ''),
                    'ap750_owner_result_id' => (string) data_get($ownerSandboxRuntime, 'ap750_bridge_input.owner_result_id', data_get($ownerSandboxRuntime, 'owner_result.result_id', '')),
                ],
                'blockers' => $blockers,
                'recommended_operator_action' => match ((string) ($ownerSandboxRuntime['status'] ?? '')) {
                    StewardshipOwnerSandboxRuntimeRunnerService::STATUS_PLANNED => 'review_ap759_owner_runtime_command_plan_before_execute',
                    StewardshipOwnerSandboxRuntimeRunnerService::STATUS_READY,
                    StewardshipOwnerSandboxRuntimeRunnerService::STATUS_RECORDED => 'feed_ap759_owner_result_into_ap750_before_merge_deploy_or_followup',
                    default => 'resolve_ap759_owner_sandbox_runtime_blockers_before_execution',
                },
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ];
        }

        if (in_array((string) ($ownerRuntimeResult['status'] ?? ''), [
            StewardshipOwnerRuntimeResultBridgeService::STATUS_READY,
            StewardshipOwnerRuntimeResultBridgeService::STATUS_RECORDED,
            StewardshipOwnerRuntimeResultBridgeService::STATUS_BLOCKED,
        ], true)) {
            $blockers = array_values(array_filter((array) ($ownerRuntimeResult['blockers'] ?? []), 'is_string'));
            $queue[] = [
                'schema_version' => 'atlas.software_company.product_mode_cockpit.review_item.v1',
                'source_ap' => 'AP-750',
                'kind' => 'owner_runtime_result_bridge',
                'id' => (string) ($ownerRuntimeResult['owner_result_id'] ?? $ownerRuntimeResult['result_bridge_id'] ?? ''),
                'title' => 'Review AP-750 owner runtime result before merge/deploy/follow-up',
                'status' => (string) ($ownerRuntimeResult['status'] ?? ''),
                'risk_level' => $blockers === [] ? 'high' : 'critical',
                'target_area' => (string) ($ownerRuntimeResult['area_id'] ?? ''),
                'priority_score' => 101,
                'decision_anchor' => [
                    'source_ap' => 'AP-750',
                    'consumption_id' => (string) ($ownerRuntimeResult['consumption_id'] ?? ''),
                    'owner_result_id' => (string) ($ownerRuntimeResult['owner_result_id'] ?? ''),
                    'target_owner' => (string) ($ownerRuntimeResult['target_owner'] ?? ''),
                ],
                'blockers' => $blockers,
                'recommended_operator_action' => $blockers === []
                    ? 'review_owner_runtime_result_before_merge_deploy_or_followup'
                    : 'resolve_ap750_result_bridge_blockers_before_any_followup',
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ];
        }

        foreach (array_values(array_filter((array) ($executiveAllocationHandoff['allocation_handoff_packets'] ?? []), 'is_array')) as $packet) {
            $blockers = array_values(array_filter((array) ($packet['blockers'] ?? []), 'is_string'));
            $queue[] = [
                'schema_version' => 'atlas.software_company.product_mode_cockpit.review_item.v1',
                'source_ap' => 'AP-752',
                'kind' => 'executive_allocation_handoff',
                'id' => (string) ($packet['handoff_packet_id'] ?? ''),
                'title' => 'Review Autonomous Executive allocation handoff: '.(string) ($packet['target_area'] ?? ''),
                'status' => (string) ($packet['handoff_status'] ?? $executiveAllocationHandoff['status'] ?? 'blocked'),
                'risk_level' => $blockers === [] ? 'high' : 'critical',
                'target_area' => (string) ($packet['target_area'] ?? $executiveAllocationHandoff['target_area'] ?? ''),
                'priority_score' => 102,
                'decision_anchor' => [
                    'source_ap' => 'AP-752',
                    'handoff_packet_id' => (string) ($packet['handoff_packet_id'] ?? ''),
                    'packet_hash' => (string) ($packet['packet_hash'] ?? ''),
                    'source_pack_id' => (string) ($packet['source_pack_id'] ?? ''),
                    'source_recommendation_id' => (string) ($packet['source_recommendation_id'] ?? ''),
                    'source_decision_id' => (string) ($packet['source_decision_id'] ?? ''),
                    'target_owner' => (string) ($packet['target_owner'] ?? ''),
                    'target_owner_contract' => (string) ($packet['target_owner_contract'] ?? ''),
                    'target_owner_doc' => (string) ($packet['target_owner_doc'] ?? ''),
                ],
                'allowed_next_actions' => array_values(array_filter((array) ($packet['allowed_next_actions'] ?? []), 'is_string')),
                'blockers' => $blockers,
                'recommended_operator_action' => $blockers === []
                    ? 'review_ap752_allocation_handoff_before_routing_owner_work'
                    : 'resolve_ap752_allocation_handoff_blockers_before_owner_routing',
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ];
        }

        foreach (array_values(array_filter((array) ($newAreaGate['gate_items'] ?? []), 'is_array')) as $item) {
            $queue[] = [
                'schema_version' => 'atlas.software_company.product_mode_cockpit.review_item.v1',
                'source_ap' => 'AP-737',
                'kind' => is_array($item['known_existing_owner'] ?? null)
                    ? 'existing_capability_handoff'
                    : 'new_area_proposal',
                'id' => (string) ($item['gate_item_id'] ?? ''),
                'title' => 'Review expansion proposal: '.(string) ($item['candidate_area'] ?? ''),
                'status' => (string) ($item['gate_status'] ?? 'awaiting_operator_review'),
                'risk_level' => (string) ($item['risk_level'] ?? 'medium'),
                'target_area' => (string) ($item['candidate_area'] ?? ''),
                'priority_score' => 0,
                'decision_anchor' => is_array($item['operator_decision_anchor'] ?? null) ? $item['operator_decision_anchor'] : [],
                'blockers' => array_values(array_filter((array) ($item['blockers'] ?? []), 'is_string')),
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ];
        }

        $selfItems = array_values(array_filter((array) data_get($selfExpanding, 'operator_inbox.items', []), 'is_array'));
        foreach ($selfItems as $item) {
            $queue[] = [
                'schema_version' => 'atlas.software_company.product_mode_cockpit.review_item.v1',
                'source_ap' => 'AP-738',
                'kind' => 'self_expanding_operator_inbox',
                'id' => (string) ($item['inbox_item_id'] ?? ''),
                'title' => 'Self-expanding review: '.(string) ($item['candidate_area'] ?? ''),
                'status' => (string) ($item['gate_status'] ?? 'awaiting_operator_review'),
                'risk_level' => 'medium',
                'target_area' => (string) ($item['candidate_area'] ?? ''),
                'priority_score' => 0,
                'decision_anchor' => is_array($item['decision_anchor'] ?? null) ? $item['decision_anchor'] : [],
                'recommended_operator_action' => (string) ($item['recommended_operator_action'] ?? ''),
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ];
        }

        usort($queue, static function (array $a, array $b): int {
            $riskRank = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
            $ar = $riskRank[(string) ($a['risk_level'] ?? '')] ?? 0;
            $br = $riskRank[(string) ($b['risk_level'] ?? '')] ?? 0;
            if ($ar !== $br) {
                return $br <=> $ar;
            }

            return ((int) ($b['priority_score'] ?? 0)) <=> ((int) ($a['priority_score'] ?? 0));
        });

        return $queue;
    }

    /**
     * @param  array<string,mixed>  $areaFocus
     * @param  array<string,mixed>  $executive
     * @param  array<string,mixed>  $newAreaGate
     * @param  array<string,mixed>  $selfExpanding
     * @param  array<string,mixed>  $outcomeHistory
     * @param  array<string,mixed>  $handoff
     * @param  array<string,mixed>  $areaActiveHandoff
     * @param  array<string,mixed>  $areaActiveOperation
     * @param  array<string,mixed>  $continuousLoop
     * @param  array<string,mixed>  $continuousScheduler
     * @param  array<string,mixed>  $devForgeRelease
     * @param  array<string,mixed>  $ownerSandboxRuntime
     * @param  array<string,mixed>  $ownerRuntimeResult
     * @param  array<string,mixed>  $executiveAllocationHandoff
     * @param  array<string,mixed>  $productModeControls
     * @param  list<array<string,mixed>>  $reviewQueue
     * @return array<string,int>
     */
    private function counters(array $areaFocus, array $executive, array $newAreaGate, array $selfExpanding, array $outcomeHistory, array $handoff, array $areaActiveHandoff, array $areaActiveOperation, array $continuousLoop, array $continuousScheduler, array $devForgeRelease, array $ownerSandboxRuntime, array $ownerRuntimeResult, array $executiveAllocationHandoff, array $productModeControls, array $reviewQueue): array
    {
        return [
            'area_findings' => (int) data_get($areaFocus, 'findings.total', 0),
            'area_inbox_items' => count((array) ($areaFocus['inbox_items'] ?? [])),
            'work_orders' => count((array) ($areaFocus['work_orders'] ?? [])),
            'executive_items' => (int) ($executive['item_count'] ?? 0),
            'executive_pending_review' => (int) data_get($executive, 'decision_summary.pending_operator_review', 0),
            'new_area_gate_items' => (int) ($newAreaGate['gate_item_count'] ?? 0),
            'new_area_blocked_review' => (int) data_get($newAreaGate, 'decision_summary.blocked_awaiting_operator_review', 0),
            'self_expanding_inbox_items' => (int) data_get($selfExpanding, 'operator_inbox.item_count', 0),
            'outcome_evidence_items' => (int) ($outcomeHistory['evidence_item_count'] ?? 0),
            'outcome_morning_inbox_items' => (int) ($outcomeHistory['morning_inbox_item_count'] ?? 0),
            'release_outcome_count' => (int) data_get($outcomeHistory, 'release_outcome_summary.release_count', 0),
            'release_portfolio_feed_areas' => count((array) data_get($outcomeHistory, 'portfolio_feed.areas', [])),
            'domain_handoff_packets' => (int) ($handoff['accepted_candidate_count'] ?? count((array) ($handoff['handoff_packets'] ?? []))),
            'ready_domain_handoffs' => (int) ($handoff['ready_handoff_count'] ?? 0),
            'blocked_domain_handoffs' => (int) ($handoff['blocked_handoff_count'] ?? 0),
            'area_active_handoff_packets' => (int) ($areaActiveHandoff['active_handoff_count'] ?? count((array) ($areaActiveHandoff['active_handoff_packets'] ?? []))),
            'ready_area_active_handoffs' => (int) (($areaActiveHandoff['status'] ?? '') === AreaStewardshipActiveHandoffService::STATUS_READY ? ($areaActiveHandoff['active_handoff_count'] ?? 0) : 0),
            'pending_area_active_acceptance' => (int) (($areaActiveHandoff['status'] ?? '') === AreaStewardshipActiveHandoffService::STATUS_AWAITING_OPERATOR_ACCEPTANCE ? 1 : 0),
            'area_active_operations' => (int) (in_array((string) ($areaActiveOperation['status'] ?? ''), [AreaStewardshipActiveOperatingService::STATUS_READY, AreaStewardshipActiveOperatingService::STATUS_PARTIAL], true) ? 1 : 0),
            'ready_area_active_operations' => (int) (($areaActiveOperation['status'] ?? '') === AreaStewardshipActiveOperatingService::STATUS_READY ? 1 : 0),
            'partial_area_active_operations' => (int) (($areaActiveOperation['status'] ?? '') === AreaStewardshipActiveOperatingService::STATUS_PARTIAL ? 1 : 0),
            'area_active_operation_work_orders' => (int) data_get($areaActiveOperation, 'counts.work_orders', 0),
            'area_active_operation_spec_drafts' => (int) data_get($areaActiveOperation, 'counts.spec_drafts', 0),
            'ready_area_active_operation_handoffs' => (int) data_get($areaActiveOperation, 'counts.ready_branch_handoffs', 0),
            'continuous_loop_paused' => (int) (($continuousLoop['status'] ?? '') === AtlasContinuousStewardshipLoopService::STATUS_PAUSED ? 1 : 0),
            'continuous_loop_ready_to_tick' => (int) (($continuousLoop['status'] ?? '') === AtlasContinuousStewardshipLoopService::STATUS_READY_TO_TICK ? 1 : 0),
            'continuous_loop_ticks' => (int) (in_array((string) ($continuousLoop['status'] ?? ''), [AtlasContinuousStewardshipLoopService::STATUS_TICK_COMPLETED, AtlasContinuousStewardshipLoopService::STATUS_TICK_RECORDED], true) ? 1 : 0),
            'continuous_loop_recorded_ticks' => (int) (($continuousLoop['status'] ?? '') === AtlasContinuousStewardshipLoopService::STATUS_TICK_RECORDED ? 1 : 0),
            'continuous_loop_rate_limited' => (int) (($continuousLoop['status'] ?? '') === AtlasContinuousStewardshipLoopService::STATUS_RATE_LIMITED ? 1 : 0),
            'continuous_loop_locked' => (int) (($continuousLoop['status'] ?? '') === AtlasContinuousStewardshipLoopService::STATUS_LOCKED ? 1 : 0),
            'continuous_scheduler_paused' => (int) (($continuousScheduler['status'] ?? '') === AtlasContinuousStewardshipRecurringSchedulerService::STATUS_PAUSED ? 1 : 0),
            'continuous_scheduler_scheduled' => (int) (($continuousScheduler['status'] ?? '') === AtlasContinuousStewardshipRecurringSchedulerService::STATUS_SCHEDULED ? 1 : 0),
            'continuous_scheduler_runs' => (int) (in_array((string) ($continuousScheduler['status'] ?? ''), [AtlasContinuousStewardshipRecurringSchedulerService::STATUS_RUN_COMPLETED, AtlasContinuousStewardshipRecurringSchedulerService::STATUS_RUN_RECORDED], true) ? 1 : 0),
            'continuous_scheduler_recorded_runs' => (int) (($continuousScheduler['status'] ?? '') === AtlasContinuousStewardshipRecurringSchedulerService::STATUS_RUN_RECORDED ? 1 : 0),
            'continuous_scheduler_not_due' => (int) (($continuousScheduler['status'] ?? '') === AtlasContinuousStewardshipRecurringSchedulerService::STATUS_NOT_DUE ? 1 : 0),
            'continuous_scheduler_rate_limited' => (int) (($continuousScheduler['status'] ?? '') === AtlasContinuousStewardshipRecurringSchedulerService::STATUS_RATE_LIMITED ? 1 : 0),
            'continuous_scheduler_locked' => (int) (($continuousScheduler['status'] ?? '') === AtlasContinuousStewardshipRecurringSchedulerService::STATUS_LOCKED ? 1 : 0),
            'dev_forge_releases' => (int) (in_array((string) ($devForgeRelease['status'] ?? ''), [AreaFocusDevForgeReleaseService::STATUS_READY, AreaFocusDevForgeReleaseService::STATUS_RECORDED], true) ? 1 : 0),
            'dev_forge_recorded_releases' => (int) (($devForgeRelease['status'] ?? '') === AreaFocusDevForgeReleaseService::STATUS_RECORDED ? 1 : 0),
            'dev_forge_release_blocked' => (int) (($devForgeRelease['status'] ?? '') === AreaFocusDevForgeReleaseService::STATUS_BLOCKED ? 1 : 0),
            'owner_sandbox_runtime_runs' => (int) (in_array((string) ($ownerSandboxRuntime['status'] ?? ''), [StewardshipOwnerSandboxRuntimeRunnerService::STATUS_PLANNED, StewardshipOwnerSandboxRuntimeRunnerService::STATUS_READY, StewardshipOwnerSandboxRuntimeRunnerService::STATUS_RECORDED], true) ? 1 : 0),
            'owner_sandbox_runtime_planned_runs' => (int) (($ownerSandboxRuntime['status'] ?? '') === StewardshipOwnerSandboxRuntimeRunnerService::STATUS_PLANNED ? 1 : 0),
            'owner_sandbox_runtime_ready_results' => (int) (($ownerSandboxRuntime['status'] ?? '') === StewardshipOwnerSandboxRuntimeRunnerService::STATUS_READY ? 1 : 0),
            'owner_sandbox_runtime_recorded_runs' => (int) (($ownerSandboxRuntime['status'] ?? '') === StewardshipOwnerSandboxRuntimeRunnerService::STATUS_RECORDED ? 1 : 0),
            'owner_sandbox_runtime_blocked' => (int) (($ownerSandboxRuntime['status'] ?? '') === StewardshipOwnerSandboxRuntimeRunnerService::STATUS_BLOCKED ? 1 : 0),
            'owner_sandbox_runtime_changed_files' => count((array) ($ownerSandboxRuntime['changed_files'] ?? [])),
            'owner_runtime_results' => (int) (in_array((string) ($ownerRuntimeResult['status'] ?? ''), [StewardshipOwnerRuntimeResultBridgeService::STATUS_READY, StewardshipOwnerRuntimeResultBridgeService::STATUS_RECORDED], true) ? 1 : 0),
            'owner_runtime_recorded_results' => (int) (($ownerRuntimeResult['status'] ?? '') === StewardshipOwnerRuntimeResultBridgeService::STATUS_RECORDED ? 1 : 0),
            'owner_runtime_result_blocked' => (int) (($ownerRuntimeResult['status'] ?? '') === StewardshipOwnerRuntimeResultBridgeService::STATUS_BLOCKED ? 1 : 0),
            'owner_runtime_result_evidence_items' => count((array) ($ownerRuntimeResult['evidence_items'] ?? [])),
            'owner_runtime_result_inbox_items' => count((array) ($ownerRuntimeResult['morning_inbox_items'] ?? [])),
            'owner_runtime_result_portfolio_feed_areas' => count((array) data_get($ownerRuntimeResult, 'portfolio_feed.areas', [])),
            'executive_allocation_handoff_packets' => (int) ($executiveAllocationHandoff['allocation_handoff_count'] ?? count((array) ($executiveAllocationHandoff['allocation_handoff_packets'] ?? []))),
            'ready_executive_allocation_handoffs' => (int) (($executiveAllocationHandoff['status'] ?? '') === AutonomousExecutiveAllocationHandoffService::STATUS_READY ? ($executiveAllocationHandoff['allocation_handoff_count'] ?? count((array) ($executiveAllocationHandoff['allocation_handoff_packets'] ?? []))) : 0),
            'awaiting_executive_allocation_acceptance' => (int) (($executiveAllocationHandoff['status'] ?? '') === AutonomousExecutiveAllocationHandoffService::STATUS_AWAITING_OPERATOR_ACCEPTANCE ? 1 : 0),
            'blocked_executive_allocation_handoffs' => (int) (($executiveAllocationHandoff['status'] ?? '') === AutonomousExecutiveAllocationHandoffService::STATUS_BLOCKED ? 1 : 0),
            'product_mode_control_blockers' => count((array) ($productModeControls['blockers'] ?? [])),
            'product_mode_control_review_required' => (int) (($productModeControls['status'] ?? '') === ProductModeOperationalControlsReadModelService::STATUS_REVIEW ? 1 : 0),
            'product_mode_control_blocked' => (int) (($productModeControls['status'] ?? '') === ProductModeOperationalControlsReadModelService::STATUS_BLOCKED ? 1 : 0),
            'product_mode_pending_branch_reviews' => (int) data_get($productModeControls, 'branch_review_center.pending_review_count', 0),
            'product_mode_missing_evidence_refs' => count((array) data_get($productModeControls, 'evidence_inspector.missing_refs', [])),
            'review_queue_items' => count($reviewQueue),
            'ready_for_domain_runtime_creation_gate' => (int) data_get($selfExpanding, 'expansion_summary.ready_for_domain_runtime_creation_gate', 0),
        ];
    }

    /**
     * @param  array<string,int>  $counters
     * @param  array<string,mixed>  $executive
     * @param  array<string,mixed>  $newAreaGate
     * @param  array<string,mixed>  $selfExpanding
     * @param  array<string,mixed>  $outcomeHistory
     * @param  array<string,mixed>  $handoff
     * @param  array<string,mixed>  $areaActiveHandoff
     * @param  array<string,mixed>  $areaActiveOperation
     * @param  array<string,mixed>  $continuousLoop
     * @param  array<string,mixed>  $continuousScheduler
     * @param  array<string,mixed>  $devForgeRelease
     * @param  array<string,mixed>  $ownerSandboxRuntime
     * @param  array<string,mixed>  $ownerRuntimeResult
     * @param  array<string,mixed>  $executiveAllocationHandoff
     * @param  array<string,mixed>  $productModeControls
     */
    private function overallHealth(array $counters, array $executive, array $newAreaGate, array $selfExpanding, array $outcomeHistory, array $handoff, array $areaActiveHandoff, array $areaActiveOperation, array $continuousLoop, array $continuousScheduler, array $devForgeRelease, array $ownerSandboxRuntime, array $ownerRuntimeResult, array $executiveAllocationHandoff, array $productModeControls): string
    {
        if (($executive['status'] ?? '') === self::STATUS_BLOCKED
            || ($newAreaGate['status'] ?? '') === self::STATUS_BLOCKED
            || ($selfExpanding['status'] ?? '') === self::STATUS_BLOCKED
            || ($outcomeHistory['status'] ?? '') === self::STATUS_BLOCKED
            || ($handoff['status'] ?? '') === self::STATUS_BLOCKED
            || ($areaActiveHandoff['status'] ?? '') === AreaStewardshipActiveHandoffService::STATUS_BLOCKED
            || ($areaActiveOperation['status'] ?? '') === AreaStewardshipActiveOperatingService::STATUS_BLOCKED
            || ($continuousLoop['status'] ?? '') === AtlasContinuousStewardshipLoopService::STATUS_BLOCKED
            || ($continuousScheduler['status'] ?? '') === AtlasContinuousStewardshipRecurringSchedulerService::STATUS_BLOCKED
            || ($devForgeRelease['status'] ?? '') === AreaFocusDevForgeReleaseService::STATUS_BLOCKED
            || ($ownerSandboxRuntime['status'] ?? '') === StewardshipOwnerSandboxRuntimeRunnerService::STATUS_BLOCKED
            || ($ownerRuntimeResult['status'] ?? '') === StewardshipOwnerRuntimeResultBridgeService::STATUS_BLOCKED
            || ($executiveAllocationHandoff['status'] ?? '') === AutonomousExecutiveAllocationHandoffService::STATUS_BLOCKED
            || ($productModeControls['status'] ?? '') === ProductModeOperationalControlsReadModelService::STATUS_BLOCKED) {
            return self::STATUS_BLOCKED;
        }

        if (($counters['new_area_blocked_review'] ?? 0) > 0
            || ($counters['executive_pending_review'] ?? 0) > 0
            || ($counters['outcome_morning_inbox_items'] ?? 0) > 0
            || ($counters['domain_handoff_packets'] ?? 0) > 0
            || ($counters['area_active_handoff_packets'] ?? 0) > 0
            || ($counters['area_active_operations'] ?? 0) > 0
            || ($counters['pending_area_active_acceptance'] ?? 0) > 0
            || ($counters['continuous_loop_ready_to_tick'] ?? 0) > 0
            || ($counters['continuous_loop_ticks'] ?? 0) > 0
            || ($counters['continuous_loop_rate_limited'] ?? 0) > 0
            || ($counters['continuous_loop_locked'] ?? 0) > 0
            || ($counters['continuous_scheduler_scheduled'] ?? 0) > 0
            || ($counters['continuous_scheduler_runs'] ?? 0) > 0
            || ($counters['continuous_scheduler_not_due'] ?? 0) > 0
            || ($counters['continuous_scheduler_rate_limited'] ?? 0) > 0
            || ($counters['continuous_scheduler_locked'] ?? 0) > 0
            || ($counters['dev_forge_releases'] ?? 0) > 0
            || ($counters['dev_forge_release_blocked'] ?? 0) > 0
            || ($counters['owner_sandbox_runtime_runs'] ?? 0) > 0
            || ($counters['owner_sandbox_runtime_blocked'] ?? 0) > 0
            || ($counters['owner_runtime_results'] ?? 0) > 0
            || ($counters['owner_runtime_result_blocked'] ?? 0) > 0
            || ($counters['executive_allocation_handoff_packets'] ?? 0) > 0
            || ($counters['awaiting_executive_allocation_acceptance'] ?? 0) > 0
            || ($counters['product_mode_control_review_required'] ?? 0) > 0
            || ($counters['product_mode_pending_branch_reviews'] ?? 0) > 0
            || ($counters['product_mode_missing_evidence_refs'] ?? 0) > 0) {
            return 'review';
        }

        return self::STATUS_READY;
    }

    /**
     * @param  array<string,int>  $counters
     * @return list<string>
     */
    private function nextActions(array $counters): array
    {
        $actions = [];

        if (($counters['executive_pending_review'] ?? 0) > 0) {
            $actions[] = 'Review AP-736 executive recommendation(s) in Product Mode before allocating governed cycles.';
        }
        if (($counters['new_area_blocked_review'] ?? 0) > 0) {
            $actions[] = 'Review AP-737 existing-capability handoff blockers; route known capabilities to Area Stewardship instead of creating domains.';
        }
        if (($counters['ready_for_domain_runtime_creation_gate'] ?? 0) > 0) {
            $actions[] = 'Review AP-740 outcome history and prepare AP-741 handoff packet for Domain Runtime Creation Gate; do not create a domain directly.';
        }
        if (($counters['outcome_morning_inbox_items'] ?? 0) > 0) {
            $actions[] = 'Record or emit AP-740 outcome evidence only through the existing Evidence Ledger and Morning Inbox owners.';
        }
        if (($counters['release_outcome_count'] ?? 0) > 0) {
            $actions[] = 'Run AP-749 owner queue consumption gate after AP-748 release outcomes are reviewed.';
        }
        if (($counters['ready_domain_handoffs'] ?? 0) > 0) {
            $actions[] = 'Submit AP-741 handoff packet to Domain Runtime Creation Gate review; no domain runtime is created by the cockpit.';
        }
        if (($counters['blocked_domain_handoffs'] ?? 0) > 0) {
            $actions[] = 'Resolve AP-741 handoff blockers before any Domain Runtime Creation Gate review.';
        }
        if (($counters['pending_area_active_acceptance'] ?? 0) > 0) {
            $actions[] = 'Record AP-731 accept for Area Stewardship before AP-743 can create an active handoff packet.';
        }
        if (($counters['ready_area_active_handoffs'] ?? 0) > 0) {
            $actions[] = 'Review AP-743 active handoff packet before AP-744 active operation; no irreversible action starts from the cockpit.';
        }
        if (($counters['ready_area_active_operations'] ?? 0) > 0) {
            $actions[] = 'Review AP-744 active operation queue; release Dev/Forge handoffs only through explicit operator-owned controls.';
        }
        if (($counters['partial_area_active_operations'] ?? 0) > 0) {
            $actions[] = 'Record AP-724 decisions for AP-744 pending work orders before any Dev/Forge handoff can be released.';
        }
        if (($counters['continuous_loop_ready_to_tick'] ?? 0) > 0) {
            $actions[] = 'AP-745 admits one scheduler-safe tick; keep Product Mode kill switch, rate limit and review queue visible.';
        }
        if (($counters['continuous_loop_ticks'] ?? 0) > 0) {
            $actions[] = 'Review AP-745 tick output before promoting any continuous scheduler or releasing AP-726 handoffs.';
        }
        if (($counters['continuous_loop_rate_limited'] ?? 0) > 0) {
            $actions[] = 'Respect AP-745 min interval before scheduling another Continuous Stewardship tick.';
        }
        if (($counters['continuous_loop_locked'] ?? 0) > 0) {
            $actions[] = 'Inspect AP-745 lock lease before retrying a continuous tick.';
        }
        if (($counters['continuous_scheduler_scheduled'] ?? 0) > 0) {
            $actions[] = 'AP-746 scheduler runner is due; invoke at most one AP-745 tick from operator-owned scheduler controls.';
        }
        if (($counters['continuous_scheduler_runs'] ?? 0) > 0) {
            $actions[] = 'Review AP-746 scheduler run evidence before expanding recurring cadence or releasing Dev/Forge handoffs.';
        }
        if (($counters['continuous_scheduler_not_due'] ?? 0) > 0) {
            $actions[] = 'Respect AP-746/AP-745 cadence before the next recurring scheduler invocation.';
        }
        if (($counters['continuous_scheduler_rate_limited'] ?? 0) > 0) {
            $actions[] = 'Treat AP-746 rate limit as a hard stop unless the operator explicitly uses manual verification force.';
        }
        if (($counters['continuous_scheduler_locked'] ?? 0) > 0) {
            $actions[] = 'Inspect AP-746/AP-745 lock state before retrying the recurring scheduler.';
        }
        if (($counters['dev_forge_releases'] ?? 0) > 0) {
            $actions[] = 'Use AP-749 before owner-specific runtime input; no merge/deploy/secrets are authorized by the cockpit.';
        }
        if (($counters['dev_forge_release_blocked'] ?? 0) > 0) {
            $actions[] = 'Resolve AP-747 release blockers before any AP-726 handoff reaches Atlas Dev or Forge.';
        }
        if (($counters['owner_sandbox_runtime_planned_runs'] ?? 0) > 0) {
            $actions[] = 'Review AP-759 sandboxed owner command plan before allowing execution inside the AP-756 worktree.';
        }
        if (($counters['owner_sandbox_runtime_ready_results'] ?? 0) > 0) {
            $actions[] = 'Feed AP-759 owner_result into AP-750 before merge, deploy, follow-up allocation or Portfolio rebalance.';
        }
        if (($counters['owner_sandbox_runtime_recorded_runs'] ?? 0) > 0) {
            $actions[] = 'Review recorded AP-759 owner sandbox run and bridge its owner_result through AP-750.';
        }
        if (($counters['owner_sandbox_runtime_blocked'] ?? 0) > 0) {
            $actions[] = 'Resolve AP-759 sandbox command blockers before owner runtime execution.';
        }
        if (($counters['owner_runtime_results'] ?? 0) > 0) {
            $actions[] = 'Review AP-750 owner runtime result evidence before merge, deploy, follow-up allocation or Portfolio rebalance.';
        }
        if (($counters['owner_runtime_result_blocked'] ?? 0) > 0) {
            $actions[] = 'Resolve AP-750 result bridge blockers before the owner runtime outcome feeds Portfolio or any follow-up.';
        }
        if (($counters['awaiting_executive_allocation_acceptance'] ?? 0) > 0) {
            $actions[] = 'Record AP-731 accept for the selected AP-735 executive recommendation before AP-752 can route allocation to an owner.';
        }
        if (($counters['ready_executive_allocation_handoffs'] ?? 0) > 0) {
            $actions[] = 'Review AP-752 allocation handoff in Product Mode before any owner performs follow-up work.';
        }
        if (($counters['blocked_executive_allocation_handoffs'] ?? 0) > 0) {
            $actions[] = 'Resolve AP-752 blockers before routing accepted executive allocation to Area, Portfolio, Dev or Forge owners.';
        }
        if (($counters['product_mode_control_blocked'] ?? 0) > 0) {
            $actions[] = 'Resolve AP-754 Product Mode control blockers before admitting more stewardship work.';
        }
        if (($counters['product_mode_control_review_required'] ?? 0) > 0) {
            $actions[] = 'Review AP-754 Product Mode controls before raising autonomy, enabling continuous cadence or releasing branches.';
        }
        if (($counters['product_mode_pending_branch_reviews'] ?? 0) > 0) {
            $actions[] = 'Review pending branch items in owner runtimes; Product Mode never merges or deploys directly.';
        }
        if (($counters['product_mode_missing_evidence_refs'] ?? 0) > 0) {
            $actions[] = 'Attach missing AP-754 evidence refs before claiming Product Mode completion.';
        }

        $actions[] = 'Keep this cockpit read-only: decisions go through AP-731 receipts and execution goes through Dev/Forge under branch isolation.';

        return $actions;
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only_over_repo' => true,
            'surface_only' => true,
            'writes_local_state' => false,
            'records_operator_decisions' => false,
            'provider_invoked' => false,
            'dev_invoked' => false,
            'forge_invoked' => false,
            'branch_created' => false,
            'worktree_created' => false,
            'domain_runtime_created' => false,
            'department_created' => false,
            'scheduler_installed' => false,
            'continuous_loop_tick_executed_by_cockpit' => false,
            'recurring_scheduler_executed_by_cockpit' => false,
            'dev_forge_release_executed_by_cockpit' => false,
            'owner_queue_consumption_executed_by_cockpit' => false,
            'owner_sandbox_runtime_runner_executed_by_cockpit' => false,
            'owner_runtime_result_bridge_executed_by_cockpit' => false,
            'executive_allocation_handoff_executed_by_cockpit' => false,
            'product_mode_controls_execute_actions' => false,
            'product_mode_controls_authorize_repository' => false,
            'product_mode_controls_change_autonomy_tier' => false,
            'new_os_created' => false,
            'parallel_runtime_created' => false,
            'merge_without_operator' => false,
            'deploy_without_operator' => false,
            'secret_access' => false,
            'autoapproval_allowed' => false,
            'autoimplementation_allowed' => false,
            'auto_promotion' => false,
            'operator_review_required' => true,
        ];
    }
}
