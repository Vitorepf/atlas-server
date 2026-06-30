<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPostCommitImpactProofSampler;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainPostCommitImpactProofSamplerTest extends TestCase
{
    private function sampler(): AtlasExternalBrainPostCommitImpactProofSampler
    {
        return new AtlasExternalBrainPostCommitImpactProofSampler;
    }

    /** Full-evidence commit — all proof signals satisfied. */
    private function fullCommit(array $overrides = []): array
    {
        return array_merge([
            'commit_sha'                => 'abc123',
            'task_packet_id'            => 'task-001',
            'promised_capability_delta' => 'adds outcome learning feedback loop',
            'observed_impl_files'       => ['app/Services/LearningFeedback.php'],
            'observed_test_files'       => ['tests/Unit/LearningFeedbackTest.php'],
            'queue_health_effect'       => 'improved',
            'cosmetic_only'             => false,
            'test_only'                 => false,
            'observed_behavior_delta'   => 'learning loop now closes on cycle completion',
            'learning_delta'            => 'outcome_learning_enabled',
            'acceptance_delta'          => 'cycle_1_acceptance_passed',
        ], $overrides);
    }

    // ── Output shape ──────────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->sampler()->sample($this->fullCommit());

        foreach (['schema', 'commit_sha', 'task_packet_id', 'impact_label',
                  'label_reason', 'promised_delta_observed', 'ranking_signal',
                  'causal_proof_strength'] as $k) {
            $this->assertArrayHasKey($k, $r);
        }
        $this->assertSame(AtlasExternalBrainPostCommitImpactProofSampler::SCHEMA, $r['schema']);
    }

    // ── AC1: promised_delta_observed is false without concrete behavior delta ─

    public function test_promised_delta_observed_is_true_when_all_evidence_aligns(): void
    {
        $r = $this->sampler()->sample($this->fullCommit());

        $this->assertTrue($r['promised_delta_observed']);
    }

    public function test_promised_delta_observed_is_false_without_observed_behavior_delta(): void
    {
        // impl + tests exist, promised delta exists, but NO observed_behavior_delta
        $r = $this->sampler()->sample($this->fullCommit([
            'observed_behavior_delta' => '',
        ]));

        $this->assertFalse($r['promised_delta_observed']);
    }

    public function test_promised_delta_observed_is_false_when_no_impl_files(): void
    {
        $r = $this->sampler()->sample($this->fullCommit([
            'observed_impl_files' => [],
        ]));

        $this->assertFalse($r['promised_delta_observed']);
    }

    public function test_promised_delta_observed_is_false_when_no_test_files(): void
    {
        $r = $this->sampler()->sample($this->fullCommit([
            'observed_test_files' => [],
        ]));

        $this->assertFalse($r['promised_delta_observed']);
    }

    public function test_promised_delta_observed_is_false_when_no_promised_delta(): void
    {
        $r = $this->sampler()->sample($this->fullCommit([
            'promised_capability_delta' => '',
        ]));

        $this->assertFalse($r['promised_delta_observed']);
    }

    // ── AC2: ranking_signal increases only when all evidence aligns ───────────

    public function test_full_evidence_alignment_produces_high_ranking_signal(): void
    {
        $r = $this->sampler()->sample($this->fullCommit());

        $this->assertSame(AtlasExternalBrainPostCommitImpactProofSampler::LABEL_HIGH, $r['impact_label']);
        $this->assertEqualsWithDelta(+0.30, $r['ranking_signal'], 0.001);
    }

    public function test_impl_tests_delta_without_queue_improvement_gives_medium_signal(): void
    {
        $r = $this->sampler()->sample($this->fullCommit([
            'queue_health_effect' => 'neutral',
        ]));

        $this->assertSame(AtlasExternalBrainPostCommitImpactProofSampler::LABEL_MEDIUM, $r['impact_label']);
        $this->assertEqualsWithDelta(+0.15, $r['ranking_signal'], 0.001);
    }

    public function test_impl_only_without_delta_gives_low_signal(): void
    {
        $r = $this->sampler()->sample($this->fullCommit([
            'promised_capability_delta' => '',
            'queue_health_effect'       => 'neutral',
        ]));

        $this->assertSame(AtlasExternalBrainPostCommitImpactProofSampler::LABEL_LOW, $r['impact_label']);
        $this->assertEqualsWithDelta(+0.05, $r['ranking_signal'], 0.001);
    }

    public function test_ranking_signal_for_none_label_is_zero(): void
    {
        $r = $this->sampler()->sample([
            'test_only'    => true,
            'cosmetic_only' => false,
        ]);

        $this->assertSame(AtlasExternalBrainPostCommitImpactProofSampler::LABEL_NONE, $r['impact_label']);
        $this->assertEqualsWithDelta(0.00, $r['ranking_signal'], 0.001);
    }

    // ── AC3: cosmetic/proxy commits get low impact even with green tests ──────

    public function test_cosmetic_commit_with_green_tests_gets_negative_label(): void
    {
        // Tests are green (files provided) but commit is cosmetic
        $r = $this->sampler()->sample($this->fullCommit([
            'cosmetic_only' => true,
        ]));

        $this->assertSame(AtlasExternalBrainPostCommitImpactProofSampler::LABEL_NEGATIVE, $r['impact_label']);
        $this->assertNotSame(AtlasExternalBrainPostCommitImpactProofSampler::LABEL_HIGH,   $r['impact_label']);
        $this->assertNotSame(AtlasExternalBrainPostCommitImpactProofSampler::LABEL_MEDIUM, $r['impact_label']);
    }

    public function test_cosmetic_commit_negative_ranking_signal_is_negative(): void
    {
        $r = $this->sampler()->sample($this->fullCommit(['cosmetic_only' => true]));

        $this->assertLessThan(0.0, $r['ranking_signal']);
    }

    public function test_proxy_commit_test_only_gets_none_label(): void
    {
        $r = $this->sampler()->sample([
            'test_only'              => true,
            'observed_impl_files'    => [],
            'observed_test_files'    => ['tests/Unit/FooTest.php'],
            'observed_behavior_delta' => '',
        ]);

        $this->assertSame(AtlasExternalBrainPostCommitImpactProofSampler::LABEL_NONE, $r['impact_label']);
        $this->assertNotSame(AtlasExternalBrainPostCommitImpactProofSampler::LABEL_HIGH,   $r['impact_label']);
        $this->assertNotSame(AtlasExternalBrainPostCommitImpactProofSampler::LABEL_MEDIUM, $r['impact_label']);
    }

    public function test_promised_delta_with_no_impl_or_test_evidence_is_negative(): void
    {
        // Claimed delta but zero evidence → negative
        $r = $this->sampler()->sample([
            'promised_capability_delta' => 'adds a new learning mechanism',
            'observed_impl_files'       => [],
            'observed_test_files'       => [],
        ]);

        $this->assertSame(AtlasExternalBrainPostCommitImpactProofSampler::LABEL_NEGATIVE, $r['impact_label']);
    }

    // ── AC4: pure and deterministic ───────────────────────────────────────────

    public function test_sample_is_deterministic(): void
    {
        $input = $this->fullCommit(['queue_health_effect' => 'neutral']);
        $a = $this->sampler()->sample($input);
        $b = $this->sampler()->sample($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
