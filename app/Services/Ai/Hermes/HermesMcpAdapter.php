<?php

namespace App\Services\Ai\Hermes;

use App\Models\AiJob;
use App\Models\HermesMcpCapabilityCandidate;
use App\Services\Ai\Hermes\Support\HermesStringListNormalizer;
use Illuminate\Support\Str;

/**
 * Governed MCP boundary for the Hermes Executive Runtime.
 *
 * Atlas — never Hermes — decides which MCP servers Hermes may reach. This
 * adapter takes the servers a mission/job requested, and for each one decides a
 * verdict: `allow` ONLY when the operator policy is the Atlas adapter, the
 * server is in the Atlas allowlist, AND it is present + enabled in the sovereign
 * capability manifest (treated as a READ MODEL). Anything else is fail-closed:
 *
 *   - not in the Atlas allowlist  -> block `not_in_atlas_allowlist` + quarantined candidate
 *   - absent from the manifest    -> block `not_present_in_manifest` (capability unavailable)
 *   - disabled in the manifest    -> `skip_disabled`
 *
 * The allowed-and-enabled subset is delegated to the managed-config provisioner
 * so Hermes receives the real secrets via a managed `HERMES_HOME` — but the receipt only
 * ever carries sha256 digests of command/args/env/url/headers/oauth. The receipt
 * is a pure function of its inputs and sealed with a deterministic `receipt_hash`.
 */
class HermesMcpAdapter
{
    use HermesAdapterReceipt;
    use HermesKeysHashHelper;

    public function __construct(
        private readonly HermesManagedMcpConfigProvisioner $provisioner,
        private readonly HermesMcpCapabilityCandidateRecorder $recorder,
    ) {}

    /**
     * @param  array<string,mixed>  $mission
     * @param  array<string,mixed>  $invocation
     * @param  array<string,mixed>  $capabilityManifest
     * @return array{receipt:array<string,mixed>,managed_config_path:?string}
     */
    public function resolve(AiJob $job, array $mission, array $invocation, string $mcpPolicy, array $capabilityManifest): array
    {
        $requested = $this->requestedServers($job, $mission);

        $receipt = [
            'schema_version' => 'atlas.hermes.mcp_adapter_receipt.v1',
            'adapter' => 'hermes_mcp_adapter',
            'mcp_policy' => $mcpPolicy,
            'canonical_tool_authority' => 'atlas',
            'config_delivery' => 'managed_config_via_HERMES_HOME',
            'mcp_enabled_now' => false,
            'servers_requested' => count($requested),
            'servers_allowed' => 0,
            'servers_blocked' => 0,
            'servers_skipped_disabled' => 0,
            'managed_config_path_hash' => null,
            'secrets_redacted' => true,
            'servers' => [],
            'capability_candidates_recorded' => [],
            'status' => 'no_mcp_requested',
        ];

        if ($requested === []) {
            return $this->seal($receipt, null);
        }

        $allowlist = $this->allowlist();
        $manifestServers = $this->manifestServers($capabilityManifest);
        $policyIsAtlasAdapter = $mcpPolicy === 'atlas_adapter';

        $allowedForProvisioning = [];

        foreach ($requested as $descriptor) {
            $name = $this->string($descriptor['name'] ?? null, 190);
            if ($name === null) {
                continue;
            }

            $manifestEntry = $manifestServers[$name] ?? null;
            $presentInManifest = $manifestEntry !== null;
            $enabledInManifest = $presentInManifest && (bool) ($manifestEntry['supported'] ?? false) === true
                && $this->manifestEnabled($manifestEntry);
            $inAllowlist = in_array($name, $allowlist, true);

            [$verdict, $reason, $candidateId, $enabledNow] = $this->decide(
                $name,
                $descriptor,
                $policyIsAtlasAdapter,
                $inAllowlist,
                $presentInManifest,
                $enabledInManifest,
                $job,
                $mission,
                $invocation,
            );

            if ($verdict === 'allow') {
                $receipt['servers_allowed']++;
                $allowedForProvisioning[] = $this->provisionDescriptor($name, $descriptor, $manifestEntry);
            } elseif ($verdict === 'skip_disabled') {
                $receipt['servers_skipped_disabled']++;
            } else {
                $receipt['servers_blocked']++;
            }

            if ($candidateId !== null) {
                $receipt['capability_candidates_recorded'][] = $candidateId;
            }

            $receipt['servers'][] = [
                'name' => $name,
                'transport' => $this->transport($descriptor),
                'verdict' => $verdict,
                'reason' => $reason,
                'present_in_manifest' => $presentInManifest,
                'in_atlas_allowlist' => $inAllowlist,
                'enabled_in_manifest' => $enabledInManifest,
                'enabled_now' => $enabledNow,
                'tool_filters' => $this->toolFilters($descriptor['tools'] ?? null),
                'command_hash' => $this->commandHash($descriptor),
                'env_keys_hash' => $this->keysHash($descriptor['env'] ?? null),
                'headers_hash' => $this->keysHash($descriptor['headers'] ?? null),
                'oauth_hash' => $this->oauthHash($descriptor['oauth'] ?? null),
                'capability_candidate_id' => $candidateId,
            ];
        }

        $managedConfigPath = null;
        if ($allowedForProvisioning !== []) {
            $managedConfigPath = $this->provisioner->write($allowedForProvisioning, $job, $mission);
        }

        $receipt['mcp_enabled_now'] = $receipt['servers_allowed'] > 0 && $managedConfigPath !== null;
        $receipt['status'] = $this->status($receipt, $policyIsAtlasAdapter);

        return $this->seal($receipt, $managedConfigPath);
    }

