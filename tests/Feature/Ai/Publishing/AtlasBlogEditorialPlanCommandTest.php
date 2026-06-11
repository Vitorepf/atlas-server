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

                return <<<YAML
      - order: {$post['order']}
        title: "{$post['title']}"
        slug: "{$post['slug']}"
        type: "essay"
        complexity_level: "L0"
        collection: "atlas"
        series: "building-atlas"
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
