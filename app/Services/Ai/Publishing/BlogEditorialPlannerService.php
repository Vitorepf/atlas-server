<?php

declare(strict_types=1);

namespace App\Services\Ai\Publishing;

use Symfony\Component\Yaml\Yaml;

final class BlogEditorialPlannerService
{
    public const SCHEMA_VERSION = 'atlas.blog_editorial_planner.v1';

    public function __construct(
        private readonly ?BlogEditorialContextService $context = null,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function plan(string $siteRoot, string $backlogRelativePath = 'content/backlog/blog-first-month.yaml', array $options = []): array
    {
        $siteRoot = rtrim($siteRoot, '/');
        $backlogPath = $siteRoot.'/'.$backlogRelativePath;
        $withContext = (bool) ($options['with_context'] ?? false);
        $withSourceMap = (bool) ($options['source_map'] ?? false);
        $withReviewQueue = (bool) ($options['review_queue'] ?? false);
        $suggestCandidates = (bool) ($options['suggest_candidates'] ?? false);
        $withCoverageMap = (bool) ($options['coverage_map'] ?? false);
        $withOperations = (bool) ($options['operations'] ?? false);
        $withOperatingState = (bool) ($options['operating_state'] ?? false);
        $withWritingPacket = (bool) ($options['writing_packet'] ?? false);
        $withEditorialRadar = (bool) ($options['editorial_radar'] ?? false);
        $withEditorialGoldenSet = (bool) ($options['editorial_golden_set'] ?? false);
        $withEditorialGraphContext = (bool) ($options['editorial_graph_context'] ?? false);
        $withEditorialGraphCandidates = (bool) ($options['editorial_graph_candidates'] ?? false);
        $withGraphRagReadiness = (bool) ($options['graph_rag_readiness'] ?? false);
        $executeOpenBrain = (bool) ($options['execute_open_brain'] ?? false);
        $writingSlug = is_string($options['writing_slug'] ?? null)
            ? trim((string) $options['writing_slug'])
            : '';
        $acceptCandidate = is_string($options['accept_candidate'] ?? null)
            ? trim((string) $options['accept_candidate'])
            : '';
        $promoteCandidate = is_string($options['promote_candidate'] ?? null)
            ? trim((string) $options['promote_candidate'])
            : '';
        $writeAcceptance = (bool) ($options['write'] ?? false);
        $contextLimit = max(1, min(12, (int) ($options['context_limit'] ?? 5)));
        $candidateLimit = max(1, min(30, (int) ($options['candidate_limit'] ?? 10)));
        $graphContextLimit = max(1, min(12, (int) ($options['graph_context_limit'] ?? 8)));
        $graphWorldModelId = is_string($options['graph_world_model_id'] ?? null)
            ? trim((string) $options['graph_world_model_id'])
            : '';

        if (! is_file($backlogPath)) {
            return $this->failed($siteRoot, $backlogPath, 'backlog_not_found');
        }

        try {
            $backlog = $this->parseBacklogFile($backlogPath);
        } catch (\Throwable $exception) {
            return $this->failed($siteRoot, $backlogPath, 'backlog_yaml_invalid', $exception->getMessage());
        }

        if (! is_array($backlog)) {
            return $this->failed($siteRoot, $backlogPath, 'backlog_shape_invalid');
        }

        $posts = $this->flattenPosts($backlog);
        $publishedPosts = $this->publishedPosts($siteRoot);
        $publishedSlugs = array_values(array_unique(array_filter(array_map(
            fn (array $post): string => (string) ($post['slug'] ?? ''),
            $publishedPosts,
        ))));
        $publishedSet = array_fill_keys($publishedSlugs, true);
        $findings = $this->validatePosts($posts);
        $reviewQueueState = $this->contextService()->reviewQueueState($siteRoot, $posts, $publishedSlugs);
        $queuedSlugs = array_values(array_filter((array) data_get($reviewQueueState, 'queued_slugs', []), 'is_string'));

        $plannedSlugs = array_fill_keys(array_map(
            fn (array $post): string => (string) ($post['slug'] ?? ''),
            $posts,
        ), true);

        $enriched = array_map(function (array $post) use ($publishedSet, $plannedSlugs, $withContext, $contextLimit): array {
            $slug = (string) ($post['slug'] ?? '');
            $prerequisites = array_values(array_filter((array) ($post['prerequisites'] ?? []), 'is_string'));
            $missingPrerequisites = array_values(array_filter(
                $prerequisites,
                fn (string $prerequisite): bool => ! isset($publishedSet[$prerequisite]),
            ));
            $unknownPrerequisites = array_values(array_filter(
                $prerequisites,
                fn (string $prerequisite): bool => ! isset($plannedSlugs[$prerequisite]) && ! isset($publishedSet[$prerequisite]),
            ));

            $payload = [
                'order' => (int) ($post['order'] ?? 0),
                'title' => (string) ($post['title'] ?? ''),
                'slug' => $slug,
                'week' => (int) ($post['week'] ?? 0),
                'week_theme' => (string) ($post['week_theme'] ?? ''),
                'type' => (string) ($post['type'] ?? ''),
                'complexity_level' => (string) ($post['complexity_level'] ?? ''),
                'collection' => (string) ($post['collection'] ?? ''),
                'series' => (string) ($post['series'] ?? ''),
                'main_question' => (string) ($post['main_question'] ?? ''),
                'prerequisites' => $prerequisites,
                'missing_prerequisites' => $missingPrerequisites,
                'unknown_prerequisites' => $unknownPrerequisites,
                'next_reading' => array_values(array_filter((array) ($post['next_reading'] ?? []), 'is_string')),
                'published' => isset($publishedSet[$slug]),
                'ready' => ! isset($publishedSet[$slug]) && $missingPrerequisites === [] && $unknownPrerequisites === [],
                'context_query' => $this->contextQuery($post),
            ];

            if ($withContext) {
                $payload['editorial_context'] = $this->contextService()->contextForPost($post, $contextLimit);
            }

            return $payload;
        }, $posts);

        $nextReady = collect($enriched)
            ->filter(fn (array $post): bool => (bool) $post['ready'])
            ->sortBy('order')
            ->first();
        $writingTarget = $withWritingPacket
            ? $this->writingTargetPost($posts, $writingSlug, is_array($nextReady) ? (string) ($nextReady['slug'] ?? '') : '')
            : null;

        $blocked = collect($enriched)
            ->filter(fn (array $post): bool => ! $post['published'] && ! $post['ready'])
            ->values()
            ->all();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $findings === [] ? 'ready' : 'blocked',
            'mode' => $executeOpenBrain ? 'audited_context_execution_p1' : (($withContext || $withSourceMap || $withReviewQueue || $suggestCandidates || $withCoverageMap || $withOperations || $withOperatingState || $withWritingPacket || $withEditorialRadar || $withEditorialGoldenSet || $withEditorialGraphContext || $withEditorialGraphCandidates || $withGraphRagReadiness || $acceptCandidate !== '' || $promoteCandidate !== '') ? 'read_only_governed_p1' : 'read_only_deterministic_p0'),
            'site_root' => $siteRoot,
            'backlog_path' => $backlogPath,
            'backlog' => [
                'name' => (string) ($backlog['name'] ?? ''),
                'cadence' => (string) ($backlog['cadence'] ?? ''),
                'purpose' => (string) ($backlog['purpose'] ?? ''),
                'publishing_days' => array_values((array) ($backlog['publishing_days'] ?? [])),
                'buffer_days' => array_values((array) ($backlog['buffer_days'] ?? [])),
                'rule' => (string) ($backlog['rule'] ?? ''),
            ],
            'summary' => [
                'planned_posts' => count($enriched),
                'published_posts' => count(array_filter($enriched, fn (array $post): bool => (bool) $post['published'])),
                'ready_posts' => count(array_filter($enriched, fn (array $post): bool => (bool) $post['ready'])),
                'blocked_posts' => count($blocked),
                'finding_count' => count($findings),
                'with_context' => $withContext,
                'with_source_map' => $withSourceMap,
                'with_review_queue' => $withReviewQueue,
                'with_candidate_suggestions' => $suggestCandidates,
                'with_coverage_map' => $withCoverageMap,
                'with_operations_packet' => $withOperations,
                'with_operating_state' => $withOperatingState,
                'with_writing_packet' => $withWritingPacket,
                'with_editorial_radar' => $withEditorialRadar,
                'with_editorial_golden_set' => $withEditorialGoldenSet,
                'with_editorial_graph_context' => $withEditorialGraphContext,
                'with_editorial_graph_candidates' => $withEditorialGraphCandidates,
                'with_graph_rag_readiness' => $withGraphRagReadiness,
                'with_open_brain_execution' => $executeOpenBrain,
                'with_candidate_acceptance' => $acceptCandidate !== '',
                'with_candidate_promotion' => $promoteCandidate !== '',
            ],
            'next_ready_post' => $nextReady,
            'blocked_posts' => $blocked,
            'posts' => $enriched,
            'source_map' => $withSourceMap
                ? $this->contextService()->sourceMap($posts, $publishedSlugs, $publishedPosts)
                : null,
            'review_queue' => $withReviewQueue
                ? $reviewQueueState
                : null,
            'backlog_candidates' => $suggestCandidates
                ? $this->contextService()->candidateSuggestions($posts, $publishedSlugs, $candidateLimit, $queuedSlugs)
                : null,
            'coverage_map' => $withCoverageMap
                ? $this->contextService()->coverageMap($posts, $publishedSlugs)
                : null,
            'operations_packet' => $withOperations
                ? $this->contextService()->operationsPacket($posts, $publishedSlugs, $publishedPosts, is_array($nextReady) ? $nextReady : null, $blocked, $contextLimit, $candidateLimit, $executeOpenBrain, $reviewQueueState)
                : null,
            'operating_state' => $withOperatingState
                ? $this->contextService()->operatingState($posts, $publishedSlugs, $publishedPosts, is_array($nextReady) ? $nextReady : null, $blocked, $reviewQueueState, $candidateLimit)
                : null,
            'editorial_radar' => $withEditorialRadar
                ? $this->contextService()->editorialRadar($posts, $publishedSlugs, $publishedPosts, is_array($nextReady) ? $nextReady : null, $candidateLimit, $reviewQueueState)
                : null,
            'editorial_golden_set' => $withEditorialGoldenSet
                ? $this->contextService()->editorialGoldenSet($posts, $publishedSlugs, $publishedPosts)
                : null,
            'editorial_graph_context' => $withEditorialGraphContext
                ? $this->contextService()->editorialGraphContext($posts, $publishedSlugs, $publishedPosts, is_array($nextReady) ? $nextReady : null, $graphContextLimit, $graphWorldModelId)
                : null,
            'editorial_graph_candidates' => $withEditorialGraphCandidates
                ? $this->contextService()->editorialGraphCandidates($posts, $publishedSlugs, $publishedPosts, is_array($nextReady) ? $nextReady : null, $graphContextLimit, $candidateLimit, $graphWorldModelId, $queuedSlugs)
                : null,
            'graph_rag_readiness' => $withGraphRagReadiness
                ? $this->contextService()->graphRagReadiness($posts, $publishedSlugs, $publishedPosts)
                : null,
            'writing_packet' => $withWritingPacket
                ? (
                    is_array($writingTarget)
                        ? $this->contextService()->writingPacket($writingTarget, $posts, $publishedSlugs, $contextLimit, $publishedPosts, $executeOpenBrain)
                        : [
                            'schema_version' => 'atlas.blog_editorial_writing_packet.v1',
                            'status' => 'failed',
                            'error' => $writingSlug !== '' ? 'writing_slug_not_found' : 'next_ready_post_not_found',
                            'requested_slug' => $writingSlug,
                            'guardrails' => [
                                'read_only' => true,
                                'writes_draft' => false,
                                'publishes_content' => false,
                            ],
                        ]
                )
                : null,
            'candidate_acceptance' => $acceptCandidate !== ''
                ? $this->contextService()->acceptCandidate($siteRoot, $acceptCandidate, $posts, $publishedSlugs, [
                    'write' => $writeAcceptance,
                    'include_graph_candidates' => $withEditorialGraphCandidates,
                    'published_posts' => $publishedPosts,
                    'next_ready_post' => is_array($nextReady) ? $nextReady : null,
                    'graph_context_limit' => $graphContextLimit,
                    'candidate_limit' => $candidateLimit,
                    'graph_world_model_id' => $graphWorldModelId,
                ])
                : null,
            'candidate_promotion' => $promoteCandidate !== ''
                ? $this->contextService()->promoteQueuedCandidate($siteRoot, $backlogPath, $promoteCandidate, $posts, $publishedSlugs, [
                    'write' => $writeAcceptance,
                    'promotion_after' => $options['promotion_after'] ?? null,
                ])
                : null,
            'findings' => $findings,
            'guardrails' => [
                'read_only' => true,
                'publishes_content' => false,
                'uses_graph_rag' => false,
                'uses_python_runtime' => false,
                'creates_parallel_memory_store' => false,
                'uses_existing_knowledge_read_models' => $withContext || $withSourceMap || $withOperatingState || $withGraphRagReadiness,
                'executes_open_brain_context' => $executeOpenBrain,
                'writes_audit_log' => $executeOpenBrain,
                'requires_human_approval_to_publish' => true,
                'runtime_upgrade_contract' => 'docs/ap/AP-817-blog-editorial-planning-contract.md',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function parseBacklogFile(string $path): array
    {
        if (class_exists(Yaml::class)) {
            $parsed = Yaml::parseFile($path);

            return is_array($parsed) ? $parsed : [];
        }

        if (function_exists('yaml_parse_file')) {
            $parsed = yaml_parse_file($path);

            return is_array($parsed) ? $parsed : [];
        }

        $raw = (string) file_get_contents($path);
        $lines = collect(preg_split('/\R/', $raw) ?: [])
            ->map(fn (string $line): string => rtrim($line))
            ->filter(fn (string $line): bool => trim($line) !== '' && ! str_starts_with(ltrim($line), '#'))
            ->values()
            ->all();

        $index = 0;
        $parsed = $this->parseYamlBlock($lines, $index, 0);

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * @param  array<int,string>  $lines
     * @return mixed
     */
    private function parseYamlBlock(array $lines, int &$index, int $indent): mixed
    {
        if ($index >= count($lines)) {
            return [];
        }

        $current = $lines[$index];
        $currentIndent = strlen($current) - strlen(ltrim($current, ' '));
        if ($currentIndent < $indent) {
            return [];
        }

        return str_starts_with(ltrim($current), '- ')
            ? $this->parseYamlList($lines, $index, $currentIndent)
            : $this->parseYamlMap($lines, $index, $currentIndent);
    }

    /**
     * @param  array<int,string>  $lines
     * @return array<string,mixed>
     */
    private function parseYamlMap(array $lines, int &$index, int $indent): array
    {
        $result = [];

        while ($index < count($lines)) {
            $line = $lines[$index];
            $lineIndent = strlen($line) - strlen(ltrim($line, ' '));
            if ($lineIndent < $indent || str_starts_with(ltrim($line), '- ')) {
                break;
            }
            if ($lineIndent > $indent) {
                $index++;
                continue;
            }

            $trimmed = trim($line);
            if (! preg_match('/^([^:]+):(.*)$/', $trimmed, $matches)) {
                $index++;
                continue;
            }

            $key = trim($matches[1], " \t\"'");
            $value = trim($matches[2]);
            $index++;

            $result[$key] = $value === ''
                ? $this->parseYamlBlock($lines, $index, $indent + 2)
                : $this->parseYamlScalar($value);
        }

        return $result;
    }

    /**
     * @param  array<int,string>  $lines
     * @return array<int,mixed>
     */
    private function parseYamlList(array $lines, int &$index, int $indent): array
    {
        $result = [];

        while ($index < count($lines)) {
            $line = $lines[$index];
            $lineIndent = strlen($line) - strlen(ltrim($line, ' '));
            $trimmed = ltrim($line);

            if ($lineIndent !== $indent || ! str_starts_with($trimmed, '- ')) {
                break;
            }

            $content = trim(substr($trimmed, 2));
            $index++;

            if ($content === '') {
                $result[] = $this->parseYamlBlock($lines, $index, $indent + 2);
                continue;
            }

            if (preg_match('/^([^:]+):(.*)$/', $content, $matches)) {
                $key = trim($matches[1], " \t\"'");
                $value = trim($matches[2]);
                $item = [
                    $key => $value === '' ? [] : $this->parseYamlScalar($value),
                ];

                if ($index < count($lines)) {
                    $nextIndent = strlen($lines[$index]) - strlen(ltrim($lines[$index], ' '));
                    if ($nextIndent > $indent) {
                        $child = $this->parseYamlBlock($lines, $index, $indent + 2);
                        if (is_array($child)) {
                            $item = array_merge($item, $child);
                        }
                    }
                }

                $result[] = $item;
                continue;
            }

            $result[] = $this->parseYamlScalar($content);
        }

        return $result;
    }

    private function parseYamlScalar(string $value): mixed
    {
        $value = trim($value);

        if ($value === '[]') {
            return [];
        }

        if ($value === 'true' || $value === 'false') {
            return $value === 'true';
        }

        if (preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        return trim($value, " \t\"'");
    }

    /**
     * @param  array<string,mixed>  $backlog
     * @return array<int,array<string,mixed>>
     */
    private function flattenPosts(array $backlog): array
    {
        $posts = [];

        foreach ((array) ($backlog['weeks'] ?? []) as $week) {
            if (! is_array($week)) {
                continue;
            }

            foreach ((array) ($week['posts'] ?? []) as $post) {
                if (! is_array($post)) {
                    continue;
                }

                $post['week'] = (int) ($week['week'] ?? 0);
                $post['week_theme'] = (string) ($week['theme'] ?? '');
                $posts[] = $post;
            }
        }

        usort($posts, fn (array $a, array $b): int => ((int) ($a['order'] ?? 0)) <=> ((int) ($b['order'] ?? 0)));

        return $posts;
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @return array<int,array<string,mixed>>
     */
    private function validatePosts(array $posts): array
    {
        $findings = [];
        $slugToOrder = [];

        foreach ($posts as $index => $post) {
            $expectedOrder = $index + 1;
            $order = (int) ($post['order'] ?? 0);
            $slug = (string) ($post['slug'] ?? '');

            if ($order !== $expectedOrder) {
                $findings[] = [
                    'code' => 'order_gap_or_mismatch',
                    'severity' => 'high',
                    'post' => $slug,
                    'expected_order' => $expectedOrder,
                    'actual_order' => $order,
                ];
            }

            if ($slug === '') {
                $findings[] = [
                    'code' => 'missing_slug',
                    'severity' => 'high',
                    'order' => $order,
                ];
            } elseif (isset($slugToOrder[$slug])) {
                $findings[] = [
                    'code' => 'duplicate_slug',
                    'severity' => 'high',
                    'post' => $slug,
                    'first_order' => $slugToOrder[$slug],
                    'duplicate_order' => $order,
                ];
            }

            $slugToOrder[$slug] = $order;
        }

        foreach ($posts as $post) {
            $order = (int) ($post['order'] ?? 0);
            $slug = (string) ($post['slug'] ?? '');

            foreach ((array) ($post['prerequisites'] ?? []) as $prerequisite) {
                if (! is_string($prerequisite)) {
                    continue;
                }

                if (! isset($slugToOrder[$prerequisite])) {
                    $findings[] = [
                        'code' => 'unknown_prerequisite',
                        'severity' => 'high',
                        'post' => $slug,
                        'prerequisite' => $prerequisite,
                    ];
                    continue;
                }

                if ($slugToOrder[$prerequisite] >= $order) {
                    $findings[] = [
                        'code' => 'future_prerequisite',
                        'severity' => 'high',
                        'post' => $slug,
                        'prerequisite' => $prerequisite,
                        'post_order' => $order,
                        'prerequisite_order' => $slugToOrder[$prerequisite],
                    ];
                }
            }
        }

        return $findings;
    }

    /**
     * @return array<int,string>
     */
    private function publishedPosts(string $siteRoot): array
    {
        $siteDataPath = $siteRoot.'/src/data/site.js';
        if (! is_file($siteDataPath)) {
            return [];
        }

        $contents = (string) file_get_contents($siteDataPath);
        if (! preg_match('/export const posts = \[(.*?)\];/s', $contents, $matches)) {
            return [];
        }

        $posts = [];
        foreach ($this->topLevelObjectBlocks($matches[1]) as $block) {
            $slug = $this->jsStringField($block, 'slug');
            if ($slug === '') {
                continue;
            }

            $posts[] = [
                'slug' => $slug,
                'kind' => $this->jsNullableStringField($block, 'kind'),
                'date' => $this->jsNullableStringField($block, 'date'),
                'reading' => $this->jsIntField($block, 'reading'),
                'collection' => $this->jsNullableStringField($block, 'collection'),
                'series' => $this->jsNullableStringField($block, 'series'),
                'series_index' => $this->jsIntField($block, 'seriesIndex'),
                'original' => $this->jsNullableStringField($block, 'original'),
                'num' => $this->jsNullableStringField($block, 'num'),
                'tags' => $this->jsStringArrayField($block, 'tags'),
                'title_pt' => $this->jsLocalizedTitle($block, 'pt'),
                'title_en' => $this->jsLocalizedTitle($block, 'en'),
            ];
        }

        return $posts;
    }

    /**
     * @return array<int,string>
     */
    private function publishedSlugs(string $siteRoot): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (array $post): string => (string) ($post['slug'] ?? ''),
            $this->publishedPosts($siteRoot),
        ))));
    }

    /**
     * @return array<int,string>
     */
    private function topLevelObjectBlocks(string $source): array
    {
        $objects = [];
        $depth = 0;
        $start = null;
        $quote = null;
        $escaped = false;
        $length = strlen($source);

        for ($i = 0; $i < $length; $i++) {
            $char = $source[$i];

            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }

            if ($char === '{') {
                if ($depth === 0) {
                    $start = $i;
                }
                $depth++;
                continue;
            }

            if ($char === '}') {
                $depth--;
                if ($depth === 0 && $start !== null) {
                    $objects[] = substr($source, $start, $i - $start + 1);
                    $start = null;
                }
            }
        }

        return $objects;
    }