    /**
     * @param  array<string,mixed>  $descriptor
     * @return array{0:string,1:?string,2:?string,3:bool}
     */
    private function decide(
        string $name,
        array $descriptor,
        bool $policyIsAtlasAdapter,
        bool $inAllowlist,
        bool $presentInManifest,
        bool $enabledInManifest,
        AiJob $job,
        array $mission,
        array $invocation,
    ): array {
        if (! $policyIsAtlasAdapter) {
            return ['skip_by_policy', 'mcp_policy_not_atlas_adapter', null, false];
        }

        if (! $inAllowlist) {
            $candidateId = $this->recordCandidate($name, $descriptor, $this->candidateSource($descriptor), $job, $mission, $invocation);

            return ['block', 'not_in_atlas_allowlist', $candidateId, false];
        }

        if (! $presentInManifest) {
            return ['block', 'not_present_in_manifest', null, false];
        }

        if (! $this->descriptorEnabled($descriptor) || ! $enabledInManifest) {
            return ['skip_disabled', 'server_disabled', null, false];
        }

        return ['allow', 'allowlisted_present_enabled', null, true];
    }

    /**
     * @param  array<string,mixed>  $descriptor
     * @param  array<string,mixed>  $mission
     * @param  array<string,mixed>  $invocation
     */
    private function recordCandidate(string $name, array $descriptor, string $source, AiJob $job, array $mission, array $invocation): ?string
    {
        $descriptor['name'] = $name;
        $descriptor['source'] = $source;

        $candidate = $this->recorder->record($descriptor, $job, $mission, $invocation);

        return $candidate instanceof HermesMcpCapabilityCandidate ? $candidate->id : null;
    }

    /**
     * A server flagged as a catalog install stays a catalog candidate; everything
     * else newly seen in a mission/job that is not allowlisted is a manifest diff.
     *
     * @param  array<string,mixed>  $descriptor
     */
    private function candidateSource(array $descriptor): string
    {
        $source = $this->string($descriptor['source'] ?? null, 40);

        return $source === 'catalog_install' ? 'catalog_install' : 'manifest_diff';
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function status(array $receipt, bool $policyIsAtlasAdapter): string
    {
        if (! $policyIsAtlasAdapter) {
            return 'skipped_by_policy';
        }

        if ($receipt['servers_allowed'] > 0 && $receipt['mcp_enabled_now'] === true) {
            return 'provisioned_for_invocation';
        }

        $blockedNotAllowlisted = false;
        $blockedNotPresent = false;
        foreach ($receipt['servers'] as $server) {
            if (($server['reason'] ?? null) === 'not_in_atlas_allowlist') {
                $blockedNotAllowlisted = true;
            }
            if (($server['reason'] ?? null) === 'not_present_in_manifest') {
                $blockedNotPresent = true;
            }
        }

        if ($blockedNotAllowlisted) {
            return 'all_blocked_not_allowlisted';
        }

        if ($blockedNotPresent) {
            return 'mcp_capability_unavailable';
        }

        // Everything requested was disabled — no MCP capability provisioned.
        return 'mcp_capability_unavailable';
    }

    /**
     * The descriptor handed to the provisioner keeps the real, un-redacted
     * config (secrets included) but is constrained to the manifest-declared tool
     * filters where the manifest tightens them. tools.exclude always wins.
     *
     * @param  array<string,mixed>  $descriptor
     * @param  array<string,mixed>|null  $manifestEntry
     * @return array<string,mixed>
     */
    private function provisionDescriptor(string $name, array $descriptor, ?array $manifestEntry): array
    {
        $descriptor['name'] = $name;
        $descriptor['transport'] = $this->transport($descriptor);
        $descriptor['enabled'] = true;
        $descriptor['tools'] = $this->mergedToolFilters($descriptor['tools'] ?? null, $manifestEntry);

        return $descriptor;
    }

    /**
     * @param  array<string,mixed>|null  $manifestEntry
     * @return array{include:array<int,string>,exclude:array<int,string>}
     */
    private function mergedToolFilters(mixed $tools, ?array $manifestEntry): array
    {
        $filters = $this->toolFilters($tools);

        $manifestDetail = is_array($manifestEntry['detail'] ?? null) ? $manifestEntry['detail'] : [];
        $manifestFilters = $this->toolFilters($manifestDetail['tools'] ?? ($manifestEntry['tools'] ?? null));

        $include = array_values(array_unique(array_merge($filters['include'], $manifestFilters['include'])));
        // exclude is a security control: union both sources, exclude wins over include.
        $exclude = array_values(array_unique(array_merge($filters['exclude'], $manifestFilters['exclude'])));
        $include = array_values(array_filter($include, fn (string $tool): bool => ! in_array($tool, $exclude, true)));

        return ['include' => $include, 'exclude' => $exclude];
    }

    /**
     * @param  array<string,mixed>  $mission
     * @return array<int,array<string,mixed>>
     */
    private function requestedServers(AiJob $job, array $mission): array
    {
        $servers = data_get($mission, 'scope.mcp.servers', null);
        if (! is_array($servers) || $servers === []) {
            $servers = data_get($job->payload, 'hermes.mcp.servers', []);
        }

        $servers = is_array($servers) ? array_values($servers) : [];

        return array_values(array_filter($servers, fn (mixed $server): bool => is_array($server)));
    }

    /**
     * Capability manifest is a READ MODEL: index the `mcp_server` entries by
     * capability_key so an absent server is fail-closed.
     *
     * @param  array<string,mixed>  $manifest
     * @return array<string,array<string,mixed>>
     */
    private function manifestServers(array $manifest): array
    {
        $entries = is_array($manifest['entries'] ?? null) ? $manifest['entries'] : [];
        $byName = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if ($this->string($entry['capability_class'] ?? null, 40) !== 'mcp_server') {
                continue;
            }

            $key = $this->string($entry['capability_key'] ?? null, 190);
            if ($key === null) {
                continue;
            }

            $byName[$key] = $entry;
        }

        return $byName;
    }

