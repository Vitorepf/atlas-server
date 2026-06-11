<?php

namespace App\Console\Commands;

use App\Services\Ai\AtlasOpenBrainMcpService;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
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

        while (($message = $this->readMessage($stdin)) !== null) {
            [$raw, $framed] = $message;
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }

            $request = json_decode($raw, true);
            $response = is_array($request)
                ? $mcp->handleJsonRpc($request)
                : $this->jsonRpcError(null, -32700, 'Parse error.');

            if ($response !== null) {
                $this->writeResponse($stdout, $response, $framed);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  resource  $stdin
     * @return array{0:string,1:bool}|null
     */
    private function readMessage($stdin): ?array
    {
        $line = fgets($stdin);
        if ($line === false) {
            return null;
        }

        $line = rtrim($line, "\r\n");
        if (! preg_match('/^Content-Length:\s*(\d+)\s*$/i', $line, $match)) {
            return [$line, false];
        }

        $length = (int) $match[1];
        while (($header = fgets($stdin)) !== false) {
            $header = rtrim($header, "\r\n");
            if ($header === '') {
                break;
            }

            if (preg_match('/^Content-Length:\s*(\d+)\s*$/i', $header, $headerMatch)) {
                $length = (int) $headerMatch[1];
            }
        }

        $body = '';
        while (strlen($body) < $length && ! feof($stdin)) {
            $chunk = fread($stdin, $length - strlen($body));
            if ($chunk === false || $chunk === '') {
                break;
            }

            $body .= $chunk;
        }

        return [$body, true];
    }

    /**
     * @param  resource  $stdout
     * @param  array<string,mixed>  $response
     */
    private function writeResponse($stdout, array $response, bool $framed): void
    {
        $encoded = $this->encode($response);
        if ($framed) {
            fwrite($stdout, 'Content-Length: '.strlen($encoded)."\r\n\r\n".$encoded);
            fflush($stdout);

            return;
        }

        fwrite($stdout, $encoded."\n");
        fflush($stdout);
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
            'workspace_id' => $this->resolvedWorkspaceId(),
            'tools' => [
                'atlas_memory_recall',
                'atlas_open_brain_context_pack',
                'atlas_context_expand',
                'atlas_memory_maintenance_status',
                'atlas_code_find_relevant',
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
        $this->components->twoColumnDetail('workspace_id', (string) $payload['workspace_id']);
        $this->line('');
        $this->line('Claude/Codex MCP config:');
        $this->line($this->encode($payload['claude_desktop_config']));

        return self::SUCCESS;
    }

    /**
     * Resolve the default workspace for every MCP tool call in this process.
     *
     * AP-815 I-4 (Stage 2): when the operator passes --workspace we honour it verbatim
     * (path or id). When they DON'T, the default must be the REAL indexed primary — the
     * running app's base_path(), which {@see CodeGraphWorkspaceIdentity} maps to the stable
     * 'atlas-server' id the code-graph read-model is keyed by. Previously the command left
     * `atlas.ai.workdir` untouched, so a stale/foreign config value (e.g. a different repo
     * path) leaked into the reported workspace and the code-graph tools resolved to a
     * workspace that holds zero symbols. Defaulting to base_path() makes a tool called
     * WITHOUT --workspace target the primary graph instead.
     *
     * Only `atlas.ai.workdir` is mutated (an in-memory config override for this process);
     * config/atlas.php is never edited and nothing is persisted.
     */
    private function configureWorkspace(): void
    {
        $workspace = $this->option('workspace');

        if (is_string($workspace) && trim($workspace) !== '') {
            $workspace = trim($workspace);
            config()->set('atlas.ai.workdir', realpath($workspace) ?: $workspace);

            return;
        }

        // No explicit workspace: anchor to the real indexed primary (base_path → the
        // 'atlas-server' workspace id) instead of whatever stale value config carries, so
        // code-graph MCP tools resolve to the primary graph by default.
        $primary = base_path();
        config()->set('atlas.ai.workdir', realpath($primary) ?: $primary);
    }

    /**
     * The stable workspace id the configured workspace path resolves to (for --describe).
     */
    private function resolvedWorkspaceId(): string
    {
        $workspace = config('atlas.ai.workdir');
        $workspace = is_string($workspace) && trim($workspace) !== '' ? trim($workspace) : base_path();

        return app(CodeGraphWorkspaceIdentity::class)->resolve($workspace);
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
