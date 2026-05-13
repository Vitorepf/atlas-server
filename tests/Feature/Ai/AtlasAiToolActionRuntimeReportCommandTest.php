<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasToolDefinition;
use App\Models\AtlasToolFinding;
use App\Models\AtlasToolInstallation;
use App\Models\AtlasToolRun;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAtlasToolRuntimeTables;
use Tests\TestCase;

class AtlasAiToolActionRuntimeReportCommandTest extends TestCase
{
    use CreatesAtlasToolRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAtlasToolRuntimeTables();
        $this->createInstallationAndPolicyTables();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_tool_policies');
        Schema::dropIfExists('atlas_tool_installations');
        $this->dropAtlasToolRuntimeTables();

        parent::tearDown();
    }

    public function test_command_reports_tool_action_runtime_evidence_without_writes(): void
    {
        $definition = AtlasToolDefinition::query()->create([
            'slug' => 'atlas_code_intelligence',
            'name' => 'Atlas Code Intelligence',
            'type' => 'internal_analyzer',
            'category' => 'code_intelligence',
            'status' => 'active',
            'risk_level' => 'low',
            'execution_tier' => 'T0',
            'expected_cost' => 'instant',
            'authority_role' => 'primary',
            'authority_group' => 'semantic_code_intelligence',
            'capabilities_json' => ['module_index'],
            'runtime_json' => ['execution_layers' => ['atlas_internal']],
            'detect_json' => ['binaries' => ['internal']],
            'outputs_json' => ['json'],
            'risks_json' => ['reads_workspace'],
            'metadata' => [],
        ]);
        AtlasToolInstallation::query()->create([
            'tool_definition_id' => $definition->id,
            'workspace_hash' => hash('sha256', base_path()),
            'execution_layer' => 'atlas_internal',
            'status' => 'ready',
            'version' => '1.0.0',
            'binary_path_hash' => hash('sha256', base_path()),
            'detected_at' => now(),
            'metadata_json' => ['binary' => 'internal'],
        ]);
        AtlasToolRun::query()->create([
            'tool_definition_id' => $definition->id,
            'tool_slug' => 'atlas_code_intelligence',
            'surface' => 'engineering_code_intelligence',
            'workspace_hash' => hash('sha256', base_path()),
            'workspace' => base_path(),
            'status' => 'passed',
            'required' => false,
            'failure_policy' => 'advisory',
            'policy_decision' => 'allowed',
            'exit_code' => 0,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'duration_ms' => 12,
            'summary_json' => ['finding_count' => 0],
            'normalized_result_json' => ['status' => 'passed'],
            'policy_decision_json' => [],
            'metadata_json' => [
                'action_runtime_contract' => [
                    'schema_version' => 'atlas.tool_action_runtime.contract.v1',
                    'provider_dispatch_allowed' => false,
                    'agent_control_plane_allowed' => false,
                ],
            ],
        ]);

        $exit = Artisan::call('atlas:ai:tool-action-runtime-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('atlas.tool_action_runtime_report.v1', data_get($payload, 'tool_action_runtime.schema_version'));
        $this->assertSame('ok', data_get($payload, 'tool_action_runtime.status'));
        $this->assertSame(1, data_get($payload, 'tool_action_runtime.definition_count'));
        $this->assertSame(1, data_get($payload, 'tool_action_runtime.ready_installation_count'));
        $this->assertSame(1, data_get($payload, 'tool_action_runtime.evidence_run_count'));
        $this->assertSame(1, data_get($payload, 'tool_action_runtime.action_runtime_contract_count'));
        $this->assertSame(1, data_get($payload, 'tool_action_runtime.latest_action_runtime_contract_count'));
        $this->assertSame(0, data_get($payload, 'tool_action_runtime.unsafe_action_runtime_contract_count'));
        $this->assertSame(0, data_get($payload, 'tool_action_runtime.latest_unsafe_action_runtime_contract_count'));
        $this->assertSame('atlas.tool_action_runtime.contract.v1', data_get($payload, 'tool_action_runtime.recent_runs.0.action_runtime_contract_summary.schema_version'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'tool_action_runtime.recent_runs.0.action_runtime_contract_summary.contract_hash'));
        $this->assertFalse(data_get($payload, 'tool_action_runtime.recent_runs.0.action_runtime_contract_summary.unsafe'));
        $this->assertFalse(data_get($payload, 'tool_action_runtime.recent_runs.0.action_runtime_contract_summary.provider_dispatch_allowed'));
        $this->assertFalse(data_get($payload, 'tool_action_runtime.recent_runs.0.action_runtime_contract_summary.agent_control_plane_allowed'));
        $this->assertFalse(data_get($payload, 'tool_action_runtime.writes'));
        $this->assertSame('continue_tool_action_runtime_monitoring', data_get($payload, 'tool_action_runtime.review_signal.recommended_action'));
    }

    public function test_historical_runs_missing_contract_do_not_warn_when_latest_tool_evidence_is_contracted(): void
    {
        $definition = $this->definition('atlas_code_intelligence');
        AtlasToolRun::query()->create([
            'tool_definition_id' => $definition->id,
            'tool_slug' => 'atlas_code_intelligence',
            'surface' => 'engineering_code_intelligence',
            'status' => 'passed',
            'required' => false,
            'failure_policy' => 'advisory',
            'policy_decision' => 'allowed',
            'exit_code' => 0,
            'metadata_json' => [],
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
        AtlasToolRun::query()->create([
            'tool_definition_id' => $definition->id,
            'tool_slug' => 'atlas_code_intelligence',
            'surface' => 'engineering_code_intelligence',
            'status' => 'passed',
            'required' => false,
            'failure_policy' => 'advisory',
            'policy_decision' => 'allowed',
            'exit_code' => 0,
            'metadata_json' => [
                'action_runtime_contract' => ['schema_version' => 'atlas.tool_action_runtime.contract.v1'],
            ],
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $exit = Artisan::call('atlas:ai:tool-action-runtime-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(1, data_get($payload, 'tool_action_runtime.missing_action_runtime_contract_count'));
        $this->assertSame(0, data_get($payload, 'tool_action_runtime.latest_missing_action_runtime_contract_count'));
        $this->assertSame('ok', data_get($payload, 'tool_action_runtime.review_signal.status'));
    }

    public function test_same_second_latest_evidence_prefers_contracted_run_over_legacy_completed_row(): void
    {
        $definition = $this->definition('atlas_code_intelligence');
        $timestamp = now()->subMinute()->startOfSecond();
        AtlasToolRun::query()->create([
            'tool_definition_id' => $definition->id,
            'tool_slug' => 'atlas_code_intelligence',
            'surface' => 'engineering_code_intelligence',
            'status' => 'completed',
            'required' => false,
            'failure_policy' => 'advisory',
            'policy_decision' => 'allowed',
            'exit_code' => 0,
            'metadata_json' => [],
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        AtlasToolRun::query()->create([
            'tool_definition_id' => $definition->id,
            'tool_slug' => 'atlas_code_intelligence',
            'surface' => 'engineering_code_intelligence',
            'status' => 'passed',
            'required' => false,
            'failure_policy' => 'advisory',
            'policy_decision' => 'allowed',
            'exit_code' => 0,
            'metadata_json' => [
                'action_runtime_contract' => ['schema_version' => 'atlas.tool_action_runtime.contract.v1'],
            ],
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        Artisan::call('atlas:ai:tool-action-runtime-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, data_get($payload, 'tool_action_runtime.latest_evidence_run_count'));
        $this->assertSame(0, data_get($payload, 'tool_action_runtime.latest_missing_action_runtime_contract_count'));
        $this->assertSame('ok', data_get($payload, 'tool_action_runtime.review_signal.status'));
    }

    public function test_command_warns_when_required_tool_evidence_failed(): void
    {
        $definition = $this->definition('semgrep');
        AtlasToolRun::query()->create([
            'tool_definition_id' => $definition->id,
            'tool_slug' => 'semgrep',
            'surface' => 'engineering_quality_scan',
            'status' => 'failed',
            'required' => true,
            'failure_policy' => 'blocking',
            'policy_decision' => 'allowed',
            'exit_code' => 1,
            'metadata_json' => [
                'action_runtime_contract' => ['schema_version' => 'atlas.tool_action_runtime.contract.v1'],
            ],
        ]);

        $exit = Artisan::call('atlas:ai:tool-action-runtime-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('warning', data_get($payload, 'tool_action_runtime.status'));
        $this->assertSame(1, data_get($payload, 'tool_action_runtime.failed_required_run_count'));
        $this->assertSame('inspect_failed_required_tool_runs', data_get($payload, 'tool_action_runtime.review_signal.recommended_action'));
    }

    public function test_command_warns_when_blocking_findings_are_open(): void
    {
        $definition = $this->definition('gitleaks');
        $run = AtlasToolRun::query()->create([
            'tool_definition_id' => $definition->id,
            'tool_slug' => 'gitleaks',
            'surface' => 'engineering_quality_scan',
            'status' => 'passed',
            'required' => true,
            'failure_policy' => 'blocking',
            'policy_decision' => 'allowed',
            'exit_code' => 0,
            'metadata_json' => [
                'action_runtime_contract' => ['schema_version' => 'atlas.tool_action_runtime.contract.v1'],
            ],
        ]);
        AtlasToolFinding::query()->create([
            'tool_run_id' => $run->id,
            'rule_id' => 'secret',
            'title' => 'Secret found',
            'severity' => 'critical',
            'fingerprint' => 'secret:1',
            'blocks_resolved' => true,
            'status' => 'open',
            'metadata_json' => [],
        ]);

        $exit = Artisan::call('atlas:ai:tool-action-runtime-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('warning', data_get($payload, 'tool_action_runtime.status'));
        $this->assertSame(1, data_get($payload, 'tool_action_runtime.blocking_open_finding_count'));
        $this->assertSame('resolve_or_waive_blocking_tool_findings', data_get($payload, 'tool_action_runtime.review_signal.recommended_action'));
    }

    public function test_command_warns_when_latest_tool_run_has_unsafe_action_runtime_contract(): void
    {
        $definition = $this->definition('unsafe_tool');
        AtlasToolRun::query()->create([
            'tool_definition_id' => $definition->id,
            'tool_slug' => 'unsafe_tool',
            'surface' => 'engineering_quality_scan',
            'status' => 'passed',
            'required' => false,
            'failure_policy' => 'advisory',
            'policy_decision' => 'allowed',
            'exit_code' => 0,
            'metadata_json' => [
                'action_runtime_contract' => [
                    'schema_version' => 'atlas.tool_action_runtime.contract.v1',
                    'provider_dispatch_allowed' => true,
                    'runtime_policy_mutation_allowed' => false,
                    'agent_control_plane_allowed' => false,
                    'operator_approval_required_for_execution' => true,
                ],
            ],
        ]);

        $exit = Artisan::call('atlas:ai:tool-action-runtime-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('warning', data_get($payload, 'tool_action_runtime.status'));
        $this->assertSame(1, data_get($payload, 'tool_action_runtime.unsafe_action_runtime_contract_count'));
        $this->assertSame(1, data_get($payload, 'tool_action_runtime.latest_unsafe_action_runtime_contract_count'));
        $this->assertTrue(data_get($payload, 'tool_action_runtime.recent_runs.0.action_runtime_contract_summary.unsafe'));
        $this->assertTrue(data_get($payload, 'tool_action_runtime.recent_runs.0.action_runtime_contract_summary.provider_dispatch_allowed'));
        $this->assertSame(
            'block_tool_promotion_until_action_runtime_contract_is_repaired',
            data_get($payload, 'tool_action_runtime.review_signal.recommended_action'),
        );
    }

    private function definition(string $slug): AtlasToolDefinition
    {
        return AtlasToolDefinition::query()->create([
            'slug' => $slug,
            'name' => $slug,
            'type' => 'scanner',
            'category' => 'security',
            'status' => 'active',
            'risk_level' => 'medium',
            'execution_tier' => 'T2',
            'expected_cost' => 'review_medium',
            'authority_role' => 'primary',
            'authority_group' => 'security',
            'capabilities_json' => [],
            'runtime_json' => [],
            'detect_json' => [],
            'outputs_json' => [],
            'risks_json' => [],
            'metadata' => [],
        ]);
    }

    private function createInstallationAndPolicyTables(): void
    {
        Schema::create('atlas_tool_installations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tool_definition_id');
            $table->string('workspace_hash')->index();
            $table->string('execution_layer');
            $table->string('status');
            $table->string('version')->nullable();
            $table->string('binary_path_hash')->nullable();
            $table->string('node_modules_path_hash')->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_tool_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tool_slug')->index();
            $table->string('workspace_hash')->nullable()->index();
            $table->string('scope')->default('workspace');
            $table->string('decision')->default('allowed');
            $table->timestamp('expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }
}
