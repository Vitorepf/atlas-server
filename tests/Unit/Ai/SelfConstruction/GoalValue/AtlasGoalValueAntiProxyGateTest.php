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
}
