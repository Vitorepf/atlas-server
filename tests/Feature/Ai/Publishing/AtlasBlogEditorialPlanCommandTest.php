<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Publishing;

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
        $this->assertSame('pt-BR', data_get($payload, 'writing_packet.writing_brief.language'));
        $this->assertFalse(data_get($payload, 'writing_packet.guardrails.writes_draft'));
        $this->assertFalse(data_get($payload, 'writing_packet.guardrails.publishes_content'));
        $this->assertFalse(data_get($payload, 'writing_packet.guardrails.generates_full_article'));
        $this->assertSame('atlas.blog_editorial_open_brain_handoff.v1', data_get($payload, 'writing_packet.open_brain_handoff.schema_version'));
        $this->assertSame('o-que-e-o-atlas', data_get($payload, 'writing_packet.open_brain_handoff.payload.post.slug'));
        $this->assertFalse(data_get($payload, 'writing_packet.open_brain_handoff.guardrails.uses_graph_rag'));
        $this->assertContains('Nao expor paths locais, tokens, prompts, traces ou detalhes privados.', data_get($payload, 'writing_packet.writing_brief.must_not_include'));
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

        $encodedPosts = collect($posts)
            ->map(function (array $post): string {
                $prerequisites = collect((array) ($post['prerequisites'] ?? []))
                    ->map(fn (string $slug): string => "          - \"{$slug}\"")
                    ->implode("\n");

                $prerequisiteBlock = $prerequisites === ''
                    ? '        prerequisites: []'
                    : "        prerequisites:\n{$prerequisites}";

                $complexity = (string) ($post['complexity_level'] ?? 'L0');
                $collection = (string) ($post['collection'] ?? 'atlas');
                $series = (string) ($post['series'] ?? 'building-atlas');

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
          - "atlas"
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
  - week: 1
    theme: "Orientacao"
    goal: "Test"
    posts:
{$encodedPosts}
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
