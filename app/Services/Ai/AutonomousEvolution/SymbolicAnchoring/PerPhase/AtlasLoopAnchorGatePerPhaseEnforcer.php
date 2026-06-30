<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\PerPhase;

/**
 * Per-phase anchor-gate enforcer. Consults {@see AtlasLoopAnchorGatePerPhaseRegistry} and refuses
 * emissions whose anchored-symbol density falls below the per-phase floor.
 *
 * Allow / refuse returned as a typed {@see EnforcementVerdict}. Fail-CLOSED when enabled, byte-
 * identical no-op when disabled (always allow).
 */
final class AtlasLoopAnchorGatePerPhaseEnforcer
{
    public function __construct(
        private readonly AtlasLoopAnchorGatePerPhaseRegistry $registry,
        private readonly bool $enabled = true,
    ) {}

    /**
     * @param  array<string,mixed>  $payload  {text:string, anchors:list<{kind:string, name:string}>}
     */
    public function enforce(string $phase, array $payload): EnforcementVerdict
    {
        if (! $this->enabled) {
            return EnforcementVerdict::disabled();
        }

        $profile = $this->registry->profileFor($phase);
        $text = (string) ($payload['text'] ?? '');
        $anchors = array_values((array) ($payload['anchors'] ?? []));

        $kchars = max(1, (int) ceil(mb_strlen($text) / 1000));
        $anchorCount = count($anchors);
        $density = $anchorCount / $kchars;
        $required = $profile->anchoredSymbolsPerKchar;

        $distinct = [];
        $kinds = [];
        foreach ($anchors as $a) {
            if (! is_array($a)) {
                continue;
            }
            $name = (string) ($a['name'] ?? '');
            $kind = (string) ($a['kind'] ?? '');
            if ($name !== '') {
                $distinct[$name] = true;
            }
            if ($kind !== '') {
                $kinds[$kind] = true;
            }
        }

        $missingKinds = [];
        foreach ($profile->mustAnchorKinds as $required_kind) {
            if (! isset($kinds[$required_kind])) {
                $missingKinds[] = $required_kind;
            }
        }

        if ($density < $required) {
            return EnforcementVerdict::refuse(EnforcementVerdict::REASON_DENSITY_BELOW_FLOOR, $density, $required, $missingKinds);
        }
        if (count($distinct) < $profile->distinctAnchorFloor) {
            return EnforcementVerdict::refuse(EnforcementVerdict::REASON_DISTINCT_BELOW_FLOOR, $density, $required, $missingKinds);
        }
        if ($missingKinds !== []) {
            return EnforcementVerdict::refuse(EnforcementVerdict::REASON_MISSING_ANCHOR_KINDS, $density, $required, $missingKinds);
        }

        return EnforcementVerdict::allow($density, $required);
    }
}
