<?php

namespace Tests\Feature\Ai;

use App\Models\AiInboxItem;
use App\Models\AiJob;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerProjectionWorker;
use App\Services\Ai\Mobile\AtlasInboxService;
use App\Services\Ai\Mobile\DiscussionBootstrapper;
use App\Services\Ai\Mobile\InboxActionRegistry;
use App\Services\Ai\Mobile\ProposalInboxEmitter;
use App\Services\Ai\AutonomousEvolution\AtlasLoopOperatorReviewQueueService;
use App\Services\Ai\Telemetry\AiProviderCostRateService;
use App\Services\Ai\Telemetry\Engine\RecommendationLifecycleService;
use App\Services\AuditLogService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class AtlasCliInboxJobResultActionTest extends TestCase
{
    private InboxActionRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        $this->registry = $this->buildRegistry();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->dropTables();
        parent::tearDown();
    }

    public function test_job_result_with_high_importance_exposes_approve_reject_actions(): void
    {
        $item = AiInboxItem::query()->create([
            'type' => 'job_result',
            'title' => 'Job finalizado: test',
            'summary' => 'Job de alta importancia terminou.',
            'category' => 'test',
            'severity' => 'warning',
            'status' => 'unread',
            'initiator' => 'job',
            'source_type' => 'ai_job',
            'source_id' => '00000000-0000-0000-0000-000000001001',
            'available_actions' => [
                ['id' => 'view_trace', 'label' => 'Ver trace', 'style' => 'primary'],
                ['id' => 'discuss', 'label' => 'Discutir com Atlas', 'style' => 'default'],
                ['id' => 'dismiss', 'label' => 'Descartar', 'style' => 'default'],
                ['id' => 'approve_job_result', 'label' => 'Aprovar resultado', 'style' => 'success'],
                ['id' => 'reject_job_result', 'label' => 'Rejeitar resultado', 'style' => 'danger'],
            ],
            'payload' => [
                'job_id' => '00000000-0000-0000-0000-000000001001',
                'trace_id' => '00000000-0000-0000-0000-000000009999',
            ],
        ]);

        $this->assertSame('job_result', $item->type);

        $availableIds = collect($item->available_actions)->pluck('id')->all();
        $this->assertContains('approve_job_result', $availableIds);
        $this->assertContains('reject_job_result', $availableIds);
        $this->assertContains('view_trace', $availableIds);
        $this->assertContains('discuss', $availableIds);
        $this->assertContains('dismiss', $availableIds);
    }

    public function test_approve_job_result_resolves_item(): void
    {
        $item = AiInboxItem::query()->create([
            'type' => 'job_result',
            'title' => 'Job finalizado',
            'summary' => 'Sucesso.',
            'severity' => 'info',
            'status' => 'unread',
            'initiator' => 'job',
            'source_type' => 'ai_job',
            'source_id' => '00000000-0000-0000-0000-000000001002',
            'available_actions' => [
                ['id' => 'approve_job_result', 'label' => 'Aprovar', 'style' => 'success'],
            ],
            'payload' => [
                'job_id' => '00000000-0000-0000-0000-000000001002',
            ],
        ]);

        $result = $this->registry->handle(
            $item->refresh(),
            'approve_job_result',
            ['reason' => 'Resultado correto.'],
            'cli-approve-job-result-test',
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('approve_job_result', $result['result']['job_result_action']);
        $this->assertSame('resolved', $result['item']->status);
    }

    public function test_reject_job_result_marks_read_and_records_reason(): void
    {
        $item = AiInboxItem::query()->create([
            'type' => 'job_result',
            'title' => 'Job falhou',
            'summary' => 'Falhou.',
            'severity' => 'warning',
            'status' => 'unread',
            'initiator' => 'job',
            'source_type' => 'ai_job',
            'source_id' => '00000000-0000-0000-0000-000000001003',
            'available_actions' => [
                ['id' => 'reject_job_result', 'label' => 'Rejeitar', 'style' => 'danger'],
            ],
            'payload' => [
                'job_id' => '00000000-0000-0000-0000-000000001003',
            ],
        ]);

        $result = $this->registry->handle(
            $item->refresh(),
            'reject_job_result',
            ['reason' => 'Esperava resultado diferente.'],
            'cli-reject-job-result-test',
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('reject_job_result', $result['result']['job_result_action']);
        $this->assertNotNull($result['item']->read_at);
        $this->assertSame('reject_job_result', $result['item']->response['action']);
        $this->assertSame('Esperava resultado diferente.', $result['item']->response['reason']);
    }

    public function test_rerun_job_result_works_on_failed_job(): void
    {
        $job = AiJob::query()->create([
            'id' => '00000000-0000-0000-0000-000000001004',
            'kind' => 'test',
            'status' => 'failed',
            'agent_slug' => 'test',
            'input_text' => 'test input',
            'prompt' => 'test prompt',
            'error_code' => 'ERR_TEST',
            'error_message' => 'Test error',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'attempts' => 1,
            'max_attempts' => 2,
        ]);

        $item = AiInboxItem::query()->create([
            'type' => 'job_result',
            'title' => 'Job falhou',
            'summary' => 'Erro de teste.',
            'severity' => 'warning',
            'status' => 'unread',
            'initiator' => 'job',
            'source_type' => 'ai_job',
            'source_id' => $job->id,
            'available_actions' => [
                ['id' => 'rerun_job_result', 'label' => 'Solicitar rerun', 'style' => 'warning'],
            ],
            'payload' => [
                'job_id' => $job->id,
            ],
        ]);

        $result = $this->registry->handle(
            $item->refresh(),
            'rerun_job_result',
            ['reason' => 'Parece erro transiente.'],
            'cli-rerun-job-result-test',
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('rerun_job_result', $result['result']['job_result_action']);
        $this->assertSame($job->id, $result['result']['job_id']);
    }

    public function test_job_result_action_fails_on_non_job_result_item(): void
    {
        $item = AiInboxItem::query()->create([
            'type' => 'insight',
            'title' => 'Insight de teste',
            'summary' => 'Nao e job_result.',
            'severity' => 'info',
            'status' => 'unread',
            'initiator' => 'system',
        ]);

        $this->expectException(ValidationException::class);
        $this->registry->handle(
            $item->refresh(),
            'approve_job_result',
            [],
            'cli-approve-wrong-type-test',
        );
    }

    public function test_approve_job_result_is_idempotent_with_same_key(): void
    {
        $item = AiInboxItem::query()->create([
            'type' => 'job_result',
            'title' => 'Job finalizado',
            'summary' => 'Sucesso.',
            'severity' => 'info',
            'status' => 'unread',
            'initiator' => 'job',
            'source_type' => 'ai_job',
            'source_id' => '00000000-0000-0000-0000-000000001005',
            'available_actions' => [
                ['id' => 'approve_job_result', 'label' => 'Aprovar', 'style' => 'success'],
                ['id' => 'dismiss', 'label' => 'Descartar', 'style' => 'default'],
            ],
            'payload' => [
                'job_id' => '00000000-0000-0000-0000-000000001005',
            ],
        ]);

        $idempotencyKey = 'cli-idempotent-job-result';

        $first = $this->registry->handle(
            $item->refresh(),
            'approve_job_result',
            [],
            $idempotencyKey,
        );
        $this->assertTrue($first['ok']);
        $this->assertFalse($first['idempotent']);

        $second = $this->registry->handle(
            $item->refresh(),
            'approve_job_result',
            [],
            $idempotencyKey,
        );
        $this->assertTrue($second['ok']);
        $this->assertTrue($second['idempotent']);
    }

    public function test_resolved_job_result_cannot_receive_new_action(): void
    {
        $item = AiInboxItem::query()->create([
            'type' => 'job_result',
            'title' => 'Job finalizado',
            'summary' => 'Sucesso.',
            'severity' => 'info',
            'status' => 'unread',
            'initiator' => 'job',
            'source_type' => 'ai_job',
            'source_id' => '00000000-0000-0000-0000-000000001006',
            'available_actions' => [
                ['id' => 'approve_job_result', 'label' => 'Aprovar', 'style' => 'success'],
                ['id' => 'reject_job_result', 'label' => 'Rejeitar', 'style' => 'danger'],
            ],
            'payload' => [
                'job_id' => '00000000-0000-0000-0000-000000001006',
            ],
        ]);

        // First approve resolves the item
        $this->registry->handle(
            $item->refresh(),
            'approve_job_result',
            [],
            'cli-approve-closed-1',
        );

        // Now reject should fail — item is resolved
        $this->expectException(ValidationException::class);
        $this->registry->handle(
            $item->refresh(),
            'reject_job_result',
            [],
            'cli-reject-closed-1',
        );
    }

    private function buildRegistry(): InboxActionRegistry
    {
        $stub = function (string $class): object {
            return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        };

        return new InboxActionRegistry(
            $stub(AtlasInboxService::class),
            new AuditLogService,
            $stub(ProposalInboxEmitter::class),
            $stub(RecommendationLifecycleService::class),
            $stub(DiscussionBootstrapper::class),
            $stub(AtlasLoopOperatorReviewQueueService::class),
            $stub(AtlasEvidenceLedger::class),
            $stub(LedgerProjectionWorker::class),
            $stub(AiProviderCostRateService::class),
        );
    }

    private function createTables(): void
    {
        $this->dropTables();

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_type', 120);
            $table->string('subject_type', 120)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('actor_type', 80)->default('system');
            $table->string('actor_id', 160)->nullable();
            $table->string('severity', 20)->default('info');
            $table->text('summary');
            $table->json('evidence')->default('{}');
            $table->json('privacy')->default('{}');
            $table->json('refs')->default('{}');
            $table->json('metadata')->default('{}');
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('ai_context_bundles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_id')->default('vitor');
            $table->string('purpose');
            $table->string('title');
            $table->text('summary');
            $table->text('body_for_thread');
            $table->json('source_refs')->nullable();
            $table->json('trace_refs')->nullable();
            $table->json('job_refs')->nullable();
            $table->json('metric_refs')->nullable();
            $table->json('file_refs')->nullable();
            $table->json('diff_refs')->nullable();
            $table->json('raw_payload')->nullable();
            $table->string('redaction_status')->default('clean');
            $table->integer('token_estimate')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_inbox_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_id')->default('vitor');
            $table->string('type');
            $table->string('category')->nullable();
            $table->string('severity')->default('info');
            $table->string('status')->default('unread');
            $table->string('title');
            $table->text('summary')->nullable();
            $table->text('body')->nullable();
            $table->string('source_type')->nullable();
            $table->uuid('source_id')->nullable();
            $table->string('initiator')->default('system');
            $table->uuid('context_bundle_id')->nullable();
            $table->string('dedupe_key')->nullable();
            $table->json('available_actions')->nullable();
            $table->json('response')->nullable();
            $table->json('payload')->nullable();
            $table->text('deep_link')->nullable();
            $table->json('push_policy')->nullable();
            $table->smallInteger('priority_score')->default(50);
            $table->decimal('confidence_score', 4, 3)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();
        });

        Schema::getConnection()->statement(<<<'SQL'
            CREATE UNIQUE INDEX ai_inbox_items_active_dedupe_unique
            ON ai_inbox_items (user_id, dedupe_key)
            WHERE dedupe_key IS NOT NULL
              AND status NOT IN ('resolved', 'dismissed', 'expired')
        SQL);

        Schema::create('ai_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->uuid('client_id')->nullable();
            $table->string('kind')->default('interaction');
            $table->string('status')->default('queued');
            $table->smallInteger('priority')->default(50);
            $table->string('agent_slug');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->text('input_text');
            $table->text('prompt');
            $table->json('context_refs')->nullable();
            $table->json('payload')->nullable();
            $table->text('result_text')->nullable();
            $table->json('result_json')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(2);
            $table->integer('timeout_seconds')->default(300);
            $table->string('worker_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 32)->primary();
            $table->string('schema_version', 40)->default('atlas.ledger_event.v1');
            $table->string('tenant_id', 120)->index();
            $table->string('operator_id', 120)->index();
            $table->string('envelope_id', 80)->index();
            $table->string('receipt_id', 80)->nullable()->index();
            $table->uuid('trace_id')->nullable()->index();
            $table->string('correlation_id', 120)->index();
            $table->string('causation_id', 80)->nullable()->index();
            $table->string('event_type', 80)->index();
            $table->string('emitter_stage', 120)->index();
            $table->string('emitter_version', 80);
            $table->json('payload');
            $table->string('payload_hash', 64)->index();
            $table->timestampTz('occurred_at')->index();
            $table->timestampsTz();
        });
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        Schema::dropIfExists('ai_jobs');
        Schema::dropIfExists('ai_inbox_items');
        Schema::dropIfExists('ai_context_bundles');
        Schema::dropIfExists('audit_events');
    }
}
