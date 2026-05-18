<?php

namespace App\Services\Ai\MarketingDomain;

use App\Models\AiMarketingArtifact;
use App\Models\AiMarketingRun;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class FunnelPlanService
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function plan(AiMarketingRun $run, array $payload): AiMarketingArtifact
    {
        $stages = (array) ($payload['stages'] ?? []);
        if (count($stages) < 3) {
            throw new InvalidArgumentException('funnel plan requires at least 3 stages');
        }
        foreach ($stages as $i => $stage) {
            foreach (['name', 'metric', 'target_conversion'] as $required) {
                if (! array_key_exists($required, (array) $stage)) {
                    throw new InvalidArgumentException("funnel stage[{$i}] requires field [{$required}]");
                }
            }
        }

        return AiMarketingArtifact::query()->create([
            'uuid' => (string) Str::uuid(),
            'marketing_run_id' => $run->id,
            'artifact_type' => MarketingDomainCanon::ARTIFACT_FUNNEL,
            'title' => (string) ($payload['title'] ?? 'Funnel plan'),
            'payload' => $payload,
            'status' => MarketingDomainCanon::ARTIFACT_READY_FOR_REVIEW,
            'artifact_hash' => MissionCanonicalHash::sha256([
                'run_uuid' => $run->uuid,
                'artifact_type' => MarketingDomainCanon::ARTIFACT_FUNNEL,
                'payload' => $payload,
            ]),
        ]);
    }
}
