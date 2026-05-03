<?php

namespace Tests\Feature;

use App\Models\AtlasToolRun;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasEngineeringApiContractTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $this->workspace = sys_get_temp_dir().'/atlas-api-contract-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace.'/docs');
        $this->createToolRuntimeTables();
    }

    protected function tearDown(): void
    {
        foreach ([
            'atlas_tool_findings',
            'atlas_tool_artifacts',
            'atlas_tool_runs',
            'atlas_tool_policies',
            'atlas_tool_installations',
            'atlas_tool_definitions',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_api_contract_command_detects_openapi_and_records_runtime_evidence(): void
    {
        File::put($this->workspace.'/docs/openapi.yaml', <<<'YAML'
openapi: 3.1.0
info:
  title: Atlas Test API
  version: 1.0.0
paths:
  /tools:
    get:
      responses:
        "200":
          description: OK
YAML);

        $exit = Artisan::call('atlas:engineering:api-contract', [
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('passed', $payload['status'] ?? null);
        $this->assertSame('docs/openapi.yaml', $payload['spec_path'] ?? null);
        $this->assertSame(true, data_get($payload, 'metrics.spec_detected'));
        $this->assertGreaterThan(0, data_get($payload, 'summary.route_count'));
        $this->assertFileExists($payload['artifact_root'].'/api-contract.json');
        $this->assertDatabaseHas('atlas_tool_runs', [
            'tool_slug' => 'atlas_api_contract',
            'surface' => 'engineering_api_contract',
            'status' => 'passed',
        ]);
        $this->assertSame(1, AtlasToolRun::query()->where('tool_slug', 'atlas_api_contract')->count());
    }

    public function test_api_contract_blocks_documented_operation_that_is_not_implemented(): void
    {
        File::put($this->workspace.'/openapi.json', json_encode([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Atlas Test API', 'version' => '1.0.0'],
            'paths' => [
                '/not-implemented' => [
                    'post' => [
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
            ],
        ]));

        $exit = Artisan::call('atlas:engineering:api-contract', [
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertSame('failed', $payload['status'] ?? null);
        $this->assertSame('atlas_api_contract.operation_not_implemented', data_get($payload, 'findings.0.rule_id'));
        $this->assertTrue((bool) data_get($payload, 'findings.0.blocks_resolved'));
        $this->assertDatabaseHas('atlas_tool_findings', [
            'rule_id' => 'atlas_api_contract.operation_not_implemented',
            'severity' => 'high',
            'blocks_resolved' => true,
        ]);
    }

    public function test_api_contract_strict_mode_blocks_undocumented_routes(): void
    {
        File::put($this->workspace.'/openapi.json', json_encode([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Atlas Test API', 'version' => '1.0.0'],
            'paths' => [
                '/tools' => [
                    'get' => [
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
            ],
        ]));

        $this->postJson('/engineering/api-contract', [
            'workspace' => $this->workspace,
            'strict' => true,
            'run_context_type' => 'engineering_run',
            'run_context_id' => 'api-contract-test',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('strict', true);

        $run = AtlasToolRun::query()->where('tool_slug', 'atlas_api_contract')->latest('created_at')->firstOrFail();

        $this->assertSame('engineering_run', $run->run_context_type);
        $this->assertSame('api-contract-test', $run->run_context_id);
        $this->assertGreaterThan(0, $run->findings()->where('rule_id', 'atlas_api_contract.route_not_documented')->count());
    }

    public function test_api_contract_treats_parameter_names_as_equivalent(): void
    {
        File::put($this->workspace.'/openapi.json', json_encode([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Atlas Test API', 'version' => '1.0.0'],
            'paths' => [
                '/tools/findings/{findingId}/waiver' => [
                    'delete' => [
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
            ],
        ]));

        $exit = Artisan::call('atlas:engineering:api-contract', [
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('passed', $payload['status'] ?? null);
        $this->assertFalse(collect($payload['findings'] ?? [])->contains(
            fn (array $finding): bool => ($finding['rule_id'] ?? null) === 'atlas_api_contract.operation_not_implemented'
        ));
    }

    public function test_api_contract_missing_spec_is_skipped_with_recommendation(): void
    {
        $exit = Artisan::call('atlas:engineering:api-contract', [
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('skipped', $payload['status'] ?? null);
        $this->assertSame('atlas_api_contract.spec_missing', data_get($payload, 'findings.0.rule_id'));
        $this->assertSame('add_openapi_spec', data_get($payload, 'recommendations.0.action'));
        $this->assertDatabaseHas('atlas_tool_runs', [
            'tool_slug' => 'atlas_api_contract',
            'status' => 'skipped',
        ]);
    }

    private function createToolRuntimeTables(): void
    {
        Schema::create('atlas_tool_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('type')->default('validator');
            $table->string('category');
            $table->text('description')->nullable();
            $table->string('homepage')->nullable();
            $table->string('license_posture')->default('open_source');
            $table->string('cost_posture')->default('free_local');
            $table->boolean('default_enabled')->default(true);
            $table->unsignedSmallInteger('default_timeout_seconds')->default(120);
            $table->string('default_failure_policy')->default('advisory');
            $table->string('risk_level')->default('low');
            $table->string('status')->default('active');
            $table->string('execution_tier')->default('T1');
            $table->string('expected_cost')->default('local_fast');
            $table->string('default_trigger')->default('manual_or_policy');
            $table->string('authority_role')->default('primary');
            $table->string('authority_group')->nullable();
            $table->string('detected_version')->nullable();
            $table->json('capabilities_json')->nullable();
            $table->json('runtime_json')->nullable();
            $table->json('detect_json')->nullable();
            $table->json('outputs_json')->nullable();
            $table->json('risks_json')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_tool_installations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tool_definition_id');
            $table->string('workspace_hash');
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
            $table->string('scope_type')->default('global');
            $table->string('scope_id')->nullable();
            $table->string('tool_slug');
            $table->boolean('enabled')->default(true);
            $table->json('required_when_json')->nullable();
            $table->string('failure_policy')->nullable();
            $table->unsignedSmallInteger('timeout_seconds')->nullable();
            $table->json('thresholds_json')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_tool_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tool_definition_id')->nullable();
            $table->string('tool_slug');
            $table->string('surface')->default('cli');
            $table->string('workspace_hash')->nullable();
            $table->text('workspace')->nullable();
            $table->string('run_context_type')->nullable();
            $table->string('run_context_id')->nullable();
            $table->string('status');
            $table->boolean('required')->default(false);
            $table->string('failure_policy')->default('advisory');
            $table->string('policy_decision')->default('allowed');
            $table->string('command_hash')->nullable();
            $table->integer('exit_code')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('duration_ms')->default(0);
            $table->uuid('stdout_artifact_id')->nullable();
            $table->uuid('stderr_artifact_id')->nullable();
            $table->json('summary_json')->nullable();
            $table->json('normalized_result_json')->nullable();
            $table->json('policy_decision_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_tool_artifacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tool_run_id');
            $table->string('type');
            $table->text('path');
            $table->string('filename');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('sha256');
            $table->boolean('is_redacted')->default(true);
            $table->json('preview_json')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_tool_findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tool_run_id');
            $table->string('rule_id')->nullable();
            $table->text('title');
            $table->text('message')->nullable();
            $table->string('severity')->default('medium');
            $table->decimal('confidence', 4, 3)->nullable();
            $table->text('file_path')->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->unsignedInteger('end_line')->nullable();
            $table->string('fingerprint')->nullable();
            $table->boolean('blocks_resolved')->default(false);
            $table->string('waiver_id')->nullable();
            $table->string('status')->default('open');
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });
    }
}
