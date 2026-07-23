<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryRelation;
use App\Services\Ai\Cognition\FactPairPolarityContradictionDetector;
use App\Services\Ai\Cognition\NumericRangeOverlapContradictionDetector;
use App\Services\Ai\Cognition\TemporalSupersessionClassifier;
use App\Services\Ai\Compounding\AtlasCaptureQualityGate;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\LongHorizon\StrategicForgettingService;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * MAXH-03 — Autonomous producer of candidate memory pairs that feeds the six
 * previously dead-fed conflict-resolution kernels (verb classifier, axis
 * resolver, scope-contradiction, fact-polarity, numeric-range-overlap,
 * temporal-supersession).
 *
 * Contract (frozen with MAXH-01):
 *  - cosine threshold = 0.82; confidence floor = 0.86; candidate min pairs = 12
 *    (all pinned in {@see AtlasMemoryTemporalQualityService::freezePayload()}).
 *  - kernels are instantiated LOCALLY inside this path — the global
 *    `memory_conflict.*` flags stay OFF so nothing else on the live judge path
 *    changes byte-for-byte.
 *  - observe mode NEVER persists a relation row; every verdict is appended to
 *    an append-only JSONL ledger. The ledger root is env-swappable so phpunit
 *    never touches the live ASI-05 ledger.
 *  - non-triviality (ELEV-06): a scan is only reported as `qualified` when it
 *    evaluated at least `scanner_candidate_min_pairs` (=12) pairs AND its
 *    distribution has at least two non-degenerate verbs. Below that the report
 *    is `insufficient_signal`, never a fabricated `>= 1 pair`.
 *
 * Determinism: entries are sorted by id before pairing, and every proposal
 * carries a `pair_hash = sha256(source_id|target_id)` so the same corpus + the
 * same embedding set produce byte-identical proposals.
 */
final class MemoryConsolidationScanner
{
    public const SCHEMA_VERSION = 'atlas.memory.consolidation_proposal.v1';

    public const CLUSTER_SYNTHESIS_SCHEMA_VERSION = 'atlas.memory.cluster_synthesis.v1';

    public const MODE_OBSERVE = 'observe';

    public const MODE_ENFORCE = 'enforce';

    /** Hard ceiling on unique pairs evaluated per scan (defensive cap; MAXH-04 adds cluster path). */
    private const MAX_PAIRS_EVALUATED = 4000;

    /** Only actives ordered by id participate in the pair set; superseded/archived are excluded. */
    private const ACTIVE_STATUS = 'active';

    /** Non-degenerate verbs are the six canonical ones minus `not_conflict` (the "no signal" bucket). */
    private const DEGENERATE_VERBS = [
        AtlasMemoryConflictResolutionService::VERDICT_NOT_CONFLICT,
    ];

    public function __construct(
        private readonly MemoryPairwiseCosineScorer $scorer,
        private readonly ?MemoryConflictVerbClassifier $verbClassifier = null,
        private readonly ?MemoryConflictAxisResolver $axisResolver = null,
        private readonly ?MemoryScopeContradictionClassifier $scopeClassifier = null,
        private readonly ?FactPairPolarityContradictionDetector $factPolarity = null,
        private readonly ?NumericRangeOverlapContradictionDetector $numericRange = null,
        private readonly ?TemporalSupersessionClassifier $temporalClassifier = null,
        private readonly ?StrategicForgettingService $strategicForgetting = null,
        private readonly ?AtlasMemoryRegistryService $registry = null,
        private readonly ?AtlasCaptureQualityGate $captureGate = null,
    ) {}

