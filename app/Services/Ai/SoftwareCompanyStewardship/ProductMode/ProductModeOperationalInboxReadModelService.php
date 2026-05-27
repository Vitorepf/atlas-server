<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\ProductMode;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FirstFullCycleOrchestratorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchSystemCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\ContinuousStewardshipDayReadinessService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\ContinuousStewardshipDayStartService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\ContinuousStewardshipRunnerService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipRuntimeResultBridgeService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * Product Mode + Morning/Operational Inbox visibility (AP-754/AP-765/AP-766/AP-768/AP-776/AP-777).
 *
 * Read-only aggregate for operator surfaces. Composes stewardship loop receipts and
 * gates into reviewable inbox items with honest empty/degraded states. Never invokes
 * providers, Dev/Forge, schedulers, branches or secrets.
 */
final class ProductModeOperationalInboxReadModelService
{
    public const SCHEMA = 'atlas.software_company.product_mode_operational_inbox.v1';

    public const ITEM_SCHEMA = 'atlas.software_company.stewardship_operational_inbox_item.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_EMPTY = 'empty';

    public const STATUS_DEGRADED = 'degraded';

    public const DEFAULT_BUDGET_MS = 1800;

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    public const DEFAULT_PORTFOLIO_ID = 'atlas_software_company';

    public function __construct(
        private readonly ContinuousStewardshipDayReadinessService $readiness,
        private readonly ContinuousStewardshipRunnerService $runner,
        private readonly ContinuousStewardshipDayStartService $dayStart,
        private readonly StewardshipBranchSystemCertificationService $branchCertification,
        private readonly ProductModeOperationalControlsReadModelService $controls,
        private readonly ProductModeOperationalControlReceiptService $controlReceipts,
        private readonly ProductModeRuntimeResultEventService $runtimeEvents,
        private readonly FirstFullCycleOrchestratorService $firstCycle,
        private readonly StewardshipRuntimeResultBridgeService $runtimeBridge,
    ) {}

