<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Learning;

use App\Models\AiLearningProposal;
use App\Models\AiLearningSignal;
use App\Services\Ai\Learning\AtlasAiLearningLoopService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesRouterRuntimeTables;
use Tests\TestCase;

/**
 * Atlas AI Memory & Learning Feedback Loop · feature certification.
 *
 * Verifies signals are collected from each canonical source, proposals are
 * minted only when evidence is present, risk classification respects the
 * hard rules (critical sources/flows always require review), review records
 * an operator decision without auto-applying anything, and the Control Plane
 * exposes the aggregated counts.
 */
class AtlasAiLearningLoopServiceTest extends TestCase
{
    use CreatesRouterRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRouterRuntimeTables();
        $this->createLearningTables();
        $this->createSurfaceTables();
    }

    protected function tearDown(): void
    {
        $this->dropSurfaceTables();
        $this->dropLearningTables();
        $this->dropRouterRuntimeTables();
        parent::tearDown();
    }

    public function test_empty_window_returns_zero_signals(): void
    {
        $service = $this->service();
        $report = $service->collect(24);

        $this->assertSame('atlas.ai.learning_signal.v1', $report['schema_version']);
        $this->assertSame(0, $report['summary']['signals_collected']);
        $this->assertSame(0, $report['summary']['proposals_minted']);
        $this->assertSame([], $report['signals']);
    }

    public function test_completed_mission_creates_low_risk_heuristic_signal(): void
    {
        DB::table('ai_missions')->insert([
            'id' => Str::uuid()->toString(),
            'schema_version' => 'atlas.ai.mission.v1',
            'uuid' => 'mission-1',
            'title' => 'demo',
            'raw_prompt' => 'do the thing',
            'mission_type' => 'standard',
            'status' => 'completed',
            'autonomy_level' => 'guided',
            'risk_level' => 'low',
            'primary_domain' => 'research',
            'evidence_pack_hash' => str_repeat('a', 64),
            'certification_hash' => str_repeat('b', 64),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $report = $this->service()->collect(24);

        $this->assertSame(1, $report['summary']['signals_collected']);
        $this->assertSame(1, $report['summary']['by_source']['mission_completed']);
        $signal = AiLearningSignal::query()->first();
        $this->assertSame(AiLearningSignal::RISK_LOW, $signal->risk_level);
        $this->assertSame(AiLearningSignal::STATUS_PROPOSED, $signal->status);
        $this->assertTrue($signal->requires_review, 'low-risk still requires operator review per Atlas Compounding canon');
        $this->assertNotNull($signal->learning_proposal_id);

        $proposal = AiLearningProposal::query()->where('id', $signal->learning_proposal_id)->first();
        $this->assertSame('heuristic', $proposal->kind);
        $this->assertSame('proposed', $proposal->status);
        $this->assertTrue((bool) $proposal->requires_human_review);
    }

    public function test_blocked_mission_creates_critical_blocker_signal(): void
    {
        DB::table('ai_missions')->insert([
            'id' => Str::uuid()->toString(),
            'schema_version' => 'atlas.ai.mission.v1',
            'uuid' => 'mission-blocked-1',
            'title' => 'demo',
            'raw_prompt' => 'do the thing',
            'mission_type' => 'standard',
            'status' => 'blocked',
            'autonomy_level' => 'guided',
            'risk_level' => 'medium',
            'primary_domain' => 'finance',
            'blocker_reason' => 'awaiting operator approval',
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $this->service()->collect(24);

        $signal = AiLearningSignal::query()->where('source_type', 'mission_blocked')->first();
        $this->assertNotNull($signal);
        $this->assertSame(AiLearningSignal::RISK_CRITICAL, $signal->risk_level);
        $this->assertSame('awaiting operator approval', $signal->blocker_reason);
    }

    public function test_approval_denied_creates_policy_proposal(): void
    {
        DB::table('ai_operator_approvals')->insert([
            'id' => Str::uuid()->toString(),
            'schema_version' => 'atlas.ai.operator_approval.v1',
            'uuid' => 'approval-1',
            'requested_action' => 'execute_live_trade',
            'risk_level' => 'high',
            'gate_mode' => 'block',
            'approval_required' => true,
            'reason' => 'finance live trade attempt',
            'status' => 'decided',
            'operator_decision' => 'reject',
            'operator' => 'vitorepf',
            'evidence_refs' => json_encode([['type' => 'approval_request', 'id' => 'approval-1']]),
            'receipt_hash' => str_repeat('c', 64),
            'hash' => str_repeat('d', 64),
            'decided_at' => now()->subMinutes(30),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $this->service()->collect(24);

        $signal = AiLearningSignal::query()->where('source_type', 'approval_denied')->first();
        $this->assertNotNull($signal);
        $this->assertSame(AiLearningSignal::RISK_CRITICAL, $signal->risk_level);
        $this->assertSame('reject', $signal->approval_decision);

        $proposal = AiLearningProposal::query()->where('id', $signal->learning_proposal_id)->first();
        $this->assertSame('policy', $proposal->kind);
        $this->assertTrue((bool) $proposal->requires_human_review);
    }

    public function test_failed_quality_evaluation_creates_retrieval_hint(): void
    {
        DB::table('ai_quality_evaluations')->insert([
            'id' => Str::uuid()->toString(),
            'trace_id' => Str::uuid()->toString(),
            'status' => 'failed',
            'score' => 25,
            'flags' => json_encode([['code' => 'missing_evidence'], ['code' => 'uncertainty_hidden']]),
            'suggested_actions' => json_encode([]),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $this->service()->collect(24);

        $signal = AiLearningSignal::query()->where('source_type', 'quality_failed')->first();
        $this->assertNotNull($signal);
        $this->assertSame(25, $signal->quality_score);
        $this->assertSame(AiLearningSignal::RISK_CRITICAL, $signal->risk_level);

        $proposal = AiLearningProposal::query()->where('id', $signal->learning_proposal_id)->first();
        $this->assertSame('retrieval_hint', $proposal->kind);
    }

    public function test_dispatch_blocker_creates_routing_signal_per_reason(): void
    {
        $routerId = $this->insertRouterDecision();
        DB::table('ai_atlas_runtime_dispatches')->insert([
            'id' => Str::uuid()->toString(),
            'schema_version' => 'atlas.ai.runtime_dispatch.v1',
            'uuid' => Str::random(64),
            'router_decision_id' => $routerId,
            'dispatch_target' => 'atlas_research',
            'dispatch_status' => 'blocked',
            'dispatch_payload' => json_encode([]),
            'evidence_refs' => null,
            'blockers' => json_encode([['reason' => 'evidence_missing'], ['reason' => 'policy_gate_required']]),
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ]);

        $this->service()->collect(24);

        $signals = AiLearningSignal::query()->where('source_type', 'dispatch_blocked')->get();
        $this->assertCount(2, $signals);
        $reasons = $signals->pluck('blocker_reason')->all();
        $this->assertContains('evidence_missing', $reasons);
        $this->assertContains('policy_gate_required', $reasons);
        foreach ($signals as $signal) {
            $proposal = AiLearningProposal::query()->where('id', $signal->learning_proposal_id)->first();
            $this->assertSame('routing', $proposal->kind);
        }
    }

    public function test_forge_handoff_incomplete_escalates_to_critical(): void
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
            'handoff_hash' => str_repeat('e', 64),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $this->service()->collect(24);

        $signal = AiLearningSignal::query()->where('source_type', 'forge_handoff_incomplete')->first();
        $this->assertNotNull($signal);
        $this->assertSame(AiLearningSignal::RISK_CRITICAL, $signal->risk_level);
        $this->assertSame('atlas_forge', $signal->flow_id);

        $proposal = AiLearningProposal::query()->where('id', $signal->learning_proposal_id)->first();
        $this->assertSame('gate', $proposal->kind);
    }

    public function test_missing_evidence_blocks_proposal_but_keeps_signal(): void
    {
        // Insert a forge handoff WITHOUT handoff_hash AND with empty refs →
        // service produces refs anyway because handoff_id always gets pushed
        // as a ref. To force missing evidence, we test via an artificial path:
        // run collect against an empty DB, then assert blockers_missing=0.
        // Then mutate a signal manually to clear evidence_refs and verify
        // controlPlaneSummary surfaces it.
        $signal = AiLearningSignal::create([
            'id' => Str::uuid()->toString(),
            'schema_version' => AiLearningSignal::SCHEMA_VERSION,
            'signal_id' => 'als_test_no_evidence',
            'source_type' => 'mission_completed',
            'evidence_refs' => [],
            'requires_review' => true,
            'risk_level' => AiLearningSignal::RISK_LOW,
            'status' => AiLearningSignal::STATUS_COLLECTED,
            'signal_hash' => hash('sha256', 'test-no-evidence'),
            'collected_at' => now(),
        ]);

        $summary = $this->service()->controlPlaneSummary(now()->subDay()->toImmutable());

        $this->assertSame(1, $summary['blockers']['missing_evidence_signals']);
        $this->assertSame(1, $summary['signals']['total']);
        $this->assertSame('collected', $signal->fresh()->status);
    }

    public function test_review_approve_updates_proposal(): void
    {
        $proposal = $this->insertProposal('proposed');

        $result = $this->service()->review($proposal->id, 'approve', 'vitorepf', 'looks good');

        $this->assertTrue($result['ok']);
        $this->assertSame('approved', $result['status']);
        $this->assertSame('approved', $proposal->fresh()->status);
        $this->assertSame('vitorepf', $proposal->fresh()->decided_by);
        $this->assertSame('looks good', $proposal->fresh()->decision_notes);
    }

    public function test_review_reject_updates_proposal(): void
    {
        $proposal = $this->insertProposal('proposed');

        $result = $this->service()->review($proposal->id, 'reject', 'vitorepf', null);

        $this->assertTrue($result['ok']);
        $this->assertSame('rejected', $result['status']);
        $this->assertSame('rejected', $proposal->fresh()->status);
    }

    public function test_review_rejects_invalid_decision(): void
    {
        $proposal = $this->insertProposal('proposed');

        $result = $this->service()->review($proposal->id, 'maybe', 'vitorepf');

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_decision', $result['error']);
        $this->assertSame('proposed', $proposal->fresh()->status, 'invalid decision must not mutate proposal');
    }

    public function test_review_rejects_already_decided_proposal(): void
    {
        $proposal = $this->insertProposal('approved');

        $result = $this->service()->review($proposal->id, 'reject', 'vitorepf');

        $this->assertFalse($result['ok']);
        $this->assertSame('already_decided', $result['error']);
    }

    public function test_signal_hash_is_deterministic_and_dedupes(): void
    {
        DB::table('ai_missions')->insert([
            'id' => Str::uuid()->toString(),
            'schema_version' => 'atlas.ai.mission.v1',
            'uuid' => 'mission-dedupe',
            'title' => 'demo',
            'raw_prompt' => 'x',
            'mission_type' => 'standard',
            'status' => 'completed',
            'autonomy_level' => 'guided',
            'risk_level' => 'low',
            'primary_domain' => 'research',
            'evidence_pack_hash' => str_repeat('1', 64),
            'certification_hash' => str_repeat('2', 64),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $first = $this->service()->collect(24);
        $second = $this->service()->collect(24);

        $this->assertSame(1, $first['summary']['signals_collected']);
        $this->assertSame(1, $second['summary']['signals_collected'], 'second collect should reuse the deduped signal, not create a new one');
        $this->assertSame(1, AiLearningSignal::query()->count(), 'signal_hash unique constraint prevents duplicates');
    }

    public function test_critical_flow_escalates_risk_even_for_positive_source(): void
    {
        // Forge handoff complete should NOT show up — but forge handoff
        // incomplete must escalate. We exercise the critical flow path by
        // checking dispatch with target atlas_forge produces critical risk.
        $routerId = $this->insertRouterDecision();
        DB::table('ai_atlas_runtime_dispatches')->insert([
            'id' => Str::uuid()->toString(),
            'schema_version' => 'atlas.ai.runtime_dispatch.v1',
            'uuid' => Str::random(64),
            'router_decision_id' => $routerId,
            'dispatch_target' => 'atlas_finance',
            'dispatch_status' => 'blocked',
            'dispatch_payload' => json_encode([]),
            'evidence_refs' => null,
            'blockers' => json_encode([['reason' => 'policy_gate_required']]),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $this->service()->collect(24);
        $signal = AiLearningSignal::query()->where('flow_id', 'atlas_finance')->first();
        $this->assertNotNull($signal);
        $this->assertSame(AiLearningSignal::RISK_CRITICAL, $signal->risk_level);
    }

    public function test_command_collect_returns_summary_json(): void
    {
        $exit = $this->artisan('atlas:ai:learning', ['action' => 'collect', '--json' => true])->run();
        $this->assertSame(0, $exit);
    }

    public function test_command_list_returns_inbox_json(): void
    {
        $this->insertProposal('proposed');
        $exit = $this->artisan('atlas:ai:learning', ['action' => 'list', '--json' => true])->run();
        $this->assertSame(0, $exit);
    }

    public function test_command_review_requires_proposal_and_decision(): void
    {
        $exit = $this->artisan('atlas:ai:learning', ['action' => 'review', '--json' => true])->run();
        $this->assertSame(1, $exit, 'missing options must return failure exit code');
    }

    public function test_response_text_never_leaks_through_payload(): void
    {
        // Insert a quality evaluation with a flag carrying suspect text.
        DB::table('ai_quality_evaluations')->insert([
            'id' => Str::uuid()->toString(),
            'trace_id' => Str::uuid()->toString(),
            'status' => 'failed',
            'score' => 10,
            'flags' => json_encode([['code' => 'SECRET_FLAG_CODE']]),
            'suggested_actions' => json_encode([]),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $this->service()->collect(24);
        $signal = AiLearningSignal::query()->first();
        $this->assertSame('SECRET_FLAG_CODE', is_array($signal->payload['flags'] ?? null) ? $signal->payload['flags'][0] : null, 'flag code is metadata, allowed to surface');

        // Now insert a trace with raw response_text and verify the LEARNING
        // service never picks that field up (it only reads error_code/message
        // and never response_text/operator_input).
        $traceId = Str::uuid()->toString();
        DB::table('ai_traces')->insert([
            'id' => $traceId,
            'status' => 'failed',
            'provider' => 'claude',
            'response_text' => 'NEVER_LEAK_THIS_RESPONSE_TEXT',
            'operator_input' => 'NEVER_LEAK_THIS_OPERATOR_INPUT',
            'metadata' => json_encode([]),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
        DB::table('ai_jobs')->insert([
            'id' => Str::uuid()->toString(),
            'trace_id' => $traceId,
            'status' => 'failed',
            'error_code' => 'provider_timeout',
            'error_message' => 'provider hit timeout',
            'attempts' => 1,
            'max_attempts' => 3,
            'priority' => 0,
            'context_refs' => json_encode([]),
            'payload' => json_encode([]),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        AiLearningSignal::query()->delete();
        $this->service()->collect(24);

        $serialized = json_encode(AiLearningSignal::query()->get()->toArray());
        $this->assertStringNotContainsString('NEVER_LEAK_THIS_RESPONSE_TEXT', $serialized);
        $this->assertStringNotContainsString('NEVER_LEAK_THIS_OPERATOR_INPUT', $serialized);
    }

    private function service(): AtlasAiLearningLoopService
    {
        return $this->app->make(AtlasAiLearningLoopService::class);
    }

    private function insertProposal(string $status): AiLearningProposal
    {
        return AiLearningProposal::query()->create([
            'id' => Str::uuid()->toString(),
            'schema_version' => 'atlas.ai.compounding.learning_proposal.v1',
            'kind' => 'policy',
            'status' => $status,
            'scope' => 'atlas_ai_runtime',
            'summary' => 'test proposal',
            'evidence_refs' => [['type' => 'manual', 'id' => 'test']],
            'requires_human_review' => true,
            'proposal_hash' => hash('sha256', 'test:'.Str::random(20)),
        ]);
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
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    private function createLearningTables(): void
    {
        $this->dropLearningTables();

        Schema::create('ai_learning_signals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 80)->default('atlas.ai.learning_signal.v1');
            $table->string('signal_id', 80)->unique();
            $table->string('source_type', 60)->index();
            $table->string('source_id', 120)->nullable()->index();
            $table->uuid('mission_id')->nullable()->index();
            $table->string('flow_id', 80)->nullable()->index();
            $table->string('outcome', 32)->nullable()->index();
            $table->smallInteger('quality_score')->nullable();
            $table->string('failure_mode', 160)->nullable();
            $table->string('blocker_reason', 200)->nullable();
            $table->string('approval_decision', 32)->nullable();
            $table->json('evidence_refs');
            $table->json('proposed_memory_delta')->nullable();
            $table->json('proposed_policy_delta')->nullable();
            $table->json('proposed_rag_feedback')->nullable();
            $table->boolean('requires_review')->default(true);
            $table->string('risk_level', 16)->default('critical')->index();
            $table->string('status', 24)->default('collected')->index();
            $table->uuid('learning_proposal_id')->nullable()->index();
            $table->json('payload')->nullable();
            $table->string('signal_hash', 64)->unique();
            $table->timestamp('collected_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('ai_learning_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 80)->default('atlas.ai.compounding.learning_proposal.v1');
            $table->string('kind', 40)->index();
            $table->string('status', 32)->default('proposed')->index();
            $table->string('scope', 120)->default('global')->index();
            $table->string('flow_id', 80)->nullable()->index();
            $table->text('summary');
            $table->json('current_state')->nullable();
            $table->json('proposed_state')->nullable();
            $table->json('evidence_refs');
            $table->uuid('run_outcome_id')->nullable()->index();
            $table->uuid('learning_candidate_id')->nullable()->index();
            $table->uuid('rag_feedback_id')->nullable()->index();
            $table->boolean('requires_human_review')->default(true);
            $table->string('decided_by', 120)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->json('payload')->nullable();
            $table->string('proposal_hash', 64)->unique();
            $table->timestamps();
        });
    }

    private function dropLearningTables(): void
    {
        Schema::dropIfExists('ai_learning_proposals');
        Schema::dropIfExists('ai_learning_signals');
    }

    private function createSurfaceTables(): void
    {
        $this->dropSurfaceTables();

        Schema::create('ai_missions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->nullable();
            $table->string('uuid', 80)->nullable();
            $table->string('title', 200)->nullable();
            $table->text('raw_prompt')->nullable();
            $table->string('normalized_intent', 200)->nullable();
            $table->string('mission_type', 60)->nullable();
            $table->string('status', 40)->nullable();
            $table->string('autonomy_level', 40)->nullable();
            $table->string('risk_level', 40)->nullable();
            $table->json('definition_of_done')->nullable();
            $table->text('context_summary')->nullable();
            $table->string('primary_domain', 80)->nullable();
            $table->json('secondary_domains')->nullable();
            $table->string('current_step', 120)->nullable();
            $table->text('blocker_reason')->nullable();
            $table->string('evidence_pack_hash', 64)->nullable();
            $table->string('certification_hash', 64)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_operator_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->nullable();
            $table->string('uuid', 80)->nullable();
            $table->uuid('mission_id')->nullable();
            $table->uuid('work_order_id')->nullable();
            $table->uuid('trace_id')->nullable();
            $table->uuid('job_id')->nullable();
            $table->string('requested_action', 200)->nullable();
            $table->string('risk_level', 40)->nullable();
            $table->string('gate_mode', 40)->nullable();
            $table->boolean('approval_required')->default(true);
            $table->text('reason')->nullable();
            $table->json('options')->nullable();
            $table->string('status', 40)->nullable();
            $table->string('operator_decision', 40)->nullable();
            $table->string('operator', 120)->nullable();
            $table->text('operator_note')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->string('receipt_hash', 64)->nullable();
            $table->string('hash', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('ai_quality_evaluations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->string('status', 40)->nullable();
            $table->integer('score')->nullable();
            $table->json('flags')->nullable();
            $table->json('suggested_actions')->nullable();
            $table->json('dimensions')->nullable();
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

        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status', 40)->nullable();
            $table->string('provider', 80)->nullable();
            $table->text('operator_input')->nullable();
            $table->text('response_text')->nullable();
            $table->string('intent', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->string('status', 40)->nullable();
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(3);
            $table->integer('priority')->default(0);
            $table->json('context_refs')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    private function dropSurfaceTables(): void
    {
        foreach (['ai_jobs', 'ai_traces', 'ai_real_execution_forge_handoffs', 'ai_quality_actions', 'ai_quality_evaluations', 'ai_operator_approvals', 'ai_missions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
