<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

use App\Models\AiLearningCandidate;
use App\Services\Ai\Cognition\AcosProgram\AcosMaxLote2MeasureService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;

/**
 * MULTJ-06 — abstraction ladder aggregator: tactical (1) → pattern (2) →
 * principle (3) with `case_count` per level.
 *
 * Invariants:
 * - Uses `caused_by.primary_cause` (MAXJ-02) across DISTINCT signatures.
 * - Level-3 principles carry `abstraction_level: 3` + `derived_from[]` of
 *   resolvable pattern refs (`pattern:<signature_key>`).
 * - Enqueue (when flag ON) writes to `ai_learning_candidates` — the SAME
 *   queue as the distiller / ASI-02 door (MAXJ-07 pattern). NEVER a parallel
 *   queue. Frontier NEVER auto-promotes; gates judge.
 * - Default-OFF: `propose()` is read-only; enqueue is a no-op unless enabled.
 * - Pack injection preference lives in {@see AtlasLearningAbstractionPackSelector}
 *   (wiring pending — seam not hooked in this slice).
 */
final class AtlasLearningAbstractionLadderService
{
    use CompoundingEvidenceHelper;

    public const SCHEMA_VERSION = 'atlas.ai.learning_abstraction_ladder.v1';

    public const LEVEL_TACTICAL = 1;

    public const LEVEL_PATTERN = 2;

    public const LEVEL_PRINCIPLE = 3;

    public const PATTERN_REF_PREFIX = 'pattern:';

    public const UNKNOWN_PRIMARY_CAUSE = 'unknown';

    public function __construct(
        private readonly ?AtlasLearningAbstractionDerivedFromGate $derivedFromGate = null,
        private readonly ?AtlasLearningAbstractionPackSelector $packSelector = null,
        private readonly ?DistillerAuthorAdapter $author = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function propose(?int $k = null, bool $enqueue = false): array
    {
        $freeze = AcosMaxLote2MeasureService::freezePayload('MULTJ-06');
        $effectiveK = max(1, (int) ($k ?? data_get($freeze, 'thresholds.distinct_signature_k', 3)));
        $patternFloor = max(1, (int) data_get($freeze, 'thresholds.pattern_floor', 1));
        $enqueueRequested = $enqueue && $this->enqueueEnabled();

        if (! DatabaseTableAvailability::has('ai_learning_candidates')) {
            return $this->emptyReport($effectiveK, $patternFloor, 'table_missing', $freeze);
        }

        /** @var list<AiLearningCandidate> $rows */
        $rows = AiLearningCandidate::query()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->all();

        if ($rows === []) {
            return $this->emptyReport($effectiveK, $patternFloor, 'insufficient_signal', $freeze);
        }

        [$tactical, $patterns, $patternIndex, $stats] = $this->buildLevels($rows, $patternFloor);
        $principles = $this->buildPrinciples($patterns, $patternIndex, $effectiveK);
        $gate = $this->derivedFromGate ?? new AtlasLearningAbstractionDerivedFromGate;

        $accepted = [];
        $rejected = [];
        foreach ($principles as $proposal) {
            $verdict = $gate->assess($proposal, $patternIndex);
            $proposal['gate'] = [
                'schema_version' => AtlasLearningAbstractionDerivedFromGate::SCHEMA_VERSION,
                'admit' => $verdict['admit'],
                'reason' => $verdict['reason'],
                'unresolved' => $verdict['unresolved'],
            ];
            if ($verdict['admit']) {
                $accepted[] = $proposal;
            } else {
                $rejected[] = $proposal;
            }
        }

        $enqueued = [];
        if ($enqueueRequested) {
            foreach ($accepted as $proposal) {
                $row = $this->enqueuePrinciple($proposal, $rows);
                if ($row !== null) {
                    $enqueued[] = $row;
                }
            }
        }

        $packSelector = $this->packSelector ?? new AtlasLearningAbstractionPackSelector;
        $allLevels = array_merge($tactical, $patterns, $accepted);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $accepted !== [] ? 'ok' : ($patterns !== [] ? 'patterns_only' : 'insufficient_signal'),
            'generated_at' => Carbon::now()->toIso8601String(),
            'freeze' => $freeze,
            'k' => $effectiveK,
            'pattern_floor' => $patternFloor,
            'totals' => [
                'rows_scanned' => $stats['rows_scanned'],
                'duplicates_collapsed' => $stats['duplicates_collapsed'],
                'unique_candidates' => $stats['unique_candidates'],
                'tactical' => count($tactical),
                'patterns' => count($patterns),
                'principles_proposed' => count($principles),
                'principles_accepted' => count($accepted),
                'principles_rejected' => count($rejected),
                'enqueued' => count($enqueued),
            ],
            'levels' => [
                'tactical' => $tactical,
                'patterns' => $patterns,
                'principles' => $accepted,
            ],
            'rejected_principles' => $rejected,
            'enqueued' => $enqueued,
            'pack_injection' => $packSelector->injectionMeta(),
            'claim_policy' => [
                'read_only' => ! $enqueueRequested,
                'enqueue_enabled' => $this->enqueueEnabled(),
                'enqueue_requested' => $enqueueRequested,
                'memory_written' => $enqueueRequested && $enqueued !== [],
                'auto_promotion_allowed' => false,
                'frontier_promotes' => false,
                'queue' => 'ai_learning_candidates',
                'admission_door' => 'ASI-02',
            ],
        ];
    }

