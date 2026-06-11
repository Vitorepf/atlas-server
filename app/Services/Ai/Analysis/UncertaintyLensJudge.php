<?php

declare(strict_types=1);

namespace App\Services\Ai\Analysis;

/**
 * G6 lens 3 — declared uncertainty (humility ethic, mirrors ADRS R2).
 *
 * Deterministic (no provider call). An analysis that declares NO limitations
 * and NO blind spots is over-claiming by construction: every real analysis has
 * residual uncertainty. Empty/whitespace-only entries do not count as a
 * declaration.
 */
final class UncertaintyLensJudge implements AnalysisJudgePort
{
    public const LENS = 'uncertainty';

    /**
     * @param  array<string,mixed>  $analysis
     * @return array{verdict:'accept'|'refute', reasons:list<string>, lens:string}
     */
    public function judge(array $analysis): array
    {
        $declared = $this->declaredCount($analysis['limitations'] ?? null)
            + $this->declaredCount($analysis['blind_spots'] ?? null);

        if ($declared === 0) {
            return [
                'verdict' => 'refute',
                'reasons' => ['zero_uncertainty_over_claim'],
                'lens' => self::LENS,
            ];
        }

        return ['verdict' => 'accept', 'reasons' => [], 'lens' => self::LENS];
    }

    private function declaredCount(mixed $entries): int
    {
        if (is_string($entries)) {
            return trim($entries) !== '' ? 1 : 0;
        }

        if (! is_array($entries)) {
            return 0;
        }

        $count = 0;
        foreach ($entries as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $count++;
            } elseif (is_array($entry) && $entry !== []) {
                $count++;
            }
        }

        return $count;
    }
}
