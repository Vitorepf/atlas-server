<?php

namespace App\Services\Ai\MarketingDomain;

use App\Models\AiMarketingArtifact;
use App\Models\AiMarketingRun;
use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CampaignPlanService
{
    public function __construct(private readonly MarketingPlaybook $playbook = new MarketingPlaybook) {}

    /**
     * Deterministic traffic brief — keyword-intent-mapper + account-structurer + rsa-writer +
     * bidding-strategist (+ demand-gen-architect for YouTube). Account structure for Smart Bidding,
     * keyword intent → bridge lead, RSA spec, audience signals, and the VBB/seasonality/data-exclusion
     * bidding knowledge. For channel=youtube it returns the Demand Gen 2025 playbook instead of Search.
     *
     * @return array<string,mixed>
     */
    public function blueprint(string $channel = 'google_search'): array
    {
        $common = [
            'audience_signals' => $this->playbook->audienceSignals(),
            'bidding_knowledge' => [
                'value_based_bidding' => $this->playbook->valueBasedBidding(),
                'seasonality_adjustments' => $this->playbook->seasonalityAdjustments(),
                'data_exclusions' => $this->playbook->dataExclusions(),
            ],
        ];

        if ($channel === 'youtube' || $channel === 'demand_gen') {
            return array_merge([
                'skill' => 'demand-gen-architect',
                'channel' => 'youtube_demand_gen',
                'demand_gen' => $this->playbook->demandGen(),
            ], $common);
        }

        return array_merge([
            'skill' => 'keyword-intent-mapper + account-structurer + rsa-writer + bidding-strategist',
            'channel' => 'google_search',
            'account_structures' => $this->playbook->accountStructures(),
            'keyword_intent_buckets' => $this->playbook->keywordIntentBuckets(),
            'rsa_spec' => $this->playbook->rsaSpec(),
        ], $common);
    }

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
