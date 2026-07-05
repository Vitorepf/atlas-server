<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\SelfConstruction\Maestro\ProviderLearning\AtlasMaestroWorkerQualityScorecard;
use PHPUnit\Framework\TestCase;

/**
 * Proves that the Wilson score lower bound gates best_task_classes/avoid_task_classes
 * so small samples (e.g. 2/2 or 1/1) are NOT promoted/avoided until the evidence is
 * statistically confident (~95% confidence, z=1.96).
 *
 * BEFORE the Wilson gate:
 *   - 2/2 success → promoted to best (raw sr = 1.0 >= 0.75)
 *   - 1/1 failure → promoted to avoid (raw fr = 1.0 >= 0.50)
 *
 * AFTER the Wilson gate:
 *   - 2/2 success → NOT promoted (Wilson LB ~0.34 < 0.75)
 *   - 30/32 success → promoted (Wilson LB ~0.80 > 0.75)
 *   - 1/1 failure → NOT in avoid (Wilson LB ~0.34 < 0.50)
 *   - 12/15 failure → in avoid (Wilson LB ~0.55 > 0.50)
 */
final class AtlasMaestroWorkerQualityScorecardWilsonRoutingTest extends TestCase
{
    private AtlasMaestroWorkerQualityScorecard $scorecard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorecard = new AtlasMaestroWorkerQualityScorecard;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function event(string $clientId, string $event, array $overrides = []): array
    {
        return array_merge([
            'client_id'             => $clientId,
            'event'                 => $event,
            'task_class'            => 'default',
            'scope_size'            => 1,
            'cycle_time_seconds'    => 60,
            'has_required_evidence' => true,
        ], $overrides);
    }

    // ── Best class: small sample NOT promoted ─────────────────────────

    public function test_2_of_2_success_not_promoted_to_best(): void
    {
        // Before Wilson: 2/2 = 1.0 raw rate → best.
        // After Wilson:  Wilson LB ~0.34 < 0.75 → NOT best.
        $events = [
            $this->event('w1', 'success', ['task_class' => 'tiny_class']),
            $this->event('w1', 'success', ['task_class' => 'tiny_class']),
        ];

        $worker = $this->scorecard->score(['events' => $events])['workers'][0];

        $this->assertNotContains(
            'tiny_class',
            $worker['best_task_classes'],
            '2/2 sample must NOT be promoted to best_task_classes under Wilson gate',
        );
        // task_family_fit should still report the raw success rate (1.0).
        $this->assertSame(1.0, $worker['task_family_fit']['tiny_class']);
    }

    public function test_30_of_32_success_promoted_to_best(): void
    {
        // 30/32 = 93.75%. Wilson LB ~0.80 > 0.75 → promoted.
        $events = array_fill(0, 30, $this->event('w1', 'success', ['task_class' => 'big_class']));
        $events[] = $this->event('w1', 'failed_gate', ['task_class' => 'big_class']);
        $events[] = $this->event('w1', 'failed_gate', ['task_class' => 'big_class']);

        $worker = $this->scorecard->score(['events' => $events])['workers'][0];

        $this->assertContains(
            'big_class',
            $worker['best_task_classes'],
            '30/32 sample must be promoted under Wilson gate',
        );
    }

    // ── Avoid class: small sample NOT avoided ─────────────────────────

    public function test_1_of_1_failure_not_in_avoid(): void
    {
        // Before Wilson: 1/1 = 1.0 raw failure rate → avoid.
        // After Wilson:  Wilson LB ~0.34 < 0.50 → NOT in avoid.
        $events = [
            $this->event('w1', 'give_back', ['task_class' => 'single_fail_class']),
        ];

        $worker = $this->scorecard->score(['events' => $events])['workers'][0];

        $this->assertNotContains(
            'single_fail_class',
            $worker['avoid_task_classes'],
            '1/1 failure sample must NOT be placed in avoid_task_classes under Wilson gate',
        );
    }

    public function test_12_of_15_failure_in_avoid(): void
    {
        // 12/15 = 80% failure rate. Wilson LB ~0.55 > 0.50 → in avoid.
        $events = array_fill(0, 12, $this->event('w1', 'give_back', ['task_class' => 'frequent_fail_class']));
        for ($i = 0; $i < 3; $i++) {
            $events[] = $this->event('w1', 'success', ['task_class' => 'frequent_fail_class']);
        }

        $worker = $this->scorecard->score(['events' => $events])['workers'][0];

        $this->assertContains(
            'frequent_fail_class',
            $worker['avoid_task_classes'],
            '12/15 failure sample must be placed in avoid_task_classes under Wilson gate',
        );
    }

    // ── task_family_fit still reports raw rate ────────────────────────

    public function test_task_family_fit_reports_raw_rate_not_wilson_lower_bound(): void
    {
        // 3/4 = 75%. Raw rate 0.75. Wilson LB ~0.30.
        $events = [
            $this->event('w1', 'success', ['task_class' => 'mixed_class']),
            $this->event('w1', 'success', ['task_class' => 'mixed_class']),
            $this->event('w1', 'success', ['task_class' => 'mixed_class']),
            $this->event('w1', 'give_back', ['task_class' => 'mixed_class']),
        ];

        $worker = $this->scorecard->score(['events' => $events])['workers'][0];

        // task_family_fit must be the raw rate (0.75), not the Wilson LB (~0.30).
        $this->assertSame(0.75, $worker['task_family_fit']['mixed_class']);
        // The class should NOT be in best_task_classes (Wilson LB ~0.30 < 0.75).
        $this->assertNotContains('mixed_class', $worker['best_task_classes']);
    }

    // ── Deterministic ─────────────────────────────────────────────────

    public function test_wilson_routing_is_deterministic(): void
    {
        $events = array_fill(0, 30, $this->event('w1', 'success', ['task_class' => 'det_class']));
        $events[] = $this->event('w1', 'failed_gate', ['task_class' => 'det_class']);
        $events[] = $this->event('w1', 'failed_gate', ['task_class' => 'det_class']);

        $a = $this->scorecard->score(['events' => $events]);
        $b = $this->scorecard->score(['events' => $events]);

        $this->assertSame(
            $a['workers'][0]['best_task_classes'],
            $b['workers'][0]['best_task_classes'],
        );
    }
}
