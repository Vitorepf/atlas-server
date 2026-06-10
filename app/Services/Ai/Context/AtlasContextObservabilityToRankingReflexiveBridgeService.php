<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\Support\AppendOnlyJsonlStore;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * ACOP → ACRS Reflexive Streaming Bridge.
 *
 * Closes the canonical loop where AtlasContextObservabilityPlaneService
 * (ACOP) signals — context_quality, retrieval_latency, leak_risk — feed
 * the AtlasContextRankingSystemService (ACRS) so that ranking weights
 * adapt to observed health in real time.
 *
 * Streaming = append-only events emitted into a JSONL stream that an
 * ACRS consumer can tail. This service is the **emitter**; ACRS owns
 * how it consumes (out-of-scope for this slice).
 *
 * Authority doc: scaffold (next slice docs the ACRS consumer integration).
 *
 * Schemas:
 *   - atlas.acop_to_acrs.signal.v1
 *
 * Invariants:
 *   - signal envelope is canonical and immutable;
 *   - append-only, no truncation;
 *   - signal kinds restricted to canon list.
 */
final class AtlasContextObservabilityToRankingReflexiveBridgeService
{
    public const SIGNAL_SCHEMA = 'atlas.acop_to_acrs.signal.v1';

    public const KIND_CONTEXT_QUALITY = 'context_quality';

    public const KIND_RETRIEVAL_LATENCY = 'retrieval_latency';

    public const KIND_LEAK_RISK = 'leak_risk';

    public const KIND_FRESHNESS_DRIFT = 'freshness_drift';

    public const KIND_COST_PRESSURE = 'cost_pressure';

    public const VALID_KINDS = [
        self::KIND_CONTEXT_QUALITY,
        self::KIND_RETRIEVAL_LATENCY,
        self::KIND_LEAK_RISK,
        self::KIND_FRESHNESS_DRIFT,
        self::KIND_COST_PRESSURE,
    ];

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const VALID_SEVERITIES = [
        self::SEVERITY_LOW, self::SEVERITY_MEDIUM, self::SEVERITY_HIGH,
    ];

    private ?string $streamPathOverride = null;

    public function setStreamPathForTesting(?string $path): void
    {
        $this->streamPathOverride = $path;
    }

    public function streamPath(): string
    {
        if ($this->streamPathOverride !== null) {
            return $this->streamPathOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/acop_to_acrs')
            : sys_get_temp_dir().'/atlas/acop_to_acrs';

        return $base.DIRECTORY_SEPARATOR.'signals.jsonl';
    }

    /**
     * Emit a streaming signal from ACOP to be consumed by ACRS.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function emit(array $input): array
    {
        $kind = (string) ($input['kind'] ?? '');
        if (! in_array($kind, self::VALID_KINDS, true)) {
            throw new InvalidArgumentException("Unknown signal kind '{$kind}'.");
        }
        $severity = (string) ($input['severity'] ?? self::SEVERITY_LOW);
        if (! in_array($severity, self::VALID_SEVERITIES, true)) {
            throw new InvalidArgumentException("Unknown severity '{$severity}'.");
        }

        $value = $input['value'] ?? null;
        $scope = (array) ($input['scope'] ?? []);
        $rationale = (string) ($input['rationale'] ?? '');

        $emittedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
        $signalId = 'acop_'.substr(hash('sha256', $kind.'|'.json_encode($value).'|'.json_encode($scope).'|'.$emittedAt), 0, 12);

        $signal = [
            'schema_version' => self::SIGNAL_SCHEMA,
            'signal_id' => $signalId,
            'emitted_at' => $emittedAt,
            'kind' => $kind,
            'severity' => $severity,
            'value' => $value,
            'scope' => $scope,
            'rationale' => $rationale,
        ];
        $signal['signal_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::SIGNAL_SCHEMA,
            'kind' => $kind,
            'severity' => $severity,
            'value' => $value,
            'scope' => $scope,
        ], JSON_THROW_ON_ERROR));

        AppendOnlyJsonlStore::append($this->streamPath(), $signal);

        return $signal;
    }

    /**
     * Read signals (most recent last). Used by ACRS to adapt weights.
     *
     * @return list<array<string,mixed>>
     */
    public function listSignals(int $limit = 100): array
    {
        $rows = AppendOnlyJsonlStore::read($this->streamPath());
        if ($limit > 0 && count($rows) > $limit) {
            return array_slice($rows, -$limit);
        }

        return $rows;
    }

    /**
     * Aggregate signal counts per kind × severity for ACRS consumption.
     *
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        $tally = [];
        $signals = AppendOnlyJsonlStore::read($this->streamPath());
        foreach ($signals as $s) {
            $k = (string) ($s['kind'] ?? 'unknown');
            $sev = (string) ($s['severity'] ?? 'low');
            $tally[$k] = $tally[$k] ?? ['low' => 0, 'medium' => 0, 'high' => 0];
            if (isset($tally[$k][$sev])) {
                $tally[$k][$sev]++;
            }
        }

        return [
            'schema_version' => 'atlas.acop_to_acrs.summary.v1',
            'total_signals' => count($signals),
            'by_kind' => $tally,
        ];
    }

    // ---------- internals ----------

}
