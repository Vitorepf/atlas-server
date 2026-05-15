<?php

declare(strict_types=1);

namespace Tests\Feature\AtlasCode;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Meta 5 · Multi-Project Workspace UI contract.
 *
 * Atlas Desktop is multi-project: Atlas and Blackink are Projects, not Obras.
 * This test guards the contract that the workspace read-model exposes for the
 * Desktop:
 *   - canonical envelope (schema_version, data, meta.default_slug)
 *   - Atlas + Blackink seeded
 *   - Blackink production_status = production
 *   - surfaces_enabled present + reflects per-project policy
 *   - active-slug filter scopes Obras
 *   - empty default slug fallback honest
 */
class AtlasCodeMultiProjectWorkspaceUiTest extends TestCase
{
    private function headers(): array
    {
        return [
            'Accept' => 'application/json',
            'X-Atlas-Token' => 'testing-atlas-token-with-enough-length',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_projects')) {
            Schema::create('atlas_projects', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('title')->nullable();
                $t->text('description')->nullable();
                $t->string('status')->default('active');
                $t->string('domain')->default('atlas');
                $t->uuid('source_capture_id')->nullable();
                $t->text('goal')->nullable();
                $t->text('next_action')->nullable();
                $t->string('project_type')->nullable();
                $t->text('desired_outcome')->nullable();
                $t->text('minimum_viable_outcome')->nullable();
                $t->text('definition_of_done')->nullable();
                $t->text('why_now')->nullable();
                $t->timestamp('deadline_at')->nullable();
                $t->string('deadline_kind')->nullable();
                $t->string('priority')->default('medium');
                $t->string('energy_profile')->nullable();
                $t->text('avoidance_reason')->nullable();
                $t->uuid('active_next_task_id')->nullable();
                $t->uuid('current_step_id')->nullable();
                $t->timestamp('last_touched_at')->nullable();
                $t->timestamp('next_review_at')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->timestamp('paused_until')->nullable();
                $t->json('metadata')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
    }

    public function test_workspaces_endpoint_returns_canonical_envelope_with_default_slug(): void
    {
        $response = $this->withHeaders($this->headers())->getJson('/atlas-code/projects/workspaces');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'schema_version',
                'data' => [['id', 'slug', 'name', 'kind', 'workspace_path', 'production_status', 'surfaces_enabled', 'safety']],
                'meta' => ['total', 'default_slug'],
            ])
            ->assertJsonPath('schema_version', 'atlas.code.workspace_profile.v1')
            ->assertJsonPath('meta.default_slug', 'atlas');

        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertContains('atlas', $slugs);
        $this->assertContains('blackink', $slugs);
    }

    public function test_atlas_and_blackink_are_projects_not_obras(): void
    {
        $response = $this->withHeaders($this->headers())->getJson('/atlas-code/projects/workspaces');
        $atlas = collect($response->json('data'))->firstWhere('slug', 'atlas');
        $blackink = collect($response->json('data'))->firstWhere('slug', 'blackink');

        $this->assertSame('product', $atlas['kind']);
        $this->assertSame('product', $blackink['kind']);

        // Confirm the controller hides system certification Obras (which are
        // Obras, not Projects, and should never appear in the workspaces feed).
        $obrasResponse = $this->withHeaders($this->headers())->getJson('/atlas-code/works');
        $obrasResponse->assertOk()
            ->assertJsonPath('meta.system_certification_hidden', true);
    }

    public function test_blackink_is_production_with_limited_surfaces(): void
    {
        $response = $this->withHeaders($this->headers())->getJson('/atlas-code/projects/workspaces/blackink');

        $response
            ->assertOk()
            ->assertJsonPath('workspace.slug', 'blackink')
            ->assertJsonPath('workspace.production_status', 'production')
            ->assertJsonPath('workspace.docs_status', 'incomplete')
            ->assertJsonPath('workspace.default_risk', 'high');

        $surfaces = (array) $response->json('workspace.surfaces_enabled');
        $this->assertContains('atlas_ai', $surfaces);
        $this->assertContains('atencao', $surfaces);
        $this->assertContains('code', $surfaces);
        // Blackink does NOT have canonical docs → Cartografia is excluded
        // from the surfaces_enabled allowlist.
        $this->assertNotContains('cartografia', $surfaces);
    }

    public function test_atlas_has_full_surface_set(): void
    {
        $response = $this->withHeaders($this->headers())->getJson('/atlas-code/projects/workspaces/atlas');
        $response->assertOk();
        $surfaces = (array) $response->json('workspace.surfaces_enabled');
        foreach (['atlas_ai', 'cartografia', 'code', 'atencao'] as $expected) {
            $this->assertContains($expected, $surfaces, "Atlas project should enable surface {$expected}");
        }
    }

    public function test_unknown_slug_returns_404_honestly(): void
    {
        $response = $this->withHeaders($this->headers())->getJson('/atlas-code/projects/workspaces/no-such-project');
        $response->assertNotFound()->assertJsonPath('error', 'project_workspace_not_found');
    }

    public function test_obra_creation_binds_to_active_workspace_slug(): void
    {
        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/works', [
            'intent' => 'meta 5 scoping smoke',
            'objective' => 'verify workspace metadata binding',
            'workspace_slug' => 'blackink',
        ]);
        $response
            ->assertCreated()
            ->assertJsonPath('work.workspace_slug', 'blackink')
            ->assertJsonPath('work.metadata.workspace_slug', 'blackink');
    }

    public function test_works_index_can_filter_by_active_workspace_slug(): void
    {
        // Seed two Obras — one under atlas, one under blackink.
        $atlasId = (string) Str::uuid();
        $blackId = (string) Str::uuid();
        $now = now();
        DB::table('atlas_projects')->insert([
            [
                'id' => $atlasId,
                'title' => 'Obra Atlas',
                'description' => 'i',
                'status' => 'active',
                'domain' => 'atlas',
                'goal' => 'a',
                'priority' => 'medium',
                'metadata' => json_encode(['origin' => 'atlas-code', 'workspace_slug' => 'atlas']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => $blackId,
                'title' => 'Obra Blackink',
                'description' => 'i',
                'status' => 'active',
                'domain' => 'atlas',
                'goal' => 'b',
                'priority' => 'medium',
                'metadata' => json_encode(['origin' => 'atlas-code', 'workspace_slug' => 'blackink']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $atlasOnly = $this->withHeaders($this->headers())->getJson('/atlas-code/works?workspace=atlas');
        $atlasOnly->assertOk()->assertJsonPath('meta.workspace_filter', 'atlas');
        $slugs = collect($atlasOnly->json('data'))->pluck('workspace_slug')->unique()->values()->all();
        $this->assertSame(['atlas'], $slugs);

        $blackOnly = $this->withHeaders($this->headers())->getJson('/atlas-code/works?workspace=blackink');
        $blackOnly->assertOk()->assertJsonPath('meta.workspace_filter', 'blackink');
        $blackSlugs = collect($blackOnly->json('data'))->pluck('workspace_slug')->unique()->values()->all();
        $this->assertSame(['blackink'], $blackSlugs);
    }
}
