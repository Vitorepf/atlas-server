<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAutonomyRegressionSentinel;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAutonomyRegressionSentinelTest extends TestCase
{
    private function sentinel(): AtlasExternalBrainAutonomyRegressionSentinel
    {
        return new AtlasExternalBrainAutonomyRegressionSentinel;
    }

    // ── AC2: provider_always / prompt_only / external_tool_only → blocking + fail ─

    public function test_always_requires_external_provider_is_blocking_fail(): void
    {
        $r = $this->sentinel()->scan(['requires_external_provider_always' => true]);

        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::SEVERITY_BLOCKING, $r['severity']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::VERDICT_FAIL, $r['sentinel_verdict']);
        $this->assertContains('provider_dependency', $r['regression_types']);
    }

    public function test_prompt_only_memory_is_blocking_fail(): void
    {
        $r = $this->sentinel()->scan(['prompt_only_memory' => true]);

        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::SEVERITY_BLOCKING, $r['severity']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::VERDICT_FAIL, $r['sentinel_verdict']);
        $this->assertContains('prompt_only_memory', $r['regression_types']);
    }

    public function test_external_tool_only_execution_is_blocking_fail(): void
    {
        $r = $this->sentinel()->scan(['external_tool_only_execution' => true]);

        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::SEVERITY_BLOCKING, $r['severity']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::VERDICT_FAIL, $r['sentinel_verdict']);
        $this->assertContains('external_tool_only', $r['regression_types']);
    }

    public function test_human_approval_required_is_blocking_fail(): void
    {
        $r = $this->sentinel()->scan(['requires_human_approval' => true]);

        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::SEVERITY_BLOCKING, $r['severity']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::VERDICT_FAIL, $r['sentinel_verdict']);
        $this->assertContains('human_dependency', $r['regression_types']);
    }

    public function test_multiple_hard_regressions_all_listed(): void
    {
        $r = $this->sentinel()->scan([
            'requires_external_provider_always' => true,
            'prompt_only_memory'                => true,
            'external_tool_only_execution'      => true,
        ]);

        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::SEVERITY_BLOCKING, $r['severity']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::VERDICT_FAIL, $r['sentinel_verdict']);
        $this->assertCount(3, $r['regression_types']);
    }

    // ── AC3: bootstrap allowed only when atlas_native + evidence_gated ────────

    public function test_bootstrap_allowed_when_atlas_native_and_evidence_gated(): void
    {
        $r = $this->sentinel()->scan([
            'bootstrap_muscle_used' => true,
            'final_runtime_owner'   => 'atlas_native',
            'evidence_gated'        => true,
        ]);

        $this->assertTrue($r['allowed_bootstrap']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::SEVERITY_NONE, $r['severity']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::VERDICT_PASS, $r['sentinel_verdict']);
        $this->assertNotContains('ungated_bootstrap_muscle', $r['regression_types']);
    }

    public function test_bootstrap_blocked_when_not_atlas_native(): void
    {
        $r = $this->sentinel()->scan([
            'bootstrap_muscle_used' => true,
            'final_runtime_owner'   => 'external_provider',
            'evidence_gated'        => true,
        ]);

        $this->assertFalse($r['allowed_bootstrap']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::SEVERITY_BLOCKING, $r['severity']);
        $this->assertContains('ungated_bootstrap_muscle', $r['regression_types']);
    }

    public function test_bootstrap_blocked_when_not_evidence_gated(): void
    {
        $r = $this->sentinel()->scan([
            'bootstrap_muscle_used' => true,
            'final_runtime_owner'   => 'atlas_native',
            'evidence_gated'        => false,
        ]);

        $this->assertFalse($r['allowed_bootstrap']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::SEVERITY_BLOCKING, $r['severity']);
        $this->assertContains('ungated_bootstrap_muscle', $r['regression_types']);
    }

    public function test_bootstrap_blocked_when_neither_condition_met(): void
    {
        $r = $this->sentinel()->scan([
            'bootstrap_muscle_used' => true,
            'final_runtime_owner'   => 'human',
            'evidence_gated'        => false,
        ]);

        $this->assertFalse($r['allowed_bootstrap']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::SEVERITY_BLOCKING, $r['severity']);
    }

    // ── AC4: operator seeding alone → warning, not blocking ───────────────────

    public function test_operator_seeding_alone_is_warning_not_blocking(): void
    {
        $r = $this->sentinel()->scan(['requires_operator_seeding' => true]);

        $this->assertTrue($r['is_regression']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::SEVERITY_WARNING, $r['severity']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::VERDICT_PASS, $r['sentinel_verdict']);
        $this->assertContains('operator_seeding_required', $r['regression_types']);
    }

    public function test_operator_seeding_plus_hard_regression_escalates_to_blocking(): void
    {
        $r = $this->sentinel()->scan([
            'requires_operator_seeding'         => true,
            'requires_external_provider_always' => true,
        ]);

        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::SEVERITY_BLOCKING, $r['severity']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::VERDICT_FAIL, $r['sentinel_verdict']);
    }

    // ── Clean design → pass ───────────────────────────────────────────────────

    public function test_clean_design_passes(): void
    {
        $r = $this->sentinel()->scan([
            'requires_human_approval'           => false,
            'requires_operator_seeding'         => false,
            'requires_external_provider_always' => false,
            'prompt_only_memory'                => false,
            'external_tool_only_execution'      => false,
        ]);

        $this->assertFalse($r['is_regression']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::SEVERITY_NONE, $r['severity']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::VERDICT_PASS, $r['sentinel_verdict']);
    }

    public function test_scan_is_deterministic(): void
    {
        $input = [
            'bootstrap_muscle_used' => true,
            'final_runtime_owner'   => 'atlas_native',
            'evidence_gated'        => true,
            'requires_operator_seeding' => true,
        ];

        $a = $this->sentinel()->scan($input);
        $b = $this->sentinel()->scan($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
