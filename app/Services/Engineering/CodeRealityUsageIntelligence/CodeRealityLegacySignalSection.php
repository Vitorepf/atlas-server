<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeRealityUsageIntelligence;

final class CodeRealityLegacySignalSection
{
    public function __construct(
        private readonly CodeRealityPrimitives $primitives,
        private readonly CodeRealityRouteAliasSection $routeAlias,
    ) {}


    /**
     * @return array<string,mixed>
     */
    public function codeInventory(): array
    {
        $classes = [];
        $commands = [];
        $routes = [];
        $namedRoutes = [];
        $routeActions = [];
        $legacySignals = [];
        $aiServiceRecords = [];
        foreach ($this->primitives->allFiles(['app', 'routes', 'config', 'database']) as $file) {
            $path = $this->primitives->relativePath($file->getPathname());
            if (! $this->primitives->isTextFile($file->getPathname())) {
                continue;
            }

            $content = $this->primitives->readSmallFile($file->getPathname());
            if ($content === null) {
                continue;
            }

            if (str_ends_with($path, '.php')) {
                foreach ($this->primitives->declaredPhpTypeNames($content) as $name) {
                    $classes[] = ['name' => $name, 'path' => $path];
                }
            }

            if (preg_match('/protected\s+\$signature\s*=\s*[\'"]([^\'"]+)/', $content, $signatureMatch)) {
                $signature = trim((string) $signatureMatch[1]);
                $commands[] = [
                    'signature' => preg_split('/\s+/', $signature)[0] ?? $signature,
                    'full_signature' => $signature,
                    'path' => $path,
                ];
            }

            if (str_starts_with($path, 'routes/')) {
                $routeInventory = $this->routeAlias->routeInventoryForContent($content, $path);
                $routes = array_merge($routes, $routeInventory['routes']);
                $namedRoutes = array_merge($namedRoutes, $routeInventory['named_routes']);
                $routeActions = array_merge($routeActions, $routeInventory['route_actions']);
            }

            if (str_starts_with($path, 'app/Services/Ai/')) {
                $aiServiceRecords[] = [
                    'path' => $path,
                    'subarea' => $this->aiServiceSubarea($path),
                    'topics' => $this->aiServiceTopics($content),
                ];
            }

            foreach ($this->legacySignalContexts($content) as $context) {
                $legacySignals[] = [
                    'path' => $path,
                    'signal' => $context['signal'],
                    'line' => $context['line'],
                    'excerpt' => $context['excerpt'],
                    'source_type' => $context['source_type'],
                ];
            }
        }

        $legacySignalInventory = $this->legacySignalTriageQueue($legacySignals);
        $legacyTriageQueue = $this->legacyOperationalTriageQueue($legacySignalInventory);
        $legacyCleanupQueue = $this->legacyCleanupQueue($legacySignalInventory);

        return [
            'classes' => $classes,
            'artisan_commands' => $commands,
            'routes' => $routes,
            'named_routes' => $namedRoutes,
            'route_actions' => $routeActions,
            'legacy_signal_count' => count($legacySignals),
            'legacy_signals' => $legacySignals,
            'legacy_signal_groups' => $this->legacySignalGroups($legacySignals, 'signal'),
            'legacy_area_groups' => $this->legacySignalGroups($legacySignals, 'area'),
            'legacy_signal_inventory' => $legacySignalInventory,
            'legacy_triage_queue' => $legacyTriageQueue,
            'legacy_cleanup_queue' => $legacyCleanupQueue,
            'legacy_operational_groups' => $this->legacyOperationalGroups($legacySignalInventory),
            'legacy_operational_summary' => $this->legacyOperationalSummary($legacySignalInventory),
            'ai_service_hotspots' => $this->aiServiceHotspots($aiServiceRecords, $legacySignals),
        ];
    }

    /**
     * @return array<int,array{signal:string,line:int,excerpt:string,source_type:string}>
     */
    private function legacySignalContexts(string $content): array
    {
        $items = [];
        $seen = [];
        $lines = preg_split('/\R/', $content) ?: [];
        foreach ($lines as $index => $line) {
            $signals = [];
            if (preg_match_all('/\b(legacy|deprecated|scaffold|planned_scaffold|executed_scaffold|duplicate)\b/i', (string) $line, $legacyMatches)) {
                $signals = array_merge($signals, $legacyMatches[1] ?? []);
            }
            if (preg_match_all('/(?<![A-Za-z0-9_])(TODO|FIXME)(?![A-Za-z0-9_])/', (string) $line, $todoMatches)) {
                $signals = array_merge($signals, $todoMatches[1] ?? []);
            }
            if ($signals === []) {
                continue;
            }

            $excerpt = trim(preg_replace('/\s+/', ' ', (string) $line) ?? '');
            if (strlen($excerpt) > 180) {
                $excerpt = substr($excerpt, 0, 177).'...';
            }
            foreach ($signals as $match) {
                $signal = strtolower((string) $match);
                $dedupeKey = ($index + 1).':'.$signal.':'.$excerpt;
                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;
                $items[] = [
                    'signal' => $signal,
                    'line' => $index + 1,
                    'excerpt' => $excerpt,
                    'source_type' => $this->legacySignalSourceType((string) $line),
                ];
            }
        }

        return $items;
    }

