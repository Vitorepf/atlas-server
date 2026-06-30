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
}
