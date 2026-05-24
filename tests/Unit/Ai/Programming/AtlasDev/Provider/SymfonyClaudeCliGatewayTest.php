<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Provider;

use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliRequest;
use App\Services\Ai\Programming\AtlasDev\Provider\SymfonyClaudeCliGateway;
use Illuminate\Config\Repository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SymfonyClaudeCliGatewayTest extends TestCase
{
    use AtlasDevProviderFixtures;

    public function test_dispatch_uses_configured_claude_argv_and_stdin_prompt(): void
    {
        $gateway = new SymfonyClaudeCliGateway(new Repository([
            'atlas' => [
                'ai' => [
                    'providers' => [
                        'claude_cli' => [
                            'binary' => 'claude',
                            'args' => ['-p', '--output-format', 'stream-json', '--verbose', '--no-session-persistence'],
                        ],
                    ],
                ],
            ],
        ]));

        $seenArgv = null;
        $seenCwd = null;
        $gateway->setProcessFactory(function (array $argv, string $cwd, int $timeout) use (&$seenArgv, &$seenCwd): Process {
            $seenArgv = $argv;
            $seenCwd = $cwd;

            return new Process(['/bin/cat'], null, null, null, $timeout);
        });

        $workspace = sys_get_temp_dir().'/atlas-dev-gateway-'.bin2hex(random_bytes(4));
        mkdir($workspace, 0o755, true);

        $response = $gateway->dispatch($this->requestFixture($workspace));

        $this->assertSame('claude_cli', $response->actualProvider);
        $this->assertSame('sonnet', $response->actualModelFamily);
        $this->assertSame(0, $response->exitCode);
        $this->assertStringContainsString('Atlas Dev Flow', $response->stdout);
        $this->assertSame($workspace, $seenCwd);
        $this->assertSame('claude', $seenArgv[0]);
        $this->assertContains('-p', $seenArgv);
        $this->assertContains('--output-format', $seenArgv);
    }

    public function test_dispatch_allows_claude_code_node_package_binary(): void
    {
        $gateway = new SymfonyClaudeCliGateway(new Repository([
            'atlas' => [
                'ai' => [
                    'providers' => [
                        'claude_cli' => [
                            'binary' => '/opt/node/lib/node_modules/@anthropic-ai/claude-code/bin/claude.exe',
                            'args' => ['-p'],
                        ],
                    ],
                ],
            ],
        ]));

        $gateway->setProcessFactory(static fn (array $argv, string $cwd, int $timeout): Process => new Process(['/bin/cat'], null, null, null, $timeout));

        $workspace = sys_get_temp_dir().'/atlas-dev-gateway-'.bin2hex(random_bytes(4));
        mkdir($workspace, 0o755, true);

        $response = $gateway->dispatch($this->requestFixture($workspace));

        $this->assertSame(0, $response->exitCode);
        $this->assertStringContainsString('Atlas Dev Flow', $response->stdout);
    }

    public function test_dispatch_extracts_result_text_from_claude_stream_json(): void
    {
        $gateway = new SymfonyClaudeCliGateway(new Repository([
            'atlas' => ['ai' => ['providers' => ['claude_cli' => ['binary' => 'claude', 'args' => ['-p']]]]],
        ]));

        $gateway->setProcessFactory(static fn (): Process => new Process([
            '/bin/sh',
            '-lc',
            'printf %s '.escapeshellarg(json_encode(['type' => 'result', 'result' => "diff --git a/a b/a\n"])),
        ]));

        $workspace = sys_get_temp_dir().'/atlas-dev-gateway-'.bin2hex(random_bytes(4));
        mkdir($workspace, 0o755, true);

        $response = $gateway->dispatch($this->requestFixture($workspace));

        $this->assertSame("diff --git a/a b/a\n", $response->stdout);
    }

    public function test_dispatch_rejects_non_claude_binary_before_process_start(): void
    {
        $gateway = new SymfonyClaudeCliGateway(new Repository([
            'atlas' => ['ai' => ['providers' => ['claude_cli' => ['binary' => 'php', 'args' => ['-v']]]]],
        ]));

        $workspace = sys_get_temp_dir().'/atlas-dev-gateway-'.bin2hex(random_bytes(4));
        mkdir($workspace, 0o755, true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("binary 'php' is not allowed");

        $gateway->dispatch($this->requestFixture($workspace));
    }

    public function test_dispatch_rejects_missing_workspace(): void
    {
        $gateway = new SymfonyClaudeCliGateway(new Repository([
            'atlas' => ['ai' => ['providers' => ['claude_cli' => ['binary' => 'claude', 'args' => ['-p']]]]],
        ]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        $gateway->dispatch($this->requestFixture('/tmp/atlas-dev-missing-workspace-'.bin2hex(random_bytes(4))));
    }

    private function requestFixture(string $workspace): ClaudeCliRequest
    {
        return new ClaudeCliRequest(
            runId: 'dev-test',
            workspace: $workspace,
            provider: 'claude_cli',
            modelFamily: 'sonnet',
            promptProjection: $this->buildSendableProjection(),
            timeoutSeconds: 30,
            fallbackAllowed: false,
        );
    }
}
