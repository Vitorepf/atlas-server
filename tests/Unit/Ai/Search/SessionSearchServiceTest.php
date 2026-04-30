<?php

namespace Tests\Unit\Ai\Search;

use App\Models\AiMessage;
use App\Models\AiThread;
use App\Services\Ai\Search\SessionSearchService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SessionSearchServiceTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-session-search-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);
        $this->workspace = realpath($this->workspace) ?: $this->workspace;
        $this->createAiSearchTables();
    }

    protected function tearDown(): void
    {
        $this->dropAiSearchTables();
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_search_returns_relevant_threads_grouped_by_workspace_thread(): void
    {
        $thread = $this->thread('Continuidade Atlas', [
            'Decidimos que o Atlas CLI deve preservar contexto entre Claude e Codex usando session_search profissional.',
            'O operador quer continuidade sem anunciar contexto interno.',
            'OPENAI_API_KEY=sk-proj-abcdefghijklmnopqrstuvwxyz123456',
        ]);
        $this->thread('Assunto irrelevante', [
            'Planejamento de treino e alimentacao sem relacao com desenvolvimento.',
        ]);

        $results = app(SessionSearchService::class)->search($this->workspace, '"preservar contexto" Atlas', topN: 5);

        $this->assertCount(1, $results);
        $this->assertSame($thread->id, $results[0]->threadId);
        $this->assertStringContainsString('preservar contexto', $results[0]->excerpt);
        $this->assertStringContainsString('[redacted]', $results[0]->excerpt);
        $this->assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz123456', $results[0]->excerpt);
    }

    public function test_search_supports_or_not_and_prefix_syntax_in_fallback(): void
    {
        $expected = $this->thread('Auth mobile', [
            'login social autenticacao para atlas mobile',
        ]);
        $this->thread('Billing login', [
            'login billing checkout invoice',
        ]);
        $prefix = $this->thread('Implementacao', [
            'implementacao profunda de session search com progressive context',
        ]);

        $orResults = app(SessionSearchService::class)->search($this->workspace, 'login OR autenticacao NOT billing', topN: 5);
        $prefixResults = app(SessionSearchService::class)->search($this->workspace, 'implem*', topN: 5);

        $this->assertSame([$expected->id], collect($orResults)->pluck('threadId')->all());
        $this->assertSame([$prefix->id], collect($prefixResults)->pluck('threadId')->all());
    }

    public function test_search_can_return_local_compact_summary(): void
    {
        $this->thread('Longa conversa', [
            'Primeiro ponto: Atlas precisa lembrar sessoes longas com evidencia.',
            'Segundo ponto: a busca deve recuperar trechos centrados no match.',
            'Terceiro ponto: resumo local serve para entrada compacta antes do provider.',
        ]);

        $results = app(SessionSearchService::class)->search($this->workspace, 'busca recuperar trechos', topN: 1, summarize: true);

        $this->assertCount(1, $results);
        $this->assertStringStartsWith('Resumo local para query [busca recuperar trechos]:', $results[0]->excerpt);
        $this->assertTrue((bool) $results[0]->metadata['summarized']);
        $this->assertSame('local_extractive', $results[0]->metadata['summary_mode']);
    }

    /**
     * @param  array<int,string>  $messages
     */
    private function thread(string $title, array $messages, ?string $workspace = null): AiThread
    {
        $thread = AiThread::query()->create([
            'title' => $title,
            'summary' => null,
            'status' => 'active',
            'surface' => 'atlas_cli',
            'workspace' => $workspace ?: $this->workspace,
            'message_count' => count($messages),
            'last_message_at' => now(),
            'metadata' => [],
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

    private function createAiSearchTables(): void
    {
        $this->dropAiSearchTables();

        Schema::create('ai_threads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('title');
            $table->text('summary')->nullable();
            $table->string('status')->default('active');
            $table->string('surface')->default('app');
            $table->string('workspace')->nullable();
            $table->string('source_type')->nullable();
            $table->uuid('source_id')->nullable();
            $table->uuid('last_trace_id')->nullable();
            $table->string('last_provider')->nullable();
            $table->integer('message_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->json('metadata')->nullable();
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
    }

    private function dropAiSearchTables(): void
    {
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_threads');
    }
}
