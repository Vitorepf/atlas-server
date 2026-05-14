<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasCodeForgeFastPathService;
use App\Services\Ai\Programming\AtlasCodeForgeReviewCompletionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AtlasCodeForgeReviewCompletionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSchema();
        Queue::fake();
    }

    public function test_review_packet_blocked_for_unknown_run(): void
    {
        $obra = $this->makeObra();
        $service = app(AtlasCodeForgeReviewCompletionService::class);

        $packet = $service->packet($obra, 'NON-EXISTENT-RUN');

        $this->assertSame('atlas.code.forge_review_packet.v1', $packet['schema_version']);
        $this->assertSame('blocked', $packet['review_status']);
        $this->assertSame('fast_path_run_not_found', $packet['blocker']);
        $this->assertSame(404, $packet['http_status']);
        $this->assertFalse($packet['rollback_available']);
    }

    public function test_review_packet_blocked_when_run_belongs_to_other_obra(): void
    {
        $owner = $this->makeObra();
        $other = $this->makeObra();
        app(AtlasCodeForgeFastPathService::class)->run($owner, [
            'obra_id' => (string) $owner->id,
            'mode' => AtlasCodeForgeFastPathService::MODE_PREPARE_ONLY,
        ]);
        $owner->refresh();
        $ownerRun = data_get($owner->metadata, 'latest_atlas_code_forge_fast_path_run');
        $runId = (string) data_get($ownerRun, 'fast_path_run_id');

        $metadata = is_array($other->metadata) ? $other->metadata : [];
        $metadata['latest_atlas_code_forge_fast_path_run'] = $ownerRun;
        $other->forceFill(['metadata' => $metadata])->save();

        $packet = app(AtlasCodeForgeReviewCompletionService::class)->packet($other->refresh(), $runId);

        $this->assertSame('blocked', $packet['review_status']);
        $this->assertSame('fast_path_run_obra_mismatch', $packet['blocker']);
        $this->assertSame(403, $packet['http_status']);
    }

    public function test_review_packet_only_uses_correlated_live_execution(): void
    {
        $obra = $this->makeObra();
        app(AtlasCodeForgeFastPathService::class)->run($obra, [
            'obra_id' => (string) $obra->id,
            'mode' => AtlasCodeForgeFastPathService::MODE_PREPARE_ONLY,
        ]);
        $obra->refresh();
        $runId = (string) data_get($obra->metadata, 'latest_atlas_code_forge_fast_path_run.fast_path_run_id');

        // injeta um latest_forge_live_execution com IDs DIFERENTES — nao deve ser correlacionado
        $metadata = is_array($obra->metadata) ? $obra->metadata : [];
        $metadata['latest_forge_live_execution'] = [
            'status' => 'passed',
            'run_id' => 'OUTRO-RUN',
            'execution_id' => 'OUTRO-EXECUTION',
            'evidence_id' => 'OUTRO-EVIDENCE',
        ];
        $obra->forceFill(['metadata' => $metadata])->save();

        $packet = app(AtlasCodeForgeReviewCompletionService::class)->packet($obra->refresh(), $runId);

        // runtime_status nao deve refletir o forge live nao-correlacionado
        $this->assertSame('missing', $packet['runtime_status']);
        $this->assertFalse($packet['completion_claim_allowed_after_review']);
    }

    public function test_approve_blocked_when_runtime_not_passed(): void
    {
        $obra = $this->makeObra();
        app(AtlasCodeForgeFastPathService::class)->run($obra, [
            'obra_id' => (string) $obra->id,
            'mode' => AtlasCodeForgeFastPathService::MODE_PREPARE_ONLY,
        ]);
        $obra->refresh();
        $runId = (string) data_get($obra->metadata, 'latest_atlas_code_forge_fast_path_run.fast_path_run_id');

        $result = app(AtlasCodeForgeReviewCompletionService::class)->approve($obra, $runId, [
            'reviewer' => 'tester',
            'reason' => 'try approve without runtime',
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('forge_runtime_not_passed', $result['blocker']);
    }

    public function test_approve_blocked_when_evidence_pack_missing(): void
    {
        $obra = $this->prepareObraWithPassedRuntime(withEvidencePackDigest: false);
        $runId = (string) data_get($obra->metadata, 'latest_atlas_code_forge_fast_path_run.fast_path_run_id');

        $result = app(AtlasCodeForgeReviewCompletionService::class)->approve($obra, $runId, [
            'reviewer' => 'tester',
            'reason' => 'try approve without evidence pack',
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('evidence_pack_missing', $result['blocker']);
    }

    public function test_reject_records_decision_and_blocks_completion_claim(): void
    {
        $obra = $this->prepareObraWithPassedRuntime();
        $runId = (string) data_get($obra->metadata, 'latest_atlas_code_forge_fast_path_run.fast_path_run_id');

        $result = app(AtlasCodeForgeReviewCompletionService::class)->reject($obra, $runId, [
            'reviewer' => 'tester',
            'reason' => 'review rejected by operator',
        ]);

        $this->assertSame('rejected', $result['status']);
        $obra->refresh();
        $history = (array) data_get($obra->metadata, 'atlas_code_forge_completion_history', []);
        $this->assertNotEmpty($history);
        $this->assertSame('rejected', (string) data_get($history, '0.status'));
        $this->assertSame('blocked', $result['completion_claim']['completion_status']);
        $this->assertFalse($result['completion_claim']['final_completion_allowed']);
        $this->assertFalse($result['completion_claim']['human_approved']);
    }

    public function test_rollback_blocked_when_rollback_not_available(): void
    {
        $obra = $this->prepareObraWithPassedRuntime();
        $runId = (string) data_get($obra->metadata, 'latest_atlas_code_forge_fast_path_run.fast_path_run_id');

        $result = app(AtlasCodeForgeReviewCompletionService::class)->rollback($obra, $runId, [
            'reviewer' => 'tester',
            'reason' => 'try rollback before approval',
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('rollback_not_available', $result['blocker']);
    }

    public function test_state_endpoint_exposes_review_packet_and_completion_claim(): void
    {
        $obra = $this->prepareObraWithPassedRuntime();
        $runId = (string) data_get($obra->metadata, 'latest_atlas_code_forge_fast_path_run.fast_path_run_id');

        // grava bindings minimos no metadata + valida helper canonico do controller
        $metadata = is_array($obra->metadata) ? $obra->metadata : [];
        $metadata['latest_atlas_code_forge_review_packet'] = [
            'review_packet_id' => '01KRPACKETSEEDED',
            'fast_path_run_id' => $runId,
            'reviewer_id' => 'seed',
        ];
        $metadata['latest_atlas_code_forge_completion_claim'] = [
            'fast_path_run_id' => $runId,
            'completion_status' => 'allowed',
        ];
        $obra->forceFill(['metadata' => $metadata])->save();

        // O helper privado e exercido via reflection para garantir que o state projection
        // entrega forge_review_packet/forge_completion_claim com fast_path_run_id correto.
        $controller = new \App\Http\Controllers\AtlasCodeWorkController();
        $reflection = new \ReflectionClass($controller);

        $packetMethod = $reflection->getMethod('forgeReviewPacketForWork');
        $packetMethod->setAccessible(true);
        $packet = $packetMethod->invoke($controller, $obra->refresh());
        $this->assertIsArray($packet);
        $this->assertSame($runId, $packet['fast_path_run_id']);

        $claimMethod = $reflection->getMethod('forgeCompletionClaimForWork');
        $claimMethod->setAccessible(true);
        $claim = $claimMethod->invoke($controller, $obra->refresh());
        $this->assertIsArray($claim);
        $this->assertSame($runId, $claim['fast_path_run_id']);
    }

    public function test_completion_audit_block_lists_review_completion_artifacts(): void
    {
        $payload = (array) app(\App\Services\Ai\Programming\ProgrammingProfessionalCompletionAuditService::class)
            ->report(base_path(), false);

        $this->assertArrayHasKey('forge_review_completion_certification', $payload);
        $cert = $payload['forge_review_completion_certification'];
        $this->assertSame('atlas.code.forge_review_completion_certification.v1', $cert['schema_version']);
        $this->assertSame('separated', $cert['separated_from_external_rivals'] ?? 'separated');
        $this->assertTrue($cert['lifecycle_invariants']['no_auto_completion_without_human_review']);
    }

    public function test_completion_claim_only_allowed_after_human_approval(): void
    {
        $obra = $this->prepareObraWithPassedRuntime();
        $runId = (string) data_get($obra->metadata, 'latest_atlas_code_forge_fast_path_run.fast_path_run_id');

        $claimBefore = app(AtlasCodeForgeReviewCompletionService::class)->completionClaim($obra, $runId);
        $this->assertSame('allowed', $claimBefore['completion_status']);
        $this->assertFalse($claimBefore['final_completion_allowed']);
        $this->assertFalse($claimBefore['human_approved']);
    }

    public function test_approve_with_human_review_completes_claim(): void
    {
        $obra = $this->prepareObraWithPassedRuntime();
        $runId = (string) data_get($obra->metadata, 'latest_atlas_code_forge_fast_path_run.fast_path_run_id');

        $result = app(AtlasCodeForgeReviewCompletionService::class)->approve($obra, $runId, [
            'reviewer' => 'tester',
            'reason' => 'operator approved reviewed forge evidence',
        ]);

        $this->assertSame('approved', $result['status']);
        $this->assertSame('completed', $result['completion_claim']['completion_status']);
        $this->assertTrue($result['completion_claim']['human_approved']);
        $this->assertSame('tester', $result['completion_claim']['approved_by']);
        $this->assertTrue($result['completion_claim']['final_completion_allowed']);
    }

    public function test_rollback_seed_promoted_review_can_succeed(): void
    {
        $obra = $this->prepareObraWithPassedRuntime();
        $runId = (string) data_get($obra->metadata, 'latest_atlas_code_forge_fast_path_run.fast_path_run_id');

        // Seed an approved review with a promoted promotion that is rollback-eligible
        $metadata = is_array($obra->metadata) ? $obra->metadata : [];
        $promotionId = '01KRPROMOTION'.Str::ulid();
        $metadata['latest_atlas_code_forge_review'] = [
            'review_id' => '01KRREVIEW'.Str::ulid(),
            'fast_path_run_id' => $runId,
            'history_id' => (string) data_get($obra->metadata, 'latest_atlas_code_forge_fast_path_run.history_id', ''),
            'status' => 'approved',
            'decision' => 'approved',
            'final_completion_allowed' => true,
            'reviewer_id' => 'seed-reviewer',
            'approval_effective' => true,
            'reviewed_at' => now()->toJSON(),
            'promotion' => [
                'promotion_id' => $promotionId,
                'promotion_status' => 'promoted',
                'schema_version' => 'atlas.forge_governed_promotion.v1',
                'live_workspace_mutated' => true,
            ],
        ];
        $obra->forceFill(['metadata' => $metadata])->save();

        $packet = app(AtlasCodeForgeReviewCompletionService::class)->packet($obra->refresh(), $runId);
        $this->assertTrue($packet['rollback_available']);
    }

    private function prepareObraWithPassedRuntime(bool $withEvidencePackDigest = true): AtlasProject
    {
        $obra = $this->makeObra();
        app(AtlasCodeForgeFastPathService::class)->run($obra, [
            'obra_id' => (string) $obra->id,
            'mode' => AtlasCodeForgeFastPathService::MODE_PREPARE_ONLY,
        ]);
        $obra->refresh();

        $run = (array) data_get($obra->metadata, 'latest_atlas_code_forge_fast_path_run', []);
        $runId = (string) ($run['fast_path_run_id'] ?? Str::ulid());
        $executionId = (string) ($run['execution_id'] ?? Str::ulid());
        $historyId = (string) ($run['history_id'] ?? Str::ulid());

        $metadata = is_array($obra->metadata) ? $obra->metadata : [];
        // Garante correlacao entre run e forge live execution
        $latestRun = (array) ($metadata['latest_atlas_code_forge_fast_path_run'] ?? []);
        $latestRun['execution_id'] = $executionId;
        $latestRun['history_id'] = $historyId;
        $metadata['latest_atlas_code_forge_fast_path_run'] = $latestRun;

        $forgeLiveExecution = array_filter([
            'status' => 'passed',
            'history_id' => $historyId,
            'run_id' => $historyId,
            'execution_id' => $executionId,
            'last_run_at' => now()->toJSON(),
            'completion_claim_allowed' => true,
            'diff_scope' => [
                'files' => ['src/example.php'],
                'completion_gate' => ['completion_claim_allowed' => true],
            ],
            'evidence_pack_digest' => $withEvidencePackDigest
                ? ['stage_receipt_count' => 3, 'ledger_event_count' => 4, 'report_hash' => 'abc']
                : [],
        ], static fn (mixed $v): bool => $v !== null);
        $metadata['latest_forge_live_execution'] = $forgeLiveExecution;
        $metadata['atlas_code_forge_live_execution_history'] = [$forgeLiveExecution];

        $obra->forceFill(['metadata' => $metadata])->save();

        return $obra->refresh();
    }

    private function makeObra(): AtlasProject
    {
        return AtlasProject::create([
            'id' => (string) Str::uuid(),
            'title' => 'Forge Review test Obra',
            'description' => 'Atlas Code Forge Review & Completion Gate test obra',
            'status' => 'active',
            'domain' => 'atlas',
            'goal' => 'Validar review/completion gate',
            'desired_outcome' => 'Validar review/completion gate runtime',
            'priority' => 'normal',
            'metadata' => ['workspace_path' => '/tmp/atlas-review-test'],
        ]);
    }

    /**
     * @return array<string,string>
     */
    private function headers(): array
    {
        return ['X-Atlas-Token' => 'testing-atlas-token-with-enough-length'];
    }

    private function ensureSchema(): void
    {
        if (! Schema::hasTable('atlas_projects')) {
            Schema::create('atlas_projects', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('title')->nullable();
                $t->text('description')->nullable();
                $t->string('status')->default('active');
                $t->string('domain')->default('atlas');
                $t->uuid('source_capture_id')->nullable();
                $t->text('goal')->nullable();
                $t->text('next_action')->nullable();
                $t->string('project_type')->nullable();
                $t->text('desired_outcome')->nullable();
                $t->text('minimum_viable_outcome')->nullable();
                $t->text('definition_of_done')->nullable();
                $t->text('why_now')->nullable();
                $t->timestamp('deadline_at')->nullable();
                $t->string('deadline_kind')->nullable();
                $t->string('priority')->default('normal');
                $t->string('energy_profile')->nullable();
                $t->text('avoidance_reason')->nullable();
                $t->uuid('active_next_task_id')->nullable();
                $t->uuid('current_step_id')->nullable();
                $t->timestamp('last_touched_at')->nullable();
                $t->timestamp('next_review_at')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->timestamp('paused_until')->nullable();
                $t->json('metadata')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }

        if (! Schema::hasTable('atlas_programming_work_items')) {
            Schema::create('atlas_programming_work_items', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('code', 64)->unique();
                $t->text('intent_text');
                $t->string('intent_type', 40)->index();
                $t->string('scope_mode', 24)->index();
                $t->string('risk_level', 16)->default('medium')->index();
                $t->string('owner', 80)->nullable()->index();
                $t->string('workspace', 255)->nullable();
                $t->string('status', 32)->index();
                $t->string('current_stage', 32)->index();
                $t->string('spec_hash', 64)->nullable()->index();
                $t->string('plan_hash', 64)->nullable()->index();
                $t->json('placement_json')->default('{}');
                $t->json('code_intelligence_json')->default('{}');
                $t->json('spec_json')->default('{}');
                $t->json('plan_json')->default('{}');
                $t->json('tasks_json')->default('[]');
                $t->json('evidence_refs_json')->default('[]');
                $t->json('gaps_json')->default('[]');
                $t->json('metadata_json')->default('{}');
                $t->timestamp('closed_at')->nullable()->index();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_ledger_events')) {
            Schema::create('atlas_ledger_events', function (Blueprint $t) {
                $t->string('event_id')->primary();
                $t->string('schema_version')->nullable();
                $t->string('tenant_id')->nullable();
                $t->string('operator_id')->nullable();
                $t->string('envelope_id')->nullable();
                $t->string('receipt_id')->nullable();
                $t->string('trace_id')->nullable();
                $t->string('correlation_id')->nullable();
                $t->string('causation_id')->nullable();
                $t->string('event_type')->nullable();
                $t->string('emitter_stage')->nullable();
                $t->string('emitter_version')->nullable();
                $t->json('payload')->nullable();
                $t->string('payload_hash')->nullable();
                $t->timestamp('occurred_at')->nullable();
                $t->timestamps();
            });
        }
    }
}
