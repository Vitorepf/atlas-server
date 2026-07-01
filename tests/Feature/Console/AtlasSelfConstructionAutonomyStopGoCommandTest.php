<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Ai\SelfConstruction\Autonomy\AtlasSelfConstructionAutonomyStopGoGovernor;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasSelfConstructionAutonomyStopGoCommandTest extends TestCase
{
    private function callCommand(array $opts = []): array
    {
        Artisan::call('atlas:self-construction:autonomy-stop-go', $opts);
        $raw = trim(Artisan::output());
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "Command output is not valid JSON:\n{$raw}");

        return $decoded;
    }

    public function test_default_options_pause_when_no_expansion_signal(): void
    {
        $result = $this->callCommand();

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::DECISION_PAUSE, $result['decision']);
        $this->assertSame(0, Artisan::call('atlas:self-construction:autonomy-stop-go'));
    }

    public function test_malformed_risk_self_heals_instead_of_creating_tasks(): void
    {
        $exitCode = Artisan::call('atlas:self-construction:autonomy-stop-go', [
            '--malformed-risk' => true,
            '--value-trend' => 'high',
        ]);
        $result = json_decode(trim(Artisan::output()), true);

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::DECISION_SELF_HEAL, $result['decision']);
        $this->assertSame(0, $exitCode);
    }

    public function test_dry_queue_replenishes_before_expanding(): void
    {
        $result = $this->callCommand([
            '--dry-queue' => true,
            '--value-trend' => 'high',
        ]);

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::DECISION_REPLENISH, $result['decision']);
    }

    public function test_healthy_high_value_with_capacity_calls_more_muscles(): void
    {
        $result = $this->callCommand([
            '--value-trend' => 'high',
            '--worker-capacity-available' => true,
        ]);

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::DECISION_CALL_MUSCLES, $result['decision']);
    }

    public function test_low_worker_floor_vetoes_call_muscles_into_repair_queue(): void
    {
        $result = $this->callCommand([
            '--value-trend' => 'high',
            '--worker-capacity-available' => true,
            '--claimable-per-active-worker' => '1.0',
        ]);

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::DECISION_GO_REPAIR_QUEUE, $result['decision']);
    }

    public function test_low_value_trend_consolidates(): void
    {
        $result = $this->callCommand(['--value-trend' => 'low']);

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::DECISION_CONSOLIDATE, $result['decision']);
    }

    public function test_give_back_repeated_self_heals(): void
    {
        $result = $this->callCommand(['--give-back-repeated' => true]);

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::DECISION_SELF_HEAL, $result['decision']);
    }
}
