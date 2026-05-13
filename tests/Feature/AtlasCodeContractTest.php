<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Feature contract tests for the Atlas Desktop production-readiness surface
 * (ADR-0002). Each test asserts the exact response shape the desktop bridge
 * relies on so a backend regression breaks loud at CI time.
 *
 * Database is bootstrapped ad-hoc (no RefreshDatabase) because the full
 * Postgres migration set isn't portable to SQLite :memory:.
 */
class AtlasCodeContractTest extends TestCase
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

    public function test_boot_endpoint_returns_canonical_shape(): void
    {
        $response = $this->withHeaders($this->headers())->getJson('/atlas-code/boot');
        $response->assertOk()
            ->assertJsonStructure([
                'schema_version',
                'generated_at',
                'status',
                'kernel' => [
                    'service',
                    'version',
                    'env',
                    'php_version',
                    'db_connected',
                    'storage_path',
                    'storage_writable',
                    'ts',
                ],
                'providers' => ['available', 'degraded', 'source'],
                'mcp' => ['server', 'protocol_version', 'http_enabled', 'status', 'transport'],
                'cartography' => ['repo_root', 'repo_readable', 'vault_root', 'vault_readable'],
                'workspace' => ['cwd', 'is_git'],
                'queue' => ['connection', 'pending', 'failed'],
            ]);
    }

    public function test_mcp_status_endpoint_returns_pill_shape(): void
    {
        $response = $this->withHeaders($this->headers())->getJson('/atlas-code/mcp/status');
        $response->assertOk()
            ->assertJsonStructure([
                'server',
                'status',
                'protocol_version',
                'transport',
                'http_enabled',
                'tools_count',
                'tools',
                'docs_indexed',
                'symbols_indexed',
                'freshness' => ['indexed_at', 'drift'],
            ]);
        $this->assertContains($response->json('status'), ['active', 'degraded', 'disabled']);
    }

    public function test_cartography_graph_endpoint_returns_canonical_shape(): void
    {
        $response = $this->withHeaders($this->headers())->getJson('/atlas-cartography/graph');
        $response->assertOk()
            ->assertJsonStructure([
                'schema_version',
                'generated_at',
                'sources' => ['repo_docs_path', 'obsidian_vault_path', 'repo_indexed_count', 'vault_indexed_count'],
                'audit' => ['pieces_found', 'pieces_missing'],
                'universe',
                'views' => [
                    'atlas-ai-kernel' => ['ribbon', 'pipeline', 'lanes', 'connections'],
                ],
            ]);
    }

    public function test_cartography_recent_changes_endpoint_returns_envelope(): void
    {
        $response = $this->withHeaders($this->headers())->getJson('/atlas-cartography/recent-changes');
        $response->assertOk()
            ->assertJsonStructure([
                'generated_at',
                'sources' => ['git_commits', 'repo_mtime', 'vault_mtime'],
                'changes',
            ]);
    }

    public function test_works_index_endpoint_returns_data_meta_envelope(): void
    {
        $response = $this->withHeaders($this->headers())->getJson('/atlas-code/works');
        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'meta' => ['total'],
            ]);
    }
}
