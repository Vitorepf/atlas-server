<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure compactor. Reduces repeated decision traces into stable canonical lessons
 * while preserving evidence, uncertainty, and reversibility signals.
 *
 * Merge eligibility (AC2): a trace is compactable only when BOTH:
 *   - is_contradictory = false
 *   - uncertainty != 'high'  (high uncertainty = low confidence, keep alone)
 * Any trace failing either condition emits a standalone lesson with kept_separate_reason.
 *
 * Grouping key: sorted(causes) + '|' + outcome — so same causes with different
 * outcomes never collapse (they represent genuine contradictions between groups).
 *
 * Confidence (merged group):
 *   ≥3 occurrences  AND  no 'medium'-uncertainty trace → high
 *   ≥3 occurrences  WITH a 'medium'-uncertainty trace  → medium
 *   2  occurrences                                     → medium
 *   1  occurrence                                      → low
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

    /**
     * @param  array{traces?: list<array<string,mixed>>}  $input
     * @return array{
     *   schema:string,
     *   lessons:list<array<string,mixed>>,
     *   lesson_count:int,
     *   compacted_from:int,
     * }
     */
    public function compact(array $input): array
    {
        $traces = is_array($input['traces'] ?? null) ? $input['traces'] : [];
        $total  = count($traces);

        $mergeable  = [];
        $standalone = [];

        foreach ($traces as $trace) {
            $traceId       = (string) ($trace['trace_id']        ?? '');
            $causes        = $this->normalizeCauses($trace['causes']         ?? []);
            $outcome       = (string) ($trace['outcome']          ?? '');
            $evidenceRefs  = $this->normalizeList($trace['evidence_refs']   ?? []);
            $uncertainty   = (string) ($trace['uncertainty']      ?? 'low');
            $contradictory = (bool)   ($trace['is_contradictory'] ?? false);

            if ($contradictory) {
                $standalone[] = $this->lesson(
                    $traceId, $causes, $outcome, 1, self::CONFIDENCE_LOW,
                    $evidenceRefs, [$traceId], $uncertainty, self::SEPARATE_REASON_CONTRADICTORY,
                );

                continue;
            }
            if ($uncertainty === 'high') {
                $standalone[] = $this->lesson(
                    $traceId, $causes, $outcome, 1, self::CONFIDENCE_LOW,
                    $evidenceRefs, [$traceId], $uncertainty, self::SEPARATE_REASON_HIGH_UNCERTAINTY,
                );

                continue;
            }

            $key              = $this->groupKey($causes, $outcome);
            $mergeable[$key][] = [
                'trace_id'     => $traceId,
                'causes'       => $causes,
                'outcome'      => $outcome,
                'evidence_refs' => $evidenceRefs,
                'uncertainty'  => $uncertainty,
            ];
        }

        $lessons = $standalone;
        foreach ($mergeable as $key => $group) {
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
        $n          = count($group);
        $causes     = $group[0]['causes'];
        $outcome    = $group[0]['outcome'];
        $traceIds   = array_column($group, 'trace_id');
        $lessonId   = $this->groupKey($causes, $outcome);

        $allEvidence = [];
        $hasMedium   = false;
        foreach ($group as $t) {
            foreach ($t['evidence_refs'] as $ref) {
                $allEvidence[$ref] = true;
            }
            if ($t['uncertainty'] === 'medium') {
                $hasMedium = true;
            }
        }
        $retained   = array_values(array_keys($allEvidence));
        sort($retained);

        $confidence = $this->computeConfidence($n, $hasMedium);
        $uncertainty = $hasMedium ? 'medium' : 'low';

        return $this->lesson($lessonId, $causes, $outcome, $n, $confidence, $retained, $traceIds, $uncertainty, null);
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

    private function groupKey(array $sortedCauses, string $outcome): string
    {
        return hash('sha256', implode(',', $sortedCauses).'|'.$outcome);
    }

    /** @param  list<string>  $retainedEvidence  @param  list<string>  $supersededIds */
    private function lesson(
        string $lessonId,
        array $causes,
        string $outcome,
        int $occurrenceCount,
        string $confidence,
        array $retainedEvidence,
        array $supersededIds,
        string $uncertainty,
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
            'kept_separate_reason' => $keptSeparateReason,
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
