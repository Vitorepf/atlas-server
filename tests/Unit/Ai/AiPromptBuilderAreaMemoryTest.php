<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Services\Ai\AiPromptBuilder;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevFailureCapsuleRuntimeService;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevTaskPacketRuntimeService;
use App\Services\Ai\Programming\AtlasDev\Support\WorkspaceOriginIdentity;
use Tests\TestCase;

/**
 * Memória de falha da área no PROMPT DO CHAT de programação — o caminho
 * interativo recebe o mesmo canal known_failure_modes que o pipeline Dev e a
 * esteira já recebem (M5/S2): o modelo agêntico entra sabendo o que já falhou
 * nos arquivos-alvo. É a peça que faz Atlas-chat > provider cru.
 */
final class AiPromptBuilderAreaMemoryTest extends TestCase
{
    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migration = require base_path('database/migrations/2026_05_22_160000_create_atlas_dev_runtime_intelligence_tables.php');
        $this->migration->down();
        $this->migration->up();
    }

    protected function tearDown(): void
    {
        $this->migration->down();
        parent::tearDown();
    }

    private function section(array $payload): string
    {
        $builder = app(AiPromptBuilder::class);
        $method = new \ReflectionMethod($builder, 'programmingAreaFailureMemory');
        $method->setAccessible(true);

        return (string) $method->invoke($builder, ['payload' => $payload]);
    }

    public function test_programming_chat_prompt_carries_area_failure_memory(): void
    {
        $workspace = '/tmp/area-memory-ws';
        $file = 'app/Services/Demo/AreaTarget.php';

        $packet = (new DevTaskPacketRuntimeService)->persist([
            'run_id' => 'chat-area-1',
            'task_id' => 'chat-area-1',
            'objective' => 'fix AreaTarget',
            'workspace_slug' => WorkspaceOriginIdentity::slug($workspace),
            'allowed_files' => [$file],
            'source' => 'test',
        ]);
        (new DevFailureCapsuleRuntimeService)->persist([
            'run_id' => 'chat-area-1',
            'task_id' => 'chat-area-1',
            'failing_gate' => 'verification_gate',
            'error_excerpt' => 'AreaTargetTest failed asserting flag persists',
            'changed_files' => [$file],
        ], $packet);

        $section = $this->section([
            'atlas_mode' => 'programming',
            'tool_permissions' => ['workspace' => $workspace, 'allowed_files' => [$file]],
        ]);

        $this->assertStringContainsString('Memória da área', $section);
        $this->assertStringContainsString('failure_class=', $section);
    }

    public function test_non_programming_or_fileless_payload_gets_no_section(): void
    {
        $this->assertSame('', $this->section([
            'atlas_mode' => 'programming',
            'tool_permissions' => ['workspace' => '/tmp/x'], // sem arquivos-alvo
        ]));
        $this->assertSame('', $this->section([
            'tool_permissions' => ['workspace' => '/tmp/x', 'allowed_files' => ['app/A.php']], // sem mode
        ]));
    }
}
