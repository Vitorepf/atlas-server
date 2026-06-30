<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWorkClassPriorService;
use PHPUnit\Framework\TestCase;

/**
 * ACDE M1 — the work-class landing-rate prior. Exercises the PURE core (workClass token, aggregate over raw
 * rows, deprioritization weight) with no DB and no container, so it is hang-free and deterministic. Proves the
 * anti-gaming rule (only real provider attempts count), the Wilson-LB hopeless verdict (only with enough
 * samples), and that the weight is 0 unless hopeless.
 */
final class AtlasLoopWorkClassPriorServiceTest extends TestCase
{
    private function svc(): AtlasLoopWorkClassPriorService
    {
        return new AtlasLoopWorkClassPriorService;
    }

    private function attempt(bool $passed, int $tokens = 100, bool $providerInvoked = true): array
    {
        return ['passed' => $passed, 'tokens_used' => $tokens, 'provider_invoked' => $providerInvoked];
    }

    public function test_work_class_token_is_deterministic_path_family(): void
    {
        $svc = $this->svc();
        $this->assertSame('app/Services/Ai', $svc->workClass('app/Services/Ai/AutonomousEvolution/Foo.php'));
        $this->assertSame('app/Models', $svc->workClass('app/Models/Bar.php'));
        $this->assertSame('database/migrations', $svc->workClass('database/migrations/2026_x.php'));
        $this->assertSame('root', $svc->workClass('top.php'));
        $this->assertSame('unknown', $svc->workClass(''));
        // leading slash + backslashes normalize identically
        $this->assertSame('app/Services/Ai', $svc->workClass('/app\\Services\\Ai\\X\\Y.php'));
    }

    public function test_hopeless_class_only_with_enough_real_attempts_and_low_wilson(): void
    {
        // 10 real attempts in app/Services/Ai, ALL failed => landing rate 0 => Wilson-LB 0 < floor => hopeless.
        $rows = [];
        for ($i = 0; $i < 10; $i++) {
            $rows[] = ['target_path' => "app/Services/Ai/Loop/F{$i}.php", 'attempt_metrics' => [$this->attempt(false)]];
        }
        $byClass = $this->svc()->aggregate($rows, 10, 8, 0.15);

        $this->assertArrayHasKey('app/Services/Ai', $byClass);
        $prior = $byClass['app/Services/Ai'];
        $this->assertSame(10, $prior['real_attempts']);
        $this->assertSame(0, $prior['certified']);
        $this->assertTrue($prior['enough_samples']);
        $this->assertTrue($prior['hopeless'], 'ten real attempts, zero certs => hopeless');
        $this->assertGreaterThan(0.0, $this->svc()->deprioritizationWeight($prior));
    }

    public function test_thin_evidence_is_never_hopeless(): void
    {
        // Only 3 real attempts (< min 8) => not enough samples => never hopeless, even at 0% landing.
        $rows = [
            ['target_path' => 'app/X/A.php', 'attempt_metrics' => [$this->attempt(false), $this->attempt(false)]],
            ['target_path' => 'app/X/B.php', 'attempt_metrics' => [$this->attempt(false)]],
        ];
        $prior = $this->svc()->aggregate($rows, 10, 8, 0.15)['app/X'];

        $this->assertSame(3, $prior['real_attempts']);
        $this->assertFalse($prior['enough_samples']);
        $this->assertFalse($prior['hopeless']);
        $this->assertSame(0.0, $this->svc()->deprioritizationWeight($prior));
    }

    public function test_a_landing_class_is_not_hopeless(): void
    {
        // 10 attempts, 6 certs => healthy landing rate => Wilson-LB above floor => not hopeless.
        $rows = [];
        for ($i = 0; $i < 10; $i++) {
            $rows[] = ['target_path' => 'app/Healthy/F.php', 'attempt_metrics' => [$this->attempt($i < 6)]];
        }
        $prior = $this->svc()->aggregate($rows, 10, 8, 0.15)['app/Healthy'];

        $this->assertSame(6, $prior['certified']);
        $this->assertFalse($prior['hopeless'], 'a 60% landing class is not hopeless');
        $this->assertSame(0.0, $this->svc()->deprioritizationWeight($prior));
    }

    public function test_fabricated_rows_do_not_count_anti_gaming(): void
    {
        // 20 "passed" attempts but NONE invoked a provider / all sub-threshold tokens => zero real attempts.
        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $rows[] = ['target_path' => 'app/Fake/F.php', 'attempt_metrics' => [
                ['passed' => true, 'tokens_used' => 1, 'provider_invoked' => false],
            ]];
        }
        $byClass = $this->svc()->aggregate($rows, 10, 8, 0.15);

