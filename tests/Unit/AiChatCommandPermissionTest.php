<?php

namespace Tests\Unit;

use App\Console\Commands\AiChatCommand;
use App\Services\Ai\Cli\AtlasImageAttachmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AiChatCommandPermissionTest extends TestCase
{
    public function test_auto_permission_uses_operator_defaults_for_cli_payload(): void
    {
        config([
            'atlas.ai.tool_permissions.default_mode' => 'danger',
            'atlas.ai.tool_permissions.allow_danger' => true,
            'atlas.ai.tool_permissions.allow_unsandboxed_write' => true,
            'atlas.ai.tool_permissions.allowed_roots' => ['/Users/vitorepf'],
        ]);

        $permissions = $this->toolPermissions(
            options: ['--permission' => 'auto'],
            workspace: '/Users/vitorepf/Develop/Blackink',
            workflowMode: 'direct',
            permissionMode: 'auto',
        );

        $this->assertSame('/Users/vitorepf/Develop/Blackink', data_get($permissions, 'workspace'));
        $this->assertSame('danger', data_get($permissions, 'mode'));
        $this->assertTrue(data_get($permissions, 'confirmed'));
        $this->assertTrue(data_get($permissions, 'allow_unsandboxed_provider'));
        $this->assertContains('/Users/vitorepf', data_get($permissions, 'allowed_roots'));
        $this->assertContains('danger_full_access', data_get($permissions, 'capabilities'));
    }

    public function test_explicit_read_permission_can_still_downgrade_operator_default(): void
    {
        config([
            'atlas.ai.tool_permissions.default_mode' => 'danger',
            'atlas.ai.tool_permissions.allow_danger' => true,
            'atlas.ai.tool_permissions.allow_unsandboxed_write' => true,
            'atlas.ai.tool_permissions.allowed_roots' => ['/Users/vitorepf'],
        ]);

        $permissions = $this->toolPermissions(
            options: ['--permission' => 'read'],
            workspace: '/Users/vitorepf/Develop/Blackink',
            workflowMode: 'direct',
            permissionMode: 'read',
        );

        $this->assertSame('read', data_get($permissions, 'mode'));
        $this->assertFalse(data_get($permissions, 'confirmed'));
        $this->assertTrue(data_get($permissions, 'allow_unsandboxed_provider'));
    }

    public function test_visual_language_triggers_auto_clipboard_image_detection(): void
    {
        $command = app(AiChatCommand::class);
        $method = new ReflectionMethod(AiChatCommand::class, 'shouldAutoAttachClipboardImage');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($command, 'analise essa tela e corrija'));
        $this->assertTrue($method->invoke($command, 'o que esta errado nesse screenshot?'));
        $this->assertFalse($method->invoke($command, 'crie a tela inicial do app'));
        $this->assertFalse($method->invoke($command, 'responda sem imagem'));
    }

    public function test_pasted_terminal_image_path_is_attached_and_removed_from_prompt(): void
    {
        $workspace = storage_path('framework/testing/image-cli-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($workspace);
        $imagePath = $workspace.'/bug tela.png';
        File::put($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/l8Jv6wAAAABJRU5ErkJggg=='));

        $command = $this->commandWithIo();
        $method = new ReflectionMethod(AiChatCommand::class, 'attachInlineImagePaths');
        $method->setAccessible(true);

        [$input, $attachments] = $method->invoke(
            $command,
            app(AtlasImageAttachmentService::class),
            $workspace,
            'corrija este bug visual "'.$imagePath.'"',
            [],
        );

        $this->assertSame('corrija este bug visual', $input);
        $this->assertCount(1, $attachments);
        $this->assertSame($imagePath, $attachments[0]['path']);
        $this->assertSame('image/png', $attachments[0]['mime_type']);
    }

    public function test_image_command_accepts_quoted_paths_with_spaces(): void
    {
        $workspace = storage_path('framework/testing/image-cli-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($workspace);
        $imagePath = $workspace.'/print tela.png';
        File::put($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/l8Jv6wAAAABJRU5ErkJggg=='));

        $command = $this->commandWithIo();
        $method = new ReflectionMethod(AiChatCommand::class, 'imageCommandPaths');
        $method->setAccessible(true);

        $this->assertSame([$imagePath], $method->invoke($command, '"'.$imagePath.'"'));
    }

    public function test_blank_input_uses_clipboard_image_as_pasted_image(): void
    {
        $workspace = storage_path('framework/testing/image-cli-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($workspace);
        $imagePath = $workspace.'/clipboard.png';
        File::put($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/l8Jv6wAAAABJRU5ErkJggg=='));

        $images = \Mockery::mock(AtlasImageAttachmentService::class);
        $images->shouldReceive('fromClipboard')->once()->with($workspace)->andReturn([
            'path' => $imagePath,
            'source' => 'clipboard',
            'original_path' => $imagePath,
            'mime_type' => 'image/png',
            'bytes' => File::size($imagePath),
            'sha256' => hash_file('sha256', $imagePath),
        ]);
        $images->shouldReceive('dedupe')->once()->andReturnUsing(fn (array $attachments): array => $attachments);

        $command = $this->commandWithIo();
        $method = new ReflectionMethod(AiChatCommand::class, 'inputFromBlankClipboardPaste');
        $method->setAccessible(true);

        [$input, $attachments] = $method->invoke($command, $images, $workspace, []);

        $this->assertSame('Analise a imagem anexada.', $input);
        $this->assertCount(1, $attachments);
        $this->assertSame('clipboard', $attachments[0]['source']);
        $output = $this->commandOutput($command);
        $this->assertStringContainsString('Verificando clipboard visual, aguarde...', $output);
        $this->assertStringContainsString('Imagem detectada; preparando anexo visual...', $output);
    }

    public function test_visual_text_auto_attach_prints_count_and_openable_file_link(): void
    {
        $workspace = storage_path('framework/testing/image-cli-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($workspace);
        $imagePath = $workspace.'/clipboard.png';
        File::put($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/l8Jv6wAAAABJRU5ErkJggg=='));

        $images = \Mockery::mock(AtlasImageAttachmentService::class);
        $images->shouldReceive('fromClipboard')->once()->with($workspace)->andReturn([
            'path' => $imagePath,
            'source' => 'clipboard',
            'original_path' => $imagePath,
            'mime_type' => 'image/png',
            'bytes' => File::size($imagePath),
            'sha256' => hash_file('sha256', $imagePath),
        ]);
        $images->shouldReceive('dedupe')->once()->andReturnUsing(fn (array $attachments): array => $attachments);

        $command = $this->commandWithIo();
        $method = new ReflectionMethod(AiChatCommand::class, 'maybeAutoAttachClipboardImage');
        $method->setAccessible(true);

        $attachments = $method->invoke($command, $images, $workspace, 'consegue ler essa imagem?', []);
        $output = $this->commandOutput($command);

        $this->assertCount(1, $attachments);
        $this->assertStringContainsString('Imagem do clipboard detectada e anexada automaticamente.', $output);
        $this->assertStringContainsString('[img:1] pronta para enviar', $output);
        $this->assertStringContainsString('image/png', $output);
    }

    public function test_raw_key_dispatch_covers_text_backspace_submit_and_eof(): void
    {
        $workspace = storage_path('framework/testing/image-cli-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($workspace);

        $command = $this->commandWithIo();
        $method = new ReflectionMethod(AiChatCommand::class, 'dispatchRawKey');
        $method->setAccessible(true);
        $images = \Mockery::mock(AtlasImageAttachmentService::class);
        $buffer = '';
        $label = 'atlas';
        $pending = [];

        $this->assertSame('continue', $method->invokeArgs($command, ['a', &$buffer, &$label, &$pending, $images, $workspace]));
        $this->assertSame('a', $buffer);
        $this->assertSame('continue', $method->invokeArgs($command, ["\x7f", &$buffer, &$label, &$pending, $images, $workspace]));
        $this->assertSame('', $buffer);
        $this->assertSame('submit', $method->invokeArgs($command, ["\n", &$buffer, &$label, &$pending, $images, $workspace]));

        $this->expectException(\Symfony\Component\Console\Exception\RuntimeException::class);
        $method->invokeArgs($command, ["\x04", &$buffer, &$label, &$pending, $images, $workspace]);
    }

    public function test_pending_images_output_shows_product_grade_attachment_proof(): void
    {
        $workspace = storage_path('framework/testing/image-cli-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($workspace);
        $imagePath = $workspace.'/clipboard.png';
        File::put($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/l8Jv6wAAAABJRU5ErkJggg=='));

        $command = $this->commandWithIo();
        $method = new ReflectionMethod(AiChatCommand::class, 'printPendingImages');
        $method->setAccessible(true);

        $method->invoke($command, [[
            'path' => $imagePath,
            'source' => 'clipboard',
            'original_path' => $imagePath,
            'mime_type' => 'image/png',
            'bytes' => File::size($imagePath),
            'sha256' => hash_file('sha256', $imagePath),
        ]], true);
        $output = $this->commandOutput($command);

        $this->assertStringContainsString('Imagens anexadas: 1', $output);
        $this->assertStringContainsString('imagem anexada e pronta para envio visual', $output);
        $this->assertStringContainsString('origem: clipboard', $output);
        $this->assertStringContainsString('image/png', $output);
        $this->assertStringContainsString('1x1', $output);
        $this->assertStringContainsString('sha256 ', $output);
        $this->assertStringContainsString('abrir: file://', $output);
        $this->assertStringContainsString('preview:', $output);
    }

    public function test_interactive_prompt_makes_image_paste_affordance_visible(): void
    {
        $command = $this->commandWithIo();
        $method = new ReflectionMethod(AiChatCommand::class, 'interactivePromptLabel');
        $method->setAccessible(true);

        $emptyPrompt = $method->invoke($command, null, []);
        $this->assertSame('atlas', $emptyPrompt);
        $this->assertStringNotContainsString('[img:', $emptyPrompt);
        $this->assertSame('atlas [img:1] Enter=analisar', $method->invoke($command, null, [[
            'path' => '/tmp/print.png',
        ]]));
    }

    public function test_open_image_reports_when_no_attachment_exists(): void
    {
        $command = $this->commandWithIo();
        $method = new ReflectionMethod(AiChatCommand::class, 'openImageAttachment');
        $method->setAccessible(true);

        $method->invoke($command, [], '');

        $this->assertStringContainsString('Nenhuma imagem anexada ou enviada recentemente.', $this->commandOutput($command));
    }

    public function test_visual_provider_switch_is_blocked_while_images_are_pending_or_queued(): void
    {
        $command = $this->commandWithIo();
        $method = new ReflectionMethod(AiChatCommand::class, 'imageProviderSwitchBlocked');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($command, 'codex_cli', [['path' => '/tmp/print.png']], []));
        $this->assertTrue($method->invoke($command, 'claude_cli', [['path' => '/tmp/print.png']], []));
        $this->assertTrue($method->invoke($command, 'claude_cli', [], [[
            'input' => 'analise',
            'images' => [['path' => '/tmp/print.png']],
        ]]));
        $this->assertFalse($method->invoke($command, 'claude_cli', [], [[
            'input' => 'texto',
            'images' => [],
        ]]));
    }

    public function test_blank_input_reports_when_clipboard_has_no_image(): void
    {
        $workspace = storage_path('framework/testing/image-cli-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($workspace);

        $images = \Mockery::mock(AtlasImageAttachmentService::class);
        $images->shouldReceive('fromClipboard')->once()->with($workspace)->andThrow(new \RuntimeException('Clipboard nao contem imagem.'));

        $command = $this->commandWithIo();
        $method = new ReflectionMethod(AiChatCommand::class, 'inputFromBlankClipboardPaste');
        $method->setAccessible(true);

        [$input, $attachments] = $method->invoke($command, $images, $workspace, []);
        $output = $this->commandOutput($command);

        $this->assertNull($input);
        $this->assertSame([], $attachments);
        $this->assertStringContainsString('Verificando clipboard visual, aguarde...', $output);
        $this->assertStringContainsString('Nenhuma imagem detectada no clipboard apos Enter vazio', $output);
        $this->assertStringContainsString('Clipboard nao contem imagem.', $output);
    }

    public function test_dev_plan_generates_programming_message_plan_for_each_chat_message(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.codex_cli.allow_auto' => true,
            'atlas.ai.providers.codex_cli.allow_manual' => true,
        ]);

        $command = app(AiChatCommand::class);
        $method = new ReflectionMethod(AiChatCommand::class, 'programmingMessagePlan');
        $method->setAccessible(true);

        $messagePlan = $method->invoke($command, '/tmp/atlas-workspace', 'dev', 'implementar parser robusto', null, null, [
            'schema_version' => 1,
            'plan_id' => 'parent-plan-1',
            'programming_profile' => 'forge',
            'execution_profile' => [
                'complete' => true,
                'auto_test' => true,
                'max_iterations' => 7,
            ],
        ]);

        $this->assertIsArray($messagePlan);
        $this->assertSame('AtlasProgrammingOrchestrator', data_get($messagePlan, 'orchestrator'));
        $this->assertSame('forge', data_get($messagePlan, 'programming_profile'));
        $this->assertSame('parent-plan-1', data_get($messagePlan, 'parent_plan_id'));
        $this->assertTrue(data_get($messagePlan, 'execution_profile.complete'));
        $this->assertTrue(data_get($messagePlan, 'execution_profile.auto_test'));
        $this->assertSame(5, data_get($messagePlan, 'execution_profile.max_iterations'));
        $this->assertSame('engineering_harness', data_get($messagePlan, 'executor_decision.executor'));
        $this->assertSame('engineering_harness', data_get($messagePlan, 'executor_decision.policy_executor_preference'));
        $this->assertSame('programming.forge', data_get($messagePlan, 'policy_profile.profile_id'));
        $this->assertSame(5, data_get($messagePlan, 'policy_profile.execution_policy.max_iterations'));
    }

    public function test_programming_executor_request_data_inherits_harness_overrides(): void
    {
        $command = app(AiChatCommand::class);
        $input = new ArrayInput([
            '--no-run' => true,
        ]);
        $input->bind($command->getDefinition());
        $this->setCommandProperty($command, 'input', $input);
        $this->setCommandProperty($command, 'output', new BufferedOutput);

        $method = new ReflectionMethod(AiChatCommand::class, 'programmingExecutionRequestData');
        $method->setAccessible(true);

        $request = $method->invoke($command, 'implementar fluxo', '/tmp/atlas-workspace', 'codex_cli', 'gpt-test', 'danger', [
            'dev_execution_plan' => [
                'operator_options' => [
                    'harness_overrides' => [
                        'test_command' => 'php -r "exit(0);"',
                        'sandbox' => 'worktree',
                        'provider_runtime' => 'host',
                        'quality_scan' => 'required',
                        'apply_isolated_patch' => false,
                    ],
                ],
            ],
        ], [
            'programming_profile' => 'forge',
            'execution_profile' => [
                'complete' => true,
                'auto_test' => true,
                'max_iterations' => 6,
            ],
        ]);

        $this->assertSame('forge', data_get($request, 'profile'));
        $this->assertSame('codex_cli', data_get($request, 'provider'));
        $this->assertSame('gpt-test', data_get($request, 'model'));
        $this->assertSame('danger', data_get($request, 'permission'));
        $this->assertTrue(data_get($request, 'no_provider'));
        $this->assertSame(6, data_get($request, 'max_attempts'));
        $this->assertSame('php -r "exit(0);"', data_get($request, 'test_command'));
        $this->assertSame('worktree', data_get($request, 'sandbox'));
        $this->assertSame('host', data_get($request, 'provider_runtime'));
        $this->assertSame('required', data_get($request, 'quality_scan'));
        $this->assertFalse(data_get($request, 'apply_isolated_patch'));
    }

    public function test_programming_dispatch_contract_records_selected_execution_path(): void
    {
        $command = app(AiChatCommand::class);
        $method = new ReflectionMethod(AiChatCommand::class, 'programmingDispatchContract');
        $method->setAccessible(true);

        $harnessDispatch = $method->invoke($command, [
            'plan_id' => 'plan-harness',
            'programming_profile' => 'forge',
            'executor_decision' => [
                'executor' => 'engineering_harness',
                'reason' => 'forge_profile_prefers_harness',
            ],
            'policy_profile' => [
                'profile_id' => 'programming.forge',
                'profile_context' => [
                    'programming' => true,
                    'forge' => true,
                ],
                'execution_policy' => [
                    'executor_preference' => 'engineering_harness',
                    'max_iterations' => 5,
                ],
            ],
            'operational_decision' => [
                'decision_id' => 'decision-1',
            ],
        ]);

        $this->assertSame(1, data_get($harnessDispatch, 'schema_version'));
        $this->assertSame('selected', data_get($harnessDispatch, 'status'));
        $this->assertSame('AtlasProgrammingOrchestrator', data_get($harnessDispatch, 'source'));
        $this->assertSame('programming_orchestrator_harness', data_get($harnessDispatch, 'dispatch_path'));
        $this->assertSame('engineering_harness', data_get($harnessDispatch, 'executor'));
        $this->assertSame('forge', data_get($harnessDispatch, 'programming_profile'));
        $this->assertSame('programming.forge', data_get($harnessDispatch, 'policy_profile_id'));
        $this->assertTrue((bool) data_get($harnessDispatch, 'profile_context.forge'));
        $this->assertSame('engineering_harness', data_get($harnessDispatch, 'execution_policy.executor_preference'));
        $this->assertSame('decision-1', data_get($harnessDispatch, 'operational_decision_id'));
        $this->assertSame('plan-harness', data_get($harnessDispatch, 'plan_id'));

        $providerDispatch = $method->invoke($command, [
            'plan_id' => 'plan-provider',
            'programming_profile' => 'dev',
            'executor_decision' => [
                'executor' => 'dev_repair_executor',
                'reason' => 'complete_mode_requires_repair_loop',
            ],
        ]);

        $this->assertSame('ai_gateway_provider', data_get($providerDispatch, 'dispatch_path'));
        $this->assertSame('dev_repair_executor', data_get($providerDispatch, 'executor'));
        $this->assertSame('complete_mode_requires_repair_loop', data_get($providerDispatch, 'reason'));
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function toolPermissions(array $options, string $workspace, string $workflowMode, string $permissionMode): array
    {
        $command = $this->commandWithIo($options + [
            '--allow-unsandboxed' => false,
            '--allow-write' => false,
            '--dangerously-allow-all' => false,
        ]);

        $method = new ReflectionMethod(AiChatCommand::class, 'toolPermissions');
        $method->setAccessible(true);

        return $method->invoke($command, $workspace, $workflowMode, null, $permissionMode);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function commandWithIo(array $options = []): AiChatCommand
    {
        $command = app(AiChatCommand::class);
        $input = new ArrayInput($options);
        $input->bind($command->getDefinition());

        $this->setCommandProperty($command, 'input', $input);
        $this->setCommandProperty($command, 'output', new BufferedOutput);

        return $command;
    }

    private function commandOutput(AiChatCommand $command): string
    {
        $reflection = new ReflectionProperty(Command::class, 'output');
        $reflection->setAccessible(true);
        $output = $reflection->getValue($command);

        return $output instanceof BufferedOutput ? $output->fetch() : '';
    }

    private function setCommandProperty(AiChatCommand $command, string $property, mixed $value): void
    {
        $reflection = new ReflectionProperty(Command::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($command, $value);
    }
}
