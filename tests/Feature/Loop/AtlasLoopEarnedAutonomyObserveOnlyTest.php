<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopEarnedAutonomyDecisionTrace;
use App\Services\Ai\Foundry\Rsi\RsiInvariantGuardService;
use Tests\TestCase;

/**
 * ACDE S2 (observe-only) — proves the EarnedAutonomy door is observed safely and stays BOLTED SHUT:
 *  - the trace is provider-safe (no raw code) and a no-op when OFF (byte-identical);
 *  - the un-sacred ACTUATION seam is locked by the guard's substring backstop (a diff wiring auto_apply /
 *    auto_merge is REJECTED), the one defence the sacred-path check misses;
 *  - the door has NO live actuator: the production self-target selector never applies/merges/canonizes.
 */
final class AtlasLoopEarnedAutonomyObserveOnlyTest extends TestCase
{
    private function decision(): array
    {
        return [
            'decision' => 'human_gate',
            'risk_rank' => 3,
            'risk_class' => 'gate_or_invariant_touch',
            'earned_tier' => 0,
            'auto_applied' => false,
            'routed_to_human_gate' => true,
            'kill_armed' => false,
            'drift_detected' => false,
            // a hostile payload trying to smuggle raw code into the trace:
            'raw_diff' => '<?php class Exfil { public function leak() { return $secret; } }',
            'added_lines' => ['$x["auto_apply"] = true;'],
        ];
    }

    public function test_trace_is_provider_safe_no_code_or_self_report_leaks(): void
    {
        $trace = (new AtlasLoopEarnedAutonomyDecisionTrace)->toSafeTrace($this->decision(), ['app/Foo/Bar.php']);

        $allowed = ['schema', 'decision', 'risk_rank', 'risk_class', 'earned_tier', 'auto_applied', 'routed_to_human_gate', 'kill_armed', 'drift_detected', 'revoked', 'changed_paths', 'decision_hash'];
        $this->assertSame([], array_diff(array_keys($trace), $allowed), 'only provider-safe keys are emitted');
        $encoded = (string) json_encode($trace);
        $this->assertStringNotContainsString('Exfil', $encoded, 'no raw diff/code enters the trace');
        $this->assertStringNotContainsString('leak', $encoded);
        $this->assertStringNotContainsString('auto_apply" = true', $encoded, 'no added-line bodies enter the trace');
        $this->assertFalse($trace['auto_applied'], 'a human-gated decision traces auto_applied=false');
        $this->assertSame(['app/Foo/Bar.php'], $trace['changed_paths']);
    }

    public function test_trace_is_a_no_op_when_off(): void
    {
        config(['atlas.loop.earned_autonomy_decision_trace' => false]);
        $this->assertSame([], (new AtlasLoopEarnedAutonomyDecisionTrace)->record($this->decision(), ['app/Foo/Bar.php']), 'OFF => empty => byte-identical');
    }

    public function test_the_unsacred_actuation_seam_is_locked_by_the_guard_substring_backstop(): void
    {
        $guard = app(RsiInvariantGuardService::class);

        // A diff trying to WIRE an actuator (introduce auto_apply / auto_merge intent) in ANY file is rejected
        // by the substring backstop — the defence that covers the proposal-gate/selector seam the sacred-path
        // check does NOT (those seam files are not in the sacred registry).
        foreach (['$result["auto_apply"] = true;', 'return $this->autoMerge($proposal); // auto_merge'] as $line) {
            $verdict = $guard->screen(['changed_paths' => ['app/Services/Ai/Foundry/Rsi/RsiSelfImprovementProposalGate.php'], 'added_lines' => [$line]]);
            $this->assertTrue((bool) $verdict['rejected'], "wiring an actuator via '{$line}' must be rejected");
            $codes = array_column((array) ($verdict['reasons'] ?? []), 'code');
            $this->assertContains(RsiInvariantGuardService::REASON_AUTO_CANONIZE_ATTEMPTED, $codes);
        }
    }

    public function test_the_door_has_no_live_actuator_in_the_selector(): void
    {
        // The only admit() caller (the production self-target selector) must NOT contain any apply/merge/
        // canonize actuation — the auto_apply signal is a verified dead-end. If a future change adds an
        // actuator here, this frozen test fails (the door is opened only by a deliberate, reviewed change).
        $selector = base_path('app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Rsi/SelfTargetSelectorService.php');
        $this->assertFileExists($selector);
        $src = (string) file_get_contents($selector);
        foreach (['git apply', 'git merge', 'shell_exec', 'proc_open', '->autoApply(', 'AtlasLoopAutoMergeService'] as $actuator) {
            $this->assertStringNotContainsString($actuator, $src, "the selector must never actuate ('{$actuator}') the auto_apply signal");
        }
    }
}
