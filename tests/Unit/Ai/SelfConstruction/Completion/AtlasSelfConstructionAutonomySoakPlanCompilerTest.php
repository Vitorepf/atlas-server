<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Completion;

use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionAutonomySoakPlanCompiler;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionAutonomySoakPlanCompilerTest extends TestCase
{
    private function compiler(): AtlasSelfConstructionAutonomySoakPlanCompiler
    {
        return new AtlasSelfConstructionAutonomySoakPlanCompiler;
    }

    private function healthySnapshot(): array
    {
        return [
            'queue_health'  => ['pressure' => 0.3, 'poison_ratio' => 0.05],
            'worker_health' => ['active_workers' => 3, 'zombie_count' => 0],
            'merge_health'  => ['auto_merge_enabled' => true, 'merge_success_rate' => 0.97],
        ];
    }

    private function unhealthySnapshot(): array
    {
        return [
            'queue_health'  => ['pressure' => 0.85, 'poison_ratio' => 0.35],
            'worker_health' => ['active_workers' => 0, 'zombie_count' => 2],
            'merge_health'  => ['auto_merge_enabled' => false, 'merge_success_rate' => 0.60],
        ];
    }

    // ── AC1: output shape + soak params ──────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->compiler()->compile([]);
        $this->assertSame(AtlasSelfConstructionAutonomySoakPlanCompiler::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('soak_duration_hours', $r);
        $this->assertArrayHasKey('required_green_streak_tasks', $r);
        $this->assertArrayHasKey('steady_state_criteria', $r);
        $this->assertArrayHasKey('disqualifiers', $r);
        $this->assertArrayHasKey('is_soak_ready', $r);
        $this->assertArrayHasKey('fail_closed_reason', $r);
    }

    public function test_healthy_snapshot_yields_24h_soak_and_50_green_streak(): void
    {
        $r = $this->compiler()->compile($this->healthySnapshot());
        $this->assertSame(24.0, $r['soak_duration_hours']);
        $this->assertSame(50, $r['required_green_streak_tasks']);
        $this->assertTrue($r['is_soak_ready']);
        $this->assertEmpty($r['disqualifiers']);
        $this->assertNull($r['fail_closed_reason']);
    }

    public function test_unhealthy_snapshot_yields_72h_soak_and_not_ready(): void
    {
        $r = $this->compiler()->compile($this->unhealthySnapshot());
        $this->assertSame(72.0, $r['soak_duration_hours']);
        $this->assertSame(200, $r['required_green_streak_tasks']);
        $this->assertFalse($r['is_soak_ready']);
        $this->assertNotEmpty($r['disqualifiers']);
    }

    // ── Standard criteria evaluation ──────────────────────────────────────────

    public function test_healthy_snapshot_all_criteria_pass(): void
    {
        $r = $this->compiler()->compile($this->healthySnapshot());
        foreach ($r['steady_state_criteria'] as $c) {
            $this->assertSame('pass', $c['status'], "Expected pass for {$c['criterion']}");
        }
    }

    public function test_high_pressure_criterion_fails(): void
    {
        $snap = $this->healthySnapshot();
        $snap['queue_health']['pressure'] = 0.75;
        $r = $this->compiler()->compile($snap);
        $failing = array_filter($r['steady_state_criteria'],
            fn ($c) => $c['criterion'] === 'queue_pressure_below_threshold');
        $this->assertSame('fail', array_values($failing)[0]['status']);
        $this->assertFalse($r['is_soak_ready']);
    }

    public function test_zombie_workers_criterion_fails(): void
    {
        $snap = $this->healthySnapshot();
        $snap['worker_health']['zombie_count'] = 1;
        $r = $this->compiler()->compile($snap);
        $failing = array_filter($r['steady_state_criteria'],
            fn ($c) => $c['criterion'] === 'no_zombie_workers');
        $this->assertSame('fail', array_values($failing)[0]['status']);
    }

    public function test_auto_merge_disabled_criterion_fails(): void
    {
        $snap = $this->healthySnapshot();
        $snap['merge_health']['auto_merge_enabled'] = false;
        $r = $this->compiler()->compile($snap);
        $failing = array_filter($r['steady_state_criteria'],
            fn ($c) => $c['criterion'] === 'auto_merge_enabled');
        $this->assertSame('fail', array_values($failing)[0]['status']);
        $this->assertContains('criterion_failed:auto_merge_enabled', $r['disqualifiers']);
    }

    // ── AC2: fail-closed on human dependency ─────────────────────────────────

    public function test_criterion_with_operator_keyword_fails_closed(): void
    {
        $snap = $this->healthySnapshot();
        $snap['criteria_overrides'] = [['name' => 'requires_operator_approval', 'passing' => true]];
        $r = $this->compiler()->compile($snap);
        $this->assertFalse($r['is_soak_ready']);
        $this->assertNotNull($r['fail_closed_reason']);
        $this->assertStringContainsString('operator', $r['fail_closed_reason']);
        $this->assertContains(
            'criterion_requires_human_dependency:requires_operator_approval',
            $r['disqualifiers']
        );
    }

    public function test_criterion_with_manual_keyword_fails_closed(): void
    {
        $snap = $this->healthySnapshot();
        $snap['criteria_overrides'] = [['name' => 'manual_review_gate', 'passing' => true]];
        $r = $this->compiler()->compile($snap);
        $this->assertFalse($r['is_soak_ready']);
        $this->assertNotNull($r['fail_closed_reason']);
    }

    public function test_criterion_with_human_keyword_fails_closed(): void
    {
        $snap = $this->healthySnapshot();
        $snap['criteria_overrides'] = [['name' => 'human_sign_off', 'passing' => true]];
        $r = $this->compiler()->compile($snap);
        $this->assertFalse($r['is_soak_ready']);
        $this->assertNotNull($r['fail_closed_reason']);
    }

    public function test_criterion_with_requires_human_flag_fails_closed(): void
    {
        $snap = $this->healthySnapshot();
        $snap['criteria_overrides'] = [['name' => 'custom_gate', 'passing' => true, 'requires_human' => true]];
        $r = $this->compiler()->compile($snap);
        $this->assertFalse($r['is_soak_ready']);
        $this->assertNotNull($r['fail_closed_reason']);
    }

    public function test_non_human_custom_criterion_does_not_trigger_fail_closed(): void
    {
        $snap = $this->healthySnapshot();
        $snap['criteria_overrides'] = [['name' => 'all_tests_green', 'passing' => true]];
        $r = $this->compiler()->compile($snap);
        $this->assertTrue($r['is_soak_ready']);
        $this->assertNull($r['fail_closed_reason']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $snap = $this->healthySnapshot();
        $a = $this->compiler()->compile($snap);
        $b = $this->compiler()->compile($snap);
        $this->assertSame(json_encode($a), json_encode($b));
    }

    // AC: compiled plans include explicit soak windows
    public function test_soak_windows_include_all_five_windows(): void
    {
        $result = $this->compiler()->compile($this->healthySnapshot());
        $this->assertArrayHasKey('soak_windows', $result);
        $windows = array_column($result['soak_windows'], 'window');
        $this->assertContains('worker_feed_continuity', $windows);
        $this->assertContains('queue_drain_pressure', $windows);
        $this->assertContains('give_back_rate', $windows);
        $this->assertContains('lease_recovery', $windows);
        $this->assertContains('knowledge_sync', $windows);
    }

    // AC: each soak window carries runnable verification command
    public function test_each_soak_window_has_verification_command(): void
    {
        $result = $this->compiler()->compile($this->healthySnapshot());
        foreach ($result['soak_windows'] as $window) {
            $this->assertArrayHasKey('verification_command', $window);
            $this->assertNotEmpty($window['verification_command']);
        }
    }

    // AC: human-dependent plan steps are blocked in soak windows
    public function test_soak_windows_blocked_by_human_dependency(): void
    {
        $snap = $this->healthySnapshot();
        $snap['criteria_overrides'] = [
            ['name' => 'requires_human_approval', 'passing' => true],
        ];
        $result = $this->compiler()->compile($snap);
        foreach ($result['soak_windows'] as $window) {
            $this->assertNotEmpty($window['blocked_by']);
        }
    }

    // AC: healthy soak windows have no blockers
    public function test_healthy_soak_windows_have_no_blockers(): void
    {
        $result = $this->compiler()->compile($this->healthySnapshot());
        foreach ($result['soak_windows'] as $window) {
            $this->assertSame([], $window['blocked_by']);
        }
    }

    // ── AC: any soak criterion containing human, operator, manual, external_provider or approval dependency fails ──

    public function test_external_provider_keyword_fails_closed(): void
    {
        $snap = $this->healthySnapshot();
        $snap['criteria_overrides'] = [['name' => 'external_provider_validation', 'passing' => true]];
        $r = $this->compiler()->compile($snap);

        $this->assertFalse($r['is_soak_ready']);
        $this->assertNotNull($r['fail_closed_reason']);
    }

    public function test_approval_keyword_fails_closed(): void
    {
        $snap = $this->healthySnapshot();
        $snap['criteria_overrides'] = [['name' => 'requires_approval_from_manager', 'passing' => true]];
        $r = $this->compiler()->compile($snap);

        $this->assertFalse($r['is_soak_ready']);
        $this->assertNotNull($r['fail_closed_reason']);
    }

    // ── AC: long soak plans include minimum_hours and minimum_cycles based on risk tier ──

    public function test_long_soak_plan_includes_minimum_hours_and_cycles(): void
    {
        $r = $this->compiler()->compile($this->unhealthySnapshot());

        $this->assertSame(72.0, $r['soak_duration_hours']);
        $this->assertSame(200, $r['required_green_streak_tasks']);
    }

    public function test_medium_soak_plan_for_warnings(): void
    {
        $snap = $this->healthySnapshot();
        $snap['criteria_overrides'] = [['name' => 'custom_check', 'passing' => false]];
        $r = $this->compiler()->compile($snap);

        $this->assertSame(72.0, $r['soak_duration_hours']);
        $this->assertSame(200, $r['required_green_streak_tasks']);
    }

    // ── AC: all generated criteria include requires_human=false when compile returns soak_ready=true ──

    public function test_all_criteria_have_requires_human_false_when_soak_ready(): void
    {
        $r = $this->compiler()->compile($this->healthySnapshot());

        $this->assertTrue($r['is_soak_ready']);
        foreach ($r['steady_state_criteria'] as $criterion) {
            $this->assertFalse($criterion['requires_human'], "Criterion {$criterion['criterion']} should have requires_human=false");
        }
    }
}
