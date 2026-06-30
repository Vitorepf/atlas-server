<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Telemetry;

use DateInterval;
use DateTimeImmutable;

final class AtlasLoopTelemetryStarvationDetector
{
    /**
     * @param  list<array<string, mixed>>  $facts
     * @return array{
     *   starved:bool,
     *   window:array{from_iso:string,to_iso:string,minutes:int},
     *   counts:array{claim:int,lease:int,serve:int},
     *   evidence:array{paired_claim_to_serve:int}
     * }
     */
    public function detect(array $facts, string $nowIso, int $windowMinutes): array
    {
        $now = new DateTimeImmutable($nowIso);
        $cutoff = $now->sub(new DateInterval('PT'.max(0, $windowMinutes).'M'));
        $counts = [
            'claim' => 0,
            'lease' => 0,
            'serve' => 0,
        ];
        $byCycle = [];

        foreach ($facts as $fact) {
            if (! is_array($fact)) {
                continue;
            }

            $kind = trim((string) ($fact['kind'] ?? ''));
            $cycleId = trim((string) ($fact['cycle_id'] ?? ''));
            $occurredAtIso = trim((string) ($fact['occurred_at_iso'] ?? ''));

            if (! array_key_exists($kind, $counts) || $cycleId === '' || $occurredAtIso === '') {
                continue;
            }

            try {
                $occurredAt = new DateTimeImmutable($occurredAtIso);
            } catch (\Exception) {
                continue;
            }

            if ($occurredAt < $cutoff || $occurredAt > $now) {
                continue;
            }

            $counts[$kind]++;
            $byCycle[$cycleId][$kind] = true;
        }

        $pairedClaimToServe = 0;
        foreach ($byCycle as $events) {
            if (($events['claim'] ?? false) && ($events['serve'] ?? false)) {
                $pairedClaimToServe++;
            }
        }

        return [
            'starved' => $counts['claim'] > 0 && $counts['serve'] === 0 && $pairedClaimToServe === 0,
            'window' => [
                'from_iso' => $cutoff->format(DATE_ATOM),
                'to_iso' => $now->format(DATE_ATOM),
                'minutes' => max(0, $windowMinutes),
            ],
            'counts' => $counts,
            'evidence' => [
                'paired_claim_to_serve' => $pairedClaimToServe,
            ],
        ];
    }
}
