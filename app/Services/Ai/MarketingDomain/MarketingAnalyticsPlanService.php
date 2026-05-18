<?php

namespace App\Services\Ai\MarketingDomain;

use App\Models\AiMarketingArtifact;
use App\Models\AiMarketingRun;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MarketingAnalyticsPlanService
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function plan(AiMarketingRun $run, array $payload): AiMarketingArtifact
    {
        $events = (array) ($payload['events'] ?? []);
        if ($events === []) {
            throw new InvalidArgumentException('analytics plan requires at least one event');
        }
        foreach ($events as $i => $event) {
            foreach (['name', 'properties'] as $required) {
                if (! array_key_exists($required, (array) $event)) {
                    throw new InvalidArgumentException("analytics event[{$i}] requires field [{$required}]");
                }
            }
        }
        $kpis = (array) ($payload['kpis'] ?? []);
        if ($kpis === []) {
            throw new InvalidArgumentException('analytics plan requires at least one KPI');
        }

        return AiMarketingArtifact::query()->create([
            'uuid' => (string) Str::uuid(),
            'marketing_run_id' => $run->id,
            'artifact_type' => MarketingDomainCanon::ARTIFACT_ANALYTICS_PLAN,
            'title' => (string) ($payload['title'] ?? 'Analytics plan'),
            'payload' => $payload,
            'status' => MarketingDomainCanon::ARTIFACT_READY_FOR_REVIEW,
            'artifact_hash' => MissionCanonicalHash::sha256([
                'run_uuid' => $run->uuid,
                'artifact_type' => MarketingDomainCanon::ARTIFACT_ANALYTICS_PLAN,
                'payload' => $payload,
            ]),
        ]);
    }
}
