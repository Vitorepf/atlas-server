<?php

namespace App\Services\Ai\Programming\Governance;

/**
 * Scope ceremony level for a programming work item.
 *
 * Compact: small/local patches (typo, focused fix, isolated test).
 *   Mandatory gates: evidence-required, scope-guard, hierarchical-control,
 *   completion.
 *   Recommended gates: feature-placement (informational), docs-health.
 *
 * Structural: multi-file, architectural, schema, security, refactor, multi-agent.
 *   Mandatory gates: feature-placement, code-intelligence-context,
 *   spec-before-code, evidence-required, scope-guard, docs-health,
 *   cartography-update, hierarchical-control, completion.
 *
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system-runbook.md
 */
enum ProgrammingScopeMode: string
{
    case Compact = 'compact';
    case Structural = 'structural';

    /**
     * @return list<string>
     */
    public function requiredGates(): array
    {
        return match ($this) {
            self::Compact => [
                'evidence-required',
                'scope-guard',
                'hierarchical-control',
                'completion',
            ],
            self::Structural => [
                'feature-placement',
                'code-intelligence-context',
                'spec-before-code',
                'evidence-required',
                'scope-guard',
                'docs-health',
                'cartography-update',
                'hierarchical-control',
                'completion',
            ],
        };
    }

    /**
     * Gates that are evaluated but never block completion.
     *
     * @return list<string>
     */
    public function advisoryGates(): array
    {
        return match ($this) {
            self::Compact => ['feature-placement', 'docs-health', 'cartography-update'],
            self::Structural => ['cartography-update'],
        };
    }

    public function isBlocking(string $gateName): bool
    {
        return in_array($gateName, $this->requiredGates(), true)
            && ! in_array($gateName, $this->advisoryGates(), true);
    }
}