    /**
     * @param  array<string,mixed>  $manifestEntry
     */
    private function manifestEnabled(array $manifestEntry): bool
    {
        $detail = is_array($manifestEntry['detail'] ?? null) ? $manifestEntry['detail'] : [];

        // A manifest entry is "enabled" unless it explicitly flags itself off.
        foreach (['enabled', 'enabled_now'] as $key) {
            if (array_key_exists($key, $detail)) {
                return (bool) $detail[$key];
            }
            if (array_key_exists($key, $manifestEntry)) {
                return (bool) $manifestEntry[$key];
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $descriptor
     */
    private function descriptorEnabled(array $descriptor): bool
    {
        if (array_key_exists('enabled', $descriptor)) {
            return (bool) $descriptor['enabled'];
        }

        return true;
    }

    /**
     * @return array<int,string>
     */
    private function allowlist(): array
    {
        $configured = config('atlas.ai.providers.hermes_cli.mcp.allowed_servers', []);
        $configured = is_array($configured) ? $configured : [];

        return collect($configured)
            ->map(fn (mixed $item): ?string => $this->string($item, 190))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{include:array<int,string>,exclude:array<int,string>}
     */
    private function toolFilters(mixed $tools): array
    {
        $tools = is_array($tools) ? $tools : [];

        return [
            'include' => HermesStringListNormalizer::bounded($tools['include'] ?? null, 64, 190),
            'exclude' => HermesStringListNormalizer::bounded($tools['exclude'] ?? null, 64, 190),
        ];
    }

    /**
     * @param  array<string,mixed>  $descriptor
     */
    private function transport(array $descriptor): string
    {
        $transport = $this->string($descriptor['transport'] ?? null, 40);
        if ($transport !== null && in_array($transport, ['stdio', 'http', 'sse'], true)) {
            return $transport;
        }

        $url = $this->string($descriptor['url'] ?? null, 2000);

        return $url !== null ? 'http' : 'stdio';
    }

    /**
     * @param  array<string,mixed>  $descriptor
     */
    private function commandHash(array $descriptor): ?string
    {
        $command = $descriptor['command'] ?? null;
        $args = $descriptor['args'] ?? null;

        $parts = [];
        if (is_string($command) || is_numeric($command)) {
            $parts[] = (string) $command;
        }
        if (is_array($args)) {
            foreach ($args as $arg) {
                if (is_string($arg) || is_numeric($arg)) {
                    $parts[] = (string) $arg;
                }
            }
        }

        if ($parts === []) {
            return null;
        }

        return hash('sha256', implode(' ', $parts));
    }

    private function oauthHash(mixed $value): ?string
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array{receipt:array<string,mixed>,managed_config_path:?string}
     */
    private function seal(array $receipt, ?string $managedConfigPath): array
    {
        $receipt['managed_config_path_hash'] = $managedConfigPath !== null
            ? hash('sha256', $managedConfigPath)
            : null;

        return [
            'receipt' => $this->withReceiptHash($receipt),
            'managed_config_path' => $managedConfigPath,
        ];
    }

    private function string(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
