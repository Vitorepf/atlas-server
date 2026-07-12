<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Models\AiRagFeedbackEvent;
use App\Services\Ai\Context\AtlasAucriRuntimeEnforcementService;
use App\Services\Ai\Context\AtlasContextFeedbackSignalPolicy;
use App\Services\Ai\Context\AtlasContextParetoFrontierRuntimeService;
use App\Services\Ai\Context\AtlasContextQualityCertificationService;
use App\Services\Ai\Context\AtlasRetrievalEvaluationBenchmarkArenaService;
use App\Services\Ai\Context\LocalRagBenchmarkService;
use App\Services\Ai\Context\RealOutcomeCrosscheckReader;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * MAXL-09 — ACQCG cruzado com outcome real (sai do `synthetic_readiness_only`).
 *
 * Acceptance clauses (§1828):
 *   - `atlas:context:quality-certify --json` publishes
 *     `real_outcome_crosscheck.{used_ratio, green_run_pass_rate, n}` with
 *     `basis=measured` when denominators are met.
 *   - `n < min` OR `measured_share = 0` ⇒ `basis=unavailable` (never a
 *     fabricated number).
 *   - Synthetic `quality_score` is byte-identical whether the reader is
 *     bound or not — the crosscheck is a SIDECAR, never fused into the
 *     scalar (lesson of the "92" — MAXL-06 clause).
 */
