<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAutonomyRegressionSentinel;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAutonomyRegressionSentinelTest extends TestCase
{
    private AtlasExternalBrainAutonomyRegressionSentinel $sentinel;

    protected function setUp(): void
    {
        $this->sentinel = new AtlasExternalBrainAutonomyRegressionSentinel;
    }

    private function clean(): array
    {
        return [
            'requires_human_approval'          => false,
            'requires_operator_seeding'         => false,
            'requires_external_provider_always' => false,
            'prompt_only_memory'                => false,
            'external_tool_only_execution'      => false,
            'bootstrap_muscle_used'             => false,
            'final_runtime_owner'               => 'atlas_native',
            'evidence_gated'                    => true,
        ];
    }

    // ── Schema / required keys ────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->sentinel->scan($this->clean());

        foreach (['schema', 'is_regression', 'regression_types', 'severity', 'allowed_bootstrap', 'sentinel_verdict'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::SCHEMA, $result['schema']);
    }

    // ── Clean proposal passes ─────────────────────────────────────────────────

    public function test_clean_proposal_passes_with_no_regressions(): void
    {
        $result = $this->sentinel->scan($this->clean());

        $this->assertFalse($result['is_regression']);
        $this->assertSame([], $result['regression_types']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::SEVERITY_NONE, $result['severity']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::VERDICT_PASS, $result['sentinel_verdict']);
    }

    // ── Human dependency ──────────────────────────────────────────────────────

    public function test_requires_human_approval_is_flagged(): void
    {
        $input                           = $this->clean();
        $input['requires_human_approval'] = true;

        $result = $this->sentinel->scan($input);

        $this->assertTrue($result['is_regression']);
        $this->assertContains('human_dependency', $result['regression_types']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::SEVERITY_BLOCKING, $result['severity']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::VERDICT_FAIL, $result['sentinel_verdict']);
    }

    public function test_human_final_runtime_owner_is_flagged(): void
    {
        $input                      = $this->clean();
        $input['final_runtime_owner'] = 'human';

        $result = $this->sentinel->scan($input);

        $this->assertContains('human_dependency', $result['regression_types']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::VERDICT_FAIL, $result['sentinel_verdict']);
    }

    // ── Operator seeding ──────────────────────────────────────────────────────

    public function test_operator_seeding_required_produces_warning(): void
    {
        $input                          = $this->clean();
        $input['requires_operator_seeding'] = true;

        $result = $this->sentinel->scan($input);

        $this->assertTrue($result['is_regression']);
        $this->assertContains('operator_seeding_required', $result['regression_types']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::SEVERITY_WARNING, $result['severity']);
        // Warning alone → still pass (not blocking)
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::VERDICT_PASS, $result['sentinel_verdict']);
    }

    // ── Provider dependency ───────────────────────────────────────────────────

    public function test_external_provider_always_required_is_flagged(): void
    {
        $input                                  = $this->clean();
        $input['requires_external_provider_always'] = true;

        $result = $this->sentinel->scan($input);

        $this->assertContains('provider_dependency', $result['regression_types']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::VERDICT_FAIL, $result['sentinel_verdict']);
    }

    // ── AC2: bootstrap muscle — allowed when atlas_native + evidence_gated ────

    public function test_bootstrap_muscle_allowed_when_atlas_native_and_evidence_gated(): void
    {
        $input = $this->clean();
        $input['bootstrap_muscle_used'] = true;
        $input['final_runtime_owner']   = 'atlas_native';
        $input['evidence_gated']        = true;

        $result = $this->sentinel->scan($input);

        $this->assertTrue($result['allowed_bootstrap']);
        $this->assertFalse($result['is_regression']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::VERDICT_PASS, $result['sentinel_verdict']);
    }

    public function test_bootstrap_muscle_blocked_when_not_evidence_gated(): void
    {
        $input = $this->clean();
        $input['bootstrap_muscle_used'] = true;
        $input['final_runtime_owner']   = 'atlas_native';
        $input['evidence_gated']        = false;

        $result = $this->sentinel->scan($input);

        $this->assertFalse($result['allowed_bootstrap']);
        $this->assertContains('ungated_bootstrap_muscle', $result['regression_types']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::VERDICT_FAIL, $result['sentinel_verdict']);
    }

    public function test_bootstrap_muscle_blocked_when_external_provider_owned(): void
    {
        $input = $this->clean();
        $input['bootstrap_muscle_used'] = true;
        $input['final_runtime_owner']   = 'external_provider';
        $input['evidence_gated']        = true;

        $result = $this->sentinel->scan($input);

        $this->assertFalse($result['allowed_bootstrap']);
        $this->assertContains('ungated_bootstrap_muscle', $result['regression_types']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::VERDICT_FAIL, $result['sentinel_verdict']);
    }

    public function test_ungated_bootstrap_muscle_is_blocking_severity(): void
    {
        $input = $this->clean();
        $input['bootstrap_muscle_used'] = true;
        $input['evidence_gated']        = false;

        $result = $this->sentinel->scan($input);

        $this->assertContains('ungated_bootstrap_muscle', $result['regression_types']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::SEVERITY_BLOCKING, $result['severity']);
    }

    public function test_ungated_bootstrap_does_not_also_add_provider_dependency(): void
    {
        $input = $this->clean();
        $input['bootstrap_muscle_used']             = true;
        $input['evidence_gated']                    = false;
        $input['requires_external_provider_always'] = false;

        $result = $this->sentinel->scan($input);

        $this->assertContains('ungated_bootstrap_muscle', $result['regression_types']);
        $this->assertNotContains('provider_dependency', $result['regression_types']);
    }

    public function test_provider_dependency_and_ungated_bootstrap_can_coexist(): void
    {
        $input = $this->clean();
        $input['requires_external_provider_always'] = true;
        $input['bootstrap_muscle_used']             = true;
        $input['evidence_gated']                    = false;

        $result = $this->sentinel->scan($input);

        $this->assertContains('provider_dependency', $result['regression_types']);
        $this->assertContains('ungated_bootstrap_muscle', $result['regression_types']);
    }

    // ── Prompt-only memory ────────────────────────────────────────────────────

    public function test_prompt_only_memory_is_flagged(): void
    {
        $input                     = $this->clean();
        $input['prompt_only_memory'] = true;

        $result = $this->sentinel->scan($input);

        $this->assertContains('prompt_only_memory', $result['regression_types']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::VERDICT_FAIL, $result['sentinel_verdict']);
    }

    // ── External-tool-only execution ──────────────────────────────────────────

    public function test_external_tool_only_execution_is_flagged(): void
    {
        $input                             = $this->clean();
        $input['external_tool_only_execution'] = true;

        $result = $this->sentinel->scan($input);

        $this->assertContains('external_tool_only', $result['regression_types']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::VERDICT_FAIL, $result['sentinel_verdict']);
    }

    // ── Multiple regressions all reported ─────────────────────────────────────

    public function test_multiple_regressions_all_appear(): void
    {
        $result = $this->sentinel->scan([
            'requires_human_approval'          => true,
            'prompt_only_memory'                => true,
            'external_tool_only_execution'      => true,
            'requires_external_provider_always' => false,
            'final_runtime_owner'               => 'atlas_native',
            'evidence_gated'                    => true,
        ]);

        $this->assertContains('human_dependency', $result['regression_types']);
        $this->assertContains('prompt_only_memory', $result['regression_types']);
        $this->assertContains('external_tool_only', $result['regression_types']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::SEVERITY_BLOCKING, $result['severity']);
    }
}
