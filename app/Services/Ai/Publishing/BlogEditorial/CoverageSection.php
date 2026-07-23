<?php

declare(strict_types=1);

namespace App\Services\Ai\Publishing\BlogEditorial;

final class CoverageSection
{
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
























}
