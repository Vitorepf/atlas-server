<?php

namespace Tests\Unit;

use App\Models\AiJob;
use App\Services\Ai\ClaudeCliProvider;
use App\Services\Ai\CodexCliProvider;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AiCliProviderRuntimeArgsTest extends TestCase
{
    private string $workspace;

    private string $operatorRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir().'/atlas-provider-runtime-test-'.bin2hex(random_bytes(4));
        $this->operatorRoot = $base.'/home';
        $this->workspace = $this->operatorRoot.'/Develop/atlas';

        File::ensureDirectoryExists($this->workspace);
        File::ensureDirectoryExists($this->operatorRoot.'/Develop/Blackink');
        $this->operatorRoot = realpath($this->operatorRoot) ?: $this->operatorRoot;
        $this->workspace = realpath($this->workspace) ?: $this->workspace;
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->operatorRoot));

        parent::tearDown();
    }

    public function test_claude_provider_maps_danger_runtime_to_bypass_permissions_and_allowed_roots(): void
    {
        $binary = $this->fakeClaudeBinary();

        config([
            'atlas.ai.providers.claude_cli.binary' => $binary,
            'atlas.ai.providers.claude_cli.args' => ['-p', '--output-format', 'stream-json', '--verbose', '--no-session-persistence'],
        ]);

        $result = app(ClaudeCliProvider::class)->runStreaming($this->job(), 'teste');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertContains('--permission-mode', $result->command);
        $this->assertSame('bypassPermissions', $result->command[array_search('--permission-mode', $result->command, true) + 1]);
        $this->assertContains('--add-dir', $result->command);
        $this->assertContains($this->operatorRoot, $result->command);
        $this->assertContains($this->workspace, $result->command);
    }

    public function test_codex_provider_maps_danger_runtime_to_full_access_never_approval_and_allowed_roots(): void
    {
        $binary = $this->fakeCodexBinary();

        config([
            'atlas.ai.providers.codex_cli.binary' => $binary,
            'atlas.ai.providers.codex_cli.args' => ['exec', '--skip-git-repo-check'],
        ]);

        $result = app(CodexCliProvider::class)->runStreaming($this->job(), 'teste');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertContains('--sandbox', $result->command);
        $this->assertSame('danger-full-access', $result->command[array_search('--sandbox', $result->command, true) + 1]);
        $this->assertContains('--ask-for-approval', $result->command);
        $this->assertSame('never', $result->command[array_search('--ask-for-approval', $result->command, true) + 1]);
        $this->assertContains('--add-dir', $result->command);
        $this->assertContains($this->operatorRoot, $result->command);
        $this->assertContains($this->workspace, $result->command);
    }

    public function test_codex_provider_forwards_image_attachments_to_cli(): void
    {
        $binary = $this->fakeCodexBinary();
        $image = $this->workspace.'/screenshot.png';
        File::put($image, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));

        config([
            'atlas.ai.providers.codex_cli.binary' => $binary,
            'atlas.ai.providers.codex_cli.args' => ['exec', '--skip-git-repo-check'],
        ]);

        $result = app(CodexCliProvider::class)->runStreaming($this->job([
            'attachments' => [
                'images' => [
                    ['path' => $image],
                ],
            ],
        ]), 'analise a imagem');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertContains('--image', $result->command);
        $this->assertSame($image, $result->command[array_search('--image', $result->command, true) + 1]);
    }

    public function test_provider_default_model_identity_is_not_sent_as_cli_model_argument(): void
    {
        $binary = $this->fakeClaudeBinary();

        config([
            'atlas.ai.providers.claude_cli.binary' => $binary,
            'atlas.ai.providers.claude_cli.args' => ['-p'],
        ]);

        $job = $this->job([
            'model_identity_source' => 'provider_default_identity',
        ]);
        $job->model = 'claude_cli_default';

        $result = app(ClaudeCliProvider::class)->runStreaming($job, 'teste');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertNotContains('--model', $result->command);
    }

    private function job(array $payload = []): AiJob
    {
        return new AiJob([
            'timeout_seconds' => 15,
            'payload' => array_replace_recursive([
                'workspace' => $this->workspace,
                'tool_permissions' => [
                    'mode' => 'danger',
                    'workspace' => $this->workspace,
                    'codex_sandbox' => 'danger-full-access',
                    'allowed_roots' => [$this->operatorRoot, $this->workspace],
                ],
            ], $payload),
        ]);
    }

    private function fakeClaudeBinary(): string
    {
        return $this->fakeExecutable('claude', <<<'SH'
#!/usr/bin/env bash
printf '{"result":"ok"}'
SH);
    }

    private function fakeCodexBinary(): string
    {
        return $this->fakeExecutable('codex', <<<'SH'
#!/usr/bin/env bash
previous=''
for arg in "$@"; do
  if [ "$previous" = "--output-last-message" ]; then
    printf 'ok' > "$arg"
  fi
  previous="$arg"
done
printf '{"result":"ok"}'
SH);
    }

    private function fakeExecutable(string $name, string $contents): string
    {
        $path = dirname($this->operatorRoot).'/bin/'.$name;
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents."\n");
        chmod($path, 0755);

        return $path;
    }
}
