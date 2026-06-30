<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPostCommitImpactProofSampler;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainPostCommitImpactProofSamplerTest extends TestCase
{
    private AtlasExternalBrainPostCommitImpactProofSampler $sampler;

    protected function setUp(): void
    {
        $this->sampler = new AtlasExternalBrainPostCommitImpactProofSampler;
    }

    private function base(array $overrides = []): array
    {
        return array_merge([
            'commit_sha'                => 'abc123',
            'task_packet_id'            => 'task-001',
            'promised_capability_delta' => 'adds queue drain forecast dossier',
            'observed_impl_files'       => ['app/Services/Ai/AtlasFoo.php'],
            'observed_test_files'       => ['tests/Unit/Ai/AtlasFooTest.php'],
            'queue_health_effect'       => 'improved',
            'cosmetic_only'             => false,
            'test_only'                 => false,
            'observed_behavior_delta'   => 'queue drain forecast now appears in dossier output',
        ], $overrides);
    }

    // ── Schema / keys ─────────────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->sampler->sample($this->base());

        foreach (['schema', 'commit_sha', 'task_packet_id', 'impact_label', 'label_reason', 'promised_delta_observed',
                  'ranking_signal', 'causal_proof_strength', 'missing_proof_signals', 'next_learning_action'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainPostCommitImpactProofSampler::SCHEMA, $result['schema']);
    }

    // ── green tests alone do not prove promised_delta_observed ────────────────

    public function test_green_tests_alone_without_behavior_delta_do_not_prove_promised_delta(): void
    {
        $result = $this->sampler->sample($this->base(['observed_behavior_delta' => '']));

        $this->assertFalse($result['promised_delta_observed']);
        $this->assertContains('behavior_delta', $result['missing_proof_signals']);
    }

    // ── cosmetic-only / test-only / promised-without-impl → non-positive ranking ──

    public function test_cosmetic_only_has_non_positive_ranking_signal(): void
    {
        $result = $this->sampler->sample($this->base(['cosmetic_only' => true]));

        $this->assertContains($result['impact_label'], [
            AtlasExternalBrainPostCommitImpactProofSampler::LABEL_NONE,
            AtlasExternalBrainPostCommitImpactProofSampler::LABEL_NEGATIVE,
        ]);
        $this->assertLessThanOrEqual(0.0, $result['ranking_signal']);
    }

    public function test_test_only_has_non_positive_ranking_signal(): void
    {
        $result = $this->sampler->sample($this->base([
            'test_only'           => true,
            'observed_impl_files' => [],
        ]));

        $this->assertContains($result['impact_label'], [
            AtlasExternalBrainPostCommitImpactProofSampler::LABEL_NONE,
            AtlasExternalBrainPostCommitImpactProofSampler::LABEL_NEGATIVE,
        ]);
        $this->assertLessThanOrEqual(0.0, $result['ranking_signal']);
    }

    public function test_promised_delta_without_implementation_has_non_positive_ranking_signal(): void
    {
        $result = $this->sampler->sample($this->base([
            'observed_impl_files' => [],
            'observed_test_files' => [],
        ]));

        $this->assertContains($result['impact_label'], [
            AtlasExternalBrainPostCommitImpactProofSampler::LABEL_NONE,
            AtlasExternalBrainPostCommitImpactProofSampler::LABEL_NEGATIVE,
        ]);
        $this->assertLessThanOrEqual(0.0, $result['ranking_signal']);
    }

    // ── next_learning_action ───────────────────────────────────────────────────

    public function test_next_learning_action_present_for_every_label(): void
    {
        $result = $this->sampler->sample($this->base());
        $this->assertNotEmpty($result['next_learning_action']);
    }

    public function test_commit_sha_and_task_packet_id_echoed(): void
    {
        $result = $this->sampler->sample($this->base([
            'commit_sha'     => 'deadbeef',
            'task_packet_id' => 'task-xyz',
        ]));

        $this->assertSame('deadbeef', $result['commit_sha']);
        $this->assertSame('task-xyz', $result['task_packet_id']);
    }

    // ── AC1: impl+tests+delta+improved_queue → high ───────────────────────────

    public function test_high_label_for_impl_tests_delta_and_improved_queue(): void
    {
        $result = $this->sampler->sample($this->base());

        $this->assertSame(AtlasExternalBrainPostCommitImpactProofSampler::LABEL_HIGH, $result['impact_label']);
        $this->assertSame(+0.30, $result['ranking_signal']);
        $this->assertTrue($result['promised_delta_observed']);
    }

    // ── AC1: impl+tests+delta but neutral queue → medium ─────────────────────

    public function test_medium_label_for_impl_tests_delta_without_queue_improvement(): void
    {
        $result = $this->sampler->sample($this->base(['queue_health_effect' => 'neutral']));

        $this->assertSame(AtlasExternalBrainPostCommitImpactProofSampler::LABEL_MEDIUM, $result['impact_label']);
        $this->assertSame(+0.15, $result['ranking_signal']);
    }

    public function test_medium_label_when_queue_degraded_but_impl_tests_and_delta_present(): void
    {
        $result = $this->sampler->sample($this->base(['queue_health_effect' => 'degraded']));

        $this->assertSame(AtlasExternalBrainPostCommitImpactProofSampler::LABEL_MEDIUM, $result['impact_label']);
    }

    // ── AC1: impl present but tests missing → low ─────────────────────────────

    public function test_low_label_when_impl_present_but_no_tests(): void
    {
        $result = $this->sampler->sample($this->base(['observed_test_files' => []]));

        $this->assertSame(AtlasExternalBrainPostCommitImpactProofSampler::LABEL_LOW, $result['impact_label']);
        $this->assertSame(+0.05, $result['ranking_signal']);
        $this->assertFalse($result['promised_delta_observed']);
    }

    public function test_low_label_when_impl_present_but_no_capability_delta(): void
    {
        $result = $this->sampler->sample($this->base([
            'promised_capability_delta' => '',
            'observed_test_files'       => ['tests/FooTest.php'],
        ]));

        $this->assertSame(AtlasExternalBrainPostCommitImpactProofSampler::LABEL_LOW, $result['impact_label']);
    }

    // ── none labels ───────────────────────────────────────────────────────────

    public function test_none_label_for_test_only_commit(): void
    {
        $result = $this->sampler->sample($this->base([
            'observed_impl_files' => [],
            'test_only'           => true,
        ]));

        $this->assertSame(AtlasExternalBrainPostCommitImpactProofSampler::LABEL_NONE, $result['impact_label']);
        $this->assertSame(0.0, $result['ranking_signal']);
    }

    public function test_none_label_when_no_impl_and_no_delta(): void
    {
        $result = $this->sampler->sample($this->base([
            'observed_impl_files'       => [],
            'observed_test_files'       => [],
            'promised_capability_delta' => '',
        ]));

        $this->assertSame(AtlasExternalBrainPostCommitImpactProofSampler::LABEL_NONE, $result['impact_label']);
    }

    // ── AC2: promised delta but no impl/test evidence → negative ─────────────

    public function test_negative_label_when_promised_delta_has_no_observable_impl_or_test(): void
    {
        $result = $this->sampler->sample($this->base([
            'promised_capability_delta' => 'adds autonomous self-improvement loop',
            'observed_impl_files'       => [],
            'observed_test_files'       => [],
        ]));

        $this->assertSame(AtlasExternalBrainPostCommitImpactProofSampler::LABEL_NEGATIVE, $result['impact_label']);
        $this->assertSame(-0.20, $result['ranking_signal']);
        $this->assertFalse($result['promised_delta_observed']);
    }

    public function test_negative_label_for_cosmetic_only_commit(): void
    {
        $result = $this->sampler->sample($this->base(['cosmetic_only' => true]));

        $this->assertSame(AtlasExternalBrainPostCommitImpactProofSampler::LABEL_NEGATIVE, $result['impact_label']);
        $this->assertSame(-0.20, $result['ranking_signal']);
    }

    // ── promised_delta_observed logic ─────────────────────────────────────────

    public function test_promised_delta_observed_true_only_when_both_impl_and_tests_present(): void
    {
        $withBoth = $this->sampler->sample($this->base());
        $this->assertTrue($withBoth['promised_delta_observed']);

        $implOnly = $this->sampler->sample($this->base(['observed_test_files' => []]));
        $this->assertFalse($implOnly['promised_delta_observed']);

        $testOnly = $this->sampler->sample($this->base(['observed_impl_files' => [], 'test_only' => true]));
        $this->assertFalse($testOnly['promised_delta_observed']);
    }

    // ── AC3: causal_proof_strength ────────────────────────────────────────────

    public function test_causal_proof_strength_key_always_present(): void
    {
        $result = $this->sampler->sample([]);

        $this->assertArrayHasKey('causal_proof_strength', $result);
    }

    public function test_causal_proof_strength_zero_when_no_signals(): void
    {
        $result = $this->sampler->sample([]);

        $this->assertSame(0.0, $result['causal_proof_strength']);
    }

    public function test_causal_proof_strength_positive_when_observed_deltas_match_promised(): void
    {
        $result = $this->sampler->sample($this->base());

        // base() has impl+tests+delta+queue = 4/7 signals
        $this->assertGreaterThan(0.0, $result['causal_proof_strength']);
        $this->assertGreaterThan(0.0, $result['ranking_signal']);
    }

    public function test_causal_proof_strength_increases_with_more_signals(): void
    {
        $fewer = $this->sampler->sample($this->base(['queue_health_effect' => 'neutral']));
        $more  = $this->sampler->sample($this->base([
            'observed_behavior_delta' => 'self-heal rate improved 12%',
            'learning_delta'          => '0.08',
            'acceptance_delta'        => 'acceptance rate +5%',
        ]));

        $this->assertGreaterThan($fewer['causal_proof_strength'], $more['causal_proof_strength']);
    }

    public function test_causal_proof_strength_all_seven_signals_gives_one(): void
    {
        $result = $this->sampler->sample([
            'promised_capability_delta' => 'something',
            'observed_impl_files'       => ['app/Foo.php'],
            'observed_test_files'       => ['tests/FooTest.php'],
            'queue_health_effect'       => 'improved',
            'observed_behavior_delta'   => 'metrics improved',
            'learning_delta'            => '0.05',
            'acceptance_delta'          => '+3%',
        ]);

        $this->assertSame(1.0, $result['causal_proof_strength']);
    }

    // ── AC2: impl+tests without behavior/queue/learning/acceptance → ≤ medium ─

    public function test_no_high_label_without_any_proof_signals(): void
    {
        $result = $this->sampler->sample([
            'promised_capability_delta' => 'adds loop capability',
            'observed_impl_files'       => ['app/Foo.php'],
            'observed_test_files'       => ['tests/FooTest.php'],
        ]);

        $this->assertNotSame(AtlasExternalBrainPostCommitImpactProofSampler::LABEL_HIGH, $result['impact_label']);
    }

    // ── label_reason is always a non-empty string ─────────────────────────────

    public function test_label_reason_non_empty_for_all_labels(): void
    {
        $cases = [
            $this->base(),                                                            // high
            $this->base(['queue_health_effect' => 'neutral']),                        // medium
            $this->base(['observed_test_files' => []]),                               // low
            $this->base(['observed_impl_files' => [], 'test_only' => true]),          // none
            $this->base(['observed_impl_files' => [], 'observed_test_files' => []]),  // negative
            $this->base(['cosmetic_only' => true]),                                   // negative
        ];

        foreach ($cases as $case) {
            $result = $this->sampler->sample($case);
            $this->assertIsString($result['label_reason']);
            $this->assertNotEmpty($result['label_reason']);
        }
    }
}
