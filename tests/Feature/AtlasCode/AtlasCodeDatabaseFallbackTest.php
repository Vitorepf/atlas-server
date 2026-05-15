<?php

declare(strict_types=1);

namespace Tests\Feature\AtlasCode;

use App\Models\AtlasCodeObservedSession;
use App\Models\AtlasCodeWorkPacket;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Boots both `atlas_code_work_packets` and `atlas_code_observed_sessions`
 * tables ad-hoc and runs the canonical workflow via DB persistence —
 * proves the dual-storage fallback works and that the public service API
 * is identical regardless of backend.
 */
class AtlasCodeDatabaseFallbackTest extends TestCase
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
                $t->text('goal')->nullable();
                $t->string('priority')->default('medium');
                $t->json('metadata')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }

        // Boot the canonical Atlas Code tables as the production migrations
        // would create them. We use Schema::create directly because the
        // production migration is idempotent (skips if hasTable).
        if (! Schema::hasTable('atlas_code_work_packets')) {
            Schema::create('atlas_code_work_packets', function (Blueprint $t) {
                $t->string('id', 64)->primary();
                $t->uuid('obra_id')->index();
                $t->string('obra_title', 240)->nullable();
                $t->string('workspace_slug', 80)->nullable();
                $t->string('workspace_path', 2048)->nullable();
                $t->string('status', 40)->default('draft');
                $t->text('objective');
                $t->text('context_summary')->nullable();
                $t->json('allowed_files')->nullable();
                $t->json('forbidden_files')->nullable();
                $t->json('interfaces')->nullable();
                $t->json('constraints')->nullable();
                $t->json('acceptance_criteria')->nullable();
                $t->json('verification_commands')->nullable();
                $t->json('evidence_required')->nullable();
                $t->text('report_format')->nullable();
                $t->text('stop_rule')->nullable();
                $t->string('role_slot', 80)->default('implementation_lead');
                $t->string('risk_band', 40)->default('medium');
                $t->string('task_category', 80)->default('feature');
                $t->timestamp('exported_at')->nullable();
                $t->string('packet_md_path', 2048)->nullable();
                $t->string('prompt_hash', 80)->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('atlas_code_observed_sessions')) {
            Schema::create('atlas_code_observed_sessions', function (Blueprint $t) {
                $t->string('id', 64)->primary();
                $t->uuid('obra_id')->index();
                $t->string('obra_title', 240)->nullable();
                $t->string('work_packet_id', 64)->index();
                $t->string('provider_id', 80);
                $t->string('provider_name', 160)->nullable();
                $t->string('provider_family', 80)->nullable();
                $t->string('invocation_mode', 80)->default('interactive_observed');
                $t->string('role_slot', 80)->nullable();
                $t->string('workspace_slug', 80)->nullable();
                $t->string('workspace_path', 2048)->nullable();
                $t->string('packet_md_path', 2048)->nullable();
                $t->string('packet_md_status', 40)->default('unknown');
                $t->text('packet_md_excerpt')->nullable();
                $t->text('prompt');
                $t->string('prompt_hash', 80)->nullable();
                $t->string('terminal_command_hint', 240)->nullable();
                $t->string('state', 40)->default('waiting_operator');
                $t->json('state_history')->nullable();
                $t->timestamp('operator_opened_terminal_at')->nullable();
                $t->timestamp('operator_marked_running_at')->nullable();
                $t->timestamp('result_imported_at')->nullable();
                $t->text('report_text')->nullable();
                $t->json('report_files')->nullable();
                $t->text('diff_excerpt')->nullable();
                $t->string('diff_hash', 80)->nullable();
                $t->json('gates')->nullable();
                $t->json('gates_summary')->nullable();
                $t->timestamp('gates_evaluated_at')->nullable();
                $t->json('scope_guard')->nullable();
                $t->json('git_snapshot')->nullable();
                $t->json('human_decision')->nullable();
                $t->string('decision_signature', 240)->nullable();
                $t->string('decision_public_key', 240)->nullable();
                $t->string('decision_signing_status', 40)->nullable();
                $t->timestamp('decision_signed_at')->nullable();
                $t->string('blocker_reason', 1000)->nullable();
                $t->json('governance')->nullable();
                $t->timestamps();
            });
        }

        // Clean both backends. We use delete() instead of truncate() for
        // SQLite compatibility (truncate complains about sqlite_sequence).
        DB::table('atlas_code_work_packets')->delete();
        DB::table('atlas_code_observed_sessions')->delete();
    }

    public function test_packets_and_sessions_persist_to_database_when_tables_present(): void
    {
        $obraId = (string) Str::uuid();
        DB::table('atlas_projects')->insert([
            'id' => $obraId,
            'title' => 'Obra DB',
            'description' => 'intent',
            'status' => 'active',
            'domain' => 'atlas',
            'goal' => 'persist via DB',
            'priority' => 'medium',
            'metadata' => json_encode([
                'origin' => 'atlas-code',
                'workspace_slug' => 'atlas',
                'workspace_path' => storage_path('app/test-db-workspace'),
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\File::ensureDirectoryExists(storage_path('app/test-db-workspace'));

        $packet = $this->withHeaders($this->headers())->postJson("/atlas-code/works/{$obraId}/work-packets", [
            'objective' => 'Persist via DB',
            'acceptance_criteria' => ['critério 1'],
            'allowed_files' => ['src/x.ts'],
        ])->json('packet');

        $this->assertNotNull($packet['id']);
        $this->assertSame(1, AtlasCodeWorkPacket::query()->where('id', $packet['id'])->count());

        $session = $this->withHeaders($this->headers())->postJson("/atlas-code/works/{$obraId}/observed-sessions", [
            'work_packet_id' => $packet['id'],
            'provider_id' => 'claude_code',
        ])->json('session');

        $this->assertNotNull($session['id']);
        $this->assertSame(1, AtlasCodeObservedSession::query()->where('id', $session['id'])->count());

        // Round-trip through the public read endpoint (which goes through
        // the same service layer) and verify the shape is intact.
        $fetched = $this->withHeaders($this->headers())
            ->getJson("/atlas-code/works/{$obraId}/observed-sessions/{$session['id']}")
            ->assertOk()
            ->json('session');
        $this->assertSame($session['id'], $fetched['id']);
        $this->assertSame('waiting_operator', $fetched['state']);
        $this->assertSame('atlas.code.observed_session.v1', $fetched['schema_version']);

        // Cleanup
        @rmdir(storage_path('app/test-db-workspace'));
    }
}
