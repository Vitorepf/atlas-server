<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\ProductMode;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionReadModelService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FirstFullCycleOrchestratorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchSystemCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\ContinuousStewardshipDayReadinessService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\ContinuousStewardshipDayStartService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\ContinuousStewardshipRunnerService;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\Support\ProductModeOperationalInboxProjectionSupport;
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
 *
 * Pure item/counter/claim/hash helpers live in ProductModeOperationalInboxProjectionSupport.
 */
final class ProductModeOperationalInboxReadModelService
{
    use ProductModeStringHelper;

    public const SCHEMA = 'atlas.software_company.product_mode_operational_inbox.v1';

    public const ITEM_SCHEMA = ProductModeOperationalInboxProjectionSupport::ITEM_SCHEMA;

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
        private readonly AutonomousEvolutionSessionReadModelService $autonomousSessions,
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
        $this->autonomousSessions->setStorageRootForTesting($root !== null ? $root.'/autonomous_evolution_sessions' : null);
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
        $areaId = ProductModeOperationalInboxProjectionSupport::slug($areaId, self::DEFAULT_AREA_ID);
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
            $items = array_merge($items, ProductModeOperationalInboxProjectionSupport::itemsFromReadiness($areaId, $portfolioId, $readiness));
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
            $items = array_merge($items, ProductModeOperationalInboxProjectionSupport::itemsFromRunner($areaId, $portfolioId, $runner));
        }

        $branchCert = $this->loadSource($sources, $failedSources, 'ap_776_branch_cert', function () use ($areaId, $repoRoot): array {
            return $this->branchCertification->certify([
                'area_id' => $areaId,
                'repo_root' => $repoRoot,
            ]);
        }, $started, $budgetMs);
        if ($branchCert !== null) {
            $items = array_merge($items, ProductModeOperationalInboxProjectionSupport::itemsFromBranchCert($areaId, $portfolioId, $branchCert));
        }

        $controls = $this->loadSource($sources, $failedSources, 'ap_754_controls', function () use ($areaId, $portfolioId, $input): array {
            return $this->controls->project($areaId, $portfolioId, $input);
        }, $started, $budgetMs);
        if ($controls !== null) {
            $items = array_merge($items, ProductModeOperationalInboxProjectionSupport::itemsFromControls($areaId, $portfolioId, $controls));
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
            $items = array_merge($items, ProductModeOperationalInboxProjectionSupport::itemsFromRuntimeEvents($areaId, $portfolioId, $runtimeEvents));
        }

        $bridgeRecords = $this->loadSource($sources, $failedSources, 'ap_765_runtime_bridge', fn (): array => [
            'records' => $this->tailJsonl($this->runtimeBridge->bridgeFilePath($areaId), 5),
        ], $started, $budgetMs);

        $autonomousSessions = $this->loadSource($sources, $failedSources, 'ap_786_autonomous_evolution', function () use ($areaId, $input): array {
            if (array_key_exists('autonomous_evolution_sessions', $input) && is_array($input['autonomous_evolution_sessions'])) {
                return ['sessions' => array_values(array_filter($input['autonomous_evolution_sessions'], 'is_array'))];
            }

            return ['sessions' => $this->autonomousSessions->listSessions($areaId, 5)];
        }, $started, $budgetMs);
        if ($autonomousSessions !== null) {
            $items = array_merge($items, ProductModeOperationalInboxProjectionSupport::itemsFromAutonomousEvolution($areaId, $portfolioId, $autonomousSessions));
        }

        $items = ProductModeOperationalInboxProjectionSupport::dedupeItems($items);
        $counters = ProductModeOperationalInboxProjectionSupport::counters($items);
        $latestReceipts = ProductModeOperationalInboxProjectionSupport::latestReceipts($runner, $dayStarts, $firstCycles, $bridgeRecords, $receipts);

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
            'source_ap_contracts' => ['AP-754', 'AP-755', 'AP-765', 'AP-766', 'AP-768', 'AP-776', 'AP-777', 'AP-778', 'AP-786'],
            'sources' => $sources,
            'items' => $items,
            'item_count' => count($items),
            'counters' => $counters,
            'latest_receipts' => $latestReceipts,
            'blockers' => ProductModeOperationalInboxProjectionSupport::collectBlockers($readiness, $runner, $controls, $branchCert),
            'next_actions' => ProductModeOperationalInboxProjectionSupport::collectNextActions($readiness, $runner, $controls),
            'empty_state' => $empty ? ProductModeOperationalInboxProjectionSupport::emptyState($degraded, $failedSources) : null,
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
            'claim_policy' => ProductModeOperationalInboxProjectionSupport::claimPolicy(),
        ];

        return $this->finalize($payload);
    }

    /**
     * Loads first-cycle list then optional replay I/O; pure shaping stays in Support.
     *
     * @param  array<string,mixed>  $cycles
     * @return list<array<string,mixed>>
     */
    private function itemsFromFirstCycles(string $areaId, string $portfolioId, array $cycles): array
    {
        $list = array_values(array_filter((array) ($cycles['cycles'] ?? []), 'is_array'));
        if ($list === []) {
            return [];
        }

        $latest = $list[array_key_last($list)];
        $replay = $this->firstCycle->replay((string) ($latest['cycle_id'] ?? ''), $areaId);
        $replayStages = is_array($replay) ? $replay : null;

        return ProductModeOperationalInboxProjectionSupport::itemsFromFirstCycles($areaId, $portfolioId, $cycles, $replayStages);
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
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $payload['projection_hash'] = 'sha256:'.MissionCanonicalHash::sha256(
            ProductModeOperationalInboxProjectionSupport::hashIdentity($payload),
        );
        $payload['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);

        return $payload;
    }
}
