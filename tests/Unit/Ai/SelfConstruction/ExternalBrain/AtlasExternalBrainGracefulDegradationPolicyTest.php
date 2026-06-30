<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainGracefulDegradationPolicy;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainGracefulDegradationPolicyTest extends TestCase
{
    private AtlasExternalBrainGracefulDegradationPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new AtlasExternalBrainGracefulDegradationPolicy;
    }

    // ── Schema / AC4 required keys ────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->policy->apply(['available_tiers' => ['frontier_model']]);

        foreach (['schema', 'mode', 'ambition_cap', 'required_scaffold_strictness', 'forbidden_task_classes', 'fallback_batch_constraints'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainGracefulDegradationPolicy::SCHEMA, $result['schema']);
    }

    // ── Mode resolution ───────────────────────────────────────────────────────

    public function test_frontier_available_yields_full_mode(): void
    {
        $result = $this->policy->apply(['available_tiers' => ['frontier_model', 'scaffolded_small_model']]);

        $this->assertSame(AtlasExternalBrainGracefulDegradationPolicy::MODE_FULL, $result['mode']);
    }

    public function test_frontier_available_flag_true_yields_full_mode(): void
    {
        $result = $this->policy->apply(['frontier_available' => true]);

        $this->assertSame(AtlasExternalBrainGracefulDegradationPolicy::MODE_FULL, $result['mode']);
    }

    public function test_no_frontier_with_scaffolded_yields_degraded_mode(): void
    {
        $result = $this->policy->apply(['available_tiers' => ['scaffolded_small_model', 'small_model']]);

        $this->assertSame(AtlasExternalBrainGracefulDegradationPolicy::MODE_DEGRADED, $result['mode']);
    }

    public function test_no_frontier_no_scaffolded_yields_minimal_mode(): void
    {
        $result = $this->policy->apply(['available_tiers' => ['small_model']]);

        $this->assertSame(AtlasExternalBrainGracefulDegradationPolicy::MODE_MINIMAL, $result['mode']);
    }

    public function test_frontier_available_flag_false_with_scaffolded_yields_degraded(): void
    {
        $result = $this->policy->apply([
            'frontier_available' => false,
            'available_tiers'    => ['scaffolded_small_model'],
        ]);

        $this->assertSame(AtlasExternalBrainGracefulDegradationPolicy::MODE_DEGRADED, $result['mode']);
    }

    // ── AC2: ambition_cap reduces when tiers shrink ───────────────────────────

    public function test_full_mode_has_maximum_ambition_cap(): void
    {
        $result = $this->policy->apply(['available_tiers' => ['frontier_model']]);

        $this->assertSame(1.0, $result['ambition_cap']);
    }

    public function test_degraded_mode_reduces_ambition_cap(): void
    {
        $result = $this->policy->apply(['available_tiers' => ['scaffolded_small_model']]);

        $this->assertLessThan(1.0, $result['ambition_cap']);
        $this->assertGreaterThan(0.0, $result['ambition_cap']);
    }

    public function test_minimal_mode_has_lowest_ambition_cap(): void
    {
        $degraded = $this->policy->apply(['available_tiers' => ['scaffolded_small_model']]);
        $minimal  = $this->policy->apply(['available_tiers' => ['small_model']]);

        $this->assertLessThan($degraded['ambition_cap'], $minimal['ambition_cap']);
    }

    // ── AC2: scaffold strictness ──────────────────────────────────────────────

    public function test_full_mode_is_standard_strictness(): void
    {
        $result = $this->policy->apply(['available_tiers' => ['frontier_model']]);

        $this->assertSame(AtlasExternalBrainGracefulDegradationPolicy::STRICTNESS_STANDARD, $result['required_scaffold_strictness']);
    }

    public function test_degraded_mode_is_strict(): void
    {
        $result = $this->policy->apply(['available_tiers' => ['scaffolded_small_model']]);

        $this->assertSame(AtlasExternalBrainGracefulDegradationPolicy::STRICTNESS_STRICT, $result['required_scaffold_strictness']);
    }

    public function test_minimal_mode_is_maximum_strictness(): void
    {
        $result = $this->policy->apply(['available_tiers' => ['small_model']]);

        $this->assertSame(AtlasExternalBrainGracefulDegradationPolicy::STRICTNESS_MAXIMUM, $result['required_scaffold_strictness']);
    }

    // ── AC2: forbidden task classes ───────────────────────────────────────────

    public function test_full_mode_forbids_no_classes(): void
    {
        $result = $this->policy->apply(['available_tiers' => ['frontier_model']]);

        $this->assertSame([], $result['forbidden_task_classes']);
    }

    public function test_degraded_mode_forbids_frontier_only_classes(): void
    {
        $result = $this->policy->apply(['available_tiers' => ['scaffolded_small_model']]);

        $this->assertContains('architecture_tradeoff', $result['forbidden_task_classes']);
        $this->assertContains('novel_research', $result['forbidden_task_classes']);
    }

    public function test_minimal_mode_forbids_more_classes_than_degraded(): void
    {
        $degraded = $this->policy->apply(['available_tiers' => ['scaffolded_small_model']]);
        $minimal  = $this->policy->apply(['available_tiers' => ['small_model']]);

        $this->assertGreaterThan(count($degraded['forbidden_task_classes']), count($minimal['forbidden_task_classes']));
    }

    public function test_minimal_mode_also_forbids_scaffolded_required_classes(): void
    {
        $result = $this->policy->apply(['available_tiers' => ['small_model']]);

        $this->assertContains('multi_domain_refactor', $result['forbidden_task_classes']);
        $this->assertContains('novel_capability_origination', $result['forbidden_task_classes']);
    }

    // ── AC3: safety constraints in degraded mode ─────────────────────────────

    public function test_degraded_mode_requires_grep_proof(): void
    {
        $result      = $this->policy->apply(['available_tiers' => ['scaffolded_small_model']]);
        $constraints = implode(' ', $result['fallback_batch_constraints']);

        $this->assertStringContainsString('grep_proof', $constraints);
    }

    public function test_degraded_mode_requires_duplicate_check(): void
    {
        $result      = $this->policy->apply(['available_tiers' => ['scaffolded_small_model']]);
        $constraints = implode(' ', $result['fallback_batch_constraints']);

        $this->assertStringContainsString('duplicate_check', $constraints);
    }

    public function test_degraded_mode_requires_runnable_acceptance(): void
    {
        $result      = $this->policy->apply(['available_tiers' => ['scaffolded_small_model']]);
        $constraints = implode(' ', $result['fallback_batch_constraints']);

        $this->assertStringContainsString('runnable_acceptance', $constraints);
    }

    public function test_degraded_mode_requires_critique_quorum(): void
    {
        $result      = $this->policy->apply(['available_tiers' => ['scaffolded_small_model']]);
        $constraints = implode(' ', $result['fallback_batch_constraints']);

        $this->assertStringContainsString('critique_quorum', $constraints);
    }

    public function test_minimal_mode_requires_canonical_doc_read(): void
    {
        $result      = $this->policy->apply(['available_tiers' => ['small_model']]);
        $constraints = implode(' ', $result['fallback_batch_constraints']);

        $this->assertStringContainsString('canonical_doc_read', $constraints);
    }

    // ── AC4: batch size constraint ────────────────────────────────────────────

    public function test_batch_size_is_capped_in_fallback_constraints(): void
    {
        $result      = $this->policy->apply(['available_tiers' => ['scaffolded_small_model'], 'current_batch_size' => 10]);
        $constraints = implode(' ', $result['fallback_batch_constraints']);

        $this->assertStringContainsString('max_batch_size', $constraints);
    }

    public function test_full_mode_has_no_safety_constraints_beyond_batch(): void
    {
        $result = $this->policy->apply(['available_tiers' => ['frontier_model'], 'current_batch_size' => 10]);

        $this->assertCount(1, $result['fallback_batch_constraints']); // only max_batch_size
    }
}
