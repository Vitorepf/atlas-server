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
        ], $overrides);
    }

    // ── Schema / keys ─────────────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->sampler->sample($this->base());

        foreach (['schema', 'commit_sha', 'task_packet_id', 'impact_label', 'label_reason', 'promised_delta_observed', 'ranking_signal'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainPostCommitImpactProofSampler::SCHEMA, $result['schema']);
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
