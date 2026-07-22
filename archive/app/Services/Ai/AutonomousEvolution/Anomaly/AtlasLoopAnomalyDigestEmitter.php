<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Anomaly;

/**
 * Emits the `anomaly_facts` section into the morning digest from
 * {@see AtlasLoopAnomalyReceiptLedger}.
 *
 * Per-signal output (ordered by signal name ASC) carries the MOST RECENT fact in the supplied
 * window: signal, baseline_rate, current_rate, delta_in_sigmas, window_start, window_end.
 *
 * Deterministic & FACTUAL: identical ledger contents + identical window ⇒ byte-identical JSON.
 * Empty window ⇒ `{section: anomaly_facts, entries: []}` (never throws).
 */
final class AtlasLoopAnomalyDigestEmitter
{
    public const SECTION = 'anomaly_facts';

    public const SCHEMA = 'atlas.loop.anomaly_digest.v1';

    /**
     * @param  list<string>  $signals  list of signals to digest (resolved by the caller)
     */
    public function __construct(
        private readonly AtlasLoopAnomalyReceiptLedger $ledger,
        private readonly array $signals,
    ) {}

    /**
     * @param  array{from?:string,to?:string}  $window
     * @return array<string,mixed>
     */
    public function emit(array $window = []): array
    {
        $from = isset($window['from']) ? (string) $window['from'] : null;
        $to = isset($window['to']) ? (string) $window['to'] : null;

        $entries = [];
        $signals = $this->signals;
        sort($signals, SORT_STRING);
        foreach ($signals as $signal) {
            $history = $this->ledger->history($signal, $from, $to);
            if ($history === []) {
                continue;
            }
            // Pick the MOST RECENT row in the window — `history()` orders ASC by observed_at,
            // so the last element is the most recent.
            $latest = $history[count($history) - 1];
            $entries[] = [
                'signal' => (string) $latest['signal'],
                'baseline_rate' => (float) $latest['baseline_rate'],
                'current_rate' => (float) $latest['current_rate'],
                'delta_in_sigmas' => (float) $latest['delta_in_sigmas'],
                'window_start' => (string) $latest['window_start'],
                'window_end' => (string) $latest['window_end'],
            ];
        }

        // Stable order by signal name.
        usort($entries, static fn (array $a, array $b): int => strcmp((string) $a['signal'], (string) $b['signal']));

        return [
            'schema_version' => self::SCHEMA,
            'section' => self::SECTION,
            'entries' => $entries,
        ];
    }

    /**
     * JSON-encode the emitted section with byte-stable flags for identical-state guarantees.
     *
     * @param  array{from?:string,to?:string}  $window
     */
    public function emitJson(array $window = []): string
    {
        $payload = $this->emit($window);

        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
