<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainUnifiedControlPlaneSnapshot;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasExternalBrainUnifiedControlPlaneCommandTest extends TestCase
{
    private function callCommand(array $opts = []): array
    {
        Artisan::call('atlas:external-brain:unified-control-plane', $opts);
        $raw = trim(Artisan::output());
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "Command output is not valid JSON:\n{$raw}");

        return $decoded;
    }

    public function test_default_options_emit_a_full_snapshot_and_succeed(): void
    {
        $result = $this->callCommand();

        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::SCHEMA, $result['schema']);
        foreach (['status', 'top_risks', 'next_decision', 'recommended_batch_theme', 'ranked_focus', 'stop_go_verdict', 'evidence_freshness_status', 'integration_coverage_status'] as $key) {
            $this->assertArrayHasKey($key, $result, "missing key: {$key}");
        }
        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::STATUS_GREEN, $result['status']);
        $this->assertSame(0, Artisan::call('atlas:external-brain:unified-control-plane'));
    }

    public function test_high_give_back_rate_produces_stop_verdict_and_nonzero_exit_code(): void
    {
        $exitCode = Artisan::call('atlas:external-brain:unified-control-plane', [
            '--give-back-rate' => '0.35',
        ]);
        $result = json_decode(trim(Artisan::output()), true);

        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::VERDICT_STOP, $result['stop_go_verdict']);
        $this->assertNotSame(0, $exitCode);
    }

    public function test_stale_evidence_fails_closed_instead_of_claiming_green(): void
    {
        $exitCode = Artisan::call('atlas:external-brain:unified-control-plane', [
            '--evidence-age-hours' => '200',
        ]);
        $result = json_decode(trim(Artisan::output()), true);

        $this->assertSame('stale', $result['evidence_freshness_status']);
        $this->assertNotSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::STATUS_GREEN, $result['status']);
        $this->assertNotSame(0, $exitCode);
    }

    public function test_weak_integration_coverage_fails_closed_instead_of_claiming_green(): void
    {
        $exitCode = Artisan::call('atlas:external-brain:unified-control-plane', [
            '--integration-coverage-percent' => '10',
        ]);
        $result = json_decode(trim(Artisan::output()), true);

        $this->assertSame('weak', $result['integration_coverage_status']);
        $this->assertNotSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::STATUS_GREEN, $result['status']);
        $this->assertNotSame(0, $exitCode);
    }

    public function test_maturity_gaps_route_to_create_more_tasks_decision(): void
    {
        $result = $this->callCommand(['--maturity-gap-count' => '5']);

        $this->assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::DECISION_CREATE, $result['next_decision']);
    }

    public function test_task_value_degrading_flag_prevents_green_status(): void
    {
        $result = $this->callCommand(['--task-value-degrading' => true]);

        $this->assertNotSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::STATUS_GREEN, $result['status']);
    }
}
