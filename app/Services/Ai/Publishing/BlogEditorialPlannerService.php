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
        $suggestCandidates = (bool) ($options['suggest_candidates'] ?? false);
        $acceptCandidate = is_string($options['accept_candidate'] ?? null)
            ? trim((string) $options['accept_candidate'])
            : '';
        $writeAcceptance = (bool) ($options['write'] ?? false);
        $contextLimit = max(1, min(12, (int) ($options['context_limit'] ?? 5)));
        $candidateLimit = max(1, min(30, (int) ($options['candidate_limit'] ?? 10)));

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
        $publishedSlugs = $this->publishedSlugs($siteRoot);
        $publishedSet = array_fill_keys($publishedSlugs, true);
        $findings = $this->validatePosts($posts);

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

        $blocked = collect($enriched)
            ->filter(fn (array $post): bool => ! $post['published'] && ! $post['ready'])
            ->values()
            ->all();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $findings === [] ? 'ready' : 'blocked',
            'mode' => ($withContext || $suggestCandidates || $acceptCandidate !== '') ? 'read_only_governed_p1' : 'read_only_deterministic_p0',
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
                'with_candidate_suggestions' => $suggestCandidates,
                'with_candidate_acceptance' => $acceptCandidate !== '',
            ],
            'next_ready_post' => $nextReady,
            'blocked_posts' => $blocked,
            'posts' => $enriched,
            'backlog_candidates' => $suggestCandidates
                ? $this->contextService()->candidateSuggestions($posts, $publishedSlugs, $candidateLimit)
                : null,
            'candidate_acceptance' => $acceptCandidate !== ''
                ? $this->contextService()->acceptCandidate($siteRoot, $acceptCandidate, $posts, $publishedSlugs, [
                    'write' => $writeAcceptance,
                ])
                : null,
            'findings' => $findings,
            'guardrails' => [
                'read_only' => true,
                'publishes_content' => false,
                'uses_graph_rag' => false,
                'uses_python_runtime' => false,
                'creates_parallel_memory_store' => false,
                'uses_existing_knowledge_read_models' => $withContext,
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
    private function publishedSlugs(string $siteRoot): array
    {
        $siteDataPath = $siteRoot.'/src/data/site.js';
        if (! is_file($siteDataPath)) {
            return [];
        }

        $contents = (string) file_get_contents($siteDataPath);
        if (! preg_match('/export const posts = \[(.*?)\];/s', $contents, $matches)) {
            return [];
        }

        preg_match_all('/slug:\s*[\'"]([^\'"]+)[\'"]/', $matches[1], $slugMatches);

        return array_values(array_unique($slugMatches[1] ?? []));
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
