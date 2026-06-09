<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\AtlasMemoryRegistryService;
use App\Services\Ai\AtlasVerbatimMemoryService;
use App\Services\Ai\Context\AtlasContextQualityCertificationService;
use App\Services\Ai\Context\LocalRagBenchmarkService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ContextQualityCertificationTest extends TestCase
{
    /**
     * R2 anti-over-claim: with NO real retrieval-answer-quality measurement
     * available (no provider-safe memory recall corpus), the service must emit
     * NO numeric score and honestly label itself `synthetic_readiness_only`,
     * instead of fabricating a 9.8-9.95.
     */
    public function test_emits_no_numeric_score_when_no_real_measurement_is_available(): void
    {
        Schema::dropIfExists('atlas_memory_entries');
        Schema::dropIfExists('atlas_verbatim_memories');
        Schema::dropIfExists('semantic_notes');
        Schema::dropIfExists('ai_attachment_index_entries');

        $payload = app(AtlasContextQualityCertificationService::class)->certify();

        $this->assertSame(AtlasContextQualityCertificationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('synthetic_readiness_only', $payload['status']);
        $this->assertNull($payload['quality_score']);
        $this->assertFalse(data_get($payload, 'real_measurement.available'));
        $this->assertFalse(data_get($payload, 'summary.real_measurement_available'));
        $this->assertSame('synthetic_readiness_only_no_real_measurement', $payload['score_basis']);
        $this->assertTrue(data_get($payload, 'claim_policy.synthetic_readiness_only'));
        $this->assertContains('no_real_measurement', array_column($payload['blockers'], 'id'));
        $this->assertContains('quality_score_unmeasured', array_column($payload['blockers'], 'id'));
    }

    /**
     * Happy path with a REAL measured corpus: the numeric score is derived from
     * the real Local RAG benchmark (router precision + real pgvector recall),
     * not hardcoded.
     */
    public function test_score_is_derived_from_real_local_rag_benchmark_when_measured(): void
    {
        $this->seedRealMeasuredSubstrate();

        $payload = app(AtlasContextQualityCertificationService::class)->certify();

        $this->assertSame('ready', $payload['status']);
        $this->assertTrue(data_get($payload, 'real_measurement.available'));
        $this->assertSame('real_local_rag_benchmark_measurement', $payload['score_basis']);
        $this->assertSame(LocalRagBenchmarkService::SCHEMA_VERSION, data_get($payload, 'real_measurement.source'));
        $this->assertIsFloat($payload['quality_score']);
        $this->assertGreaterThanOrEqual(9.8, $payload['quality_score']);
        $this->assertLessThanOrEqual(10.0, $payload['quality_score']);
        // Real signals are present and non-fabricated.
        $this->assertEquals(1.0, data_get($payload, 'real_measurement.precision_at_3'));
        $this->assertSame(0, data_get($payload, 'real_measurement.provider_safe_violation_count'));
        $this->assertSame([], $payload['blockers']);
    }

    /**
     * HARD CONSTRAINT: a real retrieval failure MUST be able to lower/fail the
     * score. The same code path that produced a perfect score above must, given
     * a genuinely failing Local RAG benchmark report (real provider-safe
     * violation + precision drop — the shape the real harness emits on
     * regression), produce a LOWER score and a `blocked` status.
     */
    public function test_real_retrieval_failure_lowers_and_blocks_the_score(): void
    {
        $clean = $this->measure($this->benchmarkReport(
            precisionAt3: 1.0,
            precisionAt5: 1.0,
            providerSafeViolations: 0,
        ));

        $regressed = $this->measure($this->benchmarkReport(
            precisionAt3: 0.4,
            precisionAt5: 0.4,
            providerSafeViolations: 1,
            contamination: 1,
        ));

        $this->assertIsFloat($clean['quality_score']);
        $this->assertIsFloat($regressed['quality_score']);

        // The real regression provably LOWERS the measured score...
        $this->assertLessThan($clean['quality_score'], $regressed['quality_score']);
        // ...past the target, flipping the gate to blocked.
        $this->assertSame('ready', $clean['status']);
        $this->assertSame('blocked', $regressed['status']);
        $this->assertLessThan(9.8, $regressed['quality_score']);
        $this->assertContains('provider_safe_violation', array_column($regressed['blockers'], 'id'));
        $this->assertContains('quality_score_below_target', array_column($regressed['blockers'], 'id'));
    }

    public function test_synthetic_corpus_covers_long_horizon_adversarial_and_outcome_dimensions(): void
    {
        $payload = app(AtlasContextQualityCertificationService::class)->certify(['cases' => 1500]);

        $this->assertSame(1500, data_get($payload, 'synthetic_long_horizon_corpus.case_count'));
        $this->assertSame('declared_readiness_only', data_get($payload, 'synthetic_long_horizon_corpus.status'));
        $this->assertFalse(data_get($payload, 'synthetic_long_horizon_corpus.is_real_measurement'));

        foreach (['commits', 'adrs', 'recurring_bugs', 'broken_tests', 'milestones', 'handoffs', 'architecture_changes', 'stale_docs'] as $artifact) {
            $this->assertContains($artifact, data_get($payload, 'synthetic_long_horizon_corpus.dimensions.artifact'));
        }

        foreach (['wrong_similar_name', 'false_memory', 'old_comment', 'dead_file', 'irrelevant_test', 'incomplete_prompt', 'ambiguous_instruction', 'dangerous_instruction'] as $category) {
            $this->assertContains($category, data_get($payload, 'adversarial_context_evaluation.categories'));
        }

        foreach (['patch_passed', 'patch_failed', 'test_broke', 'context_used', 'context_ignored', 'human_correction', 'provider_hit', 'provider_miss', 'file_regressed', 'retrieval_noise'] as $event) {
            $this->assertContains($event, data_get($payload, 'aemor_synthetic_feed.outcome_event_types'));
        }
    }

    public function test_certification_executes_all_18_aucri_blocks_and_preserves_claim_policy(): void
    {
        $payload = app(AtlasContextQualityCertificationService::class)->certify();

        $this->assertSame(18, data_get($payload, 'aucri_runtime_enforcement.block_ref_summary.total'));
        $this->assertSame(18, data_get($payload, 'aucri_runtime_enforcement.block_ref_summary.executed'));
        $this->assertTrue(data_get($payload, 'aucri_runtime_enforcement.block_ref_summary.all_18_blocks_executed'));

        $acronyms = data_get($payload, 'aucri_runtime_enforcement.block_ref_summary.acronyms');
        foreach (['ASEF', 'AHRI', 'AARF', 'ACRS', 'ACFQ', 'ARFL', 'AGRN', 'AURG', 'APDR', 'AREBA', 'ARCLG', 'ACOP', 'ARPTL', 'AKIF', 'ACMF', 'ACCR', 'ATER', 'ACPFR'] as $acronym) {
            $this->assertContains($acronym, $acronyms);
        }

        $this->assertTrue(data_get($payload, 'claim_policy.score_from_real_measurement_only'));
        $this->assertTrue(data_get($payload, 'claim_policy.no_hardcoded_score_literals'));
        $this->assertTrue(data_get($payload, 'claim_policy.no_score_cap'));
        $this->assertTrue(data_get($payload, 'claim_policy.no_real_metric_floor'));
        $this->assertTrue(data_get($payload, 'claim_policy.real_failure_can_lower_score'));
        $this->assertFalse(data_get($payload, 'claim_policy.providers_invoked'));
        $this->assertFalse(data_get($payload, 'claim_policy.rivals_run'));
        $this->assertFalse(data_get($payload, 'claim_policy.external_benchmark_run'));
        $this->assertFalse(data_get($payload, 'claim_policy.external_superiority_claim'));
        $this->assertFalse(data_get($payload, 'claim_policy.writes'));
        $this->assertFalse(data_get($payload, 'claim_policy.raw_text_exposed'));
        $this->assertFalse($payload['writes']);
    }

    public function test_metrics_are_real_signals_only_with_no_hardcoded_literals(): void
    {
        $this->seedRealMeasuredSubstrate();

        $metrics = app(AtlasContextQualityCertificationService::class)->certify()['metrics'];

        $this->assertSame(1.0, $metrics['real_measurement_available']);
        // Every metric is a real measured signal from the Local RAG harness.
        $this->assertArrayHasKey('router_governance_precision', $metrics);
        $this->assertArrayHasKey('retrieval_precision_at_3', $metrics);
        $this->assertArrayHasKey('retrieval_precision_at_5', $metrics);
        $this->assertEquals(1.0, $metrics['retrieval_precision_at_3']);
        $this->assertSame(0.0, $metrics['provider_safe_violation_count']);

        // The fabricated literals are gone.
        $this->assertArrayNotHasKey('irrelevant_context_ratio', $metrics);
        $this->assertArrayNotHasKey('token_savings', $metrics);
        $this->assertArrayNotHasKey('hallucination_risk_score', $metrics);
        $this->assertArrayNotHasKey('adversarial_detection_rate', $metrics);
    }

    public function test_hash_is_deterministic_and_payload_does_not_expose_raw_context(): void
    {
        $service = app(AtlasContextQualityCertificationService::class);
        $first = $service->certify();
        $second = $service->certify();

        $this->assertSame($first['certification_hash'], $second['certification_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first['certification_hash']);
        $this->assertStringNotContainsString('synthetic-context-quality-certification', json_encode($first, JSON_THROW_ON_ERROR));
    }

    public function test_source_does_not_contain_fabricated_score_literals_cap_or_floors(): void
    {
        $source = (string) file_get_contents(app_path('Services/Ai/Context/AtlasContextQualityCertificationService.php'));

        // No fabricated metric literals.
        $this->assertStringNotContainsString('0.986', $source);
        $this->assertStringNotContainsString('0.992', $source);
        $this->assertStringNotContainsString('0.984', $source);
        $this->assertStringNotContainsString('0.031', $source);
        $this->assertStringNotContainsString('0.014', $source);
        // No score cap.
        $this->assertStringNotContainsString('min(9.95', $source);
        // No real-metric floors.
        $this->assertStringNotContainsString('max(0.992', $source);
        $this->assertStringNotContainsString('max(0.94', $source);

        // The source-inspection contract consumed by AtlasAiProductCertificationService is preserved.
        $this->assertStringContainsString('atlas.context.quality_certification.v1', $source);
        $this->assertStringContainsString('aucri_runtime_enforcement', $source);
        $this->assertStringContainsString('aucri_blocks_executed', $source);
        $this->assertStringContainsString('target_score', $source);
    }

    public function test_command_emits_json_and_is_blocked_without_real_measurement(): void
    {
        Schema::dropIfExists('atlas_memory_entries');
        Schema::dropIfExists('atlas_verbatim_memories');

        $exit = Artisan::call('atlas:context:quality-certify', [
            '--json' => true,
            '--strict' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        // Strict + no real measurement honestly fails (no fabricated pass).
        $this->assertSame(1, $exit);
        $this->assertSame(AtlasContextQualityCertificationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('synthetic_readiness_only', $payload['status']);
        $this->assertNull($payload['quality_score']);
    }

    public function test_command_passes_strict_when_real_measurement_certifies(): void
    {
        $this->seedRealMeasuredSubstrate();

        $exit = Artisan::call('atlas:context:quality-certify', [
            '--json' => true,
            '--strict' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ready', $payload['status']);
        $this->assertGreaterThanOrEqual(9.8, $payload['quality_score']);
    }

    /**
     * With the artificial 9.95 cap removed, a perfect REAL measurement now
     * genuinely reaches 10.0 — target=10 is no longer impossible-by-fudge. This
     * is the inverse proof that the cap is gone: the score is honest, not capped.
     */
    public function test_perfect_real_measurement_reaches_full_score_with_no_artificial_cap(): void
    {
        $this->seedRealMeasuredSubstrate();

        $exit = Artisan::call('atlas:context:quality-certify', [
            '--target' => 10,
            '--json' => true,
            '--strict' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ready', $payload['status']);
        // JSON round-trip coerces 10.0 -> 10; assert the value, not the PHP type.
        $this->assertEquals(10.0, $payload['quality_score']);
    }

    /**
     * A below-target score (without any real failure, just a higher bar than the
     * measured value) honestly blocks. Proven via the swap so the measured value
     * is deterministic and strictly between the default floor and the bar.
     */
    public function test_strict_command_blocks_when_real_score_is_below_target(): void
    {
        // A real-shaped report with imperfect (but non-failing) precision yields
        // a measured score below a 9.8 target → honest block, no fudge.
        $payload = $this->measure($this->benchmarkReport(
            precisionAt3: 0.85,
            precisionAt5: 0.80,
            providerSafeViolations: 0,
        ));

        $this->assertTrue(data_get($payload, 'real_measurement.available'));
        $this->assertIsFloat($payload['quality_score']);
        $this->assertLessThan(9.8, $payload['quality_score']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('quality_score_below_target', array_column($payload['blockers'], 'id'));
    }

    /**
     * Run the certification with the Local RAG benchmark boundary swapped for a
     * deterministic real-shaped report, so a specific real signal can be
     * exercised end-to-end through the score derivation.
     *
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function measure(array $report): array
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

    /**
     * The real-harness output contract shape (mirrors LocalRagBenchmarkService::report()).
     *
     * @return array<string,mixed>
     */
    private function benchmarkReport(
        float $precisionAt3,
        float $precisionAt5,
        int $providerSafeViolations,
        int $contamination = 0,
    ): array {
        return [
            'schema_version' => LocalRagBenchmarkService::SCHEMA_VERSION,
            'status' => 'passed',
            'readiness_status' => 'ready',
            'case_count' => 4,
            'passed_case_count' => 4,
            'average_score' => 1.0,
            'quality_corpus' => [
                'status' => 'passed',
                'metrics' => ['min_score' => 1.0, 'average_score' => 1.0],
            ],
            'memory_recall_corpus' => [
                'status' => 'passed',
                'case_count' => 2,
                'metrics' => [
                    'precision_at_3' => $precisionAt3,
                    'precision_at_5' => $precisionAt5,
                    'missed_critical_context_count' => 0,
                    'context_contamination_count' => $contamination,
                    'provider_safe_violation_count' => $providerSafeViolations,
                    'stale_context_use_count' => 0,
                ],
            ],
        ];
    }

    /**
     * Seed the real Local RAG substrate + a promoted, provider-safe memory
     * corpus so LocalRagBenchmarkService measures real pgvector precision@k.
     * Mirrors the proven setup in AtlasAiLocalRagBenchmarkCommandTest.
     */
    private function seedRealMeasuredSubstrate(): void
    {
        $this->createLocalRagTables();
        $this->createMemoryTables();
        config()->set('atlas.semantic_memory.embedding_provider', 'semantic_rag');

        app(AtlasMemoryRegistryService::class)->record([
            'memory_type' => 'strategic_insight',
            'scope_type' => 'global',
            'title' => 'Capture promoted memory retrieval rule',
            'body' => 'Promoted capture memory must be recalled through the governed hybrid memory path.',
            'summary' => 'Promoted capture memory uses governed hybrid recall.',
            'priority' => 99,
            'importance' => 5,
            'confidence' => 0.94,
            'privacy_class' => 'normal',
            'source_type' => 'ai_memory_delta',
            'source_id' => 'delta-memory-context-cert',
            'metadata' => [
                'promotion_receipt' => ['schema_version' => 'atlas.memory.promotion_receipt.v1'],
            ],
        ]);
        app(AtlasVerbatimMemoryService::class)->record([
            'verbatim_type' => 'evidence',
            'scope_type' => 'global',
            'title' => 'Capture promoted verbatim evidence',
            'verbatim_text' => 'Exact local capture evidence with source text retained locally.',
            'redacted_text' => 'Exact provider-safe capture evidence retained with redaction.',
            'summary' => 'Provider-safe promoted capture evidence.',
            'privacy_class' => 'normal',
            'source_type' => 'capture',
            'source_id' => 'capture-memory-context-cert',
            'metadata' => [
                'promotion_receipt' => ['schema_version' => 'atlas.verbatim_memory.promotion_receipt.v1'],
            ],
            'link_registry' => false,
        ]);
    }

    private function createLocalRagTables(): void
    {
        Schema::dropIfExists('ai_attachment_index_entries');
        Schema::dropIfExists('semantic_notes');

        Schema::create('semantic_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('embedding')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_attachment_index_entries', function (Blueprint $table): void {
            $table->id();
            $table->text('embedding')->nullable();
            $table->timestamps();
        });
    }

    private function createMemoryTables(): void
    {
        Schema::dropIfExists('atlas_verbatim_memories');
        Schema::dropIfExists('atlas_memory_entry_usages');
        Schema::dropIfExists('atlas_memory_entries');

        (require database_path('migrations/2026_05_02_000000_create_atlas_memory_entries_table.php'))->up();
        (require database_path('migrations/2026_05_02_003000_create_atlas_memory_entry_relations_table.php'))->up();
        (require database_path('migrations/2026_05_02_005000_add_privacy_columns_to_atlas_memory_entries.php'))->up();
        (require database_path('migrations/2026_05_02_001000_create_atlas_memory_entry_usages_table.php'))->up();
        (require database_path('migrations/2026_05_02_004000_create_atlas_verbatim_memories_table.php'))->up();
    }
}
