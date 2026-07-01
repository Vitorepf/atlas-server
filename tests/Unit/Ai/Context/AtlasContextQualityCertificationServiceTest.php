<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Context;

use App\Services\Ai\Context\AtlasContextQualityCertificationService;
use App\Services\Ai\Context\LocalRagBenchmarkService;
use Tests\TestCase;

final class AtlasContextQualityCertificationServiceTest extends TestCase
{
    /** @param array<string,mixed> $report */
    private function certifyWith(array $report): array
    {
        $this->swap(LocalRagBenchmarkService::class, new class($report) extends LocalRagBenchmarkService
        {
            /** @param array<string,mixed> $report */
            public function __construct(private readonly array $report) {}

            /** @return array<string,mixed> */
            public function report(): array
            {
                return $this->report;
            }
        });

        return app(AtlasContextQualityCertificationService::class)->certify();
    }

    /** @return array<string,mixed> */
    private function unavailableReport(): array
    {
        return [
            'schema_version' => LocalRagBenchmarkService::SCHEMA_VERSION,
            'status' => 'attention',
            'readiness_status' => 'blocked',
            'average_score' => 0.0,
            'quality_corpus' => ['status' => 'attention', 'metrics' => ['min_score' => 0.0]],
            'memory_recall_corpus' => [
                'status' => 'attention',
                'case_count' => 0,
                'missing_reason' => 'no_provider_safe_memory_recall_corpus_measured',
                'metrics' => [],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function availableReport(): array
    {
        return [
            'schema_version' => LocalRagBenchmarkService::SCHEMA_VERSION,
            'status' => 'passed',
            'readiness_status' => 'ready',
            'average_score' => 1.0,
            'quality_corpus' => ['status' => 'passed', 'metrics' => ['min_score' => 1.0, 'average_score' => 1.0]],
            'memory_recall_corpus' => [
                'status' => 'passed',
                'case_count' => 2,
                'metrics' => [
                    'precision_at_3' => 1.0,
                    'precision_at_5' => 1.0,
                    'missed_critical_context_count' => 0,
                    'context_contamination_count' => 0,
                    'provider_safe_violation_count' => 0,
                    'stale_context_use_count' => 0,
                ],
            ],
        ];
    }

    // ── AC2: unavailable real measurement -> quality_score=null, proof.status=unmeasured ──

    public function test_unavailable_measurement_returns_null_score_and_unmeasured_proof(): void
    {
        $payload = $this->certifyWith($this->unavailableReport());

        $this->assertNull($payload['quality_score']);
        $this->assertArrayHasKey('real_measurement_proof', $payload);
        $this->assertSame('unmeasured', $payload['real_measurement_proof']['status']);
        $this->assertNull($payload['real_measurement_proof']['quality_score']);
        $this->assertNotEmpty($payload['real_measurement_proof']['reason']);
    }

    // ── AC3: available real measurement -> numeric quality_score, proof.status=measured ──

    public function test_available_measurement_returns_numeric_score_and_measured_proof(): void
    {
        $payload = $this->certifyWith($this->availableReport());

        $this->assertIsFloat($payload['quality_score']);
        $this->assertSame('measured', $payload['real_measurement_proof']['status']);
        $this->assertSame($payload['quality_score'], $payload['real_measurement_proof']['quality_score']);
        $this->assertSame(LocalRagBenchmarkService::SCHEMA_VERSION, $payload['real_measurement_proof']['source']);
    }

    // ── AC4: provider calls, writes, raw text exposure, external superiority claims all false ──

    public function test_no_provider_calls_writes_raw_text_or_external_superiority_claims(): void
    {
        $payload = $this->certifyWith($this->availableReport());

        $this->assertFalse($payload['claim_policy']['providers_invoked']);
        $this->assertFalse($payload['claim_policy']['rivals_run']);
        $this->assertFalse($payload['claim_policy']['external_benchmark_run']);
        $this->assertFalse($payload['claim_policy']['external_superiority_claim']);
        $this->assertFalse($payload['claim_policy']['writes']);
        $this->assertFalse($payload['claim_policy']['raw_text_exposed']);
        $this->assertFalse($payload['writes']);
    }

    public function test_unmeasured_case_also_keeps_all_execution_flags_false(): void
    {
        $payload = $this->certifyWith($this->unavailableReport());

        $this->assertFalse($payload['claim_policy']['providers_invoked']);
        $this->assertFalse($payload['claim_policy']['writes']);
        $this->assertFalse($payload['claim_policy']['raw_text_exposed']);
        $this->assertFalse($payload['writes']);
    }
}
