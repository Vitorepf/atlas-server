<?php

declare(strict_types=1);

namespace App\Services\Ai\Autonomy;

/**
 * MAXK-06 — port that publishes autonomy ladder exit metrics WITH provenance.
 *
 * The pre-MAXK-06 evaluation path let the caller hand-craft the metric map;
 * the same actor asking for a promotion could therefore supply the numbers
 * that decided it — the second forgeable input the plan names. This port is
 * how the promotion gate reads metrics from a sealed source; the numbers now
 * carry a `provenance` envelope naming the source, its identifier, a sealed
 * timestamp and a sealed entry hash. `evaluatePromotion($metrics, ...)`
 * remains unchanged (report/calculator surface); only the promotion path
 * binds to this port.
 *
 * Adapters MUST fail-closed: if there is no sealed entry for the level, or
 * if the sealed entry's hash cannot be verified against its payload, the
 * port returns `metrics_authority_missing` / `metrics_authority_tampered`
 * as the provenance source so the gate can refuse. Silent 0-value fallbacks
 * would be the very forgery the port exists to block.
 */
interface AtlasAutonomyMetricsAuthorityPort
{
    /**
     * The provenance source string returned when no sealed entry exists for
     * the target level. Callers MUST treat this as a hard refusal, never as
     * "measured zero".
     */
    public const SOURCE_MISSING = 'metrics_authority_missing';

    /**
     * The provenance source string returned when a sealed entry exists but
     * the recomputed `entry_hash` does not match the stored one — the sealed
     * ledger was tampered with after seal. Callers MUST refuse.
     */
    public const SOURCE_TAMPERED = 'metrics_authority_tampered';

    /**
     * Return the sealed metric map for the target level.
     *
     * @param  string  $level  target level e.g. `L1` (the LEVEL BEING PROMOTED INTO)
     * @return array{
     *     metrics: array<string,float>,
     *     provenance: array{
     *         source: string,
     *         source_id: string,
     *         sealed_at: string,
     *         entry_hash: string,
     *         verified: bool
     *     }
     * }
     */
    public function metricsFor(string $level): array;
}
