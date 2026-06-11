<?php

declare(strict_types=1);

namespace App\Services\Ai\Publishing;

use App\Models\AtlasEngineeringCodeModule;
use App\Models\AtlasEngineeringCodeSymbol;
use App\Models\AtlasEngineeringKnowledgeItem;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class BlogEditorialContextService
{
    public const SCHEMA_VERSION = 'atlas.blog_editorial_context.v1';

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function acceptCandidate(string $siteRoot, string $candidateSlug, array $posts, array $publishedSlugs = [], array $options = []): array
    {
        $siteRoot = rtrim($siteRoot, '/');
        $write = (bool) ($options['write'] ?? false);
        $relativePath = (string) ($options['review_queue'] ?? 'content/backlog/blog-candidates.yaml');
        $targetPath = $siteRoot.'/'.$relativePath;
        $existing = $this->existingEditorialIndex($posts, $publishedSlugs);
        if (isset($existing['slugs'][$candidateSlug])) {
            return [
                'schema_version' => 'atlas.blog_editorial_candidate_acceptance.v1',
                'status' => 'failed',
                'error' => 'candidate_already_in_backlog',
                'candidate_slug' => $candidateSlug,
                'target_path' => $targetPath,
                'write' => $write,
            ];
        }

        $candidate = $this->candidateBySlug($candidateSlug, $posts);

        if (! is_array($candidate)) {
            return [
                'schema_version' => 'atlas.blog_editorial_candidate_acceptance.v1',
                'status' => 'failed',
                'error' => 'candidate_not_found',
                'candidate_slug' => $candidateSlug,
                'target_path' => $targetPath,
                'write' => $write,
            ];
        }

        $entry = $this->reviewQueueEntry($candidate);
        $snippet = $this->reviewQueueSnippet($entry);
        $alreadyQueued = is_file($targetPath)
            && str_contains((string) file_get_contents($targetPath), 'slug: "'.$this->escapeYamlString((string) $candidate['slug']).'"');

        if ($write && ! $alreadyQueued) {
            File::ensureDirectoryExists(dirname($targetPath));
            if (! is_file($targetPath)) {
                File::put($targetPath, "name: \"Blog candidate review queue\"\nstatus: \"review\"\ncandidates:\n");
            }
            File::append($targetPath, $snippet);
        }

        return [
            'schema_version' => 'atlas.blog_editorial_candidate_acceptance.v1',
            'status' => $alreadyQueued ? 'already_queued' : 'ready',
            'mode' => $write ? 'explicit_write_review_queue_p1' : 'dry_run_review_queue_p1',
            'candidate_slug' => $candidateSlug,
            'target_path' => $targetPath,
            'write' => $write,
            'already_queued' => $alreadyQueued,
            'candidate' => $candidate,
            'review_queue_entry' => $entry,
            'yaml_snippet' => $snippet,
            'guardrails' => [
                'writes_main_backlog' => false,
                'writes_review_queue' => $write && ! $alreadyQueued,
                'requires_human_approval_to_promote' => true,
                'uses_graph_rag' => false,
                'uses_python_runtime' => false,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function promoteQueuedCandidate(string $siteRoot, string $backlogPath, string $candidateSlug, array $posts, array $publishedSlugs = [], array $options = []): array
    {
        $siteRoot = rtrim($siteRoot, '/');
        $write = (bool) ($options['write'] ?? false);
        $reviewQueueRelativePath = (string) ($options['review_queue'] ?? 'content/backlog/blog-candidates.yaml');
        $reviewQueuePath = $siteRoot.'/'.$reviewQueueRelativePath;
        $existing = $this->existingEditorialIndex($posts, $publishedSlugs);

        if (isset($existing['slugs'][$candidateSlug])) {
            return [
                'schema_version' => 'atlas.blog_editorial_candidate_promotion.v1',
                'status' => 'failed',
                'error' => 'candidate_already_in_main_backlog',
                'candidate_slug' => $candidateSlug,
                'backlog_path' => $backlogPath,
                'review_queue_path' => $reviewQueuePath,
                'write' => $write,
            ];
        }

        if (! is_file($reviewQueuePath)) {
            return [
                'schema_version' => 'atlas.blog_editorial_candidate_promotion.v1',
                'status' => 'failed',
                'error' => 'review_queue_not_found',
                'candidate_slug' => $candidateSlug,
                'backlog_path' => $backlogPath,
                'review_queue_path' => $reviewQueuePath,
                'write' => $write,
            ];
        }

        if (! is_file($backlogPath)) {
            return [
                'schema_version' => 'atlas.blog_editorial_candidate_promotion.v1',
                'status' => 'failed',
                'error' => 'backlog_not_found',
                'candidate_slug' => $candidateSlug,
                'backlog_path' => $backlogPath,
                'review_queue_path' => $reviewQueuePath,
                'write' => $write,
            ];
        }

        $entry = $this->queuedCandidateEntry($reviewQueuePath, $candidateSlug);

        if ($entry === null) {
            return [
                'schema_version' => 'atlas.blog_editorial_candidate_promotion.v1',
                'status' => 'failed',
                'error' => 'candidate_not_in_review_queue',
                'candidate_slug' => $candidateSlug,
                'backlog_path' => $backlogPath,
                'review_queue_path' => $reviewQueuePath,
                'write' => $write,
            ];
        }

        $maxOrder = max([0, ...array_map(fn (array $post): int => (int) ($post['order'] ?? 0), $posts)]);
        $maxWeek = max([0, ...array_map(fn (array $post): int => (int) ($post['week'] ?? 0), $posts)]);
        $fallbackAfter = $this->lastPostSlug($posts);
        $promotionAfter = trim((string) ($options['promotion_after'] ?? ''));
        $promotionAfter = $promotionAfter !== '' ? $promotionAfter : (string) ($entry['suggested_after_slug'] ?? '');
        $promotionAfter = $promotionAfter !== '' ? $promotionAfter : $fallbackAfter;

        if ($promotionAfter !== '' && ! isset($existing['slugs'][$promotionAfter])) {
            return [
                'schema_version' => 'atlas.blog_editorial_candidate_promotion.v1',
                'status' => 'failed',
                'error' => 'promotion_after_not_found',
                'candidate_slug' => $candidateSlug,
                'promotion_after_slug' => $promotionAfter,
                'backlog_path' => $backlogPath,
                'review_queue_path' => $reviewQueuePath,
                'write' => $write,
            ];
        }

        $post = [
            'order' => $maxOrder + 1,
            'title' => (string) ($entry['title'] ?? ''),
            'slug' => (string) ($entry['slug'] ?? $candidateSlug),
            'type' => 'essay',
            'complexity_level' => (string) ($entry['complexity_level'] ?? 'L1'),
            'collection' => (string) ($entry['collection'] ?? 'atlas'),
            'series' => (string) ($entry['series'] ?? 'building-atlas'),
            'reader_level' => $this->readerLevelForComplexity((string) ($entry['complexity_level'] ?? 'L1')),
            'goal' => 'Transformar candidato revisado em texto publico sem quebrar a sequencia editorial.',
            'main_question' => (string) ($entry['main_question'] ?? ''),
            'prerequisites' => $promotionAfter !== '' ? [$promotionAfter] : [],
            'next_reading' => [],
            'topics' => array_values(array_filter((array) ($entry['topics'] ?? []), 'is_string')),
        ];

        $week = [
            'week' => $maxWeek + 1,
            'theme' => 'Fila revisada',
            'goal' => 'Candidatos aceitos para planejamento editorial futuro.',
            'post' => $post,
        ];
        $snippet = $this->promotionWeekSnippet($week);

        if ($write) {
            $current = (string) file_get_contents($backlogPath);
            File::put($backlogPath, rtrim($current)."\n".$snippet);
        }

        return [
            'schema_version' => 'atlas.blog_editorial_candidate_promotion.v1',
            'status' => 'ready',
            'mode' => $write ? 'explicit_write_main_backlog_p1' : 'dry_run_main_backlog_p1',
            'candidate_slug' => $candidateSlug,
            'promotion_after_slug' => $promotionAfter,
            'new_week' => $week['week'],
            'new_order' => $post['order'],
            'backlog_path' => $backlogPath,
            'review_queue_path' => $reviewQueuePath,
            'write' => $write,
            'candidate' => $entry,
            'promoted_post' => $post,
            'yaml_snippet' => $snippet,
            'guardrails' => [
                'writes_main_backlog' => $write,
                'writes_review_queue' => false,
                'removes_from_review_queue' => false,
                'append_only_backlog_update' => true,
                'requires_human_approval_to_publish' => true,
                'uses_graph_rag' => false,
                'uses_python_runtime' => false,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @return array<string,mixed>
     */
    public function candidateSuggestions(array $posts, array $publishedSlugs = [], int $limit = 10): array
    {
        $limit = max(1, min(30, $limit));
        $existing = $this->existingEditorialIndex($posts, $publishedSlugs);
        $candidates = [];

        foreach ($this->knowledgeCandidateRows($limit * 3) as $row) {
            $candidate = $this->candidateFromKnowledgeRow($row, $posts);
            if ($candidate === null || isset($existing['slugs'][$candidate['slug']])) {
                continue;
            }
            if (isset($existing['titles'][$this->normalizedTitle((string) $candidate['title'])])) {
                continue;
            }

            $existing['slugs'][$candidate['slug']] = true;
            $existing['titles'][$this->normalizedTitle((string) $candidate['title'])] = true;
            $candidates[] = $candidate;

            if (count($candidates) >= $limit) {
                break;
            }
        }

        if (count($candidates) < $limit) {
            foreach ($this->moduleCandidateRows($limit * 2) as $row) {
                $candidate = $this->candidateFromModuleRow($row, $posts);
                if ($candidate === null || isset($existing['slugs'][$candidate['slug']])) {
                    continue;
                }
                if (isset($existing['titles'][$this->normalizedTitle((string) $candidate['title'])])) {
                    continue;
                }

                $existing['slugs'][$candidate['slug']] = true;
                $existing['titles'][$this->normalizedTitle((string) $candidate['title'])] = true;
                $candidates[] = $candidate;

                if (count($candidates) >= $limit) {
                    break;
                }
            }
        }

        return [
            'schema_version' => 'atlas.blog_editorial_candidates.v1',
            'mode' => 'read_only_review_queue_p1',
            'status' => 'ready',
            'candidate_count' => count($candidates),
            'candidates' => array_values($candidates),
            'guardrails' => [
                'read_only' => true,
                'writes_backlog' => false,
                'requires_human_approval' => true,
                'uses_graph_rag' => false,
                'uses_python_runtime' => false,
                'creates_parallel_memory_store' => false,
                'source_of_truth' => 'existing_atlas_engineering_knowledge_and_code_intelligence_read_models',
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @return array<string,mixed>
     */
    public function coverageMap(array $posts, array $publishedSlugs = []): array
    {
        $publishedSet = array_fill_keys($publishedSlugs, true);
        $plannedSlugs = array_values(array_filter(array_map(
            fn (array $post): string => (string) ($post['slug'] ?? ''),
            $posts,
        )));
        $plannedSet = array_fill_keys($plannedSlugs, true);
        $foundation = $this->foundationCoverage($plannedSet, $publishedSet);
        $topicIndex = $this->topicIndex($posts, $publishedSet);
        $levelCounts = $this->countByField($posts, 'complexity_level');
        $collectionCounts = $this->countByField($posts, 'collection');
        $seriesCounts = $this->countByField($posts, 'series');
        $warnings = $this->deepSequenceWarnings($posts);

        return [
            'schema_version' => 'atlas.blog_editorial_coverage_map.v1',
            'mode' => 'read_only_sequence_intelligence_p1',
            'status' => 'ready',
            'summary' => [
                'planned_posts' => count($posts),
                'published_posts' => count(array_filter($posts, fn (array $post): bool => isset($publishedSet[(string) ($post['slug'] ?? '')]))),
                'foundation_items' => count($foundation),
                'foundation_planned' => count(array_filter($foundation, fn (array $item): bool => (bool) $item['planned'])),
                'foundation_published' => count(array_filter($foundation, fn (array $item): bool => (bool) $item['published'])),
                'deep_sequence_warning_count' => count($warnings),
            ],
            'foundation_ladder' => $foundation,
            'topic_index' => $topicIndex,
            'complexity_distribution' => $levelCounts,
            'collection_distribution' => $collectionCounts,
            'series_distribution' => $seriesCounts,
            'deep_sequence_warnings' => $warnings,
            'next_safe_arcs' => $this->nextSafeArcs($foundation, $plannedSet),
            'guardrails' => [
                'read_only' => true,
                'writes_backlog' => false,
                'publishes_content' => false,
                'uses_graph_rag' => false,
                'uses_python_runtime' => false,
                'purpose' => 'Show what the public archive already covers before suggesting deeper posts.',
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @param  array<int,array<string,mixed>>  $publishedPosts
     * @return array<string,mixed>
     */
    public function sourceMap(array $posts, array $publishedSlugs = [], array $publishedPosts = []): array
    {
        $publishedSet = array_fill_keys($publishedSlugs, true);
        $plannedSlugs = array_values(array_filter(array_map(
            fn (array $post): string => (string) ($post['slug'] ?? ''),
            $posts,
        )));
        $plannedSet = array_fill_keys($plannedSlugs, true);
        $plannedPublishedCount = count(array_filter(
            $plannedSlugs,
            fn (string $slug): bool => isset($publishedSet[$slug]),
        ));
        $externalPublishedCount = count(array_filter(
            $publishedSlugs,
            fn (string $slug): bool => ! isset($plannedSet[$slug]),
        ));
        $readyCount = 0;
        $blockedCount = 0;

        foreach ($posts as $post) {
            $slug = (string) ($post['slug'] ?? '');
            if ($slug === '' || isset($publishedSet[$slug])) {
                continue;
            }

            $prerequisites = array_values(array_filter((array) ($post['prerequisites'] ?? []), 'is_string'));
            $missingPrerequisites = array_values(array_filter(
                $prerequisites,
                fn (string $prerequisite): bool => ! isset($publishedSet[$prerequisite]),
            ));
            $unknownPrerequisites = array_values(array_filter(
                $prerequisites,
                fn (string $prerequisite): bool => ! isset($plannedSet[$prerequisite]) && ! isset($publishedSet[$prerequisite]),
            ));

            if ($missingPrerequisites === [] && $unknownPrerequisites === []) {
                $readyCount++;
            } else {
                $blockedCount++;
            }
        }

        $knowledgeAvailable = DatabaseTableAvailability::has('atlas_engineering_knowledge_items');
        $modulesAvailable = DatabaseTableAvailability::has('atlas_engineering_code_modules');
        $symbolsAvailable = DatabaseTableAvailability::has('atlas_engineering_code_symbols');
        $archiveReconciliation = $this->archiveReconciliation($posts, $publishedSlugs, $publishedPosts);

        return [
            'schema_version' => 'atlas.blog_editorial_source_map.v1',
            'mode' => 'read_only_source_readiness_p1',
            'status' => 'ready',
            'archive_state' => [
                'planned_posts' => count($posts),
                'planned_published_posts' => $plannedPublishedCount,
                'public_archive_posts' => count($publishedSlugs),
                'external_published_posts' => $externalPublishedCount,
                'ready_unpublished_posts' => $readyCount,
                'blocked_unpublished_posts' => $blockedCount,
                'rule' => 'Published and planned state must gate depth before Atlas context is used for new posts.',
            ],
            'archive_reconciliation' => $archiveReconciliation,
            'sources' => [
                'site_backlog' => [
                    'status' => 'ready',
                    'role' => 'primary_public_sequence',
                    'planned_count' => count($posts),
                    'writes_allowed' => false,
                ],
                'public_site_archive' => [
                    'status' => 'ready',
                    'role' => 'published_state',
                    'published_count' => count($publishedSlugs),
                    'planned_published_count' => $plannedPublishedCount,
                    'external_published_count' => $externalPublishedCount,
                    'metadata_available' => $publishedPosts !== [],
                    'writes_allowed' => false,
                ],
                'engineering_knowledge' => [
                    'status' => $knowledgeAvailable ? 'ready' : 'unavailable',
                    'role' => 'canonical_docs_reference',
                    'table' => 'atlas_engineering_knowledge_items',
                    'row_count' => $knowledgeAvailable ? AtlasEngineeringKnowledgeItem::query()->count() : 0,
                    'writes_allowed' => false,
                ],
                'code_intelligence' => [
                    'status' => ($modulesAvailable || $symbolsAvailable) ? 'ready' : 'unavailable',
                    'role' => 'code_reality_reference',
                    'module_count' => $modulesAvailable ? AtlasEngineeringCodeModule::query()->count() : 0,
                    'symbol_count' => $symbolsAvailable ? AtlasEngineeringCodeSymbol::query()->count() : 0,
                    'writes_allowed' => false,
                ],
                'open_brain_context_pack' => [
                    'status' => 'available_contract',
                    'role' => 'provider_safe_context_composition',
                    'invocation' => 'future_kernel_or_cli_handoff',
                    'invoked_by_this_command' => false,
                    'owner_doc' => 'docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md',
                ],
                'vector_retrieval' => [
                    'status' => 'available_through_open_brain',
                    'role' => 'semantic_similarity_reference',
                    'direct_invocation_allowed' => false,
                    'invoked_by_this_command' => false,
                ],
                'graph_retrieval' => [
                    'status' => 'future_governed',
                    'role' => 'relationship_dependency_context',
                    'blocked_until' => [
                        'AP-817 review',
                        'Kernel decision receipt',
                        'runtime-language-boundaries compliance',
                    ],
                    'invoked_by_this_command' => false,
                ],
            ],
            'post_source_directives' => array_values(array_map(
                fn (array $post): array => $this->postSourceDirective($post),
                $posts,
            )),
            'guardrails' => [
                'read_only' => true,
                'writes_backlog' => false,
                'publishes_content' => false,
                'uses_graph_rag' => false,
                'uses_python_runtime' => false,
                'creates_parallel_memory_store' => false,
                'purpose' => 'Expose which Atlas sources can inform editorial planning without pretending graph/RAG is already enabled.',
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @param  array<int,array<string,mixed>>  $publishedPosts
     * @param  array<string,mixed>|null  $nextReadyPost
     * @param  array<int,array<string,mixed>>  $blockedPosts
     * @return array<string,mixed>
     */
    public function operationsPacket(array $posts, array $publishedSlugs = [], array $publishedPosts = [], ?array $nextReadyPost = null, array $blockedPosts = [], int $contextLimit = 5, int $candidateLimit = 5): array
    {
        $contextLimit = max(1, min(12, $contextLimit));
        $candidateLimit = max(1, min(15, $candidateLimit));
        $sourceMap = $this->sourceMap($posts, $publishedSlugs, $publishedPosts);
        $coverageMap = $this->coverageMap($posts, $publishedSlugs);
        $candidateFeed = $this->candidateSuggestions($posts, $publishedSlugs, $candidateLimit);
        $nextPost = $this->plannedPostBySlug($posts, (string) ($nextReadyPost['slug'] ?? ''));
        $writingPacket = $nextPost !== null
            ? $this->writingPacket($nextPost, $posts, $publishedSlugs, $contextLimit, $publishedPosts)
            : null;
        $publicArchiveContext = is_array($writingPacket)
            ? (array) ($writingPacket['public_archive_context'] ?? [])
            : [];

        return [
            'schema_version' => 'atlas.blog_editorial_operations_packet.v1',
            'mode' => 'read_only_daily_operations_p1',
            'status' => 'ready',
            'next_action' => [
                'action' => $nextPost !== null ? 'prepare_next_post' : 'review_blockers',
                'slug' => $nextPost !== null ? (string) ($nextPost['slug'] ?? '') : null,
                'title' => $nextPost !== null ? (string) ($nextPost['title'] ?? '') : null,
                'order' => $nextPost !== null ? (int) ($nextPost['order'] ?? 0) : null,
                'why' => $nextPost !== null
                    ? 'This is the first unpublished planned post with satisfied prerequisites.'
                    : 'No planned post is ready; review prerequisite blockers before writing.',
            ],
            'daily_focus' => [
                'primary_packet' => $nextPost !== null ? 'writing_packet' : 'blocked_posts',
                'must_check_before_writing' => [
                    'previous prerequisites are published',
                    'public archive context has no unreviewed duplicate risk',
                    'Portuguese draft is approved by Vitor',
                    'sensitive paths, prompts, traces and tokens are removed',
                ],
                'do_not_do' => [
                    'do not publish automatically',
                    'do not skip the planned order',
                    'do not turn external archive posts into prerequisites automatically',
                    'do not use graph/RAG until P2 is promoted through AP-817',
                ],
            ],
            'next_ready_post' => $nextReadyPost,
            'writing_packet' => $writingPacket,
            'public_archive_risks' => [
                'duplicate_risk_count' => (int) ($publicArchiveContext['duplicate_risk_count'] ?? 0),
                'linkable_artifact_count' => (int) ($publicArchiveContext['linkable_artifact_count'] ?? 0),
                'prior_public_artifacts' => array_values((array) ($publicArchiveContext['prior_public_artifacts'] ?? [])),
            ],
            'blocked_summary' => [
                'blocked_count' => count($blockedPosts),
                'first_blocked_posts' => array_slice(array_values(array_map(
                    fn (array $post): array => [
                        'slug' => (string) ($post['slug'] ?? ''),
                        'title' => (string) ($post['title'] ?? ''),
                        'order' => (int) ($post['order'] ?? 0),
                        'missing_prerequisites' => array_values((array) ($post['missing_prerequisites'] ?? [])),
                    ],
                    $blockedPosts,
                )), 0, 5),
            ],
            'coverage_snapshot' => [
                'foundation_planned' => (int) data_get($coverageMap, 'summary.foundation_planned', 0),
                'foundation_published' => (int) data_get($coverageMap, 'summary.foundation_published', 0),
                'deep_sequence_warning_count' => (int) data_get($coverageMap, 'summary.deep_sequence_warning_count', 0),
                'next_safe_arcs' => array_slice((array) ($coverageMap['next_safe_arcs'] ?? []), 0, 5),
            ],
            'source_snapshot' => [
                'public_archive_posts' => (int) data_get($sourceMap, 'archive_state.public_archive_posts', 0),
                'external_published_posts' => (int) data_get($sourceMap, 'archive_state.external_published_posts', 0),
                'graph_retrieval_status' => (string) data_get($sourceMap, 'sources.graph_retrieval.status', 'unknown'),
                'open_brain_status' => (string) data_get($sourceMap, 'sources.open_brain_context_pack.status', 'unknown'),
            ],
            'candidate_feed' => [
                'candidate_count' => (int) ($candidateFeed['candidate_count'] ?? 0),
                'candidates' => array_slice((array) ($candidateFeed['candidates'] ?? []), 0, $candidateLimit),
                'rule' => 'Candidates are ideas for review, not scheduled posts until accepted and promoted.',
            ],
            'guardrails' => [
                'read_only' => true,
                'writes_backlog' => false,
                'writes_review_queue' => false,
                'writes_draft' => false,
                'publishes_content' => false,
                'uses_graph_rag' => false,
                'uses_python_runtime' => false,
                'creates_parallel_memory_store' => false,
                'requires_human_approval_to_publish' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $post
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @param  array<int,array<string,mixed>>  $publishedPosts
     * @return array<string,mixed>
     */
    public function writingPacket(array $post, array $posts, array $publishedSlugs = [], int $contextLimit = 5, array $publishedPosts = []): array
    {
        $contextLimit = max(1, min(12, $contextLimit));
        $publishedSet = array_fill_keys($publishedSlugs, true);
        $plannedSet = array_fill_keys(array_values(array_filter(array_map(
            fn (array $item): string => (string) ($item['slug'] ?? ''),
            $posts,
        ))), true);
        $slug = (string) ($post['slug'] ?? '');
        $prerequisites = array_values(array_filter((array) ($post['prerequisites'] ?? []), 'is_string'));
        $missingPrerequisites = array_values(array_filter(
            $prerequisites,
            fn (string $prerequisite): bool => ! isset($publishedSet[$prerequisite]),
        ));
        $unknownPrerequisites = array_values(array_filter(
            $prerequisites,
            fn (string $prerequisite): bool => ! isset($plannedSet[$prerequisite]) && ! isset($publishedSet[$prerequisite]),
        ));
        $neighbors = $this->neighborPosts($post, $posts);
        $terms = $this->terms($post);
        $publicArchiveContext = $this->publicArchiveContextForPost($post, $posts, $publishedSlugs, $publishedPosts);

        return [
            'schema_version' => 'atlas.blog_editorial_writing_packet.v1',
            'mode' => 'read_only_writing_preparation_p1',
            'status' => $unknownPrerequisites === [] ? 'ready' : 'blocked',
            'post' => [
                'order' => (int) ($post['order'] ?? 0),
                'title' => (string) ($post['title'] ?? ''),
                'slug' => $slug,
                'type' => (string) ($post['type'] ?? ''),
                'complexity_level' => (string) ($post['complexity_level'] ?? ''),
                'collection' => (string) ($post['collection'] ?? ''),
                'series' => (string) ($post['series'] ?? ''),
                'reader_level' => (string) ($post['reader_level'] ?? ''),
                'main_question' => (string) ($post['main_question'] ?? ''),
                'goal' => (string) ($post['goal'] ?? ''),
                'topics' => array_values(array_filter((array) ($post['topics'] ?? []), 'is_string')),
            ],
            'sequence' => [
                'previous_post' => $neighbors['previous'],
                'next_post' => $neighbors['next'],
                'prerequisites' => $prerequisites,
                'missing_prerequisites' => $missingPrerequisites,
                'unknown_prerequisites' => $unknownPrerequisites,
                'next_reading' => array_values(array_filter((array) ($post['next_reading'] ?? []), 'is_string')),
                'rule' => 'Write only what this post is allowed to introduce at its current depth.',
            ],
            'public_archive_context' => $publicArchiveContext,
            'editorial_context' => $this->contextForPost($post, $contextLimit),
            'coverage_snapshot' => [
                'terms' => $terms,
                'current_level' => (string) ($post['complexity_level'] ?? ''),
                'needs_foundation' => in_array((string) ($post['complexity_level'] ?? ''), ['L2', 'L3', 'L4', 'L5'], true),
                'future_topics_to_avoid' => $this->futureTopicsToAvoid($post, $posts),
            ],
            'writing_brief' => [
                'language' => 'pt-BR',
                'voice' => 'tecnica, direta, pessoal, sem hype e sem tom de marketing',
                'primary_question' => (string) ($post['main_question'] ?? ''),
                'reader_promise' => $this->readerPromise($post),
                'outline' => $this->outlineForPost($post),
                'must_include' => $this->mustInclude($post),
                'must_not_include' => $this->mustNotInclude($post),
            ],
            'safety_review' => [
                'requires_human_review' => true,
                'draft_private_until_approved' => true,
                'remove_private_paths' => true,
                'remove_tokens_prompts_traces' => true,
                'do_not_publish_internal_architecture_by_default' => true,
            ],
            'guardrails' => [
                'read_only' => true,
                'writes_backlog' => false,
                'writes_draft' => false,
                'publishes_content' => false,
                'generates_full_article' => false,
                'uses_graph_rag' => false,
                'uses_python_runtime' => false,
                'creates_parallel_memory_store' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $post
     * @return array<string,mixed>
     */
    public function contextForPost(array $post, int $limit = 5): array
    {
        $limit = max(1, min(12, $limit));
        $terms = $this->terms($post);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => 'read_only_governed_p1',
            'post_slug' => (string) ($post['slug'] ?? ''),
            'context_query' => $this->contextQuery($post),
            'terms' => $terms,
            'knowledge_refs' => $this->knowledgeRefs($terms, $limit),
            'code_refs' => $this->codeRefs($terms, $limit),
            'coverage_signals' => $this->coverageSignals($post),
            'safety_review' => [
                'requires_human_review' => true,
                'publish_private_paths' => false,
                'publish_internal_ids_or_traces' => false,
                'publish_provider_prompts' => false,
                'rule' => 'Use references to understand the subject; do not expose private implementation details by default.',
            ],
            'guardrails' => [
                'read_only' => true,
                'publishes_content' => false,
                'uses_graph_rag' => false,
                'uses_python_runtime' => false,
                'creates_parallel_memory_store' => false,
                'source_of_truth' => 'existing_atlas_engineering_knowledge_and_code_intelligence_read_models',
            ],
        ];
    }

    /**
     * @param  array<int,string>  $terms
     * @return array<int,array<string,mixed>>
     */
    private function knowledgeRefs(array $terms, int $limit): array
    {
        if ($terms === [] || ! DatabaseTableAvailability::has('atlas_engineering_knowledge_items')) {
            return [];
        }

        return AtlasEngineeringKnowledgeItem::query()
            ->active()
            ->where(function (Builder $query) use ($terms): void {
                foreach ($terms as $term) {
                    $query->orWhere('slug', 'like', "%{$term}%")
                        ->orWhere('title', 'like', "%{$term}%")
                        ->orWhere('summary', 'like', "%{$term}%")
                        ->orWhere('canonical_path', 'like', "%{$term}%")
                        ->orWhere('body_excerpt', 'like', "%{$term}%");
                }
            })
            ->orderByDesc('priority')
            ->latest('indexed_at')
            ->limit($limit)
            ->get()
            ->map(fn (AtlasEngineeringKnowledgeItem $item): array => [
                'type' => 'engineering_knowledge',
                'slug' => $item->slug,
                'title' => $item->title,
                'category' => $item->category,
                'canonical_path' => $item->canonical_path,
                'summary' => Str::limit((string) $item->summary, 220, ''),
                'reason' => 'matched_editorial_terms',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int,string>  $terms
     * @return array<string,mixed>
     */
    private function codeRefs(array $terms, int $limit): array
    {
        if ($terms === []) {
            return [
                'modules' => [],
                'symbols' => [],
            ];
        }

        return [
            'modules' => $this->moduleRefs($terms, $limit),
            'symbols' => $this->symbolRefs($terms, $limit),
        ];
    }

    /**
     * @param  array<int,string>  $terms
     * @return array<int,array<string,mixed>>
     */
    private function moduleRefs(array $terms, int $limit): array
    {
        if (! DatabaseTableAvailability::has('atlas_engineering_code_modules')) {
            return [];
        }

        return AtlasEngineeringCodeModule::query()
            ->active()
            ->where(function (Builder $query) use ($terms): void {
                foreach ($terms as $term) {
                    $query->orWhere('slug', 'like', "%{$term}%")
                        ->orWhere('name', 'like', "%{$term}%")
                        ->orWhere('root_path', 'like', "%{$term}%")
                        ->orWhere('description', 'like', "%{$term}%");
                }
            })
            ->orderByDesc('symbol_count')
            ->orderBy('slug')
            ->limit($limit)
            ->get()
            ->map(fn (AtlasEngineeringCodeModule $module): array => [
                'type' => 'code_module',
                'slug' => $module->slug,
                'name' => $module->name,
                'layer' => $module->layer,
                'root_path' => $module->root_path,
                'docs_status' => $module->docs_status,
                'symbol_count' => $module->symbol_count,
                'reason' => 'matched_editorial_terms',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int,string>  $terms
     * @return array<int,array<string,mixed>>
     */
    private function symbolRefs(array $terms, int $limit): array
    {
        if (! DatabaseTableAvailability::has('atlas_engineering_code_symbols')) {
            return [];
        }

        return AtlasEngineeringCodeSymbol::query()
            ->with('module')
            ->active()
            ->where(function (Builder $query) use ($terms): void {
                foreach ($terms as $term) {
                    $query->orWhere('symbol_name', 'like', "%{$term}%")
                        ->orWhere('file_path', 'like', "%{$term}%")
                        ->orWhere('signature', 'like', "%{$term}%");
                }
            })
            ->orderBy('file_path')
            ->orderBy('line_start')
            ->limit($limit)
            ->get()
            ->map(fn (AtlasEngineeringCodeSymbol $symbol): array => [
                'type' => 'code_symbol',
                'symbol_type' => $symbol->symbol_type,
                'symbol_name' => $symbol->symbol_name,
                'file_path' => $symbol->file_path,
                'line_start' => $symbol->line_start,
                'line_end' => $symbol->line_end,
                'module' => $symbol->module?->slug,
                'docs_status' => $symbol->docs_status,
                'reason' => 'matched_editorial_terms',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $post
     * @return array<string,mixed>
     */
    private function coverageSignals(array $post): array
    {
        $level = (string) ($post['complexity_level'] ?? '');

        return [
            'reader_level' => (string) ($post['reader_level'] ?? ''),
            'complexity_level' => $level,
            'needs_foundation' => in_array($level, ['L2', 'L3', 'L4'], true),
            'collection' => (string) ($post['collection'] ?? ''),
            'series' => (string) ($post['series'] ?? ''),
            'prerequisite_count' => count((array) ($post['prerequisites'] ?? [])),
            'editorial_rule' => 'Advanced subjects must preserve the public learning chain from surface-level framing to deeper implementation.',
        ];
    }

    /**
     * @param  array<string,mixed>  $post
     * @return array<string,mixed>
     */
    private function postSourceDirective(array $post): array
    {
        $level = (string) ($post['complexity_level'] ?? '');
        $collection = (string) ($post['collection'] ?? '');
        $topics = array_values(array_filter((array) ($post['topics'] ?? []), 'is_string'));
        $requiresCodeReality = in_array($level, ['L2', 'L3', 'L4', 'L5'], true)
            || in_array($collection, ['atlas', 'arquitetura', 'architecture'], true)
            || array_intersect($topics, ['codigo', 'code', 'arquitetura', 'architecture', 'agentes', 'agents']) !== [];

        $requiredSources = [
            'site_backlog',
            'public_site_archive',
            'engineering_knowledge',
        ];

        if ($requiresCodeReality) {
            $requiredSources[] = 'code_intelligence';
        }

        $recommendedSources = [
            'open_brain_context_pack',
        ];

        if (in_array($level, ['L3', 'L4', 'L5'], true)) {
            $recommendedSources[] = 'vector_retrieval';
        }

        return [
            'slug' => (string) ($post['slug'] ?? ''),
            'order' => (int) ($post['order'] ?? 0),
            'complexity_level' => $level,
            'collection' => $collection,
            'context_query' => $this->contextQuery($post),
            'required_sources' => array_values(array_unique($requiredSources)),
            'recommended_sources' => array_values(array_unique($recommendedSources)),
            'deferred_sources' => [
                'graph_retrieval',
            ],
            'reason' => $requiresCodeReality
                ? 'Technical or Atlas-specific post needs code reality before deeper claims.'
                : 'Introductory post can stay anchored in public sequence and canonical docs.',
        ];
    }

    /**
     * @param  array<string,mixed>  $post
     * @return array<int,string>
     */
    private function terms(array $post): array
    {
        $raw = implode(' ', array_filter([
            (string) ($post['title'] ?? ''),
            (string) ($post['main_question'] ?? ''),
            (string) ($post['collection'] ?? ''),
            (string) ($post['series'] ?? ''),
            implode(' ', array_filter((array) ($post['topics'] ?? []), 'is_string')),
        ]));

        $stop = array_fill_keys([
            'atlas', 'para', 'porque', 'como', 'quando', 'onde', 'quem', 'que',
            'uma', 'por', 'com', 'sem', 'dos', 'das', 'the', 'and', 'what',
            'why', 'how', 'from', 'into', 'sobre', 'sistema',
        ], true);

        return collect(preg_split('/[^a-zA-Z0-9_\\-]+/', Str::ascii(Str::lower($raw))) ?: [])
            ->map(fn (string $term): string => trim($term, " \t\n\r\0\x0B-_"))
            ->filter(fn (string $term): bool => strlen($term) >= 3 && ! isset($stop[$term]))
            ->unique()
            ->take(12)
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $post
     */
    private function contextQuery(array $post): string
    {
        return trim(implode(' ', array_filter([
            (string) ($post['title'] ?? ''),
            (string) ($post['main_question'] ?? ''),
            implode(' ', array_filter((array) ($post['topics'] ?? []), 'is_string')),
        ])));
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @return array{slugs:array<string,bool>,titles:array<string,bool>}
     */
    private function existingEditorialIndex(array $posts, array $publishedSlugs): array
    {
        $slugs = array_fill_keys(array_values(array_filter($publishedSlugs)), true);
        $titles = [];

        foreach ($posts as $post) {
            $slug = (string) ($post['slug'] ?? '');
            if ($slug !== '') {
                $slugs[$slug] = true;
            }

            $title = $this->normalizedTitle((string) ($post['title'] ?? ''));
            if ($title !== '') {
                $titles[$title] = true;
            }
        }

        return [
            'slugs' => $slugs,
            'titles' => $titles,
        ];
    }

    /**
     * @return array<int,AtlasEngineeringKnowledgeItem>
     */
    private function knowledgeCandidateRows(int $limit): array
    {
        if (! DatabaseTableAvailability::has('atlas_engineering_knowledge_items')) {
            return [];
        }

        $terms = ['atlas', 'memory', 'memoria', 'context', 'contexto', 'agent', 'agente', 'local', 'governance', 'governanca', 'knowledge', 'conhecimento'];

        return AtlasEngineeringKnowledgeItem::query()
            ->active()
            ->where(function (Builder $query) use ($terms): void {
                foreach ($terms as $term) {
                    $query->orWhere('slug', 'like', "%{$term}%")
                        ->orWhere('title', 'like', "%{$term}%")
                        ->orWhere('summary', 'like', "%{$term}%")
                        ->orWhere('canonical_path', 'like', "%{$term}%");
                }
            })
            ->orderByDesc('priority')
            ->latest('indexed_at')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * @return array<int,AtlasEngineeringCodeModule>
     */
    private function moduleCandidateRows(int $limit): array
    {
        if (! DatabaseTableAvailability::has('atlas_engineering_code_modules')) {
            return [];
        }

        $terms = ['memory', 'context', 'agent', 'knowledge', 'publishing', 'open-brain', 'code-intelligence'];

        return AtlasEngineeringCodeModule::query()
            ->active()
            ->where(function (Builder $query) use ($terms): void {
                foreach ($terms as $term) {
                    $query->orWhere('slug', 'like', "%{$term}%")
                        ->orWhere('name', 'like', "%{$term}%")
                        ->orWhere('root_path', 'like', "%{$term}%")
                        ->orWhere('description', 'like', "%{$term}%");
                }
            })
            ->orderByDesc('symbol_count')
            ->orderBy('slug')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @return array<string,mixed>|null
     */
    private function candidateBySlug(string $slug, array $posts): ?array
    {
        foreach ($this->knowledgeCandidateRows(500) as $row) {
            $candidate = $this->candidateFromKnowledgeRow($row, $posts);
            if (is_array($candidate) && (string) ($candidate['slug'] ?? '') === $slug) {
                return $candidate;
            }
        }

        foreach ($this->moduleCandidateRows(500) as $row) {
            $candidate = $this->candidateFromModuleRow($row, $posts);
            if (is_array($candidate) && (string) ($candidate['slug'] ?? '') === $slug) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @return array<string,mixed>|null
     */
    private function candidateFromKnowledgeRow(AtlasEngineeringKnowledgeItem $item, array $posts): ?array
    {
        $title = $this->candidateTitle((string) $item->title);
        if ($title === '') {
            return null;
        }

        $topics = $this->terms([
            'title' => $item->title,
            'main_question' => $item->summary,
            'topics' => array_merge((array) $item->tags_json, [(string) $item->category]),
        ]);

        return [
            'source_type' => 'engineering_knowledge',
            'source_ref' => (string) $item->canonical_path,
            'title' => $title,
            'slug' => Str::slug($title),
            'collection' => $this->candidateCollection($topics, (string) $item->category),
            'series' => $this->candidateSeries($topics),
            'complexity_level' => $this->candidateComplexity($title, $topics),
            'main_question' => $this->candidateQuestion($title),
            'suggested_after_slug' => $this->suggestedAfterSlug($posts, $topics),
            'topics' => $topics,
            'why' => 'Canonical Atlas knowledge not yet represented in the public backlog.',
            'safety' => [
                'requires_human_review' => true,
                'publish_private_paths' => false,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @return array<string,mixed>|null
     */
    private function candidateFromModuleRow(AtlasEngineeringCodeModule $module, array $posts): ?array
    {
        $name = trim((string) ($module->name ?: $module->slug));
        if ($name === '') {
            return null;
        }

        $title = 'Por dentro de '.$name;
        $topics = $this->terms([
            'title' => $title,
            'main_question' => (string) $module->description,
            'topics' => array_merge((array) $module->tags_json, [(string) $module->layer]),
        ]);

        return [
            'source_type' => 'code_module',
            'source_ref' => (string) ($module->root_path ?: $module->slug),
            'title' => $title,
            'slug' => Str::slug($title),
            'collection' => $this->candidateCollection($topics, (string) $module->layer),
            'series' => $this->candidateSeries($topics),
            'complexity_level' => 'L3',
            'main_question' => 'O que esse modulo revela sobre a arquitetura do Atlas?',
            'suggested_after_slug' => $this->suggestedAfterSlug($posts, $topics),
            'topics' => $topics,
            'why' => 'Indexed code module has enough implementation signal to become a public architecture note after prerequisites.',
            'safety' => [
                'requires_human_review' => true,
                'publish_private_paths' => false,
            ],
        ];
    }

    private function candidateTitle(string $title): string
    {
        $title = trim(preg_replace('/\s+/', ' ', $title) ?? $title);
        $title = preg_replace('/^(Atlas|AP-\d+)\s*[-:]\s*/i', '', $title) ?? $title;

        return trim($title);
    }

    /**
     * @param  array<int,string>  $topics
     */
    private function candidateCollection(array $topics, string $fallback): string
    {
        if ($this->hasAny($topics, ['memory', 'memoria', 'agent', 'agente', 'context', 'contexto'])) {
            return 'ia-pessoal';
        }
        if ($this->hasAny($topics, ['architecture', 'arquitetura', 'code', 'runtime'])) {
            return 'arquitetura';
        }

        return Str::slug($fallback !== '' ? $fallback : 'atlas');
    }

    /**
     * @param  array<int,string>  $topics
     */
    private function candidateSeries(array $topics): string
    {
        if ($this->hasAny($topics, ['memory', 'memoria', 'ledger'])) {
            return 'agent-memory';
        }
        if ($this->hasAny($topics, ['agent', 'agente', 'mandate', 'mandato'])) {
            return 'agent-governance';
        }

        return 'building-atlas';
    }

    /**
     * @param  array<int,string>  $topics
     */
    private function candidateComplexity(string $title, array $topics): string
    {
        $text = Str::lower(Str::ascii($title.' '.implode(' ', $topics)));
        if (str_contains($text, 'runtime') || str_contains($text, 'architecture') || str_contains($text, 'arquitetura')) {
            return 'L3';
        }
        if (str_contains($text, 'memory') || str_contains($text, 'memoria') || str_contains($text, 'governance')) {
            return 'L2';
        }

        return 'L1';
    }

    private function candidateQuestion(string $title): string
    {
        return 'Por que "'.$title.'" importa para entender o Atlas?';
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $topics
     */
    private function suggestedAfterSlug(array $posts, array $topics): ?string
    {
        $best = null;
        $bestScore = -1;

        foreach ($posts as $post) {
            $postTopics = $this->terms($post);
            $score = count(array_intersect($topics, $postTopics));
            $order = (int) ($post['order'] ?? 0);
            if ($score > $bestScore || ($score === $bestScore && $order > (int) ($best['order'] ?? 0))) {
                $best = $post;
                $bestScore = $score;
            }
        }

        return is_array($best) ? (string) ($best['slug'] ?? '') ?: null : null;
    }

    /**
     * @param  array<int,string>  $haystack
     * @param  array<int,string>  $needles
     */
    private function hasAny(array $haystack, array $needles): bool
    {
        return array_intersect($haystack, $needles) !== [];
    }

    private function normalizedTitle(string $title): string
    {
        return Str::slug(Str::ascii(Str::lower($title)));
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    private function reviewQueueEntry(array $candidate): array
    {
        return [
            'status' => 'accepted_for_review',
            'accepted_at' => now()->toJSON(),
            'title' => (string) ($candidate['title'] ?? ''),
            'slug' => (string) ($candidate['slug'] ?? ''),
            'collection' => (string) ($candidate['collection'] ?? ''),
            'series' => (string) ($candidate['series'] ?? ''),
            'complexity_level' => (string) ($candidate['complexity_level'] ?? ''),
            'main_question' => (string) ($candidate['main_question'] ?? ''),
            'suggested_after_slug' => (string) ($candidate['suggested_after_slug'] ?? ''),
            'source_type' => (string) ($candidate['source_type'] ?? ''),
            'source_ref' => (string) ($candidate['source_ref'] ?? ''),
            'topics' => array_values(array_filter((array) ($candidate['topics'] ?? []), 'is_string')),
            'why' => (string) ($candidate['why'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $post
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @param  array<int,array<string,mixed>>  $publishedPosts
     * @return array<string,mixed>
     */
    private function publicArchiveContextForPost(array $post, array $posts, array $publishedSlugs, array $publishedPosts): array
    {
        $slug = (string) ($post['slug'] ?? '');
        $reconciliation = $this->archiveReconciliation($posts, $publishedSlugs, $publishedPosts);
        $bridges = array_values(array_filter(
            (array) ($reconciliation['bridge_candidates'] ?? []),
            fn (array $candidate): bool => (string) ($candidate['matched_planned_slug'] ?? '') === $slug,
        ));
        $alreadyPublished = array_values(array_filter(
            (array) ($reconciliation['planned_published'] ?? []),
            fn (array $publishedPost): bool => (string) ($publishedPost['slug'] ?? '') === $slug,
        ));

        return [
            'schema_version' => 'atlas.blog_editorial_public_archive_context.v1',
            'status' => 'ready',
            'post_slug' => $slug,
            'already_published' => $alreadyPublished,
            'prior_public_artifacts' => $bridges,
            'duplicate_risk_count' => count(array_filter(
                $bridges,
                fn (array $candidate): bool => (string) ($candidate['suggested_action'] ?? '') === 'review_for_duplicate_or_rewrite',
            )),
            'linkable_artifact_count' => count(array_filter(
                $bridges,
                fn (array $candidate): bool => (string) ($candidate['suggested_action'] ?? '') === 'link_as_prior_artifact',
            )),
            'rule' => 'Use public archive matches as writing context; do not silently treat them as completed prerequisites or duplicate replacements.',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @param  array<int,array<string,mixed>>  $publishedPosts
     * @return array<string,mixed>
     */
    private function archiveReconciliation(array $posts, array $publishedSlugs, array $publishedPosts): array
    {
        $plannedBySlug = [];
        foreach ($posts as $post) {
            $slug = (string) ($post['slug'] ?? '');
            if ($slug !== '') {
                $plannedBySlug[$slug] = $post;
            }
        }

        $publishedBySlug = [];
        foreach ($publishedPosts as $publishedPost) {
            $slug = (string) ($publishedPost['slug'] ?? '');
            if ($slug !== '') {
                $publishedBySlug[$slug] = $publishedPost;
            }
        }

        if ($publishedBySlug === []) {
            foreach ($publishedSlugs as $slug) {
                $publishedBySlug[$slug] = ['slug' => $slug];
            }
        }

        $plannedPublished = [];
        $externalPublished = [];
        foreach ($publishedBySlug as $slug => $publishedPost) {
            if (isset($plannedBySlug[$slug])) {
                $plannedPublished[] = $this->publishedPostSummary($publishedPost, $plannedBySlug[$slug]);
                continue;
            }

            $externalPublished[] = $this->publishedPostSummary($publishedPost);
        }

        usort($plannedPublished, fn (array $a, array $b): int => ((int) ($a['planned_order'] ?? 9999)) <=> ((int) ($b['planned_order'] ?? 9999)));
        usort($externalPublished, fn (array $a, array $b): int => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')));

        return [
            'schema_version' => 'atlas.blog_editorial_archive_reconciliation.v1',
            'status' => 'ready',
            'planned_published_count' => count($plannedPublished),
            'external_published_count' => count($externalPublished),
            'external_by_kind' => $this->countPublishedByField($externalPublished, 'kind'),
            'external_by_collection' => $this->countPublishedByField($externalPublished, 'collection'),
            'planned_published' => array_values($plannedPublished),
            'external_published' => array_values($externalPublished),
            'bridge_candidates' => $this->archiveBridgeCandidates($posts, $externalPublished),
            'rule' => 'External public posts are not treated as ordered prerequisites automatically; they can be linked as prior artifacts or reviewed for duplication risk.',
        ];
    }

    /**
     * @param  array<string,mixed>  $publishedPost
     * @param  array<string,mixed>|null  $plannedPost
     * @return array<string,mixed>
     */
    private function publishedPostSummary(array $publishedPost, ?array $plannedPost = null): array
    {
        $titlePt = (string) ($publishedPost['title_pt'] ?? '');
        $titleEn = (string) ($publishedPost['title_en'] ?? '');
        $title = $titlePt !== '' ? $titlePt : $titleEn;

        return [
            'slug' => (string) ($publishedPost['slug'] ?? ''),
            'title' => $title,
            'title_pt' => $titlePt,
            'title_en' => $titleEn,
            'kind' => (string) ($publishedPost['kind'] ?? ''),
            'date' => (string) ($publishedPost['date'] ?? ''),
            'collection' => (string) ($publishedPost['collection'] ?? ''),
            'series' => (string) ($publishedPost['series'] ?? ''),
            'tags' => array_values(array_filter((array) ($publishedPost['tags'] ?? []), 'is_string')),
            'planned_order' => $plannedPost !== null ? (int) ($plannedPost['order'] ?? 0) : null,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,array<string,mixed>>  $externalPublished
     * @return array<int,array<string,mixed>>
     */
    private function archiveBridgeCandidates(array $posts, array $externalPublished): array
    {
        $candidates = [];

        foreach ($externalPublished as $publishedPost) {
            $publishedTerms = $this->publishedTerms($publishedPost);
            if ($publishedTerms === []) {
                continue;
            }

            $best = null;
            foreach ($posts as $post) {
                $plannedTerms = $this->terms($post);
                $overlap = array_values(array_intersect($publishedTerms, $plannedTerms));
                $score = count($overlap);

                if ($score < 2) {
                    continue;
                }

                if ($best === null || $score > (int) $best['score']) {
                    $best = [
                        'score' => $score,
                        'matched_terms' => $overlap,
                        'planned_slug' => (string) ($post['slug'] ?? ''),
                        'planned_title' => (string) ($post['title'] ?? ''),
                        'planned_order' => (int) ($post['order'] ?? 0),
                    ];
                }
            }

            if ($best === null) {
                continue;
            }

            $candidates[] = [
                'published_slug' => (string) ($publishedPost['slug'] ?? ''),
                'published_title' => (string) ($publishedPost['title'] ?? ''),
                'published_date' => (string) ($publishedPost['date'] ?? ''),
                'matched_planned_slug' => $best['planned_slug'],
                'matched_planned_title' => $best['planned_title'],
                'matched_planned_order' => $best['planned_order'],
                'score' => $best['score'],
                'matched_terms' => $best['matched_terms'],
                'suggested_action' => $this->archiveBridgeAction((int) $best['score'], (string) ($publishedPost['slug'] ?? ''), (string) $best['planned_slug']),
            ];
        }

        usort($candidates, fn (array $a, array $b): int => ((int) $b['score'] <=> (int) $a['score'])
            ?: ((int) $a['matched_planned_order'] <=> (int) $b['matched_planned_order'])
            ?: strcmp((string) $a['published_slug'], (string) $b['published_slug']));

        return array_slice($candidates, 0, 20);
    }

    /**
     * @param  array<string,mixed>  $post
     * @return array<int,string>
     */
    private function publishedTerms(array $post): array
    {
        return $this->terms([
            'title' => trim(implode(' ', array_filter([
                (string) ($post['title'] ?? ''),
                (string) ($post['title_pt'] ?? ''),
                (string) ($post['title_en'] ?? ''),
                (string) ($post['slug'] ?? ''),
            ]))),
            'main_question' => trim(implode(' ', array_filter([
                (string) ($post['kind'] ?? ''),
                (string) ($post['collection'] ?? ''),
                (string) ($post['series'] ?? ''),
            ]))),
            'topics' => array_values(array_filter((array) ($post['tags'] ?? []), 'is_string')),
        ]);
    }

    private function archiveBridgeAction(int $score, string $publishedSlug, string $plannedSlug): string
    {
        if ($publishedSlug === $plannedSlug) {
            return 'mark_as_already_published';
        }

        if ($score >= 4) {
            return 'review_for_duplicate_or_rewrite';
        }

        return 'link_as_prior_artifact';
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @return array<string,int>
     */
    private function countPublishedByField(array $posts, string $field): array
    {
        $counts = [];
        foreach ($posts as $post) {
            $value = (string) ($post[$field] ?? '');
            if ($value === '') {
                $value = 'unknown';
            }
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @param  array<string,bool>  $plannedSet
     * @param  array<string,bool>  $publishedSet
     * @return array<int,array<string,mixed>>
     */
    private function foundationCoverage(array $plannedSet, array $publishedSet): array
    {
        $items = [
            ['key' => 'atlas_identity', 'slug' => 'o-que-e-o-atlas', 'level' => 'L0', 'label' => 'O que e o Atlas'],
            ['key' => 'builder_motivation', 'slug' => 'por-que-estou-construindo-o-atlas', 'level' => 'L0', 'label' => 'Por que o Atlas existe'],
            ['key' => 'assistant_problem', 'slug' => 'o-problema-dos-assistentes-de-ia-hoje', 'level' => 'L1', 'label' => 'Problema dos assistentes atuais'],
            ['key' => 'personal_system', 'slug' => 'a-diferenca-entre-chatbot-e-sistema-pessoal', 'level' => 'L1', 'label' => 'Chatbot vs sistema pessoal'],
            ['key' => 'atlas_boundaries', 'slug' => 'o-que-o-atlas-nao-e', 'level' => 'L0', 'label' => 'O que o Atlas nao e'],
            ['key' => 'context_need', 'slug' => 'por-que-ia-pessoal-precisa-conhecer-contexto', 'level' => 'L1', 'label' => 'Contexto como base'],
            ['key' => 'local_first', 'slug' => 'o-que-significa-local-first', 'level' => 'L1', 'label' => 'Local-first'],
            ['key' => 'privacy', 'slug' => 'por-que-privacidade-muda-tudo', 'level' => 'L1', 'label' => 'Privacidade'],
            ['key' => 'control', 'slug' => 'por-que-controle-importa-mais-que-conveniencia', 'level' => 'L1', 'label' => 'Controle'],
            ['key' => 'vocabulary', 'slug' => 'o-vocabulario-do-atlas', 'level' => 'L0', 'label' => 'Vocabulario publico'],
            ['key' => 'memory_problem', 'slug' => 'o-problema-da-memoria-em-ia', 'level' => 'L1', 'label' => 'Problema da memoria'],
            ['key' => 'remembering_too_much', 'slug' => 'por-que-lembrar-tudo-e-ruim', 'level' => 'L2', 'label' => 'Falha da memoria total'],
            ['key' => 'memory_vs_knowledge', 'slug' => 'a-diferenca-entre-conversa-memoria-e-conhecimento', 'level' => 'L2', 'label' => 'Conversa, memoria e conhecimento'],
            ['key' => 'memory_database', 'slug' => 'memoria-como-problema-de-banco-de-dados', 'level' => 'L3', 'label' => 'Memoria como banco de dados'],
            ['key' => 'memory_ledger', 'slug' => 'memoria-como-ledger', 'level' => 'L3', 'label' => 'Memoria como ledger'],
            ['key' => 'agents_intro', 'slug' => 'o-que-sao-agentes-no-atlas', 'level' => 'L1', 'label' => 'Agentes no Atlas'],
            ['key' => 'agent_limits', 'slug' => 'por-que-agentes-precisam-de-limites', 'level' => 'L2', 'label' => 'Limites para agentes'],
            ['key' => 'agent_mandates', 'slug' => 'o-que-e-um-mandato-de-agente', 'level' => 'L3', 'label' => 'Mandato de agente'],
            ['key' => 'sandbox_dry_run', 'slug' => 'por-que-dry-run-e-sandbox-importam', 'level' => 'L3', 'label' => 'Dry-run e sandbox'],
            ['key' => 'evolution', 'slug' => 'como-o-atlas-esta-evoluindo', 'level' => 'L1', 'label' => 'Evolucao do Atlas'],
        ];

        return array_map(fn (array $item): array => $item + [
            'planned' => isset($plannedSet[$item['slug']]),
            'published' => isset($publishedSet[$item['slug']]),
        ], $items);
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<string,bool>  $publishedSet
     * @return array<int,array<string,mixed>>
     */
    private function topicIndex(array $posts, array $publishedSet): array
    {
        $topics = [];

        foreach ($posts as $post) {
            $slug = (string) ($post['slug'] ?? '');
            foreach ($this->terms($post) as $topic) {
                $topics[$topic] ??= [
                    'topic' => $topic,
                    'planned_count' => 0,
                    'published_count' => 0,
                    'first_order' => (int) ($post['order'] ?? 0),
                    'slugs' => [],
                ];

                $topics[$topic]['planned_count']++;
                if (isset($publishedSet[$slug])) {
                    $topics[$topic]['published_count']++;
                }
                $topics[$topic]['first_order'] = min((int) $topics[$topic]['first_order'], (int) ($post['order'] ?? 0));
                $topics[$topic]['slugs'][] = $slug;
            }
        }

        usort($topics, fn (array $a, array $b): int => ((int) $b['planned_count'] <=> (int) $a['planned_count'])
            ?: ((int) $a['first_order'] <=> (int) $b['first_order'])
            ?: strcmp((string) $a['topic'], (string) $b['topic']));

        return array_slice(array_values($topics), 0, 40);
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @return array<string,int>
     */
    private function countByField(array $posts, string $field): array
    {
        $counts = [];
        foreach ($posts as $post) {
            $value = (string) ($post[$field] ?? '');
            if ($value === '') {
                $value = 'unknown';
            }
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @return array<string,mixed>|null
     */
    private function plannedPostBySlug(array $posts, string $slug): ?array
    {
        if ($slug === '') {
            return null;
        }

        foreach ($posts as $post) {
            if ((string) ($post['slug'] ?? '') === $slug) {
                return $post;
            }
        }

        return null;
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @return array<int,array<string,mixed>>
     */
    private function deepSequenceWarnings(array $posts): array
    {
        $warnings = [];
        $seenSeriesFoundation = [];
        $seenCollectionFoundation = [];

        foreach ($posts as $post) {
            $level = (string) ($post['complexity_level'] ?? '');
            $series = (string) ($post['series'] ?? '');
            $collection = (string) ($post['collection'] ?? '');
            $isFoundation = in_array($level, ['L0', 'L1'], true);

            if ($isFoundation) {
                if ($series !== '') {
                    $seenSeriesFoundation[$series] = true;
                }
                if ($collection !== '') {
                    $seenCollectionFoundation[$collection] = true;
                }
            }

            if (! in_array($level, ['L3', 'L4', 'L5'], true)) {
                continue;
            }

            if ($series !== '' && ! isset($seenSeriesFoundation[$series]) && $collection !== '' && ! isset($seenCollectionFoundation[$collection])) {
                $warnings[] = [
                    'code' => 'deep_post_without_prior_foundation',
                    'slug' => (string) ($post['slug'] ?? ''),
                    'order' => (int) ($post['order'] ?? 0),
                    'level' => $level,
                    'series' => $series,
                    'collection' => $collection,
                ];
            }
        }

        return $warnings;
    }

    /**
     * @param  array<int,array<string,mixed>>  $foundation
     * @param  array<string,bool>  $plannedSet
     * @return array<int,array<string,mixed>>
     */
    private function nextSafeArcs(array $foundation, array $plannedSet): array
    {
        $foundationComplete = count(array_filter($foundation, fn (array $item): bool => ! (bool) $item['planned'])) === 0;
        $arcs = [
            [
                'arc' => 'knowledge_governance',
                'title' => 'Governanca de conhecimento',
                'first_post_slug' => 'governanca-de-conhecimento-no-atlas',
                'why_now' => 'Depois de memoria e agentes, o leitor pode entender como conhecimento entra, amadurece e expira.',
                'required_foundation' => ['memory_ledger', 'agent_mandates', 'sandbox_dry_run'],
            ],
            [
                'arc' => 'capture_inbox',
                'title' => 'Inbox de captura',
                'first_post_slug' => 'a-inbox-de-captura-do-atlas',
                'why_now' => 'Apos a base de memoria, faz sentido mostrar como informacao entra no sistema.',
                'required_foundation' => ['context_need', 'memory_vs_knowledge', 'memory_ledger'],
            ],
            [
                'arc' => 'architecture_deep_dive',
                'title' => 'Arquitetura do Atlas',
                'first_post_slug' => 'o-modelo-de-sessao-do-atlas',
                'why_now' => 'Arquitetura profunda so deve vir depois da linguagem publica de contexto, memoria e agentes.',
                'required_foundation' => ['vocabulary', 'memory_database', 'memory_ledger', 'agents_intro'],
            ],
            [
                'arc' => 'product_ux',
                'title' => 'Produto e UX',
                'first_post_slug' => 'por-que-ia-pessoal-precisa-de-ux-calma',
                'why_now' => 'A base tecnica permite discutir experiencia sem parecer marketing solto.',
                'required_foundation' => ['atlas_identity', 'atlas_boundaries', 'control'],
            ],
        ];

        $foundationByKey = [];
        foreach ($foundation as $item) {
            $foundationByKey[(string) $item['key']] = (bool) $item['planned'];
        }

        return array_map(function (array $arc) use ($foundationByKey, $foundationComplete, $plannedSet): array {
            $missing = array_values(array_filter(
                (array) $arc['required_foundation'],
                fn (string $key): bool => ! ($foundationByKey[$key] ?? false),
            ));

            return $arc + [
                'ready_after_first_month' => $foundationComplete && $missing === [] && ! isset($plannedSet[(string) $arc['first_post_slug']]),
                'missing_foundation' => $missing,
                'already_planned' => isset($plannedSet[(string) $arc['first_post_slug']]),
            ];
        }, $arcs);
    }

    /**
     * @param  array<string,mixed>  $post
     * @param  array<int,array<string,mixed>>  $posts
     * @return array{previous:?array<string,mixed>,next:?array<string,mixed>}
     */
    private function neighborPosts(array $post, array $posts): array
    {
        $order = (int) ($post['order'] ?? 0);
        $previous = null;
        $next = null;

        foreach ($posts as $candidate) {
            $candidateOrder = (int) ($candidate['order'] ?? 0);
            if ($candidateOrder === $order - 1) {
                $previous = $this->compactPostRef($candidate);
            }
            if ($candidateOrder === $order + 1) {
                $next = $this->compactPostRef($candidate);
            }
        }

        return [
            'previous' => $previous,
            'next' => $next,
        ];
    }

    /**
     * @param  array<string,mixed>  $post
     * @return array<string,mixed>
     */
    private function compactPostRef(array $post): array
    {
        return [
            'order' => (int) ($post['order'] ?? 0),
            'title' => (string) ($post['title'] ?? ''),
            'slug' => (string) ($post['slug'] ?? ''),
            'complexity_level' => (string) ($post['complexity_level'] ?? ''),
            'main_question' => (string) ($post['main_question'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $post
     * @param  array<int,array<string,mixed>>  $posts
     * @return array<int,string>
     */
    private function futureTopicsToAvoid(array $post, array $posts): array
    {
        $order = (int) ($post['order'] ?? 0);
        $currentTerms = array_fill_keys($this->terms($post), true);
        $futureTerms = [];

        foreach ($posts as $future) {
            if ((int) ($future['order'] ?? 0) <= $order) {
                continue;
            }

            foreach ($this->terms($future) as $term) {
                if (! isset($currentTerms[$term])) {
                    $futureTerms[$term] = true;
                }
            }
        }

        return array_slice(array_keys($futureTerms), 0, 12);
    }

    /**
     * @param  array<string,mixed>  $post
     */
    private function readerPromise(array $post): string
    {
        $level = (string) ($post['complexity_level'] ?? '');

        return match ($level) {
            'L0' => 'Ao final, a pessoa entende o mapa basico sem precisar conhecer a arquitetura.',
            'L1' => 'Ao final, a pessoa entende o problema e por que ele importa.',
            'L2' => 'Ao final, a pessoa entende por que a solucao obvia falha.',
            'L3' => 'Ao final, a pessoa entende a decisao de design e seus tradeoffs.',
            'L4' => 'Ao final, a pessoa entende como a decisao aparece na implementacao.',
            default => 'Ao final, a pessoa entende uma ideia clara e sabe qual texto ler depois.',
        };
    }

    /**
     * @param  array<string,mixed>  $post
     * @return array<int,string>
     */
    private function outlineForPost(array $post): array
    {
        $level = (string) ($post['complexity_level'] ?? '');

        return match ($level) {
            'L0' => [
                'Abrir com a situacao em linguagem simples.',
                'Definir o conceito principal sem jargao.',
                'Mostrar por que isso importa para uma pessoa real.',
                'Dizer o que fica fora deste texto.',
                'Fechar apontando a proxima leitura.',
            ],
            'L1' => [
                'Nomear o problema com um exemplo concreto.',
                'Mostrar por que a resposta comum ainda nao resolve.',
                'Conectar o problema ao Atlas sem entrar fundo em arquitetura.',
                'Explicar a tensao principal.',
                'Fechar com a pergunta que o proximo texto responde.',
            ],
            'L2' => [
                'Apresentar a solucao ingenua.',
                'Mostrar onde ela parece funcionar.',
                'Mostrar onde ela quebra.',
                'Extrair o principio de design que nasce dessa falha.',
                'Preparar o leitor para a decisao tecnica posterior.',
            ],
            'L3', 'L4' => [
                'Recapitular a base ja publicada em poucas linhas.',
                'Apresentar a decisao ou arquitetura.',
                'Explicar tradeoffs e limites.',
                'Mostrar como Atlas pensa nisso sem vazar detalhes privados.',
                'Fechar com consequencia pratica e proxima leitura.',
            ],
            default => [
                'Abrir com a pergunta principal.',
                'Desenvolver uma ideia por vez.',
                'Manter exemplos concretos.',
                'Fechar com continuidade editorial.',
            ],
        };
    }

    /**
     * @param  array<string,mixed>  $post
     * @return array<int,string>
     */
    private function mustInclude(array $post): array
    {
        return array_values(array_filter([
            (string) ($post['main_question'] ?? '') !== '' ? 'Responder explicitamente: '.((string) $post['main_question']) : null,
            (string) ($post['goal'] ?? '') !== '' ? 'Cumprir objetivo editorial: '.((string) $post['goal']) : null,
            'Um exemplo concreto ligado ao uso real do Atlas.',
            'Uma frase de transicao para a proxima leitura.',
        ]));
    }

    /**
     * @param  array<string,mixed>  $post
     * @return array<int,string>
     */
    private function mustNotInclude(array $post): array
    {
        $level = (string) ($post['complexity_level'] ?? '');
        $items = [
            'Nao expor paths locais, tokens, prompts, traces ou detalhes privados.',
            'Nao transformar o texto em pitch de startup.',
            'Nao publicar codigo ou arquitetura sensivel sem revisao humana.',
        ];

        if (in_array($level, ['L0', 'L1'], true)) {
            $items[] = 'Nao antecipar implementacao profunda, ledger, graph/RAG ou mandatos se isso ainda nao foi introduzido.';
        }

        return $items;
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function reviewQueueSnippet(array $entry): string
    {
        $lines = [
            '  - status: "'.$this->escapeYamlString((string) $entry['status']).'"',
            '    accepted_at: "'.$this->escapeYamlString((string) $entry['accepted_at']).'"',
            '    title: "'.$this->escapeYamlString((string) $entry['title']).'"',
            '    slug: "'.$this->escapeYamlString((string) $entry['slug']).'"',
            '    collection: "'.$this->escapeYamlString((string) $entry['collection']).'"',
            '    series: "'.$this->escapeYamlString((string) $entry['series']).'"',
            '    complexity_level: "'.$this->escapeYamlString((string) $entry['complexity_level']).'"',
            '    main_question: "'.$this->escapeYamlString((string) $entry['main_question']).'"',
            '    suggested_after_slug: "'.$this->escapeYamlString((string) $entry['suggested_after_slug']).'"',
            '    source_type: "'.$this->escapeYamlString((string) $entry['source_type']).'"',
            '    source_ref: "'.$this->escapeYamlString((string) $entry['source_ref']).'"',
            '    topics:',
        ];

        foreach ((array) $entry['topics'] as $topic) {
            $lines[] = '      - "'.$this->escapeYamlString((string) $topic).'"';
        }

        $lines[] = '    why: "'.$this->escapeYamlString((string) $entry['why']).'"';

        return implode("\n", $lines)."\n";
    }

    /**
     * @return array<string,mixed>|null
     */
    private function queuedCandidateEntry(string $reviewQueuePath, string $candidateSlug): ?array
    {
        $raw = (string) file_get_contents($reviewQueuePath);
        $blocks = preg_split('/(?=^  - status:)/m', $raw) ?: [];

        foreach ($blocks as $block) {
            if (! str_contains($block, 'slug: "'.$this->escapeYamlString($candidateSlug).'"')) {
                continue;
            }

            $entry = [];
            $lines = preg_split('/\R/', $block) ?: [];
            $readingTopics = false;

            foreach ($lines as $line) {
                if (preg_match('/^\s{4}([a-z_]+):\s*"(.*)"\s*$/', $line, $matches)) {
                    $entry[$matches[1]] = $this->unescapeYamlString($matches[2]);
                    $readingTopics = false;
                    continue;
                }

                if (preg_match('/^\s{4}topics:\s*$/', $line)) {
                    $entry['topics'] = [];
                    $readingTopics = true;
                    continue;
                }

                if ($readingTopics && preg_match('/^\s{6}-\s*"(.*)"\s*$/', $line, $matches)) {
                    $entry['topics'][] = $this->unescapeYamlString($matches[1]);
                    continue;
                }
            }

            return isset($entry['slug']) && $entry['slug'] === $candidateSlug ? $entry : null;
        }

        return null;
    }

    /**
     * @param  array{week:int,theme:string,goal:string,post:array<string,mixed>}  $week
     */
    private function promotionWeekSnippet(array $week): string
    {
        $post = $week['post'];
        $lines = [
            '  - week: '.$week['week'],
            '    theme: "'.$this->escapeYamlString($week['theme']).'"',
            '    goal: "'.$this->escapeYamlString($week['goal']).'"',
            '    posts:',
            '      - order: '.((int) $post['order']),
            '        title: "'.$this->escapeYamlString((string) $post['title']).'"',
            '        slug: "'.$this->escapeYamlString((string) $post['slug']).'"',
            '        type: "'.$this->escapeYamlString((string) $post['type']).'"',
            '        complexity_level: "'.$this->escapeYamlString((string) $post['complexity_level']).'"',
            '        collection: "'.$this->escapeYamlString((string) $post['collection']).'"',
            '        series: "'.$this->escapeYamlString((string) $post['series']).'"',
            '        reader_level: "'.$this->escapeYamlString((string) $post['reader_level']).'"',
            '        goal: "'.$this->escapeYamlString((string) $post['goal']).'"',
            '        main_question: "'.$this->escapeYamlString((string) $post['main_question']).'"',
        ];

        $prerequisites = array_values(array_filter((array) $post['prerequisites'], 'is_string'));
        if ($prerequisites === []) {
            $lines[] = '        prerequisites: []';
        } else {
            $lines[] = '        prerequisites:';
            foreach ($prerequisites as $prerequisite) {
                $lines[] = '          - "'.$this->escapeYamlString($prerequisite).'"';
            }
        }

        $lines[] = '        next_reading: []';
        $lines[] = '        topics:';
        foreach (array_values(array_filter((array) $post['topics'], 'is_string')) as $topic) {
            $lines[] = '          - "'.$this->escapeYamlString($topic).'"';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     */
    private function lastPostSlug(array $posts): string
    {
        $last = collect($posts)->sortByDesc(fn (array $post): int => (int) ($post['order'] ?? 0))->first();

        return is_array($last) ? (string) ($last['slug'] ?? '') : '';
    }

    private function readerLevelForComplexity(string $complexity): string
    {
        return in_array($complexity, ['L3', 'L4', 'L5'], true) ? 'intermediate' : 'beginner';
    }

    private function escapeYamlString(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    private function unescapeYamlString(string $value): string
    {
        return str_replace(['\\"', '\\\\'], ['"', '\\'], $value);
    }
}
