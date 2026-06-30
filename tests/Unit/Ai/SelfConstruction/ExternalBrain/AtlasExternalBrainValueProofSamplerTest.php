<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainValueProofSampler;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainValueProofSamplerTest extends TestCase
{
    private AtlasExternalBrainValueProofSampler $sampler;

    protected function setUp(): void
    {
        $this->sampler = new AtlasExternalBrainValueProofSampler;
    }

    private function record(array $overrides = []): array
    {
        return array_merge([
            'task_packet_id'    => 'task-1',
            'changed_files'     => ['app/Services/AtlasFoo.php', 'tests/Unit/AtlasFooTest.php'],
            'tests'             => ['tests/Unit/AtlasFooTest.php'],
            'integration_status' => false,
            'downstream_usage'  => [],
            'behavior_evidence' => [],
        ], $overrides);
    }

    // ── Schema / envelope ────────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->sampler->sample($this->record());

        foreach (['schema', 'task_packet_id', 'value_class', 'confidence', 'signals'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
        $this->assertSame(AtlasExternalBrainValueProofSampler::SCHEMA, $result['schema']);
        $this->assertSame('task-1', $result['task_packet_id']);
    }

    public function test_value_class_is_one_of_valid_values(): void
    {
        $valid = [
            AtlasExternalBrainValueProofSampler::CLASS_REAL_CAPABILITY,
            AtlasExternalBrainValueProofSampler::CLASS_OBSERVABILITY,
            AtlasExternalBrainValueProofSampler::CLASS_CONSOLIDATION,
            AtlasExternalBrainValueProofSampler::CLASS_SCAFFOLDING,
            AtlasExternalBrainValueProofSampler::CLASS_UNKNOWN,
        ];

        $result = $this->sampler->sample($this->record());
        $this->assertContains($result['value_class'], $valid);
    }

    // ── AC1: real_capability requires integration + behavior evidence ─────────

    public function test_real_capability_when_integration_status_true_and_tests_present(): void
    {
        $result = $this->sampler->sample($this->record([
            'integration_status' => true,
            'tests'              => ['tests/Unit/AtlasFooTest.php'],
        ]));

        $this->assertSame(AtlasExternalBrainValueProofSampler::CLASS_REAL_CAPABILITY, $result['value_class']);
    }

    public function test_real_capability_when_downstream_usage_non_empty_and_tests_present(): void
    {
        $result = $this->sampler->sample($this->record([
            'integration_status' => false,
            'downstream_usage'   => ['app/Services/AtlasBar.php'],
            'tests'              => ['tests/Unit/AtlasFooTest.php'],
        ]));

        $this->assertSame(AtlasExternalBrainValueProofSampler::CLASS_REAL_CAPABILITY, $result['value_class']);
    }

    public function test_real_capability_confidence_high_with_multiple_signals(): void
    {
        $result = $this->sampler->sample($this->record([
            'integration_status' => true,
            'downstream_usage'   => ['app/Services/AtlasBar.php'],
            'tests'              => ['tests/Unit/AtlasFooTest.php'],
            'behavior_evidence'  => ['green_gate_run'],
        ]));

        $this->assertSame(AtlasExternalBrainValueProofSampler::CLASS_REAL_CAPABILITY, $result['value_class']);
        $this->assertSame(AtlasExternalBrainValueProofSampler::CONFIDENCE_HIGH, $result['confidence']);
    }

    // ── AC2: commit count / file count alone NOT sufficient ──────────────────

    public function test_not_real_capability_when_integration_missing_even_with_many_files(): void
    {
        $result = $this->sampler->sample($this->record([
            'integration_status' => false,
            'downstream_usage'   => [],
            'tests'              => ['tests/Unit/AtlasFooTest.php'],
            'changed_files'      => array_map(fn ($i) => "app/Services/Svc{$i}.php", range(1, 20)),
        ]));

        $this->assertNotSame(AtlasExternalBrainValueProofSampler::CLASS_REAL_CAPABILITY, $result['value_class'],
            'Many files without integration evidence must not be classified as real_capability');
    }

    public function test_not_real_capability_with_integration_but_no_behavior_proof(): void
    {
        $result = $this->sampler->sample($this->record([
            'integration_status' => true,
            'tests'              => [],
            'behavior_evidence'  => [],
        ]));

        $this->assertNotSame(AtlasExternalBrainValueProofSampler::CLASS_REAL_CAPABILITY, $result['value_class']);
    }

    // ── observability classification ─────────────────────────────────────────

    public function test_observability_when_all_impl_files_are_logging_or_monitoring(): void
    {
        $result = $this->sampler->sample($this->record([
            'integration_status' => false,
            'changed_files'      => ['app/Services/EventLogger.php', 'tests/Unit/EventLoggerTest.php'],
            'tests'              => ['tests/Unit/EventLoggerTest.php'],
        ]));

        $this->assertSame(AtlasExternalBrainValueProofSampler::CLASS_OBSERVABILITY, $result['value_class']);
    }

    // ── consolidation classification ─────────────────────────────────────────

    public function test_consolidation_when_all_impl_files_are_dedup_or_merge(): void
    {
        $result = $this->sampler->sample($this->record([
            'integration_status' => false,
            'changed_files'      => ['app/Services/DeduplicateService.php', 'tests/Unit/DeduplicateServiceTest.php'],
            'tests'              => ['tests/Unit/DeduplicateServiceTest.php'],
        ]));

        $this->assertSame(AtlasExternalBrainValueProofSampler::CLASS_CONSOLIDATION, $result['value_class']);
    }

    // ── scaffolding classification ───────────────────────────────────────────

    public function test_scaffolding_when_impl_files_are_stubs_or_placeholders(): void
    {
        $result = $this->sampler->sample($this->record([
            'integration_status' => false,
            'changed_files'      => ['app/Services/StubProvider.php'],
            'tests'              => [],
        ]));

        $this->assertSame(AtlasExternalBrainValueProofSampler::CLASS_SCAFFOLDING, $result['value_class']);
        $this->assertSame(AtlasExternalBrainValueProofSampler::CONFIDENCE_LOW, $result['confidence']);
    }

    // ── unknown ──────────────────────────────────────────────────────────────

    public function test_unknown_when_no_evidence_at_all(): void
    {
        $result = $this->sampler->sample($this->record([
            'integration_status' => false,
            'downstream_usage'   => [],
            'tests'              => [],
            'behavior_evidence'  => [],
            'changed_files'      => [],
        ]));

        $this->assertSame(AtlasExternalBrainValueProofSampler::CLASS_UNKNOWN, $result['value_class']);
    }

    // ── batch ────────────────────────────────────────────────────────────────

    public function test_sample_batch_returns_schema_samples_and_count(): void
    {
        $result = $this->sampler->sampleBatch([
            $this->record(['task_packet_id' => 'a']),
            $this->record(['task_packet_id' => 'b', 'integration_status' => true, 'tests' => ['t.php']]),
        ]);

        $this->assertSame(AtlasExternalBrainValueProofSampler::SCHEMA, $result['schema']);
        $this->assertSame(2, $result['count']);
        $this->assertCount(2, $result['samples']);
    }
}
