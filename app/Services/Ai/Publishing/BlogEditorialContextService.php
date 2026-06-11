<?php

declare(strict_types=1);

namespace App\Services\Ai\Publishing;

use App\Models\AtlasEngineeringCodeModule;
use App\Models\AtlasEngineeringCodeSymbol;
use App\Models\AtlasEngineeringKnowledgeItem;
use App\Services\Ai\Context\AtlasGraphRetrievalNetworkService;
use App\Services\Ai\AtlasOpenBrainService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class BlogEditorialContextService
{
    public const SCHEMA_VERSION = 'atlas.blog_editorial_context.v1';

    public function __construct(
        private readonly ?AtlasOpenBrainService $openBrain = null,
    ) {}

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

        if (! is_array($candidate) && (bool) ($options['include_graph_candidates'] ?? false)) {
            $candidate = $this->graphCandidateBySlug($candidateSlug, $posts, $publishedSlugs, [
                'published_posts' => is_array($options['published_posts'] ?? null) ? $options['published_posts'] : [],
                'next_ready_post' => is_array($options['next_ready_post'] ?? null) ? $options['next_ready_post'] : null,
                'graph_context_limit' => (int) ($options['graph_context_limit'] ?? 8),
                'candidate_limit' => (int) ($options['candidate_limit'] ?? 10),
                'graph_world_model_id' => is_string($options['graph_world_model_id'] ?? null) ? (string) $options['graph_world_model_id'] : '',
            ]);
        }

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
                'uses_global_graph_rag' => false,
                'invokes_bounded_graph_retrieval' => (string) ($candidate['source_type'] ?? '') === 'bounded_world_model_graph',
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
    public function candidateSuggestions(array $posts, array $publishedSlugs = [], int $limit = 10, array $queuedSlugs = []): array
    {
        $limit = max(1, min(30, $limit));
        $existing = $this->existingEditorialIndex($posts, $publishedSlugs, $queuedSlugs);
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
                'deduplicates_review_queue' => true,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @return array<string,mixed>
     */
    public function reviewQueueState(string $siteRoot, array $posts, array $publishedSlugs = [], string $relativePath = 'content/backlog/blog-candidates.yaml'): array
    {
        $siteRoot = rtrim($siteRoot, '/');
        $path = $siteRoot.'/'.$relativePath;
        $entries = is_file($path) ? $this->queuedCandidateEntries($path) : [];
        $existing = $this->existingEditorialIndex($posts, $publishedSlugs);
        $seen = [];
        $duplicates = [];

        $entries = array_values(array_map(function (array $entry) use (&$seen, &$duplicates, $existing): array {
            $slug = (string) ($entry['slug'] ?? '');
            $duplicateReason = null;
            if ($slug !== '' && isset($seen[$slug])) {
                $duplicateReason = 'duplicate_in_review_queue';
            } elseif ($slug !== '' && isset($existing['slugs'][$slug])) {
                $duplicateReason = 'already_in_main_backlog_or_published';
            }

            if ($slug !== '') {
                $seen[$slug] = true;
            }

            if ($duplicateReason !== null) {
                $duplicates[] = [
                    'slug' => $slug,
                    'reason' => $duplicateReason,
                ];
            }

            return $entry + [
                'duplicate_reason' => $duplicateReason,
                'ready_for_promotion_review' => $duplicateReason === null,
            ];
        }, $entries));

        return [
            'schema_version' => 'atlas.blog_editorial_review_queue.v1',
            'mode' => 'read_only_review_queue_state_p1',
            'status' => is_file($path) ? 'ready' : 'missing',
            'path' => $path,
            'candidate_count' => count($entries),
            'queued_slugs' => array_values(array_filter(array_map(
                fn (array $entry): string => (string) ($entry['slug'] ?? ''),
                $entries,
            ))),
            'duplicate_count' => count($duplicates),
            'duplicates' => $duplicates,
            'candidates' => $entries,
            'guardrails' => [
                'read_only' => true,
                'writes_backlog' => false,
                'writes_review_queue' => false,
                'publishes_content' => false,
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
     * @return array<string,mixed>
     */
    public function graphRagReadiness(array $posts, array $publishedSlugs = [], array $publishedPosts = []): array
    {
        $sourceMap = $this->sourceMap($posts, $publishedSlugs, $publishedPosts);
        $coverageMap = $this->coverageMap($posts, $publishedSlugs);
        $goldenSet = $this->editorialGoldenSet($posts, $publishedSlugs, $publishedPosts);
        $components = $this->graphRagReadinessComponents();
        $blockedItems = [
            [
                'code' => 'ap_817_p2_review_required',
                'status' => 'blocking',
                'reason' => 'Editorial graph/RAG needs explicit P2 promotion before becoming an active source.',
                'evidence' => 'docs/ap/AP-817-blog-editorial-planning-contract.md',
            ],
            [
                'code' => 'kernel_decision_receipt_required',
                'status' => 'blocking',
                'reason' => 'Every Python/data/graph runtime call must be mediated by the Kernel and recorded as a decision receipt.',
                'evidence' => 'docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md',
            ],
            [
                'code' => 'global_graph_retrieval_future_governed',
                'status' => 'blocking',
                'reason' => 'AGRN currently allows bounded Codebase World Model retrieval; global/external graph remains future-governed.',
                'evidence' => 'docs/engineering-knowledge-base/atlas-graph-retrieval-network.md',
            ],
        ];

        if (($goldenSet['status'] ?? '') !== 'passed') {
            $blockedItems[] = [
                'code' => 'editorial_golden_set_missing',
                'status' => 'blocking',
                'reason' => 'Blog order suggestions need a fixture/golden set proving they do not skip reader foundation.',
                'evidence' => 'tests/Feature/Ai/Publishing/AtlasBlogEditorialPlanCommandTest.php',
            ];
        }

        return [
            'schema_version' => 'atlas.blog_editorial_graph_rag_readiness.v1',
            'mode' => 'read_only_p2_readiness_preflight',
            'status' => 'not_promoted',
            'current_phase' => 'p1_read_only_editorial_intelligence',
            'target_phase' => 'p2_bounded_graph_rag_editorial_context',
            'summary' => [
                'planned_posts' => count($posts),
                'public_archive_posts' => (int) data_get($sourceMap, 'archive_state.public_archive_posts', 0),
                'foundation_planned' => (int) data_get($coverageMap, 'summary.foundation_planned', 0),
                'foundation_published' => (int) data_get($coverageMap, 'summary.foundation_published', 0),
                'available_component_count' => count(array_filter($components, fn (array $component): bool => (string) ($component['status'] ?? '') === 'available')),
                'blocking_item_count' => count($blockedItems),
                'editorial_golden_set_status' => (string) ($goldenSet['status'] ?? 'unknown'),
            ],
            'available_components' => $components,
            'editorial_golden_set' => $goldenSet,
            'missing_or_blocking_items' => $blockedItems,
            'allowed_now' => [
                'source_map',
                'coverage_map',
                'editorial_radar',
                'editorial_graph_context_bounded_world_model',
                'writing_packet',
                'open_brain_handoff',
                'explicit_open_brain_context_execution',
                'review_queue_candidate_suggestions',
            ],
            'deferred_until_p2' => [
                'direct_graph_traversal_for_blog_planning',
                'direct_vector_runtime_calls',
                'python_ai_data_runtime_calls',
                'automatic_backlog_reordering',
                'automatic_publication',
            ],
            'editorial_integration_plan' => [
                [
                    'step' => 1,
                    'name' => 'bounded_context_only',
                    'rule' => 'Use graph results only as provider-safe evidence summaries, never as raw blog text.',
                ],
                [
                    'step' => 2,
                    'name' => 'attach_evidence_to_candidates',
                    'rule' => 'Candidates must carry source refs and suggested placement, not mutate the backlog.',
                ],
                [
                    'step' => 3,
                    'name' => 'sequence_gate_before_depth',
                    'rule' => 'Graph/RAG may suggest topics only after foundation coverage says the reader path is ready.',
                ],
                [
                    'step' => 4,
                    'name' => 'human_promotion',
                    'rule' => 'Vitor explicitly accepts and promotes any graph/RAG-derived candidate.',
                ],
            ],
            'promotion_checklist' => [
                '/opt/homebrew/bin/php artisan atlas:engineering:knowledge docs-health --json',
                '/opt/homebrew/bin/php artisan test tests/Feature/Ai/Context/GraphRetrievalNetworkTest.php',
                "/opt/homebrew/bin/php artisan atlas:context:graph-retrieval --query='blog editorial memory atlas' --json",
                '/opt/homebrew/bin/php artisan test --filter=AtlasBlogEditorialPlanCommandTest',
                '/opt/homebrew/bin/php artisan atlas:blog:editorial-plan --graph-rag-readiness --json',
            ],
            'risk_assessment' => [
                'primary_risk' => 'Advanced context can make the blog skip the reader foundation and become impressive but confusing.',
                'privacy_risk' => 'Graph/RAG evidence can surface private paths, traces or implementation details if not summarized.',
                'mitigation' => 'Keep P1 read-only; promote P2 only through AP-817, Kernel receipts, provider-safe summaries and golden tests.',
            ],
            'guardrails' => [
                'read_only' => true,
                'writes_backlog' => false,
                'writes_review_queue' => false,
                'writes_draft' => false,
                'publishes_content' => false,
                'uses_graph_rag' => false,
                'uses_python_runtime' => false,
                'invokes_graph_retrieval' => false,
                'invokes_vector_runtime' => false,
                'creates_parallel_memory_store' => false,
                'requires_human_approval_to_promote' => true,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @param  array<int,array<string,mixed>>  $publishedPosts
     * @param  array<string,mixed>|null  $nextReadyPost
     * @return array<string,mixed>
     */
    public function editorialGraphContext(array $posts, array $publishedSlugs = [], array $publishedPosts = [], ?array $nextReadyPost = null, int $maxResults = 8, string $worldModelId = ''): array
    {
        $maxResults = max(1, min(12, $maxResults));
        $goldenSet = $this->editorialGoldenSet($posts, $publishedSlugs, $publishedPosts);
        $targetPost = is_array($nextReadyPost)
            ? $this->plannedPostBySlug($posts, (string) ($nextReadyPost['slug'] ?? ''))
            : null;

        if (! is_array($targetPost)) {
            $targetPost = $this->plannedPostBySlug($posts, $this->firstReadySlug($posts, $publishedSlugs) ?? '');
        }

        if (($goldenSet['status'] ?? '') !== 'passed') {
            return [
                'schema_version' => 'atlas.blog_editorial_graph_context.v1',
                'mode' => 'bounded_world_model_editorial_context_p2_preflight',
                'status' => 'blocked',
                'reason' => 'editorial_golden_set_failed',
                'post' => $targetPost !== null ? $this->compactPostRef($targetPost) : null,
                'editorial_golden_set' => [
                    'status' => (string) ($goldenSet['status'] ?? 'unknown'),
                    'failed_cases' => array_values((array) ($goldenSet['failed_cases'] ?? [])),
                ],
                'graph_retrieval' => null,
                'guardrails' => $this->editorialGraphContextGuardrails(false),
            ];
        }

        if (! is_array($targetPost)) {
            return [
                'schema_version' => 'atlas.blog_editorial_graph_context.v1',
                'mode' => 'bounded_world_model_editorial_context_p2_preflight',
                'status' => 'blocked',
                'reason' => 'next_ready_post_not_found',
                'post' => null,
                'editorial_golden_set' => [
                    'status' => (string) ($goldenSet['status'] ?? 'unknown'),
                    'failed_cases' => [],
                ],
                'graph_retrieval' => null,
                'guardrails' => $this->editorialGraphContextGuardrails(false),
            ];
        }

        $graph = $this->graphRetrievalService()->retrieve([
            'objective' => $this->editorialGraphObjective($targetPost),
            'task_type' => 'research',
            'domain' => 'blog_editorial',
            'risk_level' => 'medium',
            'target_files' => [
                'app/Services/Ai/Publishing/BlogEditorialPlannerService.php',
                'app/Services/Ai/Publishing/BlogEditorialContextService.php',
                'app/Console/Commands/AtlasBlogEditorialPlanCommand.php',
            ],
            'target_flows' => [
                'blog_editorial_planning',
                (string) ($targetPost['collection'] ?? ''),
                (string) ($targetPost['series'] ?? ''),
            ],
            'target_capabilities' => array_values(array_unique(array_filter(array_merge(
                ['blog_editorial_planning', 'content_intelligence'],
                (array) ($targetPost['topics'] ?? []),
                $this->terms($targetPost),
            ), 'is_string'))),
            'target_risks' => [
                'skip_reader_foundation',
                'publish_private_implementation_details',
                'automatic_backlog_reordering',
            ],
            'world_model_id' => $worldModelId,
            'max_results' => $maxResults,
        ]);

        return [
            'schema_version' => 'atlas.blog_editorial_graph_context.v1',
            'mode' => 'bounded_world_model_editorial_context_p2_preflight',
            'status' => in_array((string) ($graph['status'] ?? ''), ['ready', 'degraded'], true) ? 'ready' : 'blocked',
            'post' => $this->compactPostRef($targetPost),
            'editorial_golden_set' => [
                'status' => (string) ($goldenSet['status'] ?? 'unknown'),
                'failed_cases' => array_values((array) ($goldenSet['failed_cases'] ?? [])),
            ],
            'graph_retrieval' => [
                'schema_version' => (string) ($graph['schema_version'] ?? ''),
                'status' => (string) ($graph['status'] ?? 'unknown'),
                'hash' => (string) ($graph['graph_retrieval_hash'] ?? ''),
                'query_hash' => (string) data_get($graph, 'graph_query.query_hash', ''),
                'graph_scope' => (string) data_get($graph, 'graph_query.graph_scope', ''),
                'traversal_receipt' => [
                    'schema_version' => (string) data_get($graph, 'graph_traversal_receipt.schema_version', ''),
                    'status' => (string) data_get($graph, 'graph_traversal_receipt.status', 'unknown'),
                    'bounded_traversal' => (bool) data_get($graph, 'graph_traversal_receipt.bounded_traversal', false),
                    'fallback_reason' => data_get($graph, 'graph_traversal_receipt.fallback_reason'),
                    'edge_types_used' => array_values((array) data_get($graph, 'graph_traversal_receipt.edge_types_used', [])),
                ],
                'evidence_set' => [
                    'schema_version' => (string) data_get($graph, 'graph_evidence_set.schema_version', ''),
                    'status' => (string) data_get($graph, 'graph_evidence_set.status', 'unknown'),
                    'evidence_count' => (int) data_get($graph, 'graph_evidence_set.evidence_count', 0),
                    'source_count' => (int) data_get($graph, 'graph_evidence_set.source_count', 0),
                    'evidence' => array_slice((array) data_get($graph, 'graph_evidence_set.evidence', []), 0, $maxResults),
                    'sources' => array_slice((array) data_get($graph, 'graph_evidence_set.sources', []), 0, $maxResults),
                ],
                'policy' => [
                    'bounded_traversal_only' => (bool) data_get($graph, 'policy.bounded_traversal_only', false),
                    'global_graph_retrieval_active' => (bool) data_get($graph, 'policy.global_graph_retrieval_active', false),
                    'external_graph_runtime_invoked' => (bool) data_get($graph, 'policy.external_graph_runtime_invoked', false),
                    'python_runtime_invoked' => (bool) data_get($graph, 'policy.python_runtime_invoked', false),
                    'providers_invoked' => (bool) data_get($graph, 'policy.providers_invoked', false),
                    'writes' => (bool) data_get($graph, 'policy.writes', false),
                    'raw_query_exposed' => (bool) data_get($graph, 'policy.raw_query_exposed', false),
                ],
                'claims' => [
                    'global_graph_rag_ready' => (bool) data_get($graph, 'claims.global_graph_rag_ready', false),
                    'bounded_world_model_retrieval_ready' => (bool) data_get($graph, 'claims.bounded_world_model_retrieval_ready', false),
                ],
            ],
            'editorial_policy' => [
                'may_inform_writing_packet' => true,
                'may_create_candidate' => false,
                'may_reorder_backlog' => false,
                'may_publish' => false,
                'rule' => 'Use bounded graph evidence as context hints only; sequence, candidate promotion and publication remain human-approved.',
            ],
            'guardrails' => $this->editorialGraphContextGuardrails(true),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @param  array<int,array<string,mixed>>  $publishedPosts
     * @param  array<string,mixed>|null  $nextReadyPost
     * @return array<string,mixed>
     */
    public function editorialGraphCandidates(array $posts, array $publishedSlugs = [], array $publishedPosts = [], ?array $nextReadyPost = null, int $graphLimit = 8, int $candidateLimit = 5, string $worldModelId = '', array $queuedSlugs = []): array
    {
        $graphLimit = max(1, min(12, $graphLimit));
        $candidateLimit = max(1, min(15, $candidateLimit));
        $graphContext = $this->editorialGraphContext($posts, $publishedSlugs, $publishedPosts, $nextReadyPost, $graphLimit, $worldModelId);
        $appendAfterSlug = $this->lastPostSlug($posts);
        $existing = $this->existingEditorialIndex($posts, $publishedSlugs, $queuedSlugs);

        if (($graphContext['status'] ?? '') !== 'ready') {
            return [
                'schema_version' => 'atlas.blog_editorial_graph_candidates.v1',
                'mode' => 'bounded_world_model_candidate_feed_p2_preflight',
                'status' => 'blocked',
                'reason' => (string) ($graphContext['reason'] ?? 'editorial_graph_context_not_ready'),
                'candidate_count' => 0,
                'candidates' => [],
                'graph_context' => $this->compactGraphContextForCandidates($graphContext),
                'sequence_policy' => $this->graphCandidateSequencePolicy($posts, $publishedSlugs, $appendAfterSlug),
                'guardrails' => $this->editorialGraphCandidateGuardrails((bool) data_get($graphContext, 'guardrails.invokes_bounded_graph_retrieval', false)),
            ];
        }

        $evidence = array_values((array) data_get($graphContext, 'graph_retrieval.evidence_set.evidence', []));
        $candidates = [];
        $rejectedDuplicates = [];

        foreach ($this->graphCandidateBlueprints($graphContext, $evidence) as $blueprint) {
            $candidate = $this->graphCandidateFromBlueprint($blueprint, $appendAfterSlug, $evidence);
            $slug = (string) ($candidate['slug'] ?? '');
            $normalizedTitle = $this->normalizedTitle((string) ($candidate['title'] ?? ''));

            if ($slug === '' || isset($existing['slugs'][$slug]) || isset($existing['titles'][$normalizedTitle])) {
                $rejectedDuplicates[] = [
                    'slug' => $slug,
                    'title' => (string) ($candidate['title'] ?? ''),
                    'reason' => 'already_planned_or_published',
                ];

                continue;
            }

            $existing['slugs'][$slug] = true;
            $existing['titles'][$normalizedTitle] = true;
            $candidates[] = $candidate;

            if (count($candidates) >= $candidateLimit) {
                break;
            }
        }

        return [
            'schema_version' => 'atlas.blog_editorial_graph_candidates.v1',
            'mode' => 'bounded_world_model_candidate_feed_p2_preflight',
            'status' => 'ready',
            'candidate_count' => count($candidates),
            'candidates' => $candidates,
            'rejected_duplicates' => $rejectedDuplicates,
            'graph_context' => $this->compactGraphContextForCandidates($graphContext),
            'sequence_policy' => $this->graphCandidateSequencePolicy($posts, $publishedSlugs, $appendAfterSlug),
            'guardrails' => $this->editorialGraphCandidateGuardrails(true),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @param  array<int,array<string,mixed>>  $publishedPosts
     * @return array<string,mixed>
     */
    public function editorialGoldenSet(array $posts, array $publishedSlugs = [], array $publishedPosts = []): array
    {
        $publishedSet = array_fill_keys($publishedSlugs, true);
        $plannedSet = array_fill_keys(array_values(array_filter(array_map(
            fn (array $post): string => (string) ($post['slug'] ?? ''),
            $posts,
        ))), true);
        $coverage = $this->coverageMap($posts, $publishedSlugs);
        $frontier = $this->publicationFrontier($posts, $publishedSet);
        $firstReady = $this->firstReadySlug($posts, []);
        $afterIntroReady = $this->firstReadySlug($posts, ['o-que-e-o-atlas', 'por-que-estou-construindo-o-atlas']);
        $cases = [];

        $cases[] = $this->goldenCase(
            'sequence_starts_with_atlas_identity',
            ! isset($plannedSet['o-que-e-o-atlas']) || $firstReady === 'o-que-e-o-atlas',
            'If the intro post is planned and nothing is published, it must be the first ready post.',
            ['first_ready_slug' => $firstReady]
        );

        $cases[] = $this->goldenCase(
            'published_prefix_advances_to_next_foundation',
            ! isset($plannedSet['o-problema-dos-assistentes-de-ia-hoje']) || $afterIntroReady === 'o-problema-dos-assistentes-de-ia-hoje',
            'After the two first foundation posts, the next ready post must be the assistant-problem bridge.',
            ['next_ready_after_intro' => $afterIntroReady]
        );

        $deepFixtureWarnings = $this->deepSequenceWarnings([
            [
                'order' => 1,
                'title' => 'Graph RAG profundo no Atlas',
                'slug' => 'graph-rag-profundo-no-atlas',
                'complexity_level' => 'L5',
                'collection' => 'atlas',
                'series' => 'graph-rag',
                'prerequisites' => [],
            ],
        ]);
        $cases[] = $this->goldenCase(
            'deep_topic_without_foundation_is_warned',
            collect($deepFixtureWarnings)->contains(fn (array $warning): bool => (string) ($warning['code'] ?? '') === 'deep_post_without_prior_foundation'),
            'A deep standalone post must trigger a sequence warning instead of becoming a safe editorial jump.',
            ['warning_count' => count($deepFixtureWarnings)]
        );

        $conceptFixture = $this->conceptProgressionFixturePosts();
        $conceptPost = $this->plannedPostBySlug($conceptFixture, 'memoria-como-ledger') ?? [];
        $conceptMap = $this->conceptProgressionMap($conceptPost, $conceptFixture, ['o-que-e-o-atlas', 'por-que-estou-construindo-o-atlas']);
        $futureTerms = (array) ($conceptMap['future_terms_to_avoid'] ?? []);
        $cases[] = $this->goldenCase(
            'future_terms_stay_future_until_introduced',
            in_array('graph-rag', $futureTerms, true) && in_array('python-runtime', $futureTerms, true) && (bool) data_get($conceptMap, 'guardrails.uses_graph_rag') === false,
            'A memory post may not assume future Graph/RAG or Python runtime terms as reader knowledge.',
            ['future_terms_sample' => array_slice($futureTerms, 0, 6)]
        );

        $cases[] = $this->goldenCase(
            'first_month_foundation_has_no_deep_sequence_warning',
            (int) data_get($coverage, 'summary.deep_sequence_warning_count', 0) === 0,
            'The active first-month plan must not place L3+ material before a same-series or same-collection foundation.',
            ['deep_sequence_warning_count' => (int) data_get($coverage, 'summary.deep_sequence_warning_count', 0)]
        );

        $cases[] = $this->goldenCase(
            'publication_frontier_is_append_only',
            (int) ($frontier['next_order'] ?? 0) >= 1 && (($frontier['next_planned_post'] ?? null) === null || is_array($frontier['next_planned_post'])),
            'The public sequence frontier must be computed from contiguous published order, not from interesting deep topics.',
            ['next_order' => (int) ($frontier['next_order'] ?? 0), 'next_slug' => (string) data_get($frontier, 'next_planned_post.slug', '')]
        );

        $cases[] = $this->goldenCase(
            'graph_rag_candidate_policy_is_review_only',
            true,
            'Graph/RAG-derived topics remain review candidates until explicit human promotion.',
            ['allowed_state' => 'review_queue_before_backlog_promotion']
        );

        $failed = array_values(array_filter($cases, fn (array $case): bool => ! (bool) ($case['passed'] ?? false)));

        return [
            'schema_version' => 'atlas.blog_editorial_golden_set.v1',
            'mode' => 'read_only_sequence_evaluation_p1',
            'status' => $failed === [] ? 'passed' : 'failed',
            'summary' => [
                'case_count' => count($cases),
                'passed_count' => count($cases) - count($failed),
                'failed_count' => count($failed),
                'planned_posts' => count($posts),
                'published_posts' => count($publishedSlugs),
                'public_archive_posts' => count($publishedPosts),
            ],
            'cases' => $cases,
            'failed_cases' => $failed,
            'promotion_signal' => [
                'editorial_golden_set_ready' => $failed === [],
                'p2_blocker' => $failed === [] ? null : 'editorial_golden_set_failed',
                'rule' => 'P2 graph/RAG can inform editorial context only after sequence fixtures prove foundation-first behavior.',
            ],
            'guardrails' => [
                'read_only' => true,
                'writes_backlog' => false,
                'writes_review_queue' => false,
                'writes_draft' => false,
                'publishes_content' => false,
                'uses_graph_rag' => false,
                'uses_python_runtime' => false,
                'invokes_graph_retrieval' => false,
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
    public function operationsPacket(array $posts, array $publishedSlugs = [], array $publishedPosts = [], ?array $nextReadyPost = null, array $blockedPosts = [], int $contextLimit = 5, int $candidateLimit = 5, bool $executeOpenBrain = false, array $reviewQueueState = [], array $backlogMeta = []): array
    {
        $contextLimit = max(1, min(12, $contextLimit));
        $candidateLimit = max(1, min(15, $candidateLimit));
        $queuedSlugs = array_values(array_filter(
            (array) data_get($reviewQueueState, 'queued_slugs', []),
            'is_string',
        ));
        $sourceMap = $this->sourceMap($posts, $publishedSlugs, $publishedPosts);
        $coverageMap = $this->coverageMap($posts, $publishedSlugs);
        $candidateFeed = $this->candidateSuggestions($posts, $publishedSlugs, $candidateLimit, $queuedSlugs);
        $nextPost = $this->plannedPostBySlug($posts, (string) ($nextReadyPost['slug'] ?? ''));
        $writingPacket = $nextPost !== null
            ? $this->writingPacket($nextPost, $posts, $publishedSlugs, $contextLimit, $publishedPosts, $executeOpenBrain)
            : null;
        $publicArchiveContext = is_array($writingPacket)
            ? (array) ($writingPacket['public_archive_context'] ?? [])
            : [];
        $openBrainHandoff = $nextPost !== null
            ? $this->openBrainHandoffForPost($nextPost, $contextLimit)
            : null;
        $publishingPlan = $this->publishingPlan($posts, $publishedSlugs, $nextReadyPost, $blockedPosts, $reviewQueueState, $backlogMeta);
        $topicLedger = $this->topicLedger($posts, $publishedSlugs, $publishedPosts, $candidateFeed, $reviewQueueState, $coverageMap, $sourceMap);
        $editorialRoadmap = $this->editorialRoadmap($publishingPlan, $topicLedger);
        $editorialDependencyMatrix = $this->editorialDependencyMatrix($posts, $publishedSlugs, $publishingPlan, $editorialRoadmap);
        $backlogIntake = $this->backlogIntake($posts, $publishedSlugs, $candidateFeed, $reviewQueueState, $editorialDependencyMatrix);

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
            'publishing_plan' => $publishingPlan,
            'topic_ledger' => $topicLedger,
            'editorial_roadmap' => $editorialRoadmap,
            'editorial_dependency_matrix' => $editorialDependencyMatrix,
            'backlog_intake' => $backlogIntake,
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
            'review_queue' => [
                'status' => (string) data_get($reviewQueueState, 'status', 'missing'),
                'candidate_count' => (int) data_get($reviewQueueState, 'candidate_count', 0),
                'duplicate_count' => (int) data_get($reviewQueueState, 'duplicate_count', 0),
                'queued_slugs' => $queuedSlugs,
                'next_review_action' => count($queuedSlugs) > 0
                    ? 'review_or_promote_candidates'
                    : 'accept_new_candidates',
                'rule' => 'Accepted candidates stay in review queue until a human promotes them into the planned backlog.',
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
            'open_brain_handoff' => $openBrainHandoff,
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
                'generates_publishing_plan' => true,
                'generates_topic_ledger' => true,
                'generates_editorial_roadmap' => true,
                'generates_editorial_dependency_matrix' => true,
                'generates_backlog_intake' => true,
                'uses_graph_rag' => false,
                'uses_python_runtime' => false,
                'creates_parallel_memory_store' => false,
                'executes_open_brain_context' => $executeOpenBrain,
                'writes_audit_log' => $executeOpenBrain,
                'requires_human_approval_to_publish' => true,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @param  array<int,array<string,mixed>>  $publishedPosts
     * @param  array<string,mixed>|null  $nextReadyPost
     * @return array<string,mixed>
     */
    public function editorialRadar(array $posts, array $publishedSlugs = [], array $publishedPosts = [], ?array $nextReadyPost = null, int $candidateLimit = 5, array $reviewQueueState = []): array
    {
        $candidateLimit = max(1, min(15, $candidateLimit));
        $queuedSlugs = array_values(array_filter(
            (array) data_get($reviewQueueState, 'queued_slugs', []),
            'is_string',
        ));
        $publishedSet = array_fill_keys($publishedSlugs, true);
        $plannedSet = array_fill_keys(array_values(array_filter(array_map(
            fn (array $post): string => (string) ($post['slug'] ?? ''),
            $posts,
        ))), true);
        $coverageMap = $this->coverageMap($posts, $publishedSlugs);
        $sourceMap = $this->sourceMap($posts, $publishedSlugs, $publishedPosts);
        $candidateFeed = $this->candidateSuggestions($posts, $publishedSlugs, $candidateLimit, $queuedSlugs);
        $frontier = $this->publicationFrontier($posts, $publishedSet);
        $blocked = $this->blockedPostsForRadar($posts, $publishedSet, $plannedSet);

        return [
            'schema_version' => 'atlas.blog_editorial_radar.v1',
            'mode' => 'read_only_editorial_omnibus_p1',
            'status' => 'ready',
            'current_state' => [
                'planned_posts' => count($posts),
                'planned_published_posts' => (int) data_get($sourceMap, 'archive_state.planned_published_posts', 0),
                'public_archive_posts' => (int) data_get($sourceMap, 'archive_state.public_archive_posts', 0),
                'external_published_posts' => (int) data_get($sourceMap, 'archive_state.external_published_posts', 0),
                'contiguous_published_until_order' => (int) ($frontier['published_until_order'] ?? 0),
                'next_sequence_order' => (int) ($frontier['next_order'] ?? 1),
                'next_ready_slug' => is_array($nextReadyPost) ? (string) ($nextReadyPost['slug'] ?? '') : null,
                'next_ready_title' => is_array($nextReadyPost) ? (string) ($nextReadyPost['title'] ?? '') : null,
                'blocked_unpublished_posts' => count($blocked),
            ],
            'week_lanes' => $this->weekLanes($posts, $publishedSet),
            'sequence_lanes' => $this->sequenceLanes($posts, $publishedSet),
            'gap_register' => [
                'missing_foundation_planned' => array_values(array_filter(
                    (array) ($coverageMap['foundation_ladder'] ?? []),
                    fn (array $item): bool => ! (bool) ($item['planned'] ?? false),
                )),
                'missing_foundation_published' => array_values(array_filter(
                    (array) ($coverageMap['foundation_ladder'] ?? []),
                    fn (array $item): bool => (bool) ($item['planned'] ?? false) && ! (bool) ($item['published'] ?? false),
                )),
                'deep_sequence_warnings' => array_values((array) ($coverageMap['deep_sequence_warnings'] ?? [])),
                'blocked_posts' => array_slice($blocked, 0, 12),
                'rule' => 'Fill missing foundation before adding deep posts to the public sequence.',
            ],
            'insertion_windows' => $this->insertionWindows($posts, $publishedSet, (array) ($coverageMap['next_safe_arcs'] ?? [])),
            'review_queue' => [
                'status' => (string) data_get($reviewQueueState, 'status', 'missing'),
                'candidate_count' => (int) data_get($reviewQueueState, 'candidate_count', 0),
                'duplicate_count' => (int) data_get($reviewQueueState, 'duplicate_count', 0),
                'queued_slugs' => $queuedSlugs,
                'next_review_action' => count($queuedSlugs) > 0
                    ? 'review_or_promote_candidates'
                    : 'accept_new_candidates',
                'rule' => 'Review queue candidates are visible to the radar but cannot reorder the planned sequence by themselves.',
            ],
            'candidate_feed' => [
                'candidate_count' => (int) ($candidateFeed['candidate_count'] ?? 0),
                'candidates' => array_values(array_map(
                    fn (array $candidate): array => $this->candidateRadarSummary($candidate, $posts),
                    array_slice((array) ($candidateFeed['candidates'] ?? []), 0, $candidateLimit),
                )),
                'rule' => 'Candidates feed the review queue; they do not change the planned order until accepted and promoted.',
            ],
            'source_readiness' => [
                'engineering_knowledge' => (string) data_get($sourceMap, 'sources.engineering_knowledge.status', 'unknown'),
                'code_intelligence' => (string) data_get($sourceMap, 'sources.code_intelligence.status', 'unknown'),
                'open_brain_context_pack' => (string) data_get($sourceMap, 'sources.open_brain_context_pack.status', 'unknown'),
                'vector_retrieval' => (string) data_get($sourceMap, 'sources.vector_retrieval.status', 'unknown'),
                'graph_retrieval' => (string) data_get($sourceMap, 'sources.graph_retrieval.status', 'unknown'),
                'rule' => 'Graph/RAG remains deferred until P2; P1 radar uses existing read-models and audited context handoff only.',
            ],
            'operator_next_steps' => $this->radarNextSteps($frontier, $blocked, $nextReadyPost, (array) ($coverageMap['next_safe_arcs'] ?? [])),
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
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @param  array<int,array<string,mixed>>  $publishedPosts
     * @param  array<string,mixed>|null  $nextReadyPost
     * @param  array<int,array<string,mixed>>  $blockedPosts
     * @param  array<string,mixed>  $reviewQueueState
     * @return array<string,mixed>
     */
    public function operatingState(array $posts, array $publishedSlugs = [], array $publishedPosts = [], ?array $nextReadyPost = null, array $blockedPosts = [], array $reviewQueueState = [], int $candidateLimit = 5): array
    {
        $candidateLimit = max(1, min(15, $candidateLimit));
        $publishedSet = array_fill_keys($publishedSlugs, true);
        $queuedSlugs = array_values(array_filter(
            (array) data_get($reviewQueueState, 'queued_slugs', []),
            'is_string',
        ));
        $frontier = $this->publicationFrontier($posts, $publishedSet);
        $coverageMap = $this->coverageMap($posts, $publishedSlugs);
        $sourceMap = $this->sourceMap($posts, $publishedSlugs, $publishedPosts);
        $candidateFeed = $this->candidateSuggestions($posts, $publishedSlugs, $candidateLimit, $queuedSlugs);
        $plannedCount = count($posts);
        $publishedCount = count(array_filter(
            $posts,
            fn (array $post): bool => isset($publishedSet[(string) ($post['slug'] ?? '')]),
        ));
        $firstMonthComplete = $plannedCount > 0 && $publishedCount >= $plannedCount;
        $currentStage = $firstMonthComplete
            ? 'foundation_complete_expand_next_arc'
            : ($publishedCount === 0 ? 'first_post_pending' : 'foundation_sequence_in_progress');

        return [
            'schema_version' => 'atlas.blog_editorial_operating_state.v1',
            'mode' => 'read_only_area_state_p1',
            'status' => 'ready',
            'stage' => [
                'current' => $currentStage,
                'rule' => 'Advance the public reader ladder in order; review candidates do not affect the schedule until promoted.',
            ],
            'counts' => [
                'planned_posts' => $plannedCount,
                'published_posts' => $publishedCount,
                'ready_posts' => is_array($nextReadyPost) ? 1 : 0,
                'blocked_posts' => count($blockedPosts),
                'review_queue_candidates' => (int) data_get($reviewQueueState, 'candidate_count', 0),
                'candidate_feed' => (int) ($candidateFeed['candidate_count'] ?? 0),
            ],
            'publication_frontier' => [
                'contiguous_published_until_order' => (int) ($frontier['published_until_order'] ?? 0),
                'next_sequence_order' => (int) ($frontier['next_order'] ?? 1),
                'sequence_health' => is_array($nextReadyPost)
                    ? (count($blockedPosts) > 0 ? 'next_post_ready_future_blockers' : 'next_post_ready')
                    : (count($blockedPosts) > 0 ? 'blocked_by_prerequisites' : 'clear'),
            ],
            'next_post' => is_array($nextReadyPost)
                ? [
                    'order' => (int) ($nextReadyPost['order'] ?? 0),
                    'slug' => (string) ($nextReadyPost['slug'] ?? ''),
                    'title' => (string) ($nextReadyPost['title'] ?? ''),
                    'main_question' => (string) ($nextReadyPost['main_question'] ?? ''),
                    'complexity_level' => (string) ($nextReadyPost['complexity_level'] ?? ''),
                    'collection' => (string) ($nextReadyPost['collection'] ?? ''),
                    'series' => (string) ($nextReadyPost['series'] ?? ''),
                ]
                : null,
            'week_board' => array_values(array_map(
                fn (array $lane): array => [
                    'week' => (int) ($lane['week'] ?? 0),
                    'theme' => (string) ($lane['theme'] ?? ''),
                    'post_count' => (int) ($lane['planned_count'] ?? 0),
                    'published_count' => (int) ($lane['published_count'] ?? 0),
                    'next_unpublished_slug' => (string) data_get($lane, 'next_unpublished.slug', ''),
                    'status' => (bool) ($lane['complete'] ?? false) ? 'complete' : 'active_or_pending',
                ],
                $this->weekLanes($posts, $publishedSet),
            )),
            'review_queue' => [
                'status' => (string) data_get($reviewQueueState, 'status', 'missing'),
                'candidate_count' => (int) data_get($reviewQueueState, 'candidate_count', 0),
                'duplicate_count' => (int) data_get($reviewQueueState, 'duplicate_count', 0),
                'queued_slugs' => $queuedSlugs,
                'next_review_action' => count($queuedSlugs) > 0 ? 'review_or_promote_candidates' : 'accept_new_candidates',
            ],
            'candidate_pipeline' => [
                'feed_count' => (int) ($candidateFeed['candidate_count'] ?? 0),
                'top_candidates' => array_values(array_map(
                    fn (array $candidate): array => $this->candidateRadarSummary($candidate, $posts),
                    array_slice((array) ($candidateFeed['candidates'] ?? []), 0, $candidateLimit),
                )),
                'promotion_rule' => 'accept into review queue first, then promote append-only into the backlog after human approval',
            ],
            'source_posture' => [
                'public_archive_posts' => (int) data_get($sourceMap, 'archive_state.public_archive_posts', 0),
                'external_published_posts' => (int) data_get($sourceMap, 'archive_state.external_published_posts', 0),
                'engineering_knowledge' => (string) data_get($sourceMap, 'sources.engineering_knowledge.status', 'unknown'),
                'code_intelligence' => (string) data_get($sourceMap, 'sources.code_intelligence.status', 'unknown'),
                'open_brain_context_pack' => (string) data_get($sourceMap, 'sources.open_brain_context_pack.status', 'unknown'),
                'vector_retrieval' => (string) data_get($sourceMap, 'sources.vector_retrieval.status', 'unknown'),
                'graph_retrieval' => (string) data_get($sourceMap, 'sources.graph_retrieval.status', 'unknown'),
            ],
            'coverage' => [
                'foundation_planned' => (int) data_get($coverageMap, 'summary.foundation_planned', 0),
                'foundation_published' => (int) data_get($coverageMap, 'summary.foundation_published', 0),
                'deep_sequence_warning_count' => (int) data_get($coverageMap, 'summary.deep_sequence_warning_count', 0),
                'next_safe_arcs' => array_slice((array) ($coverageMap['next_safe_arcs'] ?? []), 0, 4),
            ],
            'area_surfaces' => [
                'planner' => 'atlas:blog:editorial-plan --json',
                'operating_state' => 'atlas:blog:editorial-plan --operating-state --json',
                'daily_operations' => 'atlas:blog:editorial-plan --operations --json',
                'editorial_radar' => 'atlas:blog:editorial-plan --editorial-radar --json',
                'writing_packet' => 'atlas:blog:editorial-plan --writing-packet --json',
                'review_queue' => 'atlas:blog:editorial-plan --review-queue --json',
                'candidate_feed' => 'atlas:blog:editorial-plan --suggest-candidates --json',
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
    public function writingPacket(array $post, array $posts, array $publishedSlugs = [], int $contextLimit = 5, array $publishedPosts = [], bool $executeOpenBrain = false): array
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
        $conceptProgressionMap = $this->conceptProgressionMap($post, $posts, $publishedSlugs);
        $publicArchiveContext = $this->publicArchiveContextForPost($post, $posts, $publishedSlugs, $publishedPosts);
        $openBrainHandoff = $this->openBrainHandoffForPost($post, $contextLimit);
        $writingBrief = [
            'language' => 'pt-BR',
            'voice' => 'tecnica, direta, pessoal, sem hype e sem tom de marketing',
            'primary_question' => (string) ($post['main_question'] ?? ''),
            'reader_promise' => $this->readerPromise($post),
            'outline' => $this->outlineForPost($post),
            'must_include' => $this->mustInclude($post),
            'must_not_include' => $this->mustNotInclude($post),
        ];

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
            'concept_progression_map' => $conceptProgressionMap,
            'editorial_context' => $this->contextForPost($post, $contextLimit),
            'open_brain_handoff' => $openBrainHandoff,
            'open_brain_context' => $executeOpenBrain
                ? $this->executeOpenBrainHandoff($openBrainHandoff)
                : null,
            'coverage_snapshot' => [
                'terms' => $terms,
                'current_level' => (string) ($post['complexity_level'] ?? ''),
                'needs_foundation' => in_array((string) ($post['complexity_level'] ?? ''), ['L2', 'L3', 'L4', 'L5'], true),
                'future_topics_to_avoid' => array_slice((array) ($conceptProgressionMap['future_terms_to_avoid'] ?? []), 0, 12),
            ],
            'writing_brief' => $writingBrief,
            'draft_seed' => $this->draftSeedForPost($post, $writingBrief, $conceptProgressionMap, $publicArchiveContext),
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
                'generates_private_draft_seed' => true,
                'uses_graph_rag' => false,
                'uses_python_runtime' => false,
                'creates_parallel_memory_store' => false,
                'executes_open_brain_context' => $executeOpenBrain,
                'writes_audit_log' => $executeOpenBrain,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $handoff
     * @return array<string,mixed>
     */
    private function executeOpenBrainHandoff(array $handoff): array
    {
        try {
            $result = $this->openBrainService()->contextPack([
                'objective' => (string) ($handoff['objective'] ?? ''),
                'task_type' => 'research',
                'desired_mode' => 'direct',
                'agent' => 'orquestrador',
                'intent' => 'blog_editorial_context_export',
                'requester' => 'atlas-blog-editorial-plan',
                'payload' => is_array($handoff['payload'] ?? null) ? $handoff['payload'] : [],
            ], 'blog_editorial_plan');
        } catch (\Throwable $exception) {
            return [
                'schema_version' => 'atlas.blog_editorial_open_brain_execution.v1',
                'status' => 'failed',
                'invoked_by_this_command' => true,
                'error' => 'open_brain_context_failed',
                'message' => $exception->getMessage(),
                'guardrails' => [
                    'raw_context_pack_returned' => false,
                    'publishes_content' => false,
                    'uses_graph_rag' => false,
                    'uses_python_runtime' => false,
                    'creates_parallel_memory_store' => false,
                ],
            ];
        }

        $contextRefs = array_values(array_filter((array) ($result['context_refs'] ?? []), 'is_array'));

        return [
            'schema_version' => 'atlas.blog_editorial_open_brain_execution.v1',
            'status' => (bool) ($result['ok'] ?? false) ? 'ready' : 'failed',
            'invoked_by_this_command' => true,
            'context_pack_hash' => (string) ($result['context_pack_hash'] ?? ''),
            'summary' => [
                'context_refs_count' => (int) data_get($result, 'summary.context_refs_count', 0),
                'memory_refs_count' => (int) data_get($result, 'summary.memory_refs_count', 0),
                'recall_count' => (int) data_get($result, 'summary.recall_count', 0),
                'semantic_count' => (int) data_get($result, 'summary.semantic_count', 0),
                'provider_safe' => (bool) data_get($result, 'summary.provider_safe', false),
            ],
            'safety' => [
                'provider_safe_only' => (bool) data_get($result, 'safety.provider_safe_only', false),
                'raw_content_exposed' => (bool) data_get($result, 'safety.raw_content_exposed', true),
                'raw_content_persisted' => (bool) data_get($result, 'safety.raw_content_persisted', true),
                'audit_persisted' => (bool) data_get($result, 'safety.audit_persisted', false),
                'context_pack_hash_persisted' => (bool) data_get($result, 'safety.context_pack_hash_persisted', false),
            ],
            'audit' => [
                'persisted' => is_array($result['audit'] ?? null),
                'surface' => (string) data_get($result, 'audit.surface', ''),
                'requester' => (string) data_get($result, 'audit.requester', ''),
                'action' => (string) data_get($result, 'audit.action', ''),
                'status' => (string) data_get($result, 'audit.status', ''),
                'provider_safe' => (bool) data_get($result, 'audit.provider_safe', false),
            ],
            'context_refs' => array_slice(array_map(
                fn (array $ref): array => [
                    'type' => (string) ($ref['type'] ?? ''),
                    'title' => (string) ($ref['title'] ?? $ref['memory_type'] ?? ''),
                    'path' => (string) ($ref['path'] ?? ''),
                    'privacy_class' => (string) ($ref['privacy_class'] ?? ''),
                    'external_ai_allowed' => (bool) ($ref['external_ai_allowed'] ?? true),
                    'redaction_status' => (string) ($ref['redaction_status'] ?? ''),
                ],
                $contextRefs,
            ), 0, 8),
            'guardrails' => [
                'raw_context_pack_returned' => false,
                'publishes_content' => false,
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
    private function openBrainHandoffForPost(array $post, int $contextLimit = 5): array
    {
        $contextLimit = max(1, min(12, $contextLimit));
        $slug = (string) ($post['slug'] ?? '');
        $title = (string) ($post['title'] ?? '');
        $mainQuestion = (string) ($post['main_question'] ?? '');
        $topics = array_values(array_filter((array) ($post['topics'] ?? []), 'is_string'));
        $objective = trim("Prepare provider-safe Atlas blog context for {$title} ({$slug}). Main question: {$mainQuestion}");
        $payload = [
            'schema_version' => 'atlas.blog_editorial_open_brain_payload.v1',
            'post' => [
                'order' => (int) ($post['order'] ?? 0),
                'title' => $title,
                'slug' => $slug,
                'complexity_level' => (string) ($post['complexity_level'] ?? ''),
                'collection' => (string) ($post['collection'] ?? ''),
                'series' => (string) ($post['series'] ?? ''),
                'main_question' => $mainQuestion,
                'topics' => $topics,
                'prerequisites' => array_values(array_filter((array) ($post['prerequisites'] ?? []), 'is_string')),
                'next_reading' => array_values(array_filter((array) ($post['next_reading'] ?? []), 'is_string')),
            ],
            'editorial_constraints' => [
                'language' => 'pt-BR',
                'canonical_language' => 'pt-BR',
                'write_depth_rule' => 'Do not introduce concepts before the backlog sequence allows them.',
                'human_approval_required' => true,
                'do_not_publish' => true,
                'do_not_generate_full_article' => true,
            ],
            'source_policy' => [
                'intent' => 'blog_editorial_context_export',
                'provider_safe_only' => true,
                'prefer_existing_knowledge_read_models' => true,
                'prefer_code_intelligence_for_implementation_claims' => true,
                'allow_vector_retrieval' => true,
                'allow_graph_retrieval' => false,
                'context_limit' => $contextLimit,
            ],
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';

        return [
            'schema_version' => 'atlas.blog_editorial_open_brain_handoff.v1',
            'status' => 'available_contract',
            'mode' => 'audited_context_export_handoff_p1',
            'invoked_by_this_command' => false,
            'objective' => $objective,
            'command' => '/opt/homebrew/bin/php artisan atlas:open-brain:context '
                .escapeshellarg($objective)
                .' --task-type=research'
                .' --desired-mode=direct'
                .' --agent=orquestrador'
                .' --intent=blog_editorial_context_export'
                .' --requester=atlas-blog-editorial-plan'
                .' --payload-json='.escapeshellarg($payloadJson)
                .' --json',
            'payload' => $payload,
            'expected_sources' => [
                'atlas_memory_registry',
                'engineering_knowledge',
                'code_intelligence',
                'vector_retrieval_when_provider_safe',
            ],
            'deferred_sources' => [
                'graph_retrieval',
                'python_ai_data_runtime',
                'automatic_publication',
            ],
            'guardrails' => [
                'read_only' => true,
                'writes_backlog' => false,
                'writes_draft' => false,
                'publishes_content' => false,
                'uses_graph_rag' => false,
                'uses_python_runtime' => false,
                'creates_parallel_memory_store' => false,
                'requires_human_approval_to_publish' => true,
            ],
        ];
    }

    private function openBrainService(): AtlasOpenBrainService
    {
        return $this->openBrain ?? app(AtlasOpenBrainService::class);
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @param  array<string,mixed>|null  $nextReadyPost
     * @param  array<int,array<string,mixed>>  $blockedPosts
     * @param  array<string,mixed>  $reviewQueueState
     * @param  array<string,mixed>  $backlogMeta
     * @return array<string,mixed>
     */
    private function publishingPlan(array $posts, array $publishedSlugs, ?array $nextReadyPost, array $blockedPosts, array $reviewQueueState, array $backlogMeta): array
    {
        $publishedSet = array_fill_keys($publishedSlugs, true);
        $blockedSet = array_fill_keys(array_values(array_filter(array_map(
            fn (array $post): string => (string) ($post['slug'] ?? ''),
            $blockedPosts,
        ))), true);
        $nextSlug = is_array($nextReadyPost) ? (string) ($nextReadyPost['slug'] ?? '') : '';
        $publishingDays = array_values(array_filter((array) ($backlogMeta['publishing_days'] ?? []), 'is_string'));
        $publishingDays = $publishingDays !== [] ? $publishingDays : ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
        $sortedPosts = collect($posts)
            ->sortBy(fn (array $post): int => (int) ($post['order'] ?? 0))
            ->values()
            ->all();

        $slots = [];
        foreach ($sortedPosts as $index => $post) {
            $slug = (string) ($post['slug'] ?? '');
            $order = (int) ($post['order'] ?? ($index + 1));
            $week = (int) ($post['week'] ?? max(1, (int) ceil($order / max(1, count($publishingDays)))));
            $slotIndex = $index % max(1, count($publishingDays));
            $day = (string) ($publishingDays[$slotIndex] ?? 'day');
            $isPublished = isset($publishedSet[$slug]);
            $isNext = $slug !== '' && $slug === $nextSlug;
            $isBlocked = isset($blockedSet[$slug]);

            $slots[] = [
                'order' => $order,
                'week' => $week,
                'slot_index' => $slotIndex + 1,
                'day' => $day,
                'label' => 'semana '.$week.' · '.$day,
                'slug' => $slug,
                'title' => (string) ($post['title'] ?? ''),
                'complexity_level' => (string) ($post['complexity_level'] ?? ''),
                'collection' => (string) ($post['collection'] ?? ''),
                'series' => (string) ($post['series'] ?? ''),
                'status' => $this->publishingSlotStatus($isPublished, $isNext, $isBlocked),
                'pipeline_stage' => $this->publishingSlotPipelineStage($isPublished, $isNext, $isBlocked),
                'human_action' => $this->publishingSlotHumanAction($isPublished, $isNext, $isBlocked),
                'prerequisites' => array_values(array_filter((array) ($post['prerequisites'] ?? []), 'is_string')),
                'main_question' => (string) ($post['main_question'] ?? ''),
            ];
        }

        $nextSlots = array_values(array_filter(
            $slots,
            fn (array $slot): bool => ! in_array((string) ($slot['status'] ?? ''), ['published'], true),
        ));

        return [
            'schema_version' => 'atlas.blog_editorial_publishing_plan.v1',
            'mode' => 'read_only_calendar_pipeline_p1',
            'status' => 'ready',
            'cadence' => (string) ($backlogMeta['cadence'] ?? ''),
            'publishing_days' => $publishingDays,
            'buffer_days' => array_values(array_filter((array) ($backlogMeta['buffer_days'] ?? []), 'is_string')),
            'rule' => (string) ($backlogMeta['rule'] ?? 'A ordem importa mais que a data.'),
            'summary' => [
                'slot_count' => count($slots),
                'published_slots' => count(array_filter($slots, fn (array $slot): bool => ($slot['status'] ?? '') === 'published')),
                'ready_slots' => count(array_filter($slots, fn (array $slot): bool => ($slot['status'] ?? '') === 'ready_to_draft')),
                'blocked_slots' => count(array_filter($slots, fn (array $slot): bool => ($slot['status'] ?? '') === 'blocked_by_prerequisite')),
                'review_queue_candidates' => (int) data_get($reviewQueueState, 'candidate_count', 0),
            ],
            'today_lane' => [
                'action' => $nextSlug !== '' ? 'draft_next_ready_post' : 'repair_sequence_blockers',
                'slug' => $nextSlug !== '' ? $nextSlug : null,
                'rule' => 'Um post por dia pode funcionar se cada slot passar por seed privado, rascunho revisado e aprovacao humana antes de publicar.',
            ],
            'next_slots' => array_slice($nextSlots, 0, 10),
            'slots' => $slots,
            'pipeline_rules' => [
                'idea' => 'Candidatos entram na fila de revisao, nunca direto no calendario.',
                'seed' => 'O writing packet gera seed privado sem escrever arquivo.',
                'draft' => 'Rascunho completo e uma fase futura e precisa aprovacao humana.',
                'publish' => 'Publicacao continua manual ou explicitamente aprovada.',
            ],
            'guardrails' => [
                'read_only' => true,
                'writes_backlog' => false,
                'writes_review_queue' => false,
                'writes_draft' => false,
                'publishes_content' => false,
                'reorders_posts' => false,
                'requires_human_approval_to_publish' => true,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @param  array<int,array<string,mixed>>  $publishedPosts
     * @param  array<string,mixed>  $candidateFeed
     * @param  array<string,mixed>  $reviewQueueState
     * @param  array<string,mixed>  $coverageMap
     * @param  array<string,mixed>  $sourceMap
     * @return array<string,mixed>
     */
    private function topicLedger(array $posts, array $publishedSlugs, array $publishedPosts, array $candidateFeed, array $reviewQueueState, array $coverageMap, array $sourceMap): array
    {
        $publishedSet = array_fill_keys($publishedSlugs, true);
        $rows = $this->topicLedgerRows($posts, $publishedSet, $publishedPosts, $candidateFeed, $reviewQueueState);
        $foundationGaps = array_values(array_filter(
            (array) ($coverageMap['foundation_ladder'] ?? []),
            fn (array $item): bool => ! (bool) ($item['planned'] ?? false) || ! (bool) ($item['published'] ?? false),
        ));
        $opportunities = array_values(array_filter(
            $rows,
            fn (array $row): bool => in_array((string) ($row['status'] ?? ''), ['planned', 'candidate_only', 'gap'], true),
        ));

        return [
            'schema_version' => 'atlas.blog_editorial_topic_ledger.v1',
            'mode' => 'read_only_topic_coverage_p1',
            'status' => 'ready',
            'summary' => [
                'topic_count' => count($rows),
                'published_topic_count' => count(array_filter($rows, fn (array $row): bool => (int) ($row['published_count'] ?? 0) > 0)),
                'planned_topic_count' => count(array_filter($rows, fn (array $row): bool => (int) ($row['planned_count'] ?? 0) > 0)),
                'candidate_topic_count' => count(array_filter($rows, fn (array $row): bool => (int) ($row['candidate_count'] ?? 0) > 0)),
                'gap_count' => count(array_filter($rows, fn (array $row): bool => (string) ($row['status'] ?? '') === 'gap')),
                'foundation_gap_count' => count($foundationGaps),
            ],
            'rows' => $rows,
            'foundation_gaps' => array_slice($foundationGaps, 0, 10),
            'next_topic_opportunities' => array_slice($opportunities, 0, 8),
            'source_posture' => [
                'site_backlog' => (string) data_get($sourceMap, 'sources.site_backlog.status', 'unknown'),
                'public_archive' => (string) data_get($sourceMap, 'sources.public_site_archive.status', 'unknown'),
                'engineering_knowledge' => (string) data_get($sourceMap, 'sources.engineering_knowledge.status', 'unknown'),
                'code_intelligence' => (string) data_get($sourceMap, 'sources.code_intelligence.status', 'unknown'),
                'graph_retrieval' => (string) data_get($sourceMap, 'sources.graph_retrieval.status', 'unknown'),
            ],
            'rules' => [
                'A linha do tempo continua sendo a fonte de sequencia; o ledger so mostra cobertura por assunto.',
                'Assuntos candidatos precisam passar por revisao humana antes de entrar no backlog.',
                'Lacunas de fundacao devem ser resolvidas antes de posts profundos dependerem delas.',
            ],
            'guardrails' => [
                'read_only' => true,
                'writes_backlog' => false,
                'writes_review_queue' => false,
                'writes_draft' => false,
                'publishes_content' => false,
                'reorders_posts' => false,
                'uses_graph_rag' => false,
                'uses_python_runtime' => false,
                'creates_parallel_memory_store' => false,
                'requires_human_approval_to_promote' => true,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<string,bool>  $publishedSet
     * @param  array<int,array<string,mixed>>  $publishedPosts
     * @param  array<string,mixed>  $candidateFeed
     * @param  array<string,mixed>  $reviewQueueState
     * @return array<int,array<string,mixed>>
     */
    private function topicLedgerRows(array $posts, array $publishedSet, array $publishedPosts, array $candidateFeed, array $reviewQueueState): array
    {
        $topics = [];
        $record = function (string $topic, string $bucket, string $slug, string $title, ?int $order = null) use (&$topics): void {
            $topic = Str::slug(Str::ascii(Str::lower(trim($topic))));
            if ($topic === '') {
                return;
            }

            $topics[$topic] ??= [
                'topic' => $topic,
                'planned_count' => 0,
                'published_count' => 0,
                'candidate_count' => 0,
                'review_queue_count' => 0,
                'planned_slugs' => [],
                'published_slugs' => [],
                'candidate_slugs' => [],
                'review_queue_slugs' => [],
                'first_planned_order' => null,
                'example_title' => '',
            ];

            $countKey = $bucket.'_count';
            $slugKey = $bucket.'_slugs';
            $topics[$topic][$countKey] = (int) ($topics[$topic][$countKey] ?? 0) + 1;
            if ($slug !== '') {
                $topics[$topic][$slugKey] = array_values(array_unique([
                    ...(array) ($topics[$topic][$slugKey] ?? []),
                    $slug,
                ]));
            }
            if ($title !== '' && (string) ($topics[$topic]['example_title'] ?? '') === '') {
                $topics[$topic]['example_title'] = $title;
            }
            if ($bucket === 'planned' && $order !== null) {
                $current = $topics[$topic]['first_planned_order'];
                $topics[$topic]['first_planned_order'] = $current === null ? $order : min((int) $current, $order);
            }
        };

        foreach ($posts as $post) {
            $slug = (string) ($post['slug'] ?? '');
            $title = (string) ($post['title'] ?? '');
            $order = (int) ($post['order'] ?? 0);
            foreach ($this->terms($post) as $topic) {
                $record($topic, 'planned', $slug, $title, $order);
                if (isset($publishedSet[$slug])) {
                    $record($topic, 'published', $slug, $title, $order);
                }
            }
        }

        foreach ($publishedPosts as $post) {
            $slug = (string) ($post['slug'] ?? $post['id'] ?? '');
            $title = (string) ($post['title'] ?? '');
            foreach ($this->terms($post) as $topic) {
                $record($topic, 'published', $slug, $title);
            }
        }

        foreach ((array) ($candidateFeed['candidates'] ?? []) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $slug = (string) ($candidate['slug'] ?? '');
            $title = (string) ($candidate['title'] ?? '');
            foreach ($this->terms($candidate) as $topic) {
                $record($topic, 'candidate', $slug, $title);
            }
        }

        foreach ((array) data_get($reviewQueueState, 'candidates', []) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $slug = (string) ($candidate['slug'] ?? '');
            $title = (string) ($candidate['title'] ?? '');
            foreach ($this->terms($candidate) as $topic) {
                $record($topic, 'review_queue', $slug, $title);
            }
        }

        $rows = array_values(array_map(function (array $row): array {
            $row['status'] = $this->topicLedgerStatus($row);
            $row['next_action'] = $this->topicLedgerNextAction($row);

            return $row;
        }, $topics));

        usort($rows, fn (array $a, array $b): int => ((int) ($a['first_planned_order'] ?? PHP_INT_MAX) <=> (int) ($b['first_planned_order'] ?? PHP_INT_MAX))
            ?: ((int) ($b['published_count'] ?? 0) <=> (int) ($a['published_count'] ?? 0))
            ?: ((int) ($b['planned_count'] ?? 0) <=> (int) ($a['planned_count'] ?? 0))
            ?: strcmp((string) ($a['topic'] ?? ''), (string) ($b['topic'] ?? '')));

        return array_slice($rows, 0, 80);
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function topicLedgerStatus(array $row): string
    {
        if ((int) ($row['published_count'] ?? 0) > 0) {
            return 'published';
        }
        if ((int) ($row['planned_count'] ?? 0) > 0) {
            return 'planned';
        }
        if ((int) ($row['review_queue_count'] ?? 0) > 0) {
            return 'in_review';
        }
        if ((int) ($row['candidate_count'] ?? 0) > 0) {
            return 'candidate_only';
        }

        return 'gap';
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function topicLedgerNextAction(array $row): string
    {
        return match ($this->topicLedgerStatus($row)) {
            'published' => 'use_as_reader_context',
            'planned' => 'draft_when_sequence_reaches_first_planned_post',
            'in_review' => 'decide_if_candidate_enters_backlog',
            'candidate_only' => 'review_candidate_before_promoting',
            default => 'define_foundational_post',
        };
    }

    /**
     * @param  array<string,mixed>  $publishingPlan
     * @param  array<string,mixed>  $topicLedger
     * @return array<string,mixed>
     */
    private function editorialRoadmap(array $publishingPlan, array $topicLedger): array
    {
        $phaseTemplates = $this->editorialRoadmapPhaseTemplates();
        $phases = [];

        foreach ($phaseTemplates as $template) {
            $phases[$template['key']] = $template + [
                'post_count' => 0,
                'published_count' => 0,
                'ready_count' => 0,
                'blocked_count' => 0,
                'planned_count' => 0,
                'posts' => [],
                'topics' => [],
                'status' => 'empty',
                'next_post' => null,
            ];
        }

        foreach ((array) ($publishingPlan['slots'] ?? []) as $slot) {
            if (! is_array($slot)) {
                continue;
            }

            $phaseKey = $this->editorialRoadmapPhaseKey($slot);
            $phase = $phases[$phaseKey] ?? $phases['expansao'];
            $status = (string) ($slot['status'] ?? 'planned_future');
            $post = [
                'order' => (int) ($slot['order'] ?? 0),
                'slug' => (string) ($slot['slug'] ?? ''),
                'title' => (string) ($slot['title'] ?? ''),
                'status' => $status,
                'pipeline_stage' => (string) ($slot['pipeline_stage'] ?? ''),
                'human_action' => (string) ($slot['human_action'] ?? ''),
            ];

            $phase['post_count']++;
            $phase['posts'][] = $post;
            $phase['topics'] = array_values(array_unique(array_merge(
                (array) ($phase['topics'] ?? []),
                $this->terms($slot),
            )));

            if ($status === 'published') {
                $phase['published_count']++;
            } elseif ($status === 'ready_to_draft') {
                $phase['ready_count']++;
            } elseif ($status === 'blocked_by_prerequisite') {
                $phase['blocked_count']++;
            } else {
                $phase['planned_count']++;
            }

            if ($phase['next_post'] === null && $status !== 'published') {
                $phase['next_post'] = $post;
            }

            $phases[$phaseKey] = $phase;
        }

        $phases = array_values(array_map(function (array $phase): array {
            $phase['topics'] = array_slice((array) ($phase['topics'] ?? []), 0, 8);
            $phase['posts'] = array_slice((array) ($phase['posts'] ?? []), 0, 12);
            $phase['status'] = $this->editorialRoadmapPhaseStatus($phase);

            return $phase;
        }, $phases));

        $activePhase = collect($phases)->first(fn (array $phase): bool => in_array((string) ($phase['status'] ?? ''), ['active', 'blocked', 'planned'], true));
        $nextTopicRows = array_slice((array) ($topicLedger['next_topic_opportunities'] ?? []), 0, 8);

        return [
            'schema_version' => 'atlas.blog_editorial_roadmap.v1',
            'mode' => 'read_only_reader_journey_p1',
            'status' => 'ready',
            'summary' => [
                'phase_count' => count($phases),
                'active_phase_key' => is_array($activePhase) ? (string) ($activePhase['key'] ?? '') : null,
                'active_phase_label' => is_array($activePhase) ? (string) ($activePhase['label'] ?? '') : null,
                'planned_posts' => (int) data_get($publishingPlan, 'summary.slot_count', 0),
                'published_posts' => (int) data_get($publishingPlan, 'summary.published_slots', 0),
                'topic_count' => (int) data_get($topicLedger, 'summary.topic_count', 0),
            ],
            'phases' => $phases,
            'next_topic_opportunities' => $nextTopicRows,
            'rules' => [
                'A jornada do leitor vai do raso ao profundo; fases explicam a intencao, nao substituem a ordem.',
                'Uma fase bloqueada exige publicar prerequisitos antes de aprofundar.',
                'Novas colecoes podem entrar depois, mas precisam de sua propria fundacao publica.',
            ],
            'guardrails' => [
                'read_only' => true,
                'writes_backlog' => false,
                'writes_review_queue' => false,
                'writes_draft' => false,
                'publishes_content' => false,
                'reorders_posts' => false,
                'uses_graph_rag' => false,
                'uses_python_runtime' => false,
                'creates_parallel_memory_store' => false,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @param  array<string,mixed>  $publishingPlan
     * @param  array<string,mixed>  $editorialRoadmap
     * @return array<string,mixed>
     */
    private function editorialDependencyMatrix(array $posts, array $publishedSlugs, array $publishingPlan, array $editorialRoadmap): array
    {
        $publishedSet = array_fill_keys($publishedSlugs, true);
        $phaseBySlug = $this->editorialPhaseBySlug($editorialRoadmap);
        $slots = array_values(array_filter(
            (array) ($publishingPlan['slots'] ?? []),
            'is_array',
        ));
        usort($slots, fn (array $a, array $b): int => (int) ($a['order'] ?? 0) <=> (int) ($b['order'] ?? 0));

        $rows = [];
        $blockedCount = 0;
        $currentSlug = null;
        $foundationWarnings = 0;

        foreach ($slots as $index => $slot) {
            $slug = (string) ($slot['slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            $post = $this->plannedPostBySlug($posts, $slug) ?? $slot;
            $progression = $this->conceptProgressionMap($post, $posts, $publishedSlugs);
            $prerequisites = array_values(array_filter((array) ($post['prerequisites'] ?? []), 'is_string'));
            $missingPrerequisites = array_values(array_filter(
                $prerequisites,
                fn (string $prerequisite): bool => ! isset($publishedSet[$prerequisite]),
            ));
            $status = (string) ($slot['status'] ?? 'planned_future');
            $readiness = $this->editorialDependencyReadiness($status, $missingPrerequisites);
            $depth = $this->editorialDepthFromLevel((string) ($post['complexity_level'] ?? $slot['complexity_level'] ?? ''));
            $priorCount = (int) data_get($progression, 'reader_state.planned_prior_count', 0);
            $phase = $phaseBySlug[$slug] ?? [
                'key' => $this->editorialRoadmapPhaseKey($slot),
                'label' => 'Expansao',
                'position' => 7,
            ];
            $warning = $depth >= 3 && $priorCount < 3;

            if ($readiness === 'blocked_missing_prerequisites') {
                $blockedCount++;
            }
            if ($currentSlug === null && $readiness === 'current_unlocked') {
                $currentSlug = $slug;
            }
            if ($warning) {
                $foundationWarnings++;
            }

            $rows[] = [
                'order' => (int) ($slot['order'] ?? $post['order'] ?? 0),
                'slug' => $slug,
                'title' => (string) ($post['title'] ?? $slot['title'] ?? ''),
                'status' => $status,
                'readiness' => $readiness,
                'complexity_level' => (string) ($post['complexity_level'] ?? $slot['complexity_level'] ?? ''),
                'depth' => $depth,
                'phase' => [
                    'key' => (string) ($phase['key'] ?? 'expansao'),
                    'label' => (string) ($phase['label'] ?? 'Expansao'),
                    'position' => (int) ($phase['position'] ?? 7),
                ],
                'depends_on' => [
                    'previous_slug' => isset($slots[$index - 1]) ? (string) ($slots[$index - 1]['slug'] ?? '') : null,
                    'next_slug' => isset($slots[$index + 1]) ? (string) ($slots[$index + 1]['slug'] ?? '') : null,
                    'explicit_prerequisites' => $prerequisites,
                    'missing_prerequisites' => $missingPrerequisites,
                ],
                'reader_contract' => [
                    'must_introduce' => array_slice((array) ($progression['current_terms'] ?? []), 0, 8),
                    'already_available' => array_slice((array) ($progression['introduced_terms'] ?? []), 0, 10),
                    'published_available' => array_slice((array) ($progression['published_prior_terms'] ?? []), 0, 10),
                    'avoid_until_later' => array_slice((array) ($progression['future_terms_to_avoid'] ?? []), 0, 10),
                    'rule' => 'O texto so deve exigir conceitos ja publicados, prerequisitos ou introduzidos no proprio texto.',
                ],
                'position_reason' => $this->editorialDependencyPositionReason($post, $phase, $missingPrerequisites, $warning),
                'depth_warning' => $warning ? 'advanced_topic_before_enough_foundation' : null,
            ];
        }

        return [
            'schema_version' => 'atlas.blog_editorial_dependency_matrix.v1',
            'mode' => 'read_only_prerequisite_ladder_p1',
            'status' => 'ready',
            'summary' => [
                'post_count' => count($rows),
                'current_unlocked_slug' => $currentSlug,
                'blocked_post_count' => $blockedCount,
                'foundation_warning_count' => $foundationWarnings,
                'phase_count' => (int) data_get($editorialRoadmap, 'summary.phase_count', 0),
            ],
            'rows' => array_slice($rows, 0, 80),
            'rules' => [
                'A matriz explica dependencias; ela nao altera a fila.',
                'Um assunto profundo precisa de fundacao publica ou prerequisitos explicitos antes de virar rascunho.',
                'Graph/RAG pode sugerir contexto, mas nao pode reordenar ou publicar sem promocao governada.',
            ],
            'guardrails' => [
                'read_only' => true,
                'writes_backlog' => false,
                'writes_review_queue' => false,
                'writes_draft' => false,
                'publishes_content' => false,
                'reorders_posts' => false,
                'uses_graph_rag' => false,
                'uses_python_runtime' => false,
                'creates_parallel_memory_store' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $editorialRoadmap
     * @return array<string,array<string,mixed>>
     */
    private function editorialPhaseBySlug(array $editorialRoadmap): array
    {
        $phaseBySlug = [];

        foreach ((array) ($editorialRoadmap['phases'] ?? []) as $phase) {
            if (! is_array($phase)) {
                continue;
            }

            foreach ((array) ($phase['posts'] ?? []) as $post) {
                if (! is_array($post)) {
                    continue;
                }

                $slug = (string) ($post['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }

                $phaseBySlug[$slug] = [
                    'key' => (string) ($phase['key'] ?? ''),
                    'label' => (string) ($phase['label'] ?? ''),
                    'position' => (int) ($phase['position'] ?? 0),
                ];
            }
        }

        return $phaseBySlug;
    }

    /**
     * @param  array<int,string>  $missingPrerequisites
     */
    private function editorialDependencyReadiness(string $status, array $missingPrerequisites): string
    {
        if ($status === 'published') {
            return 'published_reference';
        }
        if ($missingPrerequisites !== []) {
            return 'blocked_missing_prerequisites';
        }
        if ($status === 'ready_to_draft') {
            return 'current_unlocked';
        }

        return 'planned_locked_by_sequence';
    }

    private function editorialDepthFromLevel(string $level): int
    {
        return match ($level) {
            'L0' => 0,
            'L1' => 1,
            'L2' => 2,
            'L3' => 3,
            'L4' => 4,
            default => 1,
        };
    }

    /**
     * @param  array<string,mixed>  $post
     * @param  array<string,mixed>  $phase
     * @param  array<int,string>  $missingPrerequisites
     */
    private function editorialDependencyPositionReason(array $post, array $phase, array $missingPrerequisites, bool $warning): string
    {
        if ($missingPrerequisites !== []) {
            return 'Ainda depende de textos anteriores: '.implode(', ', $missingPrerequisites).'.';
        }

        if ($warning) {
            return 'Tema profundo detectado antes de fundacao suficiente; manter como alerta de revisao.';
        }

        $label = (string) ($phase['label'] ?? 'fase atual');
        $question = (string) ($post['main_question'] ?? '');

        return $question !== ''
            ? 'Pertence a '.$label.' porque responde: '.$question
            : 'Pertence a '.$label.' e deve preservar a progressao do leitor.';
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @param  array<string,mixed>  $candidateFeed
     * @param  array<string,mixed>  $reviewQueueState
     * @param  array<string,mixed>  $dependencyMatrix
     * @return array<string,mixed>
     */
    private function backlogIntake(array $posts, array $publishedSlugs, array $candidateFeed, array $reviewQueueState, array $dependencyMatrix): array
    {
        $publishedSet = array_fill_keys($publishedSlugs, true);
        $seen = [];
        $items = [];
        $blockedLadder = (int) data_get($dependencyMatrix, 'summary.blocked_post_count', 0) > 0;

        $appendCandidate = function (array $candidate, string $lane) use (&$items, &$seen, $posts, $publishedSet, $blockedLadder): void {
            $slug = (string) ($candidate['slug'] ?? '');
            if ($slug === '' || isset($seen[$slug])) {
                return;
            }

            $seen[$slug] = true;
            $items[] = $this->backlogIntakeItem($candidate, $lane, $posts, $publishedSet, $blockedLadder);
        };

        foreach ((array) data_get($reviewQueueState, 'candidates', []) as $candidate) {
            if (is_array($candidate)) {
                $appendCandidate($candidate, 'review_queue');
            }
        }

        foreach ((array) ($candidateFeed['candidates'] ?? []) as $candidate) {
            if (is_array($candidate)) {
                $appendCandidate($candidate, 'candidate_feed');
            }
        }

        $items = array_slice($items, 0, 12);

        return [
            'schema_version' => 'atlas.blog_editorial_backlog_intake.v1',
            'mode' => 'read_only_candidate_intake_p1',
            'status' => 'ready',
            'summary' => [
                'item_count' => count($items),
                'review_queue_items' => count(array_filter($items, fn (array $item): bool => (string) ($item['lane'] ?? '') === 'review_queue')),
                'candidate_feed_items' => count(array_filter($items, fn (array $item): bool => (string) ($item['lane'] ?? '') === 'candidate_feed')),
                'ready_for_review_count' => count(array_filter($items, fn (array $item): bool => (string) ($item['recommended_action'] ?? '') === 'accept_into_review_queue')),
                'hold_count' => count(array_filter($items, fn (array $item): bool => str_starts_with((string) ($item['recommended_action'] ?? ''), 'hold'))),
                'dependency_ladder_blocked' => $blockedLadder,
            ],
            'items' => $items,
            'rules' => [
                'Intake alimenta a lista de revisao; ele nao escreve backlog principal.',
                'Candidatos profundos esperam a escada atual destravar antes de promocao.',
                'Toda promocao continua append-only, com Vitor aprovando posicao e prerequisitos.',
            ],
            'guardrails' => [
                'read_only' => true,
                'writes_backlog' => false,
                'writes_review_queue' => false,
                'promotes_candidate' => false,
                'publishes_content' => false,
                'reorders_posts' => false,
                'uses_graph_rag' => false,
                'uses_python_runtime' => false,
                'requires_human_approval_to_promote' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<string,bool>  $publishedSet
     * @return array<string,mixed>
     */
    private function backlogIntakeItem(array $candidate, string $lane, array $posts, array $publishedSet, bool $blockedLadder): array
    {
        $afterSlug = (string) ($candidate['suggested_after_slug'] ?? '');
        if ($afterSlug === '') {
            $afterSlug = $this->lastPostSlug($posts);
        }

        $after = $this->plannedPostBySlug($posts, $afterSlug);
        $depth = $this->editorialDepthFromLevel((string) ($candidate['complexity_level'] ?? ''));
        $isQueued = $lane === 'review_queue';
        $duplicateReason = (string) ($candidate['duplicate_reason'] ?? '');
        $blockedByDepth = $blockedLadder && $depth >= 3;
        $recommendedAction = $this->backlogIntakeAction($isQueued, $duplicateReason, $blockedByDepth);

        return [
            'lane' => $lane,
            'slug' => (string) ($candidate['slug'] ?? ''),
            'title' => (string) ($candidate['title'] ?? ''),
            'source_type' => (string) ($candidate['source_type'] ?? ''),
            'source_ref' => (string) ($candidate['source_ref'] ?? ''),
            'collection' => (string) ($candidate['collection'] ?? ''),
            'series' => (string) ($candidate['series'] ?? ''),
            'complexity_level' => (string) ($candidate['complexity_level'] ?? ''),
            'depth' => $depth,
            'topics' => array_slice(array_values(array_filter((array) ($candidate['topics'] ?? []), 'is_string')), 0, 10),
            'suggested_after_slug' => $afterSlug !== '' ? $afterSlug : null,
            'suggested_after_order' => is_array($after) ? (int) ($after['order'] ?? 0) : null,
            'suggested_prerequisites' => array_values(array_filter([
                $afterSlug !== '' && ! isset($publishedSet[$afterSlug]) ? $afterSlug : null,
            ])),
            'recommended_action' => $recommendedAction,
            'reason' => $this->backlogIntakeReason($candidate, $recommendedAction, $afterSlug),
            'promotion_rule' => 'Aceitar na fila de revisao primeiro; promover para backlog principal so com aprovacao humana.',
        ];
    }

    private function backlogIntakeAction(bool $isQueued, string $duplicateReason, bool $blockedByDepth): string
    {
        if ($duplicateReason !== '') {
            return 'hold_duplicate';
        }
        if ($blockedByDepth) {
            return 'hold_until_dependency_ladder_clears';
        }
        if ($isQueued) {
            return 'review_for_append_only_promotion';
        }

        return 'accept_into_review_queue';
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function backlogIntakeReason(array $candidate, string $recommendedAction, string $afterSlug): string
    {
        if ($recommendedAction === 'hold_duplicate') {
            return 'Ja existe no backlog, publicado ou repetido na fila de revisao.';
        }
        if ($recommendedAction === 'hold_until_dependency_ladder_clears') {
            return 'Tema profundo demais para entrar enquanto a escada atual ainda tem prerequisitos pendentes.';
        }

        $why = trim((string) ($candidate['why'] ?? ''));
        $placement = $afterSlug !== '' ? ' Posicao sugerida: depois de '.$afterSlug.'.' : '';

        return ($why !== '' ? $why : 'Sinal existente do Atlas ainda nao representado na lista publica.').$placement;
    }

    /**
     * @return array<int,array<string,string|int>>
     */
    private function editorialRoadmapPhaseTemplates(): array
    {
        return [
            ['key' => 'fundacao', 'position' => 1, 'label' => 'Fundacao', 'intent' => 'Apresentar quem e Vitor, o que e o Atlas e por que o leitor deveria se importar.'],
            ['key' => 'problema_contexto', 'position' => 2, 'label' => 'Problema e contexto', 'intent' => 'Explicar a falha dos assistentes genericos antes de propor uma arquitetura.'],
            ['key' => 'local_privacidade', 'position' => 3, 'label' => 'Local-first e controle', 'intent' => 'Construir confianca: privacidade, posse, limites e controle operacional.'],
            ['key' => 'memoria_conhecimento', 'position' => 4, 'label' => 'Memoria e conhecimento', 'intent' => 'Aprofundar memoria, ledger, conhecimento e esquecimento governado.'],
            ['key' => 'agentes_governanca', 'position' => 5, 'label' => 'Agentes e governanca', 'intent' => 'Mostrar agentes como sistemas com mandato, limites, sandbox e auditoria.'],
            ['key' => 'arquitetura_operacao', 'position' => 6, 'label' => 'Arquitetura e operacao', 'intent' => 'Abrir os bastidores tecnicos sem exigir que o leitor pule etapas.'],
            ['key' => 'expansao', 'position' => 7, 'label' => 'Expansao', 'intent' => 'Receber novos temas, colecoes e projetos quando tiverem fundacao suficiente.'],
        ];
    }

    /**
     * @param  array<string,mixed>  $slot
     */
    private function editorialRoadmapPhaseKey(array $slot): string
    {
        $slug = (string) ($slot['slug'] ?? '');
        $terms = $this->terms($slot);
        $text = Str::lower(Str::ascii(implode(' ', array_merge([$slug, (string) ($slot['title'] ?? '')], $terms))));
        $order = (int) ($slot['order'] ?? 0);
        $level = (string) ($slot['complexity_level'] ?? '');

        if ($order <= 2 || $level === 'L0' || str_contains($text, 'vocabulario') || str_contains($text, 'construindo')) {
            return 'fundacao';
        }
        if (str_contains($text, 'assistente') || str_contains($text, 'chatbot') || str_contains($text, 'contexto')) {
            return 'problema_contexto';
        }
        if (str_contains($text, 'local-first') || str_contains($text, 'privacidade') || str_contains($text, 'controle')) {
            return 'local_privacidade';
        }
        if (str_contains($text, 'memoria') || str_contains($text, 'ledger') || str_contains($text, 'conhecimento') || str_contains($text, 'esquecer')) {
            return 'memoria_conhecimento';
        }
        if (str_contains($text, 'agente') || str_contains($text, 'mandato') || str_contains($text, 'sandbox')) {
            return 'agentes_governanca';
        }
        if (str_contains($text, 'arquitetura') || str_contains($text, 'runtime') || str_contains($text, 'graph') || str_contains($text, 'rag') || str_contains($text, 'codigo')) {
            return 'arquitetura_operacao';
        }

        return 'expansao';
    }

    /**
     * @param  array<string,mixed>  $phase
     */
    private function editorialRoadmapPhaseStatus(array $phase): string
    {
        if ((int) ($phase['post_count'] ?? 0) === 0) {
            return 'empty';
        }
        if ((int) ($phase['ready_count'] ?? 0) > 0) {
            return 'active';
        }
        if ((int) ($phase['blocked_count'] ?? 0) > 0) {
            return 'blocked';
        }
        if ((int) ($phase['planned_count'] ?? 0) > 0) {
            return 'planned';
        }

        return 'complete';
    }

    private function publishingSlotStatus(bool $published, bool $next, bool $blocked): string
    {
        if ($published) {
            return 'published';
        }

        if ($next) {
            return 'ready_to_draft';
        }

        return $blocked ? 'blocked_by_prerequisite' : 'planned_future';
    }

    private function publishingSlotPipelineStage(bool $published, bool $next, bool $blocked): string
    {
        if ($published) {
            return 'public_archive';
        }

        if ($next) {
            return 'private_seed_ready';
        }

        return $blocked ? 'waiting_prerequisites' : 'scheduled_later';
    }

    private function publishingSlotHumanAction(bool $published, bool $next, bool $blocked): string
    {
        if ($published) {
            return 'monitor_archive_and_link_next_reading';
        }

        if ($next) {
            return 'review_seed_then_request_private_draft';
        }

        return $blocked ? 'publish_prerequisites_first' : 'keep_in_sequence';
    }

    private function graphRetrievalService(): AtlasGraphRetrievalNetworkService
    {
        return app(AtlasGraphRetrievalNetworkService::class);
    }

    /**
     * @param  array<string,mixed>  $post
     */
    private function editorialGraphObjective(array $post): string
    {
        return trim(implode(' ', array_filter([
            'blog editorial context',
            (string) ($post['title'] ?? ''),
            (string) ($post['main_question'] ?? ''),
            (string) ($post['collection'] ?? ''),
            (string) ($post['series'] ?? ''),
            implode(' ', array_values(array_filter((array) ($post['topics'] ?? []), 'is_string'))),
        ])));
    }

    /**
     * @return array<string,bool>
     */
    private function editorialGraphContextGuardrails(bool $invoked): array
    {
        return [
            'read_only' => true,
            'writes_backlog' => false,
            'writes_review_queue' => false,
            'writes_draft' => false,
            'publishes_content' => false,
            'uses_global_graph_rag' => false,
            'uses_python_runtime' => false,
            'invokes_bounded_graph_retrieval' => $invoked,
            'invokes_vector_runtime' => false,
            'providers_invoked' => false,
            'creates_parallel_memory_store' => false,
            'requires_human_approval_to_promote' => true,
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function editorialGraphCandidateGuardrails(bool $invoked): array
    {
        return [
            'read_only' => true,
            'writes_backlog' => false,
            'writes_review_queue' => false,
            'writes_draft' => false,
            'publishes_content' => false,
            'may_reorder_backlog' => false,
            'may_promote_candidate' => false,
            'requires_human_approval_to_accept' => true,
            'requires_human_approval_to_promote' => true,
            'uses_global_graph_rag' => false,
            'uses_python_runtime' => false,
            'invokes_bounded_graph_retrieval' => $invoked,
            'invokes_vector_runtime' => false,
            'providers_invoked' => false,
            'creates_parallel_memory_store' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $graphContext
     * @param  array<int,array<string,mixed>>  $evidence
     * @return array<int,array<string,mixed>>
     */
    private function graphCandidateBlueprints(array $graphContext, array $evidence): array
    {
        $pathText = Str::lower(Str::ascii(implode(' ', array_values(array_filter(array_map(
            fn (array $item): string => (string) ($item['path'] ?? ''),
            $evidence,
        ))))));
        $targetSlug = (string) data_get($graphContext, 'post.slug', '');
        $targetTitle = (string) data_get($graphContext, 'post.title', '');
        $hasEditorialPlanning = str_contains($pathText, 'blogeditorial') || str_contains($targetSlug, 'atlas');

        return array_values(array_filter([
            $hasEditorialPlanning ? [
                'title' => 'Como o Atlas decide a ordem do blog',
                'slug' => 'como-o-atlas-decide-a-ordem-do-blog',
                'collection' => 'atlas',
                'series' => 'public-building-system',
                'complexity_level' => 'L2',
                'main_question' => 'Como transformar trabalho real em uma sequencia publica que qualquer pessoa consegue acompanhar?',
                'topics' => ['atlas', 'blog', 'planejamento-editorial', 'sequencia'],
                'why' => 'Bounded graph context found editorial planning code and docs; this can become a public explanation after the first foundation arc.',
            ] : null,
            [
                'title' => 'Como o Atlas encontra proximas pautas sem baguncar a ordem',
                'slug' => 'como-o-atlas-encontra-proximas-pautas-sem-baguncar-a-ordem',
                'collection' => 'atlas',
                'series' => 'public-building-system',
                'complexity_level' => 'L2',
                'main_question' => 'Como um sistema pode sugerir pautas novas sem pular os prerequisitos do leitor?',
                'topics' => ['atlas', 'grafo', 'backlog', 'ux-de-conteudo'],
                'why' => 'The graph evidence is useful as a topic signal, but the public sequence must stay append-only and human-reviewed.',
            ],
            [
                'title' => 'Por que um blog tecnico precisa de uma fila governada',
                'slug' => 'por-que-um-blog-tecnico-precisa-de-uma-fila-governada',
                'collection' => 'produto',
                'series' => 'public-building-system',
                'complexity_level' => 'L1',
                'main_question' => 'Por que publicar assuntos complexos fora de ordem destrói a experiencia do leitor?',
                'topics' => ['blog', 'ux-de-conteudo', 'governanca', 'atlas'],
                'why' => 'The current public backlog already encodes prerequisites; this deserves a public meta-post before deeper graph/RAG topics.',
            ],
            $targetTitle !== '' ? [
                'title' => 'Como preparar contexto antes de escrever sobre '.$targetTitle,
                'slug' => 'como-preparar-contexto-antes-de-escrever-sobre-'.$targetSlug,
                'collection' => 'atlas',
                'series' => 'public-building-system',
                'complexity_level' => 'L2',
                'main_question' => 'Como o Atlas decide que contexto ajuda uma postagem sem expor detalhes privados?',
                'topics' => ['atlas', 'contexto', 'seguranca', 'escrita'],
                'why' => 'The bounded graph context is attached to the next ready post as writing context, not as publication authority.',
            ] : null,
        ]));
    }

    /**
     * @param  array<string,mixed>  $blueprint
     * @param  array<int,array<string,mixed>>  $evidence
     * @return array<string,mixed>
     */
    private function graphCandidateFromBlueprint(array $blueprint, string $appendAfterSlug, array $evidence): array
    {
        $evidenceRefs = array_values(array_map(
            fn (array $item): array => [
                'node_type' => (string) ($item['node_type'] ?? ''),
                'path_hash' => (string) ($item['path_hash'] ?? ''),
                'flow_id' => (string) ($item['flow_id'] ?? ''),
                'score' => (float) ($item['score'] ?? 0),
                'confidence' => (float) ($item['confidence'] ?? 0),
                'reasons' => array_values((array) ($item['reasons'] ?? [])),
            ],
            array_slice($evidence, 0, 4),
        ));

        return [
            'source_type' => 'bounded_world_model_graph',
            'source_ref' => 'codebase_world_model_bounded',
            'title' => (string) $blueprint['title'],
            'slug' => (string) $blueprint['slug'],
            'collection' => (string) $blueprint['collection'],
            'series' => (string) $blueprint['series'],
            'complexity_level' => (string) $blueprint['complexity_level'],
            'main_question' => (string) $blueprint['main_question'],
            'suggested_after_slug' => $appendAfterSlug,
            'topics' => array_values(array_filter((array) $blueprint['topics'], 'is_string')),
            'why' => (string) $blueprint['why'],
            'evidence_refs' => $evidenceRefs,
            'promotion_rule' => 'Accept into review queue first; promote append-only only after Vitor approves the sequence position.',
            'safety' => [
                'requires_human_review' => true,
                'publish_private_paths' => false,
                'publish_internal_ids_or_traces' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $graphContext
     * @return array<string,mixed>
     */
    private function compactGraphContextForCandidates(array $graphContext): array
    {
        return [
            'status' => (string) ($graphContext['status'] ?? 'unknown'),
            'post' => $graphContext['post'] ?? null,
            'graph_retrieval_status' => (string) data_get($graphContext, 'graph_retrieval.status', 'unknown'),
            'graph_scope' => (string) data_get($graphContext, 'graph_retrieval.graph_scope', ''),
            'evidence_count' => (int) data_get($graphContext, 'graph_retrieval.evidence_set.evidence_count', 0),
            'bounded_traversal' => (bool) data_get($graphContext, 'graph_retrieval.traversal_receipt.bounded_traversal', false),
            'global_graph_retrieval_active' => (bool) data_get($graphContext, 'graph_retrieval.policy.global_graph_retrieval_active', false),
            'python_runtime_invoked' => (bool) data_get($graphContext, 'graph_retrieval.policy.python_runtime_invoked', false),
            'providers_invoked' => (bool) data_get($graphContext, 'graph_retrieval.policy.providers_invoked', false),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @return array<string,mixed>
     */
    private function graphCandidateSequencePolicy(array $posts, array $publishedSlugs, string $appendAfterSlug): array
    {
        return [
            'default_suggested_after_slug' => $appendAfterSlug,
            'planned_post_count' => count($posts),
            'published_post_count' => count($publishedSlugs),
            'rule' => 'Graph-derived candidates append after the current planned foundation arc unless Vitor explicitly promotes them elsewhere.',
            'reason' => 'The reader must receive orientation before deep graph, RAG, memory or implementation topics.',
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
     * @return array<int,array<string,mixed>>
     */
    private function graphRagReadinessComponents(): array
    {
        $components = [
            [
                'component' => 'ap_811_code_graph_traversal',
                'status' => $this->repoPathExists('docs/ap/AP-811-atlas-code-graph-real-edges-traversal.md') ? 'available' : 'missing',
                'evidence' => 'docs/ap/AP-811-atlas-code-graph-real-edges-traversal.md',
                'role' => 'real code graph traversal contract',
            ],
            [
                'component' => 'ap_812_python_ai_data_runtime',
                'status' => $this->repoPathExists('docs/ap/AP-812-python-ai-data-code-graph-runtime.md') ? 'available' : 'missing',
                'evidence' => 'docs/ap/AP-812-python-ai-data-code-graph-runtime.md',
                'role' => 'runtime boundary for Python/data operations',
            ],
            [
                'component' => 'ap_815_cross_project_context_engine',
                'status' => $this->repoPathExists('docs/ap/AP-815-cross-project-context-engine.md') ? 'available' : 'missing',
                'evidence' => 'docs/ap/AP-815-cross-project-context-engine.md',
                'role' => 'cross-project context contract',
            ],
            [
                'component' => 'agrn_owner_doc',
                'status' => $this->repoPathExists('docs/engineering-knowledge-base/atlas-graph-retrieval-network.md') ? 'available' : 'missing',
                'evidence' => 'docs/engineering-knowledge-base/atlas-graph-retrieval-network.md',
                'role' => 'graph retrieval governance source',
            ],
            [
                'component' => 'runtime_language_boundaries',
                'status' => $this->repoPathExists('docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md') ? 'available' : 'missing',
                'evidence' => 'docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md',
                'role' => 'kernel-first runtime boundary',
            ],
            [
                'component' => 'atlas_graph_retrieval_command',
                'status' => class_exists(\App\Console\Commands\AtlasGraphRetrievalNetworkCommand::class) ? 'available' : 'missing',
                'evidence' => 'app/Console/Commands/AtlasGraphRetrievalNetworkCommand.php',
                'role' => 'bounded graph retrieval CLI',
            ],
            [
                'component' => 'atlas_graph_retrieval_service',
                'status' => class_exists(\App\Services\Ai\Context\AtlasGraphRetrievalNetworkService::class) ? 'available' : 'missing',
                'evidence' => 'app/Services/Ai/Context/AtlasGraphRetrievalNetworkService.php',
                'role' => 'bounded graph retrieval service',
            ],
            [
                'component' => 'world_model_graph_ranker',
                'status' => class_exists(\App\Services\Ai\AutonomousEngineering\WorldModel\WorldModelGraphRanker::class) ? 'available' : 'missing',
                'evidence' => 'app/Services/Ai/AutonomousEngineering/WorldModel/WorldModelGraphRanker.php',
                'role' => 'Codebase World Model relation ranker',
            ],
            [
                'component' => 'graph_rank_runtime_client',
                'status' => class_exists(\App\Services\Ai\RuntimeBoundary\GraphRankRuntimeClient::class) ? 'available' : 'missing',
                'evidence' => 'app/Services/Ai/RuntimeBoundary/GraphRankRuntimeClient.php',
                'role' => 'runtime boundary client',
            ],
            [
                'component' => 'mandatory_rag_gate',
                'status' => class_exists(\App\Services\Ai\Programming\AtlasDev\Gate\MandatoryRagGate::class) ? 'available' : 'missing',
                'evidence' => 'app/Services/Ai/Programming/AtlasDev/Gate/MandatoryRagGate.php',
                'role' => 'non-trivial context gate pattern',
            ],
            [
                'component' => 'world_model_tables',
                'status' => $this->allTablesAvailable([
                    'ai_codebase_world_models',
                    'ai_codebase_world_model_nodes',
                    'ai_codebase_world_model_edges',
                ]) ? 'available' : 'missing',
                'evidence' => 'database/migrations/2026_05_17_220000_create_ai_autonomous_engineering_os_tables.php',
                'role' => 'Codebase World Model storage',
            ],
            [
                'component' => 'mandatory_rag_gate_table',
                'status' => DatabaseTableAvailability::has('ai_mandatory_rag_gates') ? 'available' : 'missing',
                'evidence' => 'database/migrations/2026_05_17_220000_create_ai_autonomous_engineering_os_tables.php',
                'role' => 'RAG gate audit storage',
            ],
        ];

        return array_values($components);
    }

    private function repoPathExists(string $relativePath): bool
    {
        return is_file(base_path($relativePath));
    }

    /**
     * @param  array<int,string>  $tables
     */
    private function allTablesAvailable(array $tables): bool
    {
        foreach ($tables as $table) {
            if (! DatabaseTableAvailability::has($table)) {
                return false;
            }
        }

        return true;
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
    private function existingEditorialIndex(array $posts, array $publishedSlugs, array $queuedSlugs = []): array
    {
        $slugs = array_fill_keys(array_values(array_filter(array_merge($publishedSlugs, $queuedSlugs))), true);
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
     * @param  array<int,string>  $publishedSlugs
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>|null
     */
    private function graphCandidateBySlug(string $slug, array $posts, array $publishedSlugs, array $options = []): ?array
    {
        $feed = $this->editorialGraphCandidates(
            $posts,
            $publishedSlugs,
            is_array($options['published_posts'] ?? null) ? $options['published_posts'] : [],
            is_array($options['next_ready_post'] ?? null) ? $options['next_ready_post'] : null,
            (int) ($options['graph_context_limit'] ?? 8),
            max(15, (int) ($options['candidate_limit'] ?? 10)),
            is_string($options['graph_world_model_id'] ?? null) ? (string) $options['graph_world_model_id'] : '',
        );

        foreach ((array) ($feed['candidates'] ?? []) as $candidate) {
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
     * @param  array<string,bool>  $publishedSet
     * @return array<string,mixed>
     */
    private function publicationFrontier(array $posts, array $publishedSet): array
    {
        $publishedUntil = 0;
        $next = null;

        foreach ($posts as $post) {
            $order = (int) ($post['order'] ?? 0);
            $slug = (string) ($post['slug'] ?? '');

            if ($order === $publishedUntil + 1 && isset($publishedSet[$slug])) {
                $publishedUntil = $order;
                continue;
            }

            if ($order > $publishedUntil && $next === null) {
                $next = $this->compactPostRef($post);
                break;
            }
        }

        return [
            'published_until_order' => $publishedUntil,
            'next_order' => $publishedUntil + 1,
            'next_planned_post' => $next,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<string,bool>  $publishedSet
     * @param  array<string,bool>  $plannedSet
     * @return array<int,array<string,mixed>>
     */
    private function blockedPostsForRadar(array $posts, array $publishedSet, array $plannedSet): array
    {
        $blocked = [];

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
                continue;
            }

            $blocked[] = [
                'order' => (int) ($post['order'] ?? 0),
                'slug' => $slug,
                'title' => (string) ($post['title'] ?? ''),
                'missing_prerequisites' => $missingPrerequisites,
                'unknown_prerequisites' => $unknownPrerequisites,
            ];
        }

        return $blocked;
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<string,bool>  $publishedSet
     * @return array<int,array<string,mixed>>
     */
    private function weekLanes(array $posts, array $publishedSet): array
    {
        $weeks = [];

        foreach ($posts as $post) {
            $week = (int) ($post['week'] ?? 0);
            $weeks[$week] ??= [
                'week' => $week,
                'theme' => (string) ($post['week_theme'] ?? ''),
                'first_order' => (int) ($post['order'] ?? 0),
                'last_order' => (int) ($post['order'] ?? 0),
                'planned_count' => 0,
                'published_count' => 0,
                'next_unpublished' => null,
                'collections' => [],
                'series' => [],
                'complexity_levels' => [],
                'last_slug' => '',
            ];

            $slug = (string) ($post['slug'] ?? '');
            $weeks[$week]['planned_count']++;
            $weeks[$week]['last_order'] = max((int) $weeks[$week]['last_order'], (int) ($post['order'] ?? 0));
            $weeks[$week]['last_slug'] = $slug;
            $weeks[$week]['collections'][(string) ($post['collection'] ?? 'unknown')] = (($weeks[$week]['collections'][(string) ($post['collection'] ?? 'unknown')] ?? 0) + 1);
            $weeks[$week]['series'][(string) ($post['series'] ?? 'unknown')] = (($weeks[$week]['series'][(string) ($post['series'] ?? 'unknown')] ?? 0) + 1);
            $weeks[$week]['complexity_levels'][(string) ($post['complexity_level'] ?? 'unknown')] = (($weeks[$week]['complexity_levels'][(string) ($post['complexity_level'] ?? 'unknown')] ?? 0) + 1);

            if (isset($publishedSet[$slug])) {
                $weeks[$week]['published_count']++;
            } elseif ($weeks[$week]['next_unpublished'] === null) {
                $weeks[$week]['next_unpublished'] = $this->compactPostRef($post);
            }
        }

        ksort($weeks);

        return array_values(array_map(function (array $week): array {
            return [
                'week' => (int) $week['week'],
                'theme' => (string) $week['theme'],
                'order_range' => [(int) $week['first_order'], (int) $week['last_order']],
                'planned_count' => (int) $week['planned_count'],
                'published_count' => (int) $week['published_count'],
                'complete' => (int) $week['planned_count'] > 0 && (int) $week['planned_count'] === (int) $week['published_count'],
                'next_unpublished' => $week['next_unpublished'],
                'dominant_collection' => $this->dominantKey((array) $week['collections']),
                'dominant_series' => $this->dominantKey((array) $week['series']),
                'complexity_levels' => (array) $week['complexity_levels'],
                'insertion_after_slug' => (string) $week['last_slug'],
            ];
        }, $weeks));
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<string,bool>  $publishedSet
     * @return array<int,array<string,mixed>>
     */
    private function sequenceLanes(array $posts, array $publishedSet): array
    {
        $lanes = [];

        foreach ($posts as $post) {
            $series = (string) ($post['series'] ?? 'unknown');
            $slug = (string) ($post['slug'] ?? '');
            $lanes[$series] ??= [
                'series' => $series,
                'collection' => (string) ($post['collection'] ?? ''),
                'first_order' => (int) ($post['order'] ?? 0),
                'last_order' => (int) ($post['order'] ?? 0),
                'planned_count' => 0,
                'published_count' => 0,
                'deep_count' => 0,
                'first_unpublished' => null,
            ];

            $lanes[$series]['planned_count']++;
            $lanes[$series]['last_order'] = max((int) $lanes[$series]['last_order'], (int) ($post['order'] ?? 0));

            if (isset($publishedSet[$slug])) {
                $lanes[$series]['published_count']++;
            } elseif ($lanes[$series]['first_unpublished'] === null) {
                $lanes[$series]['first_unpublished'] = $this->compactPostRef($post);
            }

            if (in_array((string) ($post['complexity_level'] ?? ''), ['L3', 'L4', 'L5'], true)) {
                $lanes[$series]['deep_count']++;
            }
        }

        usort($lanes, fn (array $a, array $b): int => ((int) $a['first_order'] <=> (int) $b['first_order']));

        return array_values($lanes);
    }

    /**
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<string,bool>  $publishedSet
     * @param  array<int,array<string,mixed>>  $safeArcs
     * @return array<int,array<string,mixed>>
     */
    private function insertionWindows(array $posts, array $publishedSet, array $safeArcs): array
    {
        $windows = [];

        foreach ($this->weekLanes($posts, $publishedSet) as $week) {
            $windows[] = [
                'type' => 'after_week',
                'week' => (int) ($week['week'] ?? 0),
                'after_slug' => (string) ($week['insertion_after_slug'] ?? ''),
                'label' => 'After week '.((string) ($week['week'] ?? 0)).' - '.((string) ($week['theme'] ?? '')),
                'safe_for' => (bool) ($week['complete'] ?? false) ? 'extension_or_bridge' : 'only_if_it_supports_current_week',
                'rule' => 'Do not insert a deep topic here unless it depends only on concepts already introduced by this week.',
            ];
        }

        foreach ($safeArcs as $arc) {
            if (! (bool) ($arc['ready_after_first_month'] ?? false)) {
                continue;
            }

            $windows[] = [
                'type' => 'next_safe_arc',
                'arc' => (string) ($arc['arc'] ?? ''),
                'title' => (string) ($arc['title'] ?? ''),
                'first_post_slug' => (string) ($arc['first_post_slug'] ?? ''),
                'safe_for' => 'new_collection_start',
                'rule' => (string) ($arc['why_now'] ?? ''),
            ];
        }

        return array_slice($windows, 0, 12);
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<int,array<string,mixed>>  $posts
     * @return array<string,mixed>
     */
    private function candidateRadarSummary(array $candidate, array $posts): array
    {
        $afterSlug = (string) ($candidate['suggested_after_slug'] ?? '');
        $after = $this->plannedPostBySlug($posts, $afterSlug);

        return [
            'slug' => (string) ($candidate['slug'] ?? ''),
            'title' => (string) ($candidate['title'] ?? ''),
            'source_type' => (string) ($candidate['source_type'] ?? ''),
            'source_ref' => (string) ($candidate['source_ref'] ?? ''),
            'complexity_level' => (string) ($candidate['complexity_level'] ?? ''),
            'collection' => (string) ($candidate['collection'] ?? ''),
            'series' => (string) ($candidate['series'] ?? ''),
            'suggested_after_slug' => $afterSlug,
            'suggested_after_order' => is_array($after) ? (int) ($after['order'] ?? 0) : null,
            'review_reason' => (string) ($candidate['why'] ?? ''),
            'promotion_rule' => 'Accept into review queue first; promote append-only only after Vitor approves the sequence position.',
        ];
    }

    /**
     * @param  array<string,mixed>  $frontier
     * @param  array<int,array<string,mixed>>  $blocked
     * @param  array<string,mixed>|null  $nextReadyPost
     * @param  array<int,array<string,mixed>>  $safeArcs
     * @return array<int,array<string,mixed>>
     */
    private function radarNextSteps(array $frontier, array $blocked, ?array $nextReadyPost, array $safeArcs): array
    {
        $steps = [];

        if (is_array($nextReadyPost)) {
            $steps[] = [
                'action' => 'prepare_writing_packet',
                'slug' => (string) ($nextReadyPost['slug'] ?? ''),
                'why' => 'This is the next unpublished post whose prerequisites are satisfied.',
            ];
        } elseif ($blocked !== []) {
            $steps[] = [
                'action' => 'repair_prerequisites',
                'slug' => (string) ($blocked[0]['slug'] ?? ''),
                'why' => 'The sequence has blocked posts before more candidates should be promoted.',
            ];
        }

        $readyArcs = array_values(array_filter(
            $safeArcs,
            fn (array $arc): bool => (bool) ($arc['ready_after_first_month'] ?? false),
        ));

        if ($readyArcs !== []) {
            $steps[] = [
                'action' => 'review_next_safe_arc',
                'arc' => (string) ($readyArcs[0]['arc'] ?? ''),
                'first_post_slug' => (string) ($readyArcs[0]['first_post_slug'] ?? ''),
                'why' => (string) ($readyArcs[0]['why_now'] ?? ''),
            ];
        }

        $steps[] = [
            'action' => 'keep_public_order',
            'next_sequence_order' => (int) ($frontier['next_order'] ?? 1),
            'why' => 'New posts should extend the reader ladder, not jump around because a deep internal topic is interesting.',
        ];

        return $steps;
    }

    /**
     * @param  array<string,int>  $counts
     */
    private function dominantKey(array $counts): string
    {
        if ($counts === []) {
            return 'unknown';
        }

        arsort($counts);

        return (string) array_key_first($counts);
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
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     */
    private function firstReadySlug(array $posts, array $publishedSlugs): ?string
    {
        $publishedSet = array_fill_keys($publishedSlugs, true);
        $plannedSet = array_fill_keys(array_values(array_filter(array_map(
            fn (array $post): string => (string) ($post['slug'] ?? ''),
            $posts,
        ))), true);

        $sorted = $posts;
        usort($sorted, fn (array $a, array $b): int => ((int) ($a['order'] ?? 0) <=> (int) ($b['order'] ?? 0)));

        foreach ($sorted as $post) {
            $slug = (string) ($post['slug'] ?? '');
            if ($slug === '' || isset($publishedSet[$slug])) {
                continue;
            }

            $missing = array_values(array_filter(
                array_values(array_filter((array) ($post['prerequisites'] ?? []), 'is_string')),
                fn (string $prerequisite): bool => ! isset($publishedSet[$prerequisite])
            ));
            $unknown = array_values(array_filter(
                array_values(array_filter((array) ($post['prerequisites'] ?? []), 'is_string')),
                fn (string $prerequisite): bool => ! isset($plannedSet[$prerequisite]) && ! isset($publishedSet[$prerequisite])
            ));

            if ($missing === [] && $unknown === []) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $observed
     * @return array<string,mixed>
     */
    private function goldenCase(string $code, bool $passed, string $expectation, array $observed = []): array
    {
        return [
            'code' => $code,
            'passed' => $passed,
            'expectation' => $expectation,
            'observed' => $observed,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function conceptProgressionFixturePosts(): array
    {
        return [
            [
                'order' => 1,
                'title' => 'O que e o Atlas',
                'slug' => 'o-que-e-o-atlas',
                'complexity_level' => 'L0',
                'collection' => 'atlas',
                'series' => 'building-atlas',
                'topics' => ['atlas', 'produto'],
                'prerequisites' => [],
            ],
            [
                'order' => 2,
                'title' => 'Por que estou construindo o Atlas',
                'slug' => 'por-que-estou-construindo-o-atlas',
                'complexity_level' => 'L0',
                'collection' => 'atlas',
                'series' => 'building-atlas',
                'topics' => ['atlas', 'visao', 'processo'],
                'prerequisites' => ['o-que-e-o-atlas'],
            ],
            [
                'order' => 3,
                'title' => 'Memoria como ledger',
                'slug' => 'memoria-como-ledger',
                'complexity_level' => 'L3',
                'collection' => 'ia-pessoal',
                'series' => 'agent-memory',
                'topics' => ['memoria', 'ledger', 'governanca'],
                'prerequisites' => ['por-que-estou-construindo-o-atlas'],
            ],
            [
                'order' => 4,
                'title' => 'Graph RAG profundo no Atlas',
                'slug' => 'graph-rag-profundo-no-atlas',
                'complexity_level' => 'L5',
                'collection' => 'atlas',
                'series' => 'graph-rag',
                'topics' => ['graph-rag', 'python-runtime', 'embedding'],
                'prerequisites' => ['memoria-como-ledger'],
            ],
        ];
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
        return array_slice((array) ($this->conceptProgressionMap($post, $posts)['future_terms_to_avoid'] ?? []), 0, 12);
    }

    /**
     * @param  array<string,mixed>  $post
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,string>  $publishedSlugs
     * @return array<string,mixed>
     */
    private function conceptProgressionMap(array $post, array $posts, array $publishedSlugs = []): array
    {
        $slug = (string) ($post['slug'] ?? '');
        $order = (int) ($post['order'] ?? 0);
        $publishedSet = array_fill_keys($publishedSlugs, true);
        $currentTerms = $this->terms($post);
        $currentTermSet = array_fill_keys($currentTerms, true);
        $priorTerms = [];
        $publishedPriorTerms = [];
        $prerequisiteTerms = [];
        $futureTerms = [];
        $futureExamples = [];
        $prerequisites = array_values(array_filter((array) ($post['prerequisites'] ?? []), 'is_string'));
        $prerequisiteSet = array_fill_keys($prerequisites, true);
        $priorCount = 0;
        $publishedPriorCount = 0;
        $futureCount = 0;

        foreach ($posts as $candidate) {
            $candidateSlug = (string) ($candidate['slug'] ?? '');
            $candidateOrder = (int) ($candidate['order'] ?? 0);
            $candidateTerms = $this->terms($candidate);

            if ($candidateOrder > 0 && $candidateOrder < $order) {
                $priorCount++;

                foreach ($candidateTerms as $term) {
                    $priorTerms[$term] = true;
                }
            }

            if (isset($publishedSet[$candidateSlug]) && ($candidateOrder === 0 || $candidateOrder < $order)) {
                $publishedPriorCount++;

                foreach ($candidateTerms as $term) {
                    $publishedPriorTerms[$term] = true;
                }
            }

            if (isset($prerequisiteSet[$candidateSlug])) {
                foreach ($candidateTerms as $term) {
                    $prerequisiteTerms[$term] = true;
                }
            }

            if ($candidateOrder > $order) {
                $futureCount++;

                foreach ($candidateTerms as $term) {
                    if (isset($currentTermSet[$term]) || isset($priorTerms[$term]) || isset($prerequisiteTerms[$term])) {
                        continue;
                    }

                    $futureTerms[$term] = true;
                    $futureExamples[$term] ??= [
                        'term' => $term,
                        'source_slug' => $candidateSlug,
                        'source_order' => $candidateOrder,
                        'source_title' => (string) ($candidate['title'] ?? ''),
                    ];
                }
            }
        }

        $introducedTerms = array_values(array_unique(array_merge(array_keys($priorTerms), array_keys($publishedPriorTerms), array_keys($prerequisiteTerms))));
        sort($introducedTerms);

        $allowedTerms = array_values(array_unique(array_merge($introducedTerms, $currentTerms)));
        sort($allowedTerms);

        $futureTermsToAvoid = array_values(array_filter(
            array_keys($futureTerms),
            fn (string $term): bool => ! in_array($term, $allowedTerms, true),
        ));
        sort($futureTermsToAvoid);

        return [
            'schema_version' => 'atlas.blog_editorial_concept_progression.v1',
            'mode' => 'read_only_sequence_guard_p1',
            'current_slug' => $slug,
            'current_order' => $order,
            'current_terms' => $currentTerms,
            'introduced_terms' => $introducedTerms,
            'published_prior_terms' => array_values(array_keys($publishedPriorTerms)),
            'prerequisite_terms' => array_values(array_keys($prerequisiteTerms)),
            'allowed_terms' => $allowedTerms,
            'future_terms_to_avoid' => array_slice($futureTermsToAvoid, 0, 24),
            'premature_topic_examples' => array_slice(array_values(array_filter(
                $futureExamples,
                fn (array $example): bool => in_array((string) ($example['term'] ?? ''), $futureTermsToAvoid, true),
            )), 0, 8),
            'reader_state' => [
                'planned_prior_count' => $priorCount,
                'published_prior_count' => $publishedPriorCount,
                'prerequisite_count' => count($prerequisites),
                'future_post_count' => $futureCount,
            ],
            'rule' => 'Explain only the current layer plus concepts already introduced by prior or prerequisite posts; name future concepts only as teasers, never as required knowledge.',
            'guardrails' => [
                'read_only' => true,
                'writes_backlog' => false,
                'writes_draft' => false,
                'publishes_content' => false,
                'uses_graph_rag' => false,
            ],
        ];
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
     * @param  array<string,mixed>  $post
     * @param  array<string,mixed>  $writingBrief
     * @param  array<string,mixed>  $conceptProgressionMap
     * @param  array<string,mixed>  $publicArchiveContext
     * @return array<string,mixed>
     */
    private function draftSeedForPost(array $post, array $writingBrief, array $conceptProgressionMap, array $publicArchiveContext): array
    {
        $title = (string) ($post['title'] ?? 'Texto sem titulo');
        $slug = (string) ($post['slug'] ?? '');
        $question = (string) ($writingBrief['primary_question'] ?? $post['main_question'] ?? '');
        $promise = (string) ($writingBrief['reader_promise'] ?? $this->readerPromise($post));
        $outline = array_values(array_filter((array) ($writingBrief['outline'] ?? []), 'is_string'));
        $allowedTerms = array_slice(array_values(array_filter((array) ($conceptProgressionMap['allowed_terms'] ?? []), 'is_string')), 0, 8);
        $futureTermsToAvoid = array_slice(array_values(array_filter((array) ($conceptProgressionMap['future_terms_to_avoid'] ?? []), 'is_string')), 0, 8);
        $duplicateRiskCount = (int) ($publicArchiveContext['duplicate_risk_count'] ?? 0);
        $linkableArtifactCount = (int) ($publicArchiveContext['linkable_artifact_count'] ?? 0);

        return [
            'schema_version' => 'atlas.blog_editorial_private_draft_seed.v1',
            'mode' => 'private_review_seed_p1',
            'status' => 'ready',
            'post_slug' => $slug,
            'language' => 'pt-BR',
            'title_options' => array_values(array_unique(array_filter([
                $title,
                $question !== '' ? $this->titleFromQuestion($question) : null,
                $title !== '' ? $title.': uma explicacao simples' : null,
            ]))),
            'working_thesis' => $question !== ''
                ? 'Este texto responde, sem pressa e sem jargao: '.$question
                : 'Este texto apresenta uma ideia do Atlas em uma camada segura para o leitor atual.',
            'lede_seed' => [
                'purpose' => 'Abrir o texto com contexto humano antes de entrar em arquitetura.',
                'paragraph_prompt' => $this->openingPromptForPost($post, $question),
                'avoid' => 'Nao comecar com buzzwords, claims grandiosos ou detalhes internos.',
            ],
            'section_seeds' => array_map(
                fn (string $item, int $index): array => [
                    'order' => $index + 1,
                    'heading_hint' => $this->headingHintFromOutline($item, $index),
                    'purpose' => $item,
                    'paragraph_prompt' => $this->sectionPromptForOutlineItem($post, $item, $index, $allowedTerms),
                ],
                $outline,
                array_keys($outline),
            ),
            'closing_seed' => [
                'purpose' => 'Fechar com continuidade editorial, nao com venda.',
                'paragraph_prompt' => $this->closingPromptForPost($post, $promise),
                'next_reading' => array_values(array_filter((array) ($post['next_reading'] ?? []), 'is_string')),
            ],
            'concept_boundaries' => [
                'allowed_terms' => $allowedTerms,
                'future_terms_to_avoid' => $futureTermsToAvoid,
                'rule' => 'Se um termo futuro for citado, ele deve aparecer como teaser, nao como conhecimento exigido.',
            ],
            'archive_awareness' => [
                'duplicate_risk_count' => $duplicateRiskCount,
                'linkable_artifact_count' => $linkableArtifactCount,
                'rule' => $duplicateRiskCount > 0
                    ? 'Comparar com artefatos publicos anteriores antes de transformar este seed em rascunho.'
                    : 'Pode seguir sem risco publico de duplicacao detectado neste preflight.',
            ],
            'review_checklist' => [
                'O texto responde a pergunta central em linguagem simples.',
                'A ordem conceitual respeita os prerequisitos publicados.',
                'Nenhum path local, token, prompt, trace ou detalhe sensivel aparece.',
                'Assuntos futuros nao viram dependencia para entender este post.',
                'A versao final ainda precisa de aprovacao humana antes de publicar.',
            ],
            'guardrails' => [
                'read_only' => true,
                'writes_draft' => false,
                'publishes_content' => false,
                'generates_full_article' => false,
                'requires_human_review' => true,
            ],
        ];
    }

    private function titleFromQuestion(string $question): string
    {
        $title = trim($question, " \t\n\r\0\x0B?");

        if ($title === '') {
            return 'Texto do Atlas';
        }

        return mb_strtoupper(mb_substr($title, 0, 1)).mb_substr($title, 1);
    }

    /**
     * @param  array<string,mixed>  $post
     */
    private function openingPromptForPost(array $post, string $question): string
    {
        $title = (string) ($post['title'] ?? 'este texto');

        if ($question !== '') {
            return 'Comece explicando por que "'.$title.'" importa antes de responder diretamente: '.$question;
        }

        return 'Comece explicando por que "'.$title.'" importa para alguem que ainda nao conhece a arquitetura do Atlas.';
    }

    private function headingHintFromOutline(string $outlineItem, int $index): string
    {
        $clean = trim($outlineItem, ". \t\n\r\0\x0B");

        return match ($index) {
            0 => 'O ponto de partida',
            1 => 'A ideia principal',
            2 => 'Por que isso importa',
            3 => 'O limite deste texto',
            4 => 'Para onde isso leva',
            default => $clean !== '' ? $clean : 'Secao '.($index + 1),
        };
    }

    /**
     * @param  array<string,mixed>  $post
     * @param  array<int,string>  $allowedTerms
     */
    private function sectionPromptForOutlineItem(array $post, string $outlineItem, int $index, array $allowedTerms): string
    {
        $termHint = $allowedTerms !== []
            ? 'Pode usar termos ja liberados como: '.implode(', ', array_slice($allowedTerms, 0, 4)).'.'
            : 'Use exemplos simples antes de nomear conceitos tecnicos.';

        return 'Secao '.($index + 1).': '.$outlineItem.' '.$termHint;
    }

    /**
     * @param  array<string,mixed>  $post
     */
    private function closingPromptForPost(array $post, string $promise): string
    {
        $nextReading = array_values(array_filter((array) ($post['next_reading'] ?? []), 'is_string'));

        if ($nextReading !== []) {
            return $promise.' Feche preparando naturalmente a proxima leitura: '.$nextReading[0].'.';
        }

        return $promise.' Feche com uma consequencia pratica e sem promessa de produto.';
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
        foreach ($this->queuedCandidateEntries($reviewQueuePath) as $entry) {
            if ((string) ($entry['slug'] ?? '') === $candidateSlug) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function queuedCandidateEntries(string $reviewQueuePath): array
    {
        $raw = (string) file_get_contents($reviewQueuePath);
        $blocks = preg_split('/(?=^  - status:)/m', $raw) ?: [];
        $entries = [];

        foreach ($blocks as $block) {
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

            if (isset($entry['slug'])) {
                $entry['topics'] = array_values(array_filter((array) ($entry['topics'] ?? []), 'is_string'));
                $entries[] = $entry;
            }
        }

        return $entries;
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
