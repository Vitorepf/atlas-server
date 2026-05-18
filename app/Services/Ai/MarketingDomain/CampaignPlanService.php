<?php

namespace App\Services\Ai\MarketingDomain;

use App\Models\AiMarketingArtifact;
use App\Models\AiMarketingRun;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CampaignPlanService
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function plan(AiMarketingRun $run, array $payload): AiMarketingArtifact
    {
        foreach (['name', 'objective', 'channels', 'kpis', 'budget_proposed'] as $required) {
            if (! array_key_exists($required, $payload)) {
                throw new InvalidArgumentException("campaign plan requires field [{$required}]");
            }
        }
        $channels = (array) ($payload['channels'] ?? []);
        if ($channels === []) {
            throw new InvalidArgumentException('campaign plan requires at least one channel');
        }
        $kpis = (array) ($payload['kpis'] ?? []);
        if ($kpis === []) {
            throw new InvalidArgumentException('campaign plan requires at least one KPI');
        }
        if (! is_numeric($payload['budget_proposed'])) {
            throw new InvalidArgumentException('campaign budget_proposed must be numeric');
        }

        return AiMarketingArtifact::query()->create([
            'uuid' => (string) Str::uuid(),
            'marketing_run_id' => $run->id,
            'artifact_type' => MarketingDomainCanon::ARTIFACT_CAMPAIGN,
            'title' => (string) $payload['name'],
            'payload' => array_merge($payload, [
                'side_effect_policy' => [
                    'auto_publish' => false,
                    'auto_spend' => false,
                    'requires_approval' => true,
                ],
            ]),
            'status' => MarketingDomainCanon::ARTIFACT_READY_FOR_REVIEW,
            'artifact_hash' => MissionCanonicalHash::sha256([
                'run_uuid' => $run->uuid,
                'artifact_type' => MarketingDomainCanon::ARTIFACT_CAMPAIGN,
                'payload' => $payload,
            ]),
        ]);
    }
}
