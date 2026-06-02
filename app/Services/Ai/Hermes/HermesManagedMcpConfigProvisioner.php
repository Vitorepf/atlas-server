<?php

namespace App\Services\Ai\Hermes;

use App\Models\AiJob;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Writes the Atlas-MANAGED Hermes MCP config that carries the real (un-redacted)
 * secret values Hermes needs to actually connect to the allowlisted servers.
 *
 * Atlas hands Hermes ONLY the allowed-and-enabled `mcp_servers` via a managed
 * file delivered through `HERMES_CONFIG`. The file lives under the app storage
 * tree (`storage/app/hermes/mcp/<mission-trace>.yaml`), is written 0600, and is
 * NEVER `~/.hermes/config.yaml`: Atlas never mutates the operator's global
 * config. Raw secrets live ONLY here, deliberately outside the sealed receipt.
 * `forget()` deletes the file for reversibility.
 */
class HermesManagedMcpConfigProvisioner
{
    public function __construct(
        private readonly Filesystem $files,
    ) {}

    /**
     * @param  array<int,array<string,mixed>>  $allowedServers
     * @param  array<string,mixed>  $mission
     */
    public function write(array $allowedServers, AiJob $job, array $mission): ?string
    {
        $servers = $this->normalizeServers($allowedServers);
        if ($servers === []) {
            return null;
        }

        $path = $this->path($job, $mission);
        $this->files->ensureDirectoryExists(dirname($path), 0700);

        $body = $this->encode(['mcp_servers' => $servers]);
        $this->files->put($path, $body);
        @chmod($path, 0600);

        return $path;
    }

    /**
     * @param  array<string,mixed>  $mission
     */
    public function path(AiJob $job, array $mission): string
    {
        return storage_path('app/hermes/mcp/'.$this->slug($job, $mission).'.yaml');
    }

    /**
     * @param  array<string,mixed>  $mission
     */
    public function forget(AiJob $job, array $mission): void
    {
        $path = $this->path($job, $mission);
        if ($this->files->exists($path)) {
            $this->files->delete($path);
        }
    }

    /**
     * Collapses the allowed-server list into the keyed `mcp_servers` map Hermes
     * expects, dropping the internal `name` key but preserving the real config
     * (command/args/env/url/headers/oauth/tools/...) so the server functions.
     *
     * @param  array<int,array<string,mixed>>  $allowedServers
     * @return array<string,array<string,mixed>>
     */
    private function normalizeServers(array $allowedServers): array
    {
        $servers = [];

        foreach ($allowedServers as $server) {
            if (! is_array($server)) {
                continue;
            }

            $name = $server['name'] ?? null;
            if (! is_string($name) || trim($name) === '') {
                continue;
            }
            $name = trim($name);

            $config = $server;
            unset($config['name'], $config['source'], $config['transport']);
            $config['enabled'] = true;

            $servers[$name] = $config;
        }

        return $servers;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encode(array $payload): string
    {
        if (class_exists(Yaml::class)) {
            return Yaml::dump($payload, 6, 2);
        }

        // JSON is a valid YAML 1.2 subset — avoids a hard symfony/yaml dependency.
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string,mixed>  $mission
     */
    private function slug(AiJob $job, array $mission): string
    {
        $trace = is_string($job->trace_id) && trim($job->trace_id) !== ''
            ? trim($job->trace_id)
            : (is_string($mission['mission_id'] ?? null) ? (string) $mission['mission_id'] : 'no_trace');

        $slug = Str::slug($trace, '-');

        return $slug !== '' ? Str::limit($slug, 120, '') : 'hermes-mcp-'.substr(hash('sha256', $trace), 0, 24);
    }
}
