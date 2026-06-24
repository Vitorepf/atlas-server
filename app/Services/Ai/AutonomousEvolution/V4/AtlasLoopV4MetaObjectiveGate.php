<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\V4;

/**
 * V4 META-OBJECTIVE GATE — closes the loop between PROPOSED meta-objectives and ATTRIBUTED capability deltas
 * (see {@see AtlasLoopV4CapabilityDeltaAttribution}). A meta-objective that cites a capability dimension the
 * attribution engine flagged as UNATTRIBUTED (it moved with no proposal claiming it — a confound) is GAMBLING:
 * the brain must refuse to chase a metric it cannot prove its own work moved.
 *
 * Refusal order: not-originated → cites-a-confounded-dimension → attribution-signal-dead (capability target with
 * no attributed signal). Pure — no I/O.
 */
final class AtlasLoopV4MetaObjectiveGate
{
    /**
     * @param  array{originated?:bool, cited_facts?:list<string>, target_metric?:string}  $metaObjective
     * @param  array{attributed?:bool, unattributed_dimensions?:list<string>}  $attributionResult
     * @return array{admitted:bool, refuse_reason:?string, confounded_citations:list<string>}
     */
    public function admit(array $metaObjective, array $attributionResult): array
    {
        if (($metaObjective['originated'] ?? null) !== true) {
            return $this->refuse('upstream_not_originated');
        }

        $unattributed = array_map('strval', (array) ($attributionResult['unattributed_dimensions'] ?? []));

        $confounded = [];
        foreach ((array) ($metaObjective['cited_facts'] ?? []) as $citation) {
            $citation = (string) $citation;
            $parts = explode('.', $citation, 2); // <bucket>.<key>=<value>
            if (($parts[0] ?? '') !== 'capability') {
                continue;
            }
            $key = explode('=', $parts[1] ?? '', 2)[0];
            if (in_array($key, $unattributed, true)) {
                $confounded[] = $citation; // every offending citation, in citation order
            }
        }

        if ($confounded !== []) {
            return ['admitted' => false, 'refuse_reason' => 'cites_confounded_capability_dimension', 'confounded_citations' => $confounded];
        }

        if (($attributionResult['attributed'] ?? null) !== true && ($metaObjective['target_metric'] ?? null) === 'capability') {
            return $this->refuse('attribution_signal_dead');
        }

        return ['admitted' => true, 'refuse_reason' => null, 'confounded_citations' => []];
    }

    /**
     * @return array{admitted:bool, refuse_reason:?string, confounded_citations:list<string>}
     */
    private function refuse(string $reason): array
    {
        return ['admitted' => false, 'refuse_reason' => $reason, 'confounded_citations' => []];
    }
}
