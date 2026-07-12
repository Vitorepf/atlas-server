<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Spec;

use App\Services\Ai\EngineeringKernel\TrustLevel;

/**
 * Engineering Kernel value: the auditable receipt sealed for every spec decision.
 *
 * Owns: recording WHICH floor version decided, in WHICH lane, the verdict + gaps, and the full
 * provenance (frozen_hash + divergence + source-independence + oracle mode + findings) as a
 * content-addressed, replayable packet — so a later certification cannot launder the spec.
 * Must never own: deciding the verdict (SovereignSpecFloor) or persisting it (a ReceiptLedger).
 */
final class SpecReceipt
{
    public const SCHEMA = 'atlas.engineering_kernel.spec_receipt.v1';

    /**
     * Deterministic: same inputs → same receipt_hash (replayable provenance).
     *
     * @return array<string,mixed>
     */
    public static function seal(SpecVerdict $verdict, TrustLevel $lane, array $bindings = []): array
    {
        $body = [
            'schema_version' => self::SCHEMA,
            'floor_version' => SovereignSpecFloor::FLOOR_VERSION,
            'lane' => $lane->value,
            'status' => $verdict->status,
            'gaps' => $verdict->gaps,
            'provenance' => $verdict->provenance->toArray(),
            'bindings' => $bindings,
        ];
        $body['receipt_hash'] = hash('sha256', json_encode($body, JSON_THROW_ON_ERROR));

        return $body;
    }
}
