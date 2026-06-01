<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDevEfficientProgrammingFlowV1Part03Service;
use Tests\TestCase;

/**
 * Pins the documented Atlas Dev efficient programming flow rules (Parte 3, §19–§23).
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-03.md
 */
class AtlasDevEfficientProgrammingFlowV1Part03Test extends TestCase
{
    private function service(): AtlasDevEfficientProgrammingFlowV1Part03Service
    {
        return new AtlasDevEfficientProgrammingFlowV1Part03Service();
    }

    /**
     * §19.1 — `passed` is the only unconditional full success; `failed` and
     * `escalate_forge` are not; `no_patch_needed` is success only WITH enough
     * refs ("depende"); and the hard invariant: an `unverified` run can NEVER be
     * reported as `passed` — it is forced down to `needs_review`.
     */
    public function test_completion_states_and_unverified_never_passes(): void
    {
        $s = $this->service();

        $this->assertTrue($s->classifyCompletion('passed', 'verified')['full_success']);
        $this->assertFalse($s->classifyCompletion('failed', 'verified')['full_success']);
        $this->assertFalse($s->classifyCompletion('escalate_forge', 'verified')['full_success']);

        // no_patch_needed depends on sufficient refs.
        $this->assertTrue($s->classifyCompletion('no_patch_needed', 'verified', true)['full_success']);
        $this->assertFalse($s->classifyCompletion('no_patch_needed', 'verified', false)['full_success']);

        // Hard completion_state_gate invariant.
        $gated = $s->classifyCompletion('passed', 'unverified');
        $this->assertFalse($gated['allowed']);
        $this->assertFalse($gated['full_success']);
        $this->assertSame('needs_review', $gated['effective_state']);
        $this->assertSame('unverified_never_becomes_passed', $gated['reason']);
    }

    /**
     * §19.2 — a repeated failure_signature (and a risk that became R4/R5) is a
     * documented stop condition: the loop must stop and escalate. With no
     * condition firing it continues.
     */
    public function test_repair_loop_stops_on_documented_conditions(): void
    {
        $s = $this->service();

        $stop = $s->repairLoopDecision([
            'same_failure_signature_twice' => true,
            'risk_level' => 'R5',
        ]);
        $this->assertTrue($stop['must_stop']);
        $this->assertSame('stop_and_escalate', $stop['action']);
        $this->assertContains('same_failure_signature_twice', $stop['stop_reasons']);
        $this->assertContains('risk_became_r4_or_r5', $stop['stop_reasons']);

        $cont = $s->repairLoopDecision(['risk_level' => 'R2']);
        $this->assertFalse($cont['must_stop']);
        $this->assertSame('continue_repair', $cont['action']);
        $this->assertSame([], $cont['stop_reasons']);
    }

    /**
     * §19.3 — verification_gate FAILS when a test-requiring profile (php_laravel)
     * has neither a test nor a no_test_reason; it PASSES with an explicit reason;
     * and a generic_no_test profile passes with no test at all. An unknown
     * profile fails closed.
     */
    public function test_verification_gate_requires_test_or_reason(): void
    {
        $s = $this->service();

        $this->assertFalse($s->verificationGate('php_laravel', false, null)['passes']);
        $this->assertSame(
            'profile_requires_test_but_no_test_and_no_reason',
            $s->verificationGate('php_laravel', false, null)['reason']
        );
        $this->assertTrue($s->verificationGate('php_laravel', true, null)['passes']);
        $this->assertTrue($s->verificationGate('php_laravel', false, 'docs-only change, no runnable test')['passes']);

        // generic_no_test requires no test.
        $generic = $s->verificationGate('generic_no_test', false, null);
        $this->assertTrue($generic['passes']);
        $this->assertFalse($generic['requires_test']);

        // unknown profile fails closed.
        $this->assertFalse($s->verificationGate('python_pytest', true, null)['passes']);
    }

    /**
     * §20 — escalation: score >=7 recommends forge_obra but still requires a human
     * (human_action_required) unless already inside an active contracted Obra;
     * score >=4 raises an obra_candidate preview; below 4 stays in the fast path.
     */
    public function test_escalation_score_thresholds_never_auto_create_obra(): void
    {
        $s = $this->service();

        $seven = $s->escalationDecision(['score' => 7]);
        $this->assertSame('forge_obra', $seven['target']);
        $this->assertTrue($seven['human_action_required']);

        // Already inside an active Obra with contracted Forge execution: no human gate.
        $contracted = $s->escalationDecision([
            'score' => 9,
            'inside_active_obra' => true,
            'forge_execution_contracted' => true,
        ]);
        $this->assertSame('forge_obra', $contracted['target']);
        $this->assertFalse($contracted['human_action_required']);

        // Medium signal: obra_candidate preview.
        $four = $s->escalationDecision(['score' => 4]);
        $this->assertSame('obra_candidate_preview', $four['target']);
        $this->assertTrue($four['obra_candidate']);

        // Below all thresholds: fast path, no escalation.
        $low = $s->escalationDecision(['score' => 1]);
        $this->assertSame('atlas_dev_fast_path', $low['target']);
        $this->assertFalse($low['human_action_required']);
        $this->assertFalse($low['obra_candidate']);
    }

    /**
     * §21 — patch mode requires diff_hash, changed_files, scope_guard,
     * test_or_command and output_hash; a bundle missing scope_guard/test/output
     * is reported as unsatisfied with the exact missing keys.
     */
    public function test_evidence_minima_reports_missing_for_patch_mode(): void
    {
        $s = $this->service();

        $ok = $s->evidenceCheck('patch', ['diff_hash', 'changed_files', 'scope_guard', 'test_or_command', 'output_hash']);
        $this->assertTrue($ok['satisfied']);
        $this->assertSame([], $ok['missing']);

        $bad = $s->evidenceCheck('patch', ['diff_hash', 'changed_files']);
        $this->assertFalse($bad['satisfied']);
        $this->assertContains('scope_guard', $bad['missing']);
        $this->assertContains('output_hash', $bad['missing']);
    }

    /**
     * §23 — all nine quality build gates block. None is advisory.
     */
    public function test_all_quality_build_gates_block(): void
    {
        $gates = $this->service()->qualityBuildGates();

        $this->assertCount(9, $gates);
        foreach ($gates as $id => $gate) {
            $this->assertTrue($gate['blocks'], "Gate {$id} must block");
            $this->assertNotSame('', $gate['evidence'], "Gate {$id} must carry evidence");
        }
    }
}
