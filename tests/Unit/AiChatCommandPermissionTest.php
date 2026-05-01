<?php

namespace Tests\Unit;

use App\Console\Commands\AiChatCommand;
use Illuminate\Console\Command;
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

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function toolPermissions(array $options, string $workspace, string $workflowMode, string $permissionMode): array
    {
        $command = app(AiChatCommand::class);
        $input = new ArrayInput($options + [
            '--allow-unsandboxed' => false,
            '--allow-write' => false,
            '--dangerously-allow-all' => false,
        ]);
        $input->bind($command->getDefinition());

        $this->setCommandProperty($command, 'input', $input);
        $this->setCommandProperty($command, 'output', new BufferedOutput);

        $method = new ReflectionMethod(AiChatCommand::class, 'toolPermissions');
        $method->setAccessible(true);

        return $method->invoke($command, $workspace, $workflowMode, null, $permissionMode);
    }

    private function setCommandProperty(AiChatCommand $command, string $property, mixed $value): void
    {
        $reflection = new ReflectionProperty(Command::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($command, $value);
    }
}
