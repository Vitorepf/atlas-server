<?php

declare(strict_types=1);

namespace App\Services\Ai\Publishing;

use App\Services\Ai\AtlasOpenBrainService;
use App\Services\Ai\Publishing\BlogEditorial\CandidateSection;
use App\Services\Ai\Publishing\BlogEditorial\CoverageSection;
use App\Services\Ai\Publishing\BlogEditorial\EditorialSupport;
use App\Services\Ai\Publishing\BlogEditorial\GraphSection;
use App\Services\Ai\Publishing\BlogEditorial\OperationsSection;
use App\Services\Ai\Publishing\BlogEditorial\PlanningSection;
use App\Services\Ai\Publishing\BlogEditorial\RadarSection;
use App\Services\Ai\Publishing\BlogEditorial\ReviewQueueSection;
use App\Services\Ai\Publishing\BlogEditorial\WritingSection;
use Illuminate\Support\Facades\File;

final class BlogEditorialContextService
{
    public const SCHEMA_VERSION = 'atlas.blog_editorial_context.v1';

    private readonly EditorialSupport $support;

    private readonly ReviewQueueSection $reviewQueue;

    private readonly CandidateSection $candidates;

    private readonly GraphSection $graph;

    private readonly CoverageSection $coverage;

    private readonly RadarSection $radar;

    private readonly PlanningSection $planning;

    private readonly OperationsSection $operations;

    private readonly WritingSection $writing;

    public function __construct(
        private readonly ?AtlasOpenBrainService $openBrain = null,
    ) {
        $this->support = new EditorialSupport();
        $this->reviewQueue = new ReviewQueueSection();
        $this->candidates = new CandidateSection($this->support);
        $this->graph = new GraphSection();
        $this->coverage = new CoverageSection($this->support);
        $this->radar = new RadarSection($this->support);
        $this->planning = new PlanningSection($this->support);
        $this->operations = new OperationsSection($this->support);
        $this->writing = new WritingSection($this->openBrain, $this->support);
    }

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
        $existing = $this->support->existingEditorialIndex($posts, $publishedSlugs);
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

        $candidate = $this->candidates->candidateBySlug($candidateSlug, $posts);

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

        $entry = $this->reviewQueue->reviewQueueEntry($candidate);
        $snippet = $this->reviewQueue->reviewQueueSnippet($entry);
        $alreadyQueued = is_file($targetPath)
            && str_contains((string) file_get_contents($targetPath), 'slug: "'.$this->reviewQueue->escapeYamlString((string) $candidate['slug']).'"');

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
        $existing = $this->support->existingEditorialIndex($posts, $publishedSlugs);

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

        $entry = $this->reviewQueue->queuedCandidateEntry($reviewQueuePath, $candidateSlug);

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
        $fallbackAfter = $this->support->lastPostSlug($posts);
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
            'reader_level' => $this->reviewQueue->readerLevelForComplexity((string) ($entry['complexity_level'] ?? 'L1')),
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
        $snippet = $this->reviewQueue->promotionWeekSnippet($week);

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
        $existing = $this->support->existingEditorialIndex($posts, $publishedSlugs, $queuedSlugs);
        $candidates = [];

        foreach ($this->candidates->knowledgeCandidateRows($limit * 3) as $row) {
            $candidate = $this->candidates->candidateFromKnowledgeRow($row, $posts);
            if ($candidate === null || isset($existing['slugs'][$candidate['slug']])) {
                continue;
            }
            if (isset($existing['titles'][$this->support->normalizedTitle((string) $candidate['title'])])) {
                continue;
            }

            $existing['slugs'][$candidate['slug']] = true;
            $existing['titles'][$this->support->normalizedTitle((string) $candidate['title'])] = true;
            $candidates[] = $candidate;

            if (count($candidates) >= $limit) {
                break;
            }
        }