    /**
     * Run the scanner in observe mode.
     *
     * @return array<string,mixed>
     */
    public function scan(string $mode = self::MODE_OBSERVE): array
    {
        if (! in_array($mode, [self::MODE_OBSERVE, self::MODE_ENFORCE], true)) {
            throw new \InvalidArgumentException('unsupported memory consolidation mode.');
        }

        $freeze = AtlasMemoryTemporalQualityService::freezePayload();
        $thresholds = (array) $freeze['thresholds'];
        $cosineFloor = (float) ($thresholds['scanner_cosine_threshold'] ?? 0.82);
        $confidenceFloor = (float) ($thresholds['scanner_confidence_floor'] ?? 0.86);
        $minPairs = (int) ($thresholds['scanner_candidate_min_pairs'] ?? 12);
        $now = CarbonImmutable::now('UTC');

        if (! DatabaseTableAvailability::has('atlas_memory_entries')) {
            return $this->emptyReport('memory_table_missing', $cosineFloor, $confidenceFloor, $minPairs, $now);
        }

        /** @var Collection<int,AtlasMemoryEntry> $actives */
        $actives = AtlasMemoryEntry::query()
            ->where('status', self::ACTIVE_STATUS)
            ->whereNull('archived_at')
            ->orderBy('id')
            ->get();

        if ($actives->count() < 2) {
            return $this->emptyReport('active_set_too_small', $cosineFloor, $confidenceFloor, $minPairs, $now);
        }

        $activesById = [];
        foreach ($actives as $entry) {
            $id = (string) $entry->getAttribute('id');
            if ($id === '') {
                continue;
            }
            $activesById[$id] = $entry;
        }
        $activeIds = array_keys($activesById);

        $scorerAvailable = $this->scorer->available();
        $similaritySource = $scorerAvailable ? 'vector_pgvector' : 'unavailable';

        $proposals = [];
        $verdictCounts = [
            AtlasMemoryConflictResolutionService::VERDICT_RELATED => 0,
            AtlasMemoryConflictResolutionService::VERDICT_COMPATIBLE => 0,
            AtlasMemoryConflictResolutionService::VERDICT_SCOPED => 0,
            AtlasMemoryConflictResolutionService::VERDICT_CONFLICTS_WITH => 0,
            AtlasMemoryConflictResolutionService::VERDICT_SUPERSEDES => 0,
            AtlasMemoryConflictResolutionService::VERDICT_NOT_CONFLICT => 0,
        ];
        $pairsEvaluated = 0;
        $pairsSurfaced = 0;
        $escalations = 0;
        $seenPairs = [];

        if ($scorerAvailable) {
            foreach ($activeIds as $sourceId) {
                $source = $activesById[$sourceId];
                $query = $this->queryText($source);
                if ($query === '') {
                    continue;
                }
                $candidateIds = array_values(array_diff($activeIds, [$sourceId]));
                if ($candidateIds === []) {
                    continue;
                }
                $scores = $this->scorer->scoreEntries($query, $candidateIds);
                foreach ($scores as $targetId => $cosine) {
                    if ($cosine < $cosineFloor) {
                        continue;
                    }
                    $pairKey = $this->pairKey($sourceId, (string) $targetId);
                    if (isset($seenPairs[$pairKey])) {
                        continue;
                    }
                    $seenPairs[$pairKey] = true;
                    if ($pairsEvaluated >= self::MAX_PAIRS_EVALUATED) {
                        break 2;
                    }
                    $pairsEvaluated++;

                    $target = $activesById[(string) $targetId] ?? null;
                    if ($target === null) {
                        continue;
                    }
                    $proposal = $this->evaluatePair(
                        $source,
                        $target,
                        (float) $cosine,
                        $confidenceFloor,
                    );
                    $verdictCounts[$proposal['verdict']] = ($verdictCounts[$proposal['verdict']] ?? 0) + 1;
                    if ($proposal['escalate']) {
                        $escalations++;
                    }
                    if (! in_array($proposal['verdict'], self::DEGENERATE_VERBS, true)) {
                        $pairsSurfaced++;
                    }
                    $proposals[] = $proposal;
                }
            }
        }

        $nonDegenerateVerbCount = 0;
        foreach ($verdictCounts as $verb => $count) {
            if ($count > 0 && ! in_array($verb, self::DEGENERATE_VERBS, true)) {
                $nonDegenerateVerbCount++;
            }
        }
        $qualified = ($pairsEvaluated >= $minPairs) && ($nonDegenerateVerbCount >= 2);

        $enforce = $mode === self::MODE_ENFORCE
            ? $this->enforceProposals($proposals, $now)
            : ['applied' => 0, 'review_bucket' => 0, 'skipped' => 0, 'applications' => [], 'review_items' => []];
        $clusterSynthesis = $this->synthesizeClusters(
            $mode,
            $proposals,
            $activesById,
            $confidenceFloor,
            $now,
        );

        $ledgerPath = $this->appendLedger($proposals, $now, [
            'mode' => $mode,
            'similarity_source' => $similaritySource,
            'pairs_evaluated' => $pairsEvaluated,
            'pairs_surfaced' => $pairsSurfaced,
            'qualified' => $qualified,
            'relations_written' => (int) $enforce['applied'],
            'review_bucket' => (int) $enforce['review_bucket'],
            'cluster_synthesis' => [
                'status' => $clusterSynthesis['status'] ?? 'unknown',
                'applied' => $clusterSynthesis['applied'] ?? 0,
                'candidate_clusters' => $clusterSynthesis['candidate_clusters'] ?? 0,
            ],
        ]);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => $mode,
            'status' => $qualified ? 'qualified' : 'insufficient_signal',
            'similarity_source' => $similaritySource,
            'active_count' => count($activeIds),
            'pairs_evaluated' => $pairsEvaluated,
            'pairs_surfaced' => $pairsSurfaced,
            'escalations' => $escalations,
            'thresholds' => [
                'scanner_cosine_threshold' => $cosineFloor,
                'scanner_confidence_floor' => $confidenceFloor,
                'scanner_candidate_min_pairs' => $minPairs,
            ],
            'verdict_distribution' => $verdictCounts,
            'non_degenerate_verb_count' => $nonDegenerateVerbCount,
            'relations_written' => (int) $enforce['applied'],
            'enforce' => $enforce,
            'cluster_synthesis' => $clusterSynthesis,
            'ledger_path' => $ledgerPath,
            'proposal_count' => count($proposals),
            'proposals' => $proposals,
            'generated_at' => $now->toIso8601String(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function evaluatePair(
        AtlasMemoryEntry $source,
        AtlasMemoryEntry $target,
        float $cosine,
        float $confidenceFloor,
    ): array {
        $factA = $this->factFrom($source);
        $factB = $this->factFrom($target);

        $verbClassifier = $this->verbClassifier ?? new MemoryConflictVerbClassifier;
        $classified = $verbClassifier->classify($factA, $factB);
        $verdict = (string) $classified['verdict'];
        $verdictReason = (string) $classified['reason'];
        $escalate = (bool) $classified['escalate'];

        $axisResolver = $this->axisResolver ?? new MemoryConflictAxisResolver;
        $axis = null;
        if ($verdict === AtlasMemoryConflictResolutionService::VERDICT_CONFLICTS_WITH
            || $verdict === AtlasMemoryConflictResolutionService::VERDICT_SUPERSEDES) {
            $axis = $axisResolver->resolve(
                $this->axisSide($source, 'a'),
                $this->axisSide($target, 'b'),
            );
        }

        $scopeClassifier = $this->scopeClassifier ?? new MemoryScopeContradictionClassifier;
        // The scope kernel compares two sides of the same logical key at
        // different scope_rank. Without a scope-rank ontology on the entry we
        // rank by scope_type breadth: global < project < task < session. Same
        // rank + same key falls into R2 (no_contradiction) which is a safe
        // observe-mode default when the corpus does not yet carry a rank.
        $scopeContradiction = $scopeClassifier->classify(
            $this->scopeSide($source, $factA),
            $this->scopeSide($target, $factB),
        );

        $factPolarity = $this->factPolarity ?? new FactPairPolarityContradictionDetector;
        $polarityContradiction = $factPolarity->detect($factA, $factB);

        $numeric = $this->numericRange ?? new NumericRangeOverlapContradictionDetector;
        [$aMin, $aMax] = $this->numericRangeFor($source);
        [$bMin, $bMax] = $this->numericRangeFor($target);
        $numericOverlap = ($aMin !== null && $aMax !== null && $bMin !== null && $bMax !== null)
            ? $numeric->detect($aMin, $aMax, $bMin, $bMax)
            : null;

        $temporal = $this->temporalClassifier ?? new TemporalSupersessionClassifier;
        $temporalVerdict = $temporal->classify(
            (int) $factA['recorded_ts'],
            (int) $factB['recorded_ts'],
            ($factA['key'] !== '' && $factA['key'] === $factB['key']),
        );
        $maxh06AxisResolved = false;
        if ($verdict === AtlasMemoryConflictResolutionService::VERDICT_CONFLICTS_WITH) {
            $axisDirection = $this->axisSupersessionDirection($axis);
            if ($axisDirection !== null) {
                $verdict = AtlasMemoryConflictResolutionService::VERDICT_SUPERSEDES;
                $verdictReason = 'maxh06_axis_resolution:'.(string) ($axis['decisive_axis'] ?? 'unknown');
                $temporalVerdict = $axisDirection;
                $maxh06AxisResolved = true;
            }
        }

        // Cosine is the primary confidence signal in observe mode; kernels only
        // gate the verb, they don't produce a confidence themselves. The freeze
        // pins the floor at 0.86 — a proposal below that is kept in the ledger
        // but flagged `below_confidence_floor` so MAXH-04 (enforce) knows to
        // ignore it. Never invented, never smoothed.
        $confidence = round($cosine, 4);
        $meetsFloor = $confidence >= $confidenceFloor;

        return [
            'pair_hash' => $this->pairKey(
                (string) $source->getAttribute('id'),
                (string) $target->getAttribute('id'),
            ),
            'source_id' => (string) $source->getAttribute('id'),
            'target_id' => (string) $target->getAttribute('id'),
            'cosine' => round($cosine, 6),
            'verdict' => $verdict,
            'verdict_reason' => $verdictReason,
            'escalate' => $escalate,
            'confidence' => $confidence,
            'confidence_floor_ok' => $meetsFloor,
            'axis_resolution' => $axis,
            'scope_contradiction' => $scopeContradiction,
            'polarity_contradiction' => $polarityContradiction,
            'numeric_range_overlap' => $numericOverlap,
            'temporal_supersession' => $temporalVerdict,
            'maxh06_axis_resolved' => $maxh06AxisResolved,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $axis
     */
    private function axisSupersessionDirection(?array $axis): ?string
    {
        if (! is_array($axis)) {
            return null;
        }

        return match ((string) ($axis['winner'] ?? '')) {
            'a' => 'a_supersedes_b',
            'b' => 'b_supersedes_a',
            default => null,
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function factFrom(AtlasMemoryEntry $entry): array
    {
        return [
            'key' => $this->normalizedKey($entry),
            'scope_type' => (string) ($entry->getAttribute('scope_type') ?? ''),
            'recorded_ts' => $this->recordedTimestamp($entry),
            'memory_type' => (string) ($entry->getAttribute('memory_type') ?? ''),
            'polarity' => (string) data_get($entry->getAttribute('metadata'), 'polarity', 'affirm'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function axisSide(AtlasMemoryEntry $entry, string $label): array
    {
        return [
            'label' => $label,
            'authority_rank' => $this->authorityRank($entry),
            'evidence_count' => 0,
            'recorded_ts' => $this->recordedTimestamp($entry),
        ];
    }

    /**
     * @param  array<string,mixed>  $fact
     * @return array<string,mixed>
     */
    private function scopeSide(AtlasMemoryEntry $entry, array $fact): array
    {
        return [
            'key' => (string) $fact['key'],
            'scope_rank' => $this->scopeRank((string) ($entry->getAttribute('scope_type') ?? '')),
            'polarity' => (string) $fact['polarity'],
            'memory_type' => (string) $fact['memory_type'],
        ];
    }

    private function scopeRank(string $scopeType): int
    {
        return match ($scopeType) {
            'global' => 0,
            'project' => 1,
            'task' => 2,
            'session' => 3,
            default => 0,
        };
    }

    /**
     * @return array{0: ?float, 1: ?float}
     */
    private function numericRangeFor(AtlasMemoryEntry $entry): array
    {
        $metadata = $entry->getAttribute('metadata');
        if (! is_array($metadata)) {
            return [null, null];
        }
        $range = $metadata['numeric_range'] ?? null;
        if (! is_array($range)) {
            return [null, null];
        }
        $min = $range['min'] ?? null;
        $max = $range['max'] ?? null;

        return [
            AiValueNormalizer::finiteFloatOrNull($min),
            AiValueNormalizer::finiteFloatOrNull($max),
        ];
    }

    private function authorityRank(AtlasMemoryEntry $entry): int
    {
        $level = (string) ($entry->getAttribute('authority_level') ?? '');

        return match ($level) {
            'canonical' => 0,
            'policy' => 1,
            'confirmed' => 2,
            'operational' => 3,
            'observational' => 4,
            default => 5,
        };
    }

    private function recordedTimestamp(AtlasMemoryEntry $entry): int
    {
        $recorded = $entry->getAttribute('recorded_at');
        if ($recorded === null) {
            return 0;
        }
        if (method_exists($recorded, 'getTimestamp')) {
            return (int) $recorded->getTimestamp();
        }
        if (is_string($recorded)) {
            $ts = strtotime($recorded);

            return $ts === false ? 0 : $ts;
        }

        return 0;
    }

    private function normalizedKey(AtlasMemoryEntry $entry): string
    {
        $title = (string) ($entry->getAttribute('title') ?? '');
        $key = trim($title);
        if ($key === '') {
            $key = (string) ($entry->getAttribute('content_hash') ?? '');
        }
        $key = mb_strtolower($key, 'UTF-8');
        $key = (string) preg_replace('/\s+/u', ' ', $key);

        return $key;
    }

    private function queryText(AtlasMemoryEntry $entry): string
    {
        $parts = [];
        foreach (['summary', 'title', 'body'] as $field) {
            $value = $entry->getAttribute($field);
            if (is_string($value) && trim($value) !== '') {
                $parts[] = trim($value);
                break;
            }
        }

        return $parts === [] ? '' : implode("\n", $parts);
    }

    private function pairKey(string $a, string $b): string
    {
        $ordered = [$a, $b];
        sort($ordered);

        return hash('sha256', $ordered[0].'|'.$ordered[1]);
    }

    /**
     * MAXH-07 — default-off redundant cluster synthesis.
     *
     * The AUTHOR is deterministic/local and only sees already-active memory rows.
     * The JUDGES are separate: CaptureQualityGate, ASI-02 admission via
     * AtlasMemoryRegistryService, and the frozen confidence floor. Clusters are
     * sourced from real scanner proposals plus StrategicForgetting `compress`
     * decisions; no caller can inject a prebuilt/simulated cluster.
     *
     * @param  list<array<string,mixed>>  $proposals
     * @param  array<string,AtlasMemoryEntry>  $activesById
     * @return array<string,mixed>
     */
    private function synthesizeClusters(
        string $mode,
        array $proposals,
        array $activesById,
        float $confidenceFloor,
        CarbonImmutable $now,
    ): array {
        if (! (bool) config('atlas.memory_consolidation.cluster_synthesis_enabled', false)) {
            return $this->clusterSynthesisDisabledReport();
        }

        $minMembers = max(3, (int) config('atlas.memory_consolidation.cluster_synthesis_min_members', 3));
        $author = trim((string) config('atlas.memory_consolidation.cluster_synthesis_author_engine_id', 'cursor-acos-max-maxh07-cluster-author'));
        $judge = trim((string) config('atlas.memory_consolidation.cluster_synthesis_judge_engine_id', 'codex-independent-maxh07-cluster-judge'));
        if ($author === '' || $judge === '' || $author === $judge) {
            return $this->clusterSynthesisReport('blocked', [], [[
                'reason' => 'author_judge_invariant_violation',
                'author_engine_id' => $author,
                'judge_engine_id' => $judge,
            ]], [], $minMembers);
        }

        $clusters = $this->clusterCandidates($proposals, $activesById, $minMembers, $now);
        if ($clusters === []) {
            return $this->clusterSynthesisReport('no_qualified_cluster', [], [], [], $minMembers);
        }

        $applications = [];
        $refusals = [];
        $shadow = [];
        foreach ($clusters as $memberIds) {
            $members = array_values(array_filter(
                array_map(static fn (string $id): ?AtlasMemoryEntry => $activesById[$id] ?? null, $memberIds),
            ));
            if (count($members) < $minMembers) {
                continue;
            }
            if ($this->containsSimulatedClusterMember($members)) {
                $refusals[] = [
                    'reason' => 'simulated_live_cluster_refused',
                    'member_ids' => array_map(static fn (AtlasMemoryEntry $entry): string => (string) $entry->getAttribute('id'), $members),
                ];

                continue;
            }

            $authored = $this->authorClusterCanonical($members, $author, $judge, $now);
            $judgment = $this->judgeClusterCanonical($authored, $members, $confidenceFloor, $judge);
            if (($judgment['admit'] ?? false) !== true) {
                $refusals[] = $judgment + [
                    'member_ids' => array_map(static fn (AtlasMemoryEntry $entry): string => (string) $entry->getAttribute('id'), $members),
                ];

                continue;
            }

            if ($mode !== self::MODE_ENFORCE) {
                $shadow[] = [
                    'member_ids' => array_map(static fn (AtlasMemoryEntry $entry): string => (string) $entry->getAttribute('id'), $members),
                    'candidate_hash' => hash('sha256', json_encode($authored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
                ];

                continue;
            }

            $applications[] = $this->applyClusterSynthesis($authored, $members, $now);
        }

        $status = match (true) {
            $applications !== [] => 'applied',
            $shadow !== [] => 'shadow_proposed',
            $refusals !== [] => 'refused',
            default => 'no_qualified_cluster',
        };

        return $this->clusterSynthesisReport($status, $applications, $refusals, $shadow, $minMembers, count($clusters));
    }

    /**
     * @return array<string,mixed>
     */
    private function clusterSynthesisDisabledReport(): array
    {
        return [
            'schema_version' => self::CLUSTER_SYNTHESIS_SCHEMA_VERSION,
            'status' => 'disabled',
            'enabled' => false,
            'candidate_clusters' => 0,
            'applied' => 0,
            'refusals' => [],
            'shadow_proposals' => [],
            'applications' => [],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $applications
     * @param  list<array<string,mixed>>  $refusals
     * @param  list<array<string,mixed>>  $shadow
     * @return array<string,mixed>
     */
    private function clusterSynthesisReport(
        string $status,
        array $applications,
        array $refusals,
        array $shadow,
        int $minMembers,
        int $candidateClusters = 0,
    ): array {
        return [
            'schema_version' => self::CLUSTER_SYNTHESIS_SCHEMA_VERSION,
            'status' => $status,
            'enabled' => true,
            'min_members' => $minMembers,
            'candidate_clusters' => $candidateClusters,
            'applied' => count($applications),
            'refusals' => $refusals,
            'shadow_proposals' => $shadow,
            'applications' => $applications,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $proposals
     * @param  array<string,AtlasMemoryEntry>  $activesById
     * @return list<list<string>>
     */
    private function clusterCandidates(array $proposals, array $activesById, int $minMembers, CarbonImmutable $now): array
    {
        $compressIds = $this->strategicCompressIds($now);
        if ($compressIds === []) {
            return [];
        }

        $allowed = array_fill_keys(array_values(array_intersect(array_keys($activesById), $compressIds)), true);
        $eligible = [
            AtlasMemoryConflictResolutionService::VERDICT_RELATED => true,
            AtlasMemoryConflictResolutionService::VERDICT_COMPATIBLE => true,
        ];
        $adjacency = [];
        foreach ($proposals as $proposal) {
            $sourceId = (string) ($proposal['source_id'] ?? '');
            $targetId = (string) ($proposal['target_id'] ?? '');
            if ($sourceId === '' || $targetId === '' || ! isset($allowed[$sourceId], $allowed[$targetId])) {
                continue;
            }
            if (! isset($eligible[(string) ($proposal['verdict'] ?? '')])) {
                continue;
            }
            if (($proposal['confidence_floor_ok'] ?? false) !== true) {
                continue;
            }
            $adjacency[$sourceId][$targetId] = true;
            $adjacency[$targetId][$sourceId] = true;
        }

        $ids = array_values(array_unique(array_keys($adjacency)));
        sort($ids);
        $clusters = [];
        $used = [];
        $count = count($ids);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                for ($k = $j + 1; $k < $count; $k++) {
                    $seed = [$ids[$i], $ids[$j], $ids[$k]];
                    if (array_intersect($seed, $used) !== []) {
                        continue;
                    }
                    if (! $this->completeCluster($seed, $adjacency)) {
                        continue;
                    }
                    $cluster = $seed;
                    foreach ($ids as $candidate) {
                        if (in_array($candidate, $cluster, true) || in_array($candidate, $used, true)) {
                            continue;
                        }
                        if ($this->connectedToAll($candidate, $cluster, $adjacency)) {
                            $cluster[] = $candidate;
                        }
                    }
                    if (count($cluster) >= $minMembers) {
                        sort($cluster);
                        $clusters[] = $cluster;
                        array_push($used, ...$cluster);
                    }
                }
            }
        }

        return $clusters;
    }

    /**
     * @return list<string>
     */
    private function strategicCompressIds(CarbonImmutable $now): array
    {
        $plan = ($this->strategicForgetting ?? new StrategicForgettingService)->plan([
            'now' => $now,
            'limit' => 500,
        ]);
        if (($plan['status'] ?? null) !== StrategicForgettingService::STATUS_READY) {
            return [];
        }

        $ids = [];
        foreach ((array) ($plan['decisions'] ?? []) as $decision) {
            if (($decision['policy'] ?? null) === AtlasLongHorizonCanon::FORGETTING_POLICY_COMPRESS) {
                $id = (string) ($decision['memory_entry_id'] ?? '');
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string,array<string,bool>>  $adjacency
     */
    private function completeCluster(array $ids, array $adjacency): bool
    {
        foreach ($ids as $source) {
            foreach ($ids as $target) {
                if ($source === $target) {
                    continue;
                }
                if (! isset($adjacency[$source][$target])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $cluster
     * @param  array<string,array<string,bool>>  $adjacency
     */
    private function connectedToAll(string $candidate, array $cluster, array $adjacency): bool
    {
        foreach ($cluster as $member) {
            if (! isset($adjacency[$candidate][$member])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<AtlasMemoryEntry>  $members
     */
    private function containsSimulatedClusterMember(array $members): bool
    {
        foreach ($members as $member) {
            if ((bool) data_get($member->getAttribute('metadata'), 'acos_max.maxh07.simulated_cluster', false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<AtlasMemoryEntry>  $members
     * @return array<string,mixed>
     */
    private function authorClusterCanonical(array $members, string $author, string $judge, CarbonImmutable $now): array
    {
        $title = $this->commonTitle($members);
        $memberIds = [];
        $evidenceRefs = [];
        $bodyLines = [
            'Canonical synthesis of a redundant Atlas memory cluster.',
            '',
        ];
        foreach ($members as $member) {
            $id = (string) $member->getAttribute('id');
            $memberIds[] = $id;
            $evidenceRefs[] = 'memory_entry:'.$id;
            $sourceType = (string) ($member->getAttribute('source_type') ?? '');
            $sourceId = (string) ($member->getAttribute('source_id') ?? '');
            if ($sourceType !== '' && $sourceId !== '') {
                $evidenceRefs[] = $sourceType.':'.$sourceId;
            }
            $summary = trim((string) ($member->getAttribute('summary') ?: $member->getAttribute('body') ?: $member->getAttribute('title')));
            $bodyLines[] = '- '.$summary.' [memory_entry:'.$id.']';
        }
        $memberIds = array_values(array_unique($memberIds));
        sort($memberIds);
        $evidenceRefs = array_values(array_unique($evidenceRefs));

        $privacyClass = $this->clusterPrivacyClass($members);
        $providerSafe = ! in_array($privacyClass, ['secret', 'sensitive'], true)
            && ! in_array(false, array_map(static fn (AtlasMemoryEntry $entry): bool => (bool) $entry->getAttribute('external_ai_allowed'), $members), true);
        $metadata = [
            'acos_max' => [
                'maxh07' => [
                    'schema_version' => self::CLUSTER_SYNTHESIS_SCHEMA_VERSION,
                    'author_engine_id' => $author,
                    'judge_engine_id' => $judge,
                    'author_neq_judge' => $author !== $judge,
                    'member_ids' => $memberIds,
                    'evidence_refs' => $evidenceRefs,
                    'reversible' => true,
                    'authored_at' => $now->toIso8601String(),
                ],
            ],
            'evidence_refs' => $evidenceRefs,
            'outcome_validated' => true,
            'promotion_mode_hint' => 'maxh07_cluster_synthesis',
        ];

        return [
            'memory_type' => $this->clusterMemoryType($members),
            'scope_type' => $this->clusterScopeType($members),
            'scope_id' => $this->clusterScopeId($members),
            'title' => 'Canonical synthesis: '.$title,
            'body' => implode("\n", $bodyLines),
            'summary' => 'Canonical synthesis of '.count($members).' redundant memory entries for '.$title.'.',
            'confidence' => min(0.99, max(0.0, $this->clusterConfidence($members))),
            'privacy_class' => $privacyClass,
            'external_ai_allowed' => $providerSafe,
            'redaction_status' => 'clean',
            'source_type' => 'maxh07_cluster_synthesis',
            'source_id' => hash('sha256', implode('|', $memberIds)),
            'source_label' => 'MAXH-07 redundant memory cluster synthesis',
            'status' => 'active',
            'tags' => ['acos-max', 'maxh07', 'cluster-synthesis'],
            'metadata' => $metadata,
            'recorded_at' => $now,
            'last_used_at' => $now,
            'authority_level' => 'confirmed',
        ];
    }

    /**
     * @param  array<string,mixed>  $authored
     * @param  list<AtlasMemoryEntry>  $members
     * @return array<string,mixed>
     */
    private function judgeClusterCanonical(array $authored, array $members, float $confidenceFloor, string $judge): array
    {
        unset($confidenceFloor); // The floor is enforced on every pairwise edge before a cluster can reach this judge.
        foreach ($members as $member) {
            if (! in_array('memory_entry:'.$member->getAttribute('id'), (array) data_get($authored, 'metadata.evidence_refs', []), true)) {
                return ['admit' => false, 'reason' => 'missing_member_evidence_ref'];
            }
        }

        $gate = ($this->captureGate ?? app(AtlasCaptureQualityGate::class))->assess([
            'kind' => 'maxh07_cluster_synthesis',
            'claim' => (string) ($authored['summary'] ?? ''),
            'content' => $authored,
        ]);
        if ((string) config('atlas.ai.capture_quality_gate.mode', 'observe') === 'enforce'
            && ($gate['admit'] ?? false) !== true) {
            return ['admit' => false, 'reason' => 'capture_quality_gate', 'capture_quality' => $gate];
        }

        $admission = ($this->registry ?? app(AtlasMemoryRegistryService::class))->evaluateAdmission($authored, $judge);
        if (($admission['blocks_write'] ?? false) === true) {
            return ['admit' => false, 'reason' => 'asi_02_admission_blocked', 'admission' => $admission];
        }

        return ['admit' => true, 'capture_quality' => $gate, 'admission' => $admission];
    }

    /**
     * @param  array<string,mixed>  $authored
     * @param  list<AtlasMemoryEntry>  $members
     * @return array<string,mixed>
     */
    private function applyClusterSynthesis(array $authored, array $members, CarbonImmutable $now): array
    {
        $previous = [];
        foreach ($members as $member) {
            $previous[(string) $member->getAttribute('id')] = [
                'status' => (string) $member->getAttribute('status'),
                'archived_at' => $member->archived_at?->toIso8601String(),
                'superseded_by_id' => $member->getAttribute('superseded_by_id'),
                'valid_until' => $member->valid_until?->toIso8601String(),
            ];
        }
        data_set($authored, 'metadata.acos_max.maxh07.previous_members', $previous);

        $canonical = ($this->registry ?? app(AtlasMemoryRegistryService::class))->record($authored);
        foreach ($members as $member) {
            AtlasMemoryEntryRelation::query()->create([
                'id' => (string) Str::uuid(),
                'source_memory_entry_id' => (string) $member->getAttribute('id'),
                'target_memory_entry_id' => (string) $canonical->getAttribute('id'),
                'relation_type' => AtlasMemoryConflictResolutionService::VERDICT_SUPERSEDES,
                'status' => 'resolved',
                'confidence' => (float) ($authored['confidence'] ?? 0.0),
                'reason' => 'MAXH-07 cluster synthesis supersedence.',
                'metadata' => [
                    'maxh07' => true,
                    'canonical_memory_entry_id' => (string) $canonical->getAttribute('id'),
                    'member_id' => (string) $member->getAttribute('id'),
                ],
                'marked_by_actor' => AtlasMemoryConflictResolutionService::ACTOR_ATLAS,
                'marked_by_model' => (string) data_get($authored, 'metadata.acos_max.maxh07.judge_engine_id'),
                'judgment_status' => 'judged',
                'evidence_refs' => ['memory_entry:'.$member->getAttribute('id')],
                'verdict_schema_version' => self::CLUSTER_SYNTHESIS_SCHEMA_VERSION,
            ]);
            $member->forceFill([
                'status' => 'archived',
                'archived_at' => $now,
                'superseded_by_id' => (string) $canonical->getAttribute('id'),
                'valid_until' => $now,
            ])->save();
        }

        return [
            'canonical_memory_entry_id' => (string) $canonical->getAttribute('id'),
            'member_ids' => array_map(static fn (AtlasMemoryEntry $entry): string => (string) $entry->getAttribute('id'), $members),
            'reverse_handle' => 'maxh07:'.$canonical->getAttribute('id'),
        ];
    }

    /**
     * @param  list<AtlasMemoryEntry>  $members
     */
    private function commonTitle(array $members): string
    {
        $titles = array_values(array_unique(array_filter(array_map(
            static fn (AtlasMemoryEntry $entry): string => trim((string) $entry->getAttribute('title')),
            $members,
        ))));

        return $titles[0] ?? 'memory cluster';
    }

    /**
     * @param  list<AtlasMemoryEntry>  $members
     */
    private function clusterMemoryType(array $members): string
    {
        $types = array_values(array_unique(array_filter(array_map(
            static fn (AtlasMemoryEntry $entry): string => (string) $entry->getAttribute('memory_type'),
            $members,
        ))));

        return count($types) === 1 ? $types[0] : 'technical_context';
    }

    /**
     * @param  list<AtlasMemoryEntry>  $members
     */
    private function clusterScopeType(array $members): string
    {
        $scopes = array_values(array_unique(array_map(
            static fn (AtlasMemoryEntry $entry): string => (string) $entry->getAttribute('scope_type'),
            $members,
        )));

        return count($scopes) === 1 ? $scopes[0] : 'global';
    }

    /**
     * @param  list<AtlasMemoryEntry>  $members
     */
    private function clusterScopeId(array $members): ?string
    {
        $scopeIds = array_values(array_unique(array_map(
            static fn (AtlasMemoryEntry $entry): string => (string) ($entry->getAttribute('scope_id') ?? ''),
            $members,
        )));

        return count($scopeIds) === 1 && $scopeIds[0] !== '' ? $scopeIds[0] : null;
    }

    /**
     * @param  list<AtlasMemoryEntry>  $members
     */
    private function clusterPrivacyClass(array $members): string
    {
        $rank = ['normal' => 0, 'private' => 1, 'sensitive' => 2, 'secret' => 3];
        $winner = 'normal';
        foreach ($members as $member) {
            $privacy = (string) ($member->getAttribute('privacy_class') ?? 'normal');
            if (($rank[$privacy] ?? 0) > ($rank[$winner] ?? 0)) {
                $winner = $privacy;
            }
        }

        return $winner;
    }

    /**
     * @param  list<AtlasMemoryEntry>  $members
     */
    private function clusterConfidence(array $members): float
    {
        $values = array_values(array_filter(array_map(
            static fn (AtlasMemoryEntry $entry): ?float => AiValueNormalizer::finiteFloatOrNull($entry->getAttribute('confidence')),
            $members,
        ), static fn (?float $value): bool => $value !== null));
        if ($values === []) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }

    /**
     * @param  list<array<string,mixed>>  $proposals
     * @param  array<string,mixed>  $summary
     */
    private function appendLedger(array $proposals, CarbonImmutable $at, array $summary): string
    {
        $root = (string) config('atlas.memory_consolidation.ledger_root');
        if ($root === '') {
            return '';
        }
        if (! is_dir($root)) {
            @mkdir($root, 0755, true);
        }
        $date = $at->format('Y-m-d');
        $path = $root.'/consolidation-proposals-'.$date.'.ndjson';

        $header = [
            'schema_version' => self::SCHEMA_VERSION.'#header',
            'kind' => 'scan_summary',
            'generated_at' => $at->toIso8601String(),
        ] + $summary;

        $lines = [$this->encode($header)];
        foreach ($proposals as $proposal) {
            $lines[] = $this->encode([
                'schema_version' => self::SCHEMA_VERSION,
                'kind' => 'proposal',
                'generated_at' => $at->toIso8601String(),
                'proposal_id' => (string) Str::orderedUuid(),
                'payload' => $proposal,
            ]);
        }
        @file_put_contents($path, implode("\n", $lines)."\n", FILE_APPEND | LOCK_EX);

        return $path;
    }

    /**
     * @param  list<array<string,mixed>>  $proposals
     * @return array{applied:int,review_bucket:int,skipped:int,applications:list<array<string,mixed>>,review_items:list<array<string,mixed>>}
     */
    private function enforceProposals(array $proposals, CarbonImmutable $now): array
    {
        $applied = [];
        $review = [];
        $skipped = 0;
        $resolver = new AtlasMemoryConflictResolutionService;

        foreach ($proposals as $proposal) {
            if (($proposal['verdict'] ?? null) !== AtlasMemoryConflictResolutionService::VERDICT_SUPERSEDES
                || ($proposal['confidence_floor_ok'] ?? false) !== true) {
                $skipped++;

                continue;
            }
            if (($proposal['escalate'] ?? false) === true) {
                $review[] = $proposal + ['held_reason' => 'high_risk_memory_type'];

                continue;
            }

            $direction = (string) ($proposal['temporal_supersession'] ?? '');
            $winnerId = $direction === 'a_supersedes_b'
                ? (string) $proposal['source_id']
                : ($direction === 'b_supersedes_a' ? (string) $proposal['target_id'] : '');
            $loserId = $direction === 'a_supersedes_b'
                ? (string) $proposal['target_id']
                : ($direction === 'b_supersedes_a' ? (string) $proposal['source_id'] : '');
            if ($winnerId === '' || $loserId === '') {
                $skipped++;

                continue;
            }

            $loser = AtlasMemoryEntry::query()->find($loserId);
            if ($loser === null) {
                $skipped++;

                continue;
            }
            $previous = [
                'superseded_by_id' => $loser->superseded_by_id,
                'valid_until' => $loser->valid_until?->toIso8601String(),
            ];

            $judgment = $resolver->judge($loserId, $winnerId, AtlasMemoryConflictResolutionService::VERDICT_SUPERSEDES, [
                'actor' => AtlasMemoryConflictResolutionService::ACTOR_ATLAS,
                'confidence' => (float) ($proposal['confidence'] ?? 0.0),
                'reason' => 'MAXH-04 consolidation enforce supersedence.',
                'evidence_refs' => ['pair_hash:'.(string) ($proposal['pair_hash'] ?? '')],
                'allow_escalation_bypass' => false,
            ]);
            if (($judgment['ok'] ?? false) !== true) {
                $skipped++;

                continue;
            }

            $relationId = (string) ($judgment['relation_id'] ?? '');
            AtlasMemoryEntryRelation::query()
                ->whereKey($relationId)
                ->update([
                    'status' => 'resolved',
                    'metadata' => [
                        'maxh04' => true,
                        'previous' => $previous,
                        'winner_id' => $winnerId,
                        'loser_id' => $loserId,
                        'pair_hash' => (string) ($proposal['pair_hash'] ?? ''),
                    ],
                ]);

            $loser->forceFill([
                'superseded_by_id' => $winnerId,
                'valid_until' => $now,
            ])->save();

            $handle = 'maxh04:'.$relationId;
            $applied[] = [
                'relation_id' => $relationId,
                'source_memory_entry_id' => $loserId,
                'target_memory_entry_id' => $winnerId,
                'reverse_handle' => $handle,
            ];
        }

        return [
            'applied' => count($applied),
            'review_bucket' => count($review),
            'skipped' => $skipped,
            'applications' => $applied,
            'review_items' => $review,
        ];
    }

    /** @return array{ok:bool,reason?:string} */
    public function reverseApplication(string $handle): array
    {
        if (str_starts_with($handle, 'maxh07:')) {
            return $this->reverseClusterApplication(substr($handle, strlen('maxh07:')));
        }

        if (! str_starts_with($handle, 'maxh04:')) {
            return ['ok' => false, 'reason' => 'invalid_reverse_handle'];
        }
        $relationId = substr($handle, strlen('maxh04:'));
        /** @var AtlasMemoryEntryRelation|null $relation */
        $relation = AtlasMemoryEntryRelation::query()->find($relationId);
        if ($relation === null) {
            return ['ok' => false, 'reason' => 'relation_not_found'];
        }
        $metadata = (array) $relation->metadata;
        $previous = (array) data_get($metadata, 'previous', []);
        $loserId = (string) data_get($metadata, 'loser_id', $relation->source_memory_entry_id);
        $loser = AtlasMemoryEntry::query()->find($loserId);
        if ($loser === null) {
            return ['ok' => false, 'reason' => 'memory_not_found'];
        }

        $loser->forceFill([
            'superseded_by_id' => $previous['superseded_by_id'] ?? null,
            'valid_until' => $previous['valid_until'] ?? null,
        ])->save();
        $relation->forceFill(['status' => 'dismissed'])->save();

        return ['ok' => true];
    }

    /** @return array{ok:bool,reason?:string} */
    private function reverseClusterApplication(string $canonicalId): array
    {
        /** @var AtlasMemoryEntry|null $canonical */
        $canonical = AtlasMemoryEntry::query()->find($canonicalId);
        if ($canonical === null) {
            return ['ok' => false, 'reason' => 'canonical_memory_not_found'];
        }
        $previousMembers = (array) data_get($canonical->metadata, 'acos_max.maxh07.previous_members', []);
        if ($previousMembers === []) {
            return ['ok' => false, 'reason' => 'cluster_previous_state_missing'];
        }

        foreach ($previousMembers as $memberId => $previous) {
            $member = AtlasMemoryEntry::query()->find((string) $memberId);
            if ($member === null) {
                continue;
            }
            $member->forceFill([
                'status' => (string) ($previous['status'] ?? 'active'),
                'archived_at' => $previous['archived_at'] ?? null,
                'superseded_by_id' => $previous['superseded_by_id'] ?? null,
                'valid_until' => $previous['valid_until'] ?? null,
            ])->save();
        }

        AtlasMemoryEntryRelation::query()
            ->where('target_memory_entry_id', $canonicalId)
            ->where('relation_type', AtlasMemoryConflictResolutionService::VERDICT_SUPERSEDES)
            ->where('metadata->maxh07', true)
            ->update(['status' => 'dismissed']);

        $canonical->forceFill([
            'status' => 'archived',
            'archived_at' => CarbonImmutable::now('UTC'),
        ])->save();

        return ['ok' => true];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return (string) json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyReport(
        string $reason,
        float $cosineFloor,
        float $confidenceFloor,
        int $minPairs,
        CarbonImmutable $now,
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE_OBSERVE,
            'status' => 'insufficient_signal',
            'reason' => $reason,
            'similarity_source' => 'unavailable',
            'active_count' => 0,
            'pairs_evaluated' => 0,
            'pairs_surfaced' => 0,
            'escalations' => 0,
            'thresholds' => [
                'scanner_cosine_threshold' => $cosineFloor,
                'scanner_confidence_floor' => $confidenceFloor,
                'scanner_candidate_min_pairs' => $minPairs,
            ],
            'verdict_distribution' => [],
            'non_degenerate_verb_count' => 0,
            'relations_written' => 0,
            'ledger_path' => '',
            'proposal_count' => 0,
            'proposals' => [],
            'generated_at' => $now->toIso8601String(),
        ];
    }
}
