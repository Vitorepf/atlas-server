<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Models\AtlasProgrammingWorkItem;
use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasForgeGovernedExecutionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasProgrammingGovernanceTables;
use Tests\TestCase;

final class AtlasForgeGovernedExecutionAwisTest extends TestCase
{
    use CreatesAtlasProgrammingGovernanceTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureProjectSchema();
        $this->createAtlasProgrammingGovernanceTables();
    }

    protected function tearDown(): void
    {
        $this->cleanupProgrammingWorkspaces();
        $this->dropAtlasProgrammingGovernanceTables();
        parent::tearDown();
    }

    public function test_governed_execution_blocks_before_workspace_and_sandbox_without_certified_awis_workspace(): void
    {
        $workspace = $this->makeProgrammingWorkspace(['app/Foo.php']);
        $project = AtlasProject::create([
            'id' => (string) Str::uuid(),
            'title' => 'Governed execution AWIS Obra',
            'status' => 'active',
            'domain' => 'atlas',
            'goal' => 'Provar AWIS antes de execucao governada',
            'metadata' => [
                'workspace_slug' => 'workspace-nao-registrado',
                'workspace_path' => $workspace,
            ],
        ]);
        $workItem = AtlasProgrammingWorkItem::create([
            'code' => 'AWIS-GOV-1',
            'intent_text' => 'Patch controlado para validar AWIS',
            'intent_type' => 'implementation',
            'scope_mode' => 'forge',
            'risk_level' => 'medium',
            'workspace' => $workspace,
            'status' => 'open',
            'current_stage' => 'planned',
            'spec_hash' => str_repeat('a', 64),
            'plan_hash' => str_repeat('b', 64),
            'tasks_json' => [[
                'task_id' => 'task-1',
                'allowed_files' => ['app/Foo.php'],
                'validation_commands' => ['php -r "echo \'ok\';"'],
            ]],
        ]);

        $report = app(AtlasForgeGovernedExecutionService::class)->execute($project, $workItem);

        $this->assertSame('blocked', $report['status']);
        $this->assertContains('awis_execution_gate_blocked', $report['remaining_blockers']);

        $stages = collect($report['stages'])->keyBy('name');
        $this->assertSame('passed', $stages['task_contract']['status']);
        $this->assertSame('blocked', $stages['workspace_execution_gate']['status']);
        $this->assertSame('atlas.workspace_intelligence.execution_gate.v1', data_get($stages, 'workspace_execution_gate.schema_version'));
        $this->assertFalse(data_get($stages, 'workspace_execution_gate.workspace_execution_gate.allowed'));
        $this->assertFalse($stages->has('workspace_resolution'));
        $this->assertFalse($stages->has('sandbox_shadow_workspace'));
        $this->assertFalse($stages->has('patch_dry_run'));
        $this->assertFalse($report['external_provider_call']);
        $this->assertFalse($report['live_workspace_mutated']);
    }

    private function ensureProjectSchema(): void
    {
        if (Schema::hasTable('atlas_projects')) {
            return;
        }

        Schema::create('atlas_projects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->string('domain')->default('atlas');
            $table->text('goal')->nullable();
            $table->text('desired_outcome')->nullable();
            $table->string('priority')->default('normal');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
}
