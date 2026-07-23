<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAdaptiveCompressionQueue;
use Tests\TestCase;

final class AtlasExternalBrainAdaptiveCompressionQueueTest extends TestCase
{
    private function queue(): AtlasExternalBrainAdaptiveCompressionQueue
    {
        return new AtlasExternalBrainAdaptiveCompressionQueue;
    }

    public function test_schema_present(): void
    {
        $r = $this->queue()->shape([]);
        $this->assertSame(AtlasExternalBrainAdaptiveCompressionQueue::SCHEMA, $r['schema']);
    }

    // ── AC: high-value + distinct candidates add compression regardless of depth ──

    public function test_high_value_add_case(): void
    {
        $r = $this->queue()->shape([
            'servable_depth' => 5,
            'proof_debt' => 0,
            'risk_level' => 'low',
            'high_value_candidates_available' => true,
            'distinct_candidates_available' => true,
        ]);

        $this->assertSame(AtlasExternalBrainAdaptiveCompressionQueue::ACTION_ADD_COMPRESSION, $r['action']);
        $this->assertNotEmpty($r['reasons']);
    }

    public function test_no_depth_only_wait_case(): void
    {
        // Servable depth is enormous, but high-value distinct work exists — must NOT pause/diversify.
        $r = $this->queue()->shape([
            'servable_depth' => 500,
            'proof_debt' => 0,
            'risk_level' => 'low',
            'high_value_candidates_available' => true,
            'distinct_candidates_available' => true,
        ]);

        $this->assertSame(AtlasExternalBrainAdaptiveCompressionQueue::ACTION_ADD_COMPRESSION, $r['action']);
        $this->assertNotSame(AtlasExternalBrainAdaptiveCompressionQueue::ACTION_PAUSE, $r['action']);
    }

    // ── AC: risk / repeated failure triggers repair ───────────────────────────

    public function test_risk_repair_case(): void
    {
        $r = $this->queue()->shape([
            'servable_depth' => 5,
            'risk_level' => 'high',
            'high_value_candidates_available' => true,
            'distinct_candidates_available' => true,
        ]);

        $this->assertSame(AtlasExternalBrainAdaptiveCompressionQueue::ACTION_REPAIR, $r['action']);
    }

    public function test_repeated_similar_failures_triggers_repair_even_at_low_risk(): void
    {
        $r = $this->queue()->shape([
            'risk_level' => 'low',
            'worker_outcomes' => ['repeated_similar_failures' => true],
            'high_value_candidates_available' => true,
            'distinct_candidates_available' => true,
        ]);

        $this->assertSame(AtlasExternalBrainAdaptiveCompressionQueue::ACTION_REPAIR, $r['action']);
    }

    public function test_repair_takes_priority_over_proof_debt(): void
    {
        $r = $this->queue()->shape([
            'risk_level' => 'high',
            'proof_debt' => 10,
        ]);

        $this->assertSame(AtlasExternalBrainAdaptiveCompressionQueue::ACTION_REPAIR, $r['action']);
    }

    // ── proof debt triggers add_proof ─────────────────────────────────────────

    public function test_high_proof_debt_triggers_add_proof(): void
    {
        $r = $this->queue()->shape([
            'risk_level' => 'low',
            'proof_debt' => 5,
            'high_value_candidates_available' => true,
            'distinct_candidates_available' => true,
        ]);

        $this->assertSame(AtlasExternalBrainAdaptiveCompressionQueue::ACTION_ADD_PROOF, $r['action']);
    }

    public function test_low_proof_debt_does_not_trigger_add_proof(): void
    {
        $r = $this->queue()->shape([
            'risk_level' => 'low',
            'proof_debt' => 1,
            'high_value_candidates_available' => true,
            'distinct_candidates_available' => true,
        ]);

        $this->assertNotSame(AtlasExternalBrainAdaptiveCompressionQueue::ACTION_ADD_PROOF, $r['action']);
    }

    // ── deep, samey queue diversifies rather than pausing ─────────────────────

    public function test_deep_queue_without_distinct_high_value_work_diversifies(): void
    {
        $r = $this->queue()->shape([
            'servable_depth' => 50,
            'proof_debt' => 0,
            'risk_level' => 'low',
            'high_value_candidates_available' => false,
            'distinct_candidates_available' => false,
        ]);

        $this->assertSame(AtlasExternalBrainAdaptiveCompressionQueue::ACTION_DIVERSIFY, $r['action']);
    }

    public function test_high_value_but_not_distinct_does_not_add_compression(): void
    {
        // Duplicative "high value" work should not be blindly added — falls through to diversify/pause.
        $r = $this->queue()->shape([
            'servable_depth' => 50,
            'high_value_candidates_available' => true,
            'distinct_candidates_available' => false,
        ]);

        $this->assertNotSame(AtlasExternalBrainAdaptiveCompressionQueue::ACTION_ADD_COMPRESSION, $r['action']);
    }

    // ── genuinely nothing to do pauses ────────────────────────────────────────

    public function test_shallow_queue_no_debt_no_risk_no_candidates_pauses(): void
    {
        $r = $this->queue()->shape([
            'servable_depth' => 2,
            'proof_debt' => 0,
            'risk_level' => 'low',
            'high_value_candidates_available' => false,
            'distinct_candidates_available' => false,
        ]);

        $this->assertSame(AtlasExternalBrainAdaptiveCompressionQueue::ACTION_PAUSE, $r['action']);
    }

    public function test_empty_input_pauses_as_safe_default(): void
    {
        $r = $this->queue()->shape([]);
        $this->assertSame(AtlasExternalBrainAdaptiveCompressionQueue::ACTION_PAUSE, $r['action']);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_shape_is_deterministic(): void
    {
        $facts = ['servable_depth' => 10, 'proof_debt' => 1, 'risk_level' => 'medium'];

        $this->assertSame(
            json_encode($this->queue()->shape($facts)),
            json_encode($this->queue()->shape($facts)),
        );
    }
}
