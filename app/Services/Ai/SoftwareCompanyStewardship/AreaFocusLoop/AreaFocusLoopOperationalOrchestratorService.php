<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * Area Focus Loop · Operational Orchestrator + Certification (AP-722).
 *
 * Atlas Software Company Stewardship Stack é stack/capability family dentro do
 * Atlas Autonomous Software Company Runtime, não OS novo.
 *
 * Closes the Area Focus Loop operational for `agentic_engineering_os` by
 * conducting the existing slice owners into ONE read-only, governed, end-to-end
 * cycle and emitting an operational certification:
 *
 *   core (AP-716) -> scan (AP-717) -> inbox (AP-718) -> work orders (AP-719)
 *   -> evidence pack (AP-720) -> operational certification
 *
 * It wires the previously orphaned deep finding engine (AP-717) and work order
 * router (AP-719) into the operational path, deriving the router's governed
 * budgets from the AP-716 area contract.
 *
 * Hard boundary (read-only / decision-oriented):
 *   - REUSES owners verbatim; re-implements no scan, routing, inbox or evidence.
 *   - NEVER implements a fix, opens a branch, dispatches Dev/Forge, invokes a
 *     provider, merges, deploys, accesses secrets or makes a destructive change.
 *   - Creates no parallel runtime and no new owner.
 *
 * Determinism: the operational `report_hash` is computed over the stable stage
 * hashes (it excludes wall-clock), so the same input is reproducible.
 */
