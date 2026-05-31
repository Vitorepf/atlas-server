<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

final class QuarantineEligibilityBand
{
    /**
     * @return array{band: string, action: string, score: int}
     */
    public function classify(int $score): array
    {
        $clampedScore = min(max($score, 0), 100);

        if ($clampedScore >= 80) {
            return [
                'band' => 'quarantine',
                'action' => 'quarantine_now',
                'score' => $clampedScore,
            ];
        }

        if ($clampedScore >= 40) {
            return [
                'band' => 'retry_bounded',
                'action' => 'allow_bounded_retry',
                'score' => $clampedScore,
            ];
        }

        return [
            'band' => 'keep',
            'action' => 'keep_selectable',
            'score' => $clampedScore,
        ];
    }
}
