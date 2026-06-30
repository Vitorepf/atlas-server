<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainValueProofSampler;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainValueProofSamplerTest extends TestCase
{
    private AtlasExternalBrainValueProofSampler $sampler;

    protected function setUp(): void
    {
        $this->sampler = new AtlasExternalBrainValueProofSampler;
    }

    // ── AC1: classifies value from concrete signals, not objective wording ─────

    public function test_integration_plus_behavior_tests_yields_real_capability(): void
    {
        $r = $this->sampler->sample([
            'task_packet_id'    => 'task-1',
            'changed_files'     => ['app/Services/Ai/Foo.php'],
            'tests'             => ['tests/Feature/Ai/FooTest.php'],
            'integration_status' => true,
        ]);

        $this->assertSame(AtlasExternalBrainValueProofSampler::CLASS_REAL_CAPABILITY, $r['value_class']);
    }

    public function test_downstream_usage_plus_tests_yields_real_capability(): void
    {
        $r = $this->sampler->sample([
            'downstream_usage' => ['app/Services/Ai/Consumer.php'],
            'tests'            => ['tests/Feature/Ai/ConsumerTest.php'],
        ]);

        $this->assertSame(AtlasExternalBrainValueProofSampler::CLASS_REAL_CAPABILITY, $r['value_class']);
    }

    public function test_all_log_files_no_integration_yields_observability(): void
    {
        $r = $this->sampler->sample([
            'changed_files' => ['app/Logging/SomeLogger.php', 'app/Monitoring/HealthCheck.php'],
        ]);

        $this->assertSame(AtlasExternalBrainValueProofSampler::CLASS_OBSERVABILITY, $r['value_class']);
    }

    public function test_consolidation_files_with_tests_yields_consolidation(): void
    {
        $r = $this->sampler->sample([
            'changed_files' => ['app/Services/DeduplicateRecords.php'],
            'tests'         => ['tests/Feature/DeduplicateTest.php'],
        ]);

        $this->assertSame(AtlasExternalBrainValueProofSampler::CLASS_CONSOLIDATION, $r['value_class']);
    }

    public function test_scaffolding_file_with_no_behavior_yields_scaffolding(): void
    {
        $r = $this->sampler->sample([
            'changed_files' => ['app/Services/StubAdapter.php'],
        ]);

        $this->assertSame(AtlasExternalBrainValueProofSampler::CLASS_SCAFFOLDING, $r['value_class']);
    }

    public function test_no_signals_yields_unknown(): void
    {
        $r = $this->sampler->sample([]);

        $this->assertSame(AtlasExternalBrainValueProofSampler::CLASS_UNKNOWN, $r['value_class']);
        $this->assertSame(AtlasExternalBrainValueProofSampler::CONFIDENCE_LOW, $r['confidence']);
    }

    public function test_two_independent_signals_yield_high_confidence(): void
    {
        $r = $this->sampler->sample([
            'integration_status' => true,
            'tests'              => ['tests/Feature/FooTest.php'],
            'behavior_evidence'  => ['benchmark result'],
        ]);

        $this->assertSame(AtlasExternalBrainValueProofSampler::CONFIDENCE_HIGH, $r['confidence']);
    }

    public function test_commit_count_alone_is_not_proof(): void
    {
        // Commit count is not a field the sampler accepts — must rely on concrete signals
        $r = $this->sampler->sample(['commit_count' => 50]);

        $this->assertNotSame(AtlasExternalBrainValueProofSampler::CLASS_REAL_CAPABILITY, $r['value_class']);
    }

    // ── AC2: admit rejects below proof/confidence thresholds ──────────────────

    public function test_admit_passes_when_at_least_one_dimension_above_floor(): void
    {
        $r = $this->sampler->admit(['autonomy_gain_score' => 0.80]);

        $this->assertTrue($r['admitted']);
        $this->assertNull($r['rejection_reason']);
    }

    public function test_admit_rejects_all_zero_dimensions(): void
    {
        $r = $this->sampler->admit([
            'unlocks_task_count'           => 0,
            'give_back_risk_delta'         => 0.0,
            'simplification_score'         => 0.0,
            'autonomy_gain_score'          => 0.0,
            'certification_strength_score' => 0.0,
        ]);

        $this->assertFalse($r['admitted']);
        $this->assertNotNull($r['rejection_reason']);
    }

    public function test_admit_respects_configured_min_threshold(): void
    {
        // With floor=0.90, a 0.50 autonomy score should fail
        $r = $this->sampler->admit(
            ['autonomy_gain_score' => 0.50],
            ['min_dimension_score' => 0.90]
        );

        $this->assertFalse($r['admitted']);
    }

    public function test_admit_unlock_dimension_normalized_by_factor(): void
    {
        // 5 unlocks / 5 (normalization) = 1.0
        $r = $this->sampler->admit(['unlocks_task_count' => 5]);

        $this->assertSame(1.0, $r['dimension_scores']['unlock']);
        $this->assertTrue($r['admitted']);
    }

    public function test_admit_returns_schema(): void
    {
        $r = $this->sampler->admit([]);

        $this->assertSame(AtlasExternalBrainValueProofSampler::SCHEMA_ADMIT, $r['schema']);
    }

    public function test_admit_surfaces_passing_dimensions_in_evidence(): void
    {
        $r = $this->sampler->admit(['autonomy_gain_score' => 0.75, 'simplification_score' => 0.80]);

        $this->assertNotEmpty($r['admission_evidence']);
        $combined = implode(',', $r['admission_evidence']);
        $this->assertStringContainsString('autonomy_gain', $combined);
    }

    // ── AC3: sampleBatch surfaces weak/proxy candidates ───────────────────────

    public function test_sample_batch_returns_all_classified_records(): void
    {
        $records = [
            ['task_packet_id' => 'a', 'integration_status' => true, 'tests' => ['FooTest.php']],
            ['task_packet_id' => 'b'],
        ];

        $result = $this->sampler->sampleBatch($records);

        $this->assertSame(2, $result['count']);
        $this->assertCount(2, $result['samples']);
    }

    public function test_sample_batch_reveals_scaffolding_proxy_candidate(): void
    {
        $records = [
            ['task_packet_id' => 'stub', 'changed_files' => ['app/StubFoo.php']],
        ];

        $result  = $this->sampler->sampleBatch($records);
        $classes = array_column($result['samples'], 'value_class');

        $this->assertContains(AtlasExternalBrainValueProofSampler::CLASS_SCAFFOLDING, $classes);
    }

    public function test_sample_batch_empty_returns_zero_count(): void
    {
        $r = $this->sampler->sampleBatch([]);

        $this->assertSame(0, $r['count']);
        $this->assertSame([], $r['samples']);
    }

    // ── AC4: deterministic, no providers ──────────────────────────────────────

    public function test_sample_is_deterministic(): void
    {
        $record = ['task_packet_id' => 'x', 'integration_status' => true, 'tests' => ['FooTest.php']];

        $this->assertSame(json_encode($this->sampler->sample($record)), json_encode($this->sampler->sample($record)));
    }

    public function test_admit_is_deterministic(): void
    {
        $c = ['autonomy_gain_score' => 0.6];

        $this->assertSame(json_encode($this->sampler->admit($c)), json_encode($this->sampler->admit($c)));
    }
}
