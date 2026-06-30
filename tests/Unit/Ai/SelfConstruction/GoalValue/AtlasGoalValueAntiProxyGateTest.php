<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\GoalValue;

use App\Services\Ai\SelfConstruction\GoalValue\AtlasGoalValueAntiProxyGate;
use Tests\TestCase;

final class AtlasGoalValueAntiProxyGateTest extends TestCase
{
    public function test_proxy_only_signals_are_blocked(): void
    {
        $verdict = (new AtlasGoalValueAntiProxyGate)->evaluate(
            ['test_count' => true, 'line_churn' => true, 'self_reported_success' => true],
            [],
        );

        $this->assertTrue($verdict['blocked']);
        $this->assertSame(['test_count', 'line_churn', 'self_reported_success'], $verdict['proxy_categories']);
        $this->assertSame($verdict['proxy_categories'], $verdict['blocked_proxy_categories']);
        $this->assertFalse($verdict['has_real_lever']);
    }

    public function test_real_capability_lift_lets_proxy_signal_through(): void
    {
        $verdict = (new AtlasGoalValueAntiProxyGate)->evaluate(
            ['line_churn' => true, 'rename_only' => true],
            ['capability_lift_refs' => ['receipt:new-capability-1']],
        );

        $this->assertFalse($verdict['blocked'], 'a real capability_lift ref unlocks the proxy categories');
        $this->assertSame([], $verdict['blocked_proxy_categories']);
        $this->assertTrue($verdict['has_real_lever']);
    }

    public function test_real_failure_removal_also_unlocks_proxy(): void
    {
        $verdict = (new AtlasGoalValueAntiProxyGate)->evaluate(
            ['test_count' => true],
            ['failure_removal_refs' => ['red-test:42']],
        );

        $this->assertFalse($verdict['blocked']);
        $this->assertTrue($verdict['has_real_lever']);
    }

    public function test_no_proxy_no_lever_yields_unblocked_quiet_envelope(): void
    {
        $verdict = (new AtlasGoalValueAntiProxyGate)->evaluate([], []);

        $this->assertFalse($verdict['blocked'], 'silence is not refusal — the gate only fires on proxy-only inputs');
        $this->assertSame([], $verdict['proxy_categories']);
        $this->assertSame([], $verdict['blocked_proxy_categories']);
    }

    public function test_verdict_carries_no_numeric_score_field(): void
    {
        $verdict = (new AtlasGoalValueAntiProxyGate)->evaluate(['task_count' => true], []);
        foreach (array_keys($verdict) as $key) {
            $this->assertStringNotContainsString('score', strtolower((string) $key));
        }
    }

    public function test_evaluation_is_deterministic_byte_identical(): void
    {
        $svc = new AtlasGoalValueAntiProxyGate;
        $signals = ['task_count' => true, 'line_churn' => true];
        $levers = ['capability_lift_refs' => ['r1']];
        $this->assertSame(json_encode($svc->evaluate($signals, $levers)), json_encode($svc->evaluate($signals, $levers)));
    }

    public function test_whitespace_only_null_false_empty_refs_do_not_set_has_real_lever(): void
    {
        $verdict = (new AtlasGoalValueAntiProxyGate)->evaluate(
            ['test_count' => true],
            ['capability_lift_refs' => ['   ', '', null, false], 'failure_removal_refs' => [' ', '']],
        );

        $this->assertTrue($verdict['blocked'], 'whitespace/empty refs must not unlock proxy-only work');
        $this->assertFalse($verdict['has_real_lever']);
        $this->assertSame([], $verdict['real_lever_families']);
    }

    public function test_duplicate_refs_normalized_deterministically(): void
    {
        $svc = new AtlasGoalValueAntiProxyGate;
        $withDups = $svc->evaluate(['line_churn' => true], ['capability_lift_refs' => ['ref-a', 'ref-a', 'ref-a']]);
        $withOne  = $svc->evaluate(['line_churn' => true], ['capability_lift_refs' => ['ref-a']]);

        $this->assertSame($withOne['has_real_lever'], $withDups['has_real_lever']);
        $this->assertSame($withOne['blocked'], $withDups['blocked']);
        $this->assertSame($withOne['real_lever_families'], $withDups['real_lever_families']);
    }

    public function test_real_lever_families_lists_contributing_families(): void
    {
        $verdict = (new AtlasGoalValueAntiProxyGate)->evaluate(
            [],
            ['capability_lift_refs' => ['cap-1'], 'failure_removal_refs' => ['fix-1']],
        );

        $this->assertSame(['capability_lift', 'failure_removal'], $verdict['real_lever_families']);
    }

    public function test_real_lever_families_only_capability_lift_when_failure_refs_absent(): void
    {
        $verdict = (new AtlasGoalValueAntiProxyGate)->evaluate(
            ['rename_only' => true],
            ['capability_lift_refs' => ['cap-ref']],
        );

        $this->assertSame(['capability_lift'], $verdict['real_lever_families']);
        $this->assertFalse($verdict['blocked']);
    }

    public function test_all_five_new_proxy_categories_exist_in_const(): void
    {
        foreach (['wrapper_only', 'scaffold_only_test', 'command_surface_only', 'doc_only_claim', 'template_farm_batch'] as $cat) {
            $this->assertContains($cat, AtlasGoalValueAntiProxyGate::PROXY_CATEGORIES, "$cat must be in PROXY_CATEGORIES");
        }
    }

    public function test_wrapper_only_blocks_without_real_lever(): void
    {
        $verdict = (new AtlasGoalValueAntiProxyGate)->evaluate(['wrapper_only' => true], []);
        $this->assertTrue($verdict['blocked']);
        $this->assertContains('wrapper_only', $verdict['blocked_proxy_categories']);
    }

