<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Autonomy;

use App\Services\Ai\SelfConstruction\Autonomy\AtlasSelfConstructionAutonomyRegressionTaskEmitter;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionAutonomyRegressionTaskEmitterTest extends TestCase
{
    private AtlasSelfConstructionAutonomyRegressionTaskEmitter $emitter;

    protected function setUp(): void
    {
        $this->emitter = new AtlasSelfConstructionAutonomyRegressionTaskEmitter;
    }

    // ── AC: each regression emits a service-plus-test task ──

    public function test_health_regression_emits_task(): void
    {
        $result = $this->emitter->emit([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'regressions' => [
                ['kind' => 'health_not_healthy', 'reason' => 'health.healthy=false'],
            ],
        ]);

        $this->assertSame(1, $result['task_count']);
        $task = $result['tasks'][0];
        $this->assertSame('autonomy_health_repair', $task['task_family']);
        $this->assertCount(2, $task['allowed_files']);
        $this->assertStringContainsString('AtlasSelfConstructionAutonomyStopGoGovernor.php', $task['allowed_files'][0]);
        $this->assertStringContainsString('AtlasSelfConstructionAutonomyStopGoGovernorTest.php', $task['allowed_files'][1]);
    }

    public function test_lease_leak_regression_emits_feature_test_task(): void
    {
        $result = $this->emitter->emit([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'regressions' => [
                ['kind' => 'lease_leak_detected', 'reason' => 'leases mismatch'],
            ],
        ]);

        $task = $result['tasks'][0];
        $this->assertStringContainsString('AgentControlPlaneClaimLeaseRepository.php', $task['allowed_files'][0]);
        $this->assertStringContainsString('tests/Feature/Ai/AtlasTaskCoordinationHealthTest.php', $task['allowed_files'][1]);
    }

    // ── AC: duplicate regressions are deduplicated ──

    public function test_duplicate_regressions_are_deduplicated(): void
    {
        $result = $this->emitter->emit([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'regressions' => [
                ['kind' => 'queue_dry', 'reason' => 'dry'],
                ['kind' => 'queue_dry', 'reason' => 'still dry'],
            ],
        ]);

        $this->assertSame(1, $result['task_count']);
    }

    // ── AC: clean snapshots emit no tasks ──

    public function test_clean_snapshot_emits_no_tasks(): void
    {
        $result = $this->emitter->emit([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'regressions' => [],
        ]);

        $this->assertSame(0, $result['task_count']);
        $this->assertSame([], $result['tasks']);
    }

    public function test_unknown_regression_kind_is_ignored(): void
    {
        $result = $this->emitter->emit([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'regressions' => [
                ['kind' => 'unknown_regression', 'reason' => 'whatever'],
            ],
        ]);

        $this->assertSame(0, $result['task_count']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->emitter->emit([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'regressions' => [
                ['kind' => 'stale_proof_detected', 'reason' => 'proof older than 24h'],
            ],
        ]);

        $this->assertSame(AtlasSelfConstructionAutonomyRegressionTaskEmitter::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('tasks', $result);
        $this->assertArrayHasKey('task_count', $result);
        $this->assertArrayHasKey('regression_count', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = [
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'regressions' => [
                ['kind' => 'sensing_degraded', 'reason' => 'sensor stale'],
                ['kind' => 'worker_unavailable', 'reason' => 'worker offline'],
            ],
        ];

        $a = $this->emitter->emit($input);
        $b = $this->emitter->emit($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
