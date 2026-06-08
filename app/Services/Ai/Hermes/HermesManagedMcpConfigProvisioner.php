<?php

namespace App\Services\Ai\Hermes;

use App\Models\AiJob;
use Illuminate\Filesystem\Filesystem;

/**
 * Provisions the allowed-and-enabled MCP servers into an Atlas-MANAGED HERMES_HOME.
 *
 * The managed-home plumbing — 0700 dir, learning-state symlinks, operator
 * `memory:` carry-over, config.yaml 0600, traversal-safe slug, forget — lives in
 * ONE place: {@see ManagedHermesHome}. This class only decides WHICH servers go
 * in (normalizeServers) and seeds the home by mission trace. VERIFIED against
 * Hermes v0.15.1: `HERMES_HOME=<dir>` is honored, `HERMES_CONFIG=<file>` is
 * ignored — so Atlas writes a managed home and never mutates the operator's
 * global `~/.hermes`.
 */
class HermesManagedMcpConfigProvisioner
{
    private const KIND = 'home';

    public function __construct(
        private readonly Filesystem $files,
        private readonly HermesLearningHomeLinker $learning = new HermesLearningHomeLinker(new Filesystem()),
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

        return $this->home()->write(self::KIND, $this->seed($job, $mission), ['mcp_servers' => $servers]);
    }

    /**
     * Managed HERMES_HOME directory (NOT a file, NOT the operator's ~/.hermes).
     *
     * @param  array<string,mixed>  $mission
     */
    public function path(AiJob $job, array $mission): string
    {
        return $this->home()->path(self::KIND, $this->seed($job, $mission));
    }

    /**
     * @param  array<string,mixed>  $mission
     */
    public function forget(AiJob $job, array $mission): void
    {
        $this->home()->forget(self::KIND, $this->seed($job, $mission));
    }

    private function home(): ManagedHermesHome
    {
        return new ManagedHermesHome($this->files, $this->learning);
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
     * Raw home seed (mission trace) — {@see ManagedHermesHome::safeSlug} makes it
     * filesystem-safe; this only chooses the source value.
     *
     * @param  array<string,mixed>  $mission
     */
    private function seed(AiJob $job, array $mission): string
    {
        if (is_string($job->trace_id) && trim($job->trace_id) !== '') {
            return trim($job->trace_id);
        }

        return is_string($mission['mission_id'] ?? null) ? (string) $mission['mission_id'] : 'no_trace';
    }
}
