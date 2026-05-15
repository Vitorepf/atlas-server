<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Feature test for the multi-project Workspace read-model exposed at
 *   GET /api/atlas-code/projects/workspaces
 *   GET /api/atlas-code/projects/workspaces/{slug}
 *
 * Canon: docs/engineering-knowledge-base/atlas-code-multi-project-workspace-os.md
 *
 * The test stays config-driven (no DB), so it survives schema changes and
 * does not block Atlas Code refactors unrelated to Workspaces.
 */
class AtlasCodeWorkspaceProfileTest extends TestCase
{
    private function headers(): array
    {
        return [
            'Accept' => 'application/json',
            'X-Atlas-Token' => 'testing-atlas-token-with-enough-length',
        ];
    }

    public function test_workspace_index_returns_atlas_and_blackink_profiles(): void
    {
        $response = $this->withHeaders($this->headers())->getJson('/atlas-code/projects/workspaces');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'schema_version',
                'data',
                'meta' => ['total', 'default_slug'],
            ])
            ->assertJsonPath('schema_version', 'atlas.code.workspace_profile.v1');

        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertContains('atlas', $slugs);
        $this->assertContains('blackink', $slugs);
    }

    public function test_workspace_index_marks_atlas_default_and_blackink_production(): void
    {
        $response = $this->withHeaders($this->headers())->getJson('/atlas-code/projects/workspaces');
        $response->assertOk();

        $this->assertSame('atlas', $response->json('meta.default_slug'));

        $profiles = collect($response->json('data'));
        $atlas = $profiles->firstWhere('slug', 'atlas');
        $blackink = $profiles->firstWhere('slug', 'blackink');

        $this->assertNotNull($atlas);
        $this->assertNotNull($blackink);

        $this->assertSame('production', $blackink['production_status']);
        $this->assertSame('incomplete', $blackink['docs_status']);
        $this->assertSame('high', $blackink['default_risk']);

        // Safety contract: Blackink without workspace_path resolves to no
        // execution allowed. Atlas resolves from base_path() and may exist
        // in the test environment; we only assert the canonical fields.
        $this->assertArrayHasKey('safety', $blackink);
        $this->assertArrayHasKey('execution_allowed', $blackink['safety']);
        $this->assertSame('high', $blackink['safety']['risk_floor']);
        $this->assertTrue($blackink['safety']['requires_explicit_intervention_review']);
    }

    public function test_workspace_show_returns_404_for_unknown_slug(): void
    {
        $response = $this->withHeaders($this->headers())->getJson('/atlas-code/projects/workspaces/unknown-project');
        $response->assertNotFound()->assertJsonPath('error', 'project_workspace_not_found');
    }

    public function test_works_store_binds_obra_to_workspace_slug_in_metadata(): void
    {
        // Schema bootstrap mirrors AtlasCodeContractTest minimally — only the
        // atlas_projects table is required for store/index.
        if (! \Illuminate\Support\Facades\Schema::hasTable('atlas_projects')) {
            \Illuminate\Support\Facades\Schema::create('atlas_projects', function (\Illuminate\Database\Schema\Blueprint $t) {
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

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/works', [
            'intent' => 'corrigir copy do checkout',
            'objective' => 'intervenção rápida em Blackink',
            'workspace_slug' => 'blackink',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('work.workspace_slug', 'blackink')
            ->assertJsonPath('work.workspace_name', 'Blackink')
            ->assertJsonPath('work.metadata.workspace_slug', 'blackink')
            ->assertJsonPath('work.metadata.workspace_name', 'Blackink')
            ->assertJsonPath('work.metadata.workspace_production_status', 'production');
    }

    public function test_works_index_filters_by_workspace_slug(): void
    {
        // Same minimal schema bootstrap.
        if (! \Illuminate\Support\Facades\Schema::hasTable('atlas_projects')) {
            \Illuminate\Support\Facades\Schema::create('atlas_projects', function (\Illuminate\Database\Schema\Blueprint $t) {
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

        // Two Obras: one in atlas, one in blackink.
        $atlasId = (string) \Illuminate\Support\Str::uuid();
        $blackinkId = (string) \Illuminate\Support\Str::uuid();
        \Illuminate\Support\Facades\DB::table('atlas_projects')->insert([
            [
                'id' => $atlasId,
                'title' => 'Obra Atlas A',
                'description' => 'intent atlas',
                'status' => 'active',
                'domain' => 'atlas',
                'goal' => 'do atlas thing',
                'priority' => 'medium',
                'metadata' => json_encode(['workspace_slug' => 'atlas']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $blackinkId,
                'title' => 'Obra Blackink B',
                'description' => 'intent blackink',
                'status' => 'active',
                'domain' => 'blackink',
                'goal' => 'do blackink thing',
                'priority' => 'medium',
                'metadata' => json_encode(['workspace_slug' => 'blackink']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $atlasResponse = $this->withHeaders($this->headers())->getJson('/atlas-code/works?workspace=atlas');
        $atlasResponse->assertOk()->assertJsonPath('meta.workspace_filter', 'atlas');
        $atlasSlugs = collect($atlasResponse->json('data'))->pluck('workspace_slug')->unique()->all();
        $this->assertNotContains('blackink', $atlasSlugs);

        $blackResponse = $this->withHeaders($this->headers())->getJson('/atlas-code/works?workspace=blackink');
        $blackResponse->assertOk()->assertJsonPath('meta.workspace_filter', 'blackink');
        $blackSlugs = collect($blackResponse->json('data'))->pluck('workspace_slug')->unique()->all();
        $this->assertSame(['blackink'], array_values($blackSlugs));
    }

    public function test_workspace_show_returns_canonical_shape_for_atlas(): void
    {
        $response = $this->withHeaders($this->headers())->getJson('/atlas-code/projects/workspaces/atlas');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'workspace' => [
                    'schema_version',
                    'id',
                    'slug',
                    'name',
                    'kind',
                    'workspace_path',
                    'workspace_path_exists',
                    'repo_root',
                    'production_status',
                    'stack_summary',
                    'commands',
                    'test_commands',
                    'build_commands',
                    'dev_server_command',
                    'critical_areas',
                    'docs_status',
                    'default_risk',
                    'deployment_notes',
                    'safety' => [
                        'execution_allowed',
                        'execution_blocked_reason',
                        'risk_floor',
                        'requires_explicit_intervention_review',
                    ],
                ],
            ])
            ->assertJsonPath('workspace.slug', 'atlas');
    }
}