final class Maxl09RealOutcomeCrosscheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('ai_rag_feedback_events');
        Schema::create('ai_rag_feedback_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 80)->default('atlas.ai.rag.feedback.v1');
            $table->string('retrieval_receipt_id', 120)->nullable();
            $table->string('flow_id', 80);
            $table->string('query_plan_hash', 64)->nullable();
            $table->unsignedInteger('included_sources')->default(0);
            $table->unsignedInteger('used_sources')->default(0);
            $table->unsignedInteger('noise_sources')->default(0);
            $table->json('missed_required_sources')->nullable();
            $table->unsignedTinyInteger('context_sufficiency')->default(0);
            $table->unsignedTinyInteger('post_execution_utility')->default(0);
            $table->json('source_utility')->nullable();
            $table->json('payload')->nullable();
            $table->string('feedback_hash', 64)->nullable();
            $table->timestamps();
        });
    }

    public function test_reader_is_unavailable_when_no_measured_events(): void
    {
        // Isolate from the dev live_outcomes.jsonl on disk (guard ASI-05).
        $reader = new RealOutcomeCrosscheckReader(
            new AtlasContextFeedbackSignalPolicy,
            liveOutcomesPathOverride: '/tmp/does-not-exist-'.uniqid('maxl09_', true).'.jsonl',
        );
        $report = $reader->crossCheck();

        $this->assertSame(RealOutcomeCrosscheckReader::SCHEMA_VERSION, $report['schema_version']);
        $this->assertSame('insufficient_signal', $report['status']);
        $this->assertSame('unavailable', $report['used_ratio']['basis']);
        $this->assertSame('unavailable', $report['green_run_pass_rate']['basis']);
        $this->assertNull($report['used_ratio']['value']);
        $this->assertNull($report['green_run_pass_rate']['value']);
    }

    public function test_measured_arfl_events_over_min_yield_basis_measured(): void
    {
        $this->seedMeasuredArflEvents(count: 12, usedRatio: 0.7);
        $reader = new RealOutcomeCrosscheckReader(new AtlasContextFeedbackSignalPolicy, null);
        $report = $reader->crossCheck(windowDays: 7, minEvents: 10);

        $this->assertSame('ok', $report['status']);
        $this->assertSame('measured', $report['used_ratio']['basis']);
        $this->assertGreaterThanOrEqual(10, $report['used_ratio']['n']);
        $this->assertEqualsWithDelta(0.7, (float) $report['used_ratio']['value'], 0.01);
    }

    public function test_measured_below_min_is_unavailable_never_fabricates_number(): void
    {
        $this->seedMeasuredArflEvents(count: 3, usedRatio: 0.9);
        $reader = new RealOutcomeCrosscheckReader(new AtlasContextFeedbackSignalPolicy, null);
        $report = $reader->crossCheck(windowDays: 7, minEvents: 10);

        $this->assertSame('unavailable', $report['used_ratio']['basis']);
        $this->assertSame('measured_below_min', $report['used_ratio']['reason']);
        $this->assertNull($report['used_ratio']['value']);
        $this->assertSame(3, $report['used_ratio']['n']);
    }

    public function test_live_outcomes_log_absent_marks_green_run_unavailable(): void
    {
        $reader = new RealOutcomeCrosscheckReader(
            new AtlasContextFeedbackSignalPolicy,
            liveOutcomesPathOverride: '/tmp/does-not-exist-'.uniqid('maxl09_', true).'.jsonl',
        );
        $report = $reader->crossCheck();

        $this->assertSame('unavailable', $report['green_run_pass_rate']['basis']);
        $this->assertSame('live_outcomes_log_absent', $report['green_run_pass_rate']['reason']);
    }

    public function test_live_outcomes_log_with_measured_outcomes_yields_pass_rate(): void
    {
        $tmp = sys_get_temp_dir().'/maxl09_'.uniqid('', true).'.jsonl';
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $lines = [];
        for ($i = 0; $i < 12; $i++) {
            $lines[] = json_encode([
                'recorded_at' => $now->format(DateTimeInterface::ATOM),
                'verified_basis' => 'gates_passed',
                'proven_real' => $i < 8,
            ], JSON_THROW_ON_ERROR);
        }
        file_put_contents($tmp, implode(PHP_EOL, $lines).PHP_EOL);
        try {
            $reader = new RealOutcomeCrosscheckReader(
                new AtlasContextFeedbackSignalPolicy,
                liveOutcomesPathOverride: $tmp,
            );
            $report = $reader->crossCheck(windowDays: 7, minEvents: 10);
            $this->assertSame('ok', $report['status']);
            $this->assertSame('measured', $report['green_run_pass_rate']['basis']);
            $this->assertSame(12, $report['green_run_pass_rate']['n']);
            $this->assertSame(8, $report['green_run_pass_rate']['proven_real_count']);
            $this->assertEqualsWithDelta(8 / 12, (float) $report['green_run_pass_rate']['value'], 0.001);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_service_payload_carries_the_crosscheck_block(): void
    {
        $service = new AtlasContextQualityCertificationService(
            app(AtlasAucriRuntimeEnforcementService::class),
            app(AtlasRetrievalEvaluationBenchmarkArenaService::class),
            app(AtlasContextParetoFrontierRuntimeService::class),
            app(LocalRagBenchmarkService::class),
            new RealOutcomeCrosscheckReader(new AtlasContextFeedbackSignalPolicy, null),
        );
        $payload = $service->certify();
        $this->assertArrayHasKey('real_outcome_crosscheck', $payload);
        $this->assertSame(
            RealOutcomeCrosscheckReader::SCHEMA_VERSION,
            $payload['real_outcome_crosscheck']['schema_version'],
        );
        $this->assertFalse($payload['real_outcome_crosscheck']['source']['fuses_to_scalar']);
        $this->assertTrue($payload['real_outcome_crosscheck']['source']['read_only']);
        $this->assertArrayHasKey('used_ratio', $payload['real_outcome_crosscheck']);
        $this->assertArrayHasKey('green_run_pass_rate', $payload['real_outcome_crosscheck']);
    }

    public function test_quality_score_is_byte_identical_whether_reader_is_bound_or_not(): void
    {
        $withoutReader = new AtlasContextQualityCertificationService(
            app(AtlasAucriRuntimeEnforcementService::class),
            app(AtlasRetrievalEvaluationBenchmarkArenaService::class),
            app(AtlasContextParetoFrontierRuntimeService::class),
            app(LocalRagBenchmarkService::class),
            null,
        );
        $withReader = new AtlasContextQualityCertificationService(
            app(AtlasAucriRuntimeEnforcementService::class),
            app(AtlasRetrievalEvaluationBenchmarkArenaService::class),
            app(AtlasContextParetoFrontierRuntimeService::class),
            app(LocalRagBenchmarkService::class),
            new RealOutcomeCrosscheckReader(new AtlasContextFeedbackSignalPolicy, null),
        );
        $a = $withoutReader->certify();
        $b = $withReader->certify();
        // The synthetic score itself is the invariant — the sidecar never
        // moves the scalar (MAXL-06 anti-fusion clause).
        $this->assertSame($a['quality_score'], $b['quality_score']);
        $this->assertSame($a['status'], $b['status']);
        $this->assertSame($a['score_basis'], $b['score_basis']);
    }

    private function seedMeasuredArflEvents(int $count, float $usedRatio): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $iso = $now->format(DateTimeInterface::ATOM);
        for ($i = 0; $i < $count; $i++) {
            AiRagFeedbackEvent::query()->create([
                'id' => sprintf('%08s-%04s-%04s-%04s-%012s', dechex($i + 1), 'aaaa', 'bbbb', 'cccc', 'dddddddddddd'),
                'flow_id' => 'maxl09-test',
                'query_plan_hash' => hash('sha256', 'q-'.$i),
                'feedback_hash' => hash('sha256', 'fb-'.$i),
                'included_sources' => 1,
                'used_sources' => 1,
                'noise_sources' => 0,
                'context_sufficiency' => 1,
                'post_execution_utility' => 5,
                'payload' => [
                    'measured' => true,
                    'context_roi' => ['used_ratio' => $usedRatio, 'post_execution_utility' => 0.5],
                ],
                'created_at' => $iso,
                'updated_at' => $iso,
            ]);
        }
    }
}
