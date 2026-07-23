<?php

namespace App\Services\Ai\OpenBrainMcp;

use App\Models\AiTelemetryEvent;
use App\Services\Ai\AtlasOpenBrainContextPackService;
use App\Services\Ai\AtlasOpenBrainMcpService;
use App\Services\Ai\Mcp\AtlasMcpTierService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * GOD-DEBULK D3: runtime-identity / capability / surface-review tool family
 * relocated verbatim from AtlasOpenBrainMcpService so the ~1089-LOC public tools()
 * schema + dispatch stay on the façade under 2000 LOC. These methods read the
 * façade's public contract surface (tools()/consts), so the façade threads itself
 * in as `$facade`; bodies are otherwise byte-identical to the pre-split service
 * (self::CONST -> AtlasOpenBrainMcpService::CONST, $this->tools() -> $facade->tools()).
 * This family carries no architecture scanner source-pins.
 */
class RuntimeSurfaceTools
{
    private string $processStartedAt;

    public function __construct()
    {
        $this->processStartedAt = Carbon::now()->toIso8601String();
    }

    /**
     * @return array<string,mixed>
     */
    public function capabilities(AtlasOpenBrainMcpService $facade): array
    {
        return [
            'ok' => true,
            'tool' => 'atlas_capabilities',
            'protocol_version' => AtlasOpenBrainMcpService::PROTOCOL_VERSION,
            'server' => [
                'name' => 'atlas-open-brain',
                'version' => AtlasOpenBrainMcpService::SERVER_VERSION,
            ],
            'runtime' => $this->runtimeProfile($facade),
            'surface_contract' => $this->surfaceContract($facade),
            'transport_contract' => $this->transportContract(),
            'progressive_disclosure' => $this->progressiveDisclosureCapabilities(),
            'tools' => $facade->tools(),
            'transport' => 'stdio',
            'remote_capable' => false,
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function toolSearch(array $arguments, AtlasOpenBrainMcpService $facade): array
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query === '') {
            return ['ok' => false, 'tool' => 'atlas_tool_search', 'error' => 'query_required'];
        }

        $limit = min(25, max(1, (int) ($arguments['limit'] ?? 10)));
        $tokens = array_values(array_filter(
            preg_split('/[^a-z0-9_]+/i', mb_strtolower($query)) ?: [],
            static fn (string $token): bool => $token !== '',
        ));

        $ranked = [];
        foreach ($facade->tools() as $tool) {
            $name = mb_strtolower((string) ($tool['name'] ?? ''));
            $title = mb_strtolower((string) ($tool['title'] ?? ''));
            $description = mb_strtolower((string) ($tool['description'] ?? ''));
            $score = str_contains($name, mb_strtolower($query)) ? 20 : 0;
            $score += str_contains($title, mb_strtolower($query)) ? 10 : 0;
            $score += str_contains($description, mb_strtolower($query)) ? 5 : 0;

            foreach ($tokens as $token) {
                if (str_contains($name, $token)) {
                    $score += 6;
                } elseif (str_contains($title, $token)) {
                    $score += 3;
                } elseif (str_contains($description, $token)) {
                    $score += 1;
                }
            }

            if ($score > 0) {
                $ranked[] = ['score' => $score, 'name' => (string) ($tool['name'] ?? ''), 'tool' => $tool];
            }
        }

        usort($ranked, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp($a['name'], $b['name']));
        $tools = array_map(static fn (array $row): array => $row['tool'], array_slice($ranked, 0, $limit));

        return [
            'ok' => true,
            'tool' => 'atlas_tool_search',
            'query' => $query,
            'count' => count($tools),
            'tools' => $tools,
            'compatibility' => [
                'all_legacy_tools_remain_callable_by_name' => true,
                'full_inventory_tool' => 'atlas_capabilities',
            ],
        ];
    }

