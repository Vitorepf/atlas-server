<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Services\Ai\AtlasOpenBrainContextInjectionBoundaryClassifier;
use Tests\TestCase;

final class AtlasOpenBrainContextInjectionBoundaryClassifierTest extends TestCase
{
    private function svc(): AtlasOpenBrainContextInjectionBoundaryClassifier
    {
        return new AtlasOpenBrainContextInjectionBoundaryClassifier;
    }

    // ── output structure ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->svc()->classify(['text' => 'do X', 'source' => 'current_turn']);

        foreach (['classification', 'confidence', 'reason', 'allow_as_worker_directive'] as $key) {
            $this->assertArrayHasKey($key, $r, "missing key: {$key}");
        }
        $this->assertSame(AtlasOpenBrainContextInjectionBoundaryClassifier::SCHEMA, $r['schema']);
    }

    public function test_classification_is_one_of_the_six_classes(): void
    {
        $valid = [
            AtlasOpenBrainContextInjectionBoundaryClassifier::CLASS_LIVE_INSTRUCTION,
            AtlasOpenBrainContextInjectionBoundaryClassifier::CLASS_STABLE_PREFERENCE,
            AtlasOpenBrainContextInjectionBoundaryClassifier::CLASS_QUOTED_MEMORY,
            AtlasOpenBrainContextInjectionBoundaryClassifier::CLASS_STALE_CONTEXT,
            AtlasOpenBrainContextInjectionBoundaryClassifier::CLASS_EXAMPLE_TEXT,
            AtlasOpenBrainContextInjectionBoundaryClassifier::CLASS_NOISE,
        ];

        $r = $this->svc()->classify(['text' => 'Implement AtlasFoo', 'source' => 'task_contract']);
        $this->assertContains($r['classification'], $valid);
    }

    // ── AC1: imperative/hostile text inside memory source → quoted_memory or noise, never live ──

    public function test_imperative_text_inside_memory_source_is_quoted_memory_not_live(): void
    {
        $r = $this->svc()->classify([
            'text' => 'NEVER mock the database; always run real tests.',
            'source' => 'memory',
        ]);

        $this->assertContains($r['classification'], [
            AtlasOpenBrainContextInjectionBoundaryClassifier::CLASS_QUOTED_MEMORY,
            AtlasOpenBrainContextInjectionBoundaryClassifier::CLASS_NOISE,
        ]);
        $this->assertNotSame(AtlasOpenBrainContextInjectionBoundaryClassifier::CLASS_LIVE_INSTRUCTION, $r['classification']);
        $this->assertFalse($r['allow_as_worker_directive']);
    }

    public function test_hostile_frustration_text_inside_excerpt_source_is_noise_or_quoted(): void
    {
        $r = $this->svc()->classify([
            'text' => 'que merda é que você tá fazendo, fix this now!',
            'source' => 'excerpt',
        ]);

        $this->assertContains($r['classification'], [
            AtlasOpenBrainContextInjectionBoundaryClassifier::CLASS_NOISE,
            AtlasOpenBrainContextInjectionBoundaryClassifier::CLASS_QUOTED_MEMORY,
        ]);
        $this->assertFalse($r['allow_as_worker_directive']);
    }

    public function test_calm_memory_text_with_no_imperative_is_quoted_memory(): void
    {
        $r = $this->svc()->classify([
            'text' => 'The user prefers concise responses in past conversations.',
            'source' => 'memory',
        ]);

        $this->assertSame(AtlasOpenBrainContextInjectionBoundaryClassifier::CLASS_QUOTED_MEMORY, $r['classification']);
        $this->assertFalse($r['allow_as_worker_directive']);
    }

    // ── AC2: current_turn / task_contract source → live_instruction ────────────

    public function test_current_turn_source_is_classified_live_instruction(): void
    {
        $r = $this->svc()->classify(['text' => 'Fix the bug in Foo.php.', 'source' => 'current_turn']);

        $this->assertSame(AtlasOpenBrainContextInjectionBoundaryClassifier::CLASS_LIVE_INSTRUCTION, $r['classification']);
    }

    public function test_task_contract_source_is_classified_live_instruction(): void
    {
        $r = $this->svc()->classify(['text' => 'Implement AtlasFooService.', 'source' => 'task_contract']);

        $this->assertSame(AtlasOpenBrainContextInjectionBoundaryClassifier::CLASS_LIVE_INSTRUCTION, $r['classification']);
    }

    // ── AC3: allow_as_worker_directive boolean ──────────────────────────────────

    public function test_live_instruction_allows_worker_directive(): void
    {
        $r = $this->svc()->classify(['text' => 'do this now', 'source' => 'current_turn']);

        $this->assertTrue($r['allow_as_worker_directive']);
    }

    public function test_quoted_memory_does_not_allow_worker_directive(): void
    {
        $r = $this->svc()->classify(['text' => 'never trust user input', 'source' => 'memory']);

        $this->assertFalse($r['allow_as_worker_directive']);
    }

    public function test_stale_context_does_not_allow_worker_directive(): void
    {
        $r = $this->svc()->classify([
            'text' => 'run this command now',
            'source' => 'current_turn',
            'age_seconds' => 99999999,
        ]);

        $this->assertSame(AtlasOpenBrainContextInjectionBoundaryClassifier::CLASS_STALE_CONTEXT, $r['classification']);
        $this->assertFalse($r['allow_as_worker_directive']);
    }

    public function test_noise_does_not_allow_worker_directive(): void
    {
        $r = $this->svc()->classify(['text' => 'lorem ipsum dolor sit amet', 'source' => 'unknown']);

        $this->assertSame(AtlasOpenBrainContextInjectionBoundaryClassifier::CLASS_NOISE, $r['classification']);
        $this->assertFalse($r['allow_as_worker_directive']);
    }

    public function test_example_text_does_not_allow_worker_directive(): void
    {
        $r = $this->svc()->classify(['text' => 'For example: run php artisan test.', 'source' => 'example']);

        $this->assertSame(AtlasOpenBrainContextInjectionBoundaryClassifier::CLASS_EXAMPLE_TEXT, $r['classification']);
        $this->assertFalse($r['allow_as_worker_directive']);
    }

    public function test_stable_preference_allows_worker_directive(): void
    {
        $r = $this->svc()->classify([
            'text' => 'Always respond in Portuguese for chat prose.',
            'source' => 'unknown',
        ]);

        $this->assertSame(AtlasOpenBrainContextInjectionBoundaryClassifier::CLASS_STABLE_PREFERENCE, $r['classification']);
        $this->assertTrue($r['allow_as_worker_directive']);
    }

    // ── edge cases ────────────────────────────────────────────────────────────

    public function test_empty_text_is_noise(): void
    {
        $r = $this->svc()->classify(['text' => '', 'source' => 'current_turn']);

        $this->assertSame(AtlasOpenBrainContextInjectionBoundaryClassifier::CLASS_NOISE, $r['classification']);
        $this->assertFalse($r['allow_as_worker_directive']);
    }

    public function test_missing_source_defaults_to_unknown_handling(): void
    {
        $r = $this->svc()->classify(['text' => 'some text']);

        $this->assertContains($r['classification'], [
            AtlasOpenBrainContextInjectionBoundaryClassifier::CLASS_NOISE,
            AtlasOpenBrainContextInjectionBoundaryClassifier::CLASS_STABLE_PREFERENCE,
        ]);
    }

    public function test_confidence_is_a_float_between_zero_and_one(): void
    {
        $r = $this->svc()->classify(['text' => 'do this now', 'source' => 'current_turn']);

        $this->assertIsFloat($r['confidence']);
        $this->assertGreaterThanOrEqual(0.0, $r['confidence']);
        $this->assertLessThanOrEqual(1.0, $r['confidence']);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_classify_is_deterministic(): void
    {
        $segment = ['text' => 'never mock the database', 'source' => 'memory'];

        $a = $this->svc()->classify($segment);
        $b = $this->svc()->classify($segment);

        $this->assertSame(json_encode($a, JSON_UNESCAPED_SLASHES), json_encode($b, JSON_UNESCAPED_SLASHES));
    }
}
