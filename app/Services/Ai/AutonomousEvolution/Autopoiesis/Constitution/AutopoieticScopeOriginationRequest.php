<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Autopoiesis\Constitution;

/**
 * The typed input the {@see AtlasLoopAutopoieticConstitutionGate} consumes: the path the autopoietic
 * scope-origination would touch, the category of action (matched against the registry's
 * mandatory_operator_approval_thresholds map), and the verified operator receipt level if one was issued.
 *
 * - $targetPath  : workspace-relative path the origination would extend the system into
 * - $category    : action category, e.g. 'new_domain', 'financial_action', 'cross_boundary_wiring'
 * - $operatorReceiptLevel : null when no receipt was issued; one of 'explicit'|'two-step' when verified
 */
final class AutopoieticScopeOriginationRequest
{
    public function __construct(
        public readonly string $targetPath,
        public readonly string $category,
        public readonly ?string $operatorReceiptLevel = null,
    ) {
    }
}