    private function jsStringField(string $block, string $field): string
    {
        return $this->jsNullableStringField($block, $field) ?? '';
    }

    private function jsNullableStringField(string $block, string $field): ?string
    {
        if (! preg_match('/\b'.preg_quote($field, '/').'\s*:\s*([\'"])(.*?)\1/s', $block, $matches)) {
            return null;
        }

        return stripcslashes((string) $matches[2]);
    }

    private function jsIntField(string $block, string $field): ?int
    {
        if (! preg_match('/\b'.preg_quote($field, '/').'\s*:\s*(\d+)/', $block, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * @return array<int,string>
     */
    private function jsStringArrayField(string $block, string $field): array
    {
        if (! preg_match('/\b'.preg_quote($field, '/').'\s*:\s*\[(.*?)\]/s', $block, $matches)) {
            return [];
        }

        preg_match_all('/([\'"])(.*?)\1/s', (string) $matches[1], $items);

        return array_values(array_map(
            fn (string $item): string => stripcslashes($item),
            $items[2] ?? [],
        ));
    }

    private function jsLocalizedTitle(string $block, string $locale): ?string
    {
        if (preg_match('/\b'.preg_quote($locale, '/').'\s*:\s*\{.*?\btitle\s*:\s*([\'"])(.*?)\1/s', $block, $matches)) {
            return stripcslashes((string) $matches[2]);
        }

        if ($locale === 'pt' && preg_match('/\btitle\s*:\s*\{\s*\bpt\s*:\s*([\'"])(.*?)\1/s', $block, $matches)) {
            return stripcslashes((string) $matches[2]);
        }

        if ($locale === 'en' && preg_match('/\btitle\s*:\s*\{.*?\ben\s*:\s*([\'"])(.*?)\1/s', $block, $matches)) {
            return stripcslashes((string) $matches[2]);
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $post
     */
    private function contextQuery(array $post): string
    {
        $parts = array_filter([
            (string) ($post['title'] ?? ''),
            (string) ($post['main_question'] ?? ''),
            implode(' ', array_filter((array) ($post['topics'] ?? []), 'is_string')),
        ]);

        return trim(implode(' ', $parts));
    }

    private function contextService(): BlogEditorialContextService
    {
        return $this->context ?? app(BlogEditorialContextService::class);
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @return array<string,mixed>|null
     */
    private function writingTargetPost(array $posts, string $requestedSlug, string $nextReadySlug): ?array
    {
        $targetSlug = $requestedSlug !== '' ? $requestedSlug : $nextReadySlug;
        if ($targetSlug === '') {
            return null;
        }

        foreach ($posts as $post) {
            if ((string) ($post['slug'] ?? '') === $targetSlug) {
                return $post;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function failed(string $siteRoot, string $backlogPath, string $error, ?string $detail = null): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'failed',
            'mode' => 'read_only_deterministic_p0',
            'site_root' => $siteRoot,
            'backlog_path' => $backlogPath,
            'error' => $error,
            'detail' => $detail,
            'guardrails' => [
                'read_only' => true,
                'publishes_content' => false,
                'uses_graph_rag' => false,
                'uses_python_runtime' => false,
                'creates_parallel_memory_store' => false,
            ],
        ];
    }
}
