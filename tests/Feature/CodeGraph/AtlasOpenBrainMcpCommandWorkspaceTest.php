<?php

declare(strict_types=1);

namespace Tests\Feature\CodeGraph;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * AP-815 · I-4 (Stage 2) — the MCP server defaults its workspace to the REAL indexed
 * primary (base_path → the stable 'atlas-server' id via CodeGraphWorkspaceIdentity) when
 * no --workspace is passed, instead of leaking a stale/foreign config('atlas.ai.workdir')
 * value that resolves to a workspace holding zero symbols.
 *
 * Proven through the --describe --json surface (no provider tokens, no DB writes): the
 * reported workspace/workspace_id must be the primary by default, and an explicit
 * --workspace must still be honoured verbatim.
 */
final class AtlasOpenBrainMcpCommandWorkspaceTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private function describe(array $params = []): array
    {
        $params['--describe'] = true;
        $params['--json'] = true;

        $exit = Artisan::call('atlas:open-brain:mcp', $params);
        $this->assertSame(0, $exit, '--describe --json must exit 0');

        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($decoded, '--describe --json must emit a JSON object');

        return $decoded;
    }

    public function test_default_workspace_resolves_to_the_real_primary_not_a_stale_config_path(): void
    {
        // Simulate the stale/foreign value the command previously leaked into the workspace.
        config()->set('atlas.ai.workdir', '/Users/someone/Develop/some-other-repo');

        $payload = $this->describe();

        // The reported workspace is the running app's primary, NOT the stale config path...
        $this->assertSame(base_path(), $payload['workspace']);
        $this->assertNotSame('/Users/someone/Develop/some-other-repo', $payload['workspace']);
        // ...and it resolves to the stable primary id the code-graph read-model is keyed by.
        $this->assertSame('atlas-server', $payload['workspace_id']);
        // The code-graph find tool is advertised in the describe surface.
        $this->assertContains('atlas_code_find_relevant', $payload['tools']);
    }

    public function test_explicit_workspace_option_is_still_honoured(): void
    {
        $explicit = sys_get_temp_dir();

        $payload = $this->describe(['--workspace' => $explicit]);

        $this->assertSame(realpath($explicit) ?: $explicit, $payload['workspace']);
        // A non-primary path gets its OWN resolved id (never the primary default).
        $this->assertNotSame('atlas-server', $payload['workspace_id']);
    }

    public function test_stdio_accepts_content_length_framed_json_rpc(): void
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($body);

        $process = new Process([PHP_BINARY, base_path('artisan'), 'atlas:open-brain:mcp'], base_path());
        $process->setInput('Content-Length: '.strlen($body)."\r\n\r\n".$body);
        $process->setTimeout(15);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $output = $process->getOutput();
        $this->assertStringStartsWith('Content-Length: ', $output);
        $parts = explode("\r\n\r\n", $output, 2);
        $this->assertCount(2, $parts);
        $decoded = json_decode($parts[1], true);

        $this->assertIsArray($decoded);
        $this->assertSame(1, $decoded['id'] ?? null);
        $this->assertNotEmpty($decoded['result']['tools'] ?? []);
    }

    public function test_stdio_ignores_json_rpc_notifications_without_responses(): void
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'initialized',
            'params' => [],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($body);

        $process = new Process([PHP_BINARY, base_path('artisan'), 'atlas:open-brain:mcp'], base_path());
        $process->setInput('Content-Length: '.strlen($body)."\r\n\r\n".$body);
        $process->setTimeout(15);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('', $process->getOutput());
    }

    public function test_stdio_accepts_json_rpc_batch_requests(): void
    {
        $body = json_encode([
            [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'ping',
                'params' => [],
            ],
            [
                'jsonrpc' => '2.0',
                'method' => 'initialized',
                'params' => [],
            ],
            [
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/list',
                'params' => [],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($body);

        $process = new Process([PHP_BINARY, base_path('artisan'), 'atlas:open-brain:mcp'], base_path());
        $process->setInput('Content-Length: '.strlen($body)."\r\n\r\n".$body);
        $process->setTimeout(15);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $output = $process->getOutput();
        $this->assertStringStartsWith('Content-Length: ', $output);
        $parts = explode("\r\n\r\n", $output, 2);
        $this->assertCount(2, $parts);
        $decoded = json_decode($parts[1], true);

        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);
        $this->assertSame([1, 2], array_column($decoded, 'id'));
        $this->assertNotEmpty($decoded[1]['result']['tools'] ?? []);
    }

    public function test_context_pack_tool_defaults_to_process_workspace_when_argument_is_omitted(): void
    {
        $explicit = sys_get_temp_dir();
        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_context_pack',
                'arguments' => [
                    'task' => 'workspace default propagation',
                    'budget' => 300,
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($body);

        $process = new Process([
            PHP_BINARY,
            base_path('artisan'),
            'atlas:open-brain:mcp',
            '--workspace='.$explicit,
            '--once='.$body,
        ], base_path());
        $process->setTimeout(20);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $decoded = json_decode(trim($process->getOutput()), true);
        $this->assertIsArray($decoded);

        $text = data_get($decoded, 'result.content.0.text');
        $this->assertIsString($text);
        $payload = json_decode($text, true);
        $this->assertIsArray($payload);

        $this->assertTrue($payload['ok'] ?? false);
        $this->assertSame(
            app(\App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity::class)->resolveWorkspaceOrId($explicit),
            data_get($payload, 'pack.workspace'),
        );
    }
}
