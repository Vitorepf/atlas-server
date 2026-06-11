<?php

declare(strict_types=1);

namespace App\Services\Ai\Analysis;

/**
 * G6 lens 2 — internal consistency.
 *
 * Deterministic (no provider call). Refutes on internal contradictions:
 *  - the same numeric metric reported with different values (top-level
 *    `metrics` map vs per-claim `metrics` maps, or claim vs claim);
 *  - the conclusion referencing a claim id that does not exist;
 *  - a top-level `verdict` field inconsistent with the claim polarity counts
 *    (e.g. verdict "positive" while negative-polarity claims outnumber
 *    positive ones).
 */
final class ConsistencyLensJudge implements AnalysisJudgePort
{
    public const LENS = 'consistency';

    private const NUMERIC_EPSILON = 1e-9;

    /**
     * @param  array<string,mixed>  $analysis
     * @return array{verdict:'accept'|'refute', reasons:list<string>, lens:string}
     */
    public function judge(array $analysis): array
    {
        $reasons = [
            ...$this->metricContradictions($analysis),
            ...$this->danglingConclusionRefs($analysis),
            ...$this->verdictPolarityMismatch($analysis),
        ];

        if ($reasons !== []) {
            return ['verdict' => 'refute', 'reasons' => array_values(array_unique($reasons)), 'lens' => self::LENS];
        }

        return ['verdict' => 'accept', 'reasons' => [], 'lens' => self::LENS];
    }

    /**
     * @param  array<string,mixed>  $analysis
     * @return list<string>
     */
    private function metricContradictions(array $analysis): array
    {
        /** @var array<string,float> $seen */
        $seen = [];
        $reasons = [];

        $record = static function (string $name, mixed $value) use (&$seen, &$reasons): void {
            if (! is_numeric($value)) {
                return;
            }

            $float = (float) $value;

            if (array_key_exists($name, $seen) && abs($seen[$name] - $float) > self::NUMERIC_EPSILON) {
                $reasons[] = 'metric_value_contradiction:'.$name;

                return;
            }

            $seen[$name] = $seen[$name] ?? $float;
        };

        $topMetrics = $analysis['metrics'] ?? [];
        if (is_array($topMetrics)) {
            foreach ($topMetrics as $name => $value) {
                $record((string) $name, $value);
            }
        }

        $claims = $analysis['claims'] ?? [];
        if (is_array($claims)) {
            foreach ($claims as $claim) {
                if (! is_array($claim)) {
                    continue;
                }

                $claimMetrics = $claim['metrics'] ?? [];
                if (! is_array($claimMetrics)) {
                    continue;
                }

                foreach ($claimMetrics as $name => $value) {
                    $record((string) $name, $value);
                }
            }
        }

        return $reasons;
    }

    /**
     * @param  array<string,mixed>  $analysis
     * @return list<string>
     */
    private function danglingConclusionRefs(array $analysis): array
    {
        $conclusion = $analysis['conclusion'] ?? null;
        if (! is_array($conclusion)) {
            return [];
        }

        $refs = $conclusion['claim_refs'] ?? [];
        if (! is_array($refs) || $refs === []) {
            return [];
        }

        $knownIds = [];
        $claims = $analysis['claims'] ?? [];
        if (is_array($claims)) {
            foreach ($claims as $claim) {
                if (is_array($claim)) {
                    $id = trim((string) ($claim['id'] ?? ''));
                    if ($id !== '') {
                        $knownIds[$id] = true;
                    }
                }
            }
        }

        $reasons = [];
        foreach ($refs as $ref) {
            $refId = trim((string) $ref);
            if ($refId !== '' && ! isset($knownIds[$refId])) {
                $reasons[] = 'conclusion_references_missing_claim:'.$refId;
            }
        }

        return $reasons;
    }

    /**
     * @param  array<string,mixed>  $analysis
     * @return list<string>
     */
    private function verdictPolarityMismatch(array $analysis): array
    {
        $verdict = strtolower(trim((string) ($analysis['verdict'] ?? '')));
        if (! in_array($verdict, ['positive', 'negative'], true)) {
            return [];
        }

        $positive = 0;
        $negative = 0;

        $claims = $analysis['claims'] ?? [];
        if (is_array($claims)) {
            foreach ($claims as $claim) {
                if (! is_array($claim)) {
                    continue;
                }

                $polarity = strtolower(trim((string) ($claim['polarity'] ?? '')));
                if (in_array($polarity, ['positive', 'supports'], true)) {
                    $positive++;
                } elseif (in_array($polarity, ['negative', 'refutes'], true)) {
                    $negative++;
                }
            }
        }

        if ($verdict === 'positive' && $negative > $positive) {
            return ['verdict_inconsistent_with_claim_polarity'];
        }

        if ($verdict === 'negative' && $positive > $negative) {
            return ['verdict_inconsistent_with_claim_polarity'];
        }

        return [];
    }
}