    /**
     * Small stable interface advertised to new clients. The complete tool list
     * remains available as compatibility adapters and can only be removed after
     * an observed deprecation window.
     *
     * @return array<string,mixed>
     */
    private function surfaceContract(AtlasOpenBrainMcpService $facade): array
    {
        $allNames = $this->toolNames($facade);
        $primary = array_values(array_filter(
            AtlasOpenBrainMcpService::PRIMARY_TOOLS,
            static fn (string $tool): bool => in_array($tool, $allNames, true),
        ));

        return [
            'schema_version' => 'atlas.open_brain.surface_contract.v1.1',
            'status' => 'stable',
            'primary_tool_count' => count($primary),
            'primary_tools' => $primary,
            'compatibility_tool_count' => max(0, count($allNames) - count($primary)),
            'compatibility_aliases' => AtlasOpenBrainMcpService::COMPATIBILITY_ALIASES,
            'tool_contracts' => collect($facade->tools())
                ->mapWithKeys(static fn (array $tool): array => [
                    (string) ($tool['name'] ?? '') => data_get($tool, 'annotations.atlasContract', []),
                ])
                ->all(),
            'deprecation_policy' => [
                'minimum_observation_days' => 90,
                'usage_evidence_required' => true,
                'breaking_removal_requires_major_version' => true,
                'current_action' => 'prefer_primary_keep_compatibility',
            ],
        ];
    }

    /**
     * Honest transport limits. PHP stdio dispatch is sequential, therefore an
     * in-flight tool cannot consume a later cancellation notification; clients
     * cancel by terminating/restarting the process. Claiming otherwise would be
     * a false capability.
     *
     * @return array<string,mixed>
     */
    private function transportContract(): array
    {
        return [
            'schema_version' => 'atlas.open_brain.transport_contract.v1',
            'schema_compatibility' => 'additive_minor_breaking_major',
            'quotas' => [
                'write_request_chars' => (int) config('atlas.aobg.write_back.max_request_chars', 2000),
                'write_files' => (int) config('atlas.aobg.write_back.max_files', 50),
                'write_memory_refs' => (int) config('atlas.aobg.write_back.max_memory_refs', 25),
                'context_budget_chars' => (int) config('atlas.aobg.budget_chars', 6000),
                'calls_per_window' => (int) config('atlas.aobg.mcp_quota.calls_per_window', 120),
                'window_seconds' => (int) config('atlas.aobg.mcp_quota.window_seconds', 60),
                'rate_limit_mode' => 'soft_fail_open',
            ],
            'timeouts' => [
                'file_context_soft_ms' => (int) config('atlas.aobg.file_context.soft_budget_ms', 1500),
                'client_hard_timeout_required' => true,
            ],
            'cancellation' => [
                'supported' => false,
                'reason' => 'sequential_stdio',
                'client_action' => 'terminate_and_restart_process',
            ],
            'diagnostics_tool' => 'atlas_mcp_self_check',
            'provider_safe' => true,
        ];
    }

    /**
     * Obra 7 / OB-03: Absorcao 4 phase-1 progressive disclosure manifest for MCP capabilities.
     *
     * @return array<string,mixed>
     */
    private function progressiveDisclosureCapabilities(): array
    {
        if (! (bool) config('atlas.aobg.progressive_disclosure_enabled', true)) {
            return [
                'enabled' => false,
                'phase' => 1,
            ];
        }

        try {
            $tier = app(AtlasMcpTierService::class);

            return [
                'enabled' => true,
                'phase' => 1,
                'schema_version' => 'atlas.mcp.tier.v1',
                'workflow' => 'search_brief → timeline → get_full',
                'manifest' => $tier->tierManifest(),
                'savings_estimate' => $tier->estimateSavings(5),
            ];
        } catch (Throwable) {
            return [
                'enabled' => true,
                'phase' => 1,
                'status' => 'unavailable',
            ];
        }
    }

