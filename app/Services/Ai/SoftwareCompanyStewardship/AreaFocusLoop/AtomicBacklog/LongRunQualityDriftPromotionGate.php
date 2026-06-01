<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

final class LongRunQualityDriftPromotionGate
{
    private const SCHEMA_VERSION = 'atlas.loop.long_run_quality_drift_promotion_gate.v1';

    /**
     * @param  list<array<string,mixed>>  $ladder
     * @param  array<string,mixed>  $qualityDriftReport
     * @return array<string,mixed>
     */
    public function apply(array $ladder, array $qualityDriftReport): array
    {
        if (($qualityDriftReport['stop_promotion'] ?? false) !== true) {
            return $this->result($ladder, false, [], null);
        }

        $highestPassed = $qualityDriftReport['highest_passed'] ?? null;
        $blockNext = $highestPassed === null;
        $blockedRungs = [];
        $gatedLadder = [];

        foreach ($ladder as $index => $rung) {
            $rungId = $this->rungId($rung, $index);

            if (! $blockNext && $this->sameRung($rungId, $highestPassed)) {
                $gatedLadder[] = $rung;
                $blockNext = true;
                continue;
            }

            if ($blockNext) {
                $blockedRungs[] = $rungId;
                $gatedLadder[] = $this->markBlocked($rung);
                continue;
            }

            $gatedLadder[] = $rung;
        }

        return $this->result(
            $gatedLadder,
            $blockedRungs !== [],
            $blockedRungs,
            $blockedRungs === [] ? null : 'quality_drift_stop_promotion',
        );
    }

    /** @param array<string,mixed> $rung */
    private function rungId(array $rung, int $index): int|string
    {
        $id = $rung['rung'] ?? $rung['id'] ?? $index;

        return is_int($id) || is_string($id) ? $id : $index;
    }

    private function sameRung(int|string $rungId, mixed $highestPassed): bool
    {
        if (is_int($highestPassed) || is_string($highestPassed)) {
            return (string) $rungId === (string) $highestPassed;
        }

        return false;
    }

    /** @param array<string,mixed> $rung @return array<string,mixed> */
    private function markBlocked(array $rung): array
    {
        $blocked = array_merge($rung, ['blocked' => true]);

        if (! array_key_exists('blocker', $blocked) && ! array_key_exists('block_reason', $blocked)) {
            $blocked['block_reason'] = 'quality_drift_stop_promotion';
        }

        return $blocked;
    }

    /**
     * @param  list<array<string,mixed>>  $ladder
     * @param  list<int|string>  $blockedRungs
     * @return array<string,mixed>
     */
    private function result(array $ladder, bool $changed, array $blockedRungs, ?string $nextBlocker): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ladder' => $ladder,
            'changed' => $changed,
            'blocked_rungs' => $blockedRungs,
            'next_blocker' => $nextBlocker,
        ];
    }
}
