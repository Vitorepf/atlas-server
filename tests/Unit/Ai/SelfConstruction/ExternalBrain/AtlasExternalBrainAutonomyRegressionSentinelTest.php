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

    // ── AC1: remediation_hints + autonomy_owner_contract ──────────────────────

    public function test_output_has_remediation_hints_and_autonomy_owner_contract(): void
    {
        $result = $this->sentinel->scan([]);

        $this->assertArrayHasKey('remediation_hints', $result);
        $this->assertArrayHasKey('autonomy_owner_contract', $result);
        $this->assertIsArray($result['remediation_hints']);
        $this->assertSame([], $result['remediation_hints']);
    }

    public function test_remediation_hint_present_for_each_blocking_regression(): void
    {
        $result = $this->sentinel->scan([
            'requires_human_approval' => true,
            'prompt_only_memory'      => true,
        ]);

        $this->assertCount(2, $result['remediation_hints']);
        $this->assertNotEmpty(array_filter($result['remediation_hints'], fn ($h) => str_contains($h, 'human-approval')));
        $this->assertNotEmpty(array_filter($result['remediation_hints'], fn ($h) => str_contains($h, 'Atlas-native store')));
    }

    // ── AC1/AC3: actionable blockers with capability/reason/repair_hint/required_evidence ──

    public function test_hard_regression_becomes_a_blocker_with_all_required_fields(): void
    {
        $input                            = $this->clean();
        $input['requires_human_approval'] = true;

        $result = $this->sentinel->scan($input);

        $this->assertCount(1, $result['blockers']);
        $blocker = $result['blockers'][0];
        $this->assertSame('human_dependency', $blocker['capability']);
        $this->assertNotEmpty($blocker['reason']);
        $this->assertNotEmpty($blocker['repair_hint']);
        $this->assertNotEmpty($blocker['required_evidence']);
        $this->assertFalse($result['safe_to_go']);
    }

    public function test_multiple_hard_regressions_all_become_blockers(): void
    {
        $result = $this->sentinel->scan([
            'requires_human_approval'     => true,
            'prompt_only_memory'          => true,
            'final_runtime_owner'         => 'atlas_native',
            'evidence_gated'              => true,
        ]);

        $this->assertCount(2, $result['blockers']);
        $capabilities = array_column($result['blockers'], 'capability');
        $this->assertContains('human_dependency', $capabilities);
        $this->assertContains('prompt_only_memory', $capabilities);
    }

    public function test_operator_seeding_warning_is_observation_only_and_not_a_blocker(): void
    {
        $input                               = $this->clean();
        $input['requires_operator_seeding']  = true;

        $result = $this->sentinel->scan($input);

        $this->assertSame([], $result['blockers']);
        $this->assertCount(1, $result['observation_only_alerts']);
        $this->assertSame('operator_seeding_required', $result['observation_only_alerts'][0]['capability']);
        $this->assertArrayNotHasKey('repair_hint', $result['observation_only_alerts'][0]);
        $this->assertArrayNotHasKey('required_evidence', $result['observation_only_alerts'][0]);
    }

    public function test_observation_only_alert_alone_is_safe_to_go(): void
    {
        $input                              = $this->clean();
        $input['requires_operator_seeding'] = true;

        $result = $this->sentinel->scan($input);

        $this->assertTrue($result['safe_to_go'], 'observation-only alerts never block safe_to_go');
    }

    public function test_clean_proposal_is_safe_to_go_with_no_blockers_or_alerts(): void
    {
        $result = $this->sentinel->scan($this->clean());

        $this->assertTrue($result['safe_to_go']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame([], $result['observation_only_alerts']);
    }

    public function test_any_blocker_present_makes_safe_to_go_false_even_with_alerts(): void
    {
        $result = $this->sentinel->scan([
            'requires_operator_seeding' => true,
            'prompt_only_memory'        => true,
            'final_runtime_owner'       => 'atlas_native',
            'evidence_gated'            => true,
        ]);

        $this->assertNotEmpty($result['observation_only_alerts']);
        $this->assertNotEmpty($result['blockers']);
        $this->assertFalse($result['safe_to_go']);
    }

    public function test_autonomy_owner_contract_reflects_inputs(): void
    {
        $result = $this->sentinel->scan([
            'bootstrap_muscle_used' => true,
            'final_runtime_owner'   => 'atlas_native',
            'evidence_gated'        => true,
        ]);

        $this->assertSame([
            'final_runtime_owner'   => 'atlas_native',
            'evidence_gated'        => true,
            'bootstrap_muscle_used' => true,
            'allowed_bootstrap'     => true,
        ], $result['autonomy_owner_contract']);
    }

    // ── AC: high-severity regressions become blockers with capability, reason, repair_hint, required_evidence ──

    public function test_high_severity_regression_becomes_blocker_with_all_fields(): void
    {
        $input = $this->clean();
        $input['requires_human_approval'] = true;

        $result = $this->sentinel->scan($input);

        $this->assertCount(1, $result['blockers']);
        $blocker = $result['blockers'][0];
        $this->assertSame('human_dependency', $blocker['capability']);
        $this->assertStringContainsString('human_dependency', $blocker['reason']);
        $this->assertNotEmpty($blocker['repair_hint']);
        $this->assertNotEmpty($blocker['required_evidence']);
    }

    // ── AC: observation-only alerts without repair hints are not counted as resolved or safe-to-go ──

    public function test_observation_only_alerts_not_counted_as_resolved(): void
    {
        $input = $this->clean();
        $input['requires_operator_seeding'] = true;

        $result = $this->sentinel->scan($input);

        $this->assertCount(1, $result['observation_only_alerts']);
        $alert = $result['observation_only_alerts'][0];
        $this->assertArrayNotHasKey('repair_hint', $alert);
        $this->assertArrayNotHasKey('required_evidence', $alert);
        // Observation-only alerts alone are safe_to_go=true (they don't block)
        $this->assertTrue($result['safe_to_go']);
    }

    // ── AC: tests cover no-regression, warning, blocker, and observation-only cases with deterministic severity ordering ──

    public function test_no_regression_case(): void
    {
        $result = $this->sentinel->scan($this->clean());

        $this->assertFalse($result['is_regression']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::SEVERITY_NONE, $result['severity']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame([], $result['observation_only_alerts']);
    }

    public function test_warning_case(): void
    {
        $input = $this->clean();
        $input['requires_operator_seeding'] = true;

        $result = $this->sentinel->scan($input);

        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::SEVERITY_WARNING, $result['severity']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::VERDICT_PASS, $result['sentinel_verdict']);
    }

    public function test_blocker_case(): void
    {
        $input = $this->clean();
        $input['requires_human_approval'] = true;

        $result = $this->sentinel->scan($input);

        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::SEVERITY_BLOCKING, $result['severity']);
        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::VERDICT_FAIL, $result['sentinel_verdict']);
        $this->assertFalse($result['safe_to_go']);
    }

    public function test_observation_only_case(): void
    {
        $input = $this->clean();
        $input['requires_operator_seeding'] = true;

        $result = $this->sentinel->scan($input);

        $this->assertCount(1, $result['observation_only_alerts']);
        $this->assertSame([], $result['blockers']);
    }

    public function test_deterministic_severity_ordering(): void
    {
        // When both soft and hard regressions are present, severity must be blocking (hard wins)
        $input = $this->clean();
        $input['requires_operator_seeding'] = true;
        $input['requires_human_approval'] = true;

        $result = $this->sentinel->scan($input);

        $this->assertSame(AtlasExternalBrainAutonomyRegressionSentinel::SEVERITY_BLOCKING, $result['severity']);
        $this->assertNotEmpty($result['blockers']);
        $this->assertNotEmpty($result['observation_only_alerts']);
    }
}
