<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\PortfolioStewardship;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipPromotionReadinessService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipStringListNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionDecisionLedgerService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionReadModelService;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Portfolio Stewardship · persistent health model (AP-733).
 *
 * Turns the AP-730 Portfolio Stewardship projection into a replayable health
 * model and append-only JSONL snapshot ledger. This is still proposal/review
 * infrastructure: no provider calls, no branch/worktree, no Dev/Forge dispatch,
 * no merge/deploy/secrets and no auto-promotion.
 */
class PortfolioStewardshipHealthModelService
{
    public const HEALTH_SCHEMA = 'atlas.portfolio_stewardship.health_model.v1';

    public const SNAPSHOT_SCHEMA = 'atlas.portfolio_stewardship.health_snapshot.v1';

    public const LEDGER_SCHEMA = 'atlas.portfolio_stewardship.health_ledger.v1';

    public const STATUS_READY_FOR_OPERATOR_REVIEW = 'ready_for_operator_review';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_PORTFOLIO_ID = 'atlas_software_company';

    private ?string $storageRootOverride = null;

    public function __construct(
        private readonly StewardshipEvolutionReadModelService $evolution,
        private readonly StewardshipEvolutionDecisionLedgerService $decisionLedger,
        private readonly AreaStewardshipPromotionReadinessService $areaReadiness,
    ) {}

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
    }

    public function storageDir(): string
    {
        if ($this->storageRootOverride !== null) {
            return $this->storageRootOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/portfolio_health')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/portfolio_health';
    }

    public function ledgerFilePath(string $portfolioId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->slug($portfolioId).'.jsonl';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(array $input = []): array
    {
        $areaId = $this->areaId($input['area_id'] ?? StewardshipEvolutionReadModelService::DEFAULT_AREA_ID);
        $portfolioId = $this->portfolioId($input['portfolio_id'] ?? self::DEFAULT_PORTFOLIO_ID);
        $evolution = is_array($input['evolution_report'] ?? null)
            ? $input['evolution_report']
            : $this->evolution->project($this->evolutionInput($input, $areaId, $portfolioId));

        if (($evolution['status'] ?? '') === StewardshipEvolutionReadModelService::STATUS_BLOCKED) {
            return $this->finalize([
                'schema_version' => self::HEALTH_SCHEMA,
                'status' => self::STATUS_BLOCKED,
                'reason' => 'stewardship_evolution_not_ready',
                'ap_contract' => 'AP-733',
                'portfolio_id' => $portfolioId,
                'area_id' => $areaId,
                'blockers' => ['AP-730 evolution report is blocked for this portfolio seed.'],
                'claim_policy' => $this->claimPolicy(false),
            ]);
        }

        $portfolio = is_array($evolution['portfolio_stewardship'] ?? null) ? $evolution['portfolio_stewardship'] : [];
        $releaseSignals = $this->releaseOutcomeSignals($input);
        $ownerResultSignals = $this->ownerRuntimeResultSignals($input);
        $areas = $this->applyOwnerRuntimeResultSignals(
            $this->applyReleaseSignals($this->areas($portfolio, $input), $releaseSignals),
            $ownerResultSignals,
        );
        if ($areas === []) {
            return $this->finalize([
                'schema_version' => self::HEALTH_SCHEMA,
                'status' => self::STATUS_BLOCKED,
                'reason' => 'portfolio_areas_missing',
                'ap_contract' => 'AP-733',
                'portfolio_id' => $portfolioId,
                'area_id' => $areaId,
                'blockers' => ['Portfolio health requires at least one stewarded or candidate area.'],
                'claim_policy' => $this->claimPolicy(false),
            ]);
        }

        $areaReadiness = is_array($input['area_readiness'] ?? null)
            ? $input['area_readiness']
            : $this->areaReadiness->assess([
                'area_id' => $areaId,
                'evolution_report' => $evolution,
                'decision_ledger' => is_array($input['decision_ledger'] ?? null)
                    ? $input['decision_ledger']
                    : $this->decisionLedger->listDecisions($areaId),
            ]);
        $decisionLedger = is_array($input['decision_ledger'] ?? null)
            ? $input['decision_ledger']
            : $this->decisionLedger->listDecisions($areaId);

        $dependencyGraph = $this->dependencyGraph($portfolio, $areas);
        $health = $this->health($areas, $dependencyGraph, $areaReadiness, $decisionLedger);
        $rebalanceCandidates = $this->rebalanceCandidates($areas, $dependencyGraph, $health);

        return $this->finalize([
            'schema_version' => self::HEALTH_SCHEMA,
            'status' => self::STATUS_READY_FOR_OPERATOR_REVIEW,
            'ap_contract' => 'AP-733',
            'source_ap_contracts' => ['AP-730', 'AP-731', 'AP-732', 'AP-733', 'AP-747', 'AP-748', 'AP-749', 'AP-750', 'AP-751'],
            'portfolio_id' => $portfolioId,
            'area_id' => $areaId,
            'source_report_hash' => (string) ($evolution['report_hash'] ?? ''),
            'areas' => $areas,
            'dependency_graph' => $dependencyGraph,
            'release_outcome_summary' => $this->releaseOutcomeSummary($releaseSignals),
            'owner_runtime_result_summary' => $this->ownerRuntimeResultSummary($ownerResultSignals),
            'portfolio_health' => $health,
            'risk_summary' => $this->riskSummary($areas, $dependencyGraph, $areaReadiness),
            'rebalance_candidates' => $rebalanceCandidates,
            'operator_inbox' => $this->operatorInbox($portfolioId, $rebalanceCandidates),
            'promotion_boundary' => $this->promotionBoundary(),
            'evidence_refs' => $this->evidenceRefs(),
            'claim_policy' => $this->claimPolicy(false),
        ]);
    }

    /**
     * Append the current health model as a deterministic, idempotent snapshot.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function record(array $input = []): array
    {
        $projection = $this->project($input);
        $portfolioId = (string) ($projection['portfolio_id'] ?? self::DEFAULT_PORTFOLIO_ID);
        $snapshotId = $this->snapshotId($projection);

        $existing = $this->findInFile($this->ledgerFilePath($portfolioId), $snapshotId);
        if ($existing !== null) {
            return $existing;
        }

        $record = $projection + [
            'snapshot_schema_version' => self::SNAPSHOT_SCHEMA,
            'ledger_schema_version' => self::LEDGER_SCHEMA,
            'snapshot_id' => $snapshotId,
            'recorded_at' => $this->now(),
            'claim_policy' => $this->claimPolicy(true),
        ];

        AppendOnlyJsonlStore::append($this->ledgerFilePath($portfolioId), $record);

        return $record;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function replay(string $snapshotId): ?array
    {
        foreach ($this->portfolioFiles() as $file) {
            $found = $this->findInFile($file, $snapshotId);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    public function listSnapshots(?string $portfolioId = null): array
    {
        $files = $portfolioId === null || trim($portfolioId) === ''
            ? $this->portfolioFiles()
            : [$this->ledgerFilePath($portfolioId)];

        $records = [];
        $corrupted = 0;
        foreach ($files as $file) {
            [$valid, $bad] = $this->readRecords($file);
            $records = array_merge($records, $valid);
            $corrupted += $bad;
        }

        $summaries = [];
        foreach ($records as $record) {
            $summaries[] = [
                'snapshot_id' => (string) ($record['snapshot_id'] ?? ''),
                'portfolio_id' => (string) ($record['portfolio_id'] ?? ''),
                'area_id' => (string) ($record['area_id'] ?? ''),
                'status' => (string) ($record['status'] ?? ''),
                'score' => (int) data_get($record, 'portfolio_health.score', 0),
                'band' => (string) data_get($record, 'portfolio_health.band', ''),
                'lowest_health_area' => (string) data_get($record, 'portfolio_health.lowest_health_area', ''),
                'recorded_at' => (string) ($record['recorded_at'] ?? ''),
                'health_hash' => (string) ($record['health_hash'] ?? ''),
            ];
        }

        usort($summaries, static fn (array $a, array $b): int => strcmp((string) $a['recorded_at'], (string) $b['recorded_at']));

        return [
            'schema_version' => self::LEDGER_SCHEMA,
            'ap_contract' => 'AP-733',
            'portfolio_id' => $portfolioId,
            'snapshot_count' => count($summaries),
            'corrupted_line_count' => $corrupted,
            'snapshots' => $summaries,
            'claim_policy' => $this->claimPolicy(true),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function evolutionInput(array $input, string $areaId, string $portfolioId): array
    {
        $out = [
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
        ];
        foreach (['areas', 'area_focus_report', 'portfolio_objective'] as $key) {
            if (array_key_exists($key, $input)) {
                $out[$key] = $input[$key];
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $portfolio
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    private function areas(array $portfolio, array $input): array
    {
        $raw = is_array($input['areas'] ?? null)
            ? array_values(array_filter($input['areas'], 'is_array'))
            : array_values(array_filter((array) ($portfolio['areas'] ?? []), 'is_array'));

        $areas = [];
        foreach ($raw as $area) {
            $areaId = $this->areaId($area['area_id'] ?? 'unknown');
            $score = max(0, min(100, (int) ($area['health_score'] ?? 70)));
            $dependencies = StewardshipStringListNormalizer::uniqueMappedTruthyStringValues(
                $area['dependencies'] ?? [],
                static fn (mixed $dependency): string => preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim((string) $dependency))) ?: '',
            );

            $areas[] = [
                'area_id' => $areaId,
                'area_name' => (string) ($area['area_name'] ?? ucwords(str_replace('_', ' ', $areaId))),
                'health_score' => $score,
                'health_band' => $this->healthBand($score),
                'status' => (string) ($area['status'] ?? 'candidate'),
                'dependencies' => $dependencies,
            ];
        }

        return $areas;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,array<string,mixed>>
     */
    private function releaseOutcomeSignals(array $input): array
    {
        $feed = is_array($input['release_portfolio_feed'] ?? null)
            ? $input['release_portfolio_feed']
            : (is_array(data_get($input, 'release_outcome_bridge.portfolio_feed')) ? data_get($input, 'release_outcome_bridge.portfolio_feed') : []);

        $signals = [];
        foreach ((array) ($feed['areas'] ?? []) as $area) {
            if (! is_array($area)) {
                continue;
            }
            $areaId = $this->areaId($area['area_id'] ?? '');
            if ($areaId === 'unknown') {
                continue;
            }
            $signals[$areaId] = [
                'area_id' => $areaId,
                'owner_queue_pending_count' => max(0, (int) ($area['owner_queue_pending_count'] ?? 0)),
                'blocked_release_count' => max(0, (int) ($area['blocked_release_count'] ?? 0)),
                'target_owners' => StewardshipStringListNormalizer::uniqueTruthyStringifiedValues($area['target_owners'] ?? []),
                'queue_item_ids' => StewardshipStringListNormalizer::uniqueTruthyStringifiedValues($area['queue_item_ids'] ?? []),
                'portfolio_signal' => (string) ($area['portfolio_signal'] ?? 'owner_queue_ready_for_review'),
                'recommended_portfolio_action' => (string) ($area['recommended_portfolio_action'] ?? 'prioritize_owner_queue_review_for_area'),
            ];
        }

        return $signals;
    }

    /**
     * @param  list<array<string,mixed>>  $areas
     * @param  array<string,array<string,mixed>>  $signals
     * @return list<array<string,mixed>>
     */
    private function applyReleaseSignals(array $areas, array $signals): array
    {
        if ($signals === []) {
            return $areas;
        }

        return array_map(function (array $area) use ($signals): array {
            $areaId = (string) ($area['area_id'] ?? '');
            if (isset($signals[$areaId])) {
                $area['release_outcome_signal'] = $signals[$areaId];
            }

            return $area;
        }, $areas);
    }

    /**
     * @param  array<string,array<string,mixed>>  $signals
     * @return array<string,mixed>
     */
    private function releaseOutcomeSummary(array $signals): array
    {
        $pending = 0;
        $blocked = 0;
        foreach ($signals as $signal) {
            $pending += (int) ($signal['owner_queue_pending_count'] ?? 0);
            $blocked += (int) ($signal['blocked_release_count'] ?? 0);
        }

        return [
            'schema_version' => 'atlas.portfolio_stewardship.release_outcome_summary.v1',
            'source_ap_contracts' => ['AP-747', 'AP-748'],
            'area_count_with_release_signal' => count($signals),
            'owner_queue_pending_count' => $pending,
            'blocked_release_count' => $blocked,
            'portfolio_input_only' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,array<string,mixed>>
     */
    private function ownerRuntimeResultSignals(array $input): array
    {
        $feed = [];
        if (is_array($input['owner_runtime_result_portfolio_feed'] ?? null)) {
            $feed = $input['owner_runtime_result_portfolio_feed'];
        } elseif (is_array(data_get($input, 'owner_runtime_result_bridge.portfolio_feed'))) {
            $feed = data_get($input, 'owner_runtime_result_bridge.portfolio_feed');
        } elseif (($input['ap_contract'] ?? '') === 'AP-750' && is_array($input['portfolio_feed'] ?? null)) {
            $feed = $input['portfolio_feed'];
        }

        $signals = [];
        foreach ((array) ($feed['areas'] ?? []) as $area) {
            if (! is_array($area)) {
                continue;
            }
            $areaId = $this->areaId($area['area_id'] ?? '');
            if ($areaId === '') {
                continue;
            }
            $signals[$areaId] = [
                'area_id' => $areaId,
                'owner_runtime_result_count' => max(0, (int) ($area['owner_runtime_result_count'] ?? 0)),
                'completed_result_count' => max(0, (int) ($area['completed_result_count'] ?? 0)),
                'failed_result_count' => max(0, (int) ($area['failed_result_count'] ?? 0)),
                'partial_result_count' => max(0, (int) ($area['partial_result_count'] ?? 0)),
                'result_ids' => StewardshipStringListNormalizer::uniqueTruthyStringifiedValues($area['result_ids'] ?? []),
                'result_health_signal' => (string) ($area['result_health_signal'] ?? 'owner_runtime_result_ready_for_review'),
                'recommended_portfolio_action' => (string) ($area['recommended_portfolio_action'] ?? 'review_owner_runtime_result_before_next_allocation'),
            ];
        }

        return $signals;
    }

    /**
     * @param  list<array<string,mixed>>  $areas
     * @param  array<string,array<string,mixed>>  $signals
     * @return list<array<string,mixed>>
     */
    private function applyOwnerRuntimeResultSignals(array $areas, array $signals): array
    {
        if ($signals === []) {
            return $areas;
        }

        return array_map(function (array $area) use ($signals): array {
            $areaId = (string) ($area['area_id'] ?? '');
            if (isset($signals[$areaId])) {
                $area['owner_runtime_result_signal'] = $signals[$areaId];
            }

            return $area;
        }, $areas);
    }

    /**
     * @param  array<string,array<string,mixed>>  $signals
     * @return array<string,mixed>
     */
    private function ownerRuntimeResultSummary(array $signals): array
    {
        $counts = $this->ownerRuntimeResultCountsFromSignals($signals);

        return [
            'schema_version' => 'atlas.portfolio_stewardship.owner_runtime_result_summary.v1',
            'source_ap_contracts' => ['AP-750', 'AP-751'],
            'area_count_with_result_signal' => count($signals),
            'owner_runtime_result_count' => $counts['owner_runtime_result_count'],
            'completed_result_count' => $counts['completed_result_count'],
            'failed_result_count' => $counts['failed_result_count'],
            'partial_result_count' => $counts['partial_result_count'],
            'portfolio_input_only' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $portfolio
     * @param  list<array<string,mixed>>  $areas
     * @return array<string,list<string>>
     */
    private function dependencyGraph(array $portfolio, array $areas): array
    {
        $graph = [];
        $source = is_array($portfolio['dependency_graph'] ?? null) ? $portfolio['dependency_graph'] : [];
        foreach ($areas as $area) {
            $areaId = (string) $area['area_id'];
            $dependencies = array_key_exists($areaId, $source)
                ? (array) $source[$areaId]
                : (array) ($area['dependencies'] ?? []);
            $graph[$areaId] = StewardshipStringListNormalizer::uniqueMappedTruthyStringValues(
                $dependencies,
                static fn (mixed $dependency): string => preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim((string) $dependency))) ?: '',
            );
        }

        ksort($graph);

        return $graph;
    }

    /**
     * @param  list<array<string,mixed>>  $areas
     * @param  array<string,list<string>>  $dependencyGraph
     * @param  array<string,mixed>  $areaReadiness
     * @param  array<string,mixed>  $decisionLedger
     * @return array<string,mixed>
     */
    private function health(array $areas, array $dependencyGraph, array $areaReadiness, array $decisionLedger): array
    {
        $scores = array_map(static fn (array $area): int => (int) ($area['health_score'] ?? 0), $areas);
        $average = $scores === [] ? 0 : (int) round(array_sum($scores) / count($scores));
        $lowest = $this->lowestHealthArea($areas);
        $bottlenecks = $this->dependencyBottlenecks($dependencyGraph);
        $readinessPenalty = ($areaReadiness['status'] ?? '') === AreaStewardshipPromotionReadinessService::STATUS_BLOCKED ? 10 : 0;
        $operatorReviewBonus = $this->acceptedDecisionCount($decisionLedger, 'portfolio_stewardship') > 0 ? 3 : 0;
        $ownerQueuePending = $this->ownerQueuePendingCount($areas);
        $ownerResultCounts = $this->ownerRuntimeResultCounts($areas);
        $ownerResultBonus = min(3, $ownerResultCounts['completed_result_count']);
        $ownerResultPenalty = min(12, ($ownerResultCounts['failed_result_count'] * 6) + ($ownerResultCounts['partial_result_count'] * 3));
        $score = max(0, min(100, $average - min(12, count($bottlenecks) * 2) - $readinessPenalty - $ownerResultPenalty + $operatorReviewBonus + $ownerResultBonus));

        return [
            'score' => $score,
            'band' => $this->healthBand($score),
            'raw_average_score' => $average,
            'area_count' => count($areas),
            'lowest_health_area' => (string) ($lowest['area_id'] ?? ''),
            'lowest_health_score' => (int) ($lowest['health_score'] ?? 0),
            'dependency_bottleneck_count' => count($bottlenecks),
            'owner_queue_pending_count' => $ownerQueuePending,
            'owner_runtime_result_count' => $ownerResultCounts['owner_runtime_result_count'],
            'completed_owner_runtime_result_count' => $ownerResultCounts['completed_result_count'],
            'failed_owner_runtime_result_count' => $ownerResultCounts['failed_result_count'],
            'partial_owner_runtime_result_count' => $ownerResultCounts['partial_result_count'],
            'area_readiness_status' => (string) ($areaReadiness['status'] ?? 'unknown'),
            'operator_reviewed_portfolio_decisions' => $this->acceptedDecisionCount($decisionLedger, 'portfolio_stewardship'),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $areas
     * @param  array<string,list<string>>  $dependencyGraph
     * @param  array<string,mixed>  $areaReadiness
     * @return array<string,mixed>
     */
    private function riskSummary(array $areas, array $dependencyGraph, array $areaReadiness): array
    {
        $criticalAreas = array_values(array_filter($areas, static fn (array $area): bool => (int) ($area['health_score'] ?? 0) < 50));
        $watchAreas = array_values(array_filter($areas, static fn (array $area): bool => (int) ($area['health_score'] ?? 0) < 75));
        $bottlenecks = $this->dependencyBottlenecks($dependencyGraph);
        $ownerQueuePending = $this->ownerQueuePendingCount($areas);
        $ownerResultCounts = $this->ownerRuntimeResultCounts($areas);
        $ownerResultFollowup = $ownerResultCounts['failed_result_count'] + $ownerResultCounts['partial_result_count'];

        return [
            'critical_area_count' => count($criticalAreas),
            'watch_area_count' => count($watchAreas),
            'dependency_bottlenecks' => $bottlenecks,
            'owner_queue_pending_count' => $ownerQueuePending,
            'owner_runtime_result_count' => $ownerResultCounts['owner_runtime_result_count'],
            'completed_owner_runtime_result_count' => $ownerResultCounts['completed_result_count'],
            'failed_owner_runtime_result_count' => $ownerResultCounts['failed_result_count'],
            'partial_owner_runtime_result_count' => $ownerResultCounts['partial_result_count'],
            'area_readiness_status' => (string) ($areaReadiness['status'] ?? 'unknown'),
            'primary_risk' => $criticalAreas !== []
                ? 'critical_area_health'
                : ($ownerResultFollowup > 0
                    ? 'owner_runtime_result_followup_required'
                    : ($bottlenecks !== []
                        ? 'dependency_bottleneck'
                        : ($ownerResultCounts['owner_runtime_result_count'] > 0
                            ? 'owner_runtime_result_review_latency'
                            : ($ownerQueuePending > 0 ? 'owner_queue_review_latency' : 'operator_decision_latency')))),
            'irreversible_action_risk' => 'blocked_by_policy',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $areas
     * @param  array<string,list<string>>  $dependencyGraph
     * @param  array<string,mixed>  $health
     * @return list<array<string,mixed>>
     */
    private function rebalanceCandidates(array $areas, array $dependencyGraph, array $health): array
    {
        usort($areas, static function (array $a, array $b) use ($dependencyGraph): int {
            $aResultCount = (int) data_get($a, 'owner_runtime_result_signal.owner_runtime_result_count', 0);
            $aResultFollowup = (int) data_get($a, 'owner_runtime_result_signal.failed_result_count', 0) + (int) data_get($a, 'owner_runtime_result_signal.partial_result_count', 0);
            $bResultCount = (int) data_get($b, 'owner_runtime_result_signal.owner_runtime_result_count', 0);
            $bResultFollowup = (int) data_get($b, 'owner_runtime_result_signal.failed_result_count', 0) + (int) data_get($b, 'owner_runtime_result_signal.partial_result_count', 0);
            $aPriority = (100 - (int) ($a['health_score'] ?? 0)) + (count($dependencyGraph[(string) ($a['area_id'] ?? '')] ?? []) * 4) + (((int) data_get($a, 'release_outcome_signal.owner_queue_pending_count', 0)) * 8) + ($aResultCount * 12) + ($aResultFollowup * 16);
            $bPriority = (100 - (int) ($b['health_score'] ?? 0)) + (count($dependencyGraph[(string) ($b['area_id'] ?? '')] ?? []) * 4) + (((int) data_get($b, 'release_outcome_signal.owner_queue_pending_count', 0)) * 8) + ($bResultCount * 12) + ($bResultFollowup * 16);

            return $bPriority <=> $aPriority;
        });

        $candidates = [];
        foreach (array_slice($areas, 0, 3) as $area) {
            $areaId = (string) $area['area_id'];
            $score = (int) ($area['health_score'] ?? 0);
            $pendingQueue = (int) data_get($area, 'release_outcome_signal.owner_queue_pending_count', 0);
            $resultCount = (int) data_get($area, 'owner_runtime_result_signal.owner_runtime_result_count', 0);
            $resultFollowup = (int) data_get($area, 'owner_runtime_result_signal.failed_result_count', 0) + (int) data_get($area, 'owner_runtime_result_signal.partial_result_count', 0);
            $action = $resultFollowup > 0
                ? 'route_owner_runtime_followup'
                : ($resultCount > 0 ? 'review_owner_runtime_result' : ($pendingQueue > 0 ? 'review_owner_queue_release' : 'allocate_next_governed_cycle'));
            $reason = $resultFollowup > 0
                ? 'owner_runtime_result_followup_required'
                : ($resultCount > 0
                    ? 'owner_runtime_result_waiting_for_review'
                    : ($pendingQueue > 0
                        ? 'owner_queue_waiting_for_review'
                        : ($areaId === ($health['lowest_health_area'] ?? '')
                            ? 'lowest_health_area'
                            : 'dependency_weighted_portfolio_unlock')));
            $candidates[] = [
                'candidate_id' => 'portfolio_rebalance_'.substr(MissionCanonicalHash::sha256([$areaId, $score, $health['score'] ?? 0]), 0, 12),
                'action' => $action,
                'target_area' => $areaId,
                'priority_score' => max(0, 100 - $score + (count($dependencyGraph[$areaId] ?? []) * 4) + ($pendingQueue * 8) + ($resultCount * 12) + ($resultFollowup * 16)),
                'reason' => $reason,
                'owner_queue_pending_count' => $pendingQueue,
                'owner_runtime_result_count' => $resultCount,
                'owner_runtime_result_followup_count' => $resultFollowup,
                'requires_operator_review' => true,
                'routes_to' => ['area_stewardship', 'area_focus_loop', 'atlas_dev_or_forge_after_acceptance'],
            ];
        }

        return $candidates;
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return array<string,mixed>
     */
    private function operatorInbox(string $portfolioId, array $candidates): array
    {
        return [
            'destination' => 'morning_inbox',
            'target_type' => 'portfolio_stewardship',
            'target_id' => $portfolioId,
            'decision_type' => 'portfolio_rebalance',
            'decision_options' => ['accept', 'reject', 'defer', 'request_changes'],
            'candidate_count' => count($candidates),
            'auto_approval' => false,
            'irreversible_action_allowed' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function promotionBoundary(): array
    {
        return [
            'promotion_from' => 'area_stewardship',
            'promotion_to' => 'portfolio_stewardship',
            'minimum_gate' => '2+ areas, dependency graph, shared objective, rebalance policy and AP-731 operator decision',
            'active_execution_requires_later_ap' => true,
            'operator_accept_required' => true,
            'evidence_required' => true,
            'auto_promotion' => false,
        ];
    }

    /**
     * @return list<string>
     */
    private function evidenceRefs(): array
    {
        return [
            'docs/ap/AP-733-portfolio-stewardship-health-model-contract.md',
            'docs/ap/AP-730-stewardship-evolution-read-model-contract.md',
            'docs/ap/AP-731-stewardship-evolution-operator-decision-ledger-contract.md',
            'docs/ap/AP-732-area-stewardship-promotion-readiness-gate-contract.md',
            'docs/ap/AP-750-owner-runtime-result-bridge-contract.md',
            'docs/ap/AP-751-portfolio-owner-runtime-result-signal-contract.md',
            'docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md',
            'docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md',
        ];
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(bool $writesLocalState): array
    {
        return [
            'read_only_over_repo' => true,
            'portfolio_health_model_only' => true,
            'writes_local_state' => $writesLocalState,
            'local_state_kind' => $writesLocalState ? 'jsonl_append_only_health_snapshot' : 'none',
            'mutates_target_repo' => false,
            'provider_invoked' => false,
            'dev_invoked' => false,
            'forge_invoked' => false,
            'opens_branch' => false,
            'merges' => false,
            'deploys' => false,
            'touches_secrets' => false,
            'secrets_in_payload' => false,
            'autoapproval_allowed' => false,
            'autoimplementation_allowed' => false,
            'auto_promotion' => false,
            'operator_review_required' => true,
            'is_new_os' => false,
            'parallel_runtime_created' => false,
        ];
    }

    /**
     * @param  array<string,list<string>>  $dependencyGraph
     * @return list<array<string,mixed>>
     */
    private function dependencyBottlenecks(array $dependencyGraph): array
    {
        $incoming = [];
        foreach ($dependencyGraph as $areaId => $dependencies) {
            foreach ($dependencies as $dependency) {
                $incoming[$dependency] ??= ['area_id' => $dependency, 'blocked_by_count' => 0, 'blocked_areas' => []];
                $incoming[$dependency]['blocked_by_count']++;
                $incoming[$dependency]['blocked_areas'][] = $areaId;
            }
        }

        $bottlenecks = array_values(array_filter($incoming, static fn (array $node): bool => (int) $node['blocked_by_count'] > 1));
        usort($bottlenecks, static fn (array $a, array $b): int => ((int) $b['blocked_by_count']) <=> ((int) $a['blocked_by_count']));

        return $bottlenecks;
    }

    /**
     * @param  list<array<string,mixed>>  $areas
     */
    private function ownerQueuePendingCount(array $areas): int
    {
        $count = 0;
        foreach ($areas as $area) {
            $count += (int) data_get($area, 'release_outcome_signal.owner_queue_pending_count', 0);
        }

        return $count;
    }

    /**
     * @param  list<array<string,mixed>>  $areas
     * @return array{owner_runtime_result_count:int,completed_result_count:int,failed_result_count:int,partial_result_count:int}
     */
    private function ownerRuntimeResultCounts(array $areas): array
    {
        $signals = [];
        foreach ($areas as $area) {
            if (is_array($area['owner_runtime_result_signal'] ?? null)) {
                $signals[] = $area['owner_runtime_result_signal'];
            }
        }

        return $this->ownerRuntimeResultCountsFromSignals($signals);
    }

    /**
     * @param  iterable<array<string,mixed>>  $signals
     * @return array{owner_runtime_result_count:int,completed_result_count:int,failed_result_count:int,partial_result_count:int}
     */
    private function ownerRuntimeResultCountsFromSignals(iterable $signals): array
    {
        $counts = [
            'owner_runtime_result_count' => 0,
            'completed_result_count' => 0,
            'failed_result_count' => 0,
            'partial_result_count' => 0,
        ];
        foreach ($signals as $signal) {
            $counts['owner_runtime_result_count'] += (int) ($signal['owner_runtime_result_count'] ?? 0);
            $counts['completed_result_count'] += (int) ($signal['completed_result_count'] ?? 0);
            $counts['failed_result_count'] += (int) ($signal['failed_result_count'] ?? 0);
            $counts['partial_result_count'] += (int) ($signal['partial_result_count'] ?? 0);
        }

        return $counts;
    }

    /**
     * @param  array<string,mixed>  $ledger
     */
    private function acceptedDecisionCount(array $ledger, string $targetType): int
    {
        $count = 0;
        foreach ((array) ($ledger['decisions'] ?? []) as $decision) {
            if (! is_array($decision)) {
                continue;
            }
            if ((string) ($decision['target_type'] ?? '') === $targetType && (string) ($decision['decision'] ?? '') === 'accept') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<array<string,mixed>>  $areas
     * @return array<string,mixed>
     */
    private function lowestHealthArea(array $areas): array
    {
        usort($areas, static fn (array $a, array $b): int => ((int) ($a['health_score'] ?? 0)) <=> ((int) ($b['health_score'] ?? 0)));

        return $areas[0] ?? [];
    }

    private function healthBand(int $score): string
    {
        return match (true) {
            $score >= 90 => 'excellent',
            $score >= 80 => 'healthy',
            $score >= 70 => 'watch',
            $score >= 50 => 'at_risk',
            default => 'critical',
        };
    }

    private function snapshotId(array $projection): string
    {
        return 'phs_'.substr(MissionCanonicalHash::sha256([
            $projection['portfolio_id'] ?? self::DEFAULT_PORTFOLIO_ID,
            $projection['health_hash'] ?? '',
        ]), 0, 16);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findInFile(string $path, string $snapshotId): ?array
    {
        [$records] = $this->readRecords($path);
        foreach ($records as $record) {
            if ((string) ($record['snapshot_id'] ?? '') === $snapshotId) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @return array{0:list<array<string,mixed>>,1:int}
     */
    private function readRecords(string $path): array
    {
        return AppendOnlyJsonlStore::readWhereWithRejectedCount(
            $path,
            static fn (array $row): bool => isset($row['snapshot_id']) && is_string($row['snapshot_id']),
        );
    }

    /**
     * @return list<string>
     */
    private function portfolioFiles(): array
    {
        return AppendOnlyJsonlStore::jsonlFilesInDirectory($this->storageDir());
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $payload['health_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->stable($payload));
        $payload['generated_at'] = $this->now();

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function stable(array $payload): array
    {
        unset($payload['health_hash'], $payload['generated_at'], $payload['recorded_at']);

        return $payload;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }

    private function areaId(mixed $value): string
    {
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim((string) $value))) ?? '';

        return $slug !== '' ? $slug : StewardshipEvolutionReadModelService::DEFAULT_AREA_ID;
    }

    private function portfolioId(mixed $value): string
    {
        $slug = $this->slug((string) $value);

        return $slug !== '' ? $slug : self::DEFAULT_PORTFOLIO_ID;
    }

    private function slug(string $value): string
    {
        return preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($value))) ?: '';
    }
}
