<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Cortex;

/**
 * Pure ingestor that converts queued-target collision summaries into Cortex
 * signals that future originator rounds can denylist.
 *
 * Signal mapping:
 *   - critical collision → deny signal (target must not be originated)
 *   - warning collision → pivot signal (target should pivot to alternate)
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasSelfConstructionCortexCollisionSignalIngestor
{
    public const SCHEMA = 'atlas.self_construction.cortex_collision_signal_ingestor.v1';

    public const SIGNAL_DENY = 'deny';
    public const SIGNAL_PIVOT = 'pivot';

    /**
     * @param  array<int, array<string, mixed>>  $collisions
     * @return array<string, mixed>
     */
    public function ingest(array $collisions): array
    {
        $signals = [];

        foreach ($collisions as $collision) {
            if (! is_array($collision)) {
                continue;
            }

            $targetFamily = (string) ($collision['target_family'] ?? '');
            $severity = strtolower(trim((string) ($collision['severity'] ?? '')));
            $reason = (string) ($collision['reason'] ?? '');

            if ($targetFamily === '') {
                continue;
            }

            $signalType = match ($severity) {
                'critical' => self::SIGNAL_DENY,
                'warning' => self::SIGNAL_PIVOT,
                default => null,
            };

            if ($signalType !== null) {
                $signals[] = [
                    'signal_type' => $signalType,
                    'target_family' => $targetFamily,
                    'reason' => $reason,
                    'severity' => $severity,
                ];
            }
        }

        // Deduplicate by signal_type + target_family.
        $seen = [];
        $deduped = [];
        foreach ($signals as $signal) {
            $key = $signal['signal_type'].':'.$signal['target_family'];
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $deduped[] = $signal;
            }
        }

        // Sort deterministically.
        usort($deduped, static function (array $a, array $b): int {
            return strcmp($a['signal_type'], $b['signal_type'])
                ?: strcmp($a['target_family'], $b['target_family']);
        });

        $denySignals = array_values(array_filter($deduped, static fn (array $s): bool => $s['signal_type'] === self::SIGNAL_DENY));
        $pivotSignals = array_values(array_filter($deduped, static fn (array $s): bool => $s['signal_type'] === self::SIGNAL_PIVOT));

        return [
            'schema_version' => self::SCHEMA,
            'signals' => $deduped,
            'deny_signals' => $denySignals,
            'pivot_signals' => $pivotSignals,
            'total_signals' => count($deduped),
            'denylist' => array_column($denySignals, 'target_family'),
            'pivot_list' => array_column($pivotSignals, 'target_family'),
        ];
    }
}
