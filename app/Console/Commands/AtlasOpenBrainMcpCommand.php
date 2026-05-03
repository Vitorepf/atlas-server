<?php

namespace App\Console\Commands;

use App\Services\Ai\AtlasOpenBrainMcpService;
use Illuminate\Console\Command;

class AtlasOpenBrainMcpCommand extends Command
{
    protected $signature = 'atlas:open-brain:mcp
        {--workspace= : Default workspace for MCP tool calls}
        {--once= : Handle one JSON-RPC request and exit}
        {--describe : Print local MCP client configuration}
        {--json : Print machine-readable JSON for --describe}';

    protected $description = 'Serve Atlas Open Brain tools over local MCP stdio.';

    public function handle(AtlasOpenBrainMcpService $mcp): int
    {
        $this->configureWorkspace();

        if ((bool) $this->option('describe')) {
            return $this->describe();
        }

        $once = $this->option('once');
        if (is_string($once) && trim($once) !== '') {
            return $this->handleOnce($mcp, trim($once));
        }

        return $this->serve($mcp);
    }

    private function handleOnce(AtlasOpenBrainMcpService $mcp, string $raw): int
    {
        $request = json_decode($raw, true);
        if (! is_array($request)) {
            $this->line($this->encode($this->jsonRpcError(null, -32700, 'Parse error.')));

            return self::FAILURE;
        }

        $response = $mcp->handleJsonRpc($request);
        if ($response !== null) {
            $this->line($this->encode($response));
        }

        return self::SUCCESS;
    }

    private function serve(AtlasOpenBrainMcpService $mcp): int
    {
        $stdin = fopen('php://stdin', 'r');
        $stdout = fopen('php://stdout', 'w');
        if ($stdin === false || $stdout === false) {
            $this->error('Nao foi possivel abrir stdin/stdout para MCP.');

            return self::FAILURE;
        }

        while (($line = fgets($stdin)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $request = json_decode($line, true);
            $response = is_array($request)
                ? $mcp->handleJsonRpc($request)
                : $this->jsonRpcError(null, -32700, 'Parse error.');

            if ($response !== null) {
                fwrite($stdout, $this->encode($response)."\n");
                fflush($stdout);
            }
        }

        return self::SUCCESS;
    }

    private function describe(): int
    {
        $command = base_path('bin/atlas');
        $payload = [
            'server' => 'atlas-open-brain',
            'transport' => 'stdio',
            'command' => $command,
            'args' => ['open-brain', 'mcp'],
            'workspace' => config('atlas.ai.workdir') ?: base_path(),
            'tools' => [
                'atlas_memory_recall',
                'atlas_open_brain_context_pack',
                'atlas_memory_maintenance_status',
            ],
            'claude_desktop_config' => [
                'mcpServers' => [
                    'atlas-open-brain' => [
                        'command' => $command,
                        'args' => ['open-brain', 'mcp'],
                    ],
                ],
            ],
        ];

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Open Brain MCP</>', 'stdio/local');
        $this->components->twoColumnDetail('command', $command);
        $this->components->twoColumnDetail('args', 'open-brain mcp');
        $this->components->twoColumnDetail('workspace', (string) $payload['workspace']);
        $this->line('');
        $this->line('Claude/Codex MCP config:');
        $this->line($this->encode($payload['claude_desktop_config']));

        return self::SUCCESS;
    }

    private function configureWorkspace(): void
    {
        $workspace = $this->option('workspace');
        if (! is_string($workspace) || trim($workspace) === '') {
            return;
        }

        $workspace = trim($workspace);
        config()->set('atlas.ai.workdir', realpath($workspace) ?: $workspace);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonRpcError(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }
}
