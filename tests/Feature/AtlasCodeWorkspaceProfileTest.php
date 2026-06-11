<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AtlasWorkspaceProfile;
use App\Services\Ai\AtlasAobgWorkspaceOnboardingService;
use App\Services\AtlasCode\AtlasCodeWorkspaceProfileService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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

    public function test_workspace_profile_can_resolve_by_real_path_for_runtime_gates(): void
    {
        $profile = app(AtlasCodeWorkspaceProfileService::class)->findByPath(base_path('..'));

        $this->assertIsArray($profile);
        $this->assertSame('atlas', $profile['slug']);
    }

    public function test_workspace_profile_reference_resolves_atlas_server_path_to_server_workspace(): void
    {
        $profile = app(AtlasCodeWorkspaceProfileService::class)->findByReference(base_path());
        $slugProfile = app(AtlasCodeWorkspaceProfileService::class)->findByReference('atlas-server');

        $this->assertIsArray($profile);
        $this->assertSame('atlas-server', $profile['slug']);
        $this->assertIsArray($slugProfile);
        $this->assertSame('atlas-server', $slugProfile['slug']);
    }

    public function test_workspace_registry_includes_persisted_profiles(): void
    {
        $this->createWorkspaceProfilesTable();

        AtlasWorkspaceProfile::query()->create([
            'slug' => 'client-x',
            'name' => 'Client X',
            'kind' => 'client_product',
            'workspace_path' => base_path(),
            'repo_root' => base_path(),
            'production_status' => 'development',
            'stack_summary' => 'Persisted Laravel workspace',
            'commands' => ['docs-health' => 'php artisan atlas:engineering:knowledge docs-health --json'],
            'test_commands' => ['php artisan test'],
            'build_commands' => [],
            'critical_areas' => ['routes', 'app/Services'],
            'docs_status' => 'complete',
            'default_risk' => 'medium',
            'deployment_notes' => 'Persisted overlay used by AWIS.',
            'surfaces_enabled' => ['atlas_ai', 'code', 'cartografia'],
            'source' => 'operator',
            'status' => 'active',
        ]);

        $response = $this->withHeaders($this->headers())->getJson('/atlas-code/projects/workspaces/client-x');

        $response
            ->assertOk()
            ->assertJsonPath('workspace.slug', 'client-x')
            ->assertJsonPath('workspace.name', 'Client X')
            ->assertJsonPath('workspace.stack_summary', 'Persisted Laravel workspace')
            ->assertJsonPath('workspace.workspace_path_exists', true);
    }

    public function test_workspace_registry_api_can_update_persisted_profile(): void
    {
        $this->createWorkspaceProfilesTable();

        $created = $this->withHeaders($this->headers())->postJson('/atlas-code/projects/workspaces', [
            'slug' => 'client-z',
            'name' => 'Client Z',
            'workspace_path' => '/missing/client-z',
            'docs_status' => 'incomplete',
        ]);
        $created->assertOk()->assertJsonPath('workspace.workspace_path_exists', false);

        $updated = $this->withHeaders($this->headers())->patchJson('/atlas-code/projects/workspaces/client-z', [
            'name' => 'Client Z Verified',
            'workspace_path' => base_path(),
            'repo_root' => base_path(),
            'test_commands' => ['php artisan test'],
            'critical_areas' => ['app/Services'],
            'docs_status' => 'complete',
        ]);

        $updated
            ->assertOk()
            ->assertJsonPath('workspace.name', 'Client Z Verified')
            ->assertJsonPath('workspace.workspace_path_exists', true)
            ->assertJsonPath('workspace.test_commands.0', 'php artisan test')
            ->assertJsonPath('meta.execution_allowed', true);
    }

    public function test_workspace_registry_api_triggers_aobg_activation_for_existing_folder(): void
    {
        $this->createWorkspaceProfilesTable();
        config()->set('atlas.aobg.workspace_api_auto_activate', true);

        $workspacePath = sys_get_temp_dir().'/atlas-code-aobg-api-'.Str::random(6);
        @mkdir($workspacePath, 0777, true);
        $canonicalWorkspacePath = realpath($workspacePath) ?: $workspacePath;

        $fake = new class extends AtlasAobgWorkspaceOnboardingService
        {
            /** @var array<int,array<string,mixed>> */
            public array $calls = [];

            public function __construct() {}

            /**
             * @param  array<string,mixed>  $opts
             * @return array<string,mixed>
             */
            public function activate(array $opts = []): array
            {
                $this->calls[] = $opts;

                return [
                    'ok' => true,
                    'action' => 'activated_already_indexed',
                    'workspace_id' => 'client-auto',
                    'workspace_path' => $opts['workspace'] ?? null,
                ];
            }
        };
        $this->app->instance(AtlasAobgWorkspaceOnboardingService::class, $fake);

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/projects/workspaces', [
            'slug' => 'client-auto',
            'name' => 'Client Auto',
            'workspace_path' => $canonicalWorkspacePath,
            'repo_root' => $canonicalWorkspacePath,
            'docs_status' => 'complete',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('workspace.slug', 'client-auto')
            ->assertJsonPath('meta.aobg_activation.ok', true)
            ->assertJsonPath('meta.aobg_activation.action', 'activated_already_indexed');
        $this->assertCount(1, $fake->calls);
        $this->assertSame($canonicalWorkspacePath, $fake->calls[0]['workspace']);

        @rmdir($workspacePath);
    }

    public function test_workspace_registry_api_archives_persisted_profile_without_hard_delete(): void
    {
        $this->createWorkspaceProfilesTable();

        AtlasWorkspaceProfile::query()->create([
            'slug' => 'client-archive',
            'name' => 'Client Archive',
            'kind' => 'client_product',
            'workspace_path' => base_path(),
            'repo_root' => base_path(),
            'production_status' => 'development',
            'stack_summary' => 'Temporary workspace',
            'commands' => [],
            'test_commands' => ['php artisan test'],
            'build_commands' => [],
            'critical_areas' => ['app/Services'],
            'docs_status' => 'complete',
            'default_risk' => 'medium',
            'deployment_notes' => '',
            'surfaces_enabled' => ['atlas_ai', 'code'],
            'source' => 'operator',
            'status' => 'active',
        ]);

        $archived = $this->withHeaders($this->headers())->deleteJson('/atlas-code/projects/workspaces/client-archive');

        $archived
            ->assertOk()
            ->assertJsonPath('workspace.slug', 'client-archive')
            ->assertJsonPath('workspace.status', 'archived')
            ->assertJsonPath('meta.archived', true)
            ->assertJsonPath('meta.execution_allowed', false);

        $this->assertDatabaseHas('atlas_workspace_profiles', [
            'slug' => 'client-archive',
            'status' => 'archived',
        ]);

        $hidden = $this->withHeaders($this->headers())->getJson('/atlas-code/projects/workspaces/client-archive');
        $hidden->assertNotFound();
    }

    public function test_workspace_registry_api_rejects_invalid_slug(): void
    {
        $this->createWorkspaceProfilesTable();

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/projects/workspaces', [
            'slug' => '../bad slug',
            'name' => 'Bad',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_workspace_slug');
    }

    public function test_persisted_workspace_profile_overrides_config_profile_by_slug(): void
    {
        $this->createWorkspaceProfilesTable();

        AtlasWorkspaceProfile::query()->create([
            'slug' => 'blackink',
            'name' => 'Blackink Persisted',
            'kind' => 'client_product',
            'workspace_path' => base_path(),
            'repo_root' => base_path(),
            'production_status' => 'development',
            'stack_summary' => 'Operator-reviewed persisted Blackink profile',
            'commands' => [],
            'test_commands' => ['npm test'],
            'build_commands' => ['npm run build'],
            'critical_areas' => ['checkout'],
            'docs_status' => 'complete',
            'default_risk' => 'medium',
            'deployment_notes' => 'DB profile wins over config projection.',
            'surfaces_enabled' => ['atlas_ai'],
            'source' => 'operator',
            'status' => 'active',
        ]);

        $profile = app(AtlasCodeWorkspaceProfileService::class)->findBySlug('blackink');

        $this->assertIsArray($profile);
        $this->assertSame('Blackink Persisted', $profile['name']);
        $this->assertSame('development', $profile['production_status']);
        $this->assertSame('complete', $profile['docs_status']);
        $this->assertTrue($profile['safety']['execution_allowed']);
    }

    public function test_persisted_workspace_profile_can_resolve_by_path(): void
    {
        $this->createWorkspaceProfilesTable();

        AtlasWorkspaceProfile::query()->create([
            'slug' => 'atlas-server-local',
            'name' => 'Atlas Server Local',
            'kind' => 'service_repo',
            'workspace_path' => base_path(),
            'repo_root' => base_path(),
            'production_status' => 'development',
            'stack_summary' => 'Current atlas-server repo',
            'commands' => [],
            'test_commands' => ['php artisan test'],
            'build_commands' => [],
            'critical_areas' => ['app', 'tests'],
            'docs_status' => 'complete',
            'default_risk' => 'medium',
            'deployment_notes' => '',
            'surfaces_enabled' => ['atlas_ai', 'code'],
            'source' => 'operator',
            'status' => 'active',
        ]);

        $profile = app(AtlasCodeWorkspaceProfileService::class)->findByPath(base_path());

        $this->assertIsArray($profile);
        $this->assertSame('atlas-server-local', $profile['slug']);
    }

    public function test_works_store_binds_obra_to_workspace_slug_in_metadata(): void
    {
        // Schema bootstrap mirrors AtlasCodeContractTest minimally — only the
        // atlas_projects table is required for store/index.
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

        // Two Obras: one in atlas, one in blackink.
        $atlasId = (string) Str::uuid();
        $blackinkId = (string) Str::uuid();
        DB::table('atlas_projects')->insert([
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

    private function createWorkspaceProfilesTable(): void
    {
        if (Schema::hasTable('atlas_workspace_profiles')) {
            return;
        }

        Schema::create('atlas_workspace_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 120)->unique();
            $table->string('name', 200);
            $table->string('kind', 80)->default('product');
            $table->string('workspace_path', 1000)->nullable();
            $table->string('repo_root', 1000)->nullable();
            $table->string('production_status', 80)->default('development');
            $table->text('stack_summary')->nullable();
            $table->json('commands')->nullable();
            $table->json('test_commands')->nullable();
            $table->json('build_commands')->nullable();
            $table->string('dev_server_command', 1000)->nullable();
            $table->json('critical_areas')->nullable();
            $table->string('docs_status', 80)->default('unknown');
            $table->string('default_risk', 40)->default('medium');
            $table->text('deployment_notes')->nullable();
            $table->json('surfaces_enabled')->nullable();
            $table->string('source', 80)->default('operator');
            $table->string('status', 40)->default('active');
            $table->timestamps();
        });
    }
}
