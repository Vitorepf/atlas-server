<?php

namespace Tests\Feature\Ai\Search;

use App\Models\AiMessage;
use App\Models\AiThread;
use App\Services\Ai\Runtime\AiToolRuntime;
use App\Services\Ai\Runtime\PermissionRequest;
use App\Services\Ai\Runtime\ToolInvocation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SessionSearchRuntimeTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-session-search-runtime-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);
        $this->workspace = realpath($this->workspace) ?: $this->workspace;
        config([
            'atlas.ai.workdir' => $this->workspace,
            'atlas.ai.tool_permissions.allowed_roots' => [$this->workspace],
        ]);
        $this->createAiSearchTables();
    }

    protected function tearDown(): void
    {
        $this->dropAiSearchTables();
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_session_search_is_native_read_only_runtime_tool(): void
    {
        $this->thread('Thread de decisao', [
            'A decisao anterior foi manter Atlas CLI como superficie principal do Mac.',
        ]);

        $invocation = ToolInvocation::make('session.search', $this->workspace, [
            'query' => 'decisao anterior Atlas CLI',
            'top_n' => 3,
            'summarize' => false,
        ]);

        $permission = PermissionRequest::fromInvocation($invocation);
        $result = app(AiToolRuntime::class)->execute($invocation);

        $this->assertContains('session.search', AiToolRuntime::availableTools());
        $this->assertSame('read', $permission->requiredMode);
        $this->assertSame('low', $permission->risk);
        $this->assertTrue($result->ok);
        $this->assertSame('session.search', $result->tool);
        $this->assertSame(1, $result->metadata['count']);
        $this->assertStringContainsString('superficie principal', $result->output);
    }

    public function test_atlas_runtime_command_executes_session_search(): void
    {
        $this->thread('Thread via comando', [
            'Busca de sessao pelo comando atlas runtime session.search.',
        ]);

        $this->artisan('atlas:runtime', [
            'tool' => 'session.search',
            'arguments' => ['Busca de sessao'],
            '--workspace' => $this->workspace,
            '--json' => true,
        ])->assertExitCode(0);
    }

    public function test_atlas_runtime_command_blocks_mutative_tool_without_awis_workspace(): void
    {
        $this->artisan('atlas:runtime', [
            'tool' => 'file.write',
            'arguments' => ['notes.txt'],
            '--workspace' => $this->workspace,
            '--content' => 'nao deve escrever',
            '--permission' => 'write',
            '--yes' => true,
            '--json' => true,
        ])->assertExitCode(1);

        $this->assertFalse(File::exists($this->workspace.'/notes.txt'));
    }

    /**
     * @param  array<int,string>  $messages
     */
    private function thread(string $title, array $messages): AiThread
    {
        $thread = AiThread::query()->create([
            'title' => $title,
            'summary' => null,
            'status' => 'active',
            'surface' => 'atlas_cli',
            'workspace' => $this->workspace,
            'message_count' => count($messages),
            'last_message_at' => now(),
            'metadata' => [],
        ]);

        foreach ($messages as $index => $content) {
            AiMessage::query()->create([
                'thread_id' => $thread->id,
                'position' => $index + 1,
                'role' => 'user',
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
