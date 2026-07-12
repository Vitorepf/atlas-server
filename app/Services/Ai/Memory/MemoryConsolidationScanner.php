<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryRelation;
use App\Services\Ai\Cognition\FactPairPolarityContradictionDetector;
use App\Services\Ai\Cognition\NumericRangeOverlapContradictionDetector;
use App\Services\Ai\Cognition\TemporalSupersessionClassifier;
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

        $ledgerPath = $this->appendLedger($proposals, $now, [
            'mode' => $mode,
            'similarity_source' => $similaritySource,
            'pairs_evaluated' => $pairsEvaluated,
            'pairs_surfaced' => $pairsSurfaced,
            'qualified' => $qualified,
            'relations_written' => (int) $enforce['applied'],
            'review_bucket' => (int) $enforce['review_bucket'],
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
        ];
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
            is_numeric($min) ? (float) $min : null,
            is_numeric($max) ? (float) $max : null,
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
