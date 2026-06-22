<?php

namespace App\Services\Ai\MarketingDomain;

use App\Models\AiMarketingArtifact;
use App\Models\AiMarketingRun;
use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MarketingAnalyticsPlanService
{
    public function __construct(private readonly MarketingPlaybook $playbook = new MarketingPlaybook) {}

    /**
     * Deterministic analytics/tracking brief — conversion-pipeline + tracking-setup. The loop that
     * sends the REAL SALE back so Smart Bidding optimizes for profit (OCI/GCLID/Enhanced → Data
     * Manager API, now in effect), the attribution stack, trackers by volume, and the KPI tree
     * anchored on profit-per-unit-economics (not a proxy).
     *
     * @return array<string,mixed>
     */
    public function blueprint(): array
    {
        return [
            'skill' => 'conversion-pipeline + tracking-setup',
            'conversion_pipeline' => $this->playbook->conversionPipeline(),
            'primary_kpis' => ['CPA real vs Max CPA', 'ROAS real (venda, não lead)', 'EPC', 'CVR clique→venda', 'margem por venda após refund'],
            'funnel_events' => ['ad_click', 'bridge_view', 'vsl_play', 'vsl_pitch_reached', 'checkout_start', 'sale', 'refund'],
            'anti_proxy' => 'KPI-mãe = LUCRO por unidade econômica. CTR/QS/cliques são diagnósticos, não a meta.',
        ];
    }

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
