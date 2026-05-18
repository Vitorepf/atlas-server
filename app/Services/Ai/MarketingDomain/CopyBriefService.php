<?php

namespace App\Services\Ai\MarketingDomain;

use App\Models\AiMarketingArtifact;
use App\Models\AiMarketingRun;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CopyBriefService
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function draft(AiMarketingRun $run, array $payload): AiMarketingArtifact
    {
        foreach (['headline', 'audience', 'channel', 'message', 'call_to_action'] as $required) {
            if (empty($payload[$required])) {
                throw new InvalidArgumentException("copy brief requires field [{$required}]");
            }
        }

        return AiMarketingArtifact::query()->create([
            'uuid' => (string) Str::uuid(),
            'marketing_run_id' => $run->id,
            'artifact_type' => MarketingDomainCanon::ARTIFACT_COPY,
            'title' => (string) $payload['headline'],
            'payload' => array_merge($payload, [
                'no_unsubstantiated_claim' => true,
                'side_effect_policy' => ['auto_publish' => false],
            ]),
            'status' => MarketingDomainCanon::ARTIFACT_READY_FOR_REVIEW,
            'artifact_hash' => MissionCanonicalHash::sha256([
                'run_uuid' => $run->uuid,
                'artifact_type' => MarketingDomainCanon::ARTIFACT_COPY,
                'payload' => $payload,
            ]),
        ]);
    }
}
