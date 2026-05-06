<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;

class AtlasMarketingOrchestrator implements AtlasDomainOrchestrator
{
    public function __construct(
        private readonly MarketingDraftService $drafts,
    ) {}

    public function orchestratorId(): string
    {
        return 'AtlasMarketingOrchestrator';
    }

    public function supportedDomains(): array
    {
        return ['marketing'];
    }

    public function supportedFlows(): array
    {
        return [
            'marketing.strategy',
            'marketing.research',
            'marketing.positioning',
            'marketing.campaign',
            'marketing.creative',
            'marketing.copywriting',
            'marketing.media_plan',
            'marketing.landing_page',
            'marketing.email',
            'marketing.social',
            'marketing.video_script',
            'marketing.ab_test',
            'marketing.analytics',
            'marketing.brand_review',
            'marketing.forge',
        ];
    }

    public function maturity(): string
    {
        return 'implemented';
    }

    public function plan(string $flow, array $input = [], array $context = []): array
    {
        if (! in_array($flow, $this->supportedFlows(), true)) {
            return $this->unsupportedFlow($flow, $input, $context);
        }

        $packet = $this->drafts->packet($flow, $input);

        return [
            'schema_version' => 'atlas.marketing.orchestrator_plan.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'planned',
            'mode' => 'draft_and_review',
            'domain' => 'marketing',
            'flow' => $flow,
            'packet' => $packet,
            'context' => $context,
            'next_stage' => 'execute_draft_packet',
            'forbidden_actions' => $packet['forbidden_actions'] ?? [],
        ];
    }

    public function execute(array $plan, array $context = []): array
    {
        $flow = (string) ($plan['flow'] ?? 'marketing.campaign');

        if (! in_array($flow, $this->supportedFlows(), true)) {
            return $this->unsupportedFlow($flow, $plan, $context);
        }

        $packet = (array) ($plan['packet'] ?? []);
        $input = [
            ...$context,
            'goal' => (string) data_get($packet, 'brief.goal', $plan['goal'] ?? ''),
            'offer' => (string) data_get($packet, 'brief.offer', $plan['offer'] ?? ''),
            'audience' => (string) data_get($packet, 'brief.audience', $plan['audience'] ?? ''),
            'brand_voice' => (string) data_get($packet, 'brief.brand_voice', $plan['brand_voice'] ?? ''),
            'claims' => (array) data_get($packet, 'brief.claims', $plan['claims'] ?? []),
            'channels' => (array) data_get($packet, 'brief.channels', $plan['channels'] ?? []),
            'constraints' => (array) data_get($packet, 'brief.constraints', $plan['constraints'] ?? []),
            'business_context' => (string) ($packet['business_context'] ?? $plan['business_context'] ?? ''),
            'surface_id' => (string) ($context['surface_id'] ?? 'atlas_domain_orchestrator_marketing'),
        ];

        $result = $this->drafts->auditedPacket($flow, $input);

        return [
            'schema_version' => 'atlas.marketing.orchestrator_result.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'succeeded',
            'mode' => 'draft_and_review',
            'domain' => 'marketing',
            'flow' => $flow,
            'result' => $result,
            'external_publish_allowed' => false,
        ];
    }

    public function repair(array $failure, array $context = []): array
    {
        return [
            'schema_version' => 'atlas.marketing.repair.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'planned',
            'reason' => 'marketing_repairs_by_requesting_missing_brief_or_claim_evidence',
            'domain' => 'marketing',
            'flow' => (string) ($failure['flow'] ?? 'marketing.campaign'),
            'recommended_action' => 'Regenerate the draft packet with explicit goal, offer, audience, brand voice, claims, channels, and measurement plan.',
            'failure' => $failure,
            'context' => $context,
        ];
    }

    public function summarize(array $result, array $context = []): array
    {
        return [
            'schema_version' => 'atlas.marketing.summary.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => (string) ($result['status'] ?? 'unknown'),
            'summary' => 'Marketing generated a governed draft-and-review packet with brand, claim, audience, measurement, and calibration gates.',
            'domain' => 'marketing',
            'flow' => (string) ($result['flow'] ?? 'marketing.campaign'),
            'external_publish_allowed' => false,
            'context' => $context,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function unsupportedFlow(string $flow, array $payload, array $context): array
    {
        return [
            'schema_version' => 'atlas.marketing.unsupported_flow.v1',
            'orchestrator' => $this->orchestratorId(),
            'maturity' => $this->maturity(),
            'status' => 'not_supported',
            'reason' => 'unsupported_marketing_flow',
            'flow' => $flow,
            'supported_flows' => $this->supportedFlows(),
            'payload' => $payload,
            'context' => $context,
        ];
    }
}
