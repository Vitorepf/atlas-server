<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\ProviderLearning;

use App\Services\Ai\SelfConstruction\Maestro\ProviderLearning\AtlasMaestroWorkerQualityScorecard;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroWorkerQualityScorecardTest extends TestCase
{
    private AtlasMaestroWorkerQualityScorecard $scorecard;

    protected function setUp(): void
    {
        $this->scorecard = new AtlasMaestroWorkerQualityScorecard;
    }

    private function event(string $clientId, string $event, array $extra = []): array
    {
        return array_merge([
            'client_id'            => $clientId,
            'event'                => $event,
            'task_class'           => 'self_construction',
            'scope_size'           => 2,
            'cycle_time_seconds'   => 120,
            'has_required_evidence' => true,
        ], $extra);
    }

    // ── Schema and structure ─────────────────────────────────────────────────

    public function test_result_has_schema_and_workers_keys(): void
    {
        $result = $this->scorecard->score(['events' => [$this->event('w1', 'success')]]);

        $this->assertSame(AtlasMaestroWorkerQualityScorecard::SCHEMA, $result['schema']);
        $this->assertArrayHasKey('workers', $result);
        $this->assertCount(1, $result['workers']);
    }

    public function test_worker_entry_has_all_required_keys(): void
    {
        $result = $this->scorecard->score(['events' => [$this->event('w1', 'success')]]);
        $worker = $result['workers'][0];

        foreach (['client_id', 'quality_score', 'risk_flags', 'best_task_classes', 'avoid_task_classes', 'confidence', 'event_summary'] as $key) {
            $this->assertArrayHasKey($key, $worker, "Worker entry missing key: {$key}");
        }
    }

    // ── AC1: per-worker quality_score, risk_flags, best/avoid classes, confidence ──

    public function test_clean_worker_scores_high_with_no_risk_flags(): void
    {
        $events = array_fill(0, 10, $this->event('clean', 'success'));

        $worker = $this->scorecard->score(['events' => $events])['workers'][0];

        $this->assertSame(10.0, $worker['quality_score']);
        $this->assertSame([], $worker['risk_flags']);
        $this->assertSame('high', $worker['confidence']);
    }

    public function test_event_summary_counts_all_event_types(): void
    {
        $events = [
            $this->event('w1', 'success'),
            $this->event('w1', 'give_back'),
            $this->event('w1', 'retry'),
            $this->event('w1', 'failed_gate'),
            $this->event('w1', 'malformed'),
        ];

        $summary = $this->scorecard->score(['events' => $events])['workers'][0]['event_summary'];

        $this->assertSame(5, $summary['total']);
        $this->assertSame(1, $summary['success']);
        $this->assertSame(1, $summary['give_back']);
        $this->assertSame(1, $summary['retry']);
        $this->assertSame(1, $summary['failed_gate']);
        $this->assertSame(1, $summary['malformed']);
    }

    public function test_confidence_high_at_10_or_more_events(): void
    {
        $events = array_fill(0, 10, $this->event('w1', 'success'));
        $this->assertSame('high', $this->scorecard->score(['events' => $events])['workers'][0]['confidence']);
    }

    public function test_confidence_medium_at_5_to_9_events(): void
    {
        $events = array_fill(0, 5, $this->event('w1', 'success'));
        $this->assertSame('medium', $this->scorecard->score(['events' => $events])['workers'][0]['confidence']);
    }

    public function test_confidence_low_below_5_events(): void
    {
        $events = [$this->event('w1', 'success')];
        $this->assertSame('low', $this->scorecard->score(['events' => $events])['workers'][0]['confidence']);
    }

    public function test_best_task_class_when_success_rate_above_threshold(): void
    {
        $cls = 'impl_class';
        $events = array_fill(0, 8, $this->event('w1', 'success', ['task_class' => $cls]));
        $events[] = $this->event('w1', 'failed_gate', ['task_class' => $cls]);
        $events[] = $this->event('w1', 'failed_gate', ['task_class' => $cls]);

        $worker = $this->scorecard->score(['events' => $events])['workers'][0];
        // 8/10 = 80% success → best
        $this->assertContains($cls, $worker['best_task_classes']);
    }

    public function test_avoid_task_class_when_failure_rate_above_threshold(): void
    {
        $cls = 'hard_class';
        $events = [
            $this->event('w1', 'give_back', ['task_class' => $cls]),
            $this->event('w1', 'give_back', ['task_class' => $cls]),
            $this->event('w1', 'success',   ['task_class' => $cls]),
        ];
        // 2/3 fail → avoid
        $worker = $this->scorecard->score(['events' => $events])['workers'][0];
        $this->assertContains($cls, $worker['avoid_task_classes']);
    }

    // ── AC2: fake productivity penalized more than slow clean work ───────────

    public function test_success_without_evidence_penalizes_more_than_retry(): void
    {
        $withoutEvid = $this->scorecard->score(['events' => [
            $this->event('w1', 'success', ['has_required_evidence' => false]),
        ]])['workers'][0]['quality_score'];

        $withRetry = $this->scorecard->score(['events' => [
            $this->event('w2', 'retry'),
            $this->event('w2', 'retry'),
            $this->event('w2', 'retry'),
            $this->event('w2', 'success'),
        ]])['workers'][0]['quality_score'];

        $this->assertLessThan($withRetry, $withoutEvid, 'success without evidence must score lower than slow-but-clean retries');
    }

    public function test_high_giveback_rate_flags_and_penalizes(): void
    {
        $events = [
            $this->event('w1', 'give_back'),
            $this->event('w1', 'give_back'),
            $this->event('w1', 'give_back'),
            $this->event('w1', 'success'),
        ];
        // 3/4 = 75% give_back > 25% threshold

        $worker = $this->scorecard->score(['events' => $events])['workers'][0];

        $this->assertContains('high_giveback_rate', $worker['risk_flags']);
        $this->assertLessThan(10.0, $worker['quality_score']);
    }

    public function test_repeated_malformed_flags_and_penalizes(): void
    {
        $events = [
            $this->event('w1', 'malformed'),
            $this->event('w1', 'malformed'),
            $this->event('w1', 'success'),
        ];

        $worker = $this->scorecard->score(['events' => $events])['workers'][0];

        $this->assertContains('repeated_malformed', $worker['risk_flags']);
        $this->assertLessThan(10.0, $worker['quality_score']);
    }

    public function test_quality_score_clamped_between_0_and_10(): void
    {
        // Pile on lots of penalties.
        $events = array_fill(0, 5, $this->event('bad', 'success', ['has_required_evidence' => false]));
        foreach (range(1, 5) as $_) {
            $events[] = $this->event('bad', 'malformed');
            $events[] = $this->event('bad', 'give_back');
        }

        $score = $this->scorecard->score(['events' => $events])['workers'][0]['quality_score'];
        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(10.0, $score);
    }

    // ── AC3: success_without_evidence / repeated malformed route AWAY from the affected class ──

    public function test_success_without_evidence_routes_class_to_avoid_despite_many_successes(): void
    {
        $cls = 'fake_green_class';
        // 9 "successes" with no evidence + 1 real success — high raw success count, but fake.
        $events = array_fill(0, 9, $this->event('w1', 'success', ['task_class' => $cls, 'has_required_evidence' => false]));
        $events[] = $this->event('w1', 'success', ['task_class' => $cls]);

        $worker = $this->scorecard->score(['events' => $events])['workers'][0];

        $this->assertContains($cls, $worker['avoid_task_classes']);
        $this->assertNotContains($cls, $worker['best_task_classes']);
    }

    public function test_repeated_malformed_routes_affected_class_to_avoid(): void
    {
        $cls = 'malformed_class';
        $events = [
            $this->event('w1', 'malformed', ['task_class' => $cls]),
            $this->event('w1', 'malformed', ['task_class' => $cls]),
            $this->event('w1', 'malformed', ['task_class' => $cls]),
            $this->event('w1', 'success',   ['task_class' => $cls]),
        ];

        $worker = $this->scorecard->score(['events' => $events])['workers'][0];

        $this->assertContains($cls, $worker['avoid_task_classes']);
        $this->assertNotContains($cls, $worker['best_task_classes']);
    }

    // ── AC4: slow but evidence-complete worker retains acceptable quality/confidence ──

    public function test_slow_but_evidence_complete_worker_retains_high_confidence_and_quality(): void
    {
        $events = array_fill(0, 10, $this->event('slow_clean', 'success', ['cycle_time_seconds' => 900]));

        $worker = $this->scorecard->score(['events' => $events])['workers'][0];

        $this->assertSame(10.0, $worker['quality_score']);
        $this->assertSame('high', $worker['confidence']);
        $this->assertSame([], $worker['risk_flags']);
    }

    // ── AC5: best/avoid lists are deterministic and based on observed outcomes, not provider names ──

    public function test_best_avoid_classes_depend_on_outcomes_not_client_id(): void
    {
        $cls = 'neutral_class';
        $events = [
            $this->event('zzz_provider', 'success', ['task_class' => $cls]),
            $this->event('zzz_provider', 'success', ['task_class' => $cls]),
            $this->event('zzz_provider', 'success', ['task_class' => $cls]),
            $this->event('zzz_provider', 'success', ['task_class' => $cls]),
            $this->event('aaa_provider', 'give_back', ['task_class' => $cls]),
            $this->event('aaa_provider', 'give_back', ['task_class' => $cls]),
            $this->event('aaa_provider', 'success',   ['task_class' => $cls]),
        ];

        $worker = $this->scorecard->score(['events' => $events]);
        $byClient = array_column($worker['workers'], null, 'client_id');

        // zzz_provider: 4/4 success → best, despite alphabetically last.
        $this->assertContains($cls, $byClient['zzz_provider']['best_task_classes']);
        // aaa_provider: 2/3 fail → avoid, despite alphabetically first.
        $this->assertContains($cls, $byClient['aaa_provider']['avoid_task_classes']);
    }

    public function test_score_output_is_deterministic(): void
    {
        $events = [
            $this->event('w1', 'success'),
            $this->event('w1', 'give_back'),
        ];

        $a = $this->scorecard->score(['events' => $events]);
        $b = $this->scorecard->score(['events' => $events]);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_multiple_workers_are_each_scored_independently(): void
    {
        $events = [
            $this->event('alice', 'success'),
            $this->event('alice', 'success'),
            $this->event('bob',   'give_back'),
            $this->event('bob',   'give_back'),
            $this->event('bob',   'success'),
        ];

        $result  = $this->scorecard->score(['events' => $events]);
        $workers = array_column($result['workers'], null, 'client_id');

        $this->assertArrayHasKey('alice', $workers);
        $this->assertArrayHasKey('bob', $workers);
        $this->assertGreaterThan($workers['bob']['quality_score'], $workers['alice']['quality_score']);
    }

    // ── evidence_risk_flags: focused subset of risk_flags for trust-in-evidence routing ──

    public function test_worker_entry_has_evidence_risk_flags_key(): void
    {
        $result = $this->scorecard->score(['events' => [$this->event('w1', 'success')]]);
        $this->assertArrayHasKey('evidence_risk_flags', $result['workers'][0]);
    }

    public function test_clean_worker_has_empty_evidence_risk_flags(): void
    {
        $events = array_fill(0, 10, $this->event('clean', 'success'));
        $worker = $this->scorecard->score(['events' => $events])['workers'][0];

        $this->assertSame([], $worker['evidence_risk_flags']);
    }

    public function test_success_without_evidence_appears_in_evidence_risk_flags(): void
    {
        $worker = $this->scorecard->score(['events' => [
            $this->event('w1', 'success', ['has_required_evidence' => false]),
        ]])['workers'][0];

        $this->assertContains('success_without_evidence', $worker['evidence_risk_flags']);
    }

    public function test_repeated_malformed_appears_in_evidence_risk_flags(): void
    {
        $events = [
            $this->event('w1', 'malformed'),
            $this->event('w1', 'malformed'),
            $this->event('w1', 'success'),
        ];

        $worker = $this->scorecard->score(['events' => $events])['workers'][0];

        $this->assertContains('repeated_malformed', $worker['evidence_risk_flags']);
    }

    public function test_high_giveback_rate_does_not_appear_in_evidence_risk_flags(): void
    {
        // high_giveback_rate is a general behavioral signal, not an evidence-integrity signal.
        $events = [
            $this->event('w1', 'give_back'),
            $this->event('w1', 'give_back'),
            $this->event('w1', 'give_back'),
            $this->event('w1', 'success'),
        ];

        $worker = $this->scorecard->score(['events' => $events])['workers'][0];

        $this->assertContains('high_giveback_rate', $worker['risk_flags']);
        $this->assertNotContains('high_giveback_rate', $worker['evidence_risk_flags']);
    }

    public function test_evidence_risk_flags_is_always_a_subset_of_risk_flags(): void
    {
        $events = array_fill(0, 5, $this->event('bad', 'success', ['has_required_evidence' => false]));
        $events[] = $this->event('bad', 'malformed');
        $events[] = $this->event('bad', 'malformed');
        $events[] = $this->event('bad', 'give_back');
        $events[] = $this->event('bad', 'give_back');
        $events[] = $this->event('bad', 'give_back');

        $worker = $this->scorecard->score(['events' => $events])['workers'][0];

        foreach ($worker['evidence_risk_flags'] as $flag) {
            $this->assertContains($flag, $worker['risk_flags']);
        }
    }
}