    public function setStorageRootForTesting(?string $dir): void
    {
        $root = $dir !== null ? rtrim($dir, DIRECTORY_SEPARATOR) : null;
        $this->readiness->setStorageRootForTesting($root !== null ? $root.'/readiness' : null);
        $this->runner->setStorageRootForTesting($root !== null ? $root.'/runner' : null);
        $this->dayStart->setStorageRootForTesting($root);
        $this->runtimeBridge->setStorageRootForTesting($root);
        $this->runtimeEvents->setStorageRootForTesting($root !== null ? $root.'/pm_events' : null);
        $this->firstCycle->setStorageRootForTesting($root !== null ? $root.'/first_cycle' : null);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(string $areaId = self::DEFAULT_AREA_ID, string $portfolioId = self::DEFAULT_PORTFOLIO_ID, array $input = []): array
    {
        $started = microtime(true);
        $budgetMs = array_key_exists('budget_ms', $input)
            ? max(0, (int) $input['budget_ms'])
            : self::DEFAULT_BUDGET_MS;
        $areaId = $this->slug($areaId);
        $portfolioId = $this->nonEmpty($portfolioId, self::DEFAULT_PORTFOLIO_ID);
        $repoRoot = trim((string) ($input['repo_root'] ?? ''));
        if ($repoRoot === '' && function_exists('base_path')) {
            $repoRoot = base_path();
        }

        $sources = [];
        $failedSources = [];
        $items = [];

        $readiness = $this->loadSource($sources, $failedSources, 'ap_777_readiness', function () use ($areaId, $repoRoot, $input): array {
            return $this->readiness->assess([
                'area_id' => $areaId,
                'repo_root' => $repoRoot,
                'enabled' => (bool) ($input['continuous_runner_enabled'] ?? $input['enabled'] ?? false),
                'duration_hours' => (int) ($input['duration_hours'] ?? 24),
                'global_kill_switch' => (bool) ($input['kill_switch'] ?? $input['global_kill_switch'] ?? false),
                'area_kill_switch' => (bool) ($input['area_kill_switch'] ?? false),
                'max_runs_per_day' => (int) ($input['max_runs_per_day'] ?? ContinuousStewardshipRunnerService::DEFAULT_MAX_RUNS_PER_DAY),
                'min_interval_seconds' => (int) ($input['min_interval_seconds'] ?? ContinuousStewardshipRunnerService::DEFAULT_MIN_INTERVAL_SECONDS),
                'lock_ttl_seconds' => (int) ($input['lock_ttl_seconds'] ?? ContinuousStewardshipRunnerService::DEFAULT_LOCK_TTL_SECONDS),
            ]);
        }, $started, $budgetMs);
        if ($readiness !== null) {
            $items = array_merge($items, $this->itemsFromReadiness($areaId, $portfolioId, $readiness));
        }

        $runner = $this->loadSource($sources, $failedSources, 'ap_766_runner', function () use ($areaId, $input): array {
            return $this->runner->status([
                'area_id' => $areaId,
                'enabled' => (bool) ($input['continuous_runner_enabled'] ?? $input['enabled'] ?? false),
                'global_kill_switch' => (bool) ($input['kill_switch'] ?? $input['global_kill_switch'] ?? false),
                'area_kill_switch' => (bool) ($input['area_kill_switch'] ?? false),
                'max_runs_per_day' => (int) ($input['max_runs_per_day'] ?? ContinuousStewardshipRunnerService::DEFAULT_MAX_RUNS_PER_DAY),
                'min_interval_seconds' => (int) ($input['min_interval_seconds'] ?? ContinuousStewardshipRunnerService::DEFAULT_MIN_INTERVAL_SECONDS),
                'lock_ttl_seconds' => (int) ($input['lock_ttl_seconds'] ?? ContinuousStewardshipRunnerService::DEFAULT_LOCK_TTL_SECONDS),
            ]);
        }, $started, $budgetMs);
        if ($runner !== null) {
            $items = array_merge($items, $this->itemsFromRunner($areaId, $portfolioId, $runner));
        }

        $branchCert = $this->loadSource($sources, $failedSources, 'ap_776_branch_cert', function () use ($areaId, $repoRoot): array {
            return $this->branchCertification->certify([
                'area_id' => $areaId,
                'repo_root' => $repoRoot,
            ]);
        }, $started, $budgetMs);
        if ($branchCert !== null) {
            $items = array_merge($items, $this->itemsFromBranchCert($areaId, $portfolioId, $branchCert));
        }

        $controls = $this->loadSource($sources, $failedSources, 'ap_754_controls', function () use ($areaId, $portfolioId, $input): array {
            return $this->controls->project($areaId, $portfolioId, $input);
        }, $started, $budgetMs);
        if ($controls !== null) {
            $items = array_merge($items, $this->itemsFromControls($areaId, $portfolioId, $controls));
        }

        $receipts = $this->loadSource($sources, $failedSources, 'ap_755_receipts', fn (): array => $this->controlReceipts->listReceipts($areaId, $portfolioId), $started, $budgetMs);

        $dayStarts = $this->loadSource($sources, $failedSources, 'ap_778_day_start', fn (): array => [
            'records' => $this->dayStart->list(['area_id' => $areaId]),
        ], $started, $budgetMs);

        $firstCycles = $this->loadSource($sources, $failedSources, 'ap_768_first_cycle', fn (): array => $this->firstCycle->listCycles($areaId), $started, $budgetMs);
        if ($firstCycles !== null) {
            $items = array_merge($items, $this->itemsFromFirstCycles($areaId, $portfolioId, $firstCycles));
        }

        $runtimeEvents = $this->loadSource($sources, $failedSources, 'ap_765_runtime_events', fn (): array => $this->runtimeEvents->list($areaId), $started, $budgetMs);
        if ($runtimeEvents !== null) {
            $items = array_merge($items, $this->itemsFromRuntimeEvents($areaId, $portfolioId, $runtimeEvents));
        }

        $bridgeRecords = $this->loadSource($sources, $failedSources, 'ap_765_runtime_bridge', fn (): array => [
            'records' => $this->tailJsonl($this->runtimeBridge->bridgeFilePath($areaId), 5),
        ], $started, $budgetMs);

        $items = $this->dedupeItems($items);
        $counters = $this->counters($items);
        $latestReceipts = $this->latestReceipts($runner, $dayStarts, $firstCycles, $bridgeRecords, $receipts);

        $elapsedMs = (int) round((microtime(true) - $started) * 1000);
        $degraded = $failedSources !== [];
        $empty = $items === [];

        $status = $degraded ? self::STATUS_DEGRADED : ($empty ? self::STATUS_EMPTY : self::STATUS_READY);

        $payload = [
            'schema_version' => self::SCHEMA,
            'ap_contract' => 'AP-754',
            'status' => $status,
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'read_only' => true,
            'projection_mode' => 'operational_inbox_aggregate',
            'source_ap_contracts' => ['AP-754', 'AP-755', 'AP-765', 'AP-766', 'AP-768', 'AP-776', 'AP-777', 'AP-778'],
            'sources' => $sources,
            'items' => $items,
            'item_count' => count($items),
            'counters' => $counters,
            'latest_receipts' => $latestReceipts,
            'blockers' => $this->collectBlockers($readiness, $runner, $controls, $branchCert),
            'next_actions' => $this->collectNextActions($readiness, $runner, $controls),
            'empty_state' => $empty ? $this->emptyState($degraded, $failedSources) : null,
            'degraded_state' => $degraded ? [
                'failed_sources' => $failedSources,
                'partial_projection' => $items !== [],
                'message' => 'One or more stewardship sources failed; items below are partial and honest.',
            ] : null,
            'timing' => [
                'budget_ms' => $budgetMs,
                'elapsed_ms' => $elapsedMs,
                'within_budget' => $elapsedMs <= $budgetMs,
            ],
            'claim_policy' => $this->claimPolicy(),
        ];

        return $this->finalize($payload);
    }

    /**
     * @param  array<string,mixed>  $readiness
     * @return list<array<string,mixed>>
     */
    private function itemsFromReadiness(string $areaId, string $portfolioId, array $readiness): array
    {
        $items = [];
        $status = (string) ($readiness['status'] ?? '');
        $blockers = array_values(array_filter((array) ($readiness['blockers'] ?? []), 'is_string'));

        if ($status === ContinuousStewardshipDayReadinessService::STATUS_BLOCKED) {
            $items[] = $this->item(
                kind: 'continuous_24h_readiness_blocked',
                bucket: 'alert',
                cycleState: 'blocked',
                title: '24h stewardship loop is not ready to start',
                summary: $blockers === [] ? 'AP-777 readiness gate is blocked.' : 'Blockers: '.implode(', ', $blockers),
                areaId: $areaId,
                portfolioId: $portfolioId,
                sourceAp: 'AP-777',
                dedupeKey: 'stewardship:ap777:blocked:'.$areaId,
                payload: ['readiness' => $readiness],
            );
            foreach ($blockers as $blocker) {
                if (str_contains($blocker, 'kill_switch')) {
                    $items[] = $this->item(
                        kind: 'kill_switch_active',
                        bucket: 'alert',
                        cycleState: 'blocked',
                        title: 'Kill switch is active',
                        summary: 'Continuous stewardship cannot run until the kill switch is cleared by the operator.',
                        areaId: $areaId,
                        portfolioId: $portfolioId,
                        sourceAp: 'AP-777',
                        dedupeKey: 'stewardship:kill_switch:'.$areaId.':'.$blocker,
                        payload: ['blocker' => $blocker],
                    );
                }
                if (str_contains($blocker, 'rate') || str_contains($blocker, 'budget')) {
                    $items[] = $this->item(
                        kind: 'rate_limited_or_budget_blocked',
                        bucket: 'alert',
                        cycleState: 'rate_limited',
                        title: 'Runner budget or rate policy blocks admission',
                        summary: $blocker,
                        areaId: $areaId,
                        portfolioId: $portfolioId,
                        sourceAp: 'AP-766',
                        dedupeKey: 'stewardship:rate_budget:'.$areaId.':'.$blocker,
                        payload: ['blocker' => $blocker],
                    );
                }
            }
        } elseif ($status === ContinuousStewardshipDayReadinessService::STATUS_READY) {
            $items[] = $this->item(
                kind: 'continuous_24h_readiness_ready',
                bucket: 'recommendation',
                cycleState: 'ready',
                title: '24h stewardship loop is ready to start',
                summary: 'AP-777 reports all required proofs for an operator-observed 24h run.',
                areaId: $areaId,
                portfolioId: $portfolioId,
                sourceAp: 'AP-777',
                dedupeKey: 'stewardship:ap777:ready:'.$areaId,
                payload: [
                    'operator_start_command' => (string) ($readiness['operator_start_command'] ?? ''),
                ],
            );
        }

        return $items;
    }

    /**
     * @param  array<string,mixed>  $runner
     * @return list<array<string,mixed>>
     */
    private function itemsFromRunner(string $areaId, string $portfolioId, array $runner): array
    {
        $items = [];
        $kill = (array) ($runner['kill_switch_status'] ?? []);
        if ((bool) ($kill['global_active'] ?? $kill['active'] ?? false)) {
            $items[] = $this->item(
                kind: 'kill_switch_active',
                bucket: 'alert',
                cycleState: 'blocked',
                title: 'Continuous runner kill switch is active',
                summary: (string) ($kill['detail'] ?? 'Global or area kill switch blocks runner admission.'),
                areaId: $areaId,
                portfolioId: $portfolioId,
                sourceAp: 'AP-766',
                dedupeKey: 'stewardship:ap766:kill_switch:'.$areaId,
                payload: ['kill_switch_status' => $kill],
            );
        }

        $budget = (array) ($runner['budget_status'] ?? []);
        if (($budget['status'] ?? '') === 'exhausted' || ($runner['status'] ?? '') === ContinuousStewardshipRunnerService::STATUS_BUDGET_EXHAUSTED) {
            $items[] = $this->item(
                kind: 'rate_limited',
                bucket: 'alert',
                cycleState: 'rate_limited',
                title: 'Daily runner budget exhausted',
                summary: 'No more AP-766 ticks are admitted until the budget resets or policy changes.',
                areaId: $areaId,
                portfolioId: $portfolioId,
                sourceAp: 'AP-766',
                dedupeKey: 'stewardship:ap766:budget_exhausted:'.$areaId,
                payload: ['budget_status' => $budget],
            );
        }

        $lastRun = is_array($runner['last_run'] ?? null) ? $runner['last_run'] : null;
        if ($lastRun !== null && (bool) ($lastRun['tick_admitted'] ?? false)) {
            $items[] = $this->item(
                kind: 'continuous_runner_tick_executed',
                bucket: 'insight',
                cycleState: 'executed',
                title: 'Continuous runner executed a tick',
                summary: 'Last runner receipt: '.(string) ($lastRun['status'] ?? 'ran').' · tick '.(string) ($lastRun['tick_status'] ?? ''),
                areaId: $areaId,
                portfolioId: $portfolioId,
                sourceAp: 'AP-766',
                dedupeKey: 'stewardship:ap766:executed:'.(string) ($lastRun['runner_run_id'] ?? ''),
                payload: ['last_run' => $lastRun],
            );
        } elseif (($runner['status'] ?? '') === ContinuousStewardshipRunnerService::STATUS_BLOCKED) {
            $items[] = $this->item(
                kind: 'continuous_runner_blocked',
                bucket: 'alert',
                cycleState: 'blocked',
                title: 'Continuous runner is blocked',
                summary: implode(', ', array_values(array_filter((array) ($runner['blockers'] ?? []), 'is_string'))),
                areaId: $areaId,
                portfolioId: $portfolioId,
                sourceAp: 'AP-766',
                dedupeKey: 'stewardship:ap766:blocked:'.$areaId,
                payload: ['runner_status' => $runner],
            );
        }

        return $items;
    }

    /**
     * @param  array<string,mixed>  $branchCert
     * @return list<array<string,mixed>>
     */
    private function itemsFromBranchCert(string $areaId, string $portfolioId, array $branchCert): array
    {
        if (($branchCert['status'] ?? '') === StewardshipBranchSystemCertificationService::STATUS_CERTIFIED) {
            return [];
        }

        return [
            $this->item(
                kind: 'branch_review_required',
                bucket: 'recommendation',
                cycleState: 'blocked',
                title: 'Branch system certification required before merge queue',
                summary: 'AP-776 is not certified: '.implode(', ', array_values(array_filter((array) ($branchCert['blockers'] ?? []), 'is_string'))),
                areaId: $areaId,
                portfolioId: $portfolioId,
                sourceAp: 'AP-776',
                dedupeKey: 'stewardship:ap776:not_certified:'.$areaId,
                payload: ['branch_system_certification' => $branchCert],
            ),
        ];
    }

    /**
     * @param  array<string,mixed>  $controls
     * @return list<array<string,mixed>>
     */
    private function itemsFromControls(string $areaId, string $portfolioId, array $controls): array
    {
        $items = [];
        $safety = (array) ($controls['safety_controls'] ?? []);
        if ((bool) ($safety['kill_switch_active'] ?? false)) {
            $items[] = $this->item(
                kind: 'product_mode_kill_switch_active',
                bucket: 'alert',
                cycleState: 'blocked',
                title: 'Product Mode kill switch is active',
                summary: 'Night Shift Product Mode safety control blocks stewardship admission.',
                areaId: $areaId,
                portfolioId: $portfolioId,
                sourceAp: 'AP-754',
                dedupeKey: 'stewardship:ap754:kill_switch:'.$areaId,
                payload: ['safety_controls' => $safety],
            );
        }
        if ((bool) ($safety['rate_limited'] ?? false)) {
            $items[] = $this->item(
                kind: 'product_mode_rate_limited',
                bucket: 'alert',
                cycleState: 'rate_limited',
                title: 'Product Mode is rate limited',
                summary: 'Operator policy is throttling Product Mode cycles.',
                areaId: $areaId,
                portfolioId: $portfolioId,
                sourceAp: 'AP-754',
                dedupeKey: 'stewardship:ap754:rate_limited:'.$areaId,
                payload: ['safety_controls' => $safety],
            );
        }

        $branchReview = (array) ($controls['branch_review_center'] ?? []);
        $pending = (int) ($branchReview['pending_review_count'] ?? 0);
        if ($pending > 0) {
            $items[] = $this->item(
                kind: 'branch_review_required',
                bucket: 'recommendation',
                cycleState: 'ready',
                title: 'Branches pending operator review',
                summary: $pending.' branch(es) need review before merge or deploy decisions.',
                areaId: $areaId,
                portfolioId: $portfolioId,
                sourceAp: 'AP-754',
                dedupeKey: 'stewardship:ap754:branch_review:'.$areaId,
                payload: ['branch_review_center' => $branchReview],
            );
        }

        return $items;
    }

    /**
     * @param  array<string,mixed>  $cycles
     * @return list<array<string,mixed>>
     */
    private function itemsFromFirstCycles(string $areaId, string $portfolioId, array $cycles): array
    {
        $items = [];
        $list = array_values(array_filter((array) ($cycles['cycles'] ?? []), 'is_array'));
        if ($list === []) {
            return [];
        }

        $latest = $list[array_key_last($list)];
        $final = (string) ($latest['final_status'] ?? '');
        if ($final === FirstFullCycleOrchestratorService::STATUS_CYCLE_CLOSED || $final === FirstFullCycleOrchestratorService::STATUS_EXECUTED_TO_GATE) {
            $items[] = $this->item(
                kind: 'first_full_cycle_executed',
                bucket: 'insight',
                cycleState: 'executed',
                title: 'First full stewardship cycle recorded',
                summary: 'AP-768 cycle '.$final.' · '.(string) ($latest['cycle_id'] ?? ''),
                areaId: $areaId,
                portfolioId: $portfolioId,
                sourceAp: 'AP-768',
                dedupeKey: 'stewardship:ap768:cycle:'.(string) ($latest['cycle_id'] ?? ''),
                payload: ['cycle' => $latest],
            );
        }

        $replay = $this->firstCycle->replay((string) ($latest['cycle_id'] ?? ''), $areaId);
        if (is_array($replay)) {
            foreach ((array) ($replay['stages'] ?? []) as $stageName => $stage) {
                if (! is_array($stage) || ($stage['status'] ?? '') !== FirstFullCycleOrchestratorService::STAGE_DEFERRED) {
                    continue;
                }
                $ap = (string) ($stage['ap_contract'] ?? '');
                if (! str_contains($ap, '767') && ! str_contains((string) $stageName, 'dev_forge')) {
                    continue;
                }
                $items[] = $this->item(
                    kind: 'dev_forge_deferred',
                    bucket: 'insight',
                    cycleState: 'deferred',
                    title: 'Dev/Forge execution deferred by governance',
                    summary: (string) ($stage['detail'] ?? 'AP-767 stage deferred; operator must supply execution_result.'),
                    areaId: $areaId,
                    portfolioId: $portfolioId,
                    sourceAp: 'AP-767',
                    dedupeKey: 'stewardship:ap767:deferred:'.(string) ($latest['cycle_id'] ?? '').':'.$stageName,
                    payload: ['stage' => $stage],
                );
            }
        }

        return $items;
    }

    /**
     * @param  array<string,mixed>  $runtimeEvents
     * @return list<array<string,mixed>>
     */
    private function itemsFromRuntimeEvents(string $areaId, string $portfolioId, array $runtimeEvents): array
    {
        $items = [];
        foreach (array_slice(array_reverse(array_values(array_filter((array) ($runtimeEvents['events'] ?? []), 'is_array'))), 0, 5) as $event) {
            $items[] = $this->item(
                kind: 'runtime_result_evidence_emitted',
                bucket: 'insight',
                cycleState: 'executed',
                title: 'Runtime result visible in Product Mode',
                summary: 'Owner '.(string) ($event['owner'] ?? '').' · status '.(string) ($event['result_status'] ?? ''),
                areaId: $areaId,
                portfolioId: $portfolioId,
                sourceAp: 'AP-765',
                dedupeKey: 'stewardship:ap765:event:'.(string) ($event['event_id'] ?? ''),
                payload: ['event' => $event],
            );
        }

        return $items;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return array<string,int>
     */
    private function counters(array $items): array
    {
        $counters = [
            'approvals' => 0,
            'recommendations' => 0,
            'insights' => 0,
            'alerts' => 0,
            'blocked' => 0,
            'executed' => 0,
            'deferred' => 0,
        ];

        foreach ($items as $item) {
            $bucket = (string) ($item['bucket'] ?? '');
            if ($bucket === 'approval') {
                $counters['approvals']++;
            } elseif ($bucket === 'recommendation') {
                $counters['recommendations']++;
            } elseif ($bucket === 'insight') {
                $counters['insights']++;
            } elseif ($bucket === 'alert') {
                $counters['alerts']++;
            }
            $state = (string) ($item['cycle_state'] ?? '');
            if ($state === 'blocked') {
                $counters['blocked']++;
            } elseif ($state === 'executed') {
                $counters['executed']++;
            } elseif ($state === 'deferred') {
                $counters['deferred']++;
            }
        }

        return $counters;
    }

    /**
     * @param  array<string,mixed>|null  $runner
     * @param  array<string,mixed>|null  $dayStarts
     * @param  array<string,mixed>|null  $firstCycles
     * @param  array<string,mixed>|null  $bridgeRecords
     * @param  array<string,mixed>|null  $receipts
     * @return list<array<string,mixed>>
     */
    private function latestReceipts(
        ?array $runner,
        ?array $dayStarts,
        ?array $firstCycles,
        ?array $bridgeRecords,
        ?array $receipts,
    ): array {
        $out = [];

        if (is_array($runner['last_run'] ?? null)) {
            $out[] = ['source' => 'ap_766_runner', 'receipt' => $runner['last_run']];
        }

        $starts = array_values(array_filter((array) ($dayStarts['records'] ?? []), 'is_array'));
        if ($starts !== []) {
            $out[] = ['source' => 'ap_778_day_start', 'receipt' => $starts[array_key_last($starts)]];
            $last = $starts[array_key_last($starts)];
            if (($last['final_status'] ?? '') === ContinuousStewardshipDayStartService::STATUS_FIRST_TICK_EXECUTED) {
                $out[] = [
                    'source' => 'ap_778_first_tick',
                    'receipt' => [
                        'final_status' => ContinuousStewardshipDayStartService::STATUS_FIRST_TICK_EXECUTED,
                        'start_receipt_id' => (string) ($last['start_receipt_id'] ?? ''),
                        'recorded_at' => (string) ($last['recorded_at'] ?? ''),
                    ],
                ];
            }
        }

        $cycles = array_values(array_filter((array) ($firstCycles['cycles'] ?? []), 'is_array'));
        if ($cycles !== []) {
            $out[] = ['source' => 'ap_768_first_cycle', 'receipt' => $cycles[array_key_last($cycles)]];
        }

        $bridges = array_values(array_filter((array) ($bridgeRecords['records'] ?? []), 'is_array'));
        if ($bridges !== []) {
            $out[] = ['source' => 'ap_765_runtime_bridge', 'receipt' => $bridges[array_key_last($bridges)]];
        }

        foreach (array_slice(array_reverse(array_values(array_filter((array) ($receipts['receipts'] ?? []), 'is_array'))), 0, 3) as $receipt) {
            $out[] = ['source' => 'ap_755_control_receipt', 'receipt' => $receipt];
        }

        return array_slice($out, 0, 12);
    }

    /**
     * @param  array<string,mixed>|null  $readiness
     * @param  array<string,mixed>|null  $runner
     * @param  array<string,mixed>|null  $controls
     * @param  array<string,mixed>|null  $branchCert
     * @return list<string>
     */
    private function collectBlockers(?array $readiness, ?array $runner, ?array $controls, ?array $branchCert): array
    {
        $blockers = [];
        foreach ([$readiness, $runner, $controls, $branchCert] as $section) {
            if (! is_array($section)) {
                continue;
            }
            foreach ((array) ($section['blockers'] ?? []) as $blocker) {
                if (is_string($blocker) && $blocker !== '') {
                    $blockers[] = $blocker;
                }
            }
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param  array<string,mixed>|null  $readiness
     * @param  array<string,mixed>|null  $runner
     * @param  array<string,mixed>|null  $controls
     * @return list<string>
     */
    private function collectNextActions(?array $readiness, ?array $runner, ?array $controls): array
    {
        $actions = [];
        foreach ([$readiness, $runner, $controls] as $section) {
            if (! is_array($section)) {
                continue;
            }
            foreach ((array) ($section['next_actions'] ?? []) as $action) {
                if (is_string($action) && $action !== '') {
                    $actions[] = $action;
                }
            }
        }

        return array_values(array_unique($actions));
    }

    /**
     * @param  list<string>  $failedSources
     * @return array<string,mixed>
     */
    private function emptyState(bool $degraded, array $failedSources): array
    {
        return [
            'honest' => true,
            'reason' => $degraded ? 'degraded_with_no_items' : 'no_stewardship_loop_activity_recorded',
            'message' => $degraded
                ? 'Sources failed before any inbox items could be projected; check degraded_state.failed_sources.'
                : 'No stewardship loop ticks, cycles, runtime results or control receipts are recorded yet. This is not a loading failure.',
            'failed_sources' => $failedSources,
            'loading' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $sources
     * @param  list<string>  $failedSources
     * @param  callable():array<string,mixed>  $loader
     * @return array<string,mixed>|null
     */
    private function loadSource(
        array &$sources,
        array &$failedSources,
        string $sourceId,
        callable $loader,
        float $started,
        int $budgetMs,
    ): ?array {
        $elapsedMs = (int) round((microtime(true) - $started) * 1000);
        if ($elapsedMs >= $budgetMs) {
            $sources[$sourceId] = ['status' => 'skipped', 'reason' => 'budget_exceeded'];
            $failedSources[] = $sourceId.':budget_exceeded';

            return null;
        }

        try {
            $data = $loader();
            $sources[$sourceId] = ['status' => 'ok'];

            return $data;
        } catch (Throwable $e) {
            $sources[$sourceId] = ['status' => 'error', 'message' => $e->getMessage()];
            $failedSources[] = $sourceId.':'.$e->getMessage();

            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function item(
        string $kind,
        string $bucket,
        string $cycleState,
        string $title,
        string $summary,
        string $areaId,
        string $portfolioId,
        string $sourceAp,
        string $dedupeKey,
        array $payload = [],
    ): array {
        return [
            'schema_version' => self::ITEM_SCHEMA,
            'kind' => $kind,
            'bucket' => $bucket,
            'cycle_state' => $cycleState,
            'title' => $title,
            'summary' => $summary,
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'source_ap_contract' => $sourceAp,
            'dedupe_key' => $dedupeKey,
            'review_required' => true,
            'inbox_status' => 'projected',
            'payload' => $payload,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    private function dedupeItems(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            $key = (string) ($item['dedupe_key'] ?? '');
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function tailJsonl(string $path, int $limit): array
    {
        if (! is_file($path) || $limit < 1) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $records = [];
        foreach (array_slice($lines, -$limit) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $records[] = $decoded;
            }
        }

        return $records;
    }

    /**
     * @return array<string,bool>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'writes_repo' => false,
            'invokes_provider' => false,
            'invokes_dev' => false,
            'invokes_forge' => false,
            'installs_scheduler' => false,
            'starts_loop' => false,
            'fabricates_receipts' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $payload['projection_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->hashIdentity($payload));
        $payload['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function hashIdentity(array $payload): array
    {
        $items = [];
        foreach ((array) ($payload['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $items[] = [
                'dedupe_key' => (string) ($item['dedupe_key'] ?? ''),
                'kind' => (string) ($item['kind'] ?? ''),
                'cycle_state' => (string) ($item['cycle_state'] ?? ''),
            ];
        }

        return [
            'schema_version' => (string) ($payload['schema_version'] ?? ''),
            'status' => (string) ($payload['status'] ?? ''),
            'area_id' => (string) ($payload['area_id'] ?? ''),
            'portfolio_id' => (string) ($payload['portfolio_id'] ?? ''),
            'item_count' => (int) ($payload['item_count'] ?? 0),
            'counters' => (array) ($payload['counters'] ?? []),
            'items' => $items,
            'failed_sources' => (array) data_get($payload, 'degraded_state.failed_sources', []),
        ];
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9_\-]+/', '_', $slug) ?: 'default';

        return trim($slug, '_') ?: self::DEFAULT_AREA_ID;
    }

    private function nonEmpty(string $value, string $fallback): string
    {
        $value = trim($value);

        return $value !== '' ? $value : $fallback;
    }
}