    /**
     * @param  list<AiLearningCandidate>  $rows
     * @return array{
     *     0: list<array<string,mixed>>,
     *     1: list<array<string,mixed>>,
     *     2: array<string,array<string,mixed>>,
     *     3: array{rows_scanned:int,duplicates_collapsed:int,unique_candidates:int}
     * }
     */
    private function buildLevels(array $rows, int $patternFloor): array
    {
        $tactical = [];
        $groups = [];
        $seenHashes = [];
        $duplicates = 0;

        foreach ($rows as $candidate) {
            $hash = (string) ($candidate->candidate_hash ?? '');
            if ($hash === '') {
                continue;
            }
            if (isset($seenHashes[$hash])) {
                $duplicates++;

                continue;
            }
            $seenHashes[$hash] = true;

            $memoryType = $this->nonEmptyString($candidate->memory_type) ?? 'unknown';
            $scope = $this->nonEmptyString($candidate->scope) ?? 'global';
            $primaryCause = $this->primaryCauseOf($candidate);
            $signatureKey = $this->signatureKey($memoryType, $primaryCause, $scope);

            $tactical[] = [
                'abstraction_level' => self::LEVEL_TACTICAL,
                'ref' => 'candidate:'.$hash,
                'signature_key' => $signatureKey,
                'memory_type' => $memoryType,
                'primary_cause' => $primaryCause,
                'scope' => $scope,
                'case_count' => 1,
                'candidate_hash' => $hash,
                'derived_from' => [],
            ];

            $groups[$signatureKey] ??= [
                'signature_key' => $signatureKey,
                'memory_type' => $memoryType,
                'primary_cause' => $primaryCause,
                'scope' => $scope,
                'case_count' => 0,
                'candidate_hashes' => [],
                'member_ids' => [],
                'evidence_refs' => [],
                'run_outcome_id' => (string) $candidate->run_outcome_id,
            ];

            $groups[$signatureKey]['case_count']++;
            $groups[$signatureKey]['candidate_hashes'][] = $hash;
            $groups[$signatureKey]['member_ids'][] = (string) $candidate->id;
            foreach ($this->evidenceRefsOf($candidate) as $ref) {
                if (! in_array($ref, $groups[$signatureKey]['evidence_refs'], true)) {
                    $groups[$signatureKey]['evidence_refs'][] = $ref;
                }
            }
        }

        ksort($groups);
        $patterns = [];
        $patternIndex = [];
        foreach ($groups as $signatureKey => $group) {
            if ($group['case_count'] < $patternFloor) {
                continue;
            }
            sort($group['candidate_hashes']);
            sort($group['member_ids']);
            sort($group['evidence_refs']);

            $patternRef = self::PATTERN_REF_PREFIX.$signatureKey;
            $pattern = [
                'abstraction_level' => self::LEVEL_PATTERN,
                'ref' => $patternRef,
                'signature_key' => $signatureKey,
                'memory_type' => $group['memory_type'],
                'primary_cause' => $group['primary_cause'],
                'scope' => $group['scope'],
                'case_count' => $group['case_count'],
                'candidate_hashes' => $group['candidate_hashes'],
                'member_ids' => $group['member_ids'],
                'evidence_refs' => $group['evidence_refs'],
                'derived_from' => array_map(
                    static fn (string $h): string => 'candidate:'.$h,
                    $group['candidate_hashes'],
                ),
                'run_outcome_id' => $group['run_outcome_id'],
            ];
            $patterns[] = $pattern;
            $patternIndex[$patternRef] = $pattern;
        }

        usort($patterns, static fn (array $a, array $b): int => strcmp((string) $a['signature_key'], (string) $b['signature_key']));

        return [$tactical, $patterns, $patternIndex, [
            'rows_scanned' => count($rows),
            'duplicates_collapsed' => $duplicates,
            'unique_candidates' => count($seenHashes),
        ]];
    }

