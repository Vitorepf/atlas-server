<?php

namespace Tests\Unit\Ai\Kernel;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Slo\KernelSloAssessment;
use App\Services\Ai\Kernel\Slo\KernelSloProbe;
use App\Services\Ai\Kernel\Slo\KernelSloTarget;
use App\Services\Ai\Kernel\Slo\KernelSloTargets;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KernelSloTargetsTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_slo_targets_are_versioned_and_include_success_rate_contracts(): void
    {
        $targets = app(KernelSloTargets::class);
        $report = $targets->complianceReport();

        $this->assertTrue($report['ok'], implode("\n", $report['errors']));
        $this->assertSame('atlas.kernel.slo_target.v1', $report['schema_version']);
        $this->assertContains('envelope.create', $report['stages']);
        $this->assertContains('decide.issue', $report['stages']);
        $this->assertContains('ledger.append', $report['stages']);
        $this->assertContains('voice.wake_word_detect', $report['stages']);
        $this->assertContains('voice.turn_to_first_audio', $report['stages']);
        $this->assertContains('voice.interruption_stop_audio', $report['stages']);
        $this->assertContains('cognitive.productive_failure.problem_selection', $report['stages']);
        $this->assertContains('cognitive.productive_failure.phase_gate', $report['stages']);

        $decide = $targets->targetFor('decide.issue');

        $this->assertInstanceOf(KernelSloTarget::class, $decide);
        $this->assertSame(99.5, $decide->successRate);
        $this->assertSame('critical', $decide->severity);
        $this->assertSame('atlas.kernel.slo_target.v1', $decide->schemaVersion);

        $voice = $targets->targetFor('voice.turn_to_first_audio');

        $this->assertInstanceOf(KernelSloTarget::class, $voice);
        $this->assertSame(600, $voice->p95Ms);
        $this->assertSame(1200, $voice->p99Ms);
        $this->assertSame('critical', $voice->severity);

        $interruption = $targets->targetFor('voice.interruption_stop_audio');

        $this->assertInstanceOf(KernelSloTarget::class, $interruption);
        $this->assertSame(250, $interruption->p95Ms);
        $this->assertSame(500, $interruption->p99Ms);
    }

    public function test_slo_assessment_marks_ok_warning_and_breach_deterministically(): void
    {
        $targets = app(KernelSloTargets::class);

        $ok = $targets->assess('decide.issue', 100, true);
        $warning = $targets->assess('decide.issue', 500, true);
        $breach = $targets->assess('decide.issue', 1000, true);
        $failed = $targets->assess('decide.issue', 10, false);

        $this->assertInstanceOf(KernelSloAssessment::class, $ok);
        $this->assertTrue($ok->isOk());
        $this->assertSame('warning', $warning->status);
        $this->assertSame(['latency_above_p95'], $warning->violations);
        $this->assertSame('breach', $breach->status);
        $this->assertSame(['latency_above_p99'], $breach->violations);
        $this->assertSame('breach', $failed->status);
        $this->assertSame(['stage_failed'], $failed->violations);
        $this->assertSame('critical', $failed->severity);
    }

    public function test_unknown_slo_stage_is_a_contract_violation(): void
    {
        $assessment = app(KernelSloTargets::class)->assess('random.stage', 1, true);

        $this->assertFalse($assessment->isOk());
        $this->assertSame('unknown_stage', $assessment->status);
        $this->assertSame(['slo_stage_not_declared'], $assessment->violations);
        $this->assertNull($assessment->target);
    }

    public function test_slo_probe_records_observations_and_returns_callback_result(): void
    {
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();

        $result = app(KernelSloProbe::class)->measure('ledger.append', fn (): string => 'done', [
            'tenant_id' => 'tenant-probe',
            'operator_id' => 'operator-probe',
            'envelope_id' => 'env_probe',
            'domain' => 'programming',
            'flow' => 'programming.dev',
            'surface_id' => 'atlas_cli_dev',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.2',
        ]);

        $this->assertSame('done', $result);
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'SLO_OBSERVED',
            'emitter_stage' => 'atlas.slo',
            'tenant_id' => 'tenant-probe',
            'operator_id' => 'operator-probe',
            'envelope_id' => 'env_probe',
        ]);

        $event = AtlasLedgerEvent::query()->where('envelope_id', 'env_probe')->firstOrFail();
        $this->assertSame([
            'domain' => 'programming',
            'flow' => 'programming.dev',
            'model' => 'gpt-5.2',
            'provider' => 'codex_cli',
            'surface_id' => 'atlas_cli_dev',
        ], $event->payload['dimensions']);
    }

    public function test_slo_probe_records_failed_callback_and_rethrows(): void
    {
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();

        try {
            app(KernelSloProbe::class)->measure('decide.issue', fn () => throw new \RuntimeException('boom'), [
                'envelope_id' => 'env_probe_failed',
            ]);
            $this->fail('Expected exception was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'SLO_OBSERVED',
            'emitter_stage' => 'atlas.slo',
            'envelope_id' => 'env_probe_failed',
        ]);
    }
}
