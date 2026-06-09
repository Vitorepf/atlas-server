<?php

declare(strict_types=1);

namespace Tests\Feature\CodeGraph;

use App\Models\AtlasToolRun;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * AP-815 · W-5 — proves the code-graph's recorded outcome/evidence rows are isolated
 * by workspace: an `index()` run against a SECOND project tags its tool-runtime
 * evidence with the resolved `workspace_id` (NOT the primary 'atlas-server'), so a
 * second project's outcomes are distinguishable from the running app's graph.
 *
 * The seam is purely additive — the id rides in the metadata map the evidence store
 * already spreads into `metadata_json`; no evidence-store schema change.
 *
 * Boots only the needed tables (the repo's established CodeGraph pattern — full
 * RefreshDatabase is unreliable here because a core migration is pgsql-only SQL).
 */
final class CodeGraphEvidenceWorkspaceIdTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootCodeIntelligenceTables();
        $this->bootToolRuntimeTables();

        $this->project = sys_get_temp_dir().'/atlas-w5-project-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->project.'/app');
        // One indexable file so the scan/persist path actually produces an outcome row.
        File::put($this->project.'/app/W5Sample.php', "<?php\n\nnamespace App;\n\nclass W5Sample\n{\n    public function run(): void {}\n}\n");
        // A stable git remote → the resolver derives a deterministic, clearly-non-primary id.
        File::ensureDirectoryExists($this->project.'/.git');
        File::put($this->project.'/.git/config', "[remote \"origin\"]\n\turl = git@github.com:acme/w5project.git\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->project);

        foreach ([
            'atlas_tool_findings',
            'atlas_tool_artifacts',
            'atlas_tool_runs',
            'atlas_tool_definitions',
            'atlas_ledger_events',
            'atlas_engineering_doc_links',
            'atlas_engineering_code_symbols',
            'atlas_engineering_code_modules',
            'atlas_engineering_code_file_snapshots',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_index_run_tags_evidence_with_resolved_workspace_id_for_a_second_project(): void
    {
        $expectedId = app(CodeGraphWorkspaceIdentity::class)->resolve($this->project);

        // Sanity: a second project must NOT resolve to the primary workspace id.
        $this->assertNotSame('atlas-server', $expectedId);
        $this->assertSame('acme-w5project', $expectedId, 'git-remote slug should drive the stable id');

        app(EngineeringCodeIntelligenceService::class)->index([
            'workspace' => $this->project,
            'prune' => false,
        ]);

        $run = AtlasToolRun::query()
            ->where('tool_slug', 'atlas_code_intelligence')
            ->latest('id')
            ->first();

        $this->assertInstanceOf(AtlasToolRun::class, $run, 'an index run must record a tool-runtime evidence row');
        $this->assertSame('index', data_get($run->metadata_json, 'operation'));
        $this->assertSame(
            $expectedId,
            data_get($run->metadata_json, 'workspace_id'),
            'the evidence metadata must carry the resolved workspace_id so a second project is distinguishable',
        );
    }

    public function test_primary_workspace_evidence_defaults_to_atlas_server(): void
    {
        // Default-safe: an audit of the running app stays tagged with the primary id.
        app(EngineeringCodeIntelligenceService::class)->audit([
            'workspace' => base_path(),
            'limit' => 1,
        ]);

        $run = AtlasToolRun::query()
            ->where('tool_slug', 'atlas_code_intelligence')
            ->latest('id')
            ->first();

        $this->assertInstanceOf(AtlasToolRun::class, $run);
        $this->assertSame('audit', data_get($run->metadata_json, 'operation'));
        $this->assertSame('atlas-server', data_get($run->metadata_json, 'workspace_id'));
    }

    private function bootCodeIntelligenceTables(): void
    {
        foreach ([
            'atlas_engineering_doc_links',
            'atlas_engineering_code_symbols',
            'atlas_engineering_code_modules',
            'atlas_engineering_code_file_snapshots',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        (require database_path('migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_05_21_211200_create_atlas_engineering_code_file_snapshots_table.php'))->up();
        (require database_path('migrations/2026_06_08_233000_add_workspace_id_to_code_intelligence_tables.php'))->up();
    }

    private function bootToolRuntimeTables(): void
    {
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 32)->primary();
            $table->string('schema_version', 40)->default('atlas.ledger_event.v1');
            $table->string('tenant_id', 120)->index();
            $table->string('operator_id', 120)->index();
            $table->string('envelope_id', 80)->index();
            $table->string('receipt_id', 80)->nullable()->index();
            $table->uuid('trace_id')->nullable()->index();
            $table->string('correlation_id', 120)->index();
            $table->string('causation_id', 80)->nullable()->index();
            $table->string('event_type', 80)->index();
            $table->string('emitter_stage', 120)->index();
            $table->string('emitter_version', 80);
            $table->json('payload');
            $table->string('payload_hash', 64)->index();
            $table->timestampTz('occurred_at')->index();
            $table->timestampsTz();
        });

        Schema::create('atlas_tool_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('type')->default('validator');
            $table->string('category');
            $table->text('description')->nullable();
            $table->string('default_failure_policy')->default('advisory');
            $table->string('status')->default('active');
            $table->string('execution_tier')->default('T1');
            $table->string('expected_cost')->default('local_fast');
            $table->string('default_trigger')->default('manual_or_policy');
            $table->string('authority_role')->default('primary');
            $table->string('authority_group')->nullable();
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