    /**
     * @param  list<array<string,mixed>>  $patterns
     * @param  array<string,array<string,mixed>>  $patternIndex
     * @return list<array<string,mixed>>
     */
    private function buildPrinciples(array $patterns, array $patternIndex, int $k): array
    {
        $byCause = [];
        foreach ($patterns as $pattern) {
            $cause = (string) ($pattern['primary_cause'] ?? self::UNKNOWN_PRIMARY_CAUSE);
            if ($cause === self::UNKNOWN_PRIMARY_CAUSE) {
                continue;
            }
            $byCause[$cause] ??= [];
            $byCause[$cause][] = $pattern;
        }

        $principles = [];
        ksort($byCause);
        foreach ($byCause as $primaryCause => $causePatterns) {
            $distinctSignatures = [];
            foreach ($causePatterns as $pattern) {
                $distinctSignatures[(string) $pattern['signature_key']] = $pattern;
            }
            if (count($distinctSignatures) < $k) {
                continue;
            }

            $selected = array_values($distinctSignatures);
            usort($selected, static fn (array $a, array $b): int => strcmp((string) $a['signature_key'], (string) $b['signature_key']));
            $selected = array_slice($selected, 0, $k);

            $derivedFrom = array_map(static fn (array $p): string => (string) $p['ref'], $selected);
            $caseCount = array_sum(array_map(static fn (array $p): int => (int) $p['case_count'], $selected));
            $evidenceRefs = [];
            foreach ($selected as $pattern) {
                foreach ((array) ($pattern['evidence_refs'] ?? []) as $ref) {
                    if (is_string($ref) && $ref !== '' && ! in_array($ref, $evidenceRefs, true)) {
                        $evidenceRefs[] = $ref;
                    }
                }
            }
            sort($evidenceRefs);

            $claim = $this->authorPrincipleClaim($primaryCause, $selected, $caseCount);
            $proposalHash = CompoundingHash::make([
                'schema' => self::SCHEMA_VERSION,
                'primary_cause' => $primaryCause,
                'derived_from' => $derivedFrom,
            ]);

            $principles[] = [
                'abstraction_level' => self::LEVEL_PRINCIPLE,
                'ref' => 'principle:'.$primaryCause.':'.$proposalHash,
                'primary_cause' => $primaryCause,
                'case_count' => $caseCount,
                'distinct_signatures' => count($distinctSignatures),
                'derived_from' => $derivedFrom,
                'pattern_refs' => $derivedFrom,
                'evidence_refs' => $evidenceRefs,
                'claim' => $claim,
                'proposal_hash' => $proposalHash,
                'memory_type' => 'compounding_memory',
                'scope' => 'global',
                'run_outcome_id' => (string) ($selected[0]['run_outcome_id'] ?? ''),
            ];
        }

        usort($principles, static fn (array $a, array $b): int => strcmp((string) $a['primary_cause'], (string) $b['primary_cause']));

        return $principles;
    }

    /**
     * @param  list<array<string,mixed>>  $patterns
     */
    private function authorPrincipleClaim(string $primaryCause, array $patterns, int $caseCount): string
    {
        if ($this->author !== null && (bool) config('atlas.ai.distiller.model_author_enabled', false)) {
            $authored = $this->author->authorClaim(
                new AiLearningCandidate,
                [
                    'abstraction_level' => self::LEVEL_PRINCIPLE,
                    'primary_cause' => $primaryCause,
                    'patterns' => $patterns,
                    'case_count' => $caseCount,
                ],
            );
            if (is_array($authored)) {
                $claim = $this->nonEmptyString($authored['claim'] ?? null);
                if ($claim !== null) {
                    return $claim;
                }
            }
        }

        $signatures = implode(', ', array_map(static fn (array $p): string => (string) $p['signature_key'], $patterns));

        return sprintf(
            'MULTJ-06 principle: %d distinct patterns share primary_cause=%s (case_count=%d) — signatures [%s].',
            count($patterns),
            $primaryCause,
            $caseCount,
            $signatures,
        );
    }

