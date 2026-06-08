<?php

namespace App\Services\Ai\Hermes;

use App\Support\AtlasSecurity;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Read-only introspection of the locally installed Hermes CLI.
 *
 * The probe runs ONLY non-mutating discovery commands (`--version`, `--help`,
 * `<sub> list`, `<sub> --help`) under a bounded timeout, redacts every byte of
 * captured output, and produces the pinned `atlas.hermes.capability_manifest.v1`
 * contract. It NEVER invokes `hermes chat`/`send` or anything that calls a model,
 * and it NEVER throws: a missing binary or timeout yields a degraded manifest so
 * the registry/builder can keep operating default-safe.
 *
 * The registry assigns `manifest_version`; the probe emits it as 0 (a placeholder
 * the registry overwrites when it diffs and seals the manifest).
 */
class HermesCapabilityProbe
{
    use HermesAdapterReceipt;

    public const SCHEMA_VERSION = 'atlas.hermes.capability_manifest.v1';

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function probe(array $options = []): array
    {
        $binary = $this->resolveBinary($options);
        $resolved = $this->resolveExecutable($binary);
        $probedAt = now()->toIso8601String();

        if ($resolved === null) {
            return $this->finalizeManifest([
                'schema_version' => self::SCHEMA_VERSION,
                'manifest_version' => 0,
                'hermes_version' => null,
                'probed_at' => $probedAt,
                'probe_status' => 'binary_offline',
                'binary_present' => false,
                'section_status' => $this->offlineSectionStatus(),
                'entries' => [],
            ]);
        }

        $version = $this->hermesVersion($resolved);

        $sectionStatus = [];
        $entries = [];

        [$subEntries, $subStatus] = $this->probeSubcommands($resolved);
        $sectionStatus['subcommands'] = $subStatus;
        $entries = array_merge($entries, $subEntries);

        $chatHelp = $this->run($resolved, ['chat', '--help']);
        $sectionStatus['chat'] = $this->statusFromRun($chatHelp);

        [$flagEntries] = $this->probeChatFlags($chatHelp);
        $entries = array_merge($entries, $flagEntries);

        [$toolsetEntries, $toolsetStatus] = $this->probeToolsets($resolved, $chatHelp);
        $sectionStatus['toolsets'] = $toolsetStatus;
        $entries = array_merge($entries, $toolsetEntries);

        [$mcpEntries, $mcpStatus] = $this->probeMcpServers($resolved);
        $sectionStatus['mcp'] = $mcpStatus;
        $entries = array_merge($entries, $mcpEntries);

        [$skillEntries, $skillStatus] = $this->probeSkills($resolved);
        $sectionStatus['skills'] = $skillStatus;
        $entries = array_merge($entries, $skillEntries);

        [$bundleEntries, $bundleStatus] = $this->probeBundles($resolved);
        $sectionStatus['bundles'] = $bundleStatus;
        $entries = array_merge($entries, $bundleEntries);

        [$pluginEntries, $pluginStatus] = $this->probePlugins($resolved);
        $sectionStatus['plugins'] = $pluginStatus;
        $entries = array_merge($entries, $pluginEntries);

        [$hookEntries, $hookStatus] = $this->probeHooks($resolved);
        $sectionStatus['hooks'] = $hookStatus;
        $entries = array_merge($entries, $hookEntries);

        [$delegationEntries, $delegationStatus] = $this->probeDelegation($resolved, $chatHelp);
        $sectionStatus['delegation'] = $delegationStatus;
        $entries = array_merge($entries, $delegationEntries);

        [$providerEntries, $providerStatus] = $this->probeProviders($resolved, $chatHelp);
        $sectionStatus['providers'] = $providerStatus;
        $entries = array_merge($entries, $providerEntries);

        $configPath = $this->configYamlPath($options);
        $configYaml = $this->readConfigYaml($configPath);
        $sectionStatus['config_yaml'] = $configYaml['section_status'];
        $entries = array_merge($entries, $configYaml['entries']);

        return $this->finalizeManifest([
            'schema_version' => self::SCHEMA_VERSION,
            'manifest_version' => 0,
            'hermes_version' => $version,
            'probed_at' => $probedAt,
            'probe_status' => $this->probeStatus($sectionStatus),
            'binary_present' => true,
            'section_status' => $this->normalizeSectionStatus($sectionStatus),
            'entries' => $this->normalizeEntries($entries),
        ]);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function resolveBinary(array $options): string
    {
        $candidate = $options['binary'] ?? null;
        if (is_string($candidate) && trim($candidate) !== '') {
            return trim($candidate);
        }

        return (string) config('atlas.ai.providers.hermes_cli.binary', 'hermes');
    }

    private function resolveExecutable(string $binary): ?string
    {
        $expanded = $this->expandHome($binary);

        if (str_contains($expanded, '/') || str_contains($expanded, '\\')) {
            return is_file($expanded) && is_executable($expanded)
                ? (realpath($expanded) ?: $expanded)
                : null;
        }

        $found = (new ExecutableFinder)->find($binary, null, $this->binarySearchDirs());

        return is_string($found) && $found !== '' ? $found : null;
    }

    private function hermesVersion(string $binary): ?string
    {
        $result = $this->run($binary, ['--version'], 3);
        if (! $result['ok']) {
            return null;
        }

        $first = $this->firstLine($result['text']);

        return $first === '' ? null : Str::limit($first, 200, '');
    }

    /**
     * Parse `hermes --help` -> subcommand entries from the argparse `{a,b,c,...}` choices token.
     *
     * @return array{0:array<int,array<string,mixed>>,1:string}
     */
    private function probeSubcommands(string $binary): array
    {
        $result = $this->run($binary, ['--help']);
        $status = $this->statusFromRun($result);
        if (! $result['ok']) {
            return [[], $status];
        }

        $names = $this->parseChoiceToken($result['text']);
        $entries = [];
        foreach ($names as $name) {
            $entries[] = $this->entry(
                capabilityClass: 'subcommand',
                capabilityKey: $name,
                hermesToken: null,
                supported: true,
                requiresConfig: false,
                detail: ['name' => $name],
                source: 'hermes --help',
            );
        }

        return [$entries, $status];
    }

    /**
     * Parse `hermes chat --help` -> flag entries + derived context_ref/feature entries.
     *
     * @param  array{ok:bool,exit_code:?int,text:string}  $chatHelp
     * @return array{0:array<int,array<string,mixed>>}
     */
    private function probeChatFlags(array $chatHelp): array
    {
        if (! $chatHelp['ok']) {
            return [[]];
        }

        $text = $chatHelp['text'];
        $entries = [];
        $seen = [];

        if (preg_match_all('/(?:^|\s)(--[a-z][a-z0-9-]+)/m', $text, $matches) !== false) {
            foreach ($matches[1] as $flag) {
                $flag = strtolower($flag);
                if (isset($seen[$flag])) {
                    continue;
                }
                $seen[$flag] = true;

                // --ignore-rules disables Atlas context-file injection: surface it, mark unsupported.
                $supported = $flag !== '--ignore-rules' && $flag !== '--ignore-user-config';

                $entries[] = $this->entry(
                    capabilityClass: 'flag',
                    capabilityKey: ltrim($flag, '-'),
                    hermesToken: $flag,
                    supported: $supported,
                    requiresConfig: false,
                    detail: array_filter([
                        'flag' => $flag,
                        'atlas_safe' => $supported,
                        'reason' => $supported ? null : 'disables_atlas_context_injection',
                    ], fn (mixed $value): bool => $value !== null),
                    source: 'hermes chat --help',
                );
            }
        }

        // Derive context-file synergy: Atlas wants Hermes to read its generated projections.
        if (str_contains($text, '--ignore-rules') || preg_match('/AGENTS\.md|SOUL\.md|\.cursorrules|memory|preloaded-skills/i', $text) === 1) {
            $entries[] = $this->entry(
                capabilityClass: 'context_ref',
                capabilityKey: 'context_files',
                hermesToken: null,
                supported: true,
                requiresConfig: false,
                detail: [
                    'mechanism' => 'auto_injection',
                    'files' => ['AGENTS.md', 'SOUL.md', '.cursorrules', 'memory', 'preloaded-skills'],
                    'prompt_at_syntax' => true,
                    'atlas_must_not_pass' => '--ignore-rules',
                ],
                source: 'hermes chat --help',
            );
        }

        // Derive resume/continue as a session-continuity feature.
        if (isset($seen['--resume']) || isset($seen['--continue'])) {
            $entries[] = $this->entry(
                capabilityClass: 'feature',
                capabilityKey: 'session_continuity',
                hermesToken: null,
                supported: true,
                requiresConfig: false,
                detail: [
                    'resume' => isset($seen['--resume']),
                    'continue' => isset($seen['--continue']),
                    'pass_session_id' => isset($seen['--pass-session-id']),
                ],
                source: 'hermes chat --help',
            );
        }

        if (isset($seen['--checkpoints'])) {
            $entries[] = $this->entry(
                capabilityClass: 'feature',
                capabilityKey: 'checkpoints',
                hermesToken: null,
                supported: true,
                requiresConfig: false,
                detail: ['flag' => '--checkpoints'],
                source: 'hermes chat --help',
            );
        }

        return [$entries];
    }

    /**
     * Toolsets: read the `-t/--toolsets` hint from `chat --help`, then best-effort `hermes tools`.
     *
     * @param  array{ok:bool,exit_code:?int,text:string}  $chatHelp
     * @return array{0:array<int,array<string,mixed>>,1:string}
     */
    private function probeToolsets(string $binary, array $chatHelp): array
    {
        $names = [];
        $sources = [];

        if ($chatHelp['ok']) {
            $hint = $this->extractToolsetHint($chatHelp['text']);
            if ($hint !== []) {
                $names = array_merge($names, $hint);
                $sources['hermes chat --help'] = true;
            }
        }

        // `hermes tools` may be interactive; try `--json` first, fall back to plain text, tolerate failure.
        $tools = $this->runFirst($binary, [['tools', '--json'], ['tools']]);
        $status = $chatHelp['ok'] ? 'ok' : $this->statusFromRun($chatHelp);
        if ($tools['ok']) {
            $parsed = $this->parseToolsetTokens($tools['text']);
            if ($parsed !== []) {
                $names = array_merge($names, $parsed);
                $sources['hermes tools'] = true;
            }
        }

        $names = $this->uniqueLowerList($names);
        $entries = [];
        foreach ($names as $name) {
            $entries[] = $this->entry(
                capabilityClass: 'toolset',
                capabilityKey: $name,
                hermesToken: $name,
                supported: true,
                requiresConfig: str_starts_with($name, 'mcp-'),
                detail: array_filter([
                    'name' => $name,
                    'per_server_mcp' => str_starts_with($name, 'mcp-') ?: null,
                ], fn (mixed $value): bool => $value !== null),
                source: implode('+', array_keys($sources)) ?: 'hermes chat --help',
            );
        }

        if ($names === [] && ! $chatHelp['ok']) {
            return [[], $status];
        }

        return [$entries, $names === [] ? 'absent' : $status];
    }

    /**
     * @return array{0:array<int,array<string,mixed>>,1:string}
     */
    private function probeMcpServers(string $binary): array
    {
        $result = $this->runFirst($binary, [['mcp', 'list', '--json'], ['mcp', 'list']]);
        $status = $this->statusFromRun($result);
        $entries = [];

        if ($result['ok']) {
            foreach ($this->parseListNames($result['text']) as $name) {
                $entries[] = $this->entry(
                    capabilityClass: 'mcp_server',
                    capabilityKey: $name,
                    hermesToken: null,
                    supported: true,
                    requiresConfig: true,
                    detail: [
                        'name' => $name,
                        'origin' => 'configured',
                        'toolset_alias' => 'mcp-'.$name,
                        'tool_naming' => 'mcp_'.$name.'_<tool>',
                    ],
                    source: 'hermes mcp list',
                );
            }
        }

        // Nous-approved installable catalog (informational; not yet leveraged).
        $catalog = $this->runFirst($binary, [['mcp', 'catalog', '--json'], ['mcp', 'catalog']]);
        if ($catalog['ok']) {
            $configured = array_map(static fn (array $entry): string => (string) $entry['capability_key'], $entries);
            foreach ($this->parseListNames($catalog['text']) as $name) {
                if (in_array($name, $configured, true)) {
                    continue;
                }
                $entries[] = $this->entry(
                    capabilityClass: 'mcp_server',
                    capabilityKey: $name,
                    hermesToken: null,
                    supported: false,
                    requiresConfig: true,
                    detail: [
                        'name' => $name,
                        'origin' => 'catalog',
                        'installable' => true,
                    ],
                    source: 'hermes mcp catalog',
                );
            }
        }

        if (! $result['ok'] && ! $catalog['ok']) {
            return [[], $status];
        }

        return [$entries, $result['ok'] ? 'ok' : $this->statusFromRun($catalog)];
    }

    /**
     * @return array{0:array<int,array<string,mixed>>,1:string}
     */
    private function probeSkills(string $binary): array
    {
        $result = $this->runFirst($binary, [['skills', 'list', '--json'], ['skills', 'list']]);
        $status = $this->statusFromRun($result);
        if (! $result['ok']) {
            return [[], $status];
        }

        $entries = [];
        foreach ($this->parseListNames($result['text']) as $name) {
            $entries[] = $this->entry(
                capabilityClass: 'skill',
                capabilityKey: $name,
                hermesToken: null,
                supported: true,
                requiresConfig: false,
                detail: ['name' => $name],
                source: 'hermes skills list',
            );
        }

        return [$entries, $status];
    }

    /**
     * @return array{0:array<int,array<string,mixed>>,1:string}
     */
    private function probeBundles(string $binary): array
    {
        $result = $this->runFirst($binary, [['bundles', 'list', '--json'], ['bundles', 'list']]);
        $status = $this->statusFromRun($result);
        if (! $result['ok']) {
            return [[], $status];
        }

        $entries = [];
        foreach ($this->parseListNames($result['text']) as $name) {
            $entries[] = $this->entry(
                capabilityClass: 'bundle',
                capabilityKey: $name,
                hermesToken: null,
                supported: true,
                requiresConfig: false,
                detail: ['name' => $name],
                source: 'hermes bundles list',
            );
        }

        return [$entries, $status];
    }

    /**
     * Installed Hermes plugins (`hermes plugins list`). Each is surfaced as a
     * `plugin` capability candidate carrying its enabled/disabled status so the
     * Atlas Capability Registry can govern which plugins a mission may rely on —
     * closing the previously-absent plugins coverage. A not-enabled plugin is
     * flagged requires_config (it needs `hermes plugins enable` first).
     *
     * @return array{0:array<int,array<string,mixed>>,1:string}
     */
    private function probePlugins(string $binary): array
    {
        $result = $this->runFirst($binary, [['plugins', 'list', '--json'], ['plugins', 'list']]);
        $status = $this->statusFromRun($result);
        if (! $result['ok']) {
            return [[], $status];
        }

        $entries = [];
        $decoded = json_decode($result['text'], true);

        if (is_array($decoded) && $decoded !== [] && array_is_list($decoded)) {
            foreach ($decoded as $plugin) {
                if (! is_array($plugin)) {
                    continue;
                }
                $name = $plugin['name'] ?? null;
                if (! is_string($name) || trim($name) === '') {
                    continue;
                }
                $name = trim($name);
                $enabled = ($plugin['status'] ?? null) === 'enabled';

                $entries[] = $this->entry(
                    capabilityClass: 'plugin',
                    capabilityKey: $name,
                    hermesToken: null,
                    supported: true,
                    requiresConfig: ! $enabled,
                    detail: [
                        'name' => $name,
                        'enabled' => $enabled,
                        'status' => is_string($plugin['status'] ?? null) ? $plugin['status'] : null,
                        'version' => is_string($plugin['version'] ?? null) ? $plugin['version'] : null,
                        'source' => is_string($plugin['source'] ?? null) ? $plugin['source'] : null,
                    ],
                    source: 'hermes plugins list',
                );
            }

            return [$entries, $status];
        }

        // Non-JSON fallback: names only.
        foreach ($this->parseListNames($result['text']) as $name) {
            $entries[] = $this->entry(
                capabilityClass: 'plugin',
                capabilityKey: $name,
                hermesToken: null,
                supported: true,
                requiresConfig: false,
                detail: ['name' => $name],
                source: 'hermes plugins list',
            );
        }

        return [$entries, $status];
    }

    /**
     * @return array{0:array<int,array<string,mixed>>,1:string}
     */
    private function probeHooks(string $binary): array
    {
        $result = $this->runFirst($binary, [['hooks', 'list', '--json'], ['hooks', 'list']]);
        $status = $this->statusFromRun($result);
        if (! $result['ok']) {
            return [[], $status];
        }

        $entries = [];
        foreach ($this->parseListNames($result['text']) as $name) {
            $entries[] = $this->entry(
                capabilityClass: 'hook',
                capabilityKey: $name,
                hermesToken: null,
                supported: true,
                requiresConfig: true,
                detail: ['event' => $name],
                source: 'hermes hooks list',
            );
        }

        return [$entries, $status];
    }

    /**
     * Delegation is supported when a `delegation` toolset or `delegate_task` token is present.
     *
     * @param  array{ok:bool,exit_code:?int,text:string}  $chatHelp
     * @return array{0:array<int,array<string,mixed>>,1:string}
     */
    private function probeDelegation(string $binary, array $chatHelp): array
    {
        $haystack = $chatHelp['ok'] ? $chatHelp['text'] : '';

        $tools = $this->runFirst($binary, [['tools', '--json'], ['tools']]);
        if ($tools['ok']) {
            $haystack .= "\n".$tools['text'];
        }

        $supported = preg_match('/\bdelegat(e|ion)\b|\bdelegate_task\b|\bsubagent/i', $haystack) === 1;
        $status = $chatHelp['ok'] ? 'ok' : $this->statusFromRun($chatHelp);

        $entries = [$this->entry(
            capabilityClass: 'delegation',
            capabilityKey: 'supported',
            hermesToken: null,
            supported: $supported,
            requiresConfig: false,
            detail: [
                'mechanism' => 'toolset_or_delegate_task',
                'detected' => $supported,
            ],
            source: $tools['ok'] ? 'hermes tools' : 'hermes chat --help',
        )];

        return [$entries, $status];
    }

    /**
     * Providers from `--provider` hint in chat help (informational/governance-only).
     *
     * @param  array{ok:bool,exit_code:?int,text:string}  $chatHelp
     * @return array{0:array<int,array<string,mixed>>,1:string}
     */
    private function probeProviders(string $binary, array $chatHelp): array
    {
        $status = $chatHelp['ok'] ? 'ok' : $this->statusFromRun($chatHelp);
        if (! $chatHelp['ok']) {
            return [[], $status];
        }

        $names = $this->extractProviderHint($chatHelp['text']);
        if ($names === []) {
            return [[], 'absent'];
        }

        $entries = [];
        foreach ($names as $name) {
            $entries[] = $this->entry(
                capabilityClass: 'provider',
                capabilityKey: $name,
                hermesToken: null,
                supported: true,
                requiresConfig: true,
                detail: ['name' => $name],
                source: 'hermes chat --help',
            );
        }

        return [$entries, $status];
    }

    /**
     * Safe read of ~/.hermes/config.yaml: record ONLY top-level keys present + presence booleans.
     * Values (env/oauth/headers/secrets) are NEVER stored.
     *
     * @return array{entries:array<int,array<string,mixed>>,section_status:string}
     */
    private function readConfigYaml(?string $path): array
    {
        if ($path === null || ! is_file($path) || ! is_readable($path)) {
            return ['entries' => [], 'section_status' => 'absent'];
        }

        $raw = @file_get_contents($path);
        if (! is_string($raw) || trim($raw) === '') {
            return ['entries' => [], 'section_status' => 'absent'];
        }

        $topLevelKeys = $this->parseYamlTopLevelKeys($raw);

        $detail = [
            'present' => true,
            'keys_present' => $topLevelKeys,
            'has_mcp_servers' => in_array('mcp_servers', $topLevelKeys, true),
            'has_skills' => in_array('skills', $topLevelKeys, true),
            'has_hooks' => in_array('hooks', $topLevelKeys, true),
            'has_providers' => in_array('providers', $topLevelKeys, true),
            // Presence booleans only — secret-bearing values are NEVER read into the manifest.
            'has_env' => $this->yamlMentionsKey($raw, 'env'),
            'has_oauth' => $this->yamlMentionsKey($raw, 'oauth'),
            'has_headers' => $this->yamlMentionsKey($raw, 'headers'),
            'has_auth' => $this->yamlMentionsKey($raw, 'auth'),
        ];

        $entry = $this->entry(
            capabilityClass: 'feature',
            capabilityKey: 'config_yaml',
            hermesToken: null,
            supported: true,
            requiresConfig: true,
            detail: $detail,
            source: 'config.yaml',
        );

        return ['entries' => [$entry], 'section_status' => 'ok'];
    }

    /**
     * Thin, bounded, redacted Process wrapper. Returns {ok,exit_code,text}; never throws.
     *
     * @param  array<int,string>  $args
     * @return array{ok:bool,exit_code:?int,text:string}
     */
    private function run(string $binary, array $args, int $timeout = 15): array
    {
        try {
            $process = new Process([$binary, ...$args], $this->workingDirectory(), $this->processEnv());
            $process->setTimeout($timeout > 0 ? $timeout : null);
            $process->run();

            $stdout = AtlasSecurity::redactString($process->getOutput());
            $stderr = AtlasSecurity::redactString($process->getErrorOutput());

            return [
                'ok' => $process->isSuccessful(),
                'exit_code' => $process->getExitCode(),
                'text' => trim($stdout) !== '' ? $stdout : $stderr,
            ];
        } catch (Throwable) {
            return ['ok' => false, 'exit_code' => null, 'text' => ''];
        }
    }

    /**
     * Try each arg variant in order (e.g. `<sub> --json` then text); return the first that succeeds.
     *
     * @param  array<int,array<int,string>>  $variants
     * @return array{ok:bool,exit_code:?int,text:string}
     */
    private function runFirst(string $binary, array $variants, int $timeout = 15): array
    {
        $last = ['ok' => false, 'exit_code' => null, 'text' => ''];
        foreach ($variants as $args) {
            $result = $this->run($binary, $args, $timeout);
            if ($result['ok']) {
                return $result;
            }
            $last = $result;
        }

        return $last;
    }

    /**
     * @param  array{ok:bool,exit_code:?int,text:string}  $result
     */
    private function statusFromRun(array $result): string
    {
        if ($result['ok']) {
            return 'ok';
        }

        return $result['exit_code'] === null ? 'timeout' : 'help_failed';
    }

    /**
     * @param  array<string,string>  $sectionStatus
     */
    private function probeStatus(array $sectionStatus): string
    {
        if (in_array('ok', $sectionStatus, true)) {
            foreach ($sectionStatus as $status) {
                if (in_array($status, ['help_failed', 'timeout'], true)) {
                    return 'degraded';
                }
            }

            return 'ok';
        }

        // No section succeeded but the binary resolved -> degraded, not offline.
        return 'degraded';
    }

    /**
     * @return array<string,string>
     */
    private function offlineSectionStatus(): array
    {
        return $this->normalizeSectionStatus([]);
    }

    /**
     * @param  array<string,string>  $sectionStatus
     * @return array<string,string>
     */
    private function normalizeSectionStatus(array $sectionStatus): array
    {
        $sections = ['chat', 'subcommands', 'toolsets', 'mcp', 'skills', 'bundles', 'hooks', 'delegation', 'providers', 'config_yaml'];
        $normalized = [];
        foreach ($sections as $section) {
            $value = $sectionStatus[$section] ?? 'absent';
            $normalized[$section] = in_array($value, ['ok', 'help_failed', 'timeout', 'absent'], true) ? $value : 'absent';
        }

        return $normalized;
    }

    /**
     * Build a canonical manifest entry honoring the pinned contract.
     *
     * @param  array<string,mixed>  $detail
     * @return array<string,mixed>
     */
    private function entry(
        string $capabilityClass,
        string $capabilityKey,
        ?string $hermesToken,
        bool $supported,
        bool $requiresConfig,
        array $detail,
        string $source,
    ): array {
        $idPrefix = $this->idPrefix($capabilityClass);

        return [
            'id' => $idPrefix.':'.$capabilityKey,
            'capability_class' => $capabilityClass,
            'capability_key' => $capabilityKey,
            'hermes_token' => $hermesToken,
            'supported' => $supported,
            'requires_config' => $requiresConfig,
            'detail' => $detail,
            'source' => $source,
            'first_seen' => null,
        ];
    }

    private function idPrefix(string $capabilityClass): string
    {
        return match ($capabilityClass) {
            'mcp_server' => 'mcp_server',
            'context_ref' => 'context_ref',
            default => $capabilityClass,
        };
    }

    /**
     * @param  array<int,array<string,mixed>>  $entries
     * @return array<int,array<string,mixed>>
     */
    private function normalizeEntries(array $entries): array
    {
        $byId = [];
        foreach ($entries as $entry) {
            $byId[$entry['id']] = $entry;
        }

        $entries = array_values($byId);
        usort($entries, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

        return $entries;
    }

    /**
     * Seal the manifest with a deterministic `manifest_hash` (hashed over the manifest
     * sans the hash key itself), using the shared receipt hashing primitive.
     *
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    private function finalizeManifest(array $manifest): array
    {
        $manifest['manifest_hash'] = $this->hashValue($manifest);

        return $manifest;
    }

    /**
     * Parse an argparse choices token `{a,b,c,...}` into a flat name list.
     *
     * @return array<int,string>
     */
    private function parseChoiceToken(string $text): array
    {
        if (preg_match('/\{([a-z0-9_,-]+)\}/i', $text, $match) !== 1) {
            return [];
        }

        $names = preg_split('/\s*,\s*/', $match[1]) ?: [];

        return $this->uniqueLowerList($names);
    }

    /**
     * @return array<int,string>
     */
    private function extractToolsetHint(string $text): array
    {
        // Only consider the SAME line as `--toolsets`; never cross a newline (the next help line
        // is unrelated, e.g. another flag). The enumeration is either `{a,b,c}` or `e.g. a, b, c`.
        if (preg_match('/--toolsets\b[^\n]*/i', $text, $match) !== 1) {
            return [];
        }

        $line = $match[0];
        $names = [];

        if (preg_match('/\{([a-z0-9_,-]+)\}/i', $line, $brace) === 1) {
            $names = array_merge($names, preg_split('/\s*,\s*/', $brace[1]) ?: []);
        }

        // Capture only bare toolset tokens after "e.g." — stop at the first non-name character
        // so trailing prose ("comma list", flag descriptions) cannot leak into a token.
        if (preg_match('/e\.g\.?\s*:?\s*((?:[a-z][a-z0-9_-]*)(?:\s*,\s*[a-z][a-z0-9_-]*)*)/i', $line, $eg) === 1) {
            $names = array_merge($names, preg_split('/\s*,\s*/', trim($eg[1])) ?: []);
        }

        return $this->uniqueLowerList($names);
    }

    /**
     * @return array<int,string>
     */
    private function extractProviderHint(string $text): array
    {
        if (preg_match('/--provider[^\n]*\n?(?:[^\n]*\n){0,2}/i', $text, $match) !== 1) {
            return [];
        }

        $segment = $match[0];
        if (preg_match('/\{([a-z0-9_,-]+)\}/i', $segment, $brace) === 1) {
            return $this->uniqueLowerList(preg_split('/\s*,\s*/', $brace[1]) ?: []);
        }

        return [];
    }

    /**
     * @return array<int,string>
     */
    private function parseToolsetTokens(string $text): array
    {
        $decoded = $this->decodeJsonList($text);
        if ($decoded !== null) {
            return $this->uniqueLowerList($decoded);
        }

        return $this->parseListNames($text);
    }

    /**
     * Parse a `<sub> list` text/JSON block into a flat list of names.
     *
     * @return array<int,string>
     */
    private function parseListNames(string $text): array
    {
        $decoded = $this->decodeJsonList($text);
        if ($decoded !== null) {
            return $this->uniqueLowerList($decoded);
        }

        $names = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Strip common list bullets/prefixes: "- name", "* name", "1. name", "• name".
            $line = preg_replace('/^\s*(?:[-*•]|\d+[.)])\s+/u', '', $line) ?? $line;
            // Take the first token before whitespace/colon/parenthesis as the name.
            if (preg_match('/^([A-Za-z0-9_.:-]+)/', $line, $match) !== 1) {
                continue;
            }

            $candidate = strtolower($match[1]);
            if ($this->looksLikeHeading($candidate)) {
                continue;
            }

            $names[] = $candidate;
        }

        return $this->uniqueLowerList($names);
    }

    private function looksLikeHeading(string $candidate): bool
    {
        return in_array($candidate, [
            'name', 'names', 'server', 'servers', 'skill', 'skills', 'bundle', 'bundles',
            'hook', 'hooks', 'event', 'events', 'tool', 'tools', 'status', 'enabled',
            'configured', 'available', 'installed', 'no', 'none',
        ], true);
    }

    /**
     * @return array<int,string>|null
     */
    private function decodeJsonList(string $text): ?array
    {
        $trimmed = trim($text);
        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
            return null;
        }

        try {
            $decoded = json_decode($trimmed, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        $names = [];
        if (array_is_list($decoded)) {
            foreach ($decoded as $item) {
                if (is_string($item) || is_numeric($item)) {
                    $names[] = (string) $item;
                } elseif (is_array($item) && isset($item['name']) && is_string($item['name'])) {
                    $names[] = $item['name'];
                }
            }
        } else {
            foreach (['servers', 'skills', 'bundles', 'hooks', 'tools', 'toolsets', 'items'] as $key) {
                if (isset($decoded[$key]) && is_array($decoded[$key])) {
                    foreach ($decoded[$key] as $itemKey => $item) {
                        if (is_string($item) || is_numeric($item)) {
                            $names[] = (string) $item;
                        } elseif (is_array($item) && isset($item['name']) && is_string($item['name'])) {
                            $names[] = $item['name'];
                        } elseif (is_string($itemKey)) {
                            $names[] = $itemKey;
                        }
                    }

                    return $names;
                }
            }

            // Map of name => config (e.g. mcp_servers).
            foreach (array_keys($decoded) as $key) {
                if (is_string($key)) {
                    $names[] = $key;
                }
            }
        }

        return $names;
    }

    /**
     * Parse top-level YAML keys without loading values (avoids pulling secrets into memory).
     *
     * @return array<int,string>
     */
    private function parseYamlTopLevelKeys(string $raw): array
    {
        $keys = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            // Top-level key: starts at column 0, "key:" form, not a comment/list item.
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_-]*)\s*:/', $line, $match) === 1) {
                $keys[] = $match[1];
            }
        }

        return array_values(array_unique($keys));
    }

    private function yamlMentionsKey(string $raw, string $key): bool
    {
        return preg_match('/(^|\s)'.preg_quote($key, '/').'\s*:/mi', $raw) === 1;
    }

    private function configYamlPath(array $options): ?string
    {
        $candidate = $options['config_path'] ?? config('atlas.ai.providers.hermes_cli.config_path');
        if (is_string($candidate) && trim($candidate) !== '') {
            $expanded = $this->expandHome(trim($candidate));

            return $expanded;
        }

        $home = $this->home();
        if ($home === '') {
            return null;
        }

        return $home.'/.hermes/config.yaml';
    }

    /**
     * @return array<int,string>
     */
    private function uniqueLowerList(array $names): array
    {
        return collect($names)
            ->map(fn (mixed $name): string => strtolower(trim((string) $name)))
            ->filter(fn (string $name): bool => $name !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function firstLine(string $text): string
    {
        $lines = preg_split('/\R/', trim($text)) ?: [];

        return trim($lines[0] ?? '');
    }

    /**
     * @return array<int,string>
     */
    private function binarySearchDirs(): array
    {
        $home = $this->home();

        return array_values(array_filter([
            '/opt/homebrew/bin',
            '/usr/local/bin',
            '/usr/bin',
            '/bin',
            $home !== '' ? $home.'/.local/bin' : null,
        ]));
    }

    /**
     * @return array<string,string|false>
     */
    private function processEnv(): array
    {
        return AtlasSecurity::processEnv([
            'HOME' => $this->home(),
            'PYTHONDONTWRITEBYTECODE' => '1',
        ], 'provider');
    }

    private function workingDirectory(): string
    {
        $workdir = config('atlas.ai.workdir');

        return is_string($workdir) && $workdir !== '' ? $workdir : sys_get_temp_dir();
    }

    private function home(): string
    {
        return (string) ($_SERVER['HOME'] ?? getenv('HOME') ?: '');
    }

    private function expandHome(string $path): string
    {
        if (! str_starts_with($path, '~/')) {
            return $path;
        }

        $home = $this->home();

        return $home === '' ? $path : $home.substr($path, 1);
    }
}
