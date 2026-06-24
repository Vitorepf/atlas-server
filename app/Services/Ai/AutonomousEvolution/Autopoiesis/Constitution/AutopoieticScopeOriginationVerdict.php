<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Autopoiesis\Constitution;

/**
 * Verdict returned by {@see AtlasLoopAutopoieticConstitutionGate::admit()}. The gate is the SINGLE chokepoint:
 * downstream code MUST consult $allowed and refuse to act on a denied verdict.
 *
 * - $allowed                    : true only when forbidden-scope and operator-receipt checks BOTH pass
 * - $reasonCode                 : 'forbidden_scope' | 'operator_receipt_missing' | 'admitted'
 * - $operatorReceiptRequired    : the threshold required for this category ('explicit'|'two-step'), or null
 *                                 when no receipt is required (or when the request was blocked by scope)
 */
final class AutopoieticScopeOriginationVerdict
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $reasonCode,
        public readonly ?string $operatorReceiptRequired = null,
    ) {
    }
}
