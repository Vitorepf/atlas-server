<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use Illuminate\Support\Facades\File;
use SplFileInfo;

final class AtlasCodeRealityUsageIntelligenceService
{
    public const SCHEMA_VERSION = 'atlas.code_reality_usage_intelligence.v1';

    public const REACHABILITY_SCHEMA_VERSION = 'atlas.code_reality.reachability.v1';

    /**
     * @var array<int,string>
     */
    private const SEARCH_ROOTS = ['app', 'routes', 'config', 'database', 'tests', 'docs/engineering-knowledge-base'];

    public function __construct(
        private readonly EngineeringDocumentationAuthorityAuditService $authorityAudit,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function classify(string $target): array
    {
        $normalizedTarget = trim($target);
        $targetPath = $this->targetPath($normalizedTarget);
        $needle = $targetPath ?? $normalizedTarget;
        $basename = $targetPath ? basename($targetPath) : class_basename($normalizedTarget);
        $references = $this->references($needle, $basename);
        $ownerDocs = $this->ownerDocs($needle, $basename);
        $tests = $this->testRefs($needle, $basename);
        $entrypoints = $this->entrypoints($needle, $basename);
        if (is_string($targetPath) && str_starts_with($targetPath, 'app/Console/Commands/')) {
            $entrypoints[] = $targetPath;
            $entrypoints = array_values(array_unique($entrypoints));
            sort($entrypoints);
        }
        $reachability = $this->reachabilityEnvelope($targetPath, $references, $ownerDocs, $tests, $entrypoints);
        $classification = $this->classification($targetPath, $reachability);
        $blockers = $this->blockers($classification, $targetPath, $ownerDocs);

        return $this->envelope([
            'action' => 'classify',
            'target' => $normalizedTarget,
            'classification' => $classification,
            'target_path' => $targetPath,
            'exists' => $targetPath !== null,
            'evidence' => [
                'reference_count' => count($references),
                'references' => array_slice($references, 0, 20),
                'owner_docs' => $ownerDocs,
                'tests' => $tests,
                'entrypoints' => $entrypoints,
                'reachability' => $reachability,
            ],
            'blockers' => $blockers,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function usageMap(string $target): array
    {
        $classification = $this->classify($target);
        $reachability = data_get($classification, 'evidence.reachability', []);

        return $this->envelope([
            'action' => 'usage-map',
            'target' => $target,
            'classification' => $classification['classification'],
            'usage_map' => [
                'target_path' => $classification['target_path'],
                'entrypoints' => data_get($classification, 'evidence.entrypoints', []),
                'owner_docs' => data_get($classification, 'evidence.owner_docs', []),
                'tests' => data_get($classification, 'evidence.tests', []),
                'references' => data_get($classification, 'evidence.references', []),
                'reachability' => $reachability,
                'edges' => data_get($reachability, 'edges', []),
            ],
            'blockers' => $classification['blockers'],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function reachability(string $target): array
    {
        $classification = $this->classify($target);

        return $this->envelope([
            'action' => 'reachability',
            'target' => $target,
            'classification' => $classification['classification'],
            'target_path' => $classification['target_path'],
            'reachability' => data_get($classification, 'evidence.reachability', []),
            'blockers' => $classification['blockers'],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function antiDuplicate(string $feature): array
    {
        $feature = trim($feature);
        $tokens = $this->tokens($feature);
        $authority = $this->authorityAudit->report();
        $matches = [];

        foreach ((array) ($authority['capability_overlap_clusters'] ?? []) as $cluster) {
            $capability = (string) ($cluster['capability'] ?? '');
            if ($capability === '') {
                continue;
            }

            foreach ($tokens as $token) {
                if (str_contains($capability, $token)) {
                    $matches[] = [
                        'capability' => $capability,
                        'risk' => $cluster['risk'] ?? 'review',
                        'paths' => array_slice((array) ($cluster['paths'] ?? []), 0, 8),
                    ];
                    break;
                }
            }
        }

        $decision = $matches === [] ? 'proceed_with_owner_lookup' : 'reuse_or_extend_before_new_runtime';

        return $this->envelope([
            'action' => 'anti-duplicate',
            'feature' => $feature,
            'decision' => $decision,
            'match_count' => count($matches),
            'matches' => array_slice($matches, 0, 20),
            'required_next_step' => 'run_feature_placement_and_read_owner_docs_before_implementation',
            'blockers' => [],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function deadCodeCandidates(): array
    {
        return $this->envelope([
            'action' => 'dead-code-candidates',
            'classification_policy' => 'conservative_no_dead_code_confirmation',
            'dead_code_confirmed' => [],
            'candidates' => [],
            'required_quarantine_sequence' => [
                'unused_candidate',
                'reference_scan',
                'reachability_scan',
                'focused_tests',
                'owner_doc_review',
                'human_approval',
                'separate_delete_change',
            ],
            'blockers' => [],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function contextPack(string $task): array
    {
        $antiDuplicate = $this->antiDuplicate($task);

        return $this->envelope([
            'action' => 'context-pack',
            'task' => trim($task),
            'provider_safe' => true,
            'minimal_sources' => [
                'docs/engineering-knowledge-base/atlas-documentation-reality-system.md',
                'docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md',
                'docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md',
            ],
            'anti_duplicate_decision' => $antiDuplicate['decision'],
            'do_not_claim' => [
                'dead_code_confirmed_without_quarantine',
                'child_system_complete_without_dedicated_certification',
                'safe_to_delete_without_human_approval',
            ],
            'required_commands' => [
                'php artisan atlas:ai:place-feature "<feature>" --json',
                'php artisan atlas:code-reality reachability --target="<target>" --json',
                'php artisan atlas:code-reality anti-duplicate --feature="<feature>" --json',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
            ],
            'blockers' => [],
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function envelope(array $payload): array
    {
        $base = array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => (($payload['blockers'] ?? []) === []) ? 'ready' : 'blocked',
            'writes' => false,
            'claim_policy' => [
                'read_only' => true,
                'providers_invoked' => false,
                'rivals_run' => false,
                'deletes_files' => false,
                'dead_code_confirmation_allowed' => false,
            ],
        ], $payload);

        $hashPayload = $base;
        unset($hashPayload['certification_hash']);
        $base['certification_hash'] = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $base;
    }

    private function targetPath(string $target): ?string
    {
        if ($target === '') {
            return null;
        }

        $direct = base_path($target);
        if (File::exists($direct)) {
            return $target;
        }

        foreach ($this->allFiles() as $file) {
            $relative = $this->relativePath($file->getPathname());
            if (str_ends_with($relative, $target) || basename($relative) === $target) {
                return $relative;
            }
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function references(string $needle, string $basename): array
    {
        return $this->scanFor($needle, $basename, self::SEARCH_ROOTS);
    }

    /**
     * @return array<int,string>
     */
    private function ownerDocs(string $needle, string $basename): array
    {
        return array_values(array_filter(
            $this->scanFor($needle, $basename, ['docs/engineering-knowledge-base']),
            static fn (string $path): bool => str_ends_with($path, '.md')
        ));
    }

    /**
     * @return array<int,string>
     */
    private function testRefs(string $needle, string $basename): array
    {
        return $this->scanFor($needle, $basename, ['tests']);
    }

    /**
     * @return array<int,string>
     */
    private function entrypoints(string $needle, string $basename): array
    {
        return array_values(array_unique(array_merge(
            $this->scanFor($needle, $basename, ['routes']),
            array_values(array_filter($this->scanFor($needle, $basename, ['app/Console/Commands']), static fn (string $path): bool => str_contains($path, 'Commands/'))),
        )));
    }

    /**
     * @param  array<int,string>  $references
     * @param  array<int,string>  $ownerDocs
     * @param  array<int,string>  $tests
     * @param  array<int,string>  $entrypoints
     * @return array<string,mixed>
     */
    private function reachabilityEnvelope(?string $targetPath, array $references, array $ownerDocs, array $tests, array $entrypoints): array
    {
        $breakdown = $this->sourceBreakdown($references, $ownerDocs, $tests, $entrypoints);
        $signals = [
            'target_exists' => $targetPath !== null,
            'has_route_entrypoint' => $breakdown['routes']['count'] > 0,
            'has_command_entrypoint' => $breakdown['commands']['count'] > 0,
            'has_test_coverage' => $breakdown['tests']['count'] > 0,
            'has_owner_doc' => $breakdown['owner_docs']['count'] > 0,
            'has_service_or_code_callers' => $breakdown['code_callers']['count'] > 0,
            'has_config_reference' => $breakdown['config']['count'] > 0,
            'has_database_reference' => $breakdown['database']['count'] > 0,
        ];
        $strongSignals = count(array_filter([
            $signals['has_route_entrypoint'],
            $signals['has_command_entrypoint'],
            $signals['has_test_coverage'] && $signals['has_owner_doc'],
            $signals['has_service_or_code_callers'] && $signals['has_test_coverage'],
        ]));
        $confidence = match (true) {
            ! $signals['target_exists'] => 'none',
            $strongSignals >= 2 => 'high',
            $strongSignals === 1 || $signals['has_owner_doc'] || $signals['has_service_or_code_callers'] => 'medium',
            count($references) > 1 => 'low',
            default => 'review_required',
        };
        $status = match (true) {
            ! $signals['target_exists'] => 'not_found',
            in_array($confidence, ['high', 'medium'], true) => 'reachable',
            $confidence === 'low' => 'weakly_referenced',
            default => 'unproven',
        };

        return [
            'schema_version' => self::REACHABILITY_SCHEMA_VERSION,
            'status' => $status,
            'confidence' => $confidence,
            'target_path' => $targetPath,
            'signals' => $signals,
            'source_breakdown' => $breakdown,
            'edges' => $this->reachabilityEdges($targetPath, $breakdown),
            'policy' => [
                'dead_code_confirmation_allowed' => false,
                'delete_requires_quarantine_and_human_approval' => true,
                'weak_or_missing_reachability_blocks_delete_claims' => true,
            ],
        ];
    }

    /**
     * @param  array<int,string>  $references
     * @param  array<int,string>  $ownerDocs
     * @param  array<int,string>  $tests
     * @param  array<int,string>  $entrypoints
     * @return array<string,array{count:int,paths:array<int,string>}>
     */
    private function sourceBreakdown(array $references, array $ownerDocs, array $tests, array $entrypoints): array
    {
        $routes = array_values(array_filter($entrypoints, static fn (string $path): bool => str_starts_with($path, 'routes/')));
        $commands = array_values(array_filter($entrypoints, static fn (string $path): bool => str_starts_with($path, 'app/Console/Commands/')));
        $config = array_values(array_filter($references, static fn (string $path): bool => str_starts_with($path, 'config/')));
        $database = array_values(array_filter($references, static fn (string $path): bool => str_starts_with($path, 'database/')));
        $docs = array_values(array_filter($references, static fn (string $path): bool => str_starts_with($path, 'docs/')));
        $codeCallers = array_values(array_filter($references, static function (string $path): bool {
            return str_starts_with($path, 'app/')
                && ! str_starts_with($path, 'app/Console/Commands/');
        }));

        return [
            'routes' => ['count' => count($routes), 'paths' => array_slice($routes, 0, 12)],
            'commands' => ['count' => count($commands), 'paths' => array_slice($commands, 0, 12)],
            'tests' => ['count' => count($tests), 'paths' => array_slice($tests, 0, 12)],
            'owner_docs' => ['count' => count($ownerDocs), 'paths' => array_slice($ownerDocs, 0, 12)],
            'docs' => ['count' => count($docs), 'paths' => array_slice($docs, 0, 12)],
            'code_callers' => ['count' => count($codeCallers), 'paths' => array_slice($codeCallers, 0, 12)],
            'config' => ['count' => count($config), 'paths' => array_slice($config, 0, 12)],
            'database' => ['count' => count($database), 'paths' => array_slice($database, 0, 12)],
        ];
    }

    /**
     * @param  array<string,array{count:int,paths:array<int,string>}>  $breakdown
     * @return array<int,array<string,string>>
     */
    private function reachabilityEdges(?string $targetPath, array $breakdown): array
    {
        if ($targetPath === null) {
            return [];
        }

        $edges = [];
        foreach ($breakdown as $kind => $group) {
            foreach (array_slice($group['paths'], 0, 5) as $path) {
                if ($path === $targetPath) {
                    continue;
                }
                $edges[] = [
                    'from' => $path,
                    'to' => $targetPath,
                    'kind' => $kind,
                ];
            }
        }

        return $edges;
    }

    /**
     * @param  array<int,string>  $roots
     * @return array<int,string>
     */
    private function scanFor(string $needle, string $basename, array $roots): array
    {
        $terms = array_values(array_filter(array_unique([
            $needle,
            $basename,
            pathinfo($basename, PATHINFO_FILENAME),
            class_basename($needle),
        ]), static fn (string $term): bool => $term !== ''));
        if ($terms === []) {
            return [];
        }

        $matches = [];
        foreach ($this->allFiles($roots) as $file) {
            $path = $file->getPathname();
            if (! $this->isTextFile($path)) {
                continue;
            }

            $contents = File::get($path);
            foreach ($terms as $term) {
                if (str_contains($contents, $term)) {
                    $matches[] = $this->relativePath($path);
                    break;
                }
            }
        }

        sort($matches);

        return array_values(array_unique($matches));
    }

    /**
     * @param  array<int,string>  $roots
     * @return array<int,SplFileInfo>
     */
    private function allFiles(array $roots = self::SEARCH_ROOTS): array
    {
        $files = [];
        foreach ($roots as $root) {
            $path = base_path($root);
            if (! File::isDirectory($path)) {
                continue;
            }

            foreach (File::allFiles($path) as $file) {
                $files[] = $file;
            }
        }

        return $files;
    }

    private function isTextFile(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['php', 'md', 'json', 'yml', 'yaml', 'ts', 'tsx', 'js', 'jsx'], true);
    }

    private function classification(?string $targetPath, array $reachability): string
    {
        if ($targetPath === null) {
            return 'unknown_requires_audit';
        }

        $signals = (array) ($reachability['signals'] ?? []);
        $hasEntrypoint = (bool) ($signals['has_route_entrypoint'] ?? false)
            || (bool) ($signals['has_command_entrypoint'] ?? false);
        $hasTests = (bool) ($signals['has_test_coverage'] ?? false);
        $hasOwner = (bool) ($signals['has_owner_doc'] ?? false);
        $hasCodeCallers = (bool) ($signals['has_service_or_code_callers'] ?? false);
        $confidence = (string) ($reachability['confidence'] ?? 'review_required');

        if ($hasEntrypoint && $hasTests && $hasOwner) {
            return 'active_runtime';
        }

        if ($hasEntrypoint || ($hasTests && $hasOwner) || ($hasCodeCallers && $hasTests)) {
            return 'active_read_only';
        }

        if ($hasOwner && $hasTests) {
            return 'headless_available';
        }

        if (in_array($confidence, ['medium', 'low'], true) || $hasOwner || $hasCodeCallers) {
            return 'unknown_requires_audit';
        }

        return 'unused_candidate';
    }

    /**
     * @param  array<int,string>  $ownerDocs
     * @return array<int,array<string,mixed>>
     */
    private function blockers(string $classification, ?string $targetPath, array $ownerDocs): array
    {
        if ($classification === 'unknown_requires_audit' && $targetPath === null) {
            return [[
                'reason' => 'target_not_found',
                'severity' => 'review',
                'policy' => 'do_not_implement_or_delete_until_owner_lookup_succeeds',
            ]];
        }

        if ($classification === 'unused_candidate' && $ownerDocs === []) {
            return [[
                'reason' => 'unused_candidate_requires_quarantine_review',
                'severity' => 'review',
                'policy' => 'no_delete_without_reference_scan_tests_and_human_approval',
            ]];
        }

        return [];
    }

    /**
     * @return array<int,string>
     */
    private function tokens(string $value): array
    {
        return array_values(array_filter(
            preg_split('/[^a-z0-9]+/', strtolower($value)) ?: [],
            static fn (string $token): bool => strlen($token) >= 4
        ));
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
