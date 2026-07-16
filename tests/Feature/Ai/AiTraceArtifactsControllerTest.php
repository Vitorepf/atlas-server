<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AiTrace;
use App\Models\AtlasEngineeringControlResult;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasEngineeringTestRun;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AiTraceArtifactsControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
        config()->set('atlas.token', 'testing-atlas-token-with-enough-length');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_engineering_control_results');
        Schema::dropIfExists('atlas_engineering_test_runs');
        Schema::dropIfExists('atlas_engineering_runs');
        Schema::dropIfExists('ai_traces');

        File::deleteDirectory(storage_path('app/engineering-runs'));
        File::deleteDirectory(storage_path('app/atlas-test-outside-artifacts'));

        parent::tearDown();
    }

    public function test_available_manifest_projects_provider_safe_artifacts_for_the_trace_run(): void
    {
        $trace = $this->trace('trace-artifacts-available');
        $run = $this->engineeringRun($trace->id);
        $path = $this->writeRunArtifact($run, 'artifacts/docs/relatorio-final.md', "# Prova\nconteudo real\n");
        AtlasEngineeringTestRun::create([
            'engineering_run_id' => $run->id,
            'command' => 'swift run AtlasCoreChecks',
            'status' => 'passed',
            'artifact_path' => dirname($path, 2),
        ]);

        $response = $this->withAtlasToken()
            ->getJson("/ai/interactions/{$trace->id}/artifacts");

        $response->assertOk()
            ->assertJsonPath('schema_version', 'atlas.trace_artifacts.v1')
            ->assertJsonPath('state', 'available')
            ->assertJsonPath('run.workspace_label', 'atlas-native')
            ->assertJsonPath('items.0.kind', 'markdown')
            ->assertJsonPath('items.0.name', 'relatorio-final.md')
            ->assertJsonPath('items.0.relative_dir', 'docs')
            ->assertJsonPath('items.0.byte_size', strlen("# Prova\nconteudo real\n"))
            ->assertJsonPath('items.0.sha256', hash_file('sha256', $path))
            ->assertJsonMissingPath('run.engineering_run_id');

        $this->assertStringNotContainsString(storage_path(), $response->getContent());
        $this->assertStringNotContainsString('artifact_path', $response->getContent());
    }

    public function test_unavailable_when_trace_has_no_workspace(): void
    {
        $trace = $this->trace('trace-artifacts-no-workspace');
        $this->engineeringRun($trace->id, workspaceLabel: null);

        $response = $this->withAtlasToken()
            ->getJson("/ai/interactions/{$trace->id}/artifacts");

        $response->assertOk()
            ->assertJsonPath('schema_version', 'atlas.trace_artifacts.v1')
            ->assertJsonPath('state', 'unavailable')
            ->assertJsonPath('reason', 'no_workspace')
            ->assertJsonMissingPath('run')
            ->assertJsonMissingPath('items');
    }

    public function test_unavailable_when_trace_has_no_run(): void
    {
        $trace = $this->trace('trace-artifacts-no-run');

        $response = $this->withAtlasToken()
            ->getJson("/ai/interactions/{$trace->id}/artifacts");

        $response->assertOk()
            ->assertJsonPath('state', 'unavailable')
            ->assertJsonPath('reason', 'no_run')
            ->assertJsonMissingPath('run')
            ->assertJsonMissingPath('items');
    }

    public function test_unavailable_when_trace_has_multiple_runs(): void
    {
        $trace = $this->trace('trace-artifacts-multiple-runs');
        $this->engineeringRun($trace->id);
        $this->engineeringRun($trace->id);

        $response = $this->withAtlasToken()
            ->getJson("/ai/interactions/{$trace->id}/artifacts");

        $response->assertOk()
            ->assertJsonPath('state', 'unavailable')
            ->assertJsonPath('reason', 'multiple_runs')
            ->assertJsonMissingPath('run')
            ->assertJsonMissingPath('items');
    }

    public function test_content_returns_bytes_with_matching_sha_header(): void
    {
        $trace = $this->trace('trace-artifacts-content');
        $run = $this->engineeringRun($trace->id);
        $path = $this->writeRunArtifact($run, 'artifacts/docs/nota.md', "canary de prova\n");
        AtlasEngineeringTestRun::create([
            'engineering_run_id' => $run->id,
            'command' => 'swift run AtlasCoreChecks',
            'status' => 'passed',
            'artifact_path' => dirname($path, 2),
        ]);
        $artifactId = $this->artifactIdFor($run, 'test', 'docs/nota.md');

        $response = $this->withAtlasToken()
            ->get("/ai/interactions/{$trace->id}/artifacts/{$artifactId}/content?max_bytes=1024");

        $response->assertOk();
        $this->assertSame("canary de prova\n", $response->getContent());
        $this->assertSame(hash_file('sha256', $path), $response->headers->get('X-Atlas-Sha256'));
        $this->assertStringContainsString('text/markdown', (string) $response->headers->get('Content-Type'));
    }

    public function test_content_refuses_artifacts_larger_than_the_requested_cap(): void
    {
        $trace = $this->trace('trace-artifacts-too-large');
        $run = $this->engineeringRun($trace->id);
        $path = $this->writeRunArtifact($run, 'artifacts/logs/big.txt', '0123456789');
        AtlasEngineeringTestRun::create([
            'engineering_run_id' => $run->id,
            'command' => 'make build',
            'status' => 'failed',
            'artifact_path' => dirname($path, 2),
        ]);
        $artifactId = $this->artifactIdFor($run, 'test', 'logs/big.txt');

        $response = $this->withAtlasToken()
            ->getJson("/ai/interactions/{$trace->id}/artifacts/{$artifactId}/content?max_bytes=5");

        $response->assertStatus(413)
            ->assertJsonPath('error', 'too_large')
            ->assertJsonPath('byte_size', 10);
    }

    public function test_content_404s_when_artifact_id_belongs_to_another_trace(): void
    {
        $trace = $this->trace('trace-artifacts-owner');
        $run = $this->engineeringRun($trace->id);
        $path = $this->writeRunArtifact($run, 'artifacts/docs/owned.md', 'owned');
        AtlasEngineeringTestRun::create([
            'engineering_run_id' => $run->id,
            'command' => 'make build',
            'status' => 'passed',
            'artifact_path' => dirname($path, 2),
        ]);
        $otherTrace = $this->trace('trace-artifacts-other');
        $this->engineeringRun($otherTrace->id);
        $artifactId = $this->artifactIdFor($run, 'test', 'docs/owned.md');

        $response = $this->withAtlasToken()
            ->getJson("/ai/interactions/{$otherTrace->id}/artifacts/{$artifactId}/content");

        $response->assertNotFound();
    }

    public function test_path_allowlist_never_exposes_absolute_parent_or_deep_paths(): void
    {
        $trace = $this->trace('trace-artifacts-paths');
        $run = $this->engineeringRun($trace->id);
        $deep = $this->writeRunArtifact($run, 'artifacts/deep/one/two/three/segredo.md', 'safe');
        $outsideRoot = storage_path('app/atlas-test-outside-artifacts');
        File::ensureDirectoryExists($outsideRoot);
        $outside = $outsideRoot.'/leak.md';
        File::put($outside, 'leak');
        AtlasEngineeringTestRun::create([
            'engineering_run_id' => $run->id,
            'command' => 'make build',
            'status' => 'passed',
            'artifact_path' => dirname($deep, 5),
        ]);
        AtlasEngineeringControlResult::create([
            'engineering_run_id' => $run->id,
            'control_slug' => 'unsafe-control',
            'status' => 'failed',
            'signal_summary' => 'outside path',
            'artifact_path' => $outside,
        ]);

        $response = $this->withAtlasToken()
            ->getJson("/ai/interactions/{$trace->id}/artifacts");

        $response->assertOk()
            ->assertJsonPath('items.0.name', 'segredo.md')
            ->assertJsonPath('items.0.relative_dir', 'deep/one');
        $payload = $response->getContent();
        $this->assertStringNotContainsString('two/three', $payload);
        $this->assertStringNotContainsString('leak.md', $payload);
        $this->assertStringNotContainsString($outsideRoot, $payload);
        $this->assertStringNotContainsString('..', $payload);
    }

    private function trace(string $key): AiTrace
    {
        return AiTrace::create([
            'trace_key' => $key,
            'status' => 'completed',
            'operator_input' => 'crie artefato',
        ]);
    }

    private function engineeringRun(string $traceId, ?string $workspaceLabel = 'atlas-native'): AtlasEngineeringRun
    {
        return AtlasEngineeringRun::create([
            'trace_id' => $traceId,
            'workspace_path_hash' => $workspaceLabel === null ? null : hash('sha256', $traceId),
            'workspace_label' => $workspaceLabel,
            'provider_strategy_json' => [],
            'status' => 'passed',
            'decision' => 'resolved',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'metadata' => [],
        ]);
    }

    private function writeRunArtifact(AtlasEngineeringRun $run, string $relativePath, string $content): string
    {
        $path = storage_path('app/engineering-runs/'.$run->id.'/'.$relativePath);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $content);

        return $path;
    }

    private function artifactIdFor(AtlasEngineeringRun $run, string $source, string $relativePath): string
    {
        return 'art_'.substr(hash('sha256', $run->id.'|'.$source.'|'.$relativePath), 0, 16);
    }

    private function withAtlasToken(): self
    {
        return $this->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length');
    }

    private function createTables(): void
    {
        Schema::dropIfExists('atlas_engineering_control_results');
        Schema::dropIfExists('atlas_engineering_test_runs');
        Schema::dropIfExists('atlas_engineering_runs');
        Schema::dropIfExists('ai_traces');

        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('trace_key')->nullable();
            $table->string('status')->default('queued');
            $table->text('operator_input')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('atlas_engineering_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->string('workspace_path_hash', 64)->nullable();
            $table->string('workspace_label', 180)->nullable();
            $table->json('provider_strategy_json')->default('{}');
            $table->string('status', 32)->default('queued');
            $table->string('decision', 32)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });
        Schema::create('atlas_engineering_test_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('engineering_run_id');
            $table->string('command', 500)->nullable();
            $table->integer('exit_code')->nullable();
            $table->string('status', 32);
            $table->integer('duration_ms')->default(0);
            $table->text('artifact_path')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });
        Schema::create('atlas_engineering_control_results', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('engineering_run_id');
            $table->string('control_slug', 120);
            $table->string('status', 32);
            $table->text('signal_summary');
            $table->integer('duration_ms')->default(0);
            $table->text('artifact_path')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });
    }
}
