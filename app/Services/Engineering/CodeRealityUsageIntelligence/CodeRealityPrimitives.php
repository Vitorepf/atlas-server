<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeRealityUsageIntelligence;

use App\Services\Engineering\AtlasCodeRealityUsageIntelligenceService;
use App\Services\Engineering\EngineeringStringListNormalizer;
use FilesystemIterator;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class CodeRealityPrimitives
{
    public const SEARCH_ROOTS = ['app', 'routes', 'config', 'database', 'tests', 'docs/engineering-knowledge-base'];

    private const MAX_SCAN_FILES = 40000;

    private const MAX_SCAN_SECONDS = 15.0;

    private const MAX_FILE_SCAN_BYTES = 768_000;

    /**
     * @var array<string,array{canonical_owner:string,terms:array<int,string>}>
     */
    public const CRITICAL_TOPIC_CLUSTERS = [
        'rag_retrieval' => [
            'canonical_owner' => 'docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md',
            'terms' => ['rag', 'retrieval', 'embedding', 'embeddings', 'vector', 'faiss', 'chroma', 'graph rag', 'local rag'],
        ],
        'voice_realtime' => [
            'canonical_owner' => 'docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md',
            'terms' => ['voice realtime', 'livekit', 'agents sdk', 'tts', 'stt'],
        ],
        'documentation_reality' => [
            'canonical_owner' => 'docs/engineering-knowledge-base/atlas-documentation-reality-system.md',
            'terms' => ['documentation reality', 'docs-health', 'docs-authority', 'acrui', 'aurc'],
        ],
        'frontend_programming' => [
            'canonical_owner' => 'docs/engineering-knowledge-base/domains/programming-frontend-superpower.md',
            'terms' => ['frontend', 'browser bridge', 'anti slop', 'design runtime', 'run certify'],
        ],
        'forge_programming' => [
            'canonical_owner' => 'docs/engineering-knowledge-base/atlas-forge-operating-system.md',
            'terms' => ['forge', 'obra', 'long horizon', 'work packet'],
        ],
    ];


    /**
     * @return array<string,mixed>
     */
    public function documentationInventory(): array
    {
        $docs = [];
        foreach ($this->allFiles(['docs/engineering-knowledge-base']) as $file) {
            $path = $this->relativePath($file->getPathname());
            if (! str_ends_with($path, '.md')) {
                continue;
            }

            $frontmatter = $this->frontmatter($file->getPathname());
            $isCanonical = ($frontmatter['doc_schema'] ?? null) === 'atlas_canonical_module_doc.v1';
            $status = (string) ($frontmatter['status'] ?? 'missing');
            $isArchive = str_contains($path, '/archive/') || str_contains($path, 'archive/source-material/');
            $title = (string) ($frontmatter['title'] ?? basename($path));
            $docs[] = [
                'path' => $path,
                'path_stem' => strtolower(pathinfo($path, PATHINFO_FILENAME)),
                'title' => $title,
                'title_key' => strtolower($title),
                'id' => (string) ($frontmatter['id'] ?? ''),
                'graph_id' => (string) ($frontmatter['graph_id'] ?? ''),
                'owner' => (string) ($frontmatter['owner'] ?? 'unknown'),
                'status' => $status,
                'authority_class' => (string) ($frontmatter['authority_class'] ?? ''),
                'implementation_state' => (string) ($frontmatter['implementation_state'] ?? ''),
                'canonical' => $isCanonical,
                'archive' => $isArchive,
                'critical_topics' => $this->criticalTopicsForFile($file->getPathname()),
            ];
        }

        $nonCanonicalActive = array_values(array_filter(
            $docs,
            static fn (array $doc): bool => $doc['canonical'] === false
                && $doc['archive'] === false
                && ! in_array($doc['status'], ['archived', 'source_material'], true)
        ));
        $archivedSourceMaterial = array_values(array_filter(
            $docs,
            static fn (array $doc): bool => $doc['archive'] === true
        ));
        $canonicalActive = array_values(array_filter(
            $docs,
            static fn (array $doc): bool => $doc['canonical'] === true
                && $doc['archive'] === false
                && in_array($doc['status'], ['active', 'building', 'planned', 'future', 'implemented', 'implemented_ready', 'scaffold'], true)
        ));
        $activeNonArchive = array_values(array_filter(
            $docs,
            static fn (array $doc): bool => $doc['archive'] === false
                && ! in_array($doc['status'], ['archived', 'source_material'], true)
        ));

        return [
            'doc_count' => count($docs),
            'canonical_doc_count' => count(array_filter($docs, static fn (array $doc): bool => $doc['canonical'] === true)),
            'canonical_active_items' => $canonicalActive,
            'active_non_archive_items' => $activeNonArchive,
            'non_canonical_active_count' => count($nonCanonicalActive),
            'non_canonical_active' => $nonCanonicalActive,
            'archived_source_material_count' => count($archivedSourceMaterial),
            'archived_source_material' => $archivedSourceMaterial,
            'critical_topic_noncanonical_samples' => array_slice(array_values(array_filter(
                $nonCanonicalActive,
                static fn (array $doc): bool => $doc['critical_topics'] !== []
            )), 0, 30),
            'items' => $docs,
        ];
    }

    /**
     * @param  array<string,array<int,string>>  $groups
     * @return array<int,array<string,mixed>>
     */
    public function formatPathCountGroups(array $groups, int $sampleLimit = 8): array
    {
        $items = [];
        foreach ($groups as $key => $paths) {
            $items[] = [
                'value' => $key,
                'count' => count($paths),
                'samples' => array_slice(array_values($paths), 0, $sampleLimit),
            ];
        }
        usort($items, static fn (array $a, array $b): int => ((int) $b['count']) <=> ((int) $a['count']));

        return $items;
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,string>
     */
    public function itemStringColumn(array $items, string $key, bool $filterEmpty = false): array
    {
        $values = array_map(
            static fn (array $item): string => (string) ($item[$key] ?? ''),
            $items,
        );

        return $this->uniqueStrings($values, filterEmpty: $filterEmpty);
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,string>
     */
    public function itemStringListColumn(array $items, string $key): array
    {
        return $this->uniqueStrings(array_merge(...array_map(
            static fn (array $item): array => array_map('strval', (array) ($item[$key] ?? [])),
            $items,
        )));
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    public function uniqueStrings(array $values, bool $filterEmpty = false): array
    {
        return $filterEmpty
            ? EngineeringStringListNormalizer::uniqueTruthyStringCasts($values)
            : EngineeringStringListNormalizer::uniqueStringCasts($values);
    }

    /**
     * @param  array<int,array<string,string>>  $items
     * @return array<int,array<string,mixed>>
     */
    public function duplicateGroups(array $items, string $key): array
    {
        $groups = [];
        foreach ($items as $item) {
            $value = (string) ($item[$key] ?? '');
            if ($value === '') {
                continue;
            }
            $groups[strtolower($value)][] = $item;
        }

        return array_values(array_filter(array_map(
            function (array $group, string $value): ?array {
                if (count($group) <= 1) {
                    return null;
                }

                $duplicateGroup = [
                    'value' => $value,
                    'count' => count($group),
                    'paths' => $this->itemStringColumn($group, 'path'),
                    'sample_items' => array_slice($group, 0, 5),
                ];
                $methodUris = $this->itemStringColumn($group, 'method_uri', filterEmpty: true);
                if ($methodUris !== []) {
                    $duplicateGroup['method_uris'] = $methodUris;
                }

                return $duplicateGroup;
            },
            $groups,
            array_keys($groups)
        )));
    }

    /**
     * @return array<string,mixed>
     */
    private function frontmatter(string $path): array
    {
        $content = $this->readSmallFile($path);
        if ($content === null || ! str_starts_with($content, "---\n")) {
            return [];
        }
        $end = strpos($content, "\n---", 4);
        if ($end === false) {
            return [];
        }

        $frontmatter = [];
        foreach (explode("\n", substr($content, 4, $end - 4)) as $line) {
            if (! str_contains($line, ':') || str_starts_with(ltrim($line), '-')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            if ($key !== '' && $value !== '') {
                $frontmatter[$key] = trim($value, '"\'');
            }
        }

        return $frontmatter;
    }

    /**
     * @return array<int,string>
     */
    private function criticalTopicsForFile(string $path): array
    {
        $content = $this->readSmallFile($path);
        if ($content === null) {
            return [];
        }
        $lower = strtolower($content);
        $topics = [];
        foreach (self::CRITICAL_TOPIC_CLUSTERS as $id => $definition) {
            foreach ($definition['terms'] as $term) {
                if (str_contains($lower, strtolower($term))) {
                    $topics[] = $id;
                    break;
                }
            }
        }

        return $topics;
    }

    /**
     * @param  array<int,string>  $terms
     * @return array<int,string>
     */
    public function codeMatchesForTerms(array $terms): array
    {
        $matches = [];
        foreach ($this->allFiles(['app', 'routes', 'config', 'database']) as $file) {
            $path = $file->getPathname();
            if (! $this->isTextFile($path) || ! $this->fileContainsAny($path, $terms)) {
                continue;
            }
            $matches[] = $this->relativePath($path);
        }
        sort($matches);

        return $this->uniqueStrings($matches);
    }

    public function readSmallFile(string $path): ?string
    {
        $size = @filesize($path);
        if ($size !== false && $size > self::MAX_FILE_SCAN_BYTES) {
            return null;
        }

        try {
            return File::get($path);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function envelope(array $payload): array
    {
        $base = array_merge([
            'schema_version' => AtlasCodeRealityUsageIntelligenceService::SCHEMA_VERSION,
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

    public function targetPath(string $target): ?string
    {
        if ($target === '') {
            return null;
        }

        $direct = base_path($target);
        if (File::exists($direct)) {
            return $target;
        }

        $startedAt = microtime(true);
        $visited = 0;
        foreach ($this->allFiles() as $file) {
            if (basename($file->getPathname()) === $target) {
                return $this->relativePath($file->getPathname());
            }
        }

        foreach ($this->allFiles() as $file) {
            $visited++;
            if ($visited > self::MAX_SCAN_FILES || microtime(true) - $startedAt > self::MAX_SCAN_SECONDS) {
                break;
            }
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
    public function references(string $needle, string $basename): array
    {
        return $this->scanFor($needle, $basename, self::SEARCH_ROOTS);
    }

    /**
     * @return array<int,string>
     */
    public function ownerDocs(string $needle, string $basename): array
    {
        return array_values(array_filter(
            $this->scanFor($needle, $basename, ['docs/engineering-knowledge-base']),
            static fn (string $path): bool => str_ends_with($path, '.md')
        ));
    }

    /**
     * @return array<int,string>
     */
    public function testRefs(string $needle, string $basename): array
    {
        return $this->scanFor($needle, $basename, ['tests']);
    }

    /**
     * @return array<int,string>
     */
    public function entrypoints(string $needle, string $basename): array
    {
        return $this->uniqueStrings(array_merge(
            $this->scanFor($needle, $basename, ['routes']),
            array_values(array_filter($this->scanFor($needle, $basename, ['app/Console/Commands']), static fn (string $path): bool => str_contains($path, 'Commands/'))),
        ));
    }

    /**
     * @param  array<int,string>  $references
     * @param  array<int,string>  $ownerDocs
     * @param  array<int,string>  $tests
     * @param  array<int,string>  $entrypoints
     * @return array<string,mixed>
     */
    public function reachabilityEnvelope(?string $targetPath, array $references, array $ownerDocs, array $tests, array $entrypoints, array $constructorInjectors = []): array
    {
        $breakdown = $this->sourceBreakdown($references, $ownerDocs, $tests, $entrypoints, $constructorInjectors);
        $signals = [
            'target_exists' => $targetPath !== null,
            'has_route_entrypoint' => $breakdown['routes']['count'] > 0,
            'has_command_entrypoint' => $breakdown['commands']['count'] > 0,
            'has_test_coverage' => $breakdown['tests']['count'] > 0,
            'has_owner_doc' => $breakdown['owner_docs']['count'] > 0,
            'has_service_or_code_callers' => $breakdown['code_callers']['count'] > 0,
            'has_constructor_injectors' => $breakdown['constructor_injectors']['count'] > 0,
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
            'schema_version' => AtlasCodeRealityUsageIntelligenceService::REACHABILITY_SCHEMA_VERSION,
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
     * @param  array<int,string>  $constructorInjectors
     * @return array<string,array{count:int,paths:array<int,string>}>
     */
    private function sourceBreakdown(array $references, array $ownerDocs, array $tests, array $entrypoints, array $constructorInjectors = []): array
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
            'constructor_injectors' => ['count' => count($constructorInjectors), 'paths' => array_slice($constructorInjectors, 0, 12)],
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
     * DI-aware reachability (Obra #12 lesson): app files that type-hint the class
     * (`Foo $x`) or resolve it (`Foo::class`) are live wiring even without a `use`
     * statement, tests or docs. Target file itself never counts.
     *
     * @return array<int,string>
     */
    public function constructorInjectors(string $basename, ?string $targetPath): array
    {
        $shortName = pathinfo($basename, PATHINFO_FILENAME);
        if ($shortName === '') {
            return [];
        }

        // ponytail: lexical patterns, not AST — a type-hint or ::class of the short name in app/ is the DI signal
        $terms = [$shortName.' $', $shortName.'::class'];

        $matches = [];
        $startedAt = microtime(true);
        $visited = 0;
        foreach ($this->allFiles(['app']) as $file) {
            $visited++;
            if ($visited > self::MAX_SCAN_FILES || microtime(true) - $startedAt > self::MAX_SCAN_SECONDS) {
                break;
            }
            $path = $file->getPathname();
            if (! $this->isTextFile($path)) {
                continue;
            }
            $relative = $this->relativePath($path);
            if ($relative === $targetPath) {
                continue;
            }
            if ($this->fileContainsAny($path, $terms)) {
                $matches[] = $relative;
            }
        }

        sort($matches);

        return $this->uniqueStrings($matches);
    }

    /**
     * @param  array<int,string>  $roots
     * @return array<int,string>
     */
    private function scanFor(string $needle, string $basename, array $roots): array
    {
        $terms = $this->uniqueStrings([
            $needle,
            $basename,
            pathinfo($basename, PATHINFO_FILENAME),
            class_basename($needle),
        ], filterEmpty: true);
        if ($terms === []) {
            return [];
        }

        $matches = [];
        $startedAt = microtime(true);
        $visited = 0;
        foreach ($this->allFiles($roots) as $file) {
            $visited++;
            if ($visited > self::MAX_SCAN_FILES || microtime(true) - $startedAt > self::MAX_SCAN_SECONDS) {
                break;
            }
            $path = $file->getPathname();
            if (! $this->isTextFile($path)) {
                continue;
            }

            if ($this->fileContainsAny($path, $terms)) {
                $matches[] = $this->relativePath($path);
            }
        }

        sort($matches);

        return $this->uniqueStrings($matches);
    }

    /**
     * @param  array<int,string>  $roots
     * @return iterable<int,\SplFileInfo>
     */
    public function allFiles(array $roots = self::SEARCH_ROOTS): iterable
    {
        foreach ($roots as $root) {
            $path = base_path($root);
            if (! File::isDirectory($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    yield $file;
                }
            }
        }
    }

    /**
     * Real class/interface/trait/enum declarations via the PHP tokenizer, so
     * declarations inside string literals/heredocs (test fixtures embedded in
     * commands) never count as duplicates. Skips `Foo::class` and anonymous
     * classes by looking at the surrounding meaningful tokens.
     *
     * @return array<int,string>
     */
    public function declaredPhpTypeNames(string $content): array
    {
        $names = [];
        try {
            $tokens = token_get_all($content);
        } catch (\Throwable) {
            return [];
        }

        $declarationIds = [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM];
        $count = count($tokens);
        $previousMeaningfulId = null;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token)) {
                $previousMeaningfulId = null;

                continue;
            }
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (in_array($token[0], $declarationIds, true) && $previousMeaningfulId !== T_DOUBLE_COLON && $previousMeaningfulId !== T_NEW) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $next = $tokens[$j];
                    if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    if (is_array($next) && $next[0] === T_STRING) {
                        $names[] = (string) $next[1];
                    }
                    break;
                }
            }
            $previousMeaningfulId = $token[0];
        }

        return $names;
    }

    /**
     * @param  array<int,string>  $terms
     */
    private function fileContainsAny(string $path, array $terms): bool
    {
        $size = @filesize($path);
        if ($size !== false && $size > self::MAX_FILE_SCAN_BYTES) {
            return false;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            $bytesRead = 0;
            while (($line = fgets($handle)) !== false) {
                $bytesRead += strlen($line);
                if ($bytesRead > self::MAX_FILE_SCAN_BYTES) {
                    return false;
                }
                foreach ($terms as $term) {
                    if (str_contains($line, $term)) {
                        return true;
                    }
                }
            }
        } catch (\Throwable) {
            return false;
        } finally {
            fclose($handle);
        }

        return false;
    }

    public function isTextFile(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['php', 'md', 'json', 'yml', 'yaml', 'ts', 'tsx', 'js', 'jsx'], true);
    }

    public function classification(?string $targetPath, array $reachability): string
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
        $hasConstructorInjectors = (bool) ($signals['has_constructor_injectors'] ?? false);
        $confidence = (string) ($reachability['confidence'] ?? 'review_required');

        if ($hasEntrypoint && $hasTests && $hasOwner) {
            return 'active_runtime';
        }

        if ($hasEntrypoint || ($hasTests && $hasOwner) || ($hasCodeCallers && $hasTests) || $hasConstructorInjectors) {
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

    public function deletionDecision(string $classification, string $reachabilityStatus): string
    {
        if (in_array($classification, ['active_runtime', 'active_read_only', 'headless_available'], true)) {
            return 'block_delete_active_or_available_target';
        }

        if (in_array($reachabilityStatus, ['reachable', 'weakly_referenced'], true)) {
            return 'block_delete_reachability_present';
        }

        return 'block_delete_until_quarantine_and_human_approval';
    }

    /**
     * @param  array<int,string>  $ownerDocs
     * @return array<int,array<string,mixed>>
     */
    public function blockers(string $classification, ?string $targetPath, array $ownerDocs): array
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
    public function tokens(string $value): array
    {
        return array_values(array_filter(
            preg_split('/[^a-z0-9]+/', strtolower($value)) ?: [],
            static fn (string $token): bool => strlen($token) >= 4
        ));
    }

    /**
     * @param  array<int,string>  $needles
     */
    public function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function relativePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
