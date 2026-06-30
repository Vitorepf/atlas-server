<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure compactor. Reduces repeated decision traces into stable canonical lessons
 * while preserving evidence, uncertainty, reversibility, scope, and expiry signals.
 *
 * Merge eligibility (AC2): a trace is compactable only when ALL:
 *   - is_contradictory = false
 *   - uncertainty != 'high'   (high uncertainty = low confidence, keep alone)
 *   - is_stale = false         (stale traces are archival, not canonical)
 *   - reversibility != 'irreversible' (irreversible decisions stay isolated)
 * Any trace failing a condition emits a standalone lesson with kept_separate_reason.
 *
 * Grouping key: sha256(sorted(causes) + '|' + outcome + '|' + scope)
 *   — same causes+outcome with different scopes never collapse.
 *
 * Confidence (merged group):
 *   ≥3 occurrences  AND  no 'medium'-uncertainty trace → high
 *   ≥3 occurrences  WITH a 'medium'-uncertainty trace  → medium
 *   2  occurrences                                     → medium
 *   1  occurrence                                      → low
 *
 * Merged lesson reversibility: 'unknown' if any contributor is 'unknown', else 'reversible'.
 * Merged lesson expires_at: earliest non-null value across contributors (most conservative).
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainDecisionLedgerCompactor
{
    public const SCHEMA = 'atlas.external_brain.decision_ledger_compactor.v1';

    public const CONFIDENCE_HIGH   = 'high';
    public const CONFIDENCE_MEDIUM = 'medium';
    public const CONFIDENCE_LOW    = 'low';

    private const SEPARATE_REASON_CONTRADICTORY    = 'contradictory';
    private const SEPARATE_REASON_HIGH_UNCERTAINTY = 'high_uncertainty';
    private const SEPARATE_REASON_STALE            = 'stale';
    private const SEPARATE_REASON_IRREVERSIBLE     = 'irreversible';

    /**
     * @param  array{traces?: list<array<string,mixed>>}  $input
     * @return array<string,mixed>
     */
    public function compact(array $input): array
    {
        $traces = is_array($input['traces'] ?? null) ? $input['traces'] : [];
        $total  = count($traces);

        $mergeable  = [];
        $standalone = [];

        foreach ($traces as $trace) {
            $traceId       = (string)  ($trace['trace_id']        ?? '');
            $causes        = $this->normalizeCauses($trace['causes'] ?? []);
            $outcome       = (string)  ($trace['outcome']          ?? '');
            $evidenceRefs  = $this->normalizeList($trace['evidence_refs'] ?? []);
            $uncertainty   = (string)  ($trace['uncertainty']      ?? 'low');
            $contradictory = (bool)    ($trace['is_contradictory'] ?? false);
            $isStale       = (bool)    ($trace['is_stale']         ?? false);
            $reversibility = (string)  ($trace['reversibility']    ?? 'reversible');
            $scope         = (string)  ($trace['scope']            ?? '');
            $expiresAt     = isset($trace['expires_at']) ? (string) $trace['expires_at'] : null;

            if ($contradictory) {
                $standalone[] = $this->lesson(
                    $traceId, $causes, $outcome, 1, self::CONFIDENCE_LOW,
                    $evidenceRefs, [$traceId], $uncertainty, $reversibility, $scope, $expiresAt,
                    self::SEPARATE_REASON_CONTRADICTORY,
                );
                continue;
            }
            if ($uncertainty === 'high') {
                $standalone[] = $this->lesson(
                    $traceId, $causes, $outcome, 1, self::CONFIDENCE_LOW,
                    $evidenceRefs, [$traceId], $uncertainty, $reversibility, $scope, $expiresAt,
                    self::SEPARATE_REASON_HIGH_UNCERTAINTY,
                );
                continue;
            }
            if ($isStale) {
                $standalone[] = $this->lesson(
                    $traceId, $causes, $outcome, 1, self::CONFIDENCE_LOW,
                    $evidenceRefs, [$traceId], $uncertainty, $reversibility, $scope, $expiresAt,
                    self::SEPARATE_REASON_STALE,
                );
                continue;
            }
            if ($reversibility === 'irreversible') {
                $standalone[] = $this->lesson(
                    $traceId, $causes, $outcome, 1, self::CONFIDENCE_LOW,
                    $evidenceRefs, [$traceId], $uncertainty, $reversibility, $scope, $expiresAt,
                    self::SEPARATE_REASON_IRREVERSIBLE,
                );
                continue;
            }

            $key = $this->groupKey($causes, $outcome, $scope);
            $mergeable[$key][] = [
                'trace_id'     => $traceId,
                'causes'       => $causes,
                'outcome'      => $outcome,
                'evidence_refs' => $evidenceRefs,
                'uncertainty'  => $uncertainty,
                'reversibility' => $reversibility,
                'scope'        => $scope,
                'expires_at'   => $expiresAt,
            ];
        }

        $lessons = $standalone;
        foreach ($mergeable as $group) {
            $lessons[] = $this->compactGroup($group);
        }

        usort($lessons, static fn (array $a, array $b): int => strcmp($a['lesson_id'], $b['lesson_id']));

        return [
            'schema'         => self::SCHEMA,
            'lessons'        => $lessons,
            'lesson_count'   => count($lessons),
            'compacted_from' => $total,
        ];
    }

    private function compactGroup(array $group): array
    {
        $n        = count($group);
        $causes   = $group[0]['causes'];
        $outcome  = $group[0]['outcome'];
        $scope    = $group[0]['scope'];
        $traceIds = array_column($group, 'trace_id');
        $lessonId = $this->groupKey($causes, $outcome, $scope);

        $allEvidence  = [];
        $hasMedium    = false;
        $hasUnknownRev = false;
        $expiresAtValues = [];

        foreach ($group as $t) {
            foreach ($t['evidence_refs'] as $ref) {
                $allEvidence[$ref] = true;
            }
            if ($t['uncertainty'] === 'medium') {
                $hasMedium = true;
            }
            if ($t['reversibility'] === 'unknown') {
                $hasUnknownRev = true;
            }
            if ($t['expires_at'] !== null) {
                $expiresAtValues[] = $t['expires_at'];
            }
        }

        $retained = array_values(array_keys($allEvidence));
        sort($retained);

        $confidence    = $this->computeConfidence($n, $hasMedium);
        $uncertainty   = $hasMedium ? 'medium' : 'low';
        $reversibility = $hasUnknownRev ? 'unknown' : 'reversible';
        $expiresAt     = count($expiresAtValues) > 0 ? min($expiresAtValues) : null;

        return $this->lesson($lessonId, $causes, $outcome, $n, $confidence, $retained, $traceIds, $uncertainty, $reversibility, $scope, $expiresAt, null);
    }

    private function computeConfidence(int $n, bool $hasMedium): string
    {
        if ($n >= 3 && ! $hasMedium) {
            return self::CONFIDENCE_HIGH;
        }
        if ($n >= 2 || ($n >= 3 && $hasMedium)) {
            return self::CONFIDENCE_MEDIUM;
        }

        return self::CONFIDENCE_LOW;
    }

    private function groupKey(array $sortedCauses, string $outcome, string $scope): string
    {
        return hash('sha256', implode(',', $sortedCauses).'|'.$outcome.'|'.$scope);
    }

    private function lesson(
        string $lessonId,
        array $causes,
        string $outcome,
        int $occurrenceCount,
        string $confidence,
        array $retainedEvidence,
        array $supersededIds,
        string $uncertainty,
        string $reversibility,
        string $scope,
        ?string $expiresAt,
        ?string $keptSeparateReason,
    ): array {
        return [
            'lesson_id'            => $lessonId,
            'causes'               => $causes,
            'outcome'              => $outcome,
            'occurrence_count'     => $occurrenceCount,
            'confidence'           => $confidence,
            'retained_evidence'    => $retainedEvidence,
            'superseded_trace_ids' => $supersededIds,
            'uncertainty'          => $uncertainty,
            'reversibility'        => $reversibility,
            'scope'                => $scope,
            'expires_at'           => $expiresAt,
            'kept_separate_reason' => $keptSeparateReason,
        ];
    }

    /**
     * Compact a flat decision ledger (separate from compact()'s trace-grouping model).
     *
     * Durable decisions, active constraints, and negative-result rules (failed_pattern)
     * are always preserved unless explicitly superseded/duplicate AND conflict-free.
     * A decision flagged with conflicts_with is NEVER silently dropped or silently
     * resolved to "newest wins" — it is marked for human/agent revalidation instead.
     *
     * @param  array{decisions?: list<array<string,mixed>>}  $input
     * @return array{schema:string, compact_summary:string, dropped_count:int, preserved_count:int, revalidate_count:int, preserved:list<array<string,mixed>>, dropped:list<array<string,mixed>>, revalidate:list<array<string,mixed>>}
     */
    public function compactDecisions(array $input): array
    {
        $decisions = is_array($input['decisions'] ?? null) ? $input['decisions'] : [];

        $preserved  = [];
        $dropped    = [];
        $revalidate = [];

        foreach ($decisions as $decision) {
            $decisionId   = (string) ($decision['decision_id']   ?? '');
            $type         = (string) ($decision['type']          ?? 'durable_decision');
            $status       = (string) ($decision['status']        ?? 'active');
            $conflictsWith = $this->normalizeList($decision['conflicts_with'] ?? []);

            if ($conflictsWith !== []) {
                $revalidate[] = [
                    'decision_id'    => $decisionId,
                    'type'           => $type,
                    'conflicts_with' => $conflictsWith,
                ];
                continue;
            }

            $isDurableClass = in_array($type, ['durable_decision', 'failed_pattern', 'active_constraint'], true);
            $isSupersededOrDuplicate = in_array($status, ['superseded', 'duplicate'], true);

            if ($isDurableClass && ! $isSupersededOrDuplicate) {
                $preserved[] = ['decision_id' => $decisionId, 'type' => $type];
                continue;
            }

            $dropped[] = [
                'decision_id' => $decisionId,
                'type'        => $type,
                'reason'      => $isSupersededOrDuplicate ? $status : 'not_durable',
            ];
        }

        $droppedCount    = count($dropped);
        $preservedCount  = count($preserved);
        $revalidateCount = count($revalidate);

        return [
            'schema'           => self::SCHEMA,
            'compact_summary'  => sprintf(
                'preserved %d durable decision(s)/constraint(s)/failed pattern(s), dropped %d superseded/duplicate, flagged %d for revalidation',
                $preservedCount,
                $droppedCount,
                $revalidateCount,
            ),
            'dropped_count'    => $droppedCount,
            'preserved_count'  => $preservedCount,
            'revalidate_count' => $revalidateCount,
            'preserved'        => $preserved,
            'dropped'          => $dropped,
            'revalidate'       => $revalidate,
        ];
    }

    /** @return list<string> */
    private function normalizeCauses(mixed $raw): array
    {
        $list = $this->normalizeList($raw);
        sort($list);

        return $list;
    }

    /** @return list<string> */
    private function normalizeList(mixed $raw): array
    {
        return array_values(array_filter(array_map('strval', (array) $raw), static fn (string $s): bool => $s !== ''));
    }
}
