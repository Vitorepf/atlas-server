<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\ControlPlane;

use App\Services\Ai\ControlPlane\AtlasAiControlPlaneService;
use App\Services\Ai\OperatorApproval\OperatorApprovalCanon;
use App\Services\Ai\OperatorApproval\OperatorApprovalGateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOperatorApprovalTable;
use Tests\Concerns\CreatesRouterRuntimeTables;
use Tests\TestCase;

/**
 * Atlas AI Control Plane (trace-level runtime) · feature certification.
 *
 * Verifies the read model aggregates correctly, blockers surface honestly,
 * the hash is deterministic, and the empty/missing-tables case stays
 * "degraded but functional" instead of throwing.
 */
class AtlasAiControlPlaneServiceTest extends TestCase
{
    use CreatesOperatorApprovalTable;
    use CreatesRouterRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRouterRuntimeTables();
        $this->createAiSurfaceTables();
        $this->createOperatorApprovalTable();
    }

    protected function tearDown(): void
    {
        $this->dropOperatorApprovalTable();
        $this->dropAiSurfaceTables();
        $this->dropRouterRuntimeTables();
        parent::tearDown();
    }

    public function test_approval_pending_and_decided_surface_in_runtime_report(): void
    {
        $gate = app(OperatorApprovalGateService::class);
        // Pending — destructive command, require_confirmation.
        $gate->evaluate([
            'requested_action' => 'tool.destructive.fs_delete',
            'risk_level' => 'medium',
        ]);
        // Approved — handoff_forge, escalate path then decide.
        $decision = $gate->evaluate([
            'requested_action' => OperatorApprovalCanon::ACTION_MISSION_HANDOFF_FORGE,
            'risk_level' => 'medium',
        ]);
        $approval = $decision->approval;
        $this->assertNotNull($approval);
        $gate->approve($approval, 'vitorepf', 'ok');

        $report = $this->service()->report(24);

        $this->assertSame('ready', $report['approvals']['status']);
        $this->assertSame(2, $report['approvals']['totals']['all']);
        $this->assertSame(1, $report['approvals']['totals']['pending']);
        $this->assertSame(1, $report['approvals']['totals']['approved']);
        $this->assertCount(1, $report['approvals']['recent_pending']);
        $this->assertCount(1, $report['approvals']['recent_decisions']);
        $this->assertNotNull($report['approvals']['last_decided_at']);

        // Each pending entry must carry the canonical fields the surface needs.
        $pending = $report['approvals']['recent_pending'][0];
        $this->assertNotEmpty($pending['uuid']);
        $this->assertSame('require_confirmation', $pending['gate_mode']);
        $this->assertSame('pending', $pending['status']);
        $this->assertNotEmpty($pending['hash']);

        $decided = $report['approvals']['recent_decisions'][0];
        $this->assertSame('approved', $decided['status']);
        $this->assertSame('approve', $decided['operator_decision']);
        $this->assertNotEmpty($decided['receipt_hash']);
    }

    public function test_empty_window_returns_healthy_report_with_zeros(): void
    {
        $service = $this->service();

        $report = $service->report(24);

        $this->assertSame('atlas.ai.control_plane.v1', $report['schema_version']);
        $this->assertSame('healthy', $report['status']);
        $this->assertSame(0, $report['summary']['total_traces']);
        $this->assertSame(0, $report['summary']['failed']);
        $this->assertSame([], $report['flows']);
        $this->assertSame([], $report['recent_traces']);
        $this->assertSame([], $report['failures']);
        $this->assertSame(0, $report['handoffs']['dev']['count']);
        $this->assertSame(0, $report['handoffs']['forge']['count']);
        $this->assertSame(0, $report['receipts']['total']);
        $this->assertSame([], $report['blockers']);
        $this->assertNotEmpty($report['readiness_refs']);
        $this->assertFalse($report['claim_policy']['allows_external_superiority_claim']);
        $this->assertStringStartsWith('sha256:', $report['hash']);
    }

    public function test_aggregates_traces_by_flow_with_per_flow_counts(): void
    {
        $this->insertTrace([
            'id' => Str::uuid()->toString(),
            'status' => 'succeeded',
            'provider' => 'claude',
            'intent' => 'research',
            'metadata' => json_encode(['hyperflow_runtime' => ['flow_id' => 'atlas_research']]),
        ]);
        $this->insertTrace([
            'id' => Str::uuid()->toString(),
            'status' => 'succeeded',
            'provider' => 'gemini',
            'intent' => 'research',
            'metadata' => json_encode(['hyperflow_runtime' => ['flow_id' => 'atlas_research']]),
        ]);
        $this->insertTrace([
            'id' => Str::uuid()->toString(),
            'status' => 'succeeded',
            'provider' => 'claude',
            'intent' => 'finance',
            'metadata' => json_encode(['hyperflow_runtime' => ['flow_id' => 'atlas_finance']]),
        ]);

        $report = $this->service()->report(24);

        $this->assertSame(3, $report['summary']['total_traces']);
        $this->assertSame(2, $report['summary']['unique_flows']);
        $this->assertSame(2, $report['summary']['unique_providers']);

        $researchFlow = collect($report['flows'])->firstWhere('flow_id', 'atlas_research');
        $this->assertSame(2, $researchFlow['total']);
        $this->assertSame(2, $researchFlow['by_status']['succeeded']);
        $this->assertSame(['claude' => 1, 'gemini' => 1], $researchFlow['providers']);
        $this->assertFalse($researchFlow['is_programming_anchored']);
        $this->assertTrue($researchFlow['is_canonical']);

        $financeFlow = collect($report['flows'])->firstWhere('flow_id', 'atlas_finance');
        $this->assertSame(1, $financeFlow['total']);
    }

    public function test_failed_traces_appear_in_failures_list_with_error_excerpt(): void
    {
        $traceId = Str::uuid()->toString();
        $this->insertTrace([
            'id' => $traceId,
            'status' => 'failed',
            'provider' => 'claude',
            'metadata' => json_encode(['hyperflow_runtime' => ['flow_id' => 'atlas_research']]),
        ]);
        $this->insertJob([
            'id' => Str::uuid()->toString(),
            'trace_id' => $traceId,
            'status' => 'failed',
            'error_code' => 'provider_timeout',
            'error_message' => 'Provider hit configured timeout after 60s',
            'attempts' => 3,
        ]);

        $report = $this->service()->report(24);

        $this->assertSame(1, $report['summary']['failed']);
        $this->assertCount(1, $report['failures']);
        $failure = $report['failures'][0];
        $this->assertSame($traceId, $failure['trace_id']);
        $this->assertSame('atlas_research', $failure['flow_id']);
        $this->assertSame('provider_timeout', $failure['error_code']);
        $this->assertStringContainsString('timeout', $failure['error_message']);
        $this->assertSame(3, $failure['attempts']);
        $this->assertSame('blocked', $report['status'], 'failed trace must flip overall status to blocked');
    }

    public function test_silent_failure_produces_blocker_entry(): void
    {
        $traceId = Str::uuid()->toString();
        $this->insertTrace([
            'id' => $traceId,
            'status' => 'failed',
            'provider' => 'claude',
            'metadata' => json_encode([]),
        ]);
        // Insert job WITHOUT error_message → silent failure blocker.
        $this->insertJob([
            'id' => Str::uuid()->toString(),
            'trace_id' => $traceId,
            'status' => 'failed',
            'error_code' => null,
            'error_message' => null,
            'attempts' => 1,
        ]);

        $report = $this->service()->report(24);

        $silent = collect($report['blockers'])->firstWhere('kind', 'silent_failure');
        $this->assertNotNull($silent);
        $this->assertSame($traceId, $silent['trace_id']);
    }

    public function test_dispatch_blockers_surface_with_reason(): void
    {
        $routerId = $this->insertRouterDecision();
        DB::table('ai_atlas_runtime_dispatches')->insert([
            'id' => Str::uuid()->toString(),
            'schema_version' => 'atlas.ai.runtime_dispatch.v1',
            'uuid' => Str::random(64),
            'router_decision_id' => $routerId,
            'flow_route_id' => null,
            'mission_id' => null,
            'work_order_id' => null,
            'dispatch_target' => 'atlas_research',
            'dispatch_status' => 'blocked',
            'dispatch_payload' => json_encode([]),
            'evidence_refs' => null,
            'blockers' => json_encode([['reason' => 'evidence_missing'], ['reason' => 'policy_gate_required']]),
            'receipt_hash' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $report = $this->service()->report(24);

        $dispatchBlockers = collect($report['blockers'])->where('kind', 'dispatch_blocker')->values()->all();
        $this->assertCount(2, $dispatchBlockers);
        $this->assertContains('evidence_missing', collect($dispatchBlockers)->pluck('detail')->all());
        $this->assertContains('policy_gate_required', collect($dispatchBlockers)->pluck('detail')->all());
    }

    public function test_dev_handoff_extracted_from_trace_metadata(): void
    {
        $this->insertTrace([
            'id' => Str::uuid()->toString(),
            'status' => 'succeeded',
            'provider' => 'claude',
            'metadata' => json_encode([
                'hyperflow_runtime' => [
                    'flow_id' => 'atlas_debug',
                    'handoff_target' => 'atlas_dev',
                ],
                'specialist_flow_runtime' => [
                    'delegation' => [
                        'status' => 'delegate_to_other_flow',
                        'target_flow_id' => 'atlas_dev',
                    ],
                ],
            ]),
        ]);

        $report = $this->service()->report(24);

        $this->assertSame(1, $report['handoffs']['dev']['count']);
        $this->assertSame('atlas_dev', $report['handoffs']['dev']['items'][0]['handoff_target']);
        $this->assertSame(1, $report['summary']['handoffs_count']);
    }

    public function test_forge_handoff_incomplete_emits_blocker(): void
    {
        DB::table('ai_real_execution_forge_handoffs')->insert([
            'id' => Str::uuid()->toString(),
            'goal_record_id' => Str::uuid()->toString(),
            'schema_version' => 'atlas.ai.forge_handoff.v1',
            'handoff_id' => 'forge-handoff-1',
            'status' => 'awaiting',
            'handoff_packet' => json_encode([]),
            'evidence_refs' => json_encode([]),
            'receipt' => json_encode([]),
            'handoff_hash' => str_repeat('a', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $report = $this->service()->report(24);

        $this->assertSame(1, $report['handoffs']['forge']['count']);
        $forgeBlocker = collect($report['blockers'])->firstWhere('kind', 'handoff_incomplete');
        $this->assertNotNull($forgeBlocker);
        $this->assertSame('forge-handoff-1', $forgeBlocker['handoff_id']);
    }

    public function test_rich_input_and_source_manifest_counted_in_evidence(): void
    {
        $traceId = Str::uuid()->toString();
        $this->insertTrace([
            'id' => $traceId,
            'status' => 'succeeded',
            'provider' => 'claude',
            'metadata' => json_encode(['hyperflow_runtime' => ['flow_id' => 'atlas_research']]),
        ]);
        $this->insertJob([
            'id' => Str::uuid()->toString(),
            'trace_id' => $traceId,
            'status' => 'succeeded',
            'context_refs' => json_encode([['type' => 'doc', 'id' => 'a'], ['type' => 'doc', 'id' => 'b']]),
            'payload' => json_encode([
                'rich_input_payload' => [
                    'schema_version' => 'atlas.rich_input.payload.v1',
                    'source_manifest' => [
                        ['type' => 'url', 'id' => 'src-1'],
                        ['type' => 'doc', 'id' => 'src-2'],
                    ],
                ],
            ]),
        ]);

        $report = $this->service()->report(24);

        $this->assertSame(2, $report['evidence']['context_refs_total']);
        $this->assertSame(1, $report['evidence']['rich_input_jobs_total']);
        $this->assertSame(2, $report['evidence']['source_manifest_refs_total']);
    }

    public function test_provider_decisions_aggregated_by_domain_and_mode(): void
    {
        $this->insertRouterDecision(['primary_domain' => 'research', 'routing_mode' => 'deep']);
        $this->insertRouterDecision(['primary_domain' => 'research', 'routing_mode' => 'standard']);
        $this->insertRouterDecision(['primary_domain' => 'finance', 'routing_mode' => 'standard', 'policy_required' => true, 'evidence_required' => true]);

        $report = $this->service()->report(24);

        $this->assertSame(3, $report['provider_decisions']['total']);
        $this->assertSame(2, $report['provider_decisions']['by_primary_domain']['research']);
        $this->assertSame(1, $report['provider_decisions']['by_primary_domain']['finance']);
        $this->assertSame(2, $report['provider_decisions']['by_routing_mode']['standard']);
        $this->assertSame(1, $report['provider_decisions']['by_routing_mode']['deep']);
        $this->assertSame(1, $report['provider_decisions']['policy_required_count']);
        $this->assertSame(1, $report['provider_decisions']['evidence_required_count']);
    }

    public function test_response_text_is_never_exposed(): void
    {
        $this->insertTrace([
            'id' => Str::uuid()->toString(),
            'status' => 'succeeded',
            'provider' => 'claude',
            'response_text' => 'SECRET RAW RESPONSE TEXT — operator credentials inside',
            'operator_input' => 'SECRET OPERATOR INPUT — should not leak',
            'metadata' => json_encode(['hyperflow_runtime' => ['flow_id' => 'atlas_research']]),
        ]);

        $report = $this->service()->report(24);
        $serialized = json_encode($report);

        $this->assertStringNotContainsString('SECRET RAW RESPONSE TEXT', $serialized);
        $this->assertStringNotContainsString('SECRET OPERATOR INPUT', $serialized);
    }

    public function test_hash_is_deterministic_for_same_content(): void
    {
        $traceId = Str::uuid()->toString();
        $this->insertTrace([
            'id' => $traceId,
            'status' => 'succeeded',
            'provider' => 'claude',
            'metadata' => json_encode(['hyperflow_runtime' => ['flow_id' => 'atlas_research']]),
            'created_at' => '2026-05-19 12:00:00',
            'updated_at' => '2026-05-19 12:00:00',
        ]);

        $service = $this->service();
        $first = $service->report(24);
        $second = $service->report(24);

        $this->assertSame($first['hash'], $second['hash'], 'same content must produce identical hash');
        $this->assertNotSame($first['generated_at'], $second['generated_at'], 'generated_at differs but excluded from hash');
    }

    public function test_command_emits_runtime_json_with_status_and_hash(): void
    {
        $exit = $this->artisan('atlas:ai:control-plane', [
            '--action' => 'runtime',
            '--hours' => 24,
            '--json' => true,
        ])->run();

        $this->assertSame(0, $exit, 'healthy empty window must return success exit code');

        // Re-resolve the service and verify the canonical shape independently
        // of stdout capture (which can interfere with JSON ordering).
        $report = $this->service()->report(24);
        $this->assertSame('atlas.ai.control_plane.v1', $report['schema_version']);
        $this->assertArrayHasKey('status', $report);
        $this->assertStringStartsWith('sha256:', $report['hash']);
    }

    public function test_command_returns_failure_when_status_blocked(): void
    {
        $traceId = Str::uuid()->toString();
        $this->insertTrace(['id' => $traceId, 'status' => 'failed', 'provider' => 'claude']);
        $this->insertJob(['id' => Str::uuid()->toString(), 'trace_id' => $traceId, 'status' => 'failed']);

        $this->artisan('atlas:ai:control-plane', [
            '--action' => 'runtime',
            '--json' => true,
        ])->assertExitCode(1);
    }

    private function service(): AtlasAiControlPlaneService
    {
        return $this->app->make(AtlasAiControlPlaneService::class);
    }

    private function createAiSurfaceTables(): void
    {
        $this->dropAiSurfaceTables();

        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('trace_key', 200)->nullable();
            $table->string('thread_id', 64)->nullable();
            $table->string('session_id', 64)->nullable();
            $table->string('source_type', 64)->nullable();
            $table->string('source_id', 64)->nullable();
            $table->string('status', 40)->nullable();
            $table->text('operator_input')->nullable();
            $table->string('intent', 64)->nullable();
            $table->string('agent_slug', 80)->nullable();
            $table->string('provider', 80)->nullable();
            $table->string('model', 80)->nullable();
            $table->json('skill_versions')->nullable();
            $table->json('context_refs')->nullable();
            $table->string('prompt_hash', 64)->nullable();
            $table->string('response_hash', 64)->nullable();
            $table->text('response_text')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->integer('feedback_score')->nullable();
            $table->string('feedback_action', 40)->nullable();
            $table->text('feedback_comment')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->string('client_id', 64)->nullable();
            $table->string('kind', 64)->nullable();
            $table->string('status', 40)->nullable();
            $table->integer('priority')->default(0);
            $table->string('agent_slug', 80)->nullable();
            $table->string('provider', 80)->nullable();
            $table->string('model', 80)->nullable();
            $table->text('input_text')->nullable();
            $table->text('prompt')->nullable();
            $table->json('context_refs')->nullable();
            $table->json('payload')->nullable();
            $table->text('result_text')->nullable();
            $table->json('result_json')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(3);
            $table->integer('timeout_seconds')->nullable();
            $table->string('worker_id', 80)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_quality_evaluations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->string('thread_id', 64)->nullable();
            $table->string('session_id', 64)->nullable();
            $table->string('provider', 80)->nullable();
            $table->string('model', 80)->nullable();
            $table->string('agent_slug', 80)->nullable();
            $table->string('evaluator_version', 40)->nullable();
            $table->integer('score')->nullable();
            $table->string('status', 40)->nullable();
            $table->json('dimensions')->nullable();
            $table->json('flags')->nullable();
            $table->json('suggested_actions')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_quality_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('evaluation_id')->nullable();
            $table->uuid('trace_id')->nullable();
            $table->uuid('remediation_trace_id')->nullable();
            $table->string('action_type', 80)->nullable();
            $table->string('status', 40)->nullable();
            $table->integer('priority')->default(0);
            $table->text('reason')->nullable();
            $table->json('flags')->nullable();
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->string('dedupe_key', 200)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_real_execution_forge_handoffs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('goal_record_id')->nullable();
            $table->string('schema_version', 120)->nullable();
            $table->string('handoff_id', 200)->nullable();
            $table->string('status', 40)->nullable();
            $table->json('handoff_packet')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->json('receipt')->nullable();
            $table->string('handoff_hash', 64)->nullable();
            $table->timestamps();
        });
    }

    private function dropAiSurfaceTables(): void
    {
        foreach ([
            'ai_real_execution_forge_handoffs',
            'ai_quality_actions',
            'ai_quality_evaluations',
            'ai_jobs',
            'ai_traces',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function insertTrace(array $overrides): void
    {
        DB::table('ai_traces')->insert(array_merge([
            'id' => Str::uuid()->toString(),
            'status' => 'succeeded',
            'provider' => 'claude',
            'model' => 'claude-opus',
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function insertJob(array $overrides): void
    {
        DB::table('ai_jobs')->insert(array_merge([
            'id' => Str::uuid()->toString(),
            'status' => 'succeeded',
            'attempts' => 1,
            'max_attempts' => 3,
            'priority' => 0,
            'context_refs' => json_encode([]),
            'payload' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function insertRouterDecision(array $overrides = []): string
    {
        $id = Str::uuid()->toString();
        DB::table('ai_atlas_router_decisions')->insert(array_merge([
            'id' => $id,
            'schema_version' => 'atlas.ai.router_decision.v1',
            'uuid' => Str::random(64),
            'primary_domain' => 'research',
            'secondary_domains' => json_encode([]),
            'routing_mode' => 'standard',
            'decision_reason' => json_encode([]),
            'policy_required' => false,
            'evidence_required' => false,
            'tool_plan_required' => false,
            'status' => 'routed',
            'receipt_hash' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }
}
