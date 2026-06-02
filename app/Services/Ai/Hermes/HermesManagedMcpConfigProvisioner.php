<?php

namespace App\Services\Ai\Hermes;

use App\Models\AiJob;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Materializes an Atlas-MANAGED Hermes home that carries the real (un-redacted)
 * secret values Hermes needs to actually connect to the allowlisted MCP servers.
 *
 * VERIFIED against the installed Hermes (v0.15.1): `HERMES_CONFIG=<file>` is
 * IGNORED, but `HERMES_HOME=<dir>` is honored. So Atlas writes a managed HOME
 * directory under `storage/app/hermes/home/<mission-trace>/` containing a
 * `config.yaml` with ONLY the allowed-and-enabled `mcp_servers`, and SYMLINKS
 * the operator's real assets (skills/, skill-bundles/, mcp-tokens/, .env) into
 * it so credentials and skills survive — Atlas never mutates the operator's
 * global `~/.hermes`. The managed dir is 0700, raw secrets live ONLY in its
 * config.yaml (outside the sealed receipt), and `forget()` removes it for full
 * reversibility. The directory is also the shared home the hook bridge writes
 * its governed `hooks:` block into, so MCP + hooks compose in one home.
 */
class HermesManagedMcpConfigProvisioner
{
    public function __construct(
        private readonly Filesystem $files,
    ) {}

    /**
     * @param  array<int,array<string,mixed>>  $allowedServers
     * @param  array<string,mixed>  $mission
     * @return string|null  the managed HERMES_HOME directory, or null when nothing to provision
     */
    public function write(array $allowedServers, AiJob $job, array $mission): ?string
    {
        $servers = $this->normalizeServers($allowedServers);
        if ($servers === []) {
            return null;
        }

        $home = $this->path($job, $mission);
        $this->files->ensureDirectoryExists($home, 0700);
        $this->linkOperatorAssets($home);

        $body = $this->encode(['mcp_servers' => $servers]);
        $configPath = $home.'/config.yaml';
        $this->files->put($configPath, $body);
        @chmod($configPath, 0600);

        return $home;
    }

    /**
     * Managed HERMES_HOME directory (NOT a file, NOT the operator's ~/.hermes).
     *
     * @param  array<string,mixed>  $mission
     */
    public function path(AiJob $job, array $mission): string
    {
        return storage_path('app/hermes/home/'.$this->slug($job, $mission));
    }

    /**
     * @param  array<string,mixed>  $mission
     */
    public function forget(AiJob $job, array $mission): void
    {
        $home = $this->path($job, $mission);
        if ($this->files->isDirectory($home)) {
            // Removes the managed dir + the symlink entries (not their targets).
            $this->files->deleteDirectory($home);
        }
    }

    /**
     * Symlinks the operator's real Hermes assets into the managed home so skills,
     * credentials and OAuth tokens keep working while Atlas controls mcp_servers.
     * Never copies or mutates the operator's files; only adds links into storage.
     */
    private function linkOperatorAssets(string $home): void
    {
        $operatorHome = $this->operatorHome();
        if ($operatorHome === null || ! $this->files->isDirectory($operatorHome)) {
            return;
        }

        foreach (['skills', 'skill-bundles', 'mcp-tokens', '.env'] as $asset) {
            $target = $operatorHome.'/'.$asset;
            $link = $home.'/'.$asset;
            if (! file_exists($target) || file_exists($link) || is_link($link)) {
                continue;
            }
            @symlink($target, $link);
        }
    }

    private function operatorHome(): ?string
    {
        $env = getenv('HERMES_HOME');
        if (is_string($env) && trim($env) !== '') {
            return rtrim(trim($env), '/');
        }

        $base = getenv('HOME');

        return is_string($base) && trim($base) !== '' ? rtrim(trim($base), '/').'/.hermes' : null;
    }

    /**
     * Collapses the allowed-server list into the keyed `mcp_servers` map Hermes
     * expects, dropping internal keys but preserving the real config
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
