<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompany;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\NightShift\AreaFocusLoopReadModelService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Area Focus · Product Mode Surface (v0.1, read-only Desktop read model).
 *
 * Atlas Software Company Stewardship Stack is a stack/capability family inside
 * the Atlas Autonomous Software Company Runtime, not a new OS. This service
 * (AP-721) projects the canonical read-only Area Focus Loop read model
 * ({@see AreaFocusLoopReadModelService}, AP-712) into a Desktop-ready shape so
 * Mission Control / Night Shift Product Mode can render an area without running
 * any mutating cycle. It executes nothing, creates no branch, invokes no
 * provider and never merges/deploys. It is a projection over the canonical
 * read model — not a parallel runtime, executor or second read model.
 */
class AreaFocusProductModeSurfaceService
{
    public const SURFACE_SCHEMA = 'atlas.night_shift.area_focus_product_mode_surface.v1';

    public const HEALTH_HEALTHY = 'healthy';

    public const HEALTH_WATCH = 'watch';

    public const HEALTH_BLOCKED = 'blocked';

    public function __construct(
        private readonly AreaFocusLoopReadModelService $loop,
    ) {}

    /**
     * Project the Desktop-ready Product Mode surface for an area.
     *
     * `$input` is forwarded to the read model (accepts `gap_read_model` override,
     * `owner_doc_status`, `hours`, `limit`) so the surface can be projected
     * deterministically.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(string $areaId = AreaFocusLoopReadModelService::PRIORITY_AREA, array $input = []): array
    {
        $report = $this->loop->project(array_merge($input, ['area_id' => $areaId]));

        if (($report['status'] ?? null) === AreaFocusLoopReadModelService::STATUS_BLOCKED
            && ($report['area'] ?? null) === null) {
            $blocker = is_array($report['blockers'][0] ?? null) ? $report['blockers'][0] : [];

            return [
                'schema_version' => self::SURFACE_SCHEMA,
                'status' => AreaFocusLoopReadModelService::STATUS_BLOCKED,
                'reason' => (string) ($blocker['reason'] ?? 'unknown_area'),
                'area_id' => (string) ($report['area_id'] ?? $areaId),
                'detail' => (string) ($blocker['detail'] ?? ''),
                'supported_areas' => array_values((array) ($blocker['supported_areas'] ?? [])),
                'read_only' => true,
            ];
        }

        $contract = is_array($report['area'] ?? null) ? $report['area'] : [];
        $findings = is_array($report['findings'] ?? null) ? $report['findings'] : [];
        $routing = is_array($report['routing_summary'] ?? null) ? $report['routing_summary'] : [];
        $governance = is_array($report['governance'] ?? null) ? $report['governance'] : [];
        $budgetState = is_array($report['budget_state'] ?? null) ? $report['budget_state'] : [];
        $evidenceReq = is_array($report['evidence_requirement'] ?? null) ? $report['evidence_requirement'] : [];
        $morningInbox = is_array($report['morning_inbox'] ?? null) ? $report['morning_inbox'] : [];
        $blockers = is_array($report['blockers'] ?? null) ? $report['blockers'] : [];

        $highRiskCount = $this->highRiskCount($findings);

        $payload = [
            'schema_version' => self::SURFACE_SCHEMA,
            'status' => (string) ($report['status'] ?? 'unknown'),
            'area_id' => (string) ($contract['area_id'] ?? $areaId),
            'read_only' => true,
            'ap_contract' => 'AP-721',
            'source_loop' => [
                'schema_version' => (string) ($report['schema_version'] ?? ''),
                'ap_contract' => (string) ($report['ap_contract'] ?? ''),
                'report_hash' => (string) ($report['report_hash'] ?? ''),
            ],
            'stewardship_stack_note' => (string) ($report['stewardship_stack_note'] ?? AreaFocusLoopReadModelService::STACK_NOTE),
            'area_summary' => $this->areaSummary($report, $contract),
            'health' => $this->health($report, $findings, $routing, $budgetState, $blockers, $highRiskCount),
            'findings' => $this->findings($findings, $routing),
            'inbox_items' => $this->inboxItems($morningInbox),
            'work_orders' => $this->workOrders($findings),
            'budgets' => $this->budgets($contract, $budgetState, $routing),
            'evidence_packs' => $this->evidencePacks($evidenceReq),
            'kill_switch_state' => $this->killSwitchState($governance),
            'next_actions' => $this->nextActions($morningInbox, $routing, $highRiskCount, $blockers),
            'claim_policy' => is_array($report['claim_policy'] ?? null) ? $report['claim_policy'] : [],
        ];
        $payload['surface_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);

        return $payload;
    }

    // ---------- sections ----------

    /**
     * @param  array<string,mixed>  $report
     * @param  array<string,mixed>  $contract
     * @return array<string,mixed>
     */
    private function areaSummary(array $report, array $contract): array
    {
        return [
            'area_id' => (string) ($contract['area_id'] ?? ''),
            'area_name' => (string) ($contract['area_name'] ?? ''),
            'status' => (string) ($report['status'] ?? 'unknown'),
            'autonomy_tier' => (string) ($contract['autonomy_tier'] ?? ''),
            'target_autonomy_mode' => (string) ($contract['dev_mode'] ?? 'max_governed'),
            'owner_docs' => array_values((array) ($contract['area_owner_docs'] ?? [])),
            'repo_scope' => is_array($contract['repo_scope'] ?? null) ? $contract['repo_scope'] : [],
            'stack_member' => (string) ($report['stack_member'] ?? 'area_focus_loop'),
            'stack_family' => (string) ($report['stack_family'] ?? 'atlas_software_company_stewardship_stack'),
            'runtime_home' => (string) ($report['runtime_home'] ?? 'atlas_autonomous_software_company_runtime'),
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @param  list<array<string,mixed>>  $findings
     * @param  array<string,int>  $routing
     * @param  array<string,mixed>  $budgetState
     * @param  list<array<string,mixed>>  $blockers
     * @return array<string,mixed>
     */
    private function health(array $report, array $findings, array $routing, array $budgetState, array $blockers, int $highRiskCount): array
    {
        $blockerCount = count($blockers);
        $queued = (int) ($routing['queued'] ?? 0);
        $wipLimit = (int) ($budgetState['execution_wip_limit'] ?? 0);
        $wipUsed = (int) ($budgetState['wip_advised'] ?? 0);

        $overall = match (true) {
            ($report['status'] ?? null) === AreaFocusLoopReadModelService::STATUS_BLOCKED => self::HEALTH_BLOCKED,
            $blockerCount > 0 || $highRiskCount > 0 || $queued > 0 => self::HEALTH_WATCH,
            default => self::HEALTH_HEALTHY,
        };

        $signals = [];
        if ($blockerCount > 0) {
            $signals[] = "{$blockerCount} scan blocker(s)";
        }
        if ($highRiskCount > 0) {
            $signals[] = "{$highRiskCount} high-risk finding(s)";
        }
        if ($queued > 0) {
            $signals[] = "{$queued} work order(s) queued at WIP limit";
        }
        if ($signals === []) {
            $signals[] = 'no open risk signals';
        }

        return [
            'overall' => $overall,
            'scan_status' => (string) ($report['area_map']['scan_status'] ?? 'unknown'),
            'findings_total' => count($findings),
            'blocker_count' => $blockerCount,
            'high_risk_count' => $highRiskCount,
            'queued_count' => $queued,
            'wip_pressure' => [
                'wip_used' => $wipUsed,
                'wip_limit' => $wipLimit,
            ],
            'signals' => $signals,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @param  array<string,int>  $routing
     * @return array<string,mixed>
     */
    private function findings(array $findings, array $routing): array
    {
        $byRisk = [];
        $items = [];
        foreach ($findings as $finding) {
            $risk = (string) ($finding['severity'] ?? 'unknown');
            $byRisk[$risk] = ($byRisk[$risk] ?? 0) + 1;
            $items[] = [
                'finding_hash' => (string) ($finding['finding_hash'] ?? ''),
                'title' => (string) ($finding['title'] ?? ''),
                'source' => (string) ($finding['source'] ?? ''),
                'source_owner' => (string) ($finding['source_owner'] ?? ''),
                'gap_kind' => (string) ($finding['gap_kind'] ?? ''),
                'risk_level' => $risk,
                'priority_score' => (int) ($finding['priority_score'] ?? 0),
                'route' => (string) ($finding['route'] ?? ''),
            ];
        }
        ksort($byRisk);

        return [
            'total' => count($findings),
            'by_risk' => $byRisk,
            'by_route' => $routing,
            'items' => $items,
        ];
    }

    /**
     * @param  array<string,mixed>  $morningInbox
     * @return list<array<string,mixed>>
     */
    private function inboxItems(array $morningInbox): array
    {
        $items = is_array($morningInbox['items'] ?? null) ? $morningInbox['items'] : [];
        $out = [];
        foreach ($items as $item) {
            $out[] = [
                'finding_hash' => (string) ($item['finding_hash'] ?? ''),
                'title' => (string) ($item['title'] ?? ''),
                'route' => (string) ($item['route'] ?? ''),
                'risk_level' => (string) ($item['risk_level'] ?? ''),
                'priority_score' => (int) ($item['priority_score'] ?? 0),
                'decision_required' => (bool) ($item['decision_required'] ?? true),
                'decision_options' => array_values((array) ($item['operator_actions'] ?? [])),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @return list<array<string,mixed>>
     */
    private function workOrders(array $findings): array
    {
        $orders = [];
        foreach ($findings as $finding) {
            $hash = (string) ($finding['finding_hash'] ?? '');
            $orders[] = [
                'work_order_id' => 'wo_'.substr($hash, 7, 16),
                'finding_hash' => $hash,
                'title' => (string) ($finding['title'] ?? ''),
                'route' => (string) ($finding['route'] ?? ''),
                'routes_to_owner_service' => $finding['routes_to_owner_service'] ?? null,
                'risk_level' => (string) ($finding['severity'] ?? ''),
                'priority_score' => (int) ($finding['priority_score'] ?? 0),
                'requires_branch_isolation' => (bool) ($finding['requires_branch_isolation'] ?? false),
                'operator_decision_required' => (bool) ($finding['operator_decision_required'] ?? true),
                'evidence_required' => (bool) ($finding['evidence_required'] ?? true),
                'execution_executed' => false,
                'status' => 'planned_pending_operator',
            ];
        }

        return $orders;
    }

    /**
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $budgetState
     * @param  array<string,int>  $routing
     * @return array<string,mixed>
     */
    private function budgets(array $contract, array $budgetState, array $routing): array
    {
        return [
            'dev_budget' => is_array($contract['dev_budget'] ?? null) ? $contract['dev_budget'] : [],
            'forge_budget' => is_array($contract['forge_budget'] ?? null) ? $contract['forge_budget'] : [],
            'wip_limit' => (int) ($budgetState['execution_wip_limit'] ?? 0),
            'wip_used' => (int) ($budgetState['wip_advised'] ?? 0),
            'dev_routed' => (int) ($routing['atlas_dev'] ?? 0),
            'forge_routed' => (int) ($routing['atlas_forge'] ?? 0),
            'queued' => (int) ($routing['queued'] ?? 0),
            'budget_consumed' => false,
            'execution_executed' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $evidenceReq
     * @return array<string,mixed>
     */
    private function evidencePacks(array $evidenceReq): array
    {
        return [
            'required' => (bool) ($evidenceReq['evidence_pack_required'] ?? true),
            'owner' => (string) ($evidenceReq['owner'] ?? 'atlas-evidence-certification-runtime'),
            'validations_required' => array_values((array) ($evidenceReq['validations_required'] ?? [])),
            'packs' => [],
            'note' => 'No evidence packs yet: read-only surface, nothing executed. Packs appear once work orders run under operator approval.',
        ];
    }

    /**
     * @param  array<string,mixed>  $governance
     * @return array<string,mixed>
     */
    private function killSwitchState(array $governance): array
    {
        return [
            'required' => (bool) ($governance['kill_switch_required'] ?? true),
            'engaged' => false,
            'controlled_by' => 'operator',
            'execution_enabled' => (bool) ($governance['execution_enabled'] ?? false),
            'note' => 'Kill switch is required by max_governed. Read-only surface has nothing running to stop; an operator-controlled runtime toggle arrives with execution slices.',
        ];
    }

    /**
     * @param  array<string,mixed>  $morningInbox
     * @param  array<string,int>  $routing
     * @param  list<array<string,mixed>>  $blockers
     * @return list<string>
     */
    private function nextActions(array $morningInbox, array $routing, int $highRiskCount, array $blockers): array
    {
        $actions = [];
        $decisions = (int) ($morningInbox['decision_count'] ?? 0);
        if ($decisions > 0) {
            $actions[] = "Review {$decisions} Morning Inbox decision(s) before any work is routed to execution.";
        }
        if ($highRiskCount > 0) {
            $actions[] = "Triage {$highRiskCount} high-risk finding(s).";
        }
        $queued = (int) ($routing['queued'] ?? 0);
        if ($queued > 0) {
            $actions[] = "WIP limit reached: {$queued} work order(s) queued. Raise WIP or clear active work before routing more.";
        }
        if ($blockers !== []) {
            $actions[] = count($blockers).' scan source(s) unavailable; resolve before trusting completeness.';
        }
        $actions[] = 'Execution is disabled in this read-only surface; approve work orders to route to Dev/Forge under branch isolation and evidence.';

        return $actions;
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     */
    private function highRiskCount(array $findings): int
    {
        $count = 0;
        foreach ($findings as $finding) {
            if (in_array((string) ($finding['severity'] ?? ''), ['high', 'critical'], true)) {
                $count++;
            }
        }

        return $count;
    }
}
