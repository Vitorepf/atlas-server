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

    public function test_claude_provider_ignores_stale_model_permission_and_add_dir_args_from_config(): void
    {
        $binary = $this->fakeClaudeBinary();

        config([
            'atlas.ai.providers.claude_cli.binary' => $binary,
            'atlas.ai.providers.claude_cli.args' => [
                '-p',
                '--model',
                'old-claude',
                '--model=other-claude',
                '--permission-mode',
                'plan',
                '--permission-mode=default',
                '--add-dir',
                '/tmp/outside-one',
                '/tmp/outside-two',
                '--no-session-persistence',
                '--dangerously-skip-permissions=true',
            ],
        ]);

        $job = $this->job();
        $job->model = 'claude-opus-4-7';
        $result = app(ClaudeCliProvider::class)->runStreaming($job, 'teste');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertSame(1, collect($result->command)->filter(fn (mixed $arg): bool => $arg === '--model')->count());
        $this->assertSame('claude-opus-4-7', $result->command[array_search('--model', $result->command, true) + 1]);
        $this->assertSame(1, collect($result->command)->filter(fn (mixed $arg): bool => $arg === '--permission-mode')->count());
        $this->assertSame('bypassPermissions', $result->command[array_search('--permission-mode', $result->command, true) + 1]);
        $this->assertNotContains('old-claude', $result->command);
        $this->assertNotContains('other-claude', $result->command);
        $this->assertNotContains('plan', $result->command);
        $this->assertNotContains('/tmp/outside-one', $result->command);
        $this->assertNotContains('/tmp/outside-two', $result->command);
        $this->assertNotContains('--dangerously-skip-permissions=true', $result->command);
    }

    public function test_claude_provider_records_invocation_fingerprint(): void
    {
        $binary = $this->fakeClaudeBinary();

        config([
            'atlas.ai.providers.claude_cli.binary' => $binary,
            'atlas.ai.providers.claude_cli.args' => ['-p', '--output-format', 'stream-json', '--verbose', '--no-session-persistence'],
        ]);

        $job = $this->job([
            'fair_mode' => ['fair_mode' => true],
            'dev_execution_plan' => [
                'plan_id' => 'plan_test',
                'fair_mode' => ['fair_mode' => true],
            ],
            'context_pack' => ['context_pack_hash' => 'ctx_test'],
        ]);
        $job->provider = 'claude_cli';
        $job->model = 'claude-opus-4-7';

        $result = app(ClaudeCliProvider::class)->runStreaming($job, 'teste');

        $fingerprint = data_get($result->metadata, 'claude_invocation_fingerprint');
        $this->assertIsArray($fingerprint);
        $this->assertSame('claude_cli', $fingerprint['provider']);
        $this->assertSame('claude-opus-4-7', $fingerprint['model']);
        $this->assertSame('Claude Code fake 1.0', $fingerprint['binary_version']);
        $this->assertSame('stream-json', $fingerprint['output_format']);
        $this->assertSame('bypassPermissions', $fingerprint['permission_mode']);
        $this->assertSame('ctx_test', $fingerprint['context_pack_hash']);
        $this->assertSame('plan_test', $fingerprint['dev_plan_id']);
        $this->assertTrue($fingerprint['fair_mode']);
        $this->assertSame(hash('sha256', 'teste'), $fingerprint['prompt_hash']);
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
        $this->assertContains('--dangerously-bypass-approvals-and-sandbox', $result->command);
        $this->assertNotContains('--ask-for-approval', $result->command);
        $this->assertContains('--add-dir', $result->command);
        $this->assertContains($this->operatorRoot, $result->command);
        $this->assertContains($this->workspace, $result->command);
    }

    public function test_codex_provider_ignores_stale_approval_args_from_config(): void
    {
        $binary = $this->fakeCodexBinary();

        config([
            'atlas.ai.providers.codex_cli.binary' => $binary,
            'atlas.ai.providers.codex_cli.args' => [
                'exec',
                '--skip-git-repo-check',
                '--ask-for-approval',
                'never',
                '--ask-for-approval=never',
                '--full-auto',
            ],
        ]);

        $result = app(CodexCliProvider::class)->runStreaming($this->job(), 'teste');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertNotContains('--ask-for-approval', $result->command);
        $this->assertNotContains('--ask-for-approval=never', $result->command);
        $this->assertNotContains('--full-auto', $result->command);
        $this->assertContains('--dangerously-bypass-approvals-and-sandbox', $result->command);
    }

    public function test_codex_provider_ignores_stale_model_sandbox_output_and_image_args_from_config(): void
    {
        $binary = $this->fakeCodexBinary();

        config([
            'atlas.ai.providers.codex_cli.binary' => $binary,
            'atlas.ai.providers.codex_cli.args' => [
                'exec',
                '--skip-git-repo-check',
                '--sandbox',
                'read-only',
                '--sandbox=workspace-write',
                '--model',
                'old-codex',
                '--model=other-codex',
                '--output-last-message',
                '/tmp/old-last-message.txt',
                '--image',
                '/tmp/old-image.png',
                '--full-auto=false',
            ],
        ]);

        $job = $this->job();
        $job->model = 'gpt-5.5';
        $result = app(CodexCliProvider::class)->runStreaming($job, 'teste');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertSame(1, collect($result->command)->filter(fn (mixed $arg): bool => $arg === '--sandbox')->count());
        $this->assertSame('danger-full-access', $result->command[array_search('--sandbox', $result->command, true) + 1]);
        $this->assertSame(1, collect($result->command)->filter(fn (mixed $arg): bool => $arg === '--model')->count());
        $this->assertSame('gpt-5.5', $result->command[array_search('--model', $result->command, true) + 1]);
        $this->assertSame(1, collect($result->command)->filter(fn (mixed $arg): bool => $arg === '--output-last-message')->count());
        $this->assertNotContains('old-codex', $result->command);
        $this->assertNotContains('other-codex', $result->command);
        $this->assertNotContains('read-only', $result->command);
        $this->assertNotContains('workspace-write', $result->command);
        $this->assertNotContains('/tmp/old-last-message.txt', $result->command);
        $this->assertNotContains('/tmp/old-image.png', $result->command);
        $this->assertNotContains('--full-auto=false', $result->command);
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
        $this->assertContains('--approval-mode=yolo', $result->command);
        $this->assertContains('--skip-trust', $result->command);
        $this->assertNotContains('--approval-mode', $result->command);
        $this->assertNotContains('--yolo', $result->command);
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
        $this->assertSame('passed', data_get($health->metadata, 'runtime_contract.status'));
        $this->assertSame('gemini_cli_provider.v1', data_get($health->metadata, 'runtime_contract.name'));
    }

    public function test_provider_health_validates_runtime_contracts_for_all_cli_providers(): void
    {
        config([
            'atlas.ai.providers.claude_cli.binary' => $this->fakeClaudeBinary(),
            'atlas.ai.providers.codex_cli.binary' => $this->fakeCodexBinary(),
            'atlas.ai.providers.gemini_cli.binary' => $this->fakeGeminiBinary(),
        ]);

        $claude = app(ClaudeCliProvider::class)->health();
        $codex = app(CodexCliProvider::class)->health();
        $gemini = app(GeminiCliProvider::class)->health();

        $this->assertSame('online', $claude->status);
        $this->assertSame('claude_cli_provider.v1', data_get($claude->metadata, 'runtime_contract.name'));
        $this->assertSame('passed', data_get($claude->metadata, 'runtime_contract.status'));
        $this->assertSame([], data_get($claude->metadata, 'runtime_contract.missing_tokens'));

        $this->assertSame('online', $codex->status);
        $this->assertSame('codex_cli_provider.v1', data_get($codex->metadata, 'runtime_contract.name'));
        $this->assertSame('passed', data_get($codex->metadata, 'runtime_contract.status'));
        $this->assertSame([], data_get($codex->metadata, 'runtime_contract.missing_tokens'));

        $this->assertSame('online', $gemini->status);
        $this->assertSame('gemini_cli_provider.v1', data_get($gemini->metadata, 'runtime_contract.name'));
        $this->assertSame('passed', data_get($gemini->metadata, 'runtime_contract.status'));
        $this->assertSame([], data_get($gemini->metadata, 'runtime_contract.missing_tokens'));
    }

    public function test_provider_health_degrades_when_runtime_contract_is_missing_required_flag(): void
    {
        $binary = $this->fakeExecutable('gemini', <<<'SH'
#!/usr/bin/env bash
if [ "$1" = "--version" ]; then
  printf 'gemini fake 1.0'
  exit 0
fi
if [ "$1" = "--help" ]; then
  printf '%s\n' 'Usage: gemini --model --prompt --output-format stream-json --skip-trust --include-directories'
  exit 0
fi
cat >/dev/null
printf '{"type":"result","response":"ok","stats":{"models":["gemini-3.1-pro-preview"]}}'
SH);

        config([
            'atlas.ai.providers.gemini_cli.binary' => $binary,
        ]);

        $health = app(GeminiCliProvider::class)->health();

        $this->assertSame('degraded', $health->status);
        $this->assertSame('missing_required_tokens', data_get($health->metadata, 'runtime_contract.status'));
        $this->assertContains('--approval-mode', data_get($health->metadata, 'runtime_contract.missing_tokens'));
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

    public function test_gemini_provider_ignores_user_role_events_in_stream_json_output(): void
    {
        $binary = $this->fakeExecutable('gemini', <<<'SH'
#!/usr/bin/env bash
cat >/dev/null
printf '{"type":"message","role":"user","content":"prompt secreto"}\n'
printf '{"type":"message","role":"assistant","content":"resposta limpa","delta":true}\n'
printf '{"type":"result","status":"success","stats":{"models":["gemini-3.1-pro-preview"]}}\n'
SH);
        $policy = $this->fakeGeminiPolicy();

        config([
            'atlas.ai.providers.gemini_cli.binary' => $binary,
            'atlas.ai.providers.gemini_cli.args' => [],
            'atlas.ai.providers.gemini_cli.admin_policy' => $policy,
        ]);

        $result = app(GeminiCliProvider::class)->runStreaming($this->job(), 'prompt secreto');

        $this->assertTrue($result->ok, $result->errorMessage ?? '');
        $this->assertSame('resposta limpa', $result->output);
        $this->assertStringNotContainsString('prompt secreto', $result->output);
    }

    public function test_gemini_provider_classifies_admin_policy_denial_as_policy_violation(): void
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

    public function test_gemini_provider_aborts_interactive_auth_prompt_for_claude_fallback(): void
    {
        $binary = $this->fakeExecutable('gemini', <<<'SH'
#!/usr/bin/env bash
printf 'Opening authentication page in your browser. Do you want to continue? [Y/n]: '
sleep 10
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
        $this->assertSame('auth_expired', $result->errorCode);
        $this->assertLessThan(5000, $result->durationMs);
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
                '--approval-mode', 'plan',
                '--no-sandbox',
                '--sandbox=true',
                '--sandbox', 'false',
                '-s', 'true',
                '--yolo',
                '--yolo=true',
                '-y', 'false',
                '--skip-trust=false',
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
        $this->assertContains('--approval-mode=yolo', $result->command);
        $this->assertContains('--skip-trust', $result->command);
        $this->assertNotContains('--approval-mode', $result->command);
        $this->assertNotContains('--admin-policy', $result->command);
        $this->assertNotContains('gemini-2.5-pro', $result->command);
        $this->assertNotContains('inline prompt', $result->command);
        $this->assertNotContains('plan', $result->command);
        $this->assertNotContains('/tmp/other-policy.toml', $result->command);
        $this->assertNotContains('--include-directories', $result->command);
        $this->assertNotContains('--no-sandbox', $result->command);
        $this->assertNotContains('--sandbox=true', $result->command);
        $this->assertNotContains('--sandbox', $result->command);
        $this->assertNotContains('-s', $result->command);
        $this->assertNotContains('--yolo', $result->command);
        $this->assertNotContains('--yolo=true', $result->command);
        $this->assertNotContains('-y', $result->command);
        $this->assertNotContains('--skip-trust=false', $result->command);
        $this->assertNotContains('false', $result->command);
        $this->assertNotContains('true', $result->command);
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
if [ "$1" = "--version" ]; then
  printf 'Claude Code fake 1.0'
  exit 0
fi
if [ "$1" = "--help" ]; then
  cat <<'HELP'
Usage: claude [options] [prompt]
  --add-dir <directories...>
  --model <model>
  --output-format <format> text json stream-json
  --permission-mode <mode>
  --no-session-persistence
HELP
  exit 0
fi
printf '{"result":"ok"}'
SH);
    }

    private function fakeCodexBinary(): string
    {
        return $this->fakeExecutable('codex', <<<'SH'
#!/usr/bin/env bash
if [ "$1" = "--version" ]; then
  printf 'codex fake 1.0'
  exit 0
fi
if [ "$1" = "exec" ] && [ "$2" = "--help" ]; then
  cat <<'HELP'
Usage: codex exec [OPTIONS] [PROMPT]
  --model <MODEL>
  --sandbox <SANDBOX_MODE>
  --dangerously-bypass-approvals-and-sandbox
  --add-dir <DIR>
  --image <FILE>
  --output-last-message <FILE>
HELP
  exit 0
fi
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
if [ "\$1" = "--version" ]; then
  printf 'gemini fake 1.0'
  exit 0
fi
if [ "\$1" = "--help" ]; then
  cat <<'HELP'
Usage: gemini [options] [command]
  --model
  --prompt
  --output-format text json stream-json
  --approval-mode default auto_edit yolo plan
  --skip-trust
  --include-directories
HELP
  exit 0
fi
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
