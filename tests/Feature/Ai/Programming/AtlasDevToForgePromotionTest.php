<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Models\AiMessage;
use App\Models\AiThread;
use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasDevToForgePromotionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Atlas Dev-to-Forge Promotion v1 · feature tests.
 *
 * Cobre:
 *   - thread simples → não recomenda Obra (target = none)
 *   - sinais fortes → recomenda promoção apropriada
 *   - preview é read-only
 *   - workspace preservado no payload e na Obra criada
 *   - payload carrega título/objetivo/contexto/risco/critérios
 *   - obra_candidate vs forge_obra criam AtlasProject distinto
 *   - quick_intervention NÃO cria AtlasProject
 *   - pedido explícito de humano força forge_obra (target permitido)
 */
class AtlasDevToForgePromotionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSchema();
    }

    public function test_thin_thread_returns_no_promotion(): void
    {
        $thread = $this->makeThread([
            'title' => 'corrige espaçamento do botão',
            'workspace' => 'atlas',
            'metadata' => ['atlas_focus' => 'programming'],
        ]);
        $this->makeMessage($thread, 'user', 'tem um pequeno espaçamento estranho no botão de salvar.');
        $this->makeMessage($thread, 'assistant', 'ajustei o padding em 4px.');

        $preview = app(AtlasDevToForgePromotionService::class)->preview($thread->fresh());

        $this->assertSame(AtlasDevToForgePromotionService::TARGET_NONE, $preview['promotion_target']);
        $this->assertTrue($preview['signals']['thin_small_bug']);
        $this->assertFalse($preview['already_promoted']);
        $this->assertSame('atlas', $preview['workspace']['slug']);
        $this->assertContains(AtlasDevToForgePromotionService::TARGET_NONE, $preview['allowed_targets']);
    }

    public function test_thread_with_risk_and_gates_recommends_obra_candidate(): void
    {
        $thread = $this->makeThread([
            'title' => 'mudar schema da tabela de pagamentos',
            'workspace' => 'blackink',
            'metadata' => ['atlas_focus' => 'programming'],
        ]);
        $this->makeMessage($thread, 'user', 'Precisamos migrar a schema da tabela pagamentos. Está em produção, risco de breaking. Precisa de gates e aceite humano antes de aplicar.');
        $this->makeMessage($thread, 'assistant', 'Antes de tocar, precisamos de uma migração reversível e revisão da arquitetura. Critérios de aceite: nenhuma cobrança falha durante migração.');
        $this->makeMessage($thread, 'user', 'Quais arquivos? app/Models/Payment.php e app/Services/Billing/Charge.php são os principais.');
        $this->makeMessage($thread, 'assistant', 'Também afeta database/migrations/2026_05_15_create_payments.sql.');

        $preview = app(AtlasDevToForgePromotionService::class)->preview($thread->fresh());

        $this->assertSame(AtlasDevToForgePromotionService::TARGET_OBRA_CANDIDATE, $preview['promotion_target']);
        $this->assertTrue($preview['signals']['risk_keywords']);
        $this->assertTrue($preview['signals']['gates_signal']);
        $this->assertContains('app/Models/Payment.php', $preview['known_files']);
        $this->assertNotEmpty($preview['suggested_success_criteria']);
        $this->assertSame('blackink', $preview['workspace']['slug']);
    }

    public function test_explicit_human_request_forces_forge_obra(): void
    {
        $thread = $this->makeThread([
            'title' => 'review do checkout',
            'workspace' => 'blackink',
        ]);
        $this->makeMessage($thread, 'user', 'isso virou obra. cria Obra Forge pra estruturar o checkout inteiro.');
        $this->makeMessage($thread, 'assistant', 'ok, vou estruturar como Obra Forge.');

        $preview = app(AtlasDevToForgePromotionService::class)->preview($thread->fresh());

        $this->assertSame(AtlasDevToForgePromotionService::TARGET_FORGE_OBRA, $preview['promotion_target']);
        $this->assertTrue($preview['signals']['explicit_human_request']);
        $this->assertContains(
            AtlasDevToForgePromotionService::TARGET_FORGE_OBRA,
            $preview['allowed_targets'],
        );
    }

    public function test_preview_does_not_mutate_thread_or_create_obra(): void
    {
        $thread = $this->makeThread(['workspace' => 'atlas']);
        $this->makeMessage($thread, 'user', 'algo simples');

        $obraCountBefore = AtlasProject::query()->count();
        $metadataBefore = $thread->metadata;

        app(AtlasDevToForgePromotionService::class)->preview($thread->fresh());
        app(AtlasDevToForgePromotionService::class)->preview($thread->fresh());

        $this->assertSame($obraCountBefore, AtlasProject::query()->count());
        $this->assertEquals($metadataBefore, $thread->fresh()->metadata);
    }

    public function test_obra_candidate_creation_makes_atlas_project_with_back_link(): void
    {
        $thread = $this->makeThread([
            'title' => 'migrar schema pagamentos',
            'workspace' => 'blackink',
        ]);
        $this->makeMessage($thread, 'user', 'Migração schema pagamentos production breaking. Vamos discutir arquitetura, gates e evidência.');
        $this->makeMessage($thread, 'assistant', 'Vamos planejar. app/Models/Payment.php e app/Services/Billing/Charge.php.');

        $obrasBefore = AtlasProject::query()->count();

        $result = app(AtlasDevToForgePromotionService::class)->promote(
            thread: $thread->fresh(),
            requestedTarget: AtlasDevToForgePromotionService::TARGET_OBRA_CANDIDATE,
        );

        $this->assertSame('promoted', $result['status']);
        $this->assertNotNull($result['created_obra_id']);
        $this->assertSame(AtlasDevToForgePromotionService::TARGET_OBRA_CANDIDATE, $result['promotion_target']);
        $this->assertSame($obrasBefore + 1, AtlasProject::query()->count());

        $obra = AtlasProject::query()->findOrFail($result['created_obra_id']);
        $this->assertSame('atlas-dev-promotion', $obra->metadata['origin']);
        $this->assertSame('obra_candidate', $obra->metadata['promotion_target']);
        $this->assertSame((string) $thread->id, $obra->metadata['source_thread_id']);
        $this->assertSame('blackink', $obra->metadata['workspace_slug']);

        $threadFresh = $thread->fresh();
        $this->assertNotNull(data_get($threadFresh->metadata, 'latest_dev_to_forge_promotion'));
        $this->assertSame(
            (string) $obra->getKey(),
            (string) data_get($threadFresh->metadata, 'latest_dev_to_forge_promotion.created_obra_id'),
        );
    }

    public function test_quick_intervention_does_not_create_an_obra(): void
    {
        $thread = $this->makeThread(['workspace' => 'atlas']);
        $this->makeMessage($thread, 'user', 'tem dois arquivos divergentes: app/A.php e app/B.php. tentei consertar e falhou de novo, tentei de novo e ainda não funcionou.');

        $obrasBefore = AtlasProject::query()->count();

        $result = app(AtlasDevToForgePromotionService::class)->promote(
            thread: $thread->fresh(),
            requestedTarget: AtlasDevToForgePromotionService::TARGET_QUICK_INTERVENTION,
        );

        $this->assertSame('promoted', $result['status']);
        $this->assertNull($result['created_obra_id']);
        $this->assertSame($obrasBefore, AtlasProject::query()->count());

        $threadFresh = $thread->fresh();
        $this->assertSame(
            'quick_intervention',
            (string) data_get($threadFresh->metadata, 'latest_dev_to_forge_promotion.promotion_target'),
        );
    }

    public function test_promote_rejects_target_outside_allowed_for_thin_thread(): void
    {
        $thread = $this->makeThread(['workspace' => 'atlas']);
        $this->makeMessage($thread, 'user', 'ajuste cor de botão');

        $this->expectException(\InvalidArgumentException::class);
        app(AtlasDevToForgePromotionService::class)->promote(
            thread: $thread->fresh(),
            requestedTarget: AtlasDevToForgePromotionService::TARGET_FORGE_OBRA,
        );
    }

    public function test_endpoint_returns_preview_payload(): void
    {
        $thread = $this->makeThread(['workspace' => 'atlas']);
        $this->makeMessage($thread, 'user', 'ajuste pequeno');

        $this->getJson(
            '/atlas-code/promotion/preview/'.$thread->id,
            $this->headers(),
        )
            ->assertOk()
            ->assertJsonPath('schema_version', AtlasDevToForgePromotionService::SCHEMA_VERSION)
            ->assertJsonPath('promotion_target', AtlasDevToForgePromotionService::TARGET_NONE);
    }

    public function test_endpoint_rejects_unknown_target(): void
    {
        $thread = $this->makeThread(['workspace' => 'atlas']);
        $this->makeMessage($thread, 'user', 'ajuste pequeno');

        $this->postJson(
            '/atlas-code/promotion/'.$thread->id.'/promote',
            ['promotion_target' => 'nuke_repo'],
            $this->headers(),
        )->assertStatus(422);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeThread(array $overrides = []): AiThread
    {
        return AiThread::query()->create(array_merge([
            'id' => (string) Str::uuid(),
            'title' => 'thread promotion test',
            'status' => 'active',
            'surface' => 'atlas_desktop_ai',
            'workspace' => 'atlas',
            'message_count' => 0,
            'metadata' => [],
        ], $overrides));
    }

    private function makeMessage(AiThread $thread, string $role, string $content): AiMessage
    {
        $position = (int) ($thread->messages()->max('position') ?? 0) + 1;
        $message = AiMessage::query()->create([
            'id' => (string) Str::uuid(),
            'thread_id' => $thread->id,
            'position' => $position,
            'role' => $role,
            'status' => 'completed',
            'content' => $content,
            'metadata' => [],
        ]);

        $thread->forceFill([
            'message_count' => $position,
            'last_message_at' => now(),
        ])->save();

        return $message;
    }

    /**
     * @return array<string, string>
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
        if (! Schema::hasTable('ai_threads')) {
            Schema::create('ai_threads', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('title')->nullable();
                $t->text('summary')->nullable();
                $t->string('status')->default('active');
                $t->string('surface')->nullable();
                $t->string('workspace')->nullable();
                $t->string('source_type')->nullable();
                $t->uuid('source_id')->nullable();
                $t->uuid('last_trace_id')->nullable();
                $t->string('last_provider')->nullable();
                $t->integer('message_count')->default(0);
                $t->timestamp('last_message_at')->nullable();
                $t->json('metadata')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('ai_messages')) {
            Schema::create('ai_messages', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('thread_id');
                $t->uuid('trace_id')->nullable();
                $t->integer('position')->default(0);
                $t->string('role');
                $t->string('status')->default('completed');
                $t->text('content')->nullable();
                $t->string('provider')->nullable();
                $t->string('model')->nullable();
                $t->string('agent_slug')->nullable();
                $t->integer('token_estimate')->nullable();
                $t->timestamp('occurred_at')->nullable();
                $t->json('metadata')->nullable();
                $t->timestamps();
                $t->index('thread_id');
            });
        }
    }
}