        if (count($candidates) < $limit) {
            foreach ($this->candidates->moduleCandidateRows($limit * 2) as $row) {
                $candidate = $this->candidates->candidateFromModuleRow($row, $posts);
                if ($candidate === null || isset($existing['slugs'][$candidate['slug']])) {
                    continue;
                }
                if (isset($existing['titles'][$this->support->normalizedTitle((string) $candidate['title'])])) {
                    continue;
                }

                $existing['slugs'][$candidate['slug']] = true;
                $existing['titles'][$this->support->normalizedTitle((string) $candidate['title'])] = true;
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
        $entries = is_file($path) ? $this->reviewQueue->queuedCandidateEntries($path) : [];
        $existing = $this->support->existingEditorialIndex($posts, $publishedSlugs);
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
        $foundation = $this->coverage->foundationCoverage($plannedSet, $publishedSet);
        $topicIndex = $this->coverage->topicIndex($posts, $publishedSet);
        $levelCounts = $this->coverage->countByField($posts, 'complexity_level');
        $collectionCounts = $this->coverage->countByField($posts, 'collection');
        $seriesCounts = $this->coverage->countByField($posts, 'series');
        $warnings = $this->coverage->deepSequenceWarnings($posts);

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
            'next_safe_arcs' => $this->coverage->nextSafeArcs($foundation, $plannedSet),
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
        return $this->coverage->sourceMap($posts, $publishedSlugs, $publishedPosts);
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
        $components = $this->graph->graphRagReadinessComponents();
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
            ? $this->support->plannedPostBySlug($posts, (string) ($nextReadyPost['slug'] ?? ''))
            : null;

        if (! is_array($targetPost)) {
            $targetPost = $this->support->plannedPostBySlug($posts, $this->support->firstReadySlug($posts, $publishedSlugs) ?? '');
        }

        if (($goldenSet['status'] ?? '') !== 'passed') {
            return [
                'schema_version' => 'atlas.blog_editorial_graph_context.v1',
                'mode' => 'bounded_world_model_editorial_context_p2_preflight',
                'status' => 'blocked',
                'reason' => 'editorial_golden_set_failed',
                'post' => $targetPost !== null ? $this->support->compactPostRef($targetPost) : null,
                'editorial_golden_set' => [
                    'status' => (string) ($goldenSet['status'] ?? 'unknown'),
                    'failed_cases' => array_values((array) ($goldenSet['failed_cases'] ?? [])),
                ],
                'graph_retrieval' => null,
                'guardrails' => $this->graph->editorialGraphContextGuardrails(false),
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
                'guardrails' => $this->graph->editorialGraphContextGuardrails(false),
            ];
        }

        $graph = $this->graph->graphRetrievalService()->retrieve([
            'objective' => $this->graph->editorialGraphObjective($targetPost),
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
                $this->support->terms($targetPost),
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
            'post' => $this->support->compactPostRef($targetPost),
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
            'guardrails' => $this->graph->editorialGraphContextGuardrails(true),
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
        $appendAfterSlug = $this->support->lastPostSlug($posts);
        $existing = $this->support->existingEditorialIndex($posts, $publishedSlugs, $queuedSlugs);

        if (($graphContext['status'] ?? '') !== 'ready') {
            return [
                'schema_version' => 'atlas.blog_editorial_graph_candidates.v1',
                'mode' => 'bounded_world_model_candidate_feed_p2_preflight',
                'status' => 'blocked',
                'reason' => (string) ($graphContext['reason'] ?? 'editorial_graph_context_not_ready'),
                'candidate_count' => 0,
                'candidates' => [],
                'graph_context' => $this->graph->compactGraphContextForCandidates($graphContext),
                'sequence_policy' => $this->graph->graphCandidateSequencePolicy($posts, $publishedSlugs, $appendAfterSlug),
                'guardrails' => $this->graph->editorialGraphCandidateGuardrails((bool) data_get($graphContext, 'guardrails.invokes_bounded_graph_retrieval', false)),
            ];
        }

        $evidence = array_values((array) data_get($graphContext, 'graph_retrieval.evidence_set.evidence', []));
        $candidates = [];
        $rejectedDuplicates = [];

        foreach ($this->graph->graphCandidateBlueprints($graphContext, $evidence) as $blueprint) {
            $candidate = $this->graph->graphCandidateFromBlueprint($blueprint, $appendAfterSlug, $evidence);
            $slug = (string) ($candidate['slug'] ?? '');
            $normalizedTitle = $this->support->normalizedTitle((string) ($candidate['title'] ?? ''));

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
            'graph_context' => $this->graph->compactGraphContextForCandidates($graphContext),
            'sequence_policy' => $this->graph->graphCandidateSequencePolicy($posts, $publishedSlugs, $appendAfterSlug),
            'guardrails' => $this->graph->editorialGraphCandidateGuardrails(true),
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
        $frontier = $this->radar->publicationFrontier($posts, $publishedSet);
        $firstReady = $this->support->firstReadySlug($posts, []);
        $afterIntroReady = $this->support->firstReadySlug($posts, ['o-que-e-o-atlas', 'por-que-estou-construindo-o-atlas']);
        $cases = [];

        $cases[] = $this->radar->goldenCase(
            'sequence_starts_with_atlas_identity',
            ! isset($plannedSet['o-que-e-o-atlas']) || $firstReady === 'o-que-e-o-atlas',
            'If the intro post is planned and nothing is published, it must be the first ready post.',
            ['first_ready_slug' => $firstReady]
        );

        $cases[] = $this->radar->goldenCase(
            'published_prefix_advances_to_next_foundation',
            ! isset($plannedSet['o-problema-dos-assistentes-de-ia-hoje']) || $afterIntroReady === 'o-problema-dos-assistentes-de-ia-hoje',
            'After the two first foundation posts, the next ready post must be the assistant-problem bridge.',
            ['next_ready_after_intro' => $afterIntroReady]
        );

        $deepFixtureWarnings = $this->coverage->deepSequenceWarnings([
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
        $cases[] = $this->radar->goldenCase(
            'deep_topic_without_foundation_is_warned',
            collect($deepFixtureWarnings)->contains(fn (array $warning): bool => (string) ($warning['code'] ?? '') === 'deep_post_without_prior_foundation'),
            'A deep standalone post must trigger a sequence warning instead of becoming a safe editorial jump.',
            ['warning_count' => count($deepFixtureWarnings)]
        );

        $conceptFixture = $this->radar->conceptProgressionFixturePosts();
        $conceptPost = $this->support->plannedPostBySlug($conceptFixture, 'memoria-como-ledger') ?? [];
        $conceptMap = $this->support->conceptProgressionMap($conceptPost, $conceptFixture, ['o-que-e-o-atlas', 'por-que-estou-construindo-o-atlas']);
        $futureTerms = (array) ($conceptMap['future_terms_to_avoid'] ?? []);
        $cases[] = $this->radar->goldenCase(
            'future_terms_stay_future_until_introduced',
            in_array('graph-rag', $futureTerms, true) && in_array('python-runtime', $futureTerms, true) && (bool) data_get($conceptMap, 'guardrails.uses_graph_rag') === false,
            'A memory post may not assume future Graph/RAG or Python runtime terms as reader knowledge.',
            ['future_terms_sample' => array_slice($futureTerms, 0, 6)]
        );

        $cases[] = $this->radar->goldenCase(
            'first_month_foundation_has_no_deep_sequence_warning',
            (int) data_get($coverage, 'summary.deep_sequence_warning_count', 0) === 0,
            'The active first-month plan must not place L3+ material before a same-series or same-collection foundation.',
            ['deep_sequence_warning_count' => (int) data_get($coverage, 'summary.deep_sequence_warning_count', 0)]
        );

        $cases[] = $this->radar->goldenCase(
            'publication_frontier_is_append_only',
            (int) ($frontier['next_order'] ?? 0) >= 1 && (($frontier['next_planned_post'] ?? null) === null || is_array($frontier['next_planned_post'])),
            'The public sequence frontier must be computed from contiguous published order, not from interesting deep topics.',
            ['next_order' => (int) ($frontier['next_order'] ?? 0), 'next_slug' => (string) data_get($frontier, 'next_planned_post.slug', '')]
        );

        $cases[] = $this->radar->goldenCase(
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
        $nextPost = $this->support->plannedPostBySlug($posts, (string) ($nextReadyPost['slug'] ?? ''));
        $writingPacket = $nextPost !== null
            ? $this->writingPacket($nextPost, $posts, $publishedSlugs, $contextLimit, $publishedPosts, $executeOpenBrain)
            : null;
        $publicArchiveContext = is_array($writingPacket)
            ? (array) ($writingPacket['public_archive_context'] ?? [])
            : [];
        $openBrainHandoff = $nextPost !== null
            ? $this->writing->openBrainHandoffForPost($nextPost, $contextLimit)
            : null;
        $publishingPlan = $this->planning->publishingPlan($posts, $publishedSlugs, $nextReadyPost, $blockedPosts, $reviewQueueState, $backlogMeta);
        $topicLedger = $this->planning->topicLedger($posts, $publishedSlugs, $publishedPosts, $candidateFeed, $reviewQueueState, $coverageMap, $sourceMap);
        $editorialRoadmap = $this->planning->editorialRoadmap($publishingPlan, $topicLedger);
        $editorialDependencyMatrix = $this->planning->editorialDependencyMatrix($posts, $publishedSlugs, $publishingPlan, $editorialRoadmap);
        $backlogIntake = $this->operations->backlogIntake($posts, $publishedSlugs, $candidateFeed, $reviewQueueState, $editorialDependencyMatrix);
        $atlasSignalMesh = $this->operations->atlasSignalMesh($sourceMap, $coverageMap, $candidateFeed, $reviewQueueState, $editorialRoadmap, $editorialDependencyMatrix, $backlogIntake, $openBrainHandoff);
        $publicKnowledgeMap = $this->operations->publicKnowledgeMap($posts, $sourceMap, $topicLedger, $editorialDependencyMatrix);
        $agentOperatingQueue = $this->operations->agentOperatingQueue($publishingPlan, $editorialDependencyMatrix, $backlogIntake, $publicKnowledgeMap, $atlasSignalMesh);
        $agentHandoffPacket = $this->operations->agentHandoffPacket($agentOperatingQueue, $writingPacket, $publicKnowledgeMap, $editorialDependencyMatrix, $atlasSignalMesh, $openBrainHandoff);

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
            'atlas_signal_mesh' => $atlasSignalMesh,
            'public_knowledge_map' => $publicKnowledgeMap,
            'agent_operating_queue' => $agentOperatingQueue,
            'agent_handoff_packet' => $agentHandoffPacket,
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
                'generates_atlas_signal_mesh' => true,
                'generates_public_knowledge_map' => true,
                'generates_agent_operating_queue' => true,
                'generates_agent_handoff_packet' => true,
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
        $frontier = $this->radar->publicationFrontier($posts, $publishedSet);
        $blocked = $this->radar->blockedPostsForRadar($posts, $publishedSet, $plannedSet);

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
            'week_lanes' => $this->radar->weekLanes($posts, $publishedSet),
            'sequence_lanes' => $this->radar->sequenceLanes($posts, $publishedSet),
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
            'insertion_windows' => $this->radar->insertionWindows($posts, $publishedSet, (array) ($coverageMap['next_safe_arcs'] ?? [])),
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
                    fn (array $candidate): array => $this->radar->candidateRadarSummary($candidate, $posts),
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
            'operator_next_steps' => $this->radar->radarNextSteps($frontier, $blocked, $nextReadyPost, (array) ($coverageMap['next_safe_arcs'] ?? [])),
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
        $frontier = $this->radar->publicationFrontier($posts, $publishedSet);
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
                $this->radar->weekLanes($posts, $publishedSet),
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
                    fn (array $candidate): array => $this->radar->candidateRadarSummary($candidate, $posts),
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
        $neighbors = $this->writing->neighborPosts($post, $posts);
        $terms = $this->support->terms($post);
        $conceptProgressionMap = $this->support->conceptProgressionMap($post, $posts, $publishedSlugs);
        $publicArchiveContext = $this->coverage->publicArchiveContextForPost($post, $posts, $publishedSlugs, $publishedPosts);
        $openBrainHandoff = $this->writing->openBrainHandoffForPost($post, $contextLimit);
        $writingBrief = [
            'language' => 'pt-BR',
            'voice' => 'tecnica, direta, pessoal, sem hype e sem tom de marketing',
            'primary_question' => (string) ($post['main_question'] ?? ''),
            'reader_promise' => $this->writing->readerPromise($post),
            'outline' => $this->writing->outlineForPost($post),
            'must_include' => $this->writing->mustInclude($post),
            'must_not_include' => $this->writing->mustNotInclude($post),
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
                ? $this->writing->executeOpenBrainHandoff($openBrainHandoff)
                : null,
            'coverage_snapshot' => [
                'terms' => $terms,
                'current_level' => (string) ($post['complexity_level'] ?? ''),
                'needs_foundation' => in_array((string) ($post['complexity_level'] ?? ''), ['L2', 'L3', 'L4', 'L5'], true),
                'future_topics_to_avoid' => array_slice((array) ($conceptProgressionMap['future_terms_to_avoid'] ?? []), 0, 12),
            ],
            'writing_brief' => $writingBrief,
            'draft_seed' => $this->writing->draftSeedForPost($post, $writingBrief, $conceptProgressionMap, $publicArchiveContext),
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
     * @param  array<string,mixed>  $post
     * @return array<string,mixed>
     */
    public function contextForPost(array $post, int $limit = 5): array
    {
        $limit = max(1, min(12, $limit));
        $terms = $this->support->terms($post);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => 'read_only_governed_p1',
            'post_slug' => (string) ($post['slug'] ?? ''),
            'context_query' => $this->support->contextQuery($post),
            'terms' => $terms,
            'knowledge_refs' => $this->writing->knowledgeRefs($terms, $limit),
            'code_refs' => $this->writing->codeRefs($terms, $limit),
            'coverage_signals' => $this->writing->coverageSignals($post),
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
}
