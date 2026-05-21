<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Context\AtlasContextQualityCertificationService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ContextQualityCertificationTest extends TestCase
{
    public function test_context_quality_certification_reaches_9_8_with_all_required_components(): void
    {
        $payload = app(AtlasContextQualityCertificationService::class)->certify();

        $this->assertSame(AtlasContextQualityCertificationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertGreaterThanOrEqual(9.8, $payload['quality_score']);
        $this->assertGreaterThanOrEqual(1000, data_get($payload, 'summary.case_count'));
        $this->assertSame(8, data_get($payload, 'summary.component_count'));
        $this->assertSame(8, data_get($payload, 'summary.components_ready'));
        $this->assertSame([], $payload['blockers']);

        $componentIds = array_column($payload['components'], 'id');

        $this->assertSame([
            'context_stress_lab',
            'synthetic_long_horizon_corpus',
            'massive_replay_harness',
            'golden_context_benchmark',
            'adversarial_context_evaluation',
            'embeddings_graph_readiness',
            'aemor_synthetic_feed',
            'context_quality_certification_gate',
        ], $componentIds);
    }

    public function test_synthetic_corpus_covers_long_horizon_adversarial_and_outcome_dimensions(): void
    {
        $payload = app(AtlasContextQualityCertificationService::class)->certify(['cases' => 1500]);

        $this->assertSame(1500, data_get($payload, 'synthetic_long_horizon_corpus.case_count'));

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

        $this->assertFalse(data_get($payload, 'claim_policy.providers_invoked'));
        $this->assertFalse(data_get($payload, 'claim_policy.rivals_run'));
        $this->assertFalse(data_get($payload, 'claim_policy.external_benchmark_run'));
        $this->assertFalse(data_get($payload, 'claim_policy.external_superiority_claim'));
        $this->assertFalse(data_get($payload, 'claim_policy.writes'));
        $this->assertFalse(data_get($payload, 'claim_policy.raw_text_exposed'));
        $this->assertFalse($payload['writes']);
    }

    public function test_metrics_are_above_quality_thresholds(): void
    {
        $metrics = app(AtlasContextQualityCertificationService::class)->certify()['metrics'];

        $this->assertGreaterThanOrEqual(0.99, $metrics['required_context_recall']);
        $this->assertLessThanOrEqual(0.05, $metrics['irrelevant_context_ratio']);
        $this->assertSame(1.0, $metrics['must_keep_coverage']);
        $this->assertGreaterThanOrEqual(0.65, $metrics['token_savings']);
        $this->assertGreaterThanOrEqual(0.98, $metrics['stale_context_block_rate']);
        $this->assertLessThanOrEqual(0.02, $metrics['hallucination_risk_score']);
        $this->assertGreaterThanOrEqual(0.98, $metrics['adversarial_detection_rate']);
        $this->assertGreaterThanOrEqual(0.98, $metrics['replay_route_accuracy']);
        $this->assertSame(1.0, $metrics['synthetic_outcome_learning_coverage']);
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

    public function test_command_emits_json_and_strict_passes(): void
    {
        $exit = Artisan::call('atlas:context:quality-certify', [
            '--json' => true,
            '--strict' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasContextQualityCertificationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertGreaterThanOrEqual(9.8, $payload['quality_score']);
    }

    public function test_strict_command_fails_when_target_is_impossible(): void
    {
        $exit = Artisan::call('atlas:context:quality-certify', [
            '--target' => 10,
            '--json' => true,
            '--strict' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('quality_score_below_target', array_column($payload['blockers'], 'id'));
    }
}
