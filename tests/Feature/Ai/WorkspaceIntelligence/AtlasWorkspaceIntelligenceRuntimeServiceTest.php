<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\WorkspaceIntelligence;

use App\Services\Ai\Programming\AtlasDevRuntimeService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceRuntimeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasWorkspaceIntelligenceRuntimeServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSnapshotTable();
    }

    public function test_default_atlas_workspace_certifies_full_awis_family(): void
    {
        $report = app(AtlasWorkspaceIntelligenceRuntimeService::class)->certify(
            workspace: 'atlas',
            task: 'corrigir bug na tela de login',
            conversationTexts: ['decisao: AWIS usa task context pack; blocker antigo foi resolvido'],
        );

        $this->assertSame(AtlasWorkspaceIntelligenceRuntimeService::SCHEMA_VERSION, $report['schema_version']);
        $this->assertSame('ready', $report['status']);
        $this->assertSame('atlas', $report['workspace']['workspace_id']);
        $this->assertSame('ready', $report['workspace']['readiness_status']);

        foreach (['AWIS', 'AWTR', 'ACIOS', 'AWAF', 'AWCO', 'AWEF'] as $acronym) {
            $this->assertArrayHasKey($acronym, $report['family']);
        }

        $this->assertSame('ready', $report['awtr']['status']);
        $this->assertNotEmpty($report['awtr']['twin_hash']);
        $this->assertSame(false, $report['acios']['task_context_pack_policy']['uses_raw_conversation']);
        $this->assertSame(10, $report['awaf']['artifact_count']);
        $artifactTypes = collect($report['awaf']['artifacts'])->pluck('artifact_type')->all();
        $this->assertSame([
            'workspace_brief',
            'task_packet',
            'context_pack',
            'execution_plan',
            'test_plan',
            'risk_sheet',
            'handoff_packet',
            'failure_capsule',
            'outcome_record',
            'workspace_runbook',
        ], $artifactTypes);
        $this->assertSame('ready', $report['awco']['execution_readiness_status']);
        $this->assertTrue($report['awef']['privacy_preserving_transfer']);
        $this->assertSame(64, strlen((string) $report['runtime_hash']));
    }

    public function test_unknown_workspace_blocks_without_fabricating_context(): void
    {
        $report = app(AtlasWorkspaceIntelligenceRuntimeService::class)->certify(
            workspace: 'missing-workspace',
            task: 'qualquer coisa',
        );

        $this->assertSame('blocked', $report['status']);
        $this->assertSame('blocked', $report['workspace']['status']);
        $this->assertSame(['workspace_not_registered'], $report['workspace']['blockers']);
        $this->assertSame('blocked', $report['awco']['execution_readiness_status']);
        $this->assertSame(false, $report['claim_policy']['raw_conversation_used_as_prompt']);
    }

    public function test_long_conversation_is_archived_by_hash_and_not_used_as_prompt(): void
    {
        $raw = str_repeat('contexto bruto sensivel ', 200).'decidido: usar AWIS; blocker: contexto velho';

        $report = app(AtlasWorkspaceIntelligenceRuntimeService::class)->certify(
            workspace: 'atlas',
            task: 'gerar pacote minimo',
            conversationTexts: [$raw],
        );

        $this->assertSame(1, $report['acios']['raw_archive']['conversation_count']);
        $this->assertSame('audit_only', $report['acios']['raw_archive']['access_mode']);
        $this->assertSame(false, $report['acios']['current_truth_pack']['raw_conversation_in_prompt']);
        $this->assertSame(false, $report['awaf']['artifacts'][2]['body']['raw_conversation_included']);

        $encoded = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString(str_repeat('contexto bruto sensivel ', 20), $encoded);
    }

    public function test_command_emits_json_and_strict_ready_for_atlas(): void
    {
        $exit = Artisan::call('atlas:workspace-intelligence', [
            'action' => 'certify',
            '--workspace' => 'atlas',
            '--task' => 'corrigir bug na tela de login',
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('ready', $decoded['status']);
        $this->assertSame('atlas', $decoded['workspace']['workspace_id']);
        $this->assertSame('atlas.workspace_intelligence.runtime.v1', $decoded['schema_version']);
    }

    public function test_command_strict_fails_for_missing_workspace(): void
    {
        $exit = Artisan::call('atlas:workspace-intelligence', [
            'action' => 'certify',
            '--workspace' => 'missing-workspace',
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(1, $exit);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('blocked', $decoded['status']);
    }

    public function test_api_exposes_workspace_intelligence_for_desktop_and_mobile_surfaces(): void
    {
        $response = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence?workspace=atlas&task='.urlencode('corrigir bug login'),
        );

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', AtlasWorkspaceIntelligenceRuntimeService::SCHEMA_VERSION)
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('workspace.workspace_id', 'atlas')
            ->assertJsonPath('awaf.artifact_count', 10)
            ->assertJsonPath('awco.execution_readiness_status', 'ready');
    }

    public function test_api_artifacts_endpoint_returns_awaf_projection_only(): void
    {
        $response = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/artifacts?workspace=atlas&task='.urlencode('corrigir bug login'),
        );

        $response
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.workspace_artifact_fabric.v1')
            ->assertJsonPath('workspace_id', 'atlas')
            ->assertJsonPath('artifact_count', 10);
    }

    public function test_command_persists_snapshot_when_requested(): void
    {
        $exit = Artisan::call('atlas:workspace-intelligence', [
            'action' => 'certify',
            '--workspace' => 'atlas',
            '--task' => 'persistir snapshot AWIS',
            '--json' => true,
            '--persist' => true,
        ]);

        $this->assertSame(0, $exit);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertNotEmpty($decoded['persisted_snapshot_id']);

        $this->assertDatabaseHas('atlas_workspace_intelligence_snapshots', [
            'workspace_id' => 'atlas',
            'runtime_hash' => $decoded['runtime_hash'],
            'status' => 'ready',
            'artifacts_count' => 10,
        ]);
    }

    public function test_api_latest_replays_persisted_snapshot(): void
    {
        $persisted = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence?workspace=atlas&task='.urlencode('snapshot replay').'&persist=1',
        );
        $persisted->assertOk();

        $latest = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence?workspace=atlas&latest=1',
        );

        $latest
            ->assertOk()
            ->assertJsonPath('runtime_hash', $persisted->json('runtime_hash'))
            ->assertJsonPath('workspace.workspace_id', 'atlas')
            ->assertJsonPath('awaf.artifact_count', 10);
    }

    public function test_persist_is_idempotent_for_same_runtime_hash(): void
    {
        Artisan::call('atlas:workspace-intelligence', [
            'action' => 'certify',
            '--workspace' => 'atlas',
            '--task' => 'mesma tarefa',
            '--json' => true,
            '--persist' => true,
        ]);
        $first = json_decode(Artisan::output(), true);

        Artisan::call('atlas:workspace-intelligence', [
            'action' => 'certify',
            '--workspace' => 'atlas',
            '--task' => 'mesma tarefa',
            '--json' => true,
            '--persist' => true,
        ]);
        $second = json_decode(Artisan::output(), true);

        $this->assertSame($first['runtime_hash'], $second['runtime_hash']);
        $this->assertDatabaseCount('atlas_workspace_intelligence_snapshots', 1);
    }

    public function test_execution_gate_allows_conversation_without_ready_workspace(): void
    {
        $gate = app(AtlasWorkspaceIntelligenceExecutionGateService::class)->gate(
            workspace: 'missing-workspace',
            mode: 'conversation',
            task: 'conversa livre',
        );

        $this->assertSame(AtlasWorkspaceIntelligenceExecutionGateService::SCHEMA_VERSION, $gate['schema_version']);
        $this->assertTrue($gate['allowed']);
        $this->assertSame('limited', $gate['status']);
        $this->assertSame('conversation', $gate['execution_class']);
        $this->assertSame(['workspace_not_ready_conversation_only'], $gate['warnings']);
        $this->assertSame([], $gate['blockers']);
    }

    public function test_execution_gate_blocks_mutative_dev_without_ready_workspace(): void
    {
        $gate = app(AtlasWorkspaceIntelligenceExecutionGateService::class)->gate(
            workspace: 'missing-workspace',
            mode: 'dev',
            task: 'corrigir bug login',
        );

        $this->assertFalse($gate['allowed']);
        $this->assertSame('blocked', $gate['status']);
        $this->assertSame('mutative', $gate['execution_class']);
        $this->assertContains('workspace_not_ready', $gate['blockers']);
        $this->assertContains('workspace_contracts_not_certified', $gate['blockers']);
    }

    public function test_execution_gate_allows_mutative_forge_for_ready_workspace(): void
    {
        $gate = app(AtlasWorkspaceIntelligenceExecutionGateService::class)->gate(
            workspace: 'atlas',
            mode: 'forge',
            task: 'criar ecommerce',
        );

        $this->assertTrue($gate['allowed']);
        $this->assertSame('ready', $gate['status']);
        $this->assertSame('mutative', $gate['execution_class']);
        $this->assertSame('atlas', $gate['workspace_id']);
        $this->assertSame([], $gate['blockers']);
        $this->assertTrue($gate['required_contracts']['awco_execution_readiness']);
        $this->assertSame(64, strlen((string) $gate['gate_hash']));
    }

    public function test_execution_gate_accepts_workspace_path_as_alias_for_profile_slug(): void
    {
        $gate = app(AtlasWorkspaceIntelligenceExecutionGateService::class)->gate(
            workspace: base_path('..'),
            mode: 'dev',
            task: 'corrigir bug login',
        );

        $this->assertTrue($gate['allowed']);
        $this->assertSame('ready', $gate['status']);
        $this->assertSame('atlas', $gate['workspace_id']);
        $this->assertTrue($gate['required_contracts']['awis_workspace_binding']);
    }

    public function test_command_gate_blocks_mutative_mode_in_strict_mode(): void
    {
        $exit = Artisan::call('atlas:workspace-intelligence', [
            'action' => 'gate',
            '--workspace' => 'missing-workspace',
            '--mode' => 'index-code',
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(1, $exit);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('blocked', $decoded['status']);
        $this->assertFalse($decoded['allowed']);
    }

    public function test_command_registers_persisted_workspace_and_gate_resolves_it(): void
    {
        $this->createWorkspaceProfilesTable();

        $exit = Artisan::call('atlas:workspace-intelligence', [
            'action' => 'register',
            '--workspace' => 'client-y',
            '--name' => 'Client Y',
            '--path' => base_path(),
            '--test-command' => ['php artisan test'],
            '--critical-area' => ['app', 'tests'],
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('ready', $decoded['status']);
        $this->assertSame('client-y', $decoded['workspace']['slug']);
        $this->assertTrue((bool) data_get($decoded, 'meta.execution_allowed'));

        $gate = app(AtlasWorkspaceIntelligenceExecutionGateService::class)->gate(
            workspace: 'client-y',
            mode: 'dev',
            task: 'corrigir bug login',
        );

        $this->assertTrue($gate['allowed']);
        $this->assertSame('client-y', $gate['workspace_id']);
    }

    public function test_api_persists_workspace_profile_but_does_not_auto_allow_missing_path_execution(): void
    {
        $this->createWorkspaceProfilesTable();

        $response = $this->withHeaders($this->headers())->postJson('/atlas-code/projects/workspaces', [
            'slug' => 'paper-project',
            'name' => 'Paper Project',
            'workspace_path' => '/definitely/missing/atlas/project',
            'docs_status' => 'incomplete',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('workspace.slug', 'paper-project')
            ->assertJsonPath('workspace.workspace_path_exists', false)
            ->assertJsonPath('meta.execution_allowed', false);

        $gate = app(AtlasWorkspaceIntelligenceExecutionGateService::class)->gate(
            workspace: 'paper-project',
            mode: 'forge',
            task: 'criar ecommerce',
        );

        $this->assertFalse($gate['allowed']);
        $this->assertContains('workspace_not_ready', $gate['blockers']);
    }

    public function test_api_gate_endpoint_blocks_mutative_mode_without_workspace(): void
    {
        $response = $this->withHeaders($this->headers())->getJson(
            '/atlas-code/workspace-intelligence/gate?workspace=missing-workspace&mode=patch',
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('schema_version', AtlasWorkspaceIntelligenceExecutionGateService::SCHEMA_VERSION)
            ->assertJsonPath('allowed', false)
            ->assertJsonPath('status', 'blocked');
    }

    public function test_atlas_dev_runtime_resolved_from_container_embeds_awis_execution_gate(): void
    {
        $data = app(AtlasDevRuntimeService::class)->apply([
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'atlas_mode' => 'programming',
                'routing_task' => 'dev',
                'workspace' => base_path('..'),
                'input_text' => 'corrigir bug login',
                'context_refs' => ['docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md'],
                'expected_files' => ['app/Services/Ai/Programming/AtlasDevRuntimeService.php'],
                'suggested_tests' => ['php artisan test --filter=AtlasDevRuntimeServiceTest'],
                'acceptance_criteria' => ['runtime carries AWIS execution gate'],
            ],
        ]);

        $gate = data_get($data, 'payload.atlas_dev_runtime.workspace_execution_gate');

        $this->assertIsArray($gate);
        $this->assertSame(AtlasWorkspaceIntelligenceExecutionGateService::SCHEMA_VERSION, $gate['schema_version']);
        $this->assertSame('dev', $gate['mode']);
        $this->assertTrue($gate['allowed']);
        $this->assertSame('atlas', $gate['workspace_id']);
        $this->assertTrue(data_get($data, 'payload.atlas_dev_runtime.provider_execution_allowed'));
    }

    /**
     * @return array<string,string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'application/json',
            'X-Atlas-Token' => 'testing-atlas-token-with-enough-length',
        ];
    }

    private function createSnapshotTable(): void
    {
        if (Schema::hasTable('atlas_workspace_intelligence_snapshots')) {
            return;
        }

        Schema::create('atlas_workspace_intelligence_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->index();
            $table->string('workspace_id', 120)->index();
            $table->string('workspace_hash', 64)->nullable()->index();
            $table->string('runtime_hash', 64)->unique();
            $table->string('status', 40)->index();
            $table->unsignedInteger('checks_total')->default(0);
            $table->unsignedInteger('checks_passed')->default(0);
            $table->unsignedInteger('checks_failed')->default(0);
            $table->unsignedInteger('artifacts_count')->default(0);
            $table->json('family_status');
            $table->json('payload');
            $table->timestamp('captured_at')->index();
            $table->timestamps();
        });
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
