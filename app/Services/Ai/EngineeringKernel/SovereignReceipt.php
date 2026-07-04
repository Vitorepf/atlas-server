<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

/**
 * Engineering Kernel value: the auditable receipt sealed for every sovereign certification.
 *
 * Owns: recording WHO witnessed (witness-set + trust level), WHICH floor version decided, the
 * hashes the decision was bound to, and the verdict — as a content-addressed, replayable packet.
 * Must never own: deciding the verdict (SovereignHonestyFloor) or storing it (ReceiptLedger).
 */
final class SovereignReceipt
{
    public const SCHEMA = 'atlas.engineering_kernel.sovereign_receipt.v1';

    /**
     * Seal a receipt for a verdict. Deterministic: same inputs → same receipt_hash (replayable).
     *
     * @return array<string,mixed>
     */
    public static function seal(CertVerdict $verdict, AcceptanceBundle $bundle, TrustLevel $trust, float $effectiveMutationFloor): array
    {
        $body = [
            'schema_version' => self::SCHEMA,
            'floor_version' => SovereignHonestyFloor::FLOOR_VERSION,
            'trust_level' => $trust->value,
            'witness_set' => $verdict->witnessSet,
            'status' => $verdict->status,
            'blockers' => $verdict->blockers,
            'criteria_hash' => $bundle->criteriaHash,
            'frozen_hash' => $bundle->frozenHash,
            'effective_mutation_floor' => $effectiveMutationFloor,
            'invariant_ids' => array_keys($verdict->invariants),
        ];
        $body['receipt_hash'] = hash('sha256', json_encode($body, JSON_THROW_ON_ERROR));

        return $body;
    }
}