    /**
     * Provider-safe runtime identity for stale-session detection.
     *
     * @return array<string,mixed>
     */
    public function runtimeProfile(AtlasOpenBrainMcpService $facade): array
    {
        $toolNames = $this->toolNames($facade);
        $features = AtlasOpenBrainMcpService::RUNTIME_FEATURE_FLAGS;
        $sourceProbe = $this->runtimeSourceProbe();

        return [
            'schema_version' => AtlasOpenBrainMcpService::RUNTIME_SCHEMA,
            'server_name' => 'atlas-open-brain',
            'server_version' => AtlasOpenBrainMcpService::SERVER_VERSION,
            'protocol_version' => AtlasOpenBrainMcpService::PROTOCOL_VERSION,
            'transport' => 'stdio',
            'process_started_at' => $this->processStartedAt,
            'feature_flags' => $features,
            'tool_count' => count($toolNames),
            'tool_names_hash' => $this->sha256($toolNames),
            'runtime_fingerprint' => $this->runtimeFingerprint($features, $toolNames),
            'source_probe' => $sourceProbe,
            'fresh_cli_probe' => [
                'command' => '/opt/homebrew/bin/php',
                'args' => [
                    'artisan',
                    'atlas:open-brain:mcp',
                    '--once={"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"atlas_mcp_self_check","arguments":{}}}',
                ],
            ],
            'restart_policy' => [
                'restart_required_when_feature_missing' => true,
                'restart_required_when_fingerprint_differs_from_fresh_cli' => true,
                'fallback_when_native_tool_unavailable' => 'run /opt/homebrew/bin/php artisan atlas:open-brain:mcp --once from atlas-server',
                'describe_command' => 'bin/atlas open-brain mcp --describe --json',
                'cli_context_fallback' => 'php artisan atlas:context-pack "<task>" --workspace="<path>" --json',
            ],
            'provider_safe' => true,
            'raw_prompt_exposed' => false,
            'raw_conversation_exposed' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function mcpSelfCheck(array $arguments, AtlasOpenBrainMcpService $facade): array
    {
        $runtime = $this->runtimeProfile($facade);
        $sourceProbe = (array) ($runtime['source_probe'] ?? []);
        $toolNames = $this->toolNames($facade);
        $featureFlags = (array) ($runtime['feature_flags'] ?? []);
        $expectedFingerprint = $this->string($arguments['expected_fingerprint'] ?? null);
        $expectedFeatures = $this->stringList($arguments['expected_feature_flags'] ?? []);
        $expectedTools = $this->stringList($arguments['expected_tool_names'] ?? []);

        $missingFeatures = array_values(array_diff($expectedFeatures, $featureFlags));
        $missingTools = array_values(array_diff($expectedTools, $toolNames));
        $fingerprintMatches = $expectedFingerprint === null
            || hash_equals((string) ($runtime['runtime_fingerprint'] ?? ''), $expectedFingerprint);
        $sourceMatches = ($sourceProbe['status'] ?? null) !== 'stale_source_mismatch';

        $status = ($missingFeatures === [] && $missingTools === [] && $fingerprintMatches && $sourceMatches)
            ? 'ready'
            : 'stale_or_incomplete';

        return [
            'ok' => true,
            'tool' => 'atlas_mcp_self_check',
            'status' => $status,
            'runtime' => $runtime,
            'checks' => [
                'expected_fingerprint_provided' => $expectedFingerprint !== null,
                'fingerprint_matches' => $fingerprintMatches,
                'expected_feature_count' => count($expectedFeatures),
                'missing_feature_flags' => $missingFeatures,
                'expected_tool_count' => count($expectedTools),
                'missing_tool_names' => $missingTools,
                'source_probe_status' => $sourceProbe['status'] ?? 'unknown',
                'source_matches_loaded_runtime' => $sourceMatches,
            ],
            'next_actions' => $status === 'ready'
                ? []
                : [
                    'Restart the provider MCP client/session so tools and payload schemas are re-registered.',
                    'Use the CLI fresh probe while the native MCP session is stale.',
                ],
            'writes' => false,
            'provider_safe' => true,
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * Compare the runtime constants loaded in this PHP process with the current
     * source file on disk. This catches long-lived MCP clients that keep serving
     * old schemas after the repo has already moved.
     *
     * @return array<string,mixed>
     */
    public function runtimeSourceProbe(): array
    {
        $components = [
            'mcp' => $this->runtimeSourceProbeFor(AtlasOpenBrainMcpService::class, [
                'version_key' => 'server_version',
                'version_constant' => 'SERVER_VERSION',
                'loaded_version' => AtlasOpenBrainMcpService::SERVER_VERSION,
                'loaded_feature_flags' => AtlasOpenBrainMcpService::RUNTIME_FEATURE_FLAGS,
            ]),
            'context_pack' => $this->runtimeSourceProbeFor(AtlasOpenBrainContextPackService::class, [
                'version_key' => 'runtime_version',
                'version_constant' => 'RUNTIME_VERSION',
                'loaded_version' => AtlasOpenBrainContextPackService::RUNTIME_VERSION,
                'loaded_feature_flags' => AtlasOpenBrainContextPackService::RUNTIME_FEATURE_FLAGS,
            ]),
        ];

        $stale = collect($components)
            ->contains(fn (array $component): bool => ($component['status'] ?? null) === 'stale_source_mismatch');
        $missing = collect($components)
            ->flatMap(fn (array $component): array => (array) ($component['missing_loaded_feature_flags'] ?? []))
            ->values()
            ->all();

        return [
            'status' => $stale ? 'stale_source_mismatch' : 'current',
            'loaded_server_version' => AtlasOpenBrainMcpService::SERVER_VERSION,
            'source_server_version' => data_get($components, 'mcp.source_server_version'),
            'missing_loaded_feature_flags' => $missing,
            'components' => $components,
            'action' => $stale ? 'restart_provider_mcp_client_or_use_cli_fallback' : null,
            'provider_safe' => true,
        ];
    }

    /**
     * @param  class-string  $class
     * @param  array{version_key:string,version_constant:string,loaded_version:string,loaded_feature_flags:array<int,string>}  $loaded
     * @return array<string,mixed>
     */
    private function runtimeSourceProbeFor(string $class, array $loaded): array
    {
        $versionKey = $loaded['version_key'];

        $file = (new \ReflectionClass($class))->getFileName();
        if (! is_string($file) || ! is_file($file) || ! is_readable($file)) {
            return [
                'status' => 'source_unavailable',
                'component' => $class,
                'loaded_'.$versionKey => $loaded['loaded_version'],
                'source_file_hash' => null,
                'provider_safe' => true,
            ];
        }

        $source = file_get_contents($file);
        if (! is_string($source) || $source === '') {
            return [
                'status' => 'source_unavailable',
                'component' => $class,
                'loaded_'.$versionKey => $loaded['loaded_version'],
                'source_file_hash' => null,
                'provider_safe' => true,
            ];
        }

        $sourceVersion = null;
        $constant = preg_quote($loaded['version_constant'], '/');
        if (preg_match("/public const {$constant} = '([^']+)'/", $source, $match)) {
            $sourceVersion = $match[1];
        }

        $missingLoadedFeatures = [];
        if (preg_match('/public const RUNTIME_FEATURE_FLAGS = \\[(.*?)\\];/s', $source, $match)) {
            preg_match_all("/'([^']+)'/", $match[1], $featureMatches);
            $sourceFeatures = array_values(array_unique($featureMatches[1] ?? []));
            $missingLoadedFeatures = array_values(array_diff($sourceFeatures, $loaded['loaded_feature_flags']));
        }

        $versionMatches = $sourceVersion === null || $sourceVersion === $loaded['loaded_version'];
        $status = ($versionMatches && $missingLoadedFeatures === [])
            ? 'current'
            : 'stale_source_mismatch';

        return [
            'status' => $status,
            'component' => $class,
            'loaded_'.$versionKey => $loaded['loaded_version'],
            'source_'.$versionKey => $sourceVersion,
            'missing_loaded_feature_flags' => $missingLoadedFeatures,
            'source_file_hash' => hash('sha256', $source),
            'action' => $status === 'current' ? null : 'restart_provider_mcp_client_or_use_cli_fallback',
            'provider_safe' => true,
        ];
    }

    /**
     * @return array{available:bool,window_started_at:?string,tools_by_name:array<string,array<string,mixed>>}
     */
    private function surfaceReviewTelemetry(): array
    {
        if (! DatabaseTableAvailability::has('ai_telemetry_events')) {
            return [
                'available' => false,
                'window_started_at' => null,
                'tools_by_name' => [],
            ];
        }

        $tools = [];
        $windowStartedAt = null;
        AiTelemetryEvent::query()
            ->where('event_name', AtlasOpenBrainMcpService::MCP_TOOL_USAGE_EVENT_NAME)
            ->orderBy('received_at')
            ->get()
            ->each(function (AiTelemetryEvent $event) use (&$tools, &$windowStartedAt): void {
                $metadata = is_array($event->metadata) ? $event->metadata : [];
                $toolName = trim((string) ($metadata['tool_name'] ?? ''));
                if ($toolName === '') {
                    return;
                }

                $seenAt = $event->received_at?->toIso8601String()
                    ?? $event->created_at?->toIso8601String()
                    ?? Carbon::now()->toIso8601String();
                $windowStartedAt ??= $seenAt;

                if (! isset($tools[$toolName])) {
                    $tools[$toolName] = [
                        'tool_name' => $toolName,
                        'usage_count' => 0,
                        'first_seen_at' => $seenAt,
                        'last_seen_at' => $seenAt,
                    ];
                }

                $tools[$toolName]['usage_count']++;
                $tools[$toolName]['last_seen_at'] = $seenAt;
            });

        ksort($tools);

        return [
            'available' => true,
            'window_started_at' => $windowStartedAt,
            'tools_by_name' => $tools,
        ];
    }

    private function surfaceReviewObservationDays(mixed $windowStartedAt): ?int
    {
        if (! is_string($windowStartedAt) || trim($windowStartedAt) === '') {
            return null;
        }

        try {
            return max(0, (int) floor(Carbon::parse($windowStartedAt)->diffInDays(Carbon::now())));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<int,string>
     */
    private function toolNames(AtlasOpenBrainMcpService $facade): array
    {
        return array_values(array_map(
            static fn (array $tool): string => (string) ($tool['name'] ?? ''),
            $facade->tools(),
        ));
    }

    /**
     * @param  array<int,string>  $features
     * @param  array<int,string>  $toolNames
     */
    private function runtimeFingerprint(array $features, array $toolNames): string
    {
        sort($features);
        sort($toolNames);

        return $this->sha256([
            'schema_version' => AtlasOpenBrainMcpService::RUNTIME_SCHEMA,
            'server_version' => AtlasOpenBrainMcpService::SERVER_VERSION,
            'protocol_version' => AtlasOpenBrainMcpService::PROTOCOL_VERSION,
            'feature_flags' => $features,
            'tool_names' => $toolNames,
        ]);
    }

    private function sha256(mixed $payload): string
    {
        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * OPE-07 — read-only surface review. It never removes tools; it emits the
     * evidence-backed verdict a future deprecation slice may consume.
     *
     * @return array<string,mixed>
     */
    public function surfaceReview(AtlasOpenBrainMcpService $facade): array
    {
        $toolNames = $this->toolNames($facade);
        $contract = $this->surfaceContract($facade);
        $policy = (array) ($contract['deprecation_policy'] ?? []);
        $minimumDays = max(1, (int) ($policy['minimum_observation_days'] ?? 90));
        $telemetry = $this->surfaceReviewTelemetry();
        $usageByTool = (array) ($telemetry['tools_by_name'] ?? []);
        $primarySet = array_fill_keys(AtlasOpenBrainMcpService::PRIMARY_TOOLS, true);
        $tools = [];
        $toolsByName = [];

        foreach ($toolNames as $toolName) {
            $usage = (array) ($usageByTool[$toolName] ?? []);
            $usageCount = (int) ($usage['usage_count'] ?? 0);
            $windowStartedAt = $usage['first_seen_at'] ?? $telemetry['window_started_at'] ?? null;
            $observationDays = $this->surfaceReviewObservationDays($windowStartedAt);
            $isPrimary = isset($primarySet[$toolName]);
            $verdict = match (true) {
                $isPrimary => 'keep_primary',
                $usageCount > 0 => 'keep_used',
                $observationDays === null || $observationDays < $minimumDays => 'insufficient_window',
                default => 'deprecation_candidate',
            };

            $row = [
                'tool_name' => $toolName,
                'primary' => $isPrimary,
                'usage_count' => $usageCount,
                'window_started_at' => $windowStartedAt,
                'observation_days' => $observationDays,
                'verdict' => $verdict,
                'removal_planned' => false,
            ];
            $tools[] = $row;
            $toolsByName[$toolName] = $row;
        }

        return [
            'schema_version' => AtlasOpenBrainMcpService::SURFACE_REVIEW_SCHEMA,
            'generated_at' => Carbon::now()->toIso8601String(),
            'surface_contract' => $contract,
            'primary_tools' => AtlasOpenBrainMcpService::PRIMARY_TOOLS,
            'tool_count' => count($tools),
            'telemetry' => [
                'available' => (bool) ($telemetry['available'] ?? false),
                'event_name' => AtlasOpenBrainMcpService::MCP_TOOL_USAGE_EVENT_NAME,
                'window_started_at' => $telemetry['window_started_at'] ?? null,
            ],
            'policy' => array_merge($policy, [
                'minimum_observation_days' => $minimumDays,
                'current_action' => 'zero_removals',
                'zero_removals' => true,
            ]),
            'tools' => $tools,
            'tools_by_name' => $toolsByName,
            'claims' => [
                'read_only' => true,
                'removed_tools' => 0,
                'coverage_total_tools' => count($toolNames),
                'coverage_reviewed_tools' => count($tools),
            ],
        ];
    }

    // ponytail: string/stringList copied verbatim from the façade (which keeps its own
    // pinned copies) — matches the existing per-Tools-class primitive convention.

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $item): ?string => $this->string($item),
            $values,
        ))));
    }
}
