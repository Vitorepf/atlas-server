<?php

namespace App\Services\Ai\Kernel\Architecture;

final class ProviderReleaseSourceTrustPolicy
{
    public const TIER_PRIMARY = 'tier_1_official';

    public const TIER_TECHNICAL = 'tier_2_technical';

    public const TIER_WEAK_SIGNAL = 'tier_3_weak_signal';

    /**
     * @return array<string,mixed>
     */
    public function evaluate(string $tier): array
    {
        $tier = $this->normalizeTier($tier);

        return [
            'schema_version' => 'atlas.provider_release.source_trust.v1',
            'tier' => $tier,
            'can_create_release_envelope_draft' => $this->canCreateReleaseEnvelopeDraft($tier),
            'primary_source_required' => $this->primarySourceRequired($tier),
            'decide_signal_allowed' => $tier === self::TIER_PRIMARY,
            'recommended_next_action' => $this->recommendedNextAction($tier),
            'allowed_outputs' => $this->allowedOutputs($tier),
            'prohibited_outputs' => $this->prohibitedOutputs(),
        ];
    }

    public function canCreateReleaseEnvelopeDraft(string $tier): bool
    {
        return $this->normalizeTier($tier) === self::TIER_PRIMARY;
    }

    public function primarySourceRequired(string $tier): bool
    {
        return $this->normalizeTier($tier) !== self::TIER_PRIMARY;
    }

    public function normalizeTier(string $tier): string
    {
        return match ($tier) {
            self::TIER_PRIMARY, 'tier1', 'tier_1', 'official', 'primary' => self::TIER_PRIMARY,
            self::TIER_TECHNICAL, 'tier2', 'tier_2', 'technical', 'ecosystem' => self::TIER_TECHNICAL,
            default => self::TIER_WEAK_SIGNAL,
        };
    }

    /**
     * @return array<int,string>
     */
    public function allowedOutputs(string $tier): array
    {
        return match ($this->normalizeTier($tier)) {
            self::TIER_PRIMARY => [
                'provider_release_candidate',
                'provider_release_envelope_draft',
                'inbox_review_item',
                'curator_finding',
                'ap_suggestion',
                'rivals_benchmark_suggestion',
            ],
            self::TIER_TECHNICAL => [
                'provider_release_candidate',
                'inbox_review_item',
                'primary_source_lookup',
            ],
            default => [
                'unconfirmed_signal',
                'primary_source_lookup',
            ],
        };
    }

    /**
     * @return array<int,string>
     */
    public function prohibitedOutputs(): array
    {
        return [
            'code_change',
            'policy_patch',
            'provider_routing_change',
            'decision_receipt_mutation',
            'memory_core_promotion',
            'provider_projection_write',
            'paid_benchmark_execution',
        ];
    }

    private function recommendedNextAction(string $tier): string
    {
        return match ($this->normalizeTier($tier)) {
            self::TIER_PRIMARY => 'run_provider_release_review',
            self::TIER_TECHNICAL => 'find_primary_source_before_release_envelope',
            default => 'treat_as_unconfirmed_signal',
        };
    }
}