        $this->assertArrayNotHasKey('app/Fake', $byClass, 'fabricated/sub-threshold attempts contribute nothing');
    }

    public function test_attempt_metrics_accepts_json_string_payload(): void
    {
        $rows = [];
        for ($i = 0; $i < 8; $i++) {
            $rows[] = ['target_path' => 'app/Json/F.php', 'attempt_metrics' => json_encode([$this->attempt(false)])];
        }
        $prior = $this->svc()->aggregate($rows, 10, 8, 0.15)['app/Json'];

        $this->assertSame(8, $prior['real_attempts']);
        $this->assertTrue($prior['hopeless']);
    }

    public function test_aggregate_task_outcomes_counts_green_commits_as_real_and_certified(): void
    {
        $rows = [
            ['target_path' => 'app/Services/Ai/Foo.php', 'outcome' => 'success', 'tests_or_gates_result' => '5/5 green', 'worker_id' => 'claude-muscle-1'],
            ['target_path' => 'app/Services/Ai/Bar.php', 'outcome' => 'committed', 'tests_or_gates_result' => '3/3 green', 'worker_id' => 'claude-muscle-1'],
        ];
        $byClass = $this->svc()->aggregateTaskOutcomes($rows, 1, 0.15);

        $this->assertArrayHasKey('app/Services/Ai', $byClass);
        $prior = $byClass['app/Services/Ai'];
        $this->assertSame(2, $prior['real_attempts']);
        $this->assertSame(2, $prior['certified']);
        $this->assertSame(1.0, $prior['landing_rate']);
    }

    public function test_aggregate_task_outcomes_give_back_and_poison_count_real_not_certified(): void
    {
        $rows = [
            ['target_path' => 'app/Services/Ai/Foo.php', 'outcome' => 'give_back', 'tests_or_gates_result' => '', 'worker_id' => 'claude-muscle-1'],
            ['target_path' => 'app/Services/Ai/Bar.php', 'outcome' => 'poison', 'tests_or_gates_result' => '', 'worker_id' => 'claude-muscle-2'],
        ];
        $byClass = $this->svc()->aggregateTaskOutcomes($rows, 1, 0.15);

        $prior = $byClass['app/Services/Ai'];
        $this->assertSame(2, $prior['real_attempts']);
        $this->assertSame(0, $prior['certified']);
        $this->assertSame(0.0, $prior['landing_rate']);
    }

    public function test_aggregate_task_outcomes_fabricated_rows_are_silently_skipped(): void
    {
        $rows = [
            ['target_path' => 'app/Services/Ai/Foo.php', 'outcome' => 'success', 'tests_or_gates_result' => '5/5 green', 'worker_id' => ''],
            ['target_path' => 'app/Services/Ai/Bar.php', 'outcome' => 'success', 'tests_or_gates_result' => '', 'worker_id' => 'claude-muscle-1'],
            ['target_path' => 'app/Services/Ai/Baz.php', 'outcome' => 'pending', 'tests_or_gates_result' => '5/5 green', 'worker_id' => 'claude-muscle-1'],
        ];
        $byClass = $this->svc()->aggregateTaskOutcomes($rows, 1, 0.15);

        $this->assertSame([], $byClass, 'fabricated or unrecognised rows must produce an empty prior');
    }

    public function test_aggregate_task_outcomes_hopeless_class_from_give_backs(): void
    {
        $rows = [];
        for ($i = 0; $i < 8; $i++) {
            $rows[] = ['target_path' => "app/Services/Ai/F{$i}.php", 'outcome' => 'give_back', 'tests_or_gates_result' => '', 'worker_id' => 'w1'];
        }
        $byClass = $this->svc()->aggregateTaskOutcomes($rows, 8, 0.15);

        $prior = $byClass['app/Services/Ai'];
        $this->assertSame(8, $prior['real_attempts']);
        $this->assertSame(0, $prior['certified']);
        $this->assertTrue($prior['enough_samples']);
        $this->assertTrue($prior['hopeless']);
    }

    public function test_deprioritization_weight_scales_with_distance_below_floor(): void
    {
        $svc = $this->svc();
        $deep = ['hopeless' => true, 'wilson_lower' => 0.0, 'floor_rate' => 0.2];
        $shallow = ['hopeless' => true, 'wilson_lower' => 0.15, 'floor_rate' => 0.2];

        $this->assertSame(1.0, $svc->deprioritizationWeight($deep), 'wilson 0 => full weight');
        $this->assertEqualsWithDelta(0.25, $svc->deprioritizationWeight($shallow), 1e-9);
        $this->assertSame(0.0, $svc->deprioritizationWeight(['hopeless' => false]));
    }
}
