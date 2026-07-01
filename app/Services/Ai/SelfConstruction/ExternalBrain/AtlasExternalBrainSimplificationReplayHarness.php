<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Deterministic replay gate for large simplification waves: approval is never trust that tests
 * cover every behavior path — it is a concrete comparison of the BEFORE and AFTER replay
 * receipts. A receipt carries what actually happened when the circuit ran: its output, the
 * errors it raised, an evidence hash and a side-effect summary. Any missing receipt or any
 * divergence between the two fails CLOSED with an exact replay_diff entry naming what diverged —
 * never a static approved flag.
 *
 * Receipt shape (both `before_receipts` and `after_receipts`):
 *   output?:               mixed          — the observed return value/shape
 *   errors?:               list<string>   — error/exception classes actually raised
 *   evidence_hash?:        string         — hash of the evidence collected during replay
 *   side_effect_summary?:  string         — summary string of side effects actually observed
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainSimplificationReplayHarness
{
    public const SCHEMA = 'atlas.self_construction.external_brain.simplification_replay_harness.v1';

    public const VERDICT_MATCH = 'match';

    public const VERDICT_DIVERGENT = 'divergent';

    /**
     * @param  array{before_receipts?: array<string,mixed>, after_receipts?: array<string,mixed>}  $facts
     * @return array{schema:string, verdict:string, approved:bool, replay_diff:list<string>}
     */
    public function replay(array $facts): array
    {
        $before = $facts['before_receipts'] ?? null;
        $after = $facts['after_receipts'] ?? null;

        $replayDiff = [];
        if (! is_array($before) || $before === []) {
            $replayDiff[] = 'missing_before_receipt';
        }
        if (! is_array($after) || $after === []) {
            $replayDiff[] = 'missing_after_receipt';
        }

        if ($replayDiff !== []) {
            return $this->divergent($replayDiff);
        }

        if (! $this->normalizedEquals($before['output'] ?? null, $after['output'] ?? null)) {
            $replayDiff[] = 'output_divergence';
        }

        if (! $this->setsEqual($this->stringList($before['errors'] ?? null), $this->stringList($after['errors'] ?? null))) {
            $replayDiff[] = 'error_divergence';
        }

        if ((string) ($before['evidence_hash'] ?? '') !== (string) ($after['evidence_hash'] ?? '')) {
            $replayDiff[] = 'evidence_hash_divergence';
        }

        if ((string) ($before['side_effect_summary'] ?? '') !== (string) ($after['side_effect_summary'] ?? '')) {
            $replayDiff[] = 'side_effect_summary_divergence';
        }

        if ($replayDiff !== []) {
            return $this->divergent(array_values(array_unique($replayDiff)));
        }

        return [
            'schema' => self::SCHEMA,
            'verdict' => self::VERDICT_MATCH,
            'approved' => true,
            'replay_diff' => [],
        ];
    }

    /** @param  list<string>  $replayDiff */
    private function divergent(array $replayDiff): array
    {
        return [
            'schema' => self::SCHEMA,
            'verdict' => self::VERDICT_DIVERGENT,
            'approved' => false,
            'replay_diff' => $replayDiff,
        ];
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $value), static fn (string $v): bool => $v !== ''));
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function setsEqual(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }

    private function normalizedEquals(mixed $a, mixed $b): bool
    {
        return json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            === json_encode($b, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
