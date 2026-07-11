<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition;

use App\Models\AiForgeWorkPacketExecutionCycle;
use App\Models\AiRunOutcome;
use App\Services\Ai\Cognition\AtlasOperationalVolumeCheckService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\BootsCompoundingSchema;
use Tests\Concerns\CreatesForgeLongHorizonStateTable;
use Tests\TestCase;

/**
 * ACOS Excellence VOL-01 — volume-real check with pinned thresholds.
 *
 * Authority: docs/engineering-knowledge-base/atlas-acos-excellence-10-10-plan-v1.md §VOL-01
 */
class AtlasOperationalVolumeCheckServiceTest extends TestCase
{
    use BootsCompoundingSchema;
    use CreatesForgeLongHorizonStateTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCompoundingSchema();
        $this->createForgeLongHorizonStateTable();
    }

    public function test_pinned_thresholds_are_exposed(): void
    {
        $payload = app(AtlasOperationalVolumeCheckService::class)->check(
            CarbonImmutable::parse('2026-07-08T12:00:00Z'),
        );

        $this->assertSame(3, $payload['thresholds']['dev_runs_per_business_day_min']);
        $this->assertSame(5, $payload['thresholds']['forge_cycles_per_week_min']);
        $this->assertSame(
            AtlasOperationalVolumeCheckService::DEV_RUNS_PER_BUSINESS_DAY_MIN,
            AtlasOperationalVolumeCheckService::DEV_RUNS_PER_BUSINESS_DAY_MIN,
        );
        $this->assertSame(
            AtlasOperationalVolumeCheckService::FORGE_CYCLES_PER_WEEK_MIN,
            AtlasOperationalVolumeCheckService::FORGE_CYCLES_PER_WEEK_MIN,
        );
    }

    public function test_empty_window_fixture_triggers_janela_faminta_alert(): void
    {
        // Wednesday 2026-07-08 12:00 UTC → dev window = Tuesday 2026-07-07; forge = rolling 7d ending 2026-07-07.
        $asOf = CarbonImmutable::parse('2026-07-08T12:00:00Z');

        $payload = app(AtlasOperationalVolumeCheckService::class)->check($asOf);

        $this->assertTrue($payload['alert']);
        $this->assertSame('janela_faminta', $payload['alert_code']);
        $this->assertSame('alert', $payload['status']);
        $this->assertTrue($payload['windows']['dev']['alert']);
        $this->assertTrue($payload['windows']['forge']['alert']);
        $this->assertSame(0, $payload['windows']['dev']['count']);
        $this->assertSame(0, $payload['windows']['forge']['count']);
    }

    public function test_healthy_when_thresholds_met(): void
    {
        $asOf = CarbonImmutable::parse('2026-07-08T12:00:00Z');
        $devDay = CarbonImmutable::parse('2026-07-07T10:00:00Z');
        $forgeDay = CarbonImmutable::parse('2026-07-06T10:00:00Z');

        for ($i = 0; $i < 3; $i++) {
            $this->seedDevOutcome($devDay->addMinutes($i * 5));
        }
        for ($i = 0; $i < 5; $i++) {
            $this->seedForgeCycle($forgeDay->addHours($i));
        }

        $payload = app(AtlasOperationalVolumeCheckService::class)->check($asOf);

        $this->assertFalse($payload['alert']);
        $this->assertSame('healthy', $payload['status']);
        $this->assertGreaterThanOrEqual(3, $payload['windows']['dev']['count']);
        $this->assertGreaterThanOrEqual(5, $payload['windows']['forge']['count']);
    }

    public function test_fail_open_when_sources_missing(): void
    {
        Schema::dropIfExists('ai_run_outcomes');
        Schema::dropIfExists('atlas_aemor_execution_episodes');
        Schema::dropIfExists('ai_forge_work_packet_execution_cycles');

        $payload = app(AtlasOperationalVolumeCheckService::class)->check(
            CarbonImmutable::parse('2026-07-08T12:00:00Z'),
        );

        $this->assertFalse($payload['alert']);
        $this->assertSame('skipped', $payload['status']);
    }

    public function test_command_json_emits_gap_hermes_prerequisite(): void
    {
        $payload = app(AtlasOperationalVolumeCheckService::class)->check(
            CarbonImmutable::parse('2026-07-08T12:00:00Z'),
        );

        $ids = array_column($payload['prerequisites'] ?? [], 'id');
        $this->assertContains(AtlasOperationalVolumeCheckService::PREREQUISITE_GAP_HERMES_01, $ids);
        $this->assertSame('named_prerequisite', $payload['prerequisites'][0]['status'] ?? null);
    }

    private function seedDevOutcome(CarbonImmutable $at): void
    {
        $id = (string) Str::uuid();
        AiRunOutcome::query()->insert([
            'id' => $id,
            'schema_version' => 'atlas.ai.compounding.outcome.v1',
            'run_id' => (string) Str::uuid(),
            'flow_id' => 'atlas_dev',
            'outcome_status' => 'passed',
            'flow_quality' => 80,
            'evidence_refs' => json_encode([]),
            'payload' => json_encode([]),
            'outcome_hash' => hash('sha256', 'dev-'.$at->toIso8601String().'-'.$id),
            'evaluated_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function seedForgeCycle(CarbonImmutable $at): void
    {
        $id = (string) Str::uuid();
        AiForgeWorkPacketExecutionCycle::query()->insert([
            'id' => $id,
            'schema_version' => 'atlas.forge.work_packet_execution_cycle.v1',
            'uuid' => (string) Str::uuid(),
            'intake_id' => (string) Str::uuid(),
            'work_packet_id' => (string) Str::uuid(),
            'work_packet_canonical_id' => 'wp-'.Str::random(8),
            'long_horizon_state_id' => (string) Str::uuid(),
            'cycle_position' => 1,
            'execution_mode' => 'live',
            'status' => 'completed',
            'execution_plan' => json_encode([]),
            'expected_artifacts' => json_encode([]),
            'evidence_refs' => json_encode(['forge:test']),
            'gate_result' => json_encode(['passed' => true]),
            'outcome_status' => 'success',
            'next_action' => json_encode(['continue']),
            'cycle_hash' => hash('sha256', 'forge-'.$at->toIso8601String().'-'.$id),
            'started_at' => $at,
            'completed_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
}
