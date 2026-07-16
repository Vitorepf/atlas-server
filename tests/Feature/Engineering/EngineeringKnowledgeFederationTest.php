<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Models\AtlasEngineeringKnowledgeItem;
use App\Services\Engineering\EngineeringKnowledgeBaseService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ADN F2+F3 — a rede documentacional é FEDERADA por workspace, nunca um corpus
 * concatenado (doc canônico: docs/engineering-knowledge-base/atlas-documentation-network.md).
 *
 * Contratos pinados aqui:
 *  1. O sync ingere o canto docs/engineering-knowledge-base de CADA perfil
 *     registrado (config atlas_projects.profiles + docs_roots), carimbando
 *     workspace_id — repo sem perfil NÃO entra (rede é opt-in explícito).
 *  2. O mesmo slug pode existir em N workspaces sem colisão (unique composto
 *     workspace_id+slug), e cada canonical_path é relativo ao repo_root do
 *     próprio repo.
 *  3. --prune é escopado por workspace sincronizado: slug sumido arquiva SÓ no
 *     workspace dono; o corpus dos demais workspaces fica intacto.
 *  4. O catálogo filtra por workspace.
 */
class EngineeringKnowledgeFederationTest extends TestCase
{
    private string $repoA;

    private string $repoB;

    private string $repoC;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTable();

        $base = sys_get_temp_dir().'/adn-federation-'.uniqid();
        $this->repoA = $base.'/repo-a';
        $this->repoB = $base.'/repo-b';
        $this->repoC = $base.'/repo-c';

        foreach ([$this->repoA, $this->repoB, $this->repoC] as $repo) {
            File::makeDirectory($repo.'/docs/engineering-knowledge-base', 0755, true);
        }

        $this->writeDoc($this->repoA, 'overview', 'Visão do repo A');
        $this->writeDoc($this->repoA, 'repo-a-only', 'Doc exclusivo do A');
        $this->writeDoc($this->repoB, 'overview', 'Visão do repo B');
        $this->writeDoc($this->repoC, 'overview', 'Repo C não tem perfil — não pode entrar');

        config()->set('atlas_projects.profiles', [
            $this->profile('repo-a', $this->repoA),
            $this->profile('repo-b', $this->repoB),
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_engineering_knowledge_items');
        File::deleteDirectory(dirname($this->repoA));

        parent::tearDown();
    }

    public function test_sync_federates_docs_by_workspace_without_slug_collision(): void
    {
        $payload = app(EngineeringKnowledgeBaseService::class)->sync();

        $this->assertTrue($payload['ok']);

        $overviews = AtlasEngineeringKnowledgeItem::query()->where('slug', 'overview')->get();
        $this->assertSame(
            ['repo-a', 'repo-b'],
            $overviews->pluck('workspace_id')->sort()->values()->all(),
            'o mesmo slug deve existir uma vez POR workspace, sem colisão',
        );

        $a = $overviews->firstWhere('workspace_id', 'repo-a');
        $this->assertSame('docs/engineering-knowledge-base/overview.md', $a->canonical_path);
        $this->assertStringContainsString('repo A', (string) $a->summary);

        $this->assertDatabaseHas('atlas_engineering_knowledge_items', [
            'slug' => 'repo-a-only',
            'workspace_id' => 'repo-a',
        ]);
    }

    public function test_repo_without_profile_is_not_ingested(): void
    {
        app(EngineeringKnowledgeBaseService::class)->sync();

        $this->assertSame(
            0,
            AtlasEngineeringKnowledgeItem::query()
                ->where('summary', 'like', '%Repo C%')
                ->count(),
            'repo sem perfil registrado não entra na rede (opt-in explícito)',
        );
    }

    public function test_prune_is_scoped_to_the_synced_workspace(): void
    {
        $service = app(EngineeringKnowledgeBaseService::class);
        $service->sync();

        File::delete($this->repoA.'/docs/engineering-knowledge-base/repo-a-only.md');
        $payload = $service->sync(['prune' => true]);

        $this->assertTrue($payload['ok']);
        $this->assertSame('archived', AtlasEngineeringKnowledgeItem::query()
            ->where('workspace_id', 'repo-a')->where('slug', 'repo-a-only')->value('status'));
        $this->assertSame('active', AtlasEngineeringKnowledgeItem::query()
            ->where('workspace_id', 'repo-b')->where('slug', 'overview')->value('status'),
            'prune de um workspace jamais arquiva o corpus de outro');
    }

    public function test_catalog_filters_by_workspace(): void
    {
        $service = app(EngineeringKnowledgeBaseService::class);
        $service->sync();

        $catalog = $service->catalog(['workspace' => 'repo-b'], 50);
        $workspaces = collect($catalog['items'])->pluck('workspace_id')->unique()->values()->all();

        $this->assertSame(['repo-b'], $workspaces);
    }

    public function test_legacy_fallback_when_no_profile_has_docs(): void
    {
        config()->set('atlas_projects.profiles', []);

        $payload = app(EngineeringKnowledgeBaseService::class)->sync();

        $this->assertTrue($payload['ok']);
        $this->assertGreaterThan(
            0,
            AtlasEngineeringKnowledgeItem::query()->where('workspace_id', 'atlas-server')->count(),
            'sem perfis com canto válido, o sync degrada para o corpus legado do atlas-server',
        );
    }

    private function profile(string $slug, string $repoRoot): array
    {
        return [
            'id' => $slug,
            'slug' => $slug,
            'name' => strtoupper($slug),
            'kind' => 'product',
            'workspace_path' => $repoRoot,
            'repo_root' => $repoRoot,
            'production_status' => 'development',
            'stack_summary' => 'repo sintético de teste',
            'commands' => [],
            'test_commands' => [],
            'build_commands' => [],
            'dev_server_command' => null,
            'critical_areas' => [],
            'docs_status' => 'incomplete',
            'default_risk' => 'low',
            'deployment_notes' => 'teste',
        ];
    }

    private function writeDoc(string $repo, string $slug, string $summary): void
    {
        File::put($repo."/docs/engineering-knowledge-base/{$slug}.md", <<<MD
---
id: {$slug}
type: engineering_knowledge
title: {$slug}
status: active
category: test
priority: 50
summary: {$summary}
tags: [test]
when_to_use:
  - teste de federação
---

# {$slug}

{$summary}
MD);
    }

    private function createTable(): void
    {
        Schema::dropIfExists('atlas_engineering_knowledge_items');
        Schema::create('atlas_engineering_knowledge_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace_id', 120)->default('atlas-server')->index();
            $table->string('slug', 160);
            $table->string('title', 240);
            $table->string('category', 80)->index();
            $table->string('status', 32)->default('active')->index();
            $table->unsignedSmallInteger('priority')->default(50)->index();
            $table->string('source_type', 80)->default('canonical_doc')->index();
            $table->string('canonical_path', 500);
            $table->string('source_hash', 64)->index();
            $table->string('content_hash', 64)->index();
            $table->text('summary')->nullable();
            $table->text('body_excerpt')->nullable();
            $table->jsonb('tags_json')->nullable();
            $table->jsonb('related_paths_json')->nullable();
            $table->jsonb('capabilities_json')->nullable();
            $table->jsonb('decisions_json')->nullable();
            $table->jsonb('maintenance_json')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('indexed_at')->nullable()->index();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('archived_at')->nullable()->index();
            $table->string('embedding_model', 120)->nullable();
            $table->string('embedded_content_hash', 64)->nullable();
            $table->timestamp('embedded_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'slug'], 'uq_atlas_eng_knowledge_ws_slug');
        });
    }
}
