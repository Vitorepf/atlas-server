<?php

namespace App\Services\Ai\MarketingDomain;

use App\Models\AiMarketingArtifact;
use App\Models\AiMarketingRun;
use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class FunnelPlanService
{
    public function __construct(private readonly MarketingPlaybook $playbook = new MarketingPlaybook) {}

    /**
     * Deterministic funnel/page brief — bridge-builder + page-architect + quiz-funnel-builder +
     * funnel-architect. The advertorial listicle spec (cold-traffic #1 format), the message-match
     * 4 dimensions (page conversion lever #1), the high-converting page anatomy + 5 levers, the
     * quiz funnel, and the value ladder. Includes account-uptime knowledge as PERFORMANCE context
     * (advertorial original converts AND keeps the account alive) — never a gate.
     *
     * @return array<string,mixed>
     */
    public function blueprint(string $traffic = 'cold'): array
    {
        return [
            'skill' => 'bridge-builder + page-architect + quiz-funnel-builder + funnel-architect',
            'funnel_stages' => ['Anúncio', 'Advertorial/Bridge (domínio próprio)', 'VSL/Sales page', 'Checkout', 'Order bump → OTO/upsell'],
            'advertorial' => $this->playbook->advertorialSpec(),
            'message_match' => $this->playbook->messageMatch(),
            'page_anatomy' => $this->playbook->pageAnatomy(),
            'quiz_funnel' => $this->playbook->quizFunnel(),
            'value_ladder' => $this->playbook->valueLadder(),
            'cold_traffic_note' => $traffic === 'cold'
                ? 'Tráfego frio em modo CONSUMIR → liderar com listicle advertorial (informação antes do pedido).'
                : 'Tráfego quente/remarketing → pode ir mais direto à oferta.',
            'account_uptime' => $this->playbook->accountUptimeKnowledge(),
        ];
    }

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
