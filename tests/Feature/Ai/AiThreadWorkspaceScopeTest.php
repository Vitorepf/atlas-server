<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AiMessage;
use App\Models\AiThread;
use App\Models\AtlasWorkspaceArtifactLakeEntry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AiThreadWorkspaceScopeTest extends TestCase
{
    private array $headers = [
        'Accept' => 'application/json',
        'X-Atlas-Token' => 'test-token-with-enough-length-123',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');

        Schema::dropIfExists('ai_threads');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('atlas_workspace_artifact_lake_entries');
        Schema::create('ai_threads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('title');
            $table->text('summary')->nullable();
            $table->string('status')->default('active');
            $table->string('surface')->default('app');
            $table->text('workspace')->nullable();
            $table->string('source_type')->nullable();
            $table->uuid('source_id')->nullable();
            $table->uuid('last_trace_id')->nullable();
            $table->string('last_provider')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });
        Schema::create('ai_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id');
            $table->uuid('trace_id')->nullable();
            $table->integer('position');
            $table->string('role');
            $table->string('status')->default('final');
            $table->text('content');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('agent_slug')->nullable();
            $table->integer('token_estimate')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('atlas_workspace_artifact_lake_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace_id', 120)->index();
            $table->string('runtime_hash', 64)->index();
            $table->string('artifact_hash', 64)->index();
            $table->string('artifact_type', 120)->index();
            $table->string('status', 40)->index();
            $table->string('consumer', 120)->nullable()->index();
            $table->json('source_hashes');
            $table->json('body');
            $table->decimal('quality_score', 5, 2)->default(0);
            $table->timestamp('captured_at')->index();
            $table->timestamps();
            $table->unique(['runtime_hash', 'artifact_hash']);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_threads');
        Schema::dropIfExists('atlas_workspace_artifact_lake_entries');

        parent::tearDown();
    }

    public function test_thread_created_with_workspace_slug_gets_awis_scope_metadata(): void
    {
        $response = $this->withHeaders($this->headers)->postJson('/ai/threads', [
            'title' => 'Workspace scoped chat',
            'workspace' => 'atlas',
            'surface' => 'atlas_desktop_ai',
            'source_type' => 'desktop',
            'metadata' => [
                'created_via' => 'atlas_desktop_ai',
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('thread.workspace', 'atlas')
            ->assertJsonPath('thread.metadata.workspace_slug', 'atlas')
            ->assertJsonPath('thread.metadata.awis_workspace_scope.schema_version', 'atlas.ai_thread.workspace_scope.v1');

        $this->assertNotEmpty($response->json('thread.metadata.workspace_path'));
    }

    public function test_workspace_filter_matches_slug_path_and_metadata_aliases(): void
    {
        AiThread::query()->create([
            'title' => 'Slug row',
            'status' => 'active',
            'surface' => 'atlas_desktop_ai',
            'workspace' => 'atlas',
            'metadata' => [],
        ]);
        AiThread::query()->create([
            'title' => 'Path row',
            'status' => 'active',
            'surface' => 'atlas_desktop_ai',
            'workspace' => base_path('..'),
            'metadata' => [],
        ]);
        AiThread::query()->create([
            'title' => 'Metadata row',
            'status' => 'active',
            'surface' => 'atlas_desktop_ai',
            'workspace' => null,
            'metadata' => [
                'workspace_slug' => 'atlas',
            ],
        ]);
        AiThread::query()->create([
            'title' => 'Other row',
            'status' => 'active',
            'surface' => 'atlas_desktop_ai',
            'workspace' => 'blackink',
            'metadata' => [],
        ]);

        $response = $this->withHeaders($this->headers)->getJson('/ai/threads?status=active&workspace=atlas&light=1');
        $response->assertOk();

        $titles = collect($response->json('threads'))->pluck('title')->all();
        $this->assertContains('Slug row', $titles);
        $this->assertContains('Path row', $titles);
        $this->assertContains('Metadata row', $titles);
        $this->assertNotContains('Other row', $titles);
    }

    public function test_patch_can_move_thread_to_workspace_scope(): void
    {
        $thread = AiThread::query()->create([
            'title' => 'Unscoped',
            'status' => 'active',
            'surface' => 'atlas_desktop_ai',
            'workspace' => null,
            'metadata' => [],
        ]);

        $response = $this->withHeaders($this->headers)->patchJson("/ai/threads/{$thread->id}", [
            'workspace' => 'atlas',
            'metadata' => [
                'moved_via' => 'atlas_desktop_drag_drop',
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('thread.workspace', 'atlas')
            ->assertJsonPath('thread.metadata.workspace_slug', 'atlas')
            ->assertJsonPath('thread.metadata.moved_via', 'atlas_desktop_drag_drop');
    }

    public function test_workspace_conversation_fusion_merges_threads_without_raw_prompt_dump(): void
    {
        $atlas = $this->threadWithMessages('atlas', 'Login bug', [
            'Estou com um bug na tela de login e precisamos decidir o caminho.',
            'Decidido: corrigir sessão antes de mexer no design. Bloqueio: teste de auth falhando.',
        ]);
        $other = $this->threadWithMessages('blackink', 'Other project', [
            'Esse conteúdo de outro workspace não pode entrar no fusion pack.',
        ]);

        $response = $this->withHeaders($this->headers)->getJson('/ai/workspaces/atlas/conversation-fusion?limit=10');

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.workspace_conversation_fusion.v1')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('workspace_id', 'atlas')
            ->assertJsonPath('source_policy.raw_conversation_returned', false)
            ->assertJsonPath('claim_policy.spends_tokens', false);

        $threadIds = collect($response->json('fusion_pack.source_thread_ids'))->all();
        $this->assertContains($atlas->id, $threadIds);
        $this->assertNotContains($other->id, $threadIds);
        $this->assertSame(1, $response->json('summary.thread_count'));
        $this->assertGreaterThanOrEqual(1, $response->json('summary.decision_count'));
        $this->assertGreaterThanOrEqual(1, $response->json('summary.blocker_count'));
        $this->assertStringNotContainsString('outro workspace não pode entrar', json_encode($response->json(), JSON_UNESCAPED_UNICODE));
    }

    public function test_workspace_conversation_fusion_rejects_explicit_cross_workspace_thread_ids(): void
    {
        $atlas = $this->threadWithMessages('atlas', 'Atlas thread', [
            'Decisão: manter fusion dentro do workspace Atlas.',
        ]);
        $other = $this->threadWithMessages('blackink', 'Blackink thread', [
            'Segredo operacional de outro workspace que nunca pode entrar no pack.',
        ]);

        $response = $this->withHeaders($this->headers)->getJson(
            '/ai/workspaces/atlas/conversation-fusion?'.http_build_query([
                'thread' => [$atlas->id, $other->id],
            ]),
        );

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.workspace_conversation_fusion.v1')
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('workspace_id', 'atlas')
            ->assertJsonPath('blockers.0', 'thread_outside_workspace_or_missing')
            ->assertJsonPath('claim_policy.cross_workspace_merge_allowed', false)
            ->assertJsonPath('source_policy.raw_conversation_returned', false);

        $this->assertContains($other->id, $response->json('rejected_thread_ids'));
        $this->assertStringNotContainsString('Segredo operacional de outro workspace', json_encode($response->json(), JSON_UNESCAPED_UNICODE));
    }

    public function test_workspace_conversation_fusion_command_returns_provider_safe_pack(): void
    {
        $this->threadWithMessages('atlas', 'Runtime decision', [
            'Decision: manter workspace lock por conversa.',
        ]);

        $exit = Artisan::call('atlas:workspace-intelligence', [
            'action' => 'conversation-fusion',
            '--workspace' => 'atlas',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.workspace_conversation_fusion.v1', $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertFalse($payload['source_policy']['raw_conversation_returned']);
        $this->assertFalse($payload['claim_policy']['invokes_provider']);
        $this->assertNotEmpty($payload['fusion_hash']);
    }

    public function test_workspace_conversation_fusion_can_persist_provider_safe_artifact(): void
    {
        $this->threadWithMessages('atlas', 'Merge room', [
            'Decisão: usar workspace fusion como artefato AWIS.',
            'Bloqueio: nao enviar conversa bruta para provider.',
        ]);

        $exit = Artisan::call('atlas:workspace-intelligence', [
            'action' => 'conversation-fusion',
            '--workspace' => 'atlas',
            '--persist' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('conversation_fusion_pack', $payload['persisted_artifact']['artifact_type']);
        $this->assertSame(1, AtlasWorkspaceArtifactLakeEntry::query()
            ->where('workspace_id', 'atlas')
            ->where('artifact_type', 'conversation_fusion_pack')
            ->count());
        $entry = AtlasWorkspaceArtifactLakeEntry::query()->firstOrFail();
        $this->assertFalse((bool) data_get($entry->body, 'source_policy.raw_conversation_returned'));
        $this->assertSame($payload['fusion_pack']['fusion_pack_hash'], $entry->artifact_hash);
    }

    public function test_workspace_artifact_lake_inspects_persisted_fusion_pack_without_raw_conversation_tail(): void
    {
        $rawTail = 'SEGREDO_TAIL_NUNCA_DEVE_APARECER_NO_ARTIFACT_REPLAY';
        $this->threadWithMessages('atlas', 'Long fusion source', [
            str_repeat('Contexto longo de decisao AWIS. ', 20).$rawTail,
            'Bloqueio: provider recebe somente pack seguro por hash.',
        ]);

        Artisan::call('atlas:workspace-intelligence', [
            'action' => 'conversation-fusion',
            '--workspace' => 'atlas',
            '--persist' => true,
            '--json' => true,
        ]);
        $entry = AtlasWorkspaceArtifactLakeEntry::query()->firstOrFail();

        $index = $this->withHeaders($this->headers)->getJson('/atlas-code/workspace-intelligence/artifact-lake?workspace=atlas&type=conversation_fusion_pack');
        $index
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.workspace_artifact_lake_index.v1')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('count', 1)
            ->assertJsonPath('artifacts.0.artifact_type', 'conversation_fusion_pack');
        $this->assertArrayNotHasKey('body', $index->json('artifacts.0'));

        $show = $this->withHeaders($this->headers)->getJson('/atlas-code/workspace-intelligence/artifact-lake/'.$entry->id.'?workspace=atlas');
        $show
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.workspace_artifact_lake_entry.v1')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('artifact.artifact_type', 'conversation_fusion_pack')
            ->assertJsonPath('source_policy.raw_conversation_returned', false)
            ->assertJsonPath('source_policy.full_message_content_returned', false)
            ->assertJsonPath('replay_contract.raw_conversation_replay_allowed', false)
            ->assertJsonPath('replay_contract.provider_prompt_allowed', true);
        $this->assertStringNotContainsString($rawTail, json_encode($show->json(), JSON_THROW_ON_ERROR));

        $byHash = $this->withHeaders($this->headers)->getJson('/atlas-code/workspace-intelligence/artifact-lake/'.$entry->artifact_hash.'?workspace=atlas');
        $byHash
            ->assertOk()
            ->assertJsonPath('artifact.artifact_id', (string) $entry->id);

        $wrongWorkspace = $this->withHeaders($this->headers)->getJson('/atlas-code/workspace-intelligence/artifact-lake/'.$entry->id.'?workspace=blackink');
        $wrongWorkspace
            ->assertNotFound()
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('blockers.0', 'artifact_not_found_in_workspace');
    }

    /**
     * @param  array<int,string>  $messages
     */
    private function threadWithMessages(string $workspace, string $title, array $messages): AiThread
    {
        $thread = AiThread::query()->create([
            'title' => $title,
            'status' => 'active',
            'surface' => 'atlas_desktop_ai',
            'workspace' => $workspace,
            'message_count' => count($messages),
            'last_message_at' => now(),
            'metadata' => ['workspace_slug' => $workspace],
        ]);

        foreach ($messages as $index => $content) {
            AiMessage::query()->create([
                'thread_id' => $thread->id,
                'position' => $index + 1,
                'role' => $index % 2 === 0 ? 'user' : 'assistant',
                'status' => 'final',
                'content' => $content,
                'token_estimate' => str_word_count($content),
                'occurred_at' => now()->addSeconds($index),
                'metadata' => [],
            ]);
        }

        return $thread;
    }
}
