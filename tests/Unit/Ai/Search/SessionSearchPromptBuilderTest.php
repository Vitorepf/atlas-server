<?php

namespace Tests\Unit\Ai\Search;

use App\Services\Ai\Context\AiContextPackBuilder;
use App\Services\Ai\AiPromptBuilder;
use App\Services\Ai\Router\AiIntentRouter;
use App\Services\Ai\Search\SearchResult;
use App\Services\Ai\Search\SessionSearchService;
use App\Services\Ai\Skills\AiSkillStore;
use App\Services\Ai\Skills\SkillBundleStore;
use App\Services\Ai\Skills\SkillDiscoveryService;
use DateTimeImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SessionSearchPromptBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createAiSearchTables();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_threads');

        parent::tearDown();
    }

    public function test_prompt_builder_prefetches_session_context_for_historical_continuity_requests(): void
    {
        $sessionSearch = $this->createMock(SessionSearchService::class);
        $sessionSearch->expects($this->once())
            ->method('search')
            ->with('/workspace/atlas', $this->stringContains('voltando ao que falamos'), 3, true)
            ->willReturn([
                new SearchResult(
                    threadId: 'thread-1',
                    threadTitle: 'Decisao auth',
                    lastMessageAt: new DateTimeImmutable('2026-04-30T12:00:00+00:00'),
                    excerpt: 'Resumo local: decidimos preservar contexto historico antes de responder.',
                    rank: 0.42,
                    matchPosition: 2,
                    source: 'fallback_like',
                ),
            ]);

        $section = $this->sessionSection($sessionSearch, 'voltando ao que falamos antes sobre auth', [
            'payload' => ['workspace' => '/workspace/atlas'],
        ]);

        $this->assertStringContainsString('Contexto recuperado de sessoes anteriores', $section);
        $this->assertStringContainsString('session_search_result', $section);
        $this->assertStringContainsString('Decisao auth', $section);
        $this->assertStringContainsString('preservar contexto historico', $section);
    }

    public function test_prompt_builder_does_not_prefetch_when_session_search_is_disabled(): void
    {
        $sessionSearch = $this->createMock(SessionSearchService::class);
        $sessionSearch->expects($this->never())->method('search');

        $section = $this->sessionSection($sessionSearch, 'voltando ao que falamos antes sobre auth', [
            'payload' => [
                'workspace' => '/workspace/atlas',
                'session_search' => false,
            ],
        ]);

        $this->assertSame('', $section);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function sessionSection(SessionSearchService $sessionSearch, string $input, array $options): string
    {
        $builder = new AiPromptBuilder(
            $this->createMock(AiSkillStore::class),
            $this->createMock(AiIntentRouter::class),
            $this->createMock(AiContextPackBuilder::class),
            $this->createMock(SkillDiscoveryService::class),
            $this->createMock(SkillBundleStore::class),
            $sessionSearch,
        );

        $method = new \ReflectionMethod(AiPromptBuilder::class, 'sessionSearchSection');
        $method->setAccessible(true);

        return (string) $method->invoke($builder, $input, $options);
    }

    private function createAiSearchTables(): void
    {
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_threads');

        Schema::create('ai_threads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('title');
            $table->text('summary')->nullable();
            $table->string('status')->default('active');
            $table->string('surface')->default('app');
            $table->string('workspace')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id');
            $table->integer('position');
            $table->string('role');
            $table->string('status')->default('final');
            $table->text('content');
            $table->timestamp('occurred_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }
}
