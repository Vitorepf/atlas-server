<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\LearningTransfer;

/**
 * Pure gate. Admits a lesson candidate ONLY when its supporting evidence crosses BOTH:
 *   - the repetition threshold (>= minRepetitions independent observations)
 *   - the proof threshold (>= minProvenObservations carrying a non-empty evidence_refs list)
 *
 * Rejects one-off anecdotes, unverified claims (no proven observation), broad narratives (the lesson
 * candidate carries no concrete `class` field), and conflicting evidence (observations split between
 * mutually exclusive outcomes by more than the conflict tolerance).
 *
 * Output (FACTS only):
 *   {schema_version, decision:'admit'|'hold'|'reject', reasons:list<string>, required_next_evidence:list<string>}
 *
 * NO scalar score, NO ranking. Same input ⇒ same decision (deterministic threshold check).
 */
final class AtlasSelfConstructionLearningTransferLessonCandidateGate
{
    public const SCHEMA = 'atlas.learning_transfer.lesson_candidate_gate.v1';

    public const DECISION_ADMIT = 'admit';

    public const DECISION_HOLD = 'hold';

    public const DECISION_REJECT = 'reject';

    public const DEFAULT_MIN_REPETITIONS = 3;

    public const DEFAULT_MIN_PROVEN = 2;

    public const DEFAULT_CONFLICT_TOLERANCE = 1;

    /**
     * @param  array<string,mixed>  $candidate    {class:string, observations:list<{outcome:string, evidence_refs:list<string>}>}
     * @param  array<string,mixed>  $thresholds   {min_repetitions?:int, min_proven?:int, conflict_tolerance?:int}
     * @return array<string,mixed>
     */
    public function admit(array $candidate, array $thresholds = []): array
    {
        $class = (string) ($candidate['class'] ?? '');
        $observations = is_array($candidate['observations'] ?? null) ? array_values($candidate['observations']) : [];

        $minRepetitions = (int) ($thresholds['min_repetitions'] ?? self::DEFAULT_MIN_REPETITIONS);
        $minProven = (int) ($thresholds['min_proven'] ?? self::DEFAULT_MIN_PROVEN);
        $conflictTolerance = (int) ($thresholds['conflict_tolerance'] ?? self::DEFAULT_CONFLICT_TOLERANCE);

        $reasons = [];
        $next = [];

        if ($class === '') {
            $reasons[] = 'broad_narrative_no_class';
            $next[] = 'attach_a_concrete_class_field';

            return $this->envelope(self::DECISION_REJECT, $reasons, $next);
        }

        // Tally proven vs unverified + outcome distribution.
        $proven = 0;
        $unverified = 0;
        $outcomeCounts = [];
        foreach ($observations as $obs) {
            if (! is_array($obs)) {
                continue;
            }
            $outcome = (string) ($obs['outcome'] ?? 'unknown');
            $outcomeCounts[$outcome] = ($outcomeCounts[$outcome] ?? 0) + 1;
            if (! empty($obs['evidence_refs']) && is_array($obs['evidence_refs'])) {
                $proven++;
            } else {
                $unverified++;
            }
        }
        $total = $proven + $unverified;

        // CONFLICT — if two outcomes both exceed tolerance, the candidate is contradicted.
        if (count($outcomeCounts) >= 2) {
            $sorted = $outcomeCounts;
            arsort($sorted);
            [$top, $second] = array_slice(array_values($sorted), 0, 2) + [0, 0];
            if ((int) $second > $conflictTolerance) {
                $reasons[] = 'conflicting_outcomes:'.json_encode($outcomeCounts, JSON_UNESCAPED_SLASHES);
                $next[] = 'resolve_outcome_conflict_with_specific_disambiguator';

                return $this->envelope(self::DECISION_REJECT, $reasons, $next);
            }
        }

        // ONE-OFF — below repetition threshold ⇒ hold (might mature with time).
        if ($total < $minRepetitions) {
            $reasons[] = 'below_repetition_threshold:'.$total.'<'.$minRepetitions;
            $next[] = 'accumulate_more_independent_observations';

            return $this->envelope(self::DECISION_HOLD, $reasons, $next);
        }

        // UNVERIFIED — proven observations below proof threshold ⇒ reject (claim without proof).
        if ($proven < $minProven) {
            $reasons[] = 'below_proof_threshold:'.$proven.'<'.$minProven;
            $next[] = 'attach_evidence_refs_to_at_least_'.$minProven.'_observations';

            return $this->envelope(self::DECISION_REJECT, $reasons, $next);
        }

        return $this->envelope(self::DECISION_ADMIT, ['repetition_and_proof_thresholds_met'], []);
    }

    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $next
     * @return array<string,mixed>
     */
    private function envelope(string $decision, array $reasons, array $next): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'decision' => $decision,
            'reasons' => array_values($reasons),
            'required_next_evidence' => array_values(array_unique($next)),
        ];
    }
}
