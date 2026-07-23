<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Adaptive queue-shaping gate: chooses the next compression-batch action from live queue depth,
 * proof debt, risk, and worker outcome facts — never from queue depth alone. A deep queue is not
 * itself a reason to stop creating high-value, genuinely distinct work; a shallow queue is not
 * itself a reason to add more of the same low-value shape.
 *
 * DECISION PRIORITY (first match wins):
 *   repair          — risk_level='high' OR worker_outcomes.repeated_similar_failures=true: fix the
 *                      root cause before adding more batches on top of a failing pattern.
 *   add_proof       — proof_debt >= PROOF_DEBT_THRESHOLD: the queue has more unproven claims than
 *                      it can safely act on; strengthen proof before growing the batch further.
 *   add_compression — high_value_candidates_available AND distinct_candidates_available: real,
 *                      non-duplicate leverage exists — added REGARDLESS of servable_depth, since
 *                      depth alone must never block genuinely valuable, distinct work.
 *   diversify       — servable_depth >= HIGH_SERVABLE_DEPTH_THRESHOLD and no high-value distinct
 *                      candidates: the queue is deep but samey; the fix is a different angle, not
 *                      more depth.
 *   pause           — otherwise: shallow-enough queue, no debt, no risk, nothing high-value or
 *                      distinct queued — genuinely nothing productive to add right now.
 *
 * Input shape:
 *   { servable_depth?:int, proof_debt?:int, risk_level?:string,
 *     worker_outcomes?: {recent_failure_rate?:float, repeated_similar_failures?:bool},
 *     high_value_candidates_available?:bool, distinct_candidates_available?:bool }
 *
 * Pure: no I/O, no provider calls, no queue mutation.
 */
final class AtlasExternalBrainAdaptiveCompressionQueue
{
    public const SCHEMA = 'atlas.external_brain.adaptive_compression_queue.v1';

    public const ACTION_ADD_PROOF = 'add_proof';

    public const ACTION_ADD_COMPRESSION = 'add_compression';

    public const ACTION_PAUSE = 'pause';

    public const ACTION_REPAIR = 'repair';

    public const ACTION_DIVERSIFY = 'diversify';

    private const PROOF_DEBT_THRESHOLD = 3;

    private const HIGH_SERVABLE_DEPTH_THRESHOLD = 20;

    /**
     * @param  array<string,mixed>  $facts
     * @return array{schema:string, action:string, reasons:list<string>}
     */
    public function shape(array $facts): array
    {
        $servableDepth = max(0, (int) ($facts['servable_depth'] ?? 0));
        $proofDebt = max(0, (int) ($facts['proof_debt'] ?? 0));
        $riskLevel = strtolower(trim((string) ($facts['risk_level'] ?? 'low')));
        $workerOutcomes = is_array($facts['worker_outcomes'] ?? null) ? $facts['worker_outcomes'] : [];
        $repeatedSimilarFailures = (bool) ($workerOutcomes['repeated_similar_failures'] ?? false);
        $highValueAvailable = (bool) ($facts['high_value_candidates_available'] ?? false);
        $distinctAvailable = (bool) ($facts['distinct_candidates_available'] ?? false);

        if ($riskLevel === 'high' || $repeatedSimilarFailures) {
            return $this->result(self::ACTION_REPAIR, array_filter([
                $riskLevel === 'high' ? 'risk_level=high' : null,
                $repeatedSimilarFailures ? 'worker_outcomes.repeated_similar_failures=true' : null,
            ]));
        }

        if ($proofDebt >= self::PROOF_DEBT_THRESHOLD) {
            return $this->result(self::ACTION_ADD_PROOF, [
                "proof_debt={$proofDebt} at or above threshold=".self::PROOF_DEBT_THRESHOLD,
            ]);
        }

        if ($highValueAvailable && $distinctAvailable) {
            return $this->result(self::ACTION_ADD_COMPRESSION, [
                'high_value_candidates_available=true',
                'distinct_candidates_available=true',
                "servable_depth={$servableDepth} does not block genuinely valuable distinct work",
            ]);
        }

        if ($servableDepth >= self::HIGH_SERVABLE_DEPTH_THRESHOLD) {
            return $this->result(self::ACTION_DIVERSIFY, [
                "servable_depth={$servableDepth} at or above threshold=".self::HIGH_SERVABLE_DEPTH_THRESHOLD,
                'no high-value distinct candidates available: queue is deep but samey',
            ]);
        }

        return $this->result(self::ACTION_PAUSE, [
            "servable_depth={$servableDepth} below diversify threshold",
            'no proof debt, no risk, no high-value distinct candidates: nothing productive to add now',
        ]);
    }

    /** @param  list<string>  $reasons */
    private function result(string $action, array $reasons): array
    {
        return [
            'schema' => self::SCHEMA,
            'action' => $action,
            'reasons' => array_values($reasons),
        ];
    }
}
