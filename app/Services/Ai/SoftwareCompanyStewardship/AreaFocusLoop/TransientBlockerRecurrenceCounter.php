<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

final class TransientBlockerRecurrenceCounter
{
    /**
     * Count trailing (most-recent-first) cycle records for $findingKey whose blockers
     * contain $transientBlocker, stopping at the first matching record lacking it.
     * Records for other finding keys are skipped without breaking the streak.
     *
     * @param  list<array{finding_key?: mixed, blockers?: mixed}>  $cycleHistory  oldest-first
     */
    public function consecutiveTransientRecurrences(string $findingKey, string $transientBlocker, array $cycleHistory): int
    {
        if ($findingKey === '' || $transientBlocker === '' || $cycleHistory === []) {
            return 0;
        }

        $count = 0;

        for ($index = count($cycleHistory) - 1; $index >= 0; $index--) {
            $record = $cycleHistory[$index];
            $recordFindingKey = (string) ($record['finding_key'] ?? '');

            if ($recordFindingKey !== $findingKey) {
                continue;
            }

            if (! $this->blockersContain($record['blockers'] ?? [], $transientBlocker)) {
                break;
            }

            $count++;
        }

        return $count;
    }

    /**
     * @param  mixed  $blockers
     */
    private function blockersContain(mixed $blockers, string $transientBlocker): bool
    {
        if (! is_array($blockers)) {
            return false;
        }

        foreach ($blockers as $blocker) {
            if ((string) $blocker === $transientBlocker) {
                return true;
            }
        }

        return false;
    }
}
