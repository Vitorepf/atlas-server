<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainGracefulDegradationPolicy;
use Tests\TestCase;

final class AtlasExternalBrainGracefulDegradationPolicyTest extends TestCase
{
    private function policy(): AtlasExternalBrainGracefulDegradationPolicy
    {
        return new AtlasExternalBrainGracefulDegradationPolicy;
    }

    public function test_frontier_availability_returns_full_mode(): void
    {
        $r = $this->policy()->apply(['available_tiers' => ['frontier_model']]);

        self::assertSame('full', $r['mode']);
    }

    public function test_scaffolded_small_model_only_returns_degraded_mode(): void
    {
        $r = $this->policy()->apply(['available_tiers' => ['scaffolded_small_model']]);

        self::assertSame('degraded', $r['mode']);
    }

    public function test_only_small_model_returns_minimal_mode(): void
    {
        $r = $this->policy()->apply(['available_tiers' => ['small_model']]);

        self::assertSame('minimal', $r['mode']);
    }

    public function test_no_usable_tier_returns_safe_hold(): void
    {
        $r = $this->policy()->apply(['available_tiers' => []]);

        self::assertSame('safe_hold', $r['mode']);
    }

    public function test_low_confidence_steps_mode_down_never_up(): void
    {
        $r = $this->policy()->apply(['available_tiers' => ['frontier_model'], 'low_confidence' => true]);

        self::assertSame('degraded', $r['mode']);
    }

    public function test_high_ambiguity_steps_mode_down_never_up(): void
    {
        $r = $this->policy()->apply(['available_tiers' => ['scaffolded_small_model'], 'high_ambiguity' => true]);

        self::assertSame('minimal', $r['mode']);
    }

    public function test_low_budget_steps_mode_down_never_up(): void
    {
        $r = $this->policy()->apply(['available_tiers' => ['small_model'], 'low_budget' => true]);

        self::assertSame('safe_hold', $r['mode']);
    }

    public function test_safe_hold_never_steps_further_down(): void
    {
        $r = $this->policy()->apply(['available_tiers' => [], 'low_confidence' => true, 'high_ambiguity' => true, 'low_budget' => true]);

        self::assertSame('safe_hold', $r['mode']);
    }

    public function test_degraded_mode_requires_stricter_checks_smaller_batch_and_lower_ambition(): void
    {
        $full = $this->policy()->apply(['available_tiers' => ['frontier_model']]);
        $degraded = $this->policy()->apply(['available_tiers' => ['scaffolded_small_model']]);

        self::assertLessThan($full['ambition_cap'], $degraded['ambition_cap']);
        self::assertContains('grep_proof', $degraded['required_extra_checks']);
        self::assertContains('duplicate_check', $degraded['required_extra_checks']);
        self::assertContains('runnable_acceptance_criterion', $degraded['required_extra_checks']);
        self::assertNotEmpty($degraded['forbidden_task_classes']);
        self::assertNotEmpty($degraded['fallback_batch_constraints']);
    }

    public function test_minimal_mode_requires_even_stricter_checks_and_lower_ambition_than_degraded(): void
    {
        $degraded = $this->policy()->apply(['available_tiers' => ['scaffolded_small_model']]);
        $minimal = $this->policy()->apply(['available_tiers' => ['small_model']]);

        self::assertLessThan($degraded['ambition_cap'], $minimal['ambition_cap']);
        self::assertContains('canonical_doc_read', $minimal['required_extra_checks']);
        self::assertGreaterThan(
            count($degraded['forbidden_task_classes']),
            count($minimal['forbidden_task_classes']),
        );
    }
}
