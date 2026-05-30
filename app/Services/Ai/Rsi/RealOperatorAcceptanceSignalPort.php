<?php

declare(strict_types=1);

namespace App\Services\Ai\Rsi;

use App\Models\AtlasEngineeringEvidence;
use Throwable;

/**
 * Governed RSI · Part 3 · REAL operator-acceptance signal port.
 *
 * Reads the existing append-only engineering-evidence store for an explicit
 * operator acceptance of a merged self-improvement, keyed to the merge_hash via
 * the evidence target_id. The signal is REAL-OR-BLOCKED: with no matching
 * evidence row, or a row below the confidence floor, acceptance is false with a
 * precise reason. It NEVER fabricates acceptance and NEVER calls a provider.
 *
 * The evidence row that grants acceptance must be:
 *   - evidence_type === 'operator_acceptance'
 *   - target_id === merge_hash (the self-improvement's merged commit)
 *   - status === 'accepted'
 *   - confidence >= the floor (default 0.8)
 *
 * If the evidence table is unavailable (no DB in a non-DB context) the port
 * blocks honestly rather than crashing the closing materializer path.
 */
final class RealOperatorAcceptanceSignalPort implements OperatorAcceptanceSignalPort
{
    public const EVIDENCE_TYPE = 'operator_acceptance';

    public const ACCEPTED_STATUS = 'accepted';

    public function __construct(
        private readonly float $confidenceFloor = 0.8,
    ) {}

    /**
     * @return array{accepted:bool,evidence_ref:?string,confidence:?float,reason:string}
     */
    public function isAcceptedByOperator(string $mergeHash, string $componentId): array
    {
        $hash = trim($mergeHash);
        if ($hash === '') {
            return $this->blocked('no_merge_hash');
        }

        try {
            $evidence = AtlasEngineeringEvidence::query()
                ->where('evidence_type', self::EVIDENCE_TYPE)
                ->where('target_id', $hash)
                ->where('status', self::ACCEPTED_STATUS)
                ->orderByDesc('recorded_at')
                ->first();
        } catch (Throwable $e) {
            return $this->blocked('evidence_store_unavailable:'.$e->getMessage());
        }

        if ($evidence === null) {
            return $this->blocked('no_operator_acceptance_evidence');
        }

        $confidence = (float) ($evidence->confidence ?? 0.0);
        if ($confidence < $this->confidenceFloor) {
            return [
                'accepted' => false,
                'evidence_ref' => (string) $evidence->getKey(),
                'confidence' => $confidence,
                'reason' => 'acceptance_confidence_below_floor',
            ];
        }

        return [
            'accepted' => true,
            'evidence_ref' => (string) $evidence->getKey(),
            'confidence' => $confidence,
            'reason' => 'operator_accepted',
        ];
    }

    /**
     * @return array{accepted:false,evidence_ref:null,confidence:null,reason:string}
     */
    private function blocked(string $reason): array
    {
        return ['accepted' => false, 'evidence_ref' => null, 'confidence' => null, 'reason' => $reason];
    }
}
