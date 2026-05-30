<?php

declare(strict_types=1);

namespace App\Services\Ai\Rsi;

/**
 * Governed RSI · Part 3 · Real-world OPERATOR-ACCEPTANCE signal port.
 *
 * The single real-world boundary the GroundTruthValueAdapter consults beyond the
 * loop's own append-only ledgers. A self-improvement to the loop's OWN machinery
 * is the most dangerous capability; before its value may even be CONSIDERED
 * proven, a human must have explicitly accepted the merged self-improvement.
 *
 * Live telemetry is OUT OF SCOPE by design: the only real-world input here is an
 * explicit operator confirmation recorded in an append-only evidence store. The
 * port NEVER calls a provider, NEVER fabricates acceptance, and returns
 * accepted=false (with a precise reason) whenever no real operator signal exists.
 *
 * A real implementation reads the existing append-only evidence ledger
 * (e.g. atlas_engineering_evidence with evidence_type='operator_acceptance' and a
 * confidence threshold) keyed to the self-improvement merge_hash. Tests provide a
 * labelled Fake* deterministic seam — a real operator query is NEVER run in a
 * test or a proof.
 */
interface OperatorAcceptanceSignalPort
{
    /**
     * Has the operator explicitly accepted the merged self-improvement identified
     * by $mergeHash for the named target component? No real signal => accepted
     * false with a reason. Implementations MUST NOT fabricate acceptance.
     *
     * @return array{accepted:bool,evidence_ref:?string,confidence:?float,reason:string}
     */
    public function isAcceptedByOperator(string $mergeHash, string $componentId): array;
}
