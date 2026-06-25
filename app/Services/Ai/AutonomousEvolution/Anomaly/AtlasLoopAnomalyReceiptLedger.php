<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Anomaly;

use InvalidArgumentException;

/**
 * In-memory anomaly FACT ledger for the Loop. Append-only, deterministic ordering.
 *
 * A fact row has: signal, baseline_rate, current_rate, delta_in_sigmas, window_start, window_end,
 * observed_at. Each appended row is enriched with a byte-stable receipt_hash so downstream
 * gates can prove tamper-evident audit even before a persistent storage layer exists.
 */
final class AtlasLoopAnomalyReceiptLedger
{
    public const SCHEMA = 'atlas.loop.anomaly_receipt.v1';

    public const REQUIRED_KEYS = [
        'signal',
        'baseline_rate',
        'current_rate',
        'delta_in_sigmas',
        'window_start',
        'window_end',
        'observed_at',
    ];

    /** @var list<array<string,mixed>> */
    private array $facts = [];

    /**
     * @param  array<string,mixed>  $fact
     * @return array<string,mixed>
     */
    public function append(array $fact): array
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (! array_key_exists($key, $fact)) {
                throw new InvalidArgumentException('missing_required_anomaly_fact_key:'.$key);
            }
        }
        if ((string) $fact['signal'] === '') {
            throw new InvalidArgumentException('signal_must_be_non_empty_string');
        }
        if (! is_numeric($fact['baseline_rate']) || ! is_numeric($fact['current_rate']) || ! is_numeric($fact['delta_in_sigmas'])) {
            throw new InvalidArgumentException('numeric_fields_must_be_numeric');
        }
        if (strtotime((string) $fact['window_start']) === false || strtotime((string) $fact['window_end']) === false || strtotime((string) $fact['observed_at']) === false) {
            throw new InvalidArgumentException('timestamp_fields_must_be_iso8601');
        }
        if ((string) $fact['window_end'] < (string) $fact['window_start']) {
            throw new InvalidArgumentException('window_end_must_not_precede_window_start');
        }

        $normalized = [
            'schema_version' => self::SCHEMA,
            'signal' => (string) $fact['signal'],
            'baseline_rate' => (float) $fact['baseline_rate'],
            'current_rate' => (float) $fact['current_rate'],
            'delta_in_sigmas' => (float) $fact['delta_in_sigmas'],
            'window_start' => (string) $fact['window_start'],
            'window_end' => (string) $fact['window_end'],
            'observed_at' => (string) $fact['observed_at'],
        ];
        $normalized['receipt_hash'] = $this->hash($normalized);

        $this->facts[] = $normalized;

        return $normalized;
    }

    /**
     * Filter facts by signal and (optional) window — returns events whose observed_at falls within
     * [from, to). Deterministic ordering: by observed_at ASC, then signal, then receipt_hash.
     *
     * @return list<array<string,mixed>>
     */
    public function history(string $signal, ?string $from = null, ?string $to = null): array
    {
        $matches = array_values(array_filter($this->facts, function (array $f) use ($signal, $from, $to): bool {
            if ((string) $f['signal'] !== $signal) {
                return false;
            }
            $observedAt = (string) $f['observed_at'];
            if ($from !== null && $observedAt < $from) {
                return false;
            }
            if ($to !== null && $observedAt >= $to) {
                return false;
            }

            return true;
        }));

        usort($matches, static function (array $a, array $b): int {
            return [(string) $a['observed_at'], (string) $a['signal'], (string) $a['receipt_hash']]
                <=> [(string) $b['observed_at'], (string) $b['signal'], (string) $b['receipt_hash']];
        });

        return $matches;
    }

    public function size(): int
    {
        return count($this->facts);
    }

    /**
     * @param  array<string,mixed>  $normalized
     */
    private function hash(array $normalized): string
    {
        ksort($normalized);

        return hash('sha256', (string) json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
