<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Engineering\CodeGraph\CodeGraphLanguageServer;
use Illuminate\Console\Command;

/**
 * Thin stdio wrapper around {@see CodeGraphLanguageServer} — exposes the code
 * graph as an LSP-shaped JSON-RPC endpoint an editor (or a probe) can talk to.
 *
 * The TESTABLE core is the service's handle(); this command only does line-based
 * stdio framing. `--once` handles a single request (the safe, scriptable form);
 * with no flag it reads newline-delimited JSON-RPC from STDIN until EOF.
 * Strictly additive — a NEW command, no existing wiring changes.
 */
class AtlasCodeGraphLspCommand extends Command
{
    protected $signature = 'atlas:code-graph:lsp
        {--workspace= : Workspace whose symbol graph to serve (defaults to the primary)}
        {--once= : Handle one JSON-RPC request (raw JSON) and exit}';

    protected $description = 'Serve the Atlas code graph as a minimal LSP (JSON-RPC: initialize, textDocument/definition, textDocument/references).';

    public function handle(CodeGraphLanguageServer $server): int
    {
        $once = $this->option('once');
        if (is_string($once) && trim($once) !== '') {
            return $this->handleOnce($server, trim($once));
        }

        return $this->serve($server);
    }

    private function handleOnce(CodeGraphLanguageServer $server, string $raw): int
    {
        $request = json_decode($raw, true);
        if (! is_array($request)) {
            $this->line($this->encode(['jsonrpc' => CodeGraphLanguageServer::JSONRPC_VERSION, 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error.']]));

            return self::FAILURE;
        }

        $this->line($this->encode($server->handle($this->withWorkspace($request))));

        return self::SUCCESS;
    }

    private function serve(CodeGraphLanguageServer $server): int
    {
        $stdin = fopen('php://stdin', 'rb');
        if ($stdin === false) {
            $this->error('Could not open STDIN.');

            return self::FAILURE;
        }

        while (($line = fgets($stdin)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $request = json_decode($line, true);
            if (! is_array($request)) {
                $this->line($this->encode(['jsonrpc' => CodeGraphLanguageServer::JSONRPC_VERSION, 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error.']]));

                continue;
            }
            $this->line($this->encode($server->handle($this->withWorkspace($request))));
        }

        fclose($stdin);

        return self::SUCCESS;
    }

    /**
     * Fold the --workspace option into the request params (request params win).
     *
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    private function withWorkspace(array $request): array
    {
        $workspace = $this->option('workspace');
        if (! is_string($workspace) || trim($workspace) === '') {
            return $request;
        }

        $params = is_array($request['params'] ?? null) ? $request['params'] : [];
        $params['workspace'] ??= trim($workspace);
        $request['params'] = $params;

        return $request;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