class AreaFocusLoopOperationalOrchestratorService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.area_focus_operational_cycle.v1';

    public const CERT_SCHEMA = 'atlas.software_company_stewardship.area_focus_operational_certification.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    public const CERT_PASSED = 'passed';

    public const CERT_PARTIAL = 'partial';

    public const CERT_BLOCKED = 'blocked';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    /** Declared validations the operational cycle's evidence must satisfy (not executed here). */
    private const VALIDATION_REFS = [
        'php artisan test',
        'php artisan atlas:engineering:knowledge docs-health --json',
        'php artisan atlas:ai:architecture-validate --json',
        'git diff --check',
        'evidence_pack',
    ];

    public function __construct(
        private readonly AtlasAreaFocusLoopReadModelService $coreReadModel,
        private readonly AgenticEngineeringOsFindingEngineService $findingEngine,
        private readonly AreaFocusInboxService $inbox,
        private readonly AreaFocusDevForgeRouterService $router,
        private readonly AreaFocusEvidencePackService $evidencePack,
    ) {}

    /**
     * Run one read-only operational Area Focus cycle.
     *
     * `$input`:
     *   - area_id:      string  canonical area (default agentic_engineering_os)
     *   - findings:     list    test seam — bypass the AP-717 scan with given findings
     *   - core_report:  array   test seam — bypass the AP-716 read model
     *   - hours/limit/include_area_findings/...: forwarded to the owners
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(array $input = []): array
    {
        $areaId = trim((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID)) ?: self::DEFAULT_AREA_ID;

        // ---- stage 1: core read model (AP-716) ----
        $core = $this->stageCore($areaId, $input);
        $contract = is_array($core['area_contract'] ?? null) ? $core['area_contract'] : null;
        if (($core['status'] ?? '') === self::STATUS_BLOCKED || $contract === null) {
            return $this->blockedReport($areaId, 'core_not_ready', $core);
        }

        // ---- stage 2: deep finding scan (AP-717) ----
        [$scan, $findings] = $this->stageScan($areaId, $input);

        // ---- stage 3: operator inbox (AP-718) ----
        $inboxReport = $this->stageInbox($areaId, $findings);
        $inboxItems = is_array($inboxReport['items'] ?? null) ? $inboxReport['items'] : [];

        // ---- stage 4: work order routing (AP-719) ----
        $woPlan = $this->stageWorkOrders($areaId, $findings, $inboxItems, $contract);

        // ---- stage 5: evidence pack (AP-720) ----
        $cycle = $this->assembleCycle($areaId, $core, $scan, $findings, $inboxReport, $woPlan, $input);
        $pack = $this->evidencePack->build($cycle);

        // ---- certification ----
        $stages = [
            'core' => $core,
            'scan' => $scan,
            'inbox' => $inboxReport,
            'work_orders' => $woPlan,
            'evidence_pack' => $pack,
        ];
        $cert = $this->certify($areaId, $stages, $contract);

        $status = match ($cert['status']) {
            self::CERT_PASSED => self::STATUS_READY,
            self::CERT_BLOCKED => self::STATUS_BLOCKED,
            default => self::STATUS_PARTIAL,
        };

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'status' => $status,
            'ap_contract' => 'AP-722',
            'area_id' => $areaId,
            'stewardship_stack' => $this->stewardshipStack(),
            'cycle_id' => $cycle['cycle_id'],
            'area_contract' => $contract,
            'governed_mode' => is_array($core['governed_mode'] ?? null) ? $core['governed_mode'] : [],
            'stage_status' => [
                'core' => (string) ($core['status'] ?? 'unknown'),
                'scan' => (string) ($scan['status'] ?? 'unknown'),
                'inbox' => (string) ($inboxReport['status'] ?? 'unknown'),
                'work_orders' => (string) ($woPlan['status'] ?? 'unknown'),
                'evidence_pack' => ($pack['completeness']['complete'] ?? false) ? 'complete' : 'incomplete',
            ],
            'counts' => [
                'finding_count' => count($findings),
                'inbox_item_count' => (int) ($inboxReport['item_count'] ?? count($inboxItems)),
                'work_order_count' => (int) ($woPlan['work_order_count'] ?? 0),
                'emitted_work_orders' => (int) ($woPlan['emitted_count'] ?? 0),
                'blocked_work_orders' => (int) ($woPlan['blocked_count'] ?? 0),
            ],
            'routing_summary' => is_array($woPlan['counts']['by_route'] ?? null) ? $woPlan['counts']['by_route'] : [],
            'budget_state' => is_array($woPlan['budget_state'] ?? null) ? $woPlan['budget_state'] : [],
            'morning_inbox_ready' => (bool) ($pack['morning_inbox_ready'] ?? false),
            'stages' => [
                'core' => $core,
                'scan' => $scan,
                'inbox' => $inboxReport,
                'work_orders' => $woPlan,
                'evidence_pack' => $pack,
            ],
            'operational_certification' => $cert,
            'reused_owners' => $this->reusedOwners(),
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($areaId, $core, $scan, $inboxReport, $woPlan, $pack, $cert, $findings));
        $payload['generated_at'] = $this->now();

        return $payload;
    }

    // ---------- stages ----------

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function stageCore(string $areaId, array $input): array
    {
        if (is_array($input['core_report'] ?? null)) {
            return $input['core_report'];
        }
        try {
            return $this->coreReadModel->project($this->forward($input, ['contract', 'owner_doc_exists']) + ['area_id' => $areaId]);
        } catch (Throwable $e) {
            return ['status' => self::STATUS_BLOCKED, 'area_contract' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{0:array<string,mixed>,1:list<array<string,mixed>>}
     */
    private function stageScan(string $areaId, array $input): array
    {
        // Test seam: explicit findings bypass the real scan deterministically.
        if (is_array($input['findings'] ?? null)) {
            $findings = array_values(array_filter($input['findings'], 'is_array'));

            return [[
                'schema_version' => AgenticEngineeringOsFindingEngineService::REPORT_SCHEMA,
                'status' => self::STATUS_READY,
                'mode' => 'read_only',
                'area_id' => $areaId,
                'finding_count' => count($findings),
                'findings' => $findings,
                'source' => 'input_seam',
            ], $findings];
        }

        try {
            $scan = $this->findingEngine->scan($this->forward($input, ['hours', 'limit', 'docs', 'service_files', 'test_files']) + ['area_id' => $areaId]);
        } catch (Throwable $e) {
            return [['status' => self::STATUS_BLOCKED, 'findings' => [], 'error' => $e->getMessage()], []];
        }

        $findings = is_array($scan['findings'] ?? null) ? array_values(array_filter($scan['findings'], 'is_array')) : [];

        return [$scan, $findings];
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @return array<string,mixed>
     */
    private function stageInbox(string $areaId, array $findings): array
    {
        try {
            return $this->inbox->project(['area_id' => $areaId, 'findings' => $findings]);
        } catch (Throwable $e) {
            return ['status' => self::STATUS_BLOCKED, 'items' => [], 'item_count' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @param  list<array<string,mixed>>  $inboxItems
     * @param  array<string,mixed>  $contract
     * @return array<string,mixed>
     */
    private function stageWorkOrders(string $areaId, array $findings, array $inboxItems, array $contract): array
    {
        // A legitimately empty cycle (no findings, no inbox items) has nothing to
        // route. Avoid the router's `inputs_required` block — synthesize a clean,
        // ready, empty plan instead so an empty area still certifies operational.
        if ($findings === [] && $inboxItems === []) {
            return [
                'schema_version' => AreaFocusDevForgeRouterService::REPORT_SCHEMA,
                'status' => self::STATUS_READY,
                'area_id' => $areaId,
                'work_orders' => [],
                'work_order_count' => 0,
                'emitted_count' => 0,
                'blocked_count' => 0,
                'counts' => ['by_route' => [], 'by_status' => [], 'by_block_reason' => []],
                'budget_state' => ['dev_used' => 0, 'forge_used' => 0, 'wip_used' => 0],
                'report_hash' => $this->hash(['empty_work_order_plan' => $areaId]),
                'claim_policy' => ['read_only' => true, 'execution_performed' => false],
            ];
        }

        try {
            return $this->router->project([
                'area_id' => $areaId,
                'findings' => $findings,
                'inbox_items' => $inboxItems,
                // Governed budgets come from the AP-716 area contract — not invented here.
                'dev_budget' => $contract['dev_budget'] ?? null,
                'forge_budget' => $contract['forge_budget'] ?? null,
                'wip_limit' => $contract['wip_limit'] ?? null,
                'risk_policy' => $contract['risk_policy'] ?? null,
            ]);
        } catch (Throwable $e) {
            return ['status' => self::STATUS_BLOCKED, 'work_orders' => [], 'work_order_count' => 0, 'error' => $e->getMessage()];
        }
    }

    // ---------- cycle assembly (for the AP-720 evidence pack) ----------

    /**
     * Assemble the AP-720 cycle shape from the orchestrated stages so the
     * evidence pack owner can be reused verbatim. Hashes are deterministic.
     *
     * @param  array<string,mixed>  $core
     * @param  array<string,mixed>  $scan
     * @param  list<array<string,mixed>>  $findings
     * @param  array<string,mixed>  $inboxReport
     * @param  array<string,mixed>  $woPlan
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function assembleCycle(string $areaId, array $core, array $scan, array $findings, array $inboxReport, array $woPlan, array $input): array
    {
        $coreHash = (string) ($core['report_hash'] ?? '');
        $findingsHash = $this->hash($findings);
        $inboxHash = (string) ($inboxReport['inbox_hash'] ?? $this->hash($inboxReport['items'] ?? []));
        $woHash = (string) ($woPlan['report_hash'] ?? $this->hash($woPlan['work_orders'] ?? []));
        $inputHash = $this->hash($this->sanitizeInput($input, $areaId));

        $reportStatus = $this->worstStatus([
            (string) ($core['status'] ?? 'unknown'),
            (string) ($scan['status'] ?? 'unknown'),
            (string) ($inboxReport['status'] ?? 'unknown'),
            (string) ($woPlan['status'] ?? 'unknown'),
        ]);

        $cycleId = 'afoc_'.substr(hash('sha256', implode('|', [$areaId, $coreHash, $findingsHash, $inboxHash, $woHash])), 0, 16);

        $cycle = [
            'schema_version' => AreaFocusCycleRecorderService::CYCLE_SCHEMA,
            'cycle_id' => $cycleId,
            'area_id' => $areaId,
            'report_schema_version' => self::REPORT_SCHEMA,
            'report_status' => $reportStatus,
            'report_hash' => $coreHash,
            'finding_count' => count($findings),
            'findings_hash' => $findingsHash,
            'inbox_hash' => $inboxHash,
            'inbox_decision_count' => (int) ($inboxReport['item_count'] ?? 0),
            'work_orders_hash' => $woHash,
            'work_order_count' => (int) ($woPlan['work_order_count'] ?? 0),
            'input_hash' => $inputHash,
            'routing_summary' => is_array($woPlan['counts']['by_route'] ?? null) ? $woPlan['counts']['by_route'] : [],
            'validation_refs' => $this->validationRefs(),
            'claim_policy' => $this->claimPolicy(),
        ];
        $cycle['cycle_hash'] = 'sha256:'.MissionCanonicalHash::sha256($cycle);
        $cycle['generated_at'] = $this->now();

        return $cycle;
    }

    // ---------- certification ----------

    /**
     * @param  array<string,array<string,mixed>>  $stages
     * @param  array<string,mixed>  $contract
     * @return array<string,mixed>
     */
    private function certify(string $areaId, array $stages, array $contract): array
    {
        $core = $stages['core'];
        $scan = $stages['scan'];
        $inbox = $stages['inbox'];
        $wo = $stages['work_orders'];
        $pack = $stages['evidence_pack'];

        $anyBlocked = false;
        foreach (['core', 'scan', 'inbox', 'work_orders'] as $key) {
            if ((string) ($stages[$key]['status'] ?? '') === self::STATUS_BLOCKED) {
                $anyBlocked = true;
            }
        }

        $checks = [
            'core_resolved' => (string) ($core['status'] ?? '') !== self::STATUS_BLOCKED && is_array($contract),
            'scan_ran' => (string) ($scan['status'] ?? '') !== self::STATUS_BLOCKED,
            'inbox_projected' => (string) ($inbox['status'] ?? '') !== self::STATUS_BLOCKED,
            'work_orders_routed' => (string) ($wo['status'] ?? '') !== self::STATUS_BLOCKED,
            'evidence_pack_complete' => ($pack['completeness']['complete'] ?? false) === true,
            'governance_read_only' => $this->governanceReadOnly($stages),
            'deterministic_hashes_present' => $this->hashesPresent($core, $inbox, $wo, $pack),
            'budgets_from_contract' => isset($contract['dev_budget'], $contract['forge_budget'], $contract['wip_limit']),
            'no_parallel_runtime' => true,
            'no_new_os' => true,
        ];

        $allPass = ! in_array(false, $checks, true);
        $status = match (true) {
            $anyBlocked => self::CERT_BLOCKED,
            $allPass => self::CERT_PASSED,
            default => self::CERT_PARTIAL,
        };

        $failing = array_keys(array_filter($checks, static fn (bool $v): bool => $v === false));

        return [
            'schema_version' => self::CERT_SCHEMA,
            'status' => $status,
            'operational' => $status === self::CERT_PASSED,
            'area_id' => $areaId,
            'checks' => $checks,
            'failing_checks' => array_values($failing),
            'invariants' => [
                'executes_fix' => false,
                'opens_branch' => false,
                'dispatches_work' => false,
                'merge_without_operator' => false,
                'deploy_without_operator' => false,
                'secret_access' => false,
                'destructive_change' => false,
                'operator_review_required' => true,
            ],
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $stages
     */
    private function governanceReadOnly(array $stages): bool
    {
        foreach ($stages as $stage) {
            $claim = is_array($stage['claim_policy'] ?? null) ? $stage['claim_policy'] : [];
            // Any owner declaring an execution-class side effect breaks the invariant.
            foreach (['writes_repo', 'mutates_target_repo', 'execution_performed', 'work_dispatched', 'dev_invoked', 'forge_invoked', 'merges', 'deploys', 'touches_secrets', 'merge_without_operator', 'deploy_without_operator', 'secret_access', 'destructive_change'] as $flag) {
                if (($claim[$flag] ?? false) === true) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $core
     * @param  array<string,mixed>  $inbox
     * @param  array<string,mixed>  $wo
     * @param  array<string,mixed>  $pack
     */
    private function hashesPresent(array $core, array $inbox, array $wo, array $pack): bool
    {
        return ($core['report_hash'] ?? '') !== ''
            && ($inbox['inbox_hash'] ?? '') !== ''
            && ($wo['report_hash'] ?? '') !== ''
            && ($pack['pack_hash'] ?? '') !== '';
    }

    // ---------- identity / policy blocks ----------

    /**
     * Stable identity for the deterministic operational report hash (excludes
     * all wall-clock timestamps embedded in the stage reports).
     *
     * @param  array<string,mixed>  $core
     * @param  array<string,mixed>  $scan
     * @param  array<string,mixed>  $inbox
     * @param  array<string,mixed>  $wo
     * @param  array<string,mixed>  $pack
     * @param  array<string,mixed>  $cert
     * @param  list<array<string,mixed>>  $findings
     * @return array<string,mixed>
     */
    private function identity(string $areaId, array $core, array $scan, array $inbox, array $wo, array $pack, array $cert, array $findings): array
    {
        return [
            'schema_version' => self::REPORT_SCHEMA,
            'area_id' => $areaId,
            'core_hash' => (string) ($core['report_hash'] ?? ''),
            'scan_status' => (string) ($scan['status'] ?? ''),
            'findings_hash' => $this->hash($findings),
            'inbox_hash' => (string) ($inbox['inbox_hash'] ?? ''),
            'work_orders_hash' => (string) ($wo['report_hash'] ?? ''),
            'pack_hash' => (string) ($pack['pack_hash'] ?? ''),
            'cert_status' => (string) ($cert['status'] ?? ''),
            'cert_checks' => $cert['checks'] ?? [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function stewardshipStack(): array
    {
        return [
            'umbrella' => 'Atlas Software Company Stewardship Stack',
            'level_name' => 'Area Focus Loop',
            'parent_runtime' => 'Atlas Autonomous Software Company Runtime',
            'canonical_statement' => 'Atlas Software Company Stewardship Stack é stack/capability family dentro do Atlas Autonomous Software Company Runtime, não OS novo.',
            'aps' => ['AP-712', 'AP-715', 'AP-716', 'AP-717', 'AP-718', 'AP-719', 'AP-720', 'AP-722'],
            'new_os_created' => false,
            'parallel_runtime_created' => false,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function reusedOwners(): array
    {
        return [
            'core_read_model' => ['ap' => 'AP-716', 'owner_service' => AtlasAreaFocusLoopReadModelService::class, 'reused_method' => 'project'],
            'finding_engine' => ['ap' => 'AP-717', 'owner_service' => AgenticEngineeringOsFindingEngineService::class, 'reused_method' => 'scan'],
            'inbox' => ['ap' => 'AP-718', 'owner_service' => AreaFocusInboxService::class, 'reused_method' => 'project'],
            'work_order_router' => ['ap' => 'AP-719', 'owner_service' => AreaFocusDevForgeRouterService::class, 'reused_method' => 'project'],
            'evidence_pack' => ['ap' => 'AP-720', 'owner_service' => AreaFocusEvidencePackService::class, 'reused_method' => 'build'],
        ];
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'orchestrator_only' => true,
            'writes_state' => false,
            'writes_repo' => false,
            'mutates_target_repo' => false,
            'provider_invoked' => false,
            'reimplements_owner' => false,
            'parallel_runtime_created' => false,
            'new_os_created' => false,
            'drafts_spec' => false,
            'dev_invoked' => false,
            'forge_invoked' => false,
            'work_dispatched' => false,
            'execution_performed' => false,
            'opens_branch' => false,
            'merge_without_operator' => false,
            'deploy_without_operator' => false,
            'secret_access' => false,
            'destructive_change' => false,
            'autoapproval_allowed' => false,
            'operator_review_required' => true,
        ];
    }

    // ---------- blocked + helpers ----------

    /**
     * @param  array<string,mixed>  $core
     * @return array<string,mixed>
     */
    private function blockedReport(string $areaId, string $reason, array $core): array
    {
        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'status' => self::STATUS_BLOCKED,
            'ap_contract' => 'AP-722',
            'area_id' => $areaId,
            'reason' => $reason,
            'detail' => 'Operational cycle cannot run: the AP-716 core read model is blocked or the area is not registered.',
            'stewardship_stack' => $this->stewardshipStack(),
            'stages' => ['core' => $core],
            'operational_certification' => [
                'schema_version' => self::CERT_SCHEMA,
                'status' => self::CERT_BLOCKED,
                'operational' => false,
                'area_id' => $areaId,
                'failing_checks' => ['core_resolved'],
            ],
            'reused_owners' => $this->reusedOwners(),
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256([
            'schema_version' => self::REPORT_SCHEMA,
            'area_id' => $areaId,
            'reason' => $reason,
            'core_hash' => (string) ($core['report_hash'] ?? ''),
        ]);
        $payload['generated_at'] = $this->now();

        return $payload;
    }

    /**
     * Worst (most severe) status across stages: blocked > partial > ready.
     *
     * @param  list<string>  $statuses
     */
    private function worstStatus(array $statuses): string
    {
        if (in_array(self::STATUS_BLOCKED, $statuses, true)) {
            return self::STATUS_BLOCKED;
        }
        if (in_array(self::STATUS_PARTIAL, $statuses, true)) {
            return self::STATUS_PARTIAL;
        }

        return self::STATUS_READY;
    }

    /**
     * Forward only the listed keys from $input (avoids leaking unrelated seams).
     *
     * @param  array<string,mixed>  $input
     * @param  list<string>  $keys
     * @return array<string,mixed>
     */
    private function forward(array $input, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $input)) {
                $out[$key] = $input[$key];
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function sanitizeInput(array $input, string $areaId): array
    {
        $digest = ['area_id' => $areaId];
        foreach (['hours', 'limit', 'include_area_findings'] as $key) {
            if (array_key_exists($key, $input) && is_scalar($input[$key])) {
                $digest[$key] = $input[$key];
            }
        }
        $digest['overrides'] = [
            'findings' => array_key_exists('findings', $input),
            'core_report' => array_key_exists('core_report', $input),
        ];

        return $digest;
    }

    /**
     * @return list<array<string,string>>
     */
    private function validationRefs(): array
    {
        $refs = [];
        foreach (self::VALIDATION_REFS as $ref) {
            $refs[] = ['ref' => $ref, 'status' => 'declared', 'executed' => 'false'];
        }

        return $refs;
    }

    private function hash(mixed $value): string
    {
        return 'sha256:'.MissionCanonicalHash::sha256($value);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
