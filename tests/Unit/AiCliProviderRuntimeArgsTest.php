<?php

namespace Tests\Unit;

use App\Models\AiJob;
use App\Services\Ai\ClaudeCliProvider;
use App\Services\Ai\CodexCliProvider;
use App\Services\Ai\GeminiCliProvider;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AiCliProviderRuntimeArgsTest extends TestCase
{
    private string $workspace;

    private string $operatorRoot;

    private string $attachmentFixtureRoot;

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
        $this->attachmentFixtureRoot = storage_path('app/ai/attachments/unit-test-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->attachmentFixtureRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->attachmentFixtureRoot);
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

    public function test_gemini_provider_uses_fixed_native_runtime_and_stdin_prompt(): void
    {
        $binary = $this->fakeGeminiBinary();
        $policy = dirname($this->operatorRoot).'/gemini-policy.toml';
        File::put($policy, 'policy');

        config([
            'atlas.ai.providers.gemini_cli.binary' => $binary,
            'atlas.ai.providers.gemini_cli.args' => [],
            'atlas.ai.providers.gemini_cli.home' => dirname($this->operatorRoot).'/gemini-home',
            'atlas.ai.providers.gemini_cli.admin_policy' => $policy,
        ]);

        $result = app(GeminiCliProvider::class)->runStreaming($this->job([
            'tool_permissions' => [
                'mode' => 'read',
                'workspace' => $this->workspace,
                'allowed_roots' => [$this->workspace],
            ],
        ]), 'prompt secreto');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertContains('--model', $result->command);
        $this->assertSame('gemini-3.1-pro-preview', $result->command[array_search('--model', $result->command, true) + 1]);
        $this->assertContains('--approval-mode', $result->command);
        $this->assertSame('yolo', $result->command[array_search('--approval-mode', $result->command, true) + 1]);
        $this->assertContains('--yolo', $result->command);
        $this->assertNotContains('--sandbox', $result->command);
        $this->assertNotContains('--admin-policy', $result->command);
        $this->assertNotContains('prompt secreto', $result->command);
        $this->assertSame(['gemini-3.1-pro-preview'], $result->metadata['observed_models']);
    }

    public function test_gemini_provider_rejects_model_downgrade_from_cli_output(): void
    {
        $binary = $this->fakeGeminiBinary('gemini-2.5-pro');
        $policy = $this->fakeGeminiPolicy();

        config([
            'atlas.ai.providers.gemini_cli.binary' => $binary,
            'atlas.ai.providers.gemini_cli.args' => [],
            'atlas.ai.providers.gemini_cli.home' => dirname($this->operatorRoot).'/gemini-home',
            'atlas.ai.providers.gemini_cli.admin_policy' => $policy,
        ]);

        $result = app(GeminiCliProvider::class)->runStreaming($this->job([
            'tool_permissions' => [
                'mode' => 'read',
                'workspace' => $this->workspace,
                'allowed_roots' => [$this->workspace],
            ],
        ]), 'teste');

        $this->assertFalse($result->ok);
        $this->assertSame('policy_violation', $result->errorCode);
        $this->assertSame('claude_cli', $result->metadata['fallback_provider']);
    }

    public function test_gemini_provider_does_not_require_admin_policy(): void
    {
        $binary = $this->fakeGeminiBinary();

        config([
            'atlas.ai.providers.gemini_cli.binary' => $binary,
            'atlas.ai.providers.gemini_cli.args' => [],
            'atlas.ai.providers.gemini_cli.admin_policy' => dirname($this->operatorRoot).'/missing-policy.toml',
        ]);

        $result = app(GeminiCliProvider::class)->runStreaming($this->job([
            'tool_permissions' => [
                'mode' => 'read',
                'workspace' => $this->workspace,
                'allowed_roots' => [$this->workspace],
            ],
        ]), 'teste');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertNotContains('--admin-policy', $result->command);
    }

    public function test_gemini_health_does_not_require_admin_policy(): void
    {
        $binary = $this->fakeGeminiBinary();

        config([
            'atlas.ai.providers.gemini_cli.binary' => $binary,
            'atlas.ai.providers.gemini_cli.admin_policy' => dirname($this->operatorRoot).'/missing-policy.toml',
        ]);

        $health = app(GeminiCliProvider::class)->health();

        $this->assertSame('online', $health->status);
        $this->assertSame('native_yolo', $health->metadata['runtime_policy']);
    }

    public function test_gemini_provider_allows_output_without_model_attestation_for_measurement(): void
    {
        $binary = $this->fakeExecutable('gemini', <<<'SH'
#!/usr/bin/env bash
cat >/dev/null
printf '{"type":"result","response":"ok"}'
SH);
        $policy = $this->fakeGeminiPolicy();

        config([
            'atlas.ai.providers.gemini_cli.binary' => $binary,
            'atlas.ai.providers.gemini_cli.args' => [],
            'atlas.ai.providers.gemini_cli.admin_policy' => $policy,
        ]);

        $result = app(GeminiCliProvider::class)->runStreaming($this->job([
            'tool_permissions' => [
                'mode' => 'read',
                'workspace' => $this->workspace,
                'allowed_roots' => [$this->workspace],
            ],
        ]), 'teste');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertSame([], $result->metadata['observed_models']);
    }

    public function test_gemini_provider_classifies_admin_policy_denial_for_claude_fallback(): void
    {
        $binary = $this->fakeExecutable('gemini', <<<'SH'
#!/usr/bin/env bash
cat >/dev/null
printf 'admin policy denied tool read_many_files\n' >&2
exit 1
SH);
        $policy = $this->fakeGeminiPolicy();

        config([
            'atlas.ai.providers.gemini_cli.binary' => $binary,
            'atlas.ai.providers.gemini_cli.args' => [],
            'atlas.ai.providers.gemini_cli.admin_policy' => $policy,
        ]);

        $result = app(GeminiCliProvider::class)->runStreaming($this->job([
            'tool_permissions' => [
                'mode' => 'read',
                'workspace' => $this->workspace,
                'allowed_roots' => [$this->workspace],
            ],
        ]), 'teste');

        $this->assertFalse($result->ok);
        $this->assertSame('policy_violation', $result->errorCode);
    }

    public function test_gemini_provider_exposes_attachment_paths_for_read_file_without_putting_prompt_in_command(): void
    {
        $capturedPrompt = dirname($this->operatorRoot).'/gemini-stdin.txt';
        $binary = $this->fakeGeminiBinary(capturePromptPath: $capturedPrompt);
        $policy = $this->fakeGeminiPolicy();
        $image = $this->attachmentFixtureRoot.'/screenshot.png';
        $pageDir = $this->attachmentFixtureRoot.'/pdf-pages';
        File::ensureDirectoryExists($pageDir);
        $page = $pageDir.'/page-1.png';
        $document = $this->attachmentFixtureRoot.'/brief.pdf';
        File::put($image, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));
        File::put($page, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));
        File::put($document, '%PDF-1.4');

        config([
            'atlas.ai.providers.gemini_cli.binary' => $binary,
            'atlas.ai.providers.gemini_cli.args' => [],
            'atlas.ai.providers.gemini_cli.home' => null,
            'atlas.ai.providers.gemini_cli.admin_policy' => $policy,
        ]);

        $result = app(GeminiCliProvider::class)->runStreaming($this->job([
            'tool_permissions' => [
                'mode' => 'read',
                'workspace' => $this->workspace,
                'allowed_roots' => [$this->workspace],
            ],
            'attachments' => [
                'images' => [
                    ['path' => $image, 'mime_type' => 'image/png'],
                ],
                'files' => [
                    [
                        'path' => $document,
                        'original_name' => 'brief.pdf',
                        'mime_type' => 'application/pdf',
                        'pdf_rendered_pages' => [
                            ['path' => $page, 'page' => 1],
                        ],
                    ],
                ],
            ],
        ]), 'analise os anexos');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertContains('--include-directories', $result->command);
        $includeDir = $result->command[array_search('--include-directories', $result->command, true) + 1];
        $this->assertStringContainsString(storage_path('app/ai/gemini-attachments'), $includeDir);
        $this->assertNotSame(realpath(storage_path('app/ai/attachments')), $includeDir);
        $this->assertNotContains('analise os anexos', $result->command);

        $prompt = File::get($capturedPrompt);
        $this->assertStringContainsString('Acesso local aos anexos para Gemini', $prompt);
        $this->assertStringContainsString('attachment-01.png', $prompt);
        $this->assertStringContainsString('brief.pdf', $prompt);
        $this->assertStringContainsString('gemini-attachments', $prompt);
        $this->assertStringNotContainsString($this->attachmentFixtureRoot, $prompt);
        $this->assertStringContainsString('read_file', $prompt);
    }

    public function test_gemini_provider_ignores_unsafe_configured_args(): void
    {
        $binary = $this->fakeGeminiBinary();
        $policy = $this->fakeGeminiPolicy();

        config([
            'atlas.ai.providers.gemini_cli.binary' => $binary,
            'atlas.ai.providers.gemini_cli.args' => [
                '--model', 'gemini-2.5-pro',
                '--prompt', 'inline prompt',
                '--admin-policy', '/tmp/other-policy.toml',
                '--include-directories', '/',
                '--approval-mode=yolo',
                '--no-sandbox',
                '--yolo',
            ],
            'atlas.ai.providers.gemini_cli.admin_policy' => $policy,
        ]);

        $result = app(GeminiCliProvider::class)->runStreaming($this->job([
            'tool_permissions' => [
                'mode' => 'read',
                'workspace' => $this->workspace,
                'allowed_roots' => [$this->workspace],
            ],
        ]), 'teste');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertSame('gemini-3.1-pro-preview', $result->command[array_search('--model', $result->command, true) + 1]);
        $this->assertSame('', $result->command[array_search('--prompt', $result->command, true) + 1]);
        $this->assertSame('yolo', $result->command[array_search('--approval-mode', $result->command, true) + 1]);
        $this->assertNotContains('--admin-policy', $result->command);
        $this->assertNotContains('gemini-2.5-pro', $result->command);
        $this->assertNotContains('inline prompt', $result->command);
        $this->assertNotContains('/tmp/other-policy.toml', $result->command);
        $this->assertNotContains('--include-directories', $result->command);
        $this->assertNotContains('--no-sandbox', $result->command);
        $this->assertContains('--yolo', $result->command);
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

    private function fakeGeminiBinary(string $model = 'gemini-3.1-pro-preview', ?string $capturePromptPath = null): string
    {
        $capture = $capturePromptPath
            ? "printf '%s' \"\$stdin\" > ".escapeshellarg($capturePromptPath)
            : ':';

        return $this->fakeExecutable('gemini', <<<SH
#!/usr/bin/env bash
stdin=\$(cat)
{$capture}
printf '{"type":"result","response":"ok","stats":{"models":["{$model}"]}}'
SH);
    }

    private function fakeGeminiPolicy(): string
    {
        $policy = dirname($this->operatorRoot).'/gemini-policy.toml';
        File::put($policy, 'policy');

        return $policy;
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
