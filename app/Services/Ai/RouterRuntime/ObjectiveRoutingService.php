<?php

namespace App\Services\Ai\RouterRuntime;

use App\Models\AiAtlasIntentClassification;
use App\Models\AiMission;

class ObjectiveRoutingService
{
    /**
     * Resolve the objective shape for a classified intent. v1 derives a
     * lightweight objective summary from the intent type and any related
     * mission. The full Objective Intelligence runtime (Meta 1) decomposes
     * objectives into success_criteria; this service simply hands the router
     * enough context to make a routing decision.
     *
     * @return array<string,mixed>
     */
    public function resolveObjective(AiAtlasIntentClassification $intent): array
    {
        $missionRef = null;
        if ($intent->mission_id !== null) {
            $mission = AiMission::query()->whereKey($intent->mission_id)->first();
            if ($mission instanceof AiMission) {
                $missionRef = [
                    'mission_id' => $mission->id,
                    'mission_uuid' => $mission->uuid,
                    'mission_type' => $mission->mission_type,
                    'status' => $mission->status,
                    'primary_domain' => $mission->primary_domain,
                ];
            }
        }

        return [
            'intent_id' => $intent->id,
            'intent_uuid' => $intent->uuid,
            'intent_type' => $intent->intent_type,
            'normalized_intent' => $intent->normalized_intent,
            'confidence' => (float) ($intent->confidence ?? 0.0),
            'ambiguity_score' => (float) ($intent->ambiguity_score ?? 0.0),
            'derived_domain' => RouterRuntimeCanon::INTENT_TO_DOMAIN[$intent->intent_type]
                ?? 'conversation',
            'mission' => $missionRef,
        ];
    }
}
