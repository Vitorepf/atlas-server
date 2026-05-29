<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\MinimaxFirst;

use App\Services\Ai\Programming\AtlasDev\MinimaxFirst\AtlasMinimaxContextCompilerService;
use PHPUnit\Framework\TestCase;

final class AtlasMinimaxContextCompilerServiceTest extends TestCase
{
    private function service(): AtlasMinimaxContextCompilerService
    {
        return new AtlasMinimaxContextCompilerService();
    }

    private function finding(string $title = 'Test task'): array
    {
        return [
            'title'       => $title,
            'description' => 'Implement X so that Y works correctly',
            'origin_type' => 'self_construction_admission_packet',
        ];
    }

    public function test_compile_returns_manifest_with_all_required_fields(): void
    {
        $result = $this->service()->compile($this->finding(), [], '/tmp');

        $this->assertSame(AtlasMinimaxContextCompilerService::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('system_prompt', $result);
        $this->assertArrayHasKey('user_prompt', $result);
        $this->assertArrayHasKey('files_included', $result);
        $this->assertArrayHasKey('estimated_tokens', $result);
        $this->assertArrayHasKey('manifest', $result);
        $this->assertSame('MiniMax-M2.7', $result['manifest']['model']);
        $this->assertArrayHasKey('messages', $result['manifest']);
    }

    public function test_compile_embeds_codex_plan_when_provided(): void
    {
        $plan   = ['file' => 'app/Foo.php', 'method' => 'handle', 'logic' => 'return true;'];
        $result = $this->service()->compile($this->finding(), [], '/tmp', $plan);

        $this->assertStringContainsString('CODEX PLAN', $result['user_prompt']);
        $this->assertStringContainsString('app/Foo.php', $result['user_prompt']);
    }

    public function test_compile_handles_nonexistent_file_gracefully(): void
    {
        $result = $this->service()->compile($this->finding(), ['app/Nonexistent.php'], '/nonexistent/repo');

        $this->assertStringContainsString('does not exist', $result['user_prompt']);
        $this->assertSame(['app/Nonexistent.php'], $result['files_included']);
    }

    public function test_compile_respects_token_budget_by_truncating(): void
    {
        $finding = array_merge($this->finding(), ['description' => str_repeat('x', 400_000)]);
        $result  = $this->service()->compile($finding, [], '/tmp');

        $this->assertLessThanOrEqual(AtlasMinimaxContextCompilerService::MAX_TOKENS + 300, $result['estimated_tokens']);
    }

    public function test_estimate_tokens_is_chars_divided_by_4(): void
    {
        $result      = $this->service()->compile($this->finding('short'), [], '/tmp');
        $totalChars  = mb_strlen($result['system_prompt']) + mb_strlen($result['user_prompt']);
        $expected    = (int) ceil($totalChars / 4);

        $this->assertSame($expected, $result['estimated_tokens']);
    }

    public function test_system_prompt_contains_atlas_standards(): void
    {
        $result = $this->service()->compile($this->finding(), [], '/tmp');

        $this->assertStringContainsString('declare(strict_types=1)', $result['system_prompt']);
        $this->assertStringContainsString('final class', $result['system_prompt']);
        $this->assertStringContainsString('// FILE:', $result['system_prompt']);
    }
}
