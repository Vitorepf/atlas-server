<?php

namespace App\Services\Ai\RouterRuntime;

use App\Models\AiAtlasIntentClassification;
use App\Services\Ai\Support\DatabaseTableAvailability;

class RouterEvidenceBridgeService
{
    /**
     * Decide whether the evidence plane (Meta 4) must gate this domain.
     *
     * Tolerant integration: if Mission Foundation evidence refs exist we
     * record a need for evidence; otherwise we still set the flag for canon
     * domains so downstream runtimes know they must collect evidence.
     */
    public function isEvidenceRequired(string $primaryDomain, AiAtlasIntentClassification $intent): bool
    {
        if (in_array($primaryDomain, RouterRuntimeCanon::EVIDENCE_REQUIRED_DOMAINS, true)) {
            return true;
        }
        if ($intent->intent_type === RouterRuntimeCanon::INTENT_DEBUG) {
            return true;
        }
        if ($intent->intent_type === RouterRuntimeCanon::INTENT_REVIEW) {
            return true;
        }
        if (! DatabaseTableAvailability::has('ai_mission_evidence_refs')) {
            return false;
        }

        return false;
    }
}
