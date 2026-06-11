<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Publishing;

use App\Models\AiCodebaseWorldModel;
use App\Models\AiCodebaseWorldModelEdge;
use App\Models\AiCodebaseWorldModelNode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AtlasBlogEditorialPlanCommandTest extends TestCase
{
    private string $siteRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->siteRoot = sys_get_temp_dir().'/atlas-blog-editorial-plan-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->siteRoot.'/content/backlog');
        File::ensureDirectoryExists($this->siteRoot.'/src/data');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->siteRoot);

        parent::tearDown();
    }

    public function test_reports_first_post_ready_when_nothing_is_published(): void
    {
        $this->writeBacklog();
        $this->writePublishedPosts([]);

        $payload = $this->runPlanner();

        $this->assertSame('atlas.blog_editorial_planner.v1', $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(3, data_get($payload, 'summary.planned_posts'));
        $this->assertSame(1, data_get($payload, 'summary.ready_posts'));
        $this->assertSame('o-que-e-o-atlas', data_get($payload, 'next_ready_post.slug'));
        $this->assertFalse(data_get($payload, 'guardrails.uses_graph_rag'));
        $this->assertFalse(data_get($payload, 'guardrails.uses_python_runtime'));
        $this->assertTrue(data_get($payload, 'guardrails.requires_human_approval_to_publish'));
    }

    public function test_reports_second_post_ready_when_first_is_published(): void
    {
        $this->writeBacklog();
        $this->writePublishedPosts(['o-que-e-o-atlas']);

        $payload = $this->runPlanner();

        $this->assertSame('ready', $payload['status']);
        $this->assertSame(1, data_get($payload, 'summary.published_posts'));
        $this->assertSame('por-que-estou-construindo-o-atlas', data_get($payload, 'next_ready_post.slug'));
    }

    public function test_blocks_backlog_when_prerequisite_points_forward(): void
    {
        $this->writeBacklog([
            [
                'order' => 1,
                'title' => 'O que e o Atlas',
                'slug' => 'o-que-e-o-atlas',
                'prerequisites' => ['por-que-estou-construindo-o-atlas'],
            ],
            [
                'order' => 2,
                'title' => 'Por que estou construindo o Atlas',
                'slug' => 'por-que-estou-construindo-o-atlas',
                'prerequisites' => [],
            ],
        ]);
        $this->writePublishedPosts([]);

        $payload = $this->runPlanner();

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('future_prerequisite', data_get($payload, 'findings.0.code'));
        $this->assertSame('o-que-e-o-atlas', data_get($payload, 'findings.0.post'));
    }

    public function test_attaches_governed_editorial_context_when_requested(): void
    {
        $this->bootContextReadModels();
        $this->seedContextReadModels();
        $this->writeBacklog([
            [
                'order' => 1,
                'title' => 'Memoria como ledger',
                'slug' => 'memoria-como-ledger',
                'prerequisites' => [],
            ],
        ]);
        $this->writePublishedPosts([]);

        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--with-context' => true,
            '--context-limit' => 3,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('read_only_governed_p1', $payload['mode']);
        $this->assertTrue(data_get($payload, 'summary.with_context'));
        $this->assertTrue(data_get($payload, 'guardrails.uses_existing_knowledge_read_models'));
        $this->assertFalse(data_get($payload, 'next_ready_post.editorial_context.guardrails.uses_graph_rag'));
        $knowledgePaths = collect(data_get($payload, 'next_ready_post.editorial_context.knowledge_refs', []))
            ->pluck('canonical_path')
            ->all();
        $symbolPaths = collect(data_get($payload, 'next_ready_post.editorial_context.code_refs.symbols', []))
            ->pluck('file_path')
            ->all();

        $this->assertContains('docs/engineering-knowledge-base/memory/contracts.md', $knowledgePaths);
        $this->assertContains('app/Services/Ai/Memory/AtlasMemoryLedgerService.php', $symbolPaths);
    }

    public function test_suggests_reviewable_backlog_candidates_from_existing_read_models(): void
    {
        $this->bootContextReadModels();
        $this->seedContextReadModels();
        $this->writeBacklog();
        $this->writePublishedPosts([]);

        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--suggest-candidates' => true,
            '--candidate-limit' => 3,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('read_only_governed_p1', $payload['mode']);
        $this->assertTrue(data_get($payload, 'summary.with_candidate_suggestions'));
        $this->assertFalse(data_get($payload, 'backlog_candidates.guardrails.writes_backlog'));
        $this->assertFalse(data_get($payload, 'backlog_candidates.guardrails.uses_graph_rag'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'backlog_candidates.candidate_count'));

        $candidateRefs = collect(data_get($payload, 'backlog_candidates.candidates', []))
            ->pluck('source_ref')
            ->all();
        $this->assertContains('docs/engineering-knowledge-base/memory/contracts.md', $candidateRefs);
    }

    public function test_source_map_reports_available_and_future_governed_editorial_sources(): void
    {
        $this->bootContextReadModels();
        $this->seedContextReadModels();
        $this->writeBacklog();
        $this->writePublishedPosts([]);

        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--source-map' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('read_only_governed_p1', $payload['mode']);
        $this->assertTrue(data_get($payload, 'summary.with_source_map'));
        $this->assertSame('atlas.blog_editorial_source_map.v1', data_get($payload, 'source_map.schema_version'));
        $this->assertSame('ready', data_get($payload, 'source_map.sources.engineering_knowledge.status'));
        $this->assertSame('ready', data_get($payload, 'source_map.sources.code_intelligence.status'));
        $this->assertSame('available_contract', data_get($payload, 'source_map.sources.open_brain_context_pack.status'));
        $this->assertSame('future_governed', data_get($payload, 'source_map.sources.graph_retrieval.status'));
        $this->assertFalse(data_get($payload, 'source_map.guardrails.uses_graph_rag'));

        $firstDirective = data_get($payload, 'source_map.post_source_directives.0');
        $this->assertSame('o-que-e-o-atlas', data_get($firstDirective, 'slug'));
        $this->assertContains('engineering_knowledge', data_get($firstDirective, 'required_sources'));
        $this->assertContains('graph_retrieval', data_get($firstDirective, 'deferred_sources'));
    }

    public function test_source_map_reconciles_external_public_archive_posts_with_planned_backlog(): void
    {
        $this->writeBacklog([
            [
                'order' => 1,
                'title' => 'O que e o Atlas',
                'slug' => 'o-que-e-o-atlas',
                'complexity_level' => 'L0',
                'collection' => 'atlas',
                'series' => 'building-atlas',
                'prerequisites' => [],
            ],
            [
                'order' => 2,
                'title' => 'Memoria como problema de banco de dados',
                'slug' => 'memoria-como-problema-de-banco-de-dados',
                'complexity_level' => 'L3',
                'collection' => 'ia-pessoal',
                'series' => 'agent-memory',
                'prerequisites' => ['o-que-e-o-atlas'],
            ],
        ]);
        File::put($this->siteRoot.'/src/data/site.js', <<<'JS'
export const collections = [];
export const posts = [
  {
    slug: "memory-is-a-database-problem",
    kind: "essay",
    date: "2026-05-12",
    reading: 11,
    tags: ["atlas", "memory", "local-first"],
    collection: "atlas",
    series: "building-atlas",
    original: "pt",
    en: { title: "Memory is a database problem", excerpt: "Most AI memory is a pile of embeddings." },
    pt: { title: "Memória é um problema de banco de dados", excerpt: "O Atlas trata memoria como ledger." },
  },
];
JS);

        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--source-map' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, data_get($payload, 'source_map.archive_reconciliation.external_published_count'));
        $this->assertSame('memory-is-a-database-problem', data_get($payload, 'source_map.archive_reconciliation.external_published.0.slug'));
        $this->assertSame('Memória é um problema de banco de dados', data_get($payload, 'source_map.archive_reconciliation.external_published.0.title'));
        $this->assertSame('memory-is-a-database-problem', data_get($payload, 'source_map.archive_reconciliation.bridge_candidates.0.published_slug'));
        $this->assertSame('memoria-como-problema-de-banco-de-dados', data_get($payload, 'source_map.archive_reconciliation.bridge_candidates.0.matched_planned_slug'));
        $this->assertContains(data_get($payload, 'source_map.archive_reconciliation.bridge_candidates.0.suggested_action'), [
            'review_for_duplicate_or_rewrite',
            'link_as_prior_artifact',
        ]);
    }

    public function test_coverage_map_reports_foundation_and_next_safe_arcs(): void
    {
        $this->writeFirstMonthFoundationBacklog();
        $this->writePublishedPosts(['o-que-e-o-atlas', 'por-que-estou-construindo-o-atlas']);

        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--coverage-map' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('read_only_governed_p1', $payload['mode']);
        $this->assertTrue(data_get($payload, 'summary.with_coverage_map'));
        $this->assertSame('atlas.blog_editorial_coverage_map.v1', data_get($payload, 'coverage_map.schema_version'));
        $this->assertSame(20, data_get($payload, 'coverage_map.summary.foundation_items'));
        $this->assertSame(20, data_get($payload, 'coverage_map.summary.foundation_planned'));
        $this->assertSame(2, data_get($payload, 'coverage_map.summary.foundation_published'));
        $this->assertSame(0, data_get($payload, 'coverage_map.summary.deep_sequence_warning_count'));
        $this->assertFalse(data_get($payload, 'coverage_map.guardrails.uses_graph_rag'));

        $readyArcs = collect(data_get($payload, 'coverage_map.next_safe_arcs', []))
            ->where('ready_after_first_month', true)
            ->pluck('arc')
            ->all();

        $this->assertContains('knowledge_governance', $readyArcs);
        $this->assertContains('capture_inbox', $readyArcs);
    }

    public function test_coverage_map_warns_when_deep_post_appears_without_foundation(): void
    {
        $this->writeBacklog([
            [
                'order' => 1,
                'title' => 'Memoria como ledger',
                'slug' => 'memoria-como-ledger',
                'complexity_level' => 'L3',
                'collection' => 'ia-pessoal',
                'series' => 'agent-memory',
                'prerequisites' => [],
            ],
        ]);
        $this->writePublishedPosts([]);

        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--coverage-map' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, data_get($payload, 'coverage_map.summary.deep_sequence_warning_count'));
        $this->assertSame('deep_post_without_prior_foundation', data_get($payload, 'coverage_map.deep_sequence_warnings.0.code'));
    }

    public function test_editorial_radar_reports_week_lanes_gaps_and_candidate_feed(): void
    {
        $this->bootContextReadModels();
        $this->seedContextReadModels();
        $this->writeFirstMonthFoundationBacklog();
        $this->writePublishedPosts(['o-que-e-o-atlas', 'por-que-estou-construindo-o-atlas']);

        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--editorial-radar' => true,
            '--candidate-limit' => 3,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('read_only_governed_p1', $payload['mode']);
        $this->assertTrue(data_get($payload, 'summary.with_editorial_radar'));
        $this->assertSame('atlas.blog_editorial_radar.v1', data_get($payload, 'editorial_radar.schema_version'));
        $this->assertSame(2, data_get($payload, 'editorial_radar.current_state.contiguous_published_until_order'));
        $this->assertSame(3, data_get($payload, 'editorial_radar.current_state.next_sequence_order'));
        $this->assertSame('o-problema-dos-assistentes-de-ia-hoje', data_get($payload, 'editorial_radar.current_state.next_ready_slug'));
        $this->assertSame(4, count(data_get($payload, 'editorial_radar.week_lanes')));
        $this->assertSame('building-atlas', data_get($payload, 'editorial_radar.sequence_lanes.0.series'));
        $this->assertGreaterThanOrEqual(1, count(data_get($payload, 'editorial_radar.gap_register.missing_foundation_published')));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'editorial_radar.candidate_feed.candidate_count'));
        $this->assertSame('future_governed', data_get($payload, 'editorial_radar.source_readiness.graph_retrieval'));
        $this->assertFalse(data_get($payload, 'editorial_radar.guardrails.writes_backlog'));
        $this->assertFalse(data_get($payload, 'editorial_radar.guardrails.uses_graph_rag'));
    }

    public function test_operating_state_reports_compact_blog_area_state_without_writing(): void
    {
        $this->bootContextReadModels();
        $this->seedContextReadModels();
        $this->writeFirstMonthFoundationBacklog();
        $this->writePublishedPosts(['o-que-e-o-atlas', 'por-que-estou-construindo-o-atlas']);

        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--operating-state' => true,
            '--candidate-limit' => 3,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('read_only_governed_p1', $payload['mode']);
        $this->assertTrue(data_get($payload, 'summary.with_operating_state'));
        $this->assertSame('atlas.blog_editorial_operating_state.v1', data_get($payload, 'operating_state.schema_version'));
        $this->assertSame('read_only_area_state_p1', data_get($payload, 'operating_state.mode'));
        $this->assertSame('foundation_sequence_in_progress', data_get($payload, 'operating_state.stage.current'));
        $this->assertSame(20, data_get($payload, 'operating_state.counts.planned_posts'));
        $this->assertSame(2, data_get($payload, 'operating_state.counts.published_posts'));
        $this->assertSame(3, data_get($payload, 'operating_state.publication_frontier.next_sequence_order'));
        $this->assertSame('next_post_ready_future_blockers', data_get($payload, 'operating_state.publication_frontier.sequence_health'));
        $this->assertSame('o-problema-dos-assistentes-de-ia-hoje', data_get($payload, 'operating_state.next_post.slug'));
        $this->assertSame(4, count(data_get($payload, 'operating_state.week_board')));
        $this->assertSame('o-problema-dos-assistentes-de-ia-hoje', data_get($payload, 'operating_state.week_board.0.next_unpublished_slug'));
        $this->assertSame('future_governed', data_get($payload, 'operating_state.source_posture.graph_retrieval'));
        $this->assertArrayHasKey('daily_operations', data_get($payload, 'operating_state.area_surfaces'));
        $this->assertFalse(data_get($payload, 'operating_state.guardrails.writes_backlog'));
        $this->assertFalse(data_get($payload, 'operating_state.guardrails.publishes_content'));
    }

    public function test_graph_rag_readiness_reports_components_and_blockers_without_enabling_runtime(): void
    {
        $this->writeFirstMonthFoundationBacklog();
        $this->writePublishedPosts(['o-que-e-o-atlas']);

        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--graph-rag-readiness' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('read_only_governed_p1', $payload['mode']);
        $this->assertTrue(data_get($payload, 'summary.with_graph_rag_readiness'));
        $this->assertSame('atlas.blog_editorial_graph_rag_readiness.v1', data_get($payload, 'graph_rag_readiness.schema_version'));
        $this->assertSame('not_promoted', data_get($payload, 'graph_rag_readiness.status'));
        $this->assertSame('p1_read_only_editorial_intelligence', data_get($payload, 'graph_rag_readiness.current_phase'));
        $this->assertSame('p2_bounded_graph_rag_editorial_context', data_get($payload, 'graph_rag_readiness.target_phase'));

        $components = collect(data_get($payload, 'graph_rag_readiness.available_components', []))->keyBy('component');
        $this->assertSame('available', data_get($components->get('ap_811_code_graph_traversal'), 'status'));
        $this->assertSame('available', data_get($components->get('atlas_graph_retrieval_service'), 'status'));
        $this->assertSame('available', data_get($components->get('world_model_graph_ranker'), 'status'));
        $this->assertSame('available', data_get($components->get('mandatory_rag_gate'), 'status'));

        $blockers = collect(data_get($payload, 'graph_rag_readiness.missing_or_blocking_items', []))
            ->pluck('code')
            ->all();
        $this->assertContains('ap_817_p2_review_required', $blockers);
        $this->assertContains('kernel_decision_receipt_required', $blockers);
        $this->assertContains('global_graph_retrieval_future_governed', $blockers);
        $this->assertNotContains('editorial_golden_set_missing', $blockers);
        $this->assertSame('passed', data_get($payload, 'graph_rag_readiness.editorial_golden_set.status'));
        $this->assertSame(7, data_get($payload, 'graph_rag_readiness.editorial_golden_set.summary.case_count'));
        $this->assertContains('editorial_radar', data_get($payload, 'graph_rag_readiness.allowed_now'));
        $this->assertContains('direct_graph_traversal_for_blog_planning', data_get($payload, 'graph_rag_readiness.deferred_until_p2'));
        $this->assertFalse(data_get($payload, 'graph_rag_readiness.guardrails.uses_graph_rag'));
        $this->assertFalse(data_get($payload, 'graph_rag_readiness.guardrails.uses_python_runtime'));
        $this->assertFalse(data_get($payload, 'graph_rag_readiness.guardrails.invokes_graph_retrieval'));
    }

    public function test_editorial_golden_set_proves_foundation_first_sequence_without_runtime(): void
    {
        $this->writeFirstMonthFoundationBacklog();
        $this->writePublishedPosts(['o-que-e-o-atlas', 'por-que-estou-construindo-o-atlas']);

        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--editorial-golden-set' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('read_only_governed_p1', $payload['mode']);
        $this->assertTrue(data_get($payload, 'summary.with_editorial_golden_set'));
        $this->assertSame('atlas.blog_editorial_golden_set.v1', data_get($payload, 'editorial_golden_set.schema_version'));
        $this->assertSame('passed', data_get($payload, 'editorial_golden_set.status'));
        $this->assertSame(7, data_get($payload, 'editorial_golden_set.summary.case_count'));
        $this->assertSame(0, data_get($payload, 'editorial_golden_set.summary.failed_count'));

        $cases = collect(data_get($payload, 'editorial_golden_set.cases', []))->keyBy('code');
        $this->assertTrue(data_get($cases->get('sequence_starts_with_atlas_identity'), 'passed'));
        $this->assertTrue(data_get($cases->get('published_prefix_advances_to_next_foundation'), 'passed'));
        $this->assertTrue(data_get($cases->get('deep_topic_without_foundation_is_warned'), 'passed'));
        $this->assertTrue(data_get($cases->get('future_terms_stay_future_until_introduced'), 'passed'));
        $this->assertTrue(data_get($payload, 'editorial_golden_set.promotion_signal.editorial_golden_set_ready'));
        $this->assertFalse(data_get($payload, 'editorial_golden_set.guardrails.uses_graph_rag'));
        $this->assertFalse(data_get($payload, 'editorial_golden_set.guardrails.uses_python_runtime'));
        $this->assertFalse(data_get($payload, 'editorial_golden_set.guardrails.invokes_graph_retrieval'));
    }

    public function test_editorial_graph_context_uses_bounded_world_model_receipt_without_publication_power(): void
    {
        $this->bootWorldModelSchemaForEditorialGraph();
        $worldModelId = $this->seedEditorialBlogWorldModel();
        $this->writeFirstMonthFoundationBacklog();
        $this->writePublishedPosts(['o-que-e-o-atlas', 'por-que-estou-construindo-o-atlas']);

        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--editorial-graph-context' => true,
            '--graph-world-model-id' => $worldModelId,
            '--graph-context-limit' => 4,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('read_only_governed_p1', $payload['mode']);
        $this->assertTrue(data_get($payload, 'summary.with_editorial_graph_context'));
        $this->assertSame('atlas.blog_editorial_graph_context.v1', data_get($payload, 'editorial_graph_context.schema_version'));
        $this->assertSame('ready', data_get($payload, 'editorial_graph_context.status'));
        $this->assertSame('o-problema-dos-assistentes-de-ia-hoje', data_get($payload, 'editorial_graph_context.post.slug'));
        $this->assertSame('passed', data_get($payload, 'editorial_graph_context.editorial_golden_set.status'));
        $this->assertSame('ready', data_get($payload, 'editorial_graph_context.graph_retrieval.status'));
        $this->assertSame('codebase_world_model_bounded', data_get($payload, 'editorial_graph_context.graph_retrieval.graph_scope'));
        $this->assertSame('passed', data_get($payload, 'editorial_graph_context.graph_retrieval.traversal_receipt.status'));
        $this->assertTrue(data_get($payload, 'editorial_graph_context.graph_retrieval.traversal_receipt.bounded_traversal'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'editorial_graph_context.graph_retrieval.evidence_set.evidence_count'));
        $this->assertFalse(data_get($payload, 'editorial_graph_context.graph_retrieval.policy.global_graph_retrieval_active'));
        $this->assertFalse(data_get($payload, 'editorial_graph_context.graph_retrieval.policy.python_runtime_invoked'));
        $this->assertFalse(data_get($payload, 'editorial_graph_context.graph_retrieval.policy.providers_invoked'));
        $this->assertFalse(data_get($payload, 'editorial_graph_context.editorial_policy.may_reorder_backlog'));
        $this->assertFalse(data_get($payload, 'editorial_graph_context.editorial_policy.may_publish'));
        $this->assertTrue(data_get($payload, 'editorial_graph_context.guardrails.invokes_bounded_graph_retrieval'));
        $this->assertFalse(data_get($payload, 'editorial_graph_context.guardrails.uses_global_graph_rag'));
    }

    public function test_editorial_graph_candidates_feed_review_only_future_backlog_ideas(): void
    {
        $this->bootWorldModelSchemaForEditorialGraph();
        $worldModelId = $this->seedEditorialBlogWorldModel();
        $this->writeFirstMonthFoundationBacklog();
        $this->writePublishedPosts(['o-que-e-o-atlas', 'por-que-estou-construindo-o-atlas']);

        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--editorial-graph-candidates' => true,
            '--graph-world-model-id' => $worldModelId,
            '--graph-context-limit' => 4,
            '--candidate-limit' => 3,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue(data_get($payload, 'summary.with_editorial_graph_candidates'));
        $this->assertSame('atlas.blog_editorial_graph_candidates.v1', data_get($payload, 'editorial_graph_candidates.schema_version'));
        $this->assertSame('ready', data_get($payload, 'editorial_graph_candidates.status'));
        $this->assertSame('como-o-atlas-esta-evoluindo', data_get($payload, 'editorial_graph_candidates.sequence_policy.default_suggested_after_slug'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'editorial_graph_candidates.candidate_count'));
        $this->assertSame('bounded_world_model_graph', data_get($payload, 'editorial_graph_candidates.candidates.0.source_type'));
        $this->assertSame('como-o-atlas-esta-evoluindo', data_get($payload, 'editorial_graph_candidates.candidates.0.suggested_after_slug'));
        $this->assertStringContainsString('review queue', data_get($payload, 'editorial_graph_candidates.candidates.0.promotion_rule'));
        $this->assertSame('ready', data_get($payload, 'editorial_graph_candidates.graph_context.graph_retrieval_status'));
        $this->assertTrue(data_get($payload, 'editorial_graph_candidates.graph_context.bounded_traversal'));
        $this->assertFalse(data_get($payload, 'editorial_graph_candidates.graph_context.global_graph_retrieval_active'));
        $this->assertFalse(data_get($payload, 'editorial_graph_candidates.guardrails.writes_backlog'));
        $this->assertFalse(data_get($payload, 'editorial_graph_candidates.guardrails.writes_review_queue'));
        $this->assertFalse(data_get($payload, 'editorial_graph_candidates.guardrails.publishes_content'));
        $this->assertFalse(data_get($payload, 'editorial_graph_candidates.guardrails.may_reorder_backlog'));
        $this->assertTrue(data_get($payload, 'editorial_graph_candidates.guardrails.requires_human_approval_to_accept'));
        $this->assertFalse(File::exists($this->siteRoot.'/content/backlog/blog-candidates.yaml'));
    }

    public function test_accept_graph_candidate_dry_run_uses_same_review_queue_contract(): void
    {
        $this->bootWorldModelSchemaForEditorialGraph();
        $worldModelId = $this->seedEditorialBlogWorldModel();
        $this->writeFirstMonthFoundationBacklog();
        $this->writePublishedPosts(['o-que-e-o-atlas', 'por-que-estou-construindo-o-atlas']);

        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--editorial-graph-candidates' => true,
            '--graph-world-model-id' => $worldModelId,
            '--accept-candidate' => 'como-o-atlas-decide-a-ordem-do-blog',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('dry_run_review_queue_p1', data_get($payload, 'candidate_acceptance.mode'));
        $this->assertSame('bounded_world_model_graph', data_get($payload, 'candidate_acceptance.candidate.source_type'));
        $this->assertSame('como-o-atlas-esta-evoluindo', data_get($payload, 'candidate_acceptance.review_queue_entry.suggested_after_slug'));
        $this->assertStringContainsString('slug: "como-o-atlas-decide-a-ordem-do-blog"', data_get($payload, 'candidate_acceptance.yaml_snippet'));
        $this->assertFalse(data_get($payload, 'candidate_acceptance.guardrails.writes_main_backlog'));
        $this->assertFalse(data_get($payload, 'candidate_acceptance.guardrails.writes_review_queue'));
        $this->assertTrue(data_get($payload, 'candidate_acceptance.guardrails.invokes_bounded_graph_retrieval'));
        $this->assertFalse(data_get($payload, 'candidate_acceptance.guardrails.uses_global_graph_rag'));
        $this->assertFalse(File::exists($this->siteRoot.'/content/backlog/blog-candidates.yaml'));
    }

    public function test_accept_graph_candidate_with_write_creates_review_queue_only(): void
    {
        $this->bootWorldModelSchemaForEditorialGraph();
        $worldModelId = $this->seedEditorialBlogWorldModel();
        $this->writeFirstMonthFoundationBacklog();
        $this->writePublishedPosts(['o-que-e-o-atlas', 'por-que-estou-construindo-o-atlas']);

        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--editorial-graph-candidates' => true,
            '--graph-world-model-id' => $worldModelId,
            '--accept-candidate' => 'como-o-atlas-decide-a-ordem-do-blog',
            '--write' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('explicit_write_review_queue_p1', data_get($payload, 'candidate_acceptance.mode'));
        $this->assertTrue(data_get($payload, 'candidate_acceptance.guardrails.writes_review_queue'));
        $this->assertFalse(data_get($payload, 'candidate_acceptance.guardrails.writes_main_backlog'));
        $this->assertTrue(File::exists($this->siteRoot.'/content/backlog/blog-candidates.yaml'));
        $this->assertStringContainsString('source_type: "bounded_world_model_graph"', File::get($this->siteRoot.'/content/backlog/blog-candidates.yaml'));
        $this->assertStringContainsString('slug: "como-o-atlas-decide-a-ordem-do-blog"', File::get($this->siteRoot.'/content/backlog/blog-candidates.yaml'));
    }

    public function test_review_queue_state_reports_accepted_candidates_without_writing(): void
    {
        $this->bootWorldModelSchemaForEditorialGraph();
        $worldModelId = $this->seedEditorialBlogWorldModel();
        $this->writeFirstMonthFoundationBacklog();
        $this->writePublishedPosts(['o-que-e-o-atlas', 'por-que-estou-construindo-o-atlas']);

        Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--editorial-graph-candidates' => true,
            '--graph-world-model-id' => $worldModelId,
            '--accept-candidate' => 'como-o-atlas-decide-a-ordem-do-blog',
            '--write' => true,
            '--json' => true,
        ]);

        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--review-queue' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue(data_get($payload, 'summary.with_review_queue'));
        $this->assertSame('atlas.blog_editorial_review_queue.v1', data_get($payload, 'review_queue.schema_version'));
        $this->assertSame('ready', data_get($payload, 'review_queue.status'));
        $this->assertSame(1, data_get($payload, 'review_queue.candidate_count'));
        $this->assertSame(0, data_get($payload, 'review_queue.duplicate_count'));
        $this->assertSame('como-o-atlas-decide-a-ordem-do-blog', data_get($payload, 'review_queue.candidates.0.slug'));
        $this->assertTrue(data_get($payload, 'review_queue.candidates.0.ready_for_promotion_review'));
        $this->assertFalse(data_get($payload, 'review_queue.guardrails.writes_backlog'));
    }

    public function test_review_queue_deduplicates_kb_candidate_suggestions(): void
    {
        $this->bootContextReadModels();
        $this->seedContextReadModels();
        $this->writeBacklog();
        $this->writePublishedPosts([]);

        Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--accept-candidate' => 'memory-ledger-contract',
            '--write' => true,
            '--json' => true,
        ]);

        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--suggest-candidates' => true,
            '--candidate-limit' => 10,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $slugs = collect(data_get($payload, 'backlog_candidates.candidates', []))
            ->pluck('slug')
            ->all();

        $this->assertNotContains('memory-ledger-contract', $slugs);
        $this->assertTrue(data_get($payload, 'backlog_candidates.guardrails.deduplicates_review_queue'));
    }

    public function test_operations_packet_uses_review_queue_state_to_deduplicate_candidates(): void
    {
        $this->bootContextReadModels();
        $this->seedContextReadModels();
        $this->writeBacklog();
        $this->writePublishedPosts([]);

        Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--accept-candidate' => 'memory-ledger-contract',
            '--write' => true,
            '--json' => true,
        ]);

        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--operations' => true,
            '--candidate-limit' => 10,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $slugs = collect(data_get($payload, 'operations_packet.candidate_feed.candidates', []))
            ->pluck('slug')
            ->all();

        $this->assertSame('ready', data_get($payload, 'operations_packet.review_queue.status'));
        $this->assertSame(1, data_get($payload, 'operations_packet.review_queue.candidate_count'));
        $this->assertSame(['memory-ledger-contract'], data_get($payload, 'operations_packet.review_queue.queued_slugs'));
        $this->assertSame('review_or_promote_candidates', data_get($payload, 'operations_packet.review_queue.next_review_action'));
        $this->assertNotContains('memory-ledger-contract', $slugs);
    }

    public function test_editorial_radar_uses_review_queue_state_to_deduplicate_candidates(): void
    {
        $this->bootContextReadModels();
        $this->seedContextReadModels();
        $this->writeBacklog();
        $this->writePublishedPosts([]);

        Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--accept-candidate' => 'memory-ledger-contract',
            '--write' => true,
            '--json' => true,
        ]);

        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--editorial-radar' => true,
            '--candidate-limit' => 10,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $slugs = collect(data_get($payload, 'editorial_radar.candidate_feed.candidates', []))
            ->pluck('slug')
            ->all();

        $this->assertSame('ready', data_get($payload, 'editorial_radar.review_queue.status'));
        $this->assertSame(1, data_get($payload, 'editorial_radar.review_queue.candidate_count'));
        $this->assertSame(['memory-ledger-contract'], data_get($payload, 'editorial_radar.review_queue.queued_slugs'));
        $this->assertSame('review_or_promote_candidates', data_get($payload, 'editorial_radar.review_queue.next_review_action'));
        $this->assertNotContains('memory-ledger-contract', $slugs);
    }

    public function test_review_queue_deduplicates_graph_candidate_suggestions(): void
    {
        $this->bootWorldModelSchemaForEditorialGraph();
        $worldModelId = $this->seedEditorialBlogWorldModel();
        $this->writeFirstMonthFoundationBacklog();
        $this->writePublishedPosts(['o-que-e-o-atlas', 'por-que-estou-construindo-o-atlas']);

        Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--editorial-graph-candidates' => true,
            '--graph-world-model-id' => $worldModelId,
            '--accept-candidate' => 'como-o-atlas-decide-a-ordem-do-blog',
            '--write' => true,
            '--json' => true,
        ]);

        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--editorial-graph-candidates' => true,
            '--graph-world-model-id' => $worldModelId,
            '--candidate-limit' => 10,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $slugs = collect(data_get($payload, 'editorial_graph_candidates.candidates', []))
            ->pluck('slug')
            ->all();

        $this->assertNotContains('como-o-atlas-decide-a-ordem-do-blog', $slugs);
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'editorial_graph_candidates.candidate_count'));
        $this->assertSame('como-o-atlas-esta-evoluindo', data_get($payload, 'editorial_graph_candidates.sequence_policy.default_suggested_after_slug'));
    }

    public function test_writing_packet_prepares_next_ready_post_without_writing(): void
    {
        $this->bootContextReadModels();
        $this->seedContextReadModels();
        $this->writeBacklog();
        $this->writePublishedPosts([]);

        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--writing-packet' => true,
            '--context-limit' => 3,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('read_only_governed_p1', $payload['mode']);
        $this->assertTrue(data_get($payload, 'summary.with_writing_packet'));
        $this->assertSame('atlas.blog_editorial_writing_packet.v1', data_get($payload, 'writing_packet.schema_version'));
        $this->assertSame('o-que-e-o-atlas', data_get($payload, 'writing_packet.post.slug'));
        $this->assertSame('atlas.blog_editorial_concept_progression.v1', data_get($payload, 'writing_packet.concept_progression_map.schema_version'));
        $this->assertSame('o-que-e-o-atlas', data_get($payload, 'writing_packet.concept_progression_map.current_slug'));
        $this->assertSame('pt-BR', data_get($payload, 'writing_packet.writing_brief.language'));
        $this->assertSame('atlas.blog_editorial_private_draft_seed.v1', data_get($payload, 'writing_packet.draft_seed.schema_version'));
        $this->assertSame('o-que-e-o-atlas', data_get($payload, 'writing_packet.draft_seed.post_slug'));
        $this->assertSame('pt-BR', data_get($payload, 'writing_packet.draft_seed.language'));
        $this->assertStringStartsWith('Este texto responde, sem pressa e sem jargao:', data_get($payload, 'writing_packet.draft_seed.working_thesis'));
        $this->assertGreaterThanOrEqual(1, count((array) data_get($payload, 'writing_packet.draft_seed.section_seeds', [])));
        $this->assertFalse(data_get($payload, 'writing_packet.draft_seed.guardrails.writes_draft'));
        $this->assertFalse(data_get($payload, 'writing_packet.draft_seed.guardrails.publishes_content'));
        $this->assertFalse(data_get($payload, 'writing_packet.draft_seed.guardrails.generates_full_article'));
        $this->assertFalse(data_get($payload, 'writing_packet.guardrails.writes_draft'));
        $this->assertFalse(data_get($payload, 'writing_packet.guardrails.publishes_content'));
        $this->assertFalse(data_get($payload, 'writing_packet.guardrails.generates_full_article'));
        $this->assertTrue(data_get($payload, 'writing_packet.guardrails.generates_private_draft_seed'));
        $this->assertSame('atlas.blog_editorial_open_brain_handoff.v1', data_get($payload, 'writing_packet.open_brain_handoff.schema_version'));
        $this->assertSame('o-que-e-o-atlas', data_get($payload, 'writing_packet.open_brain_handoff.payload.post.slug'));
        $this->assertFalse(data_get($payload, 'writing_packet.open_brain_handoff.guardrails.uses_graph_rag'));
        $this->assertContains('Nao expor paths locais, tokens, prompts, traces ou detalhes privados.', data_get($payload, 'writing_packet.writing_brief.must_not_include'));
    }

    public function test_writing_packet_maps_concept_progression_before_deep_topics(): void
    {
        $this->writeBacklog([
            [
                'order' => 1,
                'title' => 'O que e o Atlas',
                'slug' => 'o-que-e-o-atlas',
                'topics' => ['atlas', 'produto'],
                'prerequisites' => [],
            ],
            [
                'order' => 2,
                'title' => 'Por que estou construindo o Atlas',
                'slug' => 'por-que-estou-construindo-o-atlas',
                'topics' => ['atlas', 'visao', 'processo'],
                'prerequisites' => ['o-que-e-o-atlas'],
            ],
            [
                'order' => 3,
                'title' => 'Memoria como ledger',
                'slug' => 'memoria-como-ledger',
                'complexity_level' => 'L3',
                'topics' => ['memoria', 'ledger', 'governanca'],
                'prerequisites' => ['por-que-estou-construindo-o-atlas'],
            ],
            [
                'order' => 4,
                'title' => 'Graph RAG profundo no Atlas',
                'slug' => 'graph-rag-profundo-no-atlas',
                'complexity_level' => 'L5',
                'topics' => ['graph-rag', 'python-runtime', 'embedding'],
                'prerequisites' => ['memoria-como-ledger'],
            ],
        ]);
        $this->writePublishedPosts(['o-que-e-o-atlas', 'por-que-estou-construindo-o-atlas']);

        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--writing-packet' => true,
            '--writing-slug' => 'memoria-como-ledger',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $map = data_get($payload, 'writing_packet.concept_progression_map');

        $this->assertSame('atlas.blog_editorial_concept_progression.v1', data_get($map, 'schema_version'));
        $this->assertSame('memoria-como-ledger', data_get($map, 'current_slug'));
        $this->assertContains('produto', data_get($map, 'introduced_terms'));
        $this->assertContains('processo', data_get($map, 'introduced_terms'));
        $this->assertContains('memoria', data_get($map, 'current_terms'));
        $this->assertContains('ledger', data_get($map, 'allowed_terms'));
        $this->assertContains('graph-rag', data_get($map, 'future_terms_to_avoid'));
        $this->assertContains('python-runtime', data_get($map, 'future_terms_to_avoid'));
        $this->assertFalse(data_get($map, 'guardrails.uses_graph_rag'));
    }

    public function test_writing_packet_can_execute_open_brain_context_export_without_returning_raw_pack(): void
    {
        $this->bootContextReadModels();
        $this->seedContextReadModels();
        $this->writeBacklog();
        $this->writePublishedPosts([]);

        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--writing-packet' => true,
            '--execute-open-brain' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('audited_context_execution_p1', $payload['mode']);
        $this->assertTrue(data_get($payload, 'summary.with_open_brain_execution'));
        $this->assertSame('atlas.blog_editorial_open_brain_execution.v1', data_get($payload, 'writing_packet.open_brain_context.schema_version'));
        $this->assertSame('ready', data_get($payload, 'writing_packet.open_brain_context.status'));
        $this->assertTrue(data_get($payload, 'writing_packet.open_brain_context.invoked_by_this_command'));
        $this->assertNotSame('', data_get($payload, 'writing_packet.open_brain_context.context_pack_hash'));
        $this->assertTrue(data_get($payload, 'writing_packet.open_brain_context.summary.provider_safe'));
        $this->assertFalse(data_get($payload, 'writing_packet.open_brain_context.safety.raw_content_exposed'));
        $this->assertFalse(data_get($payload, 'writing_packet.open_brain_context.guardrails.raw_context_pack_returned'));
        $this->assertArrayNotHasKey('context_pack', data_get($payload, 'writing_packet.open_brain_context'));
        $this->assertTrue(data_get($payload, 'guardrails.executes_open_brain_context'));
        $this->assertTrue(data_get($payload, 'guardrails.writes_audit_log'));
        $this->assertFalse(data_get($payload, 'guardrails.publishes_content'));
    }

    public function test_writing_packet_can_target_specific_planned_slug(): void
    {
        $this->writeBacklog();
        $this->writePublishedPosts([]);

        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--writing-packet' => true,
            '--writing-slug' => 'por-que-estou-construindo-o-atlas',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('por-que-estou-construindo-o-atlas', data_get($payload, 'writing_packet.post.slug'));
        $this->assertSame('o-que-e-o-atlas', data_get($payload, 'writing_packet.sequence.previous_post.slug'));
        $this->assertSame('o-problema-dos-assistentes-de-ia-hoje', data_get($payload, 'writing_packet.sequence.next_post.slug'));
    }

    public function test_writing_packet_includes_prior_public_archive_artifacts(): void
    {
        $this->writeBacklog([
            [
                'order' => 1,
                'title' => 'O que e o Atlas',
                'slug' => 'o-que-e-o-atlas',
                'complexity_level' => 'L0',
                'collection' => 'atlas',
                'series' => 'building-atlas',
                'prerequisites' => [],
            ],
            [
                'order' => 2,
                'title' => 'Memoria como problema de banco de dados',
                'slug' => 'memoria-como-problema-de-banco-de-dados',
                'complexity_level' => 'L3',
                'collection' => 'ia-pessoal',
                'series' => 'agent-memory',
                'prerequisites' => ['o-que-e-o-atlas'],
            ],
        ]);
        File::put($this->siteRoot.'/src/data/site.js', <<<'JS'
export const collections = [];
export const posts = [
  {
    slug: "memory-is-a-database-problem",
    kind: "essay",
    date: "2026-05-12",
    tags: ["atlas", "memory", "database"],
    collection: "atlas",
    series: "building-atlas",
    pt: { title: "Memória é um problema de banco de dados", excerpt: "Memoria como estrutura." },
    en: { title: "Memory is a database problem", excerpt: "Memory as structure." },
  },
];
JS);

        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--writing-packet' => true,
            '--writing-slug' => 'memoria-como-problema-de-banco-de-dados',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.blog_editorial_public_archive_context.v1', data_get($payload, 'writing_packet.public_archive_context.schema_version'));
        $this->assertSame('memory-is-a-database-problem', data_get($payload, 'writing_packet.public_archive_context.prior_public_artifacts.0.published_slug'));
        $this->assertSame('memoria-como-problema-de-banco-de-dados', data_get($payload, 'writing_packet.public_archive_context.prior_public_artifacts.0.matched_planned_slug'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'writing_packet.public_archive_context.duplicate_risk_count'));
    }

    public function test_operations_packet_reports_daily_next_action_without_writing(): void
    {
        $this->bootContextReadModels();
        $this->seedContextReadModels();
        $this->writeBacklog();
        $this->writePublishedPosts([]);

        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--operations' => true,
            '--candidate-limit' => 3,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('read_only_governed_p1', $payload['mode']);
        $this->assertTrue(data_get($payload, 'summary.with_operations_packet'));
        $this->assertSame('atlas.blog_editorial_operations_packet.v1', data_get($payload, 'operations_packet.schema_version'));
        $this->assertSame('prepare_next_post', data_get($payload, 'operations_packet.next_action.action'));
        $this->assertSame('o-que-e-o-atlas', data_get($payload, 'operations_packet.next_action.slug'));
        $this->assertSame('o-que-e-o-atlas', data_get($payload, 'operations_packet.writing_packet.post.slug'));
        $this->assertSame('atlas.blog_editorial_publishing_plan.v1', data_get($payload, 'operations_packet.publishing_plan.schema_version'));
        $this->assertSame('read_only_calendar_pipeline_p1', data_get($payload, 'operations_packet.publishing_plan.mode'));
        $this->assertSame(3, data_get($payload, 'operations_packet.publishing_plan.summary.slot_count'));
        $this->assertSame(1, data_get($payload, 'operations_packet.publishing_plan.summary.ready_slots'));
        $this->assertSame('draft_next_ready_post', data_get($payload, 'operations_packet.publishing_plan.today_lane.action'));
        $this->assertSame('o-que-e-o-atlas', data_get($payload, 'operations_packet.publishing_plan.today_lane.slug'));
        $this->assertSame('ready_to_draft', data_get($payload, 'operations_packet.publishing_plan.next_slots.0.status'));
        $this->assertSame('private_seed_ready', data_get($payload, 'operations_packet.publishing_plan.next_slots.0.pipeline_stage'));
        $this->assertSame('review_seed_then_request_private_draft', data_get($payload, 'operations_packet.publishing_plan.next_slots.0.human_action'));
        $this->assertFalse(data_get($payload, 'operations_packet.publishing_plan.guardrails.writes_draft'));
        $this->assertFalse(data_get($payload, 'operations_packet.publishing_plan.guardrails.publishes_content'));
        $this->assertSame('atlas.blog_editorial_topic_ledger.v1', data_get($payload, 'operations_packet.topic_ledger.schema_version'));
        $this->assertSame('read_only_topic_coverage_p1', data_get($payload, 'operations_packet.topic_ledger.mode'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'operations_packet.topic_ledger.summary.topic_count'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'operations_packet.topic_ledger.summary.planned_topic_count'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'operations_packet.topic_ledger.summary.candidate_topic_count'));
        $buildingAtlasRow = collect(data_get($payload, 'operations_packet.topic_ledger.rows', []))
            ->firstWhere('topic', 'building-atlas');
        $this->assertIsArray($buildingAtlasRow);
        $this->assertSame('planned', data_get($buildingAtlasRow, 'status'));
        $this->assertSame(3, data_get($buildingAtlasRow, 'planned_count'));
        $this->assertSame('draft_when_sequence_reaches_first_planned_post', data_get($buildingAtlasRow, 'next_action'));
        $this->assertFalse(data_get($payload, 'operations_packet.topic_ledger.guardrails.writes_backlog'));
        $this->assertFalse(data_get($payload, 'operations_packet.topic_ledger.guardrails.publishes_content'));
        $this->assertSame('atlas.blog_editorial_roadmap.v1', data_get($payload, 'operations_packet.editorial_roadmap.schema_version'));
        $this->assertSame('read_only_reader_journey_p1', data_get($payload, 'operations_packet.editorial_roadmap.mode'));
        $this->assertGreaterThanOrEqual(7, data_get($payload, 'operations_packet.editorial_roadmap.summary.phase_count'));
        $this->assertSame('fundacao', data_get($payload, 'operations_packet.editorial_roadmap.summary.active_phase_key'));
        $this->assertSame('active', data_get($payload, 'operations_packet.editorial_roadmap.phases.0.status'));
        $this->assertSame('o-que-e-o-atlas', data_get($payload, 'operations_packet.editorial_roadmap.phases.0.next_post.slug'));
        $this->assertFalse(data_get($payload, 'operations_packet.editorial_roadmap.guardrails.writes_backlog'));
        $this->assertFalse(data_get($payload, 'operations_packet.editorial_roadmap.guardrails.publishes_content'));
        $this->assertSame('atlas.blog_editorial_dependency_matrix.v1', data_get($payload, 'operations_packet.editorial_dependency_matrix.schema_version'));
        $this->assertSame('read_only_prerequisite_ladder_p1', data_get($payload, 'operations_packet.editorial_dependency_matrix.mode'));
        $this->assertSame(3, data_get($payload, 'operations_packet.editorial_dependency_matrix.summary.post_count'));
        $this->assertSame('o-que-e-o-atlas', data_get($payload, 'operations_packet.editorial_dependency_matrix.summary.current_unlocked_slug'));
        $this->assertSame(2, data_get($payload, 'operations_packet.editorial_dependency_matrix.summary.blocked_post_count'));
        $this->assertSame('current_unlocked', data_get($payload, 'operations_packet.editorial_dependency_matrix.rows.0.readiness'));
        $this->assertSame('blocked_missing_prerequisites', data_get($payload, 'operations_packet.editorial_dependency_matrix.rows.1.readiness'));
        $this->assertSame('o-que-e-o-atlas', data_get($payload, 'operations_packet.editorial_dependency_matrix.rows.1.depends_on.missing_prerequisites.0'));
        $this->assertGreaterThanOrEqual(1, count((array) data_get($payload, 'operations_packet.editorial_dependency_matrix.rows.0.reader_contract.must_introduce', [])));
        $this->assertFalse(data_get($payload, 'operations_packet.editorial_dependency_matrix.guardrails.reorders_posts'));
        $this->assertFalse(data_get($payload, 'operations_packet.editorial_dependency_matrix.guardrails.publishes_content'));
        $this->assertSame('atlas.blog_editorial_backlog_intake.v1', data_get($payload, 'operations_packet.backlog_intake.schema_version'));
        $this->assertSame('read_only_candidate_intake_p1', data_get($payload, 'operations_packet.backlog_intake.mode'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'operations_packet.backlog_intake.summary.item_count'));
        $this->assertTrue(data_get($payload, 'operations_packet.backlog_intake.summary.dependency_ladder_blocked'));
        $this->assertContains(
            data_get($payload, 'operations_packet.backlog_intake.items.0.recommended_action'),
            ['accept_into_review_queue', 'hold_until_dependency_ladder_clears', 'review_for_append_only_promotion', 'hold_duplicate'],
        );
        $this->assertFalse(data_get($payload, 'operations_packet.backlog_intake.guardrails.writes_backlog'));
        $this->assertFalse(data_get($payload, 'operations_packet.backlog_intake.guardrails.writes_review_queue'));
        $this->assertFalse(data_get($payload, 'operations_packet.backlog_intake.guardrails.promotes_candidate'));
        $this->assertSame('atlas.blog_editorial_signal_mesh.v1', data_get($payload, 'operations_packet.atlas_signal_mesh.schema_version'));
        $this->assertSame('read_only_atlas_signal_mesh_p1', data_get($payload, 'operations_packet.atlas_signal_mesh.mode'));
        $this->assertGreaterThanOrEqual(8, data_get($payload, 'operations_packet.atlas_signal_mesh.summary.source_count'));
        $this->assertSame('future_governed', data_get($payload, 'operations_packet.atlas_signal_mesh.summary.graph_posture'));
        $this->assertSame('write_foundation_or_current_unlocked_post_before_deep_candidates', data_get($payload, 'operations_packet.atlas_signal_mesh.summary.next_safe_action'));
        $this->assertSame('primary_public_sequence', data_get($payload, 'operations_packet.atlas_signal_mesh.sources.0.authority'));
        $this->assertFalse(data_get($payload, 'operations_packet.atlas_signal_mesh.guardrails.writes_backlog'));
        $this->assertFalse(data_get($payload, 'operations_packet.atlas_signal_mesh.guardrails.publishes_content'));
        $this->assertFalse(data_get($payload, 'operations_packet.atlas_signal_mesh.guardrails.uses_graph_rag'));
        $this->assertSame('atlas.blog_editorial_public_knowledge_map.v1', data_get($payload, 'operations_packet.public_knowledge_map.schema_version'));
        $this->assertSame('read_only_public_reader_memory_p1', data_get($payload, 'operations_packet.public_knowledge_map.mode'));
        $this->assertSame(0, data_get($payload, 'operations_packet.public_knowledge_map.summary.public_archive_posts'));
        $this->assertSame(0, data_get($payload, 'operations_packet.public_knowledge_map.summary.assumable_topic_count'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'operations_packet.public_knowledge_map.summary.not_yet_assumable_topic_count'));
        $this->assertSame('o-que-e-o-atlas', data_get($payload, 'operations_packet.public_knowledge_map.summary.current_unlocked_slug'));
        $this->assertContains('building-atlas', data_get($payload, 'operations_packet.public_knowledge_map.reader_contract.must_not_assume_yet'));
        $this->assertFalse(data_get($payload, 'operations_packet.public_knowledge_map.guardrails.writes_backlog'));
        $this->assertFalse(data_get($payload, 'operations_packet.public_knowledge_map.guardrails.publishes_content'));
        $this->assertFalse(data_get($payload, 'operations_packet.public_knowledge_map.guardrails.uses_graph_rag'));
        $this->assertSame('atlas.blog_editorial_agent_operating_queue.v1', data_get($payload, 'operations_packet.agent_operating_queue.schema_version'));
        $this->assertSame('read_only_agent_blog_queue_p1', data_get($payload, 'operations_packet.agent_operating_queue.mode'));
        $this->assertSame('o-que-e-o-atlas', data_get($payload, 'operations_packet.agent_operating_queue.summary.current_unlocked_slug'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'operations_packet.agent_operating_queue.summary.write_now_count'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'operations_packet.agent_operating_queue.summary.hold_count'));
        $this->assertSame('write_now', data_get($payload, 'operations_packet.agent_operating_queue.items.0.lane'));
        $this->assertSame('o-que-e-o-atlas', data_get($payload, 'operations_packet.agent_operating_queue.items.0.slug'));
        $this->assertFalse(data_get($payload, 'operations_packet.agent_operating_queue.items.0.can_write_draft'));
        $this->assertFalse(data_get($payload, 'operations_packet.agent_operating_queue.items.0.can_publish'));
        $this->assertFalse(data_get($payload, 'operations_packet.agent_operating_queue.guardrails.writes_backlog'));
        $this->assertFalse(data_get($payload, 'operations_packet.agent_operating_queue.guardrails.publishes_content'));
        $this->assertFalse(data_get($payload, 'operations_packet.agent_operating_queue.guardrails.uses_graph_rag'));
        $this->assertSame('atlas.blog_editorial_agent_handoff_packet.v1', data_get($payload, 'operations_packet.agent_handoff_packet.schema_version'));
        $this->assertSame('read_only_agent_execution_brief_p1', data_get($payload, 'operations_packet.agent_handoff_packet.mode'));
        $this->assertSame('o-que-e-o-atlas', data_get($payload, 'operations_packet.agent_handoff_packet.mission.current_slug'));
        $this->assertSame('blog_editorial_operator', data_get($payload, 'operations_packet.agent_handoff_packet.mission.agent_role'));
        $this->assertSame('pt-BR', data_get($payload, 'operations_packet.agent_handoff_packet.mission.language'));
        $this->assertSame('operations_packet.agent_operating_queue', data_get($payload, 'operations_packet.agent_handoff_packet.read_before_work.0.ref'));
        $this->assertContains('building-atlas', data_get($payload, 'operations_packet.agent_handoff_packet.current_reader_contract.must_not_assume_yet'));
        $this->assertSame('atlas.blog_editorial_writing_packet.v1', data_get($payload, 'operations_packet.agent_handoff_packet.evidence_bundle.writing_packet_schema'));
        $this->assertSame('future_governed', data_get($payload, 'operations_packet.agent_handoff_packet.evidence_bundle.source_posture.graph_posture'));
        $this->assertStringContainsString('atlas:open-brain:context', data_get($payload, 'operations_packet.agent_handoff_packet.evidence_bundle.open_brain_command'));
        $this->assertFalse(data_get($payload, 'operations_packet.agent_handoff_packet.guardrails.writes_draft'));
        $this->assertFalse(data_get($payload, 'operations_packet.agent_handoff_packet.guardrails.publishes_content'));
        $this->assertFalse(data_get($payload, 'operations_packet.agent_handoff_packet.guardrails.uses_graph_rag'));
        $this->assertSame('future_governed', data_get($payload, 'operations_packet.source_snapshot.graph_retrieval_status'));
        $this->assertSame('atlas.blog_editorial_open_brain_handoff.v1', data_get($payload, 'operations_packet.open_brain_handoff.schema_version'));
        $this->assertSame('o-que-e-o-atlas', data_get($payload, 'operations_packet.open_brain_handoff.payload.post.slug'));
        $this->assertSame('blog_editorial_context_export', data_get($payload, 'operations_packet.open_brain_handoff.payload.source_policy.intent', 'blog_editorial_context_export'));
        $this->assertStringContainsString('atlas:open-brain:context', data_get($payload, 'operations_packet.open_brain_handoff.command'));
        $this->assertFalse(data_get($payload, 'operations_packet.open_brain_handoff.invoked_by_this_command'));
        $this->assertFalse(data_get($payload, 'operations_packet.open_brain_handoff.guardrails.uses_graph_rag'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'operations_packet.candidate_feed.candidate_count'));
        $this->assertFalse(data_get($payload, 'operations_packet.guardrails.writes_backlog'));
        $this->assertFalse(data_get($payload, 'operations_packet.guardrails.publishes_content'));
        $this->assertTrue(data_get($payload, 'operations_packet.guardrails.generates_publishing_plan'));
        $this->assertTrue(data_get($payload, 'operations_packet.guardrails.generates_topic_ledger'));
        $this->assertTrue(data_get($payload, 'operations_packet.guardrails.generates_editorial_roadmap'));
        $this->assertTrue(data_get($payload, 'operations_packet.guardrails.generates_editorial_dependency_matrix'));
        $this->assertTrue(data_get($payload, 'operations_packet.guardrails.generates_backlog_intake'));
        $this->assertTrue(data_get($payload, 'operations_packet.guardrails.generates_atlas_signal_mesh'));
        $this->assertTrue(data_get($payload, 'operations_packet.guardrails.generates_public_knowledge_map'));
        $this->assertTrue(data_get($payload, 'operations_packet.guardrails.generates_agent_operating_queue'));
        $this->assertTrue(data_get($payload, 'operations_packet.guardrails.generates_agent_handoff_packet'));
        $this->assertFalse(data_get($payload, 'operations_packet.guardrails.uses_graph_rag'));
    }

    public function test_writing_packet_reports_unknown_slug_without_writing(): void
    {
        $this->writeBacklog();
        $this->writePublishedPosts([]);

        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--writing-packet' => true,
            '--writing-slug' => 'nao-existe',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('failed', data_get($payload, 'writing_packet.status'));
        $this->assertSame('writing_slug_not_found', data_get($payload, 'writing_packet.error'));
        $this->assertFalse(data_get($payload, 'writing_packet.guardrails.writes_draft'));
    }

    public function test_accept_candidate_dry_run_does_not_write_review_queue(): void
    {
        $this->bootContextReadModels();
        $this->seedContextReadModels();
        $this->writeBacklog();
        $this->writePublishedPosts([]);

        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--accept-candidate' => 'memory-ledger-contract',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('dry_run_review_queue_p1', data_get($payload, 'candidate_acceptance.mode'));
        $this->assertFalse(data_get($payload, 'candidate_acceptance.write'));
        $this->assertFalse(File::exists($this->siteRoot.'/content/backlog/blog-candidates.yaml'));
        $this->assertStringContainsString('slug: "memory-ledger-contract"', data_get($payload, 'candidate_acceptance.yaml_snippet'));
    }

    public function test_accept_candidate_with_write_creates_review_queue(): void
    {
        $this->bootContextReadModels();
        $this->seedContextReadModels();
        $this->writeBacklog();
        $this->writePublishedPosts([]);

        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--accept-candidate' => 'memory-ledger-contract',
            '--write' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('explicit_write_review_queue_p1', data_get($payload, 'candidate_acceptance.mode'));
        $this->assertFalse(data_get($payload, 'candidate_acceptance.guardrails.writes_main_backlog'));
        $this->assertTrue(File::exists($this->siteRoot.'/content/backlog/blog-candidates.yaml'));
        $this->assertStringContainsString('slug: "memory-ledger-contract"', File::get($this->siteRoot.'/content/backlog/blog-candidates.yaml'));
    }

    public function test_promote_candidate_dry_run_does_not_write_main_backlog(): void
    {
        $this->bootContextReadModels();
        $this->seedContextReadModels();
        $this->writeBacklog();
        $this->writePublishedPosts([]);
        $this->acceptCandidateIntoReviewQueue();
        $before = File::get($this->siteRoot.'/content/backlog/blog-first-month.yaml');

        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--promote-candidate' => 'memory-ledger-contract',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('dry_run_main_backlog_p1', data_get($payload, 'candidate_promotion.mode'));
        $this->assertFalse(data_get($payload, 'candidate_promotion.write'));
        $this->assertFalse(data_get($payload, 'candidate_promotion.guardrails.writes_main_backlog'));
        $this->assertSame($before, File::get($this->siteRoot.'/content/backlog/blog-first-month.yaml'));
        $this->assertStringContainsString('slug: "memory-ledger-contract"', data_get($payload, 'candidate_promotion.yaml_snippet'));
        $this->assertSame(4, data_get($payload, 'candidate_promotion.new_order'));
    }

    public function test_promote_candidate_with_write_appends_to_main_backlog(): void
    {
        $this->bootContextReadModels();
        $this->seedContextReadModels();
        $this->writeBacklog();
        $this->writePublishedPosts([]);
        $this->acceptCandidateIntoReviewQueue();

        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--promote-candidate' => 'memory-ledger-contract',
            '--write' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('explicit_write_main_backlog_p1', data_get($payload, 'candidate_promotion.mode'));
        $this->assertTrue(data_get($payload, 'candidate_promotion.guardrails.writes_main_backlog'));
        $backlog = File::get($this->siteRoot.'/content/backlog/blog-first-month.yaml');
        $this->assertStringContainsString('theme: "Fila revisada"', $backlog);
        $this->assertStringContainsString('slug: "memory-ledger-contract"', $backlog);

        $payload = $this->runPlanner();
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(4, data_get($payload, 'summary.planned_posts'));
    }

    /**
     * @param  array<int,array<string,mixed>>|null  $posts
     */
    private function writeBacklog(?array $posts = null): void
    {
        $posts ??= [
            [
                'order' => 1,
                'title' => 'O que e o Atlas',
                'slug' => 'o-que-e-o-atlas',
                'prerequisites' => [],
            ],
            [
                'order' => 2,
                'title' => 'Por que estou construindo o Atlas',
                'slug' => 'por-que-estou-construindo-o-atlas',
                'prerequisites' => ['o-que-e-o-atlas'],
            ],
            [
                'order' => 3,
                'title' => 'O problema dos assistentes de IA hoje',
                'slug' => 'o-problema-dos-assistentes-de-ia-hoje',
                'prerequisites' => ['por-que-estou-construindo-o-atlas'],
            ],
        ];

        $encodePost = function (array $post): string {
                $prerequisites = collect((array) ($post['prerequisites'] ?? []))
                    ->map(fn (string $slug): string => "          - \"{$slug}\"")
                    ->implode("\n");

                $prerequisiteBlock = $prerequisites === ''
                    ? '        prerequisites: []'
                    : "        prerequisites:\n{$prerequisites}";

                $complexity = (string) ($post['complexity_level'] ?? 'L0');
                $collection = (string) ($post['collection'] ?? 'atlas');
                $series = (string) ($post['series'] ?? 'building-atlas');
                $topics = collect((array) ($post['topics'] ?? ['atlas']))
                    ->filter(fn (mixed $topic): bool => is_string($topic))
                    ->values()
                    ->map(fn (string $topic): string => "          - \"{$topic}\"")
                    ->implode("\n");

                return <<<YAML
      - order: {$post['order']}
        title: "{$post['title']}"
        slug: "{$post['slug']}"
        type: "essay"
        complexity_level: "{$complexity}"
        collection: "{$collection}"
        series: "{$series}"
        reader_level: "beginner"
        goal: "Test goal"
        main_question: "Test question?"
{$prerequisiteBlock}
        next_reading: []
        topics:
{$topics}
YAML;
        };

        $encodedWeeks = collect($posts)
            ->groupBy(fn (array $post): int => (int) ($post['week'] ?? max(1, (int) ceil(((int) ($post['order'] ?? 1)) / 5))))
            ->map(function ($weekPosts, int $week) use ($encodePost): string {
                $theme = (string) data_get($weekPosts->first(), 'week_theme', 'Orientacao');
                $encodedPosts = $weekPosts
                    ->sortBy(fn (array $post): int => (int) ($post['order'] ?? 0))
                    ->map(fn (array $post): string => $encodePost($post))
                    ->implode("\n");

                return <<<YAML
  - week: {$week}
    theme: "{$theme}"
    goal: "Test"
    posts:
{$encodedPosts}
YAML;
            })
            ->implode("\n");

        File::put($this->siteRoot.'/content/backlog/blog-first-month.yaml', <<<YAML
name: "Primeiro mes do blog"
cadence: "5 posts por semana"
status: "planned"
purpose: "Test"
publishing_days:
  - monday
buffer_days:
  - saturday
rule: "A ordem importa mais que a data."
weeks:
{$encodedWeeks}
YAML);
    }

    private function writeFirstMonthFoundationBacklog(): void
    {
        $slugs = [
            ['O que e o Atlas', 'o-que-e-o-atlas', 'L0', 'atlas', 'building-atlas'],
            ['Por que estou construindo o Atlas', 'por-que-estou-construindo-o-atlas', 'L0', 'atlas', 'building-atlas'],
            ['O problema dos assistentes de IA hoje', 'o-problema-dos-assistentes-de-ia-hoje', 'L1', 'ia-pessoal', 'building-atlas'],
            ['A diferenca entre chatbot e sistema pessoal', 'a-diferenca-entre-chatbot-e-sistema-pessoal', 'L1', 'ia-pessoal', 'building-atlas'],
            ['O que o Atlas nao e', 'o-que-o-atlas-nao-e', 'L0', 'atlas', 'building-atlas'],
            ['Por que IA pessoal precisa conhecer contexto', 'por-que-ia-pessoal-precisa-conhecer-contexto', 'L1', 'ia-pessoal', 'building-atlas'],
            ['O que significa local-first', 'o-que-significa-local-first', 'L1', 'atlas', 'building-atlas'],
            ['Por que privacidade muda tudo', 'por-que-privacidade-muda-tudo', 'L1', 'ia-pessoal', 'building-atlas'],
            ['Por que controle importa mais que conveniencia', 'por-que-controle-importa-mais-que-conveniencia', 'L1', 'ia-pessoal', 'building-atlas'],
            ['O vocabulario do Atlas', 'o-vocabulario-do-atlas', 'L0', 'atlas', 'building-atlas'],
            ['O problema da memoria em IA', 'o-problema-da-memoria-em-ia', 'L1', 'ia-pessoal', 'agent-memory'],
            ['Por que lembrar tudo e ruim', 'por-que-lembrar-tudo-e-ruim', 'L2', 'ia-pessoal', 'agent-memory'],
            ['A diferenca entre conversa memoria e conhecimento', 'a-diferenca-entre-conversa-memoria-e-conhecimento', 'L2', 'ia-pessoal', 'agent-memory'],
            ['Memoria como problema de banco de dados', 'memoria-como-problema-de-banco-de-dados', 'L3', 'ia-pessoal', 'agent-memory'],
            ['Memoria como ledger', 'memoria-como-ledger', 'L3', 'ia-pessoal', 'agent-memory'],
            ['O que sao agentes no Atlas', 'o-que-sao-agentes-no-atlas', 'L1', 'atlas', 'agent-governance'],
            ['Por que agentes precisam de limites', 'por-que-agentes-precisam-de-limites', 'L2', 'atlas', 'agent-governance'],
            ['O que e um mandato de agente', 'o-que-e-um-mandato-de-agente', 'L3', 'atlas', 'agent-governance'],
            ['Por que dry-run e sandbox importam', 'por-que-dry-run-e-sandbox-importam', 'L3', 'atlas', 'agent-governance'],
            ['Como o Atlas esta evoluindo', 'como-o-atlas-esta-evoluindo', 'L1', 'atlas', 'building-atlas'],
        ];

        $posts = [];
        foreach ($slugs as $index => [$title, $slug, $complexity, $collection, $series]) {
            $posts[] = [
                'order' => $index + 1,
                'title' => $title,
                'slug' => $slug,
                'complexity_level' => $complexity,
                'collection' => $collection,
                'series' => $series,
                'prerequisites' => $index === 0 ? [] : [$slugs[$index - 1][1]],
            ];
        }

        $this->writeBacklog($posts);
    }

    /**
     * @param  array<int,string>  $slugs
     */
    private function writePublishedPosts(array $slugs): void
    {
        $posts = collect($slugs)
            ->map(fn (string $slug): string => "  { slug: \"{$slug}\", title: { pt: \"{$slug}\" }, date: \"2026-06-01\" },")
            ->implode("\n");

        File::put($this->siteRoot.'/src/data/site.js', <<<JS
export const collections = [];
export const posts = [
{$posts}
];
JS);
    }

    /**
     * @return array<string,mixed>
     */
    private function runPlanner(): array
    {
        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function acceptCandidateIntoReviewQueue(): void
    {
        $exit = Artisan::call('atlas:blog:editorial-plan', [
            '--site' => $this->siteRoot,
            '--accept-candidate' => 'memory-ledger-contract',
            '--write' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertTrue(File::exists($this->siteRoot.'/content/backlog/blog-candidates.yaml'));
    }

    private function bootWorldModelSchemaForEditorialGraph(): void
    {
        if (! Schema::hasTable('ai_codebase_world_models')) {
            (require database_path('migrations/2026_05_17_220000_create_ai_autonomous_engineering_os_tables.php'))->up();
        }
    }

    private function seedEditorialBlogWorldModel(): string
    {
        $modelId = 'blog_editorial_world_model_test';
        AiCodebaseWorldModel::query()->where('model_id', $modelId)->delete();

        $model = AiCodebaseWorldModel::query()->create([
            'model_id' => $modelId,
            'scope' => 'atlas-server',
            'status' => 'built',
            'capabilities' => ['blog_editorial_planning', 'content_intelligence', 'atlas'],
            'risks' => ['skip_reader_foundation'],
            'receipt' => ['schema_version' => 'atlas.ai.autonomous_engineering.codebase_world_model.v1'],
            'model_hash' => hash('sha256', $modelId),
        ]);

        $this->worldModelNode($model->id, 'service:blog-editorial-context', 'service', 'app/Services/Ai/Publishing/BlogEditorialContextService.php', 'blog_editorial_planning', ['blog_editorial_planning', 'content_intelligence']);
        $this->worldModelNode($model->id, 'command:blog-editorial-plan', 'command', 'app/Console/Commands/AtlasBlogEditorialPlanCommand.php', 'blog_editorial_planning', ['blog_editorial_planning']);
        $this->worldModelNode($model->id, 'doc:blog-editorial-system', 'doc', 'docs/engineering-knowledge-base/atlas-blog-editorial-planning-system.md', 'blog_editorial_planning', ['content_intelligence', 'atlas']);
        $this->worldModelEdge($model->id, 'doc:blog-editorial-system', 'service:blog-editorial-context', 'documents');
        $this->worldModelEdge($model->id, 'command:blog-editorial-plan', 'service:blog-editorial-context', 'invokes');

        return $modelId;
    }

    /**
     * @param  array<int,string>  $capabilities
     */
    private function worldModelNode(string $worldModelUuid, string $nodeId, string $nodeType, string $path, string $flowId, array $capabilities): void
    {
        AiCodebaseWorldModelNode::query()->create([
            'world_model_id' => $worldModelUuid,
            'node_id' => $nodeId,
            'node_type' => $nodeType,
            'path' => $path,
            'flow_id' => $flowId,
            'capabilities' => $capabilities,
            'risks' => [],
            'metadata' => ['seeded_by' => 'AtlasBlogEditorialPlanCommandTest'],
        ]);
    }

    private function worldModelEdge(string $worldModelUuid, string $from, string $to, string $type): void
    {
        AiCodebaseWorldModelEdge::query()->create([
            'world_model_id' => $worldModelUuid,
            'from_node_id' => $from,
            'to_node_id' => $to,
            'edge_type' => $type,
            'metadata' => ['seeded_by' => 'AtlasBlogEditorialPlanCommandTest'],
        ]);
    }

    private function bootContextReadModels(): void
    {
        if (! Schema::hasTable('atlas_engineering_knowledge_items')) {
            Schema::create('atlas_engineering_knowledge_items', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('slug', 160)->unique();
                $table->string('title', 220);
                $table->string('category', 80)->index();
                $table->string('status', 32)->default('active')->index();
                $table->unsignedSmallInteger('priority')->default(50)->index();
                $table->string('source_type', 80)->default('canonical_doc')->index();
                $table->text('canonical_path');
                $table->string('source_hash', 64)->index();
                $table->string('content_hash', 64)->index();
                $table->text('summary')->nullable();
                $table->text('body_excerpt')->nullable();
                $table->json('tags_json')->default('[]');
                $table->json('related_paths_json')->default('[]');
                $table->json('capabilities_json')->default('[]');
                $table->json('decisions_json')->default('[]');
                $table->json('maintenance_json')->default('[]');
                $table->json('metadata')->default('{}');
                $table->timestamp('indexed_at')->nullable()->index();
                $table->timestamp('last_verified_at')->nullable();
                $table->timestamp('archived_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_engineering_code_modules')) {
            Schema::create('atlas_engineering_code_modules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('workspace_id', 160)->default('atlas-server')->index();
                $table->string('slug', 160)->unique();
                $table->string('name', 220);
                $table->string('layer', 80)->index();
                $table->string('root_path', 500)->nullable()->index();
                $table->string('primary_language', 40)->nullable()->index();
                $table->string('status', 32)->default('active')->index();
                $table->string('owner', 120)->nullable();
                $table->text('description')->nullable();
                $table->string('docs_status', 40)->default('undocumented')->index();
                $table->unsignedInteger('file_count')->default(0);
                $table->unsignedInteger('symbol_count')->default(0);
                $table->unsignedInteger('route_count')->default(0);
                $table->unsignedInteger('command_count')->default(0);
                $table->unsignedInteger('migration_count')->default(0);
                $table->unsignedInteger('test_count')->default(0);
                $table->string('source_hash', 64)->index();
                $table->string('docs_hash', 64)->nullable()->index();
                $table->json('tags_json')->default('[]');
                $table->json('related_docs_json')->default('[]');
                $table->json('related_tests_json')->default('[]');
                $table->json('metadata')->default('{}');
                $table->timestamp('indexed_at')->nullable()->index();
                $table->timestamp('archived_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_engineering_code_symbols')) {
            Schema::create('atlas_engineering_code_symbols', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('workspace_id', 160)->default('atlas-server')->index();
                $table->uuid('module_id')->nullable()->index();
                $table->string('symbol_type', 60)->index();
                $table->string('symbol_name', 300)->index();
                $table->string('file_path', 500)->index();
                $table->unsignedInteger('line_start')->nullable();
                $table->unsignedInteger('line_end')->nullable();
                $table->string('language', 40)->nullable()->index();
                $table->text('signature')->nullable();
                $table->string('namespace', 220)->nullable();
                $table->string('parent_symbol', 300)->nullable()->index();
                $table->string('visibility', 40)->nullable();
                $table->string('status', 32)->default('active')->index();
                $table->string('docs_status', 40)->default('undocumented')->index();
                $table->string('source_hash', 64)->index();
                $table->json('related_doc_ids_json')->default('[]');
                $table->json('metadata')->default('{}');
                $table->timestamp('indexed_at')->nullable()->index();
                $table->timestamp('archived_at')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    private function seedContextReadModels(): void
    {
        DB::table('atlas_engineering_knowledge_items')->where('slug', 'memory-ledger-contract-test')->delete();
        DB::table('atlas_engineering_code_symbols')->where('source_hash', hash('sha256', 'memory-ledger-symbol-test'))->delete();
        DB::table('atlas_engineering_code_modules')->where('slug', 'memory-ledger-test')->delete();

        DB::table('atlas_engineering_knowledge_items')->insert([
            'id' => (string) Str::uuid(),
            'slug' => 'memory-ledger-contract-test',
            'title' => 'Memory Ledger Contract',
            'category' => 'memory',
            'status' => 'active',
            'priority' => 100,
            'source_type' => 'canonical_doc',
            'canonical_path' => 'docs/engineering-knowledge-base/memory/contracts.md',
            'source_hash' => hash('sha256', 'memory-ledger-contract-test'),
            'content_hash' => hash('sha256', 'memory-ledger-contract-test-content'),
            'summary' => 'Ledger de memoria com proveniencia para agentes pessoais.',
            'body_excerpt' => 'Memoria como ledger append-only para Atlas.',
            'tags_json' => json_encode(['memoria', 'ledger']),
            'related_paths_json' => json_encode([]),
            'capabilities_json' => json_encode([]),
            'decisions_json' => json_encode([]),
            'maintenance_json' => json_encode([]),
            'metadata' => json_encode([]),
            'indexed_at' => now(),
            'last_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $moduleId = (string) Str::uuid();
        DB::table('atlas_engineering_code_modules')->insert([
            'id' => $moduleId,
            'workspace_id' => 'atlas-server',
            'slug' => 'memory-ledger-test',
            'name' => 'Memory Ledger',
            'layer' => 'ai',
            'root_path' => 'app/Services/Ai/Memory',
            'primary_language' => 'php',
            'status' => 'active',
            'description' => 'Servicos de memoria e ledger.',
            'docs_status' => 'documented',
            'file_count' => 1,
            'symbol_count' => 1,
            'route_count' => 0,
            'command_count' => 0,
            'migration_count' => 0,
            'test_count' => 0,
            'source_hash' => hash('sha256', 'memory-ledger-module-test'),
            'tags_json' => json_encode([]),
            'related_docs_json' => json_encode([]),
            'related_tests_json' => json_encode([]),
            'metadata' => json_encode([]),
            'indexed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('atlas_engineering_code_symbols')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => 'atlas-server',
            'module_id' => $moduleId,
            'symbol_type' => 'class',
            'symbol_name' => 'AtlasMemoryLedgerService',
            'file_path' => 'app/Services/Ai/Memory/AtlasMemoryLedgerService.php',
            'line_start' => 12,
            'line_end' => 80,
            'language' => 'php',
            'signature' => 'final class AtlasMemoryLedgerService',
            'status' => 'active',
            'docs_status' => 'documented',
            'source_hash' => hash('sha256', 'memory-ledger-symbol-test'),
            'related_doc_ids_json' => json_encode([]),
            'metadata' => json_encode([]),
            'indexed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
