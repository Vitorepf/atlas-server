<?php

namespace App\Services\Ai\TerminalDev\Mcp;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Minimal MCP client: load ~/.atlas/mcp.json + project .atlas/mcp.json,
 * list tools via tools/list, call tools/call.
 */
final class TerminalMcpClient
{
    /**
     * @return list<array{name:string,command:string,args:list<string>,env:array<string,string>,source:string}>
     */
    public function servers(string $workspace): array
    {
        $merged = [];
        foreach ($this->configPaths($workspace) as $path => $source) {
            if (! File::isFile($path)) {
                continue;
            }
            $raw = json_decode(File::get($path), true);
            if (! is_array($raw)) {
                continue;
            }
            $servers = $raw['mcpServers'] ?? $raw['servers'] ?? $raw;
            if (! is_array($servers)) {
                continue;
            }
            foreach ($servers as $name => $cfg) {
                if (! is_array($cfg)) {
                    continue;
                }
                $command = (string) ($cfg['command'] ?? '');
                if ($command === '') {
                    continue;
                }
                $args = array_values(array_filter((array) ($cfg['args'] ?? []), 'is_string'));
                $env = [];
                foreach ((array) ($cfg['env'] ?? []) as $k => $v) {
                    if (is_string($k) && (is_string($v) || is_numeric($v))) {
                        $env[$k] = (string) $v;
                    }
                }
                $merged[(string) $name] = [
                    'name' => (string) $name,
                    'command' => $command,
                    'args' => $args,
                    'env' => $env,
                    'source' => $source,
                ];
            }
        }

        return array_values($merged);
    }

    /**
     * @return list<array{server:string,name:string,description:string}>
     */
    public function listTools(string $workspace, int $timeoutSeconds = 8): array
    {
        $tools = [];
        foreach ($this->servers($workspace) as $server) {
            $result = $this->rpc($server, 'tools/list', new \stdClass, $timeoutSeconds);
            $list = data_get($result, 'result.tools', []);
            if (! is_array($list)) {
                continue;
            }
            foreach ($list as $tool) {
                if (! is_array($tool)) {
                    continue;
                }
                $tools[] = [
                    'server' => $server['name'],
                    'name' => (string) ($tool['name'] ?? ''),
                    'description' => (string) ($tool['description'] ?? ''),
                ];
            }
        }

        return $tools;
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function callTool(string $workspace, string $serverName, string $toolName, array $arguments = [], int $timeoutSeconds = 60): array
    {
        foreach ($this->servers($workspace) as $server) {
            if ($server['name'] !== $serverName) {
                continue;
            }

            return $this->rpc($server, 'tools/call', [
                'name' => $toolName,
                'arguments' => $arguments === [] ? new \stdClass : $arguments,
            ], $timeoutSeconds);
        }

        return ['error' => ['message' => 'mcp server not found: '.$serverName]];
    }

    /**
     * @param  array{name:string,command:string,args:list<string>,env:array<string,string>}  $server
     * @param  array<string,mixed>|\stdClass  $params
     * @return array<string,mixed>
     */
    private function rpc(array $server, string $method, array|\stdClass $params, int $timeoutSeconds): array
    {
        $init = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new \stdClass,
                'clientInfo' => ['name' => 'atlas-terminal', 'version' => '0.1.0'],
            ],
        ], JSON_UNESCAPED_SLASHES);
        $req = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => $method,
            'params' => $params,
        ], JSON_UNESCAPED_SLASHES);
        if ($init === false || $req === false) {
            return ['error' => ['message' => 'json encode failed']];
        }

        $cmd = array_merge([$server['command']], $server['args']);
        $process = new Process($cmd, null, $server['env'] ?: null, $init."\n".$req."\n", (float) $timeoutSeconds);
        try {
            $process->run();
        } catch (\Throwable $e) {
            return ['error' => ['message' => $e->getMessage()]];
        }

        $lines = preg_split('/\r\n|\n|\r/', trim($process->getOutput())) ?: [];
        $last = null;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded) && ((int) ($decoded['id'] ?? 0) === 2 || isset($decoded['result']) || isset($decoded['error']))) {
                $last = $decoded;
            }
        }

        if ($last === null) {
            $err = trim($process->getErrorOutput());

            return [
                'error' => [
                    'message' => 'no mcp response',
                    'stderr' => mb_substr($err, 0, 500),
                    'exit' => $process->getExitCode(),
                ],
            ];
        }

        return $last;
    }

    /**
     * @return array<string,string> path => source
     */
    private function configPaths(string $workspace): array
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '';
        $paths = [];
        if ($home !== '') {
            $paths[rtrim((string) $home, '/').'/.atlas/mcp.json'] = 'user';
        }
        $paths[rtrim($workspace, '/').'/.atlas/mcp.json'] = 'project';
        $paths[rtrim($workspace, '/').'/.mcp.json'] = 'project_root';

        return $paths;
    }
}
