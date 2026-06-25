<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\WeeklyDigest;

/**
 * Joins FACTS from injected sub-ledger sources into one weekly snapshot rolling up the last
 * 7 calendar days. Pure, FACTS-only — emits rows keyed by (ledger_source, event_kind,
 * occurred_at) with a payload_digest. NO scores, NO ranking.
 *
 * Master switch ATLAS_LOOP_MASTER_ENABLED=false ⇒ empty snapshot, byte-identical no-op.
 *
 * Sources are injected as callables `fn(string $from, string $to): list<array>` so the composer
 * has no direct ledger imports and can be tested with stubs.
 */
final class AtlasLoopWeeklyDigestComposer
{
    public const SCHEMA = 'atlas.loop.weekly_digest.v1';

    public const KNOWN_SOURCES = [
        'decision_receipt',
        'comprehension',
        'materializer_sandbox',
        'brain_comprehension',
        'evolution',
        'attribution',
        'certification',
    ];

    /** @var array<string, callable> */
    private array $sources;

    /**
     * @param  array<string, callable(string,string): list<array<string,mixed>>>  $sources  ledger_source => callable
     */
    public function __construct(array $sources)
    {
        $this->sources = $sources;
    }

    /**
     * @return array<string,mixed>
     */
    public function compose(string $windowFromIso, string $windowToIso): array
    {
        if (! $this->masterSwitchEnabled()) {
            return [
                'schema' => self::SCHEMA,
                'window_from' => $windowFromIso,
                'window_to' => $windowToIso,
                'rows' => [],
                'snapshot_hash' => '',
                'master_switch_off' => true,
            ];
        }

        $rows = [];
        $seen = [];
        foreach ($this->sources as $sourceName => $callable) {
            if (! is_callable($callable)) {
                continue;
            }
            $facts = $callable($windowFromIso, $windowToIso);
            if (! is_array($facts)) {
                continue;
            }
            foreach ($facts as $fact) {
                if (! is_array($fact)) {
                    continue;
                }
                $occurredAt = (string) ($fact['occurred_at'] ?? '');
                if ($occurredAt === '' || strcmp($occurredAt, $windowFromIso) < 0 || strcmp($occurredAt, $windowToIso) >= 0) {
                    continue;
                }
                $payload = $fact['payload'] ?? null;
                $payloadDigest = hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $row = [
                    'ledger_source' => (string) $sourceName,
                    'event_kind' => (string) ($fact['event_kind'] ?? 'unknown'),
                    'occurred_at' => $occurredAt,
                    'payload_digest' => $payloadDigest,
                ];
                // Chokepoint overlap dedup: (source, event_kind, occurred_at, payload_digest).
                $dedupKey = $row['ledger_source'].'|'.$row['event_kind'].'|'.$row['occurred_at'].'|'.$row['payload_digest'];
                if (isset($seen[$dedupKey])) {
                    continue;
                }
                $seen[$dedupKey] = true;
                $rows[] = $row;
            }
        }

        usort($rows, static function (array $a, array $b): int {
            $cmp = strcmp((string) $a['occurred_at'], (string) $b['occurred_at']);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcmp((string) $a['ledger_source'], (string) $b['ledger_source']);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcmp((string) $a['event_kind'], (string) $b['event_kind']);

            return $cmp !== 0 ? $cmp : strcmp((string) $a['payload_digest'], (string) $b['payload_digest']);
        });

        $snapshot = [
            'schema' => self::SCHEMA,
            'window_from' => $windowFromIso,
            'window_to' => $windowToIso,
            'rows' => $rows,
            'master_switch_off' => false,
        ];
        $snapshot['snapshot_hash'] = 'weekly_'.substr(hash('sha256', (string) json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 24);

        return $snapshot;
    }

    private function masterSwitchEnabled(): bool
    {
        if (function_exists('config')) {
            $cfg = config('atlas.loop.master_enabled');
            if ($cfg !== null) {
                return (bool) $cfg;
            }
        }
        $env = getenv('ATLAS_LOOP_MASTER_ENABLED');
        if ($env === false) {
            return true; // default ON when neither config nor env set
        }

        return in_array(strtolower((string) $env), ['1', 'true', 'on', 'yes'], true);
    }
}
