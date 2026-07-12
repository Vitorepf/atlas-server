<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainDomainWaveReadinessManifest;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainDomainWaveReadinessManifestTest extends TestCase
{
    private function evidence(array $overrides = []): array
    {
        return array_merge([
            'version' => 'wave-1.v1',
            'corpus_coverage' => 0.95,
            'private_hidden_cases' => 30,
            'public_only' => false,
            'independent_oracles' => 3,
            'capability_routes' => 2,
            'risk_depths' => ['low', 'medium', 'high'],
            'rollback_dr_proven' => true,
            'causal_multiplier_proven' => true,
            'real_soak' => true,
            'outcome_window_days' => 30,
            'rivals_evidence' => true,
            'prior_wave_quality' => 0.90,
            'current_quality' => 0.92,
            'provider_count' => 2,
        ], $overrides);
    }

    public function test_complete_wave_is_promotable_only_when_all_dimensions_pass(): void
    {
        $result = (new AtlasExternalBrainDomainWaveReadinessManifest)->evaluate([
            'requested_wave' => 1,
            'waves' => ['wave_1' => $this->evidence()],
        ]);

        $this->assertSame(AtlasExternalBrainDomainWaveReadinessManifest::STATUS_PROMOTABLE, $result['waves']['wave_1']['status']);
        $this->assertTrue($result['waves']['wave_1']['promotion_allowed']);
        $this->assertSame([], $result['waves']['wave_1']['blockers']);
    }

    public function test_missing_private_oracle_soak_rollback_or_rivals_evidence_blocks(): void
    {
        $result = (new AtlasExternalBrainDomainWaveReadinessManifest)->evaluate([
            'requested_wave' => 1,
            'waves' => ['wave_1' => $this->evidence([
                'private_hidden_cases' => 0,
                'independent_oracles' => 0,
                'real_soak' => false,
                'rollback_dr_proven' => false,
                'rivals_evidence' => false,
            ])],
        ]);

        $wave = $result['waves']['wave_1'];
        $this->assertSame(AtlasExternalBrainDomainWaveReadinessManifest::STATUS_BLOCKED, $wave['status']);
        foreach (['private_hidden_cases_required', 'independent_oracles_required', 'real_soak_required', 'rollback_dr_required', 'rivals_evidence_required'] as $blocker) {
            $this->assertContains($blocker, $wave['blockers']);
        }
    }

    public function test_public_only_benchmark_simulated_soak_narrow_provider_and_quality_regression_block(): void
    {
        $result = (new AtlasExternalBrainDomainWaveReadinessManifest)->evaluate([
            'requested_wave' => 1,
            'waves' => ['wave_1' => $this->evidence([
                'public_only' => true,
                'real_soak' => false,
                'provider_count' => 1,
                'current_quality' => 0.80,
            ])],
        ]);

        $blockers = $result['waves']['wave_1']['blockers'];
        foreach (['private_benchmark_required', 'real_soak_required', 'provider_diversity_required', 'prior_wave_non_regression_failed'] as $blocker) {
            $this->assertContains($blocker, $blockers);
        }
    }

    public function test_future_waves_remain_explicitly_unpromoted(): void
    {
        $result = (new AtlasExternalBrainDomainWaveReadinessManifest)->evaluate([
            'requested_wave' => 1,
            'waves' => [
                'wave_1' => $this->evidence(),
                'wave_2' => $this->evidence(['version' => 'wave-2.v1']),
            ],
        ]);

        $this->assertSame(AtlasExternalBrainDomainWaveReadinessManifest::STATUS_PROMOTABLE, $result['waves']['wave_1']['status']);
        $this->assertSame(AtlasExternalBrainDomainWaveReadinessManifest::STATUS_NOT_PROMOTED, $result['waves']['wave_2']['status']);
        $this->assertFalse($result['waves']['wave_2']['promotion_allowed']);
        $this->assertContains('future_wave_not_authorized', $result['waves']['wave_2']['blockers']);
    }

    public function test_missing_or_invalid_facts_stay_blocked_and_output_is_deterministic(): void
    {
        $input = ['requested_wave' => 1, 'waves' => ['wave_1' => []]];
        $service = new AtlasExternalBrainDomainWaveReadinessManifest;

        $first = $service->evaluate($input);
        $this->assertSame($first, $service->evaluate($input));
        $this->assertSame(AtlasExternalBrainDomainWaveReadinessManifest::STATUS_BLOCKED, $first['waves']['wave_1']['status']);
        $this->assertFalse($first['waves']['wave_1']['promotion_allowed']);
        $this->assertNotEmpty($first['waves']['wave_1']['blockers']);
    }
}
