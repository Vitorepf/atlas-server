<?php

namespace Tests\Feature\Ai\ProgrammingRuntime\ControlPlane;

use App\Models\AiForgeIntake;
use App\Models\AiForgeWorkPacket;
use App\Models\AiMandatoryRagGate;
use App\Models\AiMission;
use App\Models\AiMissionCertification;
use App\Models\AiRepairLoop;
use App\Models\AiRunOutcome;
use App\Services\Ai\ProgrammingRuntime\ControlPlane\ProgrammingRuntimeControlPlaneCanon;
use App\Services\Ai\ProgrammingRuntime\ControlPlane\ProgrammingRuntimeControlPlaneService;
use App\Services\Ai\ProgrammingRuntime\Telemetry\ProgrammingRuntimeTelemetryRecorder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProgrammingRuntimeControlPlaneTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCompoundingSchema();
        $this->bootTelemetrySchema();
        $this->bootMissionFoundationSchema();
        $this->bootAutonomousEngineeringSchema();
        $this->bootForgeIntakeSchema();
    }

    protected function tearDown(): void
    {
        $this->dropForgeIntakeSchema();
        $this->dropAutonomousEngineeringSchema();
        $this->dropMissionFoundationSchema();
        Schema::dropIfExists('ai_programming_runtime_telemetry_events');
        $this->dropCompoundingSchema();
        parent::tearDown();
    }

    public function test_snapshot_returns_complete_canonical_shape(): void
    {
        $this->seedSampleState();

        $payload = app(ProgrammingRuntimeControlPlaneService::class)->snapshot();

        $this->assertSame(ProgrammingRuntimeControlPlaneCanon::SCHEMA_VERSION, $payload['schema_version']);
        foreach ([
            'schema_version',
            'generated_at',
            'runtime_status',
            'active_missions',
            'dev_runs_summary',
            'forge_obras_summary',
            'work_packets_summary',
            'rag_gate_summary',
            'repair_loop_summary',
            'telemetry_summary',
            'blockers',
            'evidence_completeness',
            'certification_summary',
            'next_actions',
            'benchmark_status',
            'claim_policy',
        ] as $key) {
            $this->assertArrayHasKey($key, $payload, "missing key {$key}");
        }
        $this->assertContains($payload['runtime_status'], [
            ProgrammingRuntimeControlPlaneCanon::STATUS_GREEN,
            ProgrammingRuntimeControlPlaneCanon::STATUS_PARTIAL,
            ProgrammingRuntimeControlPlaneCanon::STATUS_BLOCKED,
        ]);
    }

    public function test_snapshot_reports_dev_and_forge_summaries_independently(): void
    {
        $this->seedDevRun('run-passed', 'atlas_dev', 'passed');
        $this->seedDevRun('run-failed', 'atlas_debug', 'failed');
        $this->seedForgeIntake('obra-shipped', 'completed', 'r2');
        $this->seedForgeIntake('obra-running', 'in_progress', 'r3');

        $payload = app(ProgrammingRuntimeControlPlaneService::class)->snapshot();

        $devRuns = $payload['dev_runs_summary'];
        $this->assertTrue($devRuns['available']);
        $this->assertSame(2, $devRuns['count']);
        $this->assertSame('atlas_dev', $devRuns['core_label']);
        $this->assertArrayHasKey('passed', $devRuns['by_status']);
        $this->assertArrayHasKey('failed', $devRuns['by_status']);

        $forge = $payload['forge_obras_summary'];
        $this->assertTrue($forge['available']);
        $this->assertSame(2, $forge['count']);
        $this->assertSame(['completed' => 1, 'in_progress' => 1], $forge['by_status']);
        $this->assertSame(['r2' => 1, 'r3' => 1], $forge['by_risk_band']);
        $this->assertCount(2, $forge['recent']);
    }

    public function test_snapshot_surfaces_blockers_from_mission_and_rag_gate(): void
    {
        $this->seedMission('mission-blocked', 'blocked', 'definitely_blocked_for_review');
        $this->seedRagGate('failed_closed', 30, ['missing_doc_1', 'missing_doc_2']);

        $payload = app(ProgrammingRuntimeControlPlaneService::class)->snapshot();

        $blockers = $payload['blockers'];
        $this->assertGreaterThanOrEqual(2, $blockers['total']);
        $this->assertArrayHasKey('mission', $blockers['by_source']);
        $this->assertArrayHasKey('rag_gate', $blockers['by_source']);

        $sources = array_column($blockers['items'], 'source');
        $this->assertContains('mission', $sources);
        $this->assertContains('rag_gate', $sources);
        $this->assertSame(
            ProgrammingRuntimeControlPlaneCanon::STATUS_BLOCKED,
            $payload['runtime_status'],
        );
    }

    public function test_snapshot_emits_next_actions_with_benchmark_safety_action(): void
    {
        $this->seedRagGate('passed', 45);
        $this->seedRepairLoop('blocked_max_attempts');

        $payload = app(ProgrammingRuntimeControlPlaneService::class)->snapshot();

        $this->assertNotEmpty($payload['next_actions']);
        $sources = array_column($payload['next_actions'], 'source');
        $this->assertContains('rag_gate', $sources);
        $this->assertContains('repair_loop', $sources);
        $this->assertContains('benchmark', $sources);

        $benchmarkAction = collect($payload['next_actions'])
            ->firstWhere('source', 'benchmark');
        $this->assertNotNull($benchmarkAction);
        $this->assertSame('control_plane_will_never_auto_run_benchmark', $benchmarkAction['note']);
    }

    public function test_snapshot_marks_benchmark_status_not_run_and_blocks_superiority_claim(): void
    {
        $payload = app(ProgrammingRuntimeControlPlaneService::class)->snapshot();

        $this->assertTrue($payload['benchmark_status']['not_run']);
        $this->assertNull($payload['benchmark_status']['last_run_at']);
        $this->assertFalse($payload['benchmark_status']['rivals_compared']);
        $this->assertTrue($payload['benchmark_status']['requires_human_authorization']);
        $this->assertTrue($payload['claim_policy']['benchmark_not_run']);
        $this->assertFalse($payload['claim_policy']['allows_external_superiority_claim']);
    }

    public function test_snapshot_payload_carries_no_secrets(): void
    {
        $this->seedMission('mission-with-secret', 'in_progress', 'Operator pasted token=sk-live-AAAABBBBCCCC into prompt accidentally');

        app(ProgrammingRuntimeTelemetryRecorder::class)->record([
            'event_name' => 'runtime_record_completed',
            'flow' => 'atlas_dev',
            'selected_core' => 'atlas_dev',
            'run_id' => 'secret-check',
            'execution_status' => 'failed',
            'metadata' => [
                'api_key' => 'sk-live-ZZZZ-PRIVATE',
                'session_token' => 'st-1234-PRIVATE',
                'note' => 'No raw secret should survive aggregation.',
            ],
        ]);

        $payload = app(ProgrammingRuntimeControlPlaneService::class)->snapshot();
        $encoded = json_encode($payload);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('sk-live-ZZZZ-PRIVATE', $encoded);
        $this->assertStringNotContainsString('st-1234-PRIVATE', $encoded);
    }

    public function test_artisan_command_outputs_canonical_json(): void
    {
        $this->seedDevRun('run-cmd', 'atlas_dev', 'passed');

        $exitCode = Artisan::call('atlas:ai:programming-runtime-control-plane', ['--json' => true]);
        $output = Artisan::output();

        $this->assertContains($exitCode, [0, 1]); // 1 if blocked, 0 otherwise
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame(ProgrammingRuntimeControlPlaneCanon::SCHEMA_VERSION, $decoded['schema_version']);
        $this->assertArrayHasKey('runtime_status', $decoded);
        $this->assertArrayHasKey('benchmark_status', $decoded);
        $this->assertTrue($decoded['benchmark_status']['not_run']);
        $this->assertSame(1, $decoded['dev_runs_summary']['count']);
    }

    public function test_snapshot_is_stable_for_identical_state(): void
    {
        $this->seedDevRun('run-stable', 'atlas_dev', 'passed');
        $service = app(ProgrammingRuntimeControlPlaneService::class);

        $first = $service->snapshot();
        $second = $service->snapshot();

        // generated_at and timestamp fields differ; structural keys + by_* must match.
        $this->assertSame(array_keys($first), array_keys($second));
        $this->assertSame($first['dev_runs_summary']['count'], $second['dev_runs_summary']['count']);
        $this->assertSame($first['dev_runs_summary']['by_status'], $second['dev_runs_summary']['by_status']);
        $this->assertSame($first['benchmark_status'], $second['benchmark_status']);
        $this->assertSame($first['claim_policy'], $second['claim_policy']);
    }

    private function seedSampleState(): void
    {
        $this->seedMission('mission-sample', 'in_progress', null);
        $this->seedDevRun('run-sample', 'atlas_dev', 'passed');
        $this->seedForgeIntake('obra-sample', 'in_progress', 'r2');
        $this->seedForgeWorkPacket('packet-sample', 'pending', 'r2');
        $this->seedRagGate('passed', 78);
        $this->seedRepairLoop('completed');
        $this->seedMissionCertification('passed');
        app(ProgrammingRuntimeTelemetryRecorder::class)->record([
            'event_name' => 'flow_selected',
            'flow' => 'atlas_dev',
            'selected_core' => 'atlas_dev',
            'run_id' => 'tel-sample',
            'execution_status' => 'passed',
        ]);
    }

    private function seedMission(string $title, string $status, ?string $blocker): AiMission
    {
        return AiMission::query()->create([
            'schema_version' => 'atlas.ai.mission.v1',
            'uuid' => (string) Str::uuid(),
            'title' => $title,
            'raw_prompt' => 'control plane test prompt',
            'normalized_intent' => 'control plane test',
            'mission_type' => 'dev',
            'status' => $status,
            'autonomy_level' => 'supervised',
            'risk_level' => 'medium',
            'definition_of_done' => ['criteria' => []],
            'primary_domain' => 'programming',
            'current_step' => 'plan',
            'blocker_reason' => $blocker,
        ]);
    }

    private function seedDevRun(string $runId, string $flow, string $status): AiRunOutcome
    {
        return AiRunOutcome::query()->create([
            'schema_version' => 'atlas.ai.compounding.outcome.v1',
            'run_id' => $runId,
            'flow_id' => $flow,
            'outcome_status' => $status,
            'flow_quality' => 80,
            'retrieval_quality' => 75,
            'execution_quality' => $status === 'passed' ? 90 : 40,
            'evidence_quality' => $status === 'passed' ? 88 : 30,
            'learning_required' => $status !== 'passed',
            'evidence_refs' => ['receipt:'.$runId],
            'payload' => [],
            'outcome_hash' => hash('sha256', $runId),
            'evaluated_at' => now(),
        ]);
    }

    private function seedForgeIntake(string $title, string $status, string $riskBand): AiForgeIntake
    {
        return AiForgeIntake::query()->create([
            'schema_version' => 'atlas.ai.forge.intake.v1',
            'uuid' => (string) Str::uuid(),
            'origin' => 'control_plane_test',
            'recommended_forge_mode' => 'sdd_intake',
            'obra_title' => $title,
            'workspace_slug' => 'atlas-server',
            'original_user_intent' => 'test',
            'normalized_intent' => 'test',
            'scope_assessment' => 'narrow',
            'risk_assessment' => 'medium',
            'ambiguity_assessment' => 'low',
            'risk_band' => $riskBand,
            'definition_of_done' => ['criteria' => ['acceptance:test']],
            'required_evidence' => ['receipt:test'],
            'status' => $status,
            'intake_hash' => hash('sha256', $title.microtime(true)),
            'actor_type' => 'system',
        ]);
    }

    private function seedForgeWorkPacket(string $packetId, string $status, string $riskBand): AiForgeWorkPacket
    {
        $intake = AiForgeIntake::query()->first() ?? $this->seedForgeIntake('packet-host', 'in_progress', 'r2');

        return AiForgeWorkPacket::query()->create([
            'schema_version' => 'atlas.forge.work_packet.v1',
            'uuid' => (string) Str::uuid(),
            'intake_id' => $intake->id,
            'packet_position' => 1,
            'packet_id' => $packetId,
            'title' => 'Test packet',
            'objective' => 'Verify control plane',
            'scope' => 'test',
            'expected_files' => [],
            'dependencies' => [],
            'risks' => [],
            'acceptance_criteria' => ['must run'],
            'required_evidence' => [],
            'suggested_tests' => [],
            'status' => $status,
            'risk_band' => $riskBand,
            'role_slot' => 'engineer',
            'packet_hash' => hash('sha256', $packetId),
        ]);
    }

    private function seedRagGate(string $status, int $sufficiency, array $missed = []): AiMandatoryRagGate
    {
        return AiMandatoryRagGate::query()->create([
            'schema_version' => 'atlas.ai.rag.gate.v1',
            'gate_id' => 'gate-'.Str::random(8),
            'status' => $status,
            'retrieval_plan' => ['query' => 'control_plane'],
            'included_sources' => 5,
            'used_sources' => 4,
            'noise_sources' => 1,
            'missed_required_sources' => $missed,
            'context_sufficiency' => $sufficiency,
            'evidence_refs' => ['receipt:gate'],
            'context_pack_hash' => str_repeat('a', 64),
            'receipt' => ['hash' => 'gate-hash'],
            'receipt_hash' => hash('sha256', $status.$sufficiency.microtime(true)),
        ]);
    }

    private function seedRepairLoop(string $status): AiRepairLoop
    {
        return AiRepairLoop::query()->create([
            'schema_version' => 'atlas.ai.repair.loop.v1',
            'goal_record_id' => (string) Str::uuid(),
            'cycle_record_id' => (string) Str::uuid(),
            'repair_id' => 'repair-'.Str::random(8),
            'status' => $status,
            'failure_class' => 'evidence_missing',
            'failure' => ['code' => 'evidence_missing'],
            'repair_steps' => [['action' => 'noop']],
            'evidence_refs' => [],
            'receipt' => ['hash' => 'repair-hash'],
            'receipt_hash' => hash('sha256', $status.microtime(true)),
        ]);
    }

    private function seedMissionCertification(string $status): AiMissionCertification
    {
        $mission = AiMission::query()->first() ?? $this->seedMission('cert-host', 'in_progress', null);

        return AiMissionCertification::query()->create([
            'schema_version' => 'atlas.ai.mission_certification.v1',
            'uuid' => (string) Str::uuid(),
            'mission_id' => $mission->id,
            'status' => $status,
            'checked_requirements' => ['x' => 'y'],
            'missing_requirements' => [],
            'evidence_refs' => ['receipt:cert'],
            'certification_hash' => hash('sha256', $status),
            'certified_at' => now(),
        ]);
    }

    private function bootCompoundingSchema(): void
    {
        $this->dropCompoundingSchema();
        (require database_path('migrations/2026_05_17_180000_create_ai_compounding_engineering_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_05_19_030000_strengthen_rag_feedback_and_create_learning_proposals.php'))->up();
    }

    private function bootTelemetrySchema(): void
    {
        Schema::dropIfExists('ai_programming_runtime_telemetry_events');
        (require database_path('migrations/2026_05_19_040000_create_ai_programming_runtime_telemetry_events_table.php'))->up();
    }

    private function bootMissionFoundationSchema(): void
    {
        $this->dropMissionFoundationSchema();
        (require database_path('migrations/2026_05_17_900000_create_ai_mission_foundation_tables.php'))->up();
    }

    private function bootAutonomousEngineeringSchema(): void
    {
        $this->dropAutonomousEngineeringSchema();
        (require database_path('migrations/2026_05_17_220000_create_ai_autonomous_engineering_os_tables.php'))->up();
    }

    private function bootForgeIntakeSchema(): void
    {
        $this->dropForgeIntakeSchema();
        (require database_path('migrations/2026_05_18_060000_create_ai_forge_intake_tables.php'))->up();
    }

    private function dropCompoundingSchema(): void
    {
        foreach ([
            'ai_learning_proposals',
            'ai_temporal_certifications',
            'ai_benchmark_cases',
            'ai_rag_feedback_events',
            'ai_heuristic_updates',
            'ai_compounding_memories',
            'ai_learning_candidates',
            'ai_run_outcomes',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function dropMissionFoundationSchema(): void
    {
        foreach ([
            'ai_mission_certifications',
            'ai_mission_evidence_refs',
            'ai_mission_events',
            'ai_work_orders',
            'ai_objectives',
            'ai_missions',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function dropAutonomousEngineeringSchema(): void
    {
        foreach ([
            'ai_repair_loops',
            'ai_mandatory_rag_gates',
            'ai_execution_plans',
            'ai_engineering_control_plane_events',
            'ai_engineering_rivals_shadow_executions',
            'ai_engineering_certifications',
            'ai_codebase_world_model_edges',
            'ai_codebase_world_model_nodes',
            'ai_codebase_world_models',
            'ai_autonomous_work_steps',
            'ai_autonomous_work_cycles',
            'ai_autonomous_engineering_goals',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function dropForgeIntakeSchema(): void
    {
        foreach ([
            'ai_forge_work_packet_execution_cycles',
            'ai_forge_work_packets',
            'ai_forge_milestones',
            'ai_forge_multi_agent_schedules',
            'ai_forge_long_horizon_states',
            'ai_forge_intakes',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
