<?php

namespace App\Services\Ai\OperatorIntelligence;

use App\Services\Ai\OperatorIntelligence\Support\OperatorLearningGateSupport;

class OperatorLearningGate
{
    /**
     * Producers whose signals may reach automatic application — a STRUCTURAL gate property,
     * not a producer-local convention. 'comprehension' = the extractor stamps it AFTER its
     * evidence-quote lock + refute pass; 'manual_operator' = the operator explicitly typed
     * the claim (the human IS the verification). The PASSIVE regex chat-detector stamps
     * NEITHER, so its hardcoded-confidence signals can be captured for review but can NEVER
     * auto-apply. Any unmarked / future producer is untrusted by default (fail-closed).
     */
    public const AUTO_APPLY_PROVENANCE = 'comprehension';

    public const AUTO_APPLY_PROVENANCE_MANUAL = 'manual_operator';

    public const TRUSTED_AUTO_APPLY_PROVENANCES = [self::AUTO_APPLY_PROVENANCE, self::AUTO_APPLY_PROVENANCE_MANUAL];

    /**
     * L3-9 #8: explicit, NON-auto-apply provenance the passive regex chat-detector
     * stamps onto its OWN signals. It is deliberately NOT in TRUSTED_AUTO_APPLY_PROVENANCES,
     * so a passive-detector signal can be captured for review but can NEVER auto-apply —
     * even though its hardcoded confidence (0.91/0.92) clears the numeric floor. The
     * detector stamps this verbatim so a caller cannot forge `comprehension`/`manual_operator`
     * onto a passive signal to sneak it past the gate.
     */
    public const PASSIVE_DETECTOR_PROVENANCE = 'passive_detector';

    public function __construct(
        private readonly OperatorTaxonomyRegistry $taxonomy = new OperatorTaxonomyRegistry(),
    ) {}

    /**
     * @param  array<string,mixed>  $classified
     * @return array<string,mixed>
     */
    public function evaluate(array $classified): array
    {
        $taxonomyId = (string) ($classified['taxonomy_item_id'] ?? '');

        return OperatorLearningGateSupport::evaluate(
            $classified,
            $this->taxonomy->isAvailable(),
            $this->taxonomy->get($taxonomyId),
            self::TRUSTED_AUTO_APPLY_PROVENANCES,
            (float) config('atlas_operator_intelligence.min_auto_apply_confidence', 0.85),
            (bool) config('atlas_operator_intelligence.shadow_mode', true),
        );
    }
}