    private function legacySignalSourceType(string $line): string
    {
        $trimmed = trim($line);
        if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '|')) {
            return 'docblock';
        }
        if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) {
            return 'comment';
        }
        if (preg_match('/[\'"][^\'"]*(legacy|deprecated|scaffold|planned_scaffold|executed_scaffold|duplicate)[^\'"]*[\'"]/i', $line)
            || preg_match('/[\'"][^\'"]*(TODO|FIXME)[^\'"]*[\'"]/', $line)) {
            return 'string_literal';
        }

        return 'code';
    }

    /**
     * @param  array<int,array{path:string,subarea:string,topics:array<int,string>}>  $records
     * @param  array<int,array<string,string>>  $legacySignals
     * @return array<string,mixed>
     */
    private function aiServiceHotspots(array $records, array $legacySignals): array
    {
        $legacyByPath = [];
        foreach ($legacySignals as $signal) {
            $path = (string) ($signal['path'] ?? '');
            if (str_starts_with($path, 'app/Services/Ai/')) {
                $legacyByPath[$path][] = (string) ($signal['signal'] ?? 'unknown');
            }
        }

        $subareas = [];
        $topics = [];
        foreach ($records as $record) {
            $subarea = $record['subarea'];
            $path = $record['path'];
            $subareas[$subarea] ??= [
                'value' => $subarea,
                'file_count' => 0,
                'legacy_signal_count' => 0,
                'topic_counts' => [],
                'samples' => [],
            ];
            $subareas[$subarea]['file_count']++;
            if (isset($legacyByPath[$path])) {
                $subareas[$subarea]['legacy_signal_count'] += count($legacyByPath[$path]);
                if (count($subareas[$subarea]['samples']) < 8) {
                    $subareas[$subarea]['samples'][] = [
                        'path' => $path,
                        'signals' => $this->primitives->uniqueStrings($legacyByPath[$path]),
                    ];
                }
            }

            foreach ($record['topics'] as $topic) {
                $topics[$topic] ??= ['value' => $topic, 'file_count' => 0, 'legacy_signal_count' => 0, 'samples' => []];
                $topics[$topic]['file_count']++;
                $subareas[$subarea]['topic_counts'][$topic] = ((int) ($subareas[$subarea]['topic_counts'][$topic] ?? 0)) + 1;
                if (isset($legacyByPath[$path])) {
                    $topics[$topic]['legacy_signal_count'] += count($legacyByPath[$path]);
                    if (count($topics[$topic]['samples']) < 8) {
                        $topics[$topic]['samples'][] = [
                            'path' => $path,
                            'signals' => $this->primitives->uniqueStrings($legacyByPath[$path]),
                        ];
                    }
                }
            }
        }

        $subareas = array_values($subareas);
        usort($subareas, static fn (array $a, array $b): int => ((int) $b['legacy_signal_count']) <=> ((int) $a['legacy_signal_count'])
            ?: ((int) $b['file_count']) <=> ((int) $a['file_count']));
        $topics = array_values($topics);
        usort($topics, static fn (array $a, array $b): int => ((int) $b['legacy_signal_count']) <=> ((int) $a['legacy_signal_count'])
            ?: ((int) $b['file_count']) <=> ((int) $a['file_count']));

        return [
            'file_count' => count($records),
            'legacy_signal_count' => array_sum(array_map('count', $legacyByPath)),
            'subareas' => array_slice($subareas, 0, 20),
            'topics' => array_slice($topics, 0, 20),
            'policy' => 'hotspot_rank_is_triage_priority_not_delete_authority',
        ];
    }

    private function aiServiceSubarea(string $path): string
    {
        $parts = explode('/', $path);
        $segment = $parts[3] ?? '_root';

        return 'app/Services/Ai/'.$segment;
    }

    /**
     * @return array<int,string>
     */
    private function aiServiceTopics(string $content): array
    {
        $lower = strtolower($content);
        $topics = [];
        $definitions = [
            'memory_rag_retrieval' => ['memory', 'rag', 'retrieval', 'embedding', 'context pack', 'open brain'],
            'self_construction' => ['self construction', 'selfconstruction', 'agent control plane', 'completion audit'],
            'runtime_orchestration' => ['runtime', 'execution', 'worker', 'dispatch', 'orchestration'],
            'domain_runtime' => ['domain runtime', 'domain manifest', 'domain maturity'],
            'voice_vox' => ['voice', 'vox', 'livekit', 'tts', 'stt'],
            'programming_forge' => ['programming', 'forge', 'atlasdev', 'work packet'],
        ];
        foreach ($definitions as $topic => $terms) {
            foreach ($terms as $term) {
                if (str_contains($lower, $term)) {
                    $topics[] = $topic;
                    break;
                }
            }
        }

        return $topics;
    }

    /**
     * @param  array<int,array<string,string>>  $legacySignals
     * @return array<int,array<string,mixed>>
     */
    private function legacySignalGroups(array $legacySignals, string $groupBy): array
    {
        $groups = [];
        foreach ($legacySignals as $signal) {
            $path = (string) ($signal['path'] ?? '');
            $key = $groupBy === 'area'
                ? $this->legacySignalArea($path)
                : (string) ($signal['signal'] ?? 'unknown');
            $groups[$key][] = $signal + ['area' => $this->legacySignalArea($path)];
        }

        $items = [];
        foreach ($groups as $key => $signals) {
            $items[] = [
                'value' => $key,
                'count' => count($signals),
                'samples' => array_slice(array_values($signals), 0, 10),
                'policy' => $groupBy === 'area'
                    ? 'triage_area_with_reachability_before_cleanup'
                    : 'signal_is_keyword_not_deletion_authority',
            ];
        }
        usort($items, static fn (array $a, array $b): int => ((int) $b['count']) <=> ((int) $a['count']));

        return $items;
    }

    private function legacySignalArea(string $path): string
    {
        $parts = explode('/', $path);
        if (($parts[0] ?? '') !== 'app') {
            return $parts[0] ?? 'unknown';
        }
        if (($parts[1] ?? '') === 'Services' && isset($parts[2])) {
            return 'app/Services/'.$parts[2];
        }
        if (($parts[1] ?? '') === 'Http' && isset($parts[2])) {
            return 'app/Http/'.$parts[2];
        }
        if (($parts[1] ?? '') === 'Console' && isset($parts[2])) {
            return 'app/Console/'.$parts[2];
        }
        if (in_array(($parts[1] ?? ''), ['Models', 'Enums'], true)) {
            return 'app/'.$parts[1];
        }

        return implode('/', array_slice($parts, 0, min(3, count($parts))));
    }

    /**
     * @param  array<int,array<string,string>>  $legacySignals
     * @return array<int,array<string,mixed>>
     */
    private function legacySignalTriageQueue(array $legacySignals): array
    {
        $items = [];
        foreach ($legacySignals as $signal) {
            $path = (string) ($signal['path'] ?? '');
            $type = (string) ($signal['signal'] ?? 'unknown');
            $area = $this->legacySignalArea($path);
            $items[] = [
                'id' => 'legacy_signal:'.$type.':'.str_replace(['/', '.', '\\'], ':', $path).':'.((int) ($signal['line'] ?? 0)),
                'kind' => 'legacy_or_scaffold_signal',
                'signal' => $type,
                'severity' => $this->legacySignalSeverity($type, $area),
                'path' => $path,
                'area' => $area,
                'line' => $signal['line'] ?? null,
                'excerpt' => $signal['excerpt'] ?? null,
                'source_type' => $signal['source_type'] ?? 'unknown',
                'operational_classification' => $this->legacySignalOperationalClassification($type, $signal),
                'boundary_contract' => $this->legacySignalBoundaryContract($type, $path, $area),
                'cleanup_recommendation' => $this->legacySignalCleanupRecommendation($type),
                'claim_policy' => 'keyword_signal_is_triage_pressure_not_dead_code_or_delete_authority',
                'next_commands' => [
                    'php artisan atlas:code-reality reachability --target="'.$path.'" --json',
                    'php artisan atlas:code-reality deletion-preflight --target="'.$path.'" --json',
                ],
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $rank = ['high' => 0, 'medium' => 1, 'review' => 2, 'low' => 3];

            return ($rank[$a['severity']] ?? 9) <=> ($rank[$b['severity']] ?? 9);
        });

        return $items;
    }

    /**
     * @param  array<int,array<string,mixed>>  $legacySignalInventory
     * @return array<int,array<string,mixed>>
     */
    private function legacyOperationalTriageQueue(array $legacySignalInventory): array
    {
        return array_values(array_filter($legacySignalInventory, static function (array $item): bool {
            $classification = (array) ($item['operational_classification'] ?? []);

            return in_array((string) ($classification['cleanup_pressure'] ?? 'unknown'), ['review', 'documentation_review'], true)
                || (string) ($classification['ia_confusion_risk'] ?? 'unknown') === 'high';
        }));
    }

    /**
     * @param  array<int,array<string,mixed>>  $legacyTriageQueue
     * @return array<int,array<string,mixed>>
     */
    private function legacyCleanupQueue(array $legacyTriageQueue): array
    {
        $items = [];
        foreach ($legacyTriageQueue as $item) {
            $classification = (array) ($item['operational_classification'] ?? []);
            $bucket = (string) ($classification['bucket'] ?? 'unknown');
            $cleanupPressure = (string) ($classification['cleanup_pressure'] ?? 'unknown');
            if ($cleanupPressure !== 'review') {
                continue;
            }

            $path = (string) ($item['path'] ?? '');
            $items[] = [
                'id' => 'legacy_cleanup:'.str_replace(['/', '.', '\\'], ':', $path).':'.((int) ($item['line'] ?? 0)).':'.((string) ($item['signal'] ?? 'unknown')),
                'kind' => 'legacy_cleanup_candidate',
                'path' => $path,
                'line' => $item['line'] ?? null,
                'signal' => (string) ($item['signal'] ?? 'unknown'),
                'excerpt' => $item['excerpt'] ?? null,
                'source_type' => (string) ($item['source_type'] ?? 'unknown'),
                'area' => (string) ($item['area'] ?? $this->legacySignalArea($path)),
                'bucket' => $bucket,
                'subtype' => (string) ($classification['subtype'] ?? 'unknown'),
                'ia_confusion_risk' => (string) ($classification['ia_confusion_risk'] ?? 'unknown'),
                'safe_interpretation' => (string) ($classification['safe_interpretation'] ?? 'requires_owner_review'),
                'delete_allowed' => false,
                'rename_allowed_without_owner_decision' => false,
                'recommended_cleanup_direction' => $this->legacyCleanupDirection($bucket),
                'boundary_contract' => $item['boundary_contract'] ?? [],
                'next_commands' => $item['next_commands'] ?? [],
                'claim_policy' => 'cleanup_queue_is_review_order_not_delete_authority',
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $riskRank = ['high' => 0, 'medium' => 1, 'low' => 2, 'unknown' => 3];

            return ($riskRank[$a['ia_confusion_risk']] ?? 9) <=> ($riskRank[$b['ia_confusion_risk']] ?? 9)
                ?: strcmp((string) ($a['area'] ?? ''), (string) ($b['area'] ?? ''))
                ?: strcmp((string) ($a['path'] ?? ''), (string) ($b['path'] ?? ''));
        });

        return array_slice($items, 0, 50);
    }

    private function legacyCleanupDirection(string $bucket): string
    {
        return match ($bucket) {
            'scaffold_status_or_filter' => 'verify_if_runtime_taxonomy_or_partial_flow_then_split_status_language_from_active_runtime_contract',
            'compatibility_adapter_code' => 'prove_replacement_and_callers_then_write_deprecation_or_alias_boundary_before_rename',
            'compatibility_wrapper_doc' => 'prove_replacement_and_callers_then_write_deprecation_or_alias_boundary_before_rename',
            'commentary_or_documentation_language' => 'convert_comment_or_docblock_into_owner_doc_decision_or_remove_stale_comment_after_tests',
            'runtime_keyword_pressure' => 'run_reachability_and_owner_doc_review_before_keep_rename_quarantine_or_delete_decision',
            default => 'owner_review_before_any_cleanup',
        };
    }

    /**
     * @param  array<string,mixed>  $signal
     * @return array<string,string>
     */
    private function legacySignalOperationalClassification(string $type, array $signal): array
    {
        $path = (string) ($signal['path'] ?? '');
        $sourceType = (string) ($signal['source_type'] ?? 'unknown');
        $excerpt = strtolower((string) ($signal['excerpt'] ?? ''));

        if ($type === 'scaffold'
            && ($path === 'app/Console/Commands/AtlasScaffoldStageCommand.php'
                || $path === 'app/Services/Ai/SelfConstruction/AtlasSelfConstructionScaffoldStagingExecutorService.php'
                || str_contains($excerpt, 'self-construction scaffold staging executor'))) {
            return [
                'bucket' => 'canonical_self_construction_scaffold_staging_runtime',
                'subtype' => 'approved_proposal_staging_runtime',
                'cleanup_pressure' => 'none',
                'ia_confusion_risk' => 'low',
                'safe_interpretation' => 'scaffold_is_canonical_self_construction_staging_term_not_legacy_code',
            ];
        }

        if ($type === 'deprecated'
            && $path === 'app/Services/Ai/Cognition/AtlasCognitiveMemoryFabricSchemaEvolutionService.php'
            && (str_contains($excerpt, 'deprecatedfields')
                || str_contains($excerpt, 'deprecated fields')
                || str_contains($excerpt, '$deprecated')
                || str_contains($excerpt, 'array $deprecated'))) {
            return [
                'bucket' => 'canonical_schema_evolution_field_lifecycle',
                'subtype' => 'schema_evolution_deprecated_fields_contract',
                'cleanup_pressure' => 'none',
                'ia_confusion_risk' => 'low',
                'safe_interpretation' => 'deprecated_fields_are_schema_lifecycle_payload_not_deprecated_runtime_code',
            ];
        }

        $lifecycleTaxonomy = $this->legacyLifecycleTaxonomyClassification($type, $excerpt);
        if ($lifecycleTaxonomy !== null) {
            return $lifecycleTaxonomy;
        }

        if (in_array($type, ['scaffold', 'planned_scaffold', 'executed_scaffold'], true)
            && $sourceType === 'string_literal'
            && str_contains($excerpt, 'implemented-vs-scaffold-matrix')) {
            return [
                'bucket' => 'diagnostic_architecture_matrix_reference',
                'subtype' => 'implemented_vs_scaffold_read_model_reference',
                'cleanup_pressure' => 'none',
                'ia_confusion_risk' => 'low',
                'safe_interpretation' => 'implemented_vs_scaffold_matrix_is_diagnostic_read_model_not_legacy_runtime',
            ];
        }

        $scaffoldBoundary = $this->legacyScaffoldBoundaryClassification($type, $path, $excerpt);
        if ($scaffoldBoundary !== null) {
            return $scaffoldBoundary;
        }

        if ($sourceType === 'string_literal' && preg_match('/(^[\'"]deprecated[\'"],?$|status|statuses|maturity|rule::in|--status|onboarding-status|scaffold_domains|planned_scaffold|executed_scaffold|attempted_scaffold|repair_scaffold|status_deprecated|tool_status)/', $excerpt)) {
            return [
                'bucket' => 'status_taxonomy_value',
                'subtype' => 'status_or_filter_value',
                'cleanup_pressure' => 'none',
                'ia_confusion_risk' => 'medium',
                'safe_interpretation' => 'allowed_status_or_filter_value_not_dead_code',
            ];
        }

        if ($sourceType === 'code' && preg_match('/^\{--[a-z0-9-]+(?:=|\s|:)/i', $excerpt)) {
            return [
                'bucket' => 'status_taxonomy_value',
                'subtype' => 'cli_filter_or_compatibility_option',
                'cleanup_pressure' => 'none',
                'ia_confusion_risk' => 'medium',
                'safe_interpretation' => 'cli_option_contract_value_not_dead_code',
            ];
        }

        if ($type === 'legacy' && $sourceType === 'code' && preg_match('/fromlegacy|\$legacy|legacy:|legacy key|legacy alias|legacy-pure|legacy single-line|legacy path|legacy worker|legacy pipeline|older dashboards|older clients|backward compatibility|compatibility|pre-freshness receipt/', $excerpt)) {
            return [
                'bucket' => 'compatibility_adapter_code',
                'subtype' => 'legacy_input_adapter',
                'cleanup_pressure' => 'review',
                'ia_confusion_risk' => 'medium',
                'safe_interpretation' => 'legacy_adapter_may_be_intentional_until_external_callers_are_migrated',
            ];
        }

        if ($sourceType === 'docblock' && str_contains($excerpt, 'deprecated wrapper')) {
            return [
                'bucket' => 'compatibility_wrapper_doc',
                'subtype' => 'compatibility_docblock',
                'cleanup_pressure' => 'review',
                'ia_confusion_risk' => 'high',
                'safe_interpretation' => 'compatibility_path_may_be_intentional_until_owner_deprecation_plan_exists',
            ];
        }

        if ($type === 'duplicate' && in_array($sourceType, ['code', 'string_literal'], true)) {
            return [
                'bucket' => 'deduplication_runtime_language',
                'subtype' => 'deduplication_algorithm_or_status',
                'cleanup_pressure' => 'none',
                'ia_confusion_risk' => 'low',
                'safe_interpretation' => 'code_is_about_detecting_duplicates_not_itself_duplicate_by_keyword',
            ];
        }

        if (in_array($type, ['scaffold', 'planned_scaffold', 'executed_scaffold'], true)
            && $sourceType === 'string_literal'
            && str_contains($excerpt, 'implemented-vs-scaffold-matrix')) {
            return [
                'bucket' => 'diagnostic_architecture_matrix_reference',
                'subtype' => 'implemented_vs_scaffold_read_model_reference',
                'cleanup_pressure' => 'none',
                'ia_confusion_risk' => 'low',
                'safe_interpretation' => 'implemented_vs_scaffold_matrix_is_diagnostic_read_model_not_legacy_runtime',
            ];
        }

        if (in_array($sourceType, ['comment', 'docblock'], true)) {
            return [
                'bucket' => 'commentary_or_documentation_language',
                'subtype' => $sourceType,
                'cleanup_pressure' => 'inventory',
                'ia_confusion_risk' => 'low',
                'safe_interpretation' => 'comment_or_doc_text_is_audit_inventory_not_cleanup_review',
            ];
        }

        if (in_array($type, ['scaffold', 'planned_scaffold', 'executed_scaffold'], true) && $sourceType === 'string_literal') {
            return [
                'bucket' => 'scaffold_status_or_filter',
                'subtype' => $this->scaffoldSignalSubtype($excerpt),
                'cleanup_pressure' => 'review',
                'ia_confusion_risk' => 'high',
                'safe_interpretation' => 'may_be_runtime_state_taxonomy_or_partial_flow_not_delete_signal',
            ];
        }

        if ($sourceType === 'code') {
            return [
                'bucket' => 'runtime_keyword_pressure',
                'subtype' => 'runtime_keyword',
                'cleanup_pressure' => 'review',
                'ia_confusion_risk' => 'high',
                'safe_interpretation' => 'requires_reachability_owner_doc_and_tests_before_cleanup',
            ];
        }

        return [
            'bucket' => 'runtime_keyword_inventory',
            'subtype' => 'string_literal_keyword',
            'cleanup_pressure' => 'inventory',
            'ia_confusion_risk' => 'medium',
            'safe_interpretation' => 'string_literal_keyword_is_audit_inventory_until_code_reachability_proves_cleanup_pressure',
        ];
    }

    /**
     * @return array<string,string>|null
     */
    private function legacyLifecycleTaxonomyClassification(string $type, string $excerpt): ?array
    {
        if ($type !== 'deprecated') {
            return null;
        }

        if (! preg_match('/deprecated(_at|fields?)|state_deprecated|deprecated state|terminal deprecated|deprecation|timestamp|schema|entry|entries|lifecycle|blocked_private|tombstoned|\$deprecated|bool \$deprecated|if \(\$deprecated|status_deprecated|tool_status|maturity|rule::in/', $excerpt)) {
            return null;
        }

        return [
            'bucket' => 'lifecycle_taxonomy_value',
            'subtype' => 'deprecated_status_or_schema_lifecycle',
            'cleanup_pressure' => 'none',
            'ia_confusion_risk' => 'medium',
            'safe_interpretation' => 'deprecated_is_lifecycle_taxonomy_or_schema_contract_not_deprecated_runtime_code',
        ];
    }

    /**
     * @return array<string,string>|null
     */
    private function legacyScaffoldBoundaryClassification(string $type, string $path, string $excerpt): ?array
    {
        if (! in_array($type, ['scaffold', 'planned_scaffold', 'executed_scaffold'], true)) {
            return null;
        }

        if (str_contains($path, '/Kernel/Pipeline/')
            || str_contains($path, 'ScaffoldPromoteCommand.php')
            || str_contains($path, 'AtlasScaffoldStageCommand.php')) {
            return [
                'bucket' => 'parked_scaffold_contract',
                'subtype' => $this->scaffoldSignalSubtype($excerpt),
                'cleanup_pressure' => 'none',
                'ia_confusion_risk' => 'medium',
                'safe_interpretation' => 'scaffold_is_explicit_pipeline_or_promotion_boundary_not_delete_signal',
            ];
        }

        if (preg_match('/implemented-vs-scaffold|implementation_state|status|maturity|domain|domains|roadmap|queue|gate|gates|guardrail|fake_runtime|no-op|scaffold-only|no-scaffold|incompleteness marker|evidence kit|placeholder|marker|signed_by|scaffold\/planned|planned until|voice realtime surface congelada/', $excerpt)) {
            return [
                'bucket' => 'scaffold_taxonomy_or_guardrail',
                'subtype' => $this->scaffoldSignalSubtype($excerpt),
                'cleanup_pressure' => 'none',
                'ia_confusion_risk' => 'medium',
                'safe_interpretation' => 'scaffold_is_status_taxonomy_or_guardrail_language_not_legacy_cleanup',
            ];
        }

        if (preg_match('/\$scaffold|->generate\(|scaffoldissafe|stagingpath|staging_dir|skillscaffoldgenerator|operator skill proposal|slug|title|description|markdown/', $excerpt)
            || str_contains($path, '/OperatorIntelligence/')) {
            return [
                'bucket' => 'scaffold_staging_payload',
                'subtype' => 'staged_skill_or_proposal_payload',
                'cleanup_pressure' => 'none',
                'ia_confusion_risk' => 'medium',
                'safe_interpretation' => 'scaffold_is_staged_payload_name_with_sandbox_boundary_not_legacy_runtime',
            ];
        }

        if (preg_match('/(^[\'"]scaffold[\'"],?$|=> [\'"]scaffold[\'"]|return [\'"]scaffold[\'"]|kind[\'"]?\s*=>\s*[\'"]scaffold[\'"]|\bscaffold\b)/', $excerpt)) {
            return [
                'bucket' => 'scaffold_taxonomy_or_guardrail',
                'subtype' => $this->scaffoldSignalSubtype($excerpt),
                'cleanup_pressure' => 'none',
                'ia_confusion_risk' => 'medium',
                'safe_interpretation' => 'scaffold_keyword_is_runtime_taxonomy_until_reachability_proves_stale_code',
            ];
        }

        return null;
    }

    private function scaffoldSignalSubtype(string $excerpt): string
    {
        return match (true) {
            str_contains($excerpt, 'signed_by') => 'runtime_provenance_signature',
            str_contains($excerpt, 'placeholder') || str_contains($excerpt, 'evidence kit') => 'evidence_placeholder',
            str_contains($excerpt, 'pipeline.scaffold') || str_contains($excerpt, 'scaffold pipeline') => 'kernel_pipeline_scaffold',
            str_contains($excerpt, 'implemented-vs-scaffold-matrix') => 'architecture_matrix_reference',
            str_contains($excerpt, '_scaffold') || str_contains($excerpt, 'needs ') => 'qualitative_gate_requirement',
            default => 'scaffold_status_or_literal',
        };
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    private function legacyOperationalGroups(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $classification = (array) ($item['operational_classification'] ?? []);
            $bucket = (string) ($classification['bucket'] ?? 'unknown');
            $groups[$bucket] ??= [
                'bucket' => $bucket,
                'count' => 0,
                'cleanup_pressure' => (string) ($classification['cleanup_pressure'] ?? 'unknown'),
                'ia_confusion_risk' => (string) ($classification['ia_confusion_risk'] ?? 'unknown'),
                'safe_interpretation' => (string) ($classification['safe_interpretation'] ?? 'unknown'),
                'subtypes' => [],
                'samples' => [],
            ];
            $groups[$bucket]['cleanup_pressure'] = $this->dominantCleanupPressure(
                (string) ($groups[$bucket]['cleanup_pressure'] ?? 'unknown'),
                (string) ($classification['cleanup_pressure'] ?? 'unknown')
            );
            $groups[$bucket]['ia_confusion_risk'] = $this->dominantIaConfusionRisk(
                (string) ($groups[$bucket]['ia_confusion_risk'] ?? 'unknown'),
                (string) ($classification['ia_confusion_risk'] ?? 'unknown')
            );
            $groups[$bucket]['count']++;
            $subtype = (string) ($classification['subtype'] ?? 'unknown');
            $groups[$bucket]['subtypes'][$subtype] ??= [
                'value' => $subtype,
                'count' => 0,
                'samples' => [],
            ];
            $groups[$bucket]['subtypes'][$subtype]['count']++;
            if (count($groups[$bucket]['subtypes'][$subtype]['samples']) < 4) {
                $groups[$bucket]['subtypes'][$subtype]['samples'][] = (string) ($item['path'] ?? '');
            }
            if (count($groups[$bucket]['samples']) < 8) {
                $groups[$bucket]['samples'][] = [
                    'path' => (string) ($item['path'] ?? ''),
                    'signal' => (string) ($item['signal'] ?? 'unknown'),
                    'subtype' => $subtype,
                    'source_type' => (string) ($item['source_type'] ?? 'unknown'),
                    'line' => $item['line'] ?? null,
                    'excerpt' => $item['excerpt'] ?? null,
                ];
            }
        }

        $groups = array_values($groups);
        foreach ($groups as &$group) {
            $subtypes = array_values((array) ($group['subtypes'] ?? []));
            usort($subtypes, static fn (array $a, array $b): int => ((int) $b['count']) <=> ((int) $a['count']));
            $group['subtypes'] = $subtypes;
        }
        unset($group);
        usort($groups, static function (array $a, array $b): int {
            $pressureRank = ['review' => 0, 'low' => 1, 'inventory' => 2, 'documentation_review' => 3, 'none' => 4, 'unknown' => 5];
            $riskRank = ['high' => 0, 'medium' => 1, 'low' => 2, 'unknown' => 3];

            return ($pressureRank[$a['cleanup_pressure']] ?? 9) <=> ($pressureRank[$b['cleanup_pressure']] ?? 9)
                ?: ($riskRank[$a['ia_confusion_risk']] ?? 9) <=> ($riskRank[$b['ia_confusion_risk']] ?? 9)
                ?: ((int) $b['count']) <=> ((int) $a['count']);
        });

        return $groups;
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<string,mixed>
     */
    private function legacyOperationalSummary(array $items): array
    {
        $review = 0;
        $documentationReview = 0;
        $documentationInventory = 0;
        $keywordInventory = 0;
        $falsePositiveOrTaxonomy = 0;
        $highRisk = 0;
        $taxonomyBuckets = [
            'canonical_schema_evolution_field_lifecycle',
            'canonical_self_construction_scaffold_staging_runtime',
            'deduplication_runtime_language',
            'diagnostic_architecture_matrix_reference',
            'lifecycle_taxonomy_value',
            'parked_scaffold_contract',
            'scaffold_staging_payload',
            'scaffold_taxonomy_or_guardrail',
            'status_taxonomy_value',
        ];

        foreach ($items as $item) {
            $classification = (array) ($item['operational_classification'] ?? []);
            $bucket = (string) ($classification['bucket'] ?? 'unknown');
            if (($classification['cleanup_pressure'] ?? null) === 'review') {
                $review++;
            }
            if (($classification['cleanup_pressure'] ?? null) === 'documentation_review') {
                $documentationReview++;
            }
            if (($classification['cleanup_pressure'] ?? null) === 'inventory' && $bucket === 'commentary_or_documentation_language') {
                $documentationInventory++;
            }
            if (($classification['cleanup_pressure'] ?? null) === 'inventory' && $bucket !== 'commentary_or_documentation_language') {
                $keywordInventory++;
            }
            if (in_array($bucket, $taxonomyBuckets, true)) {
                $falsePositiveOrTaxonomy++;
            }
            if (($classification['ia_confusion_risk'] ?? null) === 'high') {
                $highRisk++;
            }
        }

        return [
            'total' => count($items),
            'cleanup_review_count' => $review,
            'documentation_review_count' => $documentationReview,
            'documentation_inventory_count' => $documentationInventory,
            'keyword_inventory_count' => $keywordInventory,
            'false_positive_or_taxonomy_count' => $falsePositiveOrTaxonomy,
            'high_ia_confusion_risk_count' => $highRisk,
            'claim_policy' => 'summary_prioritizes_cleanup_review_and_preserves_inventory_but_never_authorizes_delete_without_preflight',
        ];
    }

    private function dominantCleanupPressure(string $current, string $candidate): string
    {
        $rank = ['review' => 0, 'low' => 1, 'inventory' => 2, 'documentation_review' => 3, 'none' => 4, 'unknown' => 5];

        return ($rank[$candidate] ?? 9) < ($rank[$current] ?? 9) ? $candidate : $current;
    }

    private function dominantIaConfusionRisk(string $current, string $candidate): string
    {
        $rank = ['high' => 0, 'medium' => 1, 'low' => 2, 'unknown' => 3];

        return ($rank[$candidate] ?? 9) < ($rank[$current] ?? 9) ? $candidate : $current;
    }

    private function legacySignalSeverity(string $type, string $area): string
    {
        return match ($type) {
            'deprecated' => 'high',
            'duplicate', 'executed_scaffold', 'planned_scaffold' => 'medium',
            'scaffold' => str_starts_with($area, 'app/Services/Ai') ? 'medium' : 'review',
            'legacy', 'todo', 'fixme' => 'review',
            default => 'low',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function legacySignalBoundaryContract(string $type, string $path, string $area): array
    {
        return [
            'classification' => 'legacy_scaffold_keyword_triage',
            'signal' => $type,
            'area' => $area,
            'target' => $path,
            'owner_docs' => $this->legacySignalOwnerDocs($path, $area),
            'evidence_required_before_cleanup' => [
                'reachability',
                'deletion_preflight',
                'owner_doc_decision',
                'focused_tests',
            ],
            'allowed_direction' => 'classify_reachability_then_choose_keep_boundary_rename_quarantine_or_delete_with_owner_decision',
            'forbidden' => 'do_not_delete_rename_or_reimplement_from_keyword_signal_alone',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function legacySignalOwnerDocs(string $path, string $area): array
    {
        if (str_starts_with($path, 'app/Services/Ai/Programming') || str_starts_with($path, 'app/Services/AtlasCode')) {
            return [
                'docs/engineering-knowledge-base/atlas-programming-forge-flow.md',
                'docs/engineering-knowledge-base/atlas-duplication-reality-governance.md',
            ];
        }
        if (str_starts_with($path, 'app/Services/Ai/SelfConstruction')) {
            return [
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract-part-01.md',
                'docs/engineering-knowledge-base/atlas-duplication-reality-governance.md',
            ];
        }
        if (str_contains($path, 'Voice') || str_contains($path, '/Vox/')) {
            return [
                'docs/engineering-knowledge-base/atlas-vox-operational-thinking-interface.md',
                'docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md',
                'docs/engineering-knowledge-base/atlas-duplication-reality-governance.md',
            ];
        }
        if ($area === 'app/Services/Engineering') {
            return [
                'docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md',
                'docs/engineering-knowledge-base/atlas-duplication-reality-governance.md',
            ];
        }

        return ['docs/engineering-knowledge-base/atlas-duplication-reality-governance.md'];
    }

    /**
     * @return array<string,mixed>
     */
    private function legacySignalCleanupRecommendation(string $type): array
    {
        return [
            'delete_allowed' => false,
            'rename_allowed_without_owner_decision' => false,
            'safe_next_action' => match ($type) {
                'deprecated' => 'prove_replacement_and_callers_then_plan_deprecation_or_quarantine',
                'duplicate' => 'compare_owner_contracts_and_reachability_before_merge_or_rename',
                'scaffold', 'planned_scaffold', 'executed_scaffold' => 'split_scaffold_language_from_active_runtime_or_mark_partial_with_owner_doc',
                'todo', 'fixme' => 'convert_comment_to_tracked_owner_decision_or_test_gap',
                default => 'run_reachability_and_owner_review_before_cleanup',
            },
        ];
    }
}
