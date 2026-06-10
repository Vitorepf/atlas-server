<?php

namespace App\Services\Ai\RouterRuntime;

use App\Models\AiAtlasIntentClassification;
use App\Services\Ai\Support\DatabaseTableAvailability;

class RouterPolicyBridgeService
{
    /**
     * Decide whether the policy plane (Meta 3) must gate this domain.
     *
     * Tolerant integration: if Meta 3 tables/services exist, we rely on the
     * Policy Decision plane; otherwise we fall back to canonical defaults so
     * the router stays usable in isolation.
     */
    public function isPolicyRequired(string $primaryDomain, AiAtlasIntentClassification $intent): bool
    {
        if (in_array($primaryDomain, RouterRuntimeCanon::HIGH_RISK_DOMAINS, true)) {
            return true;
        }
        if ($intent->intent_type === RouterRuntimeCanon::INTENT_AUTOMATION) {
            return true;
        }
        if ($intent->intent_type === RouterRuntimeCanon::INTENT_CYBER) {
            return true;
        }
        if (! DatabaseTableAvailability::has('ai_policy_profiles')) {
            // Policy plane absent: be safe by default for sensitive domains
            return in_array($primaryDomain, ['finance', 'cyber', 'marketing'], true);
        }

        return false;
    }
}