    public function test_scaffold_only_test_blocks_without_real_lever(): void
    {
        $verdict = (new AtlasGoalValueAntiProxyGate)->evaluate(['scaffold_only_test' => true], []);
        $this->assertTrue($verdict['blocked']);
        $this->assertContains('scaffold_only_test', $verdict['blocked_proxy_categories']);
    }

    public function test_command_surface_only_blocks_without_real_lever(): void
    {
        $verdict = (new AtlasGoalValueAntiProxyGate)->evaluate(['command_surface_only' => true], []);
        $this->assertTrue($verdict['blocked']);
        $this->assertContains('command_surface_only', $verdict['blocked_proxy_categories']);
    }

    public function test_doc_only_claim_blocks_without_real_lever(): void
    {
        $verdict = (new AtlasGoalValueAntiProxyGate)->evaluate(['doc_only_claim' => true], []);
        $this->assertTrue($verdict['blocked']);
        $this->assertContains('doc_only_claim', $verdict['blocked_proxy_categories']);
    }

    public function test_template_farm_batch_blocks_without_real_lever(): void
    {
        $verdict = (new AtlasGoalValueAntiProxyGate)->evaluate(['template_farm_batch' => true], []);
        $this->assertTrue($verdict['blocked']);
        $this->assertContains('template_farm_batch', $verdict['blocked_proxy_categories']);
    }

    public function test_new_proxy_categories_allowed_when_paired_with_capability_lift(): void
    {
        $levers = ['capability_lift_refs' => ['cap:real-feature-shipped']];
        foreach (['wrapper_only', 'scaffold_only_test', 'command_surface_only', 'doc_only_claim', 'template_farm_batch'] as $cat) {
            $verdict = (new AtlasGoalValueAntiProxyGate)->evaluate([$cat => true], $levers);
            $this->assertFalse($verdict['blocked'], "$cat must be allowed when paired with capability_lift_refs");
            $this->assertSame([], $verdict['blocked_proxy_categories']);
        }
    }

    public function test_new_proxy_categories_allowed_when_paired_with_failure_removal(): void
    {
        $levers = ['failure_removal_refs' => ['red-test:eliminated-flake']];
        foreach (['wrapper_only', 'scaffold_only_test', 'command_surface_only', 'doc_only_claim', 'template_farm_batch'] as $cat) {
            $verdict = (new AtlasGoalValueAntiProxyGate)->evaluate([$cat => true], $levers);
            $this->assertFalse($verdict['blocked'], "$cat must be allowed when paired with failure_removal_refs");
        }
    }

    // ── anti_proxy_reason_codes (rejected tasks) ──────────────────────────────

    public function test_blocked_verdict_includes_concrete_anti_proxy_reason_codes(): void
    {
        $verdict = (new AtlasGoalValueAntiProxyGate)->evaluate(['wrapper_only' => true, 'doc_only_claim' => true], []);

        $this->assertTrue($verdict['blocked']);
        $this->assertNotEmpty($verdict['anti_proxy_reason_codes']);
        foreach ($verdict['anti_proxy_reason_codes'] as $code) {
            $this->assertIsString($code);
            $this->assertStringContainsString(':', $code, 'reason codes must be namespaced as category:instruction');
        }
    }

    public function test_accepted_verdict_has_empty_anti_proxy_reason_codes(): void
    {
        $verdict = (new AtlasGoalValueAntiProxyGate)->evaluate(
            ['line_churn' => true],
            ['capability_lift_refs' => ['cap:real']],
        );

        $this->assertFalse($verdict['blocked']);
        $this->assertSame([], $verdict['anti_proxy_reason_codes']);
    }

    public function test_anti_proxy_reason_codes_sorted_deterministically(): void
    {
        $svc = new AtlasGoalValueAntiProxyGate;
        $a = $svc->evaluate(['task_count' => true, 'test_count' => true], []);
        $b = $svc->evaluate(['test_count' => true, 'task_count' => true], []);

        $this->assertSame($a['anti_proxy_reason_codes'], $b['anti_proxy_reason_codes']);
    }

    // ── falsifiable_evidence_demand (accepted tasks) ──────────────────────────

    public function test_accepted_verdict_includes_falsifiable_evidence_demand(): void
    {
        $verdict = (new AtlasGoalValueAntiProxyGate)->evaluate(
            [],
            ['capability_lift_refs' => ['cap:feature-wired']],
        );

        $this->assertFalse($verdict['blocked']);
        $this->assertNotEmpty($verdict['falsifiable_evidence_demand']);
        $this->assertContains(
            'capability_lift:prove_with_green_test_gate_and_behavior_observable',
            $verdict['falsifiable_evidence_demand'],
        );
    }

    public function test_failure_removal_lever_adds_its_own_evidence_demand(): void
    {
        $verdict = (new AtlasGoalValueAntiProxyGate)->evaluate(
            [],
            ['failure_removal_refs' => ['fix:red-test-42']],
        );

        $this->assertContains(
            'failure_removal:prove_with_red_to_green_trace_and_receipt',
            $verdict['falsifiable_evidence_demand'],
        );
    }

    public function test_blocked_verdict_has_empty_falsifiable_evidence_demand(): void
    {
        $verdict = (new AtlasGoalValueAntiProxyGate)->evaluate(['test_count' => true], []);

        $this->assertTrue($verdict['blocked']);
        $this->assertSame([], $verdict['falsifiable_evidence_demand']);
    }
}
