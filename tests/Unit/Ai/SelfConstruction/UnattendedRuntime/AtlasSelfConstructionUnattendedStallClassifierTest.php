<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\UnattendedRuntime;

use App\Services\Ai\SelfConstruction\UnattendedRuntime\AtlasSelfConstructionUnattendedLivenessSnapshot;
use App\Services\Ai\SelfConstruction\UnattendedRuntime\AtlasSelfConstructionUnattendedStallClassifier;
use Tests\TestCase;

class AtlasSelfConstructionUnattendedStallClassifierTest extends TestCase
{
    private function healthySnapshot(): array
    {
        return (new AtlasSelfConstructionUnattendedLivenessSnapshot)->compose([
            'queue' => ['depth' => 5, 'claimable_count' => 3, 'malformed_count' => 0, 'safety_stop' => false],
            'heartbeat' => ['last_seen_age_seconds' => 5, 'stale_threshold_seconds' => 120],
            'active_leases' => [],
            'continuous_runtime_cycle' => ['last_cycle_id' => 'c', 'last_stop_reason' => '', 'last_stopped' => false],
            'native_worker' => ['ready' => true, 'concurrency_floor_ok' => true],
            'replenisher' => ['last_run_status' => 'ok', 'last_run_age_seconds' => 30],
            'verification' => ['last_verdict' => 'verified', 'failed_run_count' => 0],
            'merge' => ['last_decision' => 'request_merge', 'blocked' => false],
        ]);
    }

    private function withOverride(array $override): array
    {
        return (new AtlasSelfConstructionUnattendedLivenessSnapshot)->compose(array_replace_recursive([
            'queue' => ['depth' => 5, 'claimable_count' => 3, 'malformed_count' => 0, 'safety_stop' => false],
            'heartbeat' => ['last_seen_age_seconds' => 5, 'stale_threshold_seconds' => 120],
            'active_leases' => [],
            'continuous_runtime_cycle' => ['last_cycle_id' => 'c', 'last_stop_reason' => '', 'last_stopped' => false],
            'native_worker' => ['ready' => true, 'concurrency_floor_ok' => true],
            'replenisher' => ['last_run_status' => 'ok', 'last_run_age_seconds' => 30],
            'verification' => ['last_verdict' => 'verified', 'failed_run_count' => 0],
            'merge' => ['last_decision' => 'request_merge', 'blocked' => false],
        ], $override));
    }

    public function test_healthy_classification(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedStallClassifier)->classify($this->healthySnapshot());

        self::assertSame(AtlasSelfConstructionUnattendedStallClassifier::HEALTHY, $verdict['classification']);
        self::assertSame(AtlasSelfConstructionUnattendedStallClassifier::SEVERITY_NONE, $verdict['severity']);
        self::assertFalse($verdict['recovery_needed']);
    }

    public function test_unsafe_stop_has_critical_severity(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedStallClassifier)->classify(
            $this->withOverride(['queue' => ['safety_stop' => true]]),
        );

        self::assertSame(AtlasSelfConstructionUnattendedStallClassifier::UNSAFE_STOP, $verdict['classification']);
        self::assertSame(AtlasSelfConstructionUnattendedStallClassifier::SEVERITY_CRITICAL, $verdict['severity']);
        self::assertTrue($verdict['recovery_needed']);
    }

    public function test_heartbeat_stale_classification(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedStallClassifier)->classify(
            $this->withOverride(['heartbeat' => ['last_seen_age_seconds' => 9999]]),
        );

        self::assertSame(AtlasSelfConstructionUnattendedStallClassifier::HEARTBEAT_STALE, $verdict['classification']);
    }

    public function test_merge_blocked_classification(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedStallClassifier)->classify(
            $this->withOverride(['merge' => ['blocked' => true]]),
        );

        self::assertSame(AtlasSelfConstructionUnattendedStallClassifier::MERGE_BLOCKED, $verdict['classification']);
    }

    public function test_verification_blocked_classification(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedStallClassifier)->classify(
            $this->withOverride(['verification' => ['failed_run_count' => 2]]),
        );

        self::assertSame(AtlasSelfConstructionUnattendedStallClassifier::VERIFICATION_BLOCKED, $verdict['classification']);
    }

    public function test_replenisher_blocked_classification(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedStallClassifier)->classify(
            $this->withOverride(['replenisher' => ['last_run_status' => 'blocked']]),
        );

        self::assertSame(AtlasSelfConstructionUnattendedStallClassifier::REPLENISHER_BLOCKED, $verdict['classification']);
    }

    public function test_worker_unavailable_classification(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedStallClassifier)->classify(
            $this->withOverride(['native_worker' => ['ready' => false]]),
        );

        self::assertSame(AtlasSelfConstructionUnattendedStallClassifier::WORKER_UNAVAILABLE, $verdict['classification']);
    }

    public function test_queue_dry_classification(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedStallClassifier)->classify(
            $this->withOverride(['queue' => ['depth' => 0, 'claimable_count' => 0]]),
        );

        self::assertSame(AtlasSelfConstructionUnattendedStallClassifier::QUEUE_DRY, $verdict['classification']);
    }

    public function test_waiting_on_dependencies_classification(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedStallClassifier)->classify(
            $this->withOverride(['queue' => ['depth' => 5, 'claimable_count' => 0]]),
        );

        self::assertSame(AtlasSelfConstructionUnattendedStallClassifier::WAITING_ON_DEPENDENCIES, $verdict['classification']);
    }

    public function test_classifier_hash_is_stable_for_identical_input(): void
    {
        $classifier = new AtlasSelfConstructionUnattendedStallClassifier();
        $snap = $this->healthySnapshot();
        $a = $classifier->classify($snap);
        $b = $classifier->classify($snap);

        self::assertSame($a['classifier_hash'], $b['classifier_hash']);
        self::assertStringStartsWith('classifier_', $a['classifier_hash']);
    }

    public function test_severity_is_a_label_not_a_number(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedStallClassifier)->classify($this->healthySnapshot());
        self::assertIsString($verdict['severity']);
        self::assertArrayNotHasKey('score', $verdict);
    }
}