    /**
     * @param  array<string,mixed>  $proposal
     * @param  list<AiLearningCandidate>  $rows
     * @return array<string,mixed>|null
     */
    private function enqueuePrinciple(array $proposal, array $rows): ?array
    {
        $runOutcomeId = (string) ($proposal['run_outcome_id'] ?? '');
        if ($runOutcomeId === '') {
            return null;
        }

        $candidateHash = CompoundingHash::make([
            'schema' => self::SCHEMA_VERSION,
            'kind' => 'principle',
            'proposal_hash' => $proposal['proposal_hash'],
        ]);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'abstraction_level' => self::LEVEL_PRINCIPLE,
            'derived_from' => $proposal['derived_from'],
            'primary_cause' => $proposal['primary_cause'],
            'case_count' => $proposal['case_count'],
            'proposal_hash' => $proposal['proposal_hash'],
            'caused_by' => [
                'schema' => 'atlas.ai.credit_assignment.v1',
                'primary_cause' => $proposal['primary_cause'],
                'contributing_causes' => [],
                'confidence' => 'medium',
                'refs' => $proposal['evidence_refs'],
            ],
            'source' => [
                'slice' => 'MULTJ-06',
                'author_engine' => 'abstraction_ladder',
                'frontier_promotes' => false,
                'admission_door' => 'ASI-02',
            ],
        ];

        $row = AiLearningCandidate::query()->firstOrCreate(
            ['candidate_hash' => $candidateHash],
            [
                'schema_version' => AtlasLearningDistiller::SCHEMA_VERSION,
                'run_outcome_id' => $runOutcomeId,
                'status' => 'held_for_evidence',
                'decision' => 'hold',
                'memory_type' => (string) ($proposal['memory_type'] ?? 'compounding_memory'),
                'scope' => (string) ($proposal['scope'] ?? 'global'),
                'claim' => (string) $proposal['claim'],
                'confidence' => 40,
                'promotion_allowed' => false,
                'evidence_refs' => $proposal['evidence_refs'],
                'payload' => $payload,
                'receipt_hash' => hash('sha256', 'multj06-'.$candidateHash),
                'decided_at' => Carbon::now(),
            ],
        );

        return [
            'candidate_id' => (string) $row->id,
            'candidate_hash' => $candidateHash,
            'abstraction_level' => self::LEVEL_PRINCIPLE,
            'derived_from' => $proposal['derived_from'],
            'created' => $row->wasRecentlyCreated,
        ];
    }

    private function enqueueEnabled(): bool
    {
        return (bool) config('atlas.ai.abstraction_ladder.enqueue_enabled', false);
    }

    private function primaryCauseOf(AiLearningCandidate $candidate): string
    {
        $payload = $candidate->payload;
        if (! is_array($payload)) {
            return self::UNKNOWN_PRIMARY_CAUSE;
        }
        $causedBy = $payload['caused_by'] ?? null;
        if (! is_array($causedBy)) {
            return self::UNKNOWN_PRIMARY_CAUSE;
        }

        return $this->nonEmptyString($causedBy['primary_cause'] ?? null) ?? self::UNKNOWN_PRIMARY_CAUSE;
    }

    /**
     * @return list<string>
     */
    private function signatureKey(string $memoryType, string $primaryCause, string $scope): string
    {
        return $memoryType.'|'.$primaryCause.'|'.$scope;
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyReport(int $k, int $patternFloor, string $status, array $freeze): array
    {
        $packSelector = $this->packSelector ?? new AtlasLearningAbstractionPackSelector;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'freeze' => $freeze,
            'k' => $k,
            'pattern_floor' => $patternFloor,
            'totals' => [
                'rows_scanned' => 0,
                'duplicates_collapsed' => 0,
                'unique_candidates' => 0,
                'tactical' => 0,
                'patterns' => 0,
                'principles_proposed' => 0,
                'principles_accepted' => 0,
                'principles_rejected' => 0,
                'enqueued' => 0,
            ],
            'levels' => ['tactical' => [], 'patterns' => [], 'principles' => []],
            'rejected_principles' => [],
            'enqueued' => [],
            'pack_injection' => $packSelector->injectionMeta(),
            'claim_policy' => [
                'read_only' => true,
                'enqueue_enabled' => $this->enqueueEnabled(),
                'enqueue_requested' => false,
                'memory_written' => false,
                'auto_promotion_allowed' => false,
                'frontier_promotes' => false,
                'queue' => 'ai_learning_candidates',
                'admission_door' => 'ASI-02',
            ],
        ];
    }
}
