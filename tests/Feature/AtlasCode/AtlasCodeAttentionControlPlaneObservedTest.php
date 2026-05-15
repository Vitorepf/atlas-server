<?php

declare(strict_types=1);

namespace Tests\Feature\AtlasCode;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Meta 4 · Attention Control Plane integration test.
 *
 * Boots the minimum atlas_projects schema, creates an Obra + observed
 * sessions in each canonical state, hits `GET /atlas-code/attention`, and
 * asserts the read-model normalizes ONLY the states that need a human
 * decision. Sessions that are running normally do NOT show up.
 */
class AtlasCodeAttentionControlPlaneObservedTest extends TestCase
{
    private string $workspace = '';

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
        $this->workspace = sys_get_temp_dir().'/atlas-test-attention-'.Str::random(8);
        File::ensureDirectoryExists($this->workspace);
        // Force filesystem mode for observed sessions to keep the test
        // hermetic (no DB rows leaking across tests).
        $base = sys_get_temp_dir().'/atlas-attention-sessions-'.Str::random(8);
        File::ensureDirectoryExists($base);

        if (! Schema::hasTable('atlas_projects')) {
            Schema::create('atlas_projects', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('title')->nullable();
                $t->text('description')->nullable();
                $t->string('status')->default('active');
                $t->string('domain')->default('atlas');
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

        // Purge any previously persisted sessions so tests are hermetic.
        $sessionsBase = storage_path('app/atlas-code/observed-sessions');
        if (is_dir($sessionsBase)) {
            File::deleteDirectory($sessionsBase);
        }
        $packetsBase = storage_path('app/atlas-code/work-packets');
        if (is_dir($packetsBase)) {
            File::deleteDirectory($packetsBase);
        }
    }

    protected function tearDown(): void
    {
        if ($this->workspace !== '' && is_dir($this->workspace)) {
            File::deleteDirectory($this->workspace);
        }
        parent::tearDown();
    }

    private function createObra(): string
    {
        $obraId = (string) Str::uuid();
        DB::table('atlas_projects')->insert([
            'id' => $obraId,
            'title' => 'Obra Meta 4',
            'description' => 'intent',
            'status' => 'active',
            'domain' => 'atlas',
            'goal' => 'attention test',
            'priority' => 'medium',
            'metadata' => json_encode([
                'origin' => 'atlas-code',
                'workspace_slug' => 'atlas',
                'workspace_path' => $this->workspace,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $obraId;
    }

    public function test_attention_endpoint_returns_canonical_envelope(): void
    {
        $response = $this->withHeaders($this->headers())->getJson('/atlas-code/attention');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'schema_version',
                'generated_at',
                'queue_items',
                'resolved_recently',
                'obra_summary',
                'health' => [
                    'total_items',
                    'returned_items',
                    'blocked_obras',
                    'waiting_human_count',
                ],
                'allowed_actions_vocabulary',
                'external_provider_call',
                'completion_claim_promoted',
                'review_gate_preserved',
            ])
            ->assertJsonPath('schema_version', 'atlas.code.attention_control_plane.v1')
            ->assertJsonPath('external_provider_call', false)
            ->assertJsonPath('completion_claim_promoted', false)
            ->assertJsonPath('review_gate_preserved', true);
    }

    public function test_observed_session_waiting_operator_appears_as_runtime_approval_item(): void
    {
        $obraId = $this->createObra();
        // Create packet + session via API. Service writes packet.md to workspace.
        $packetId = $this->withHeaders($this->headers())->postJson("/atlas-code/works/{$obraId}/work-packets", [
            'objective' => 'attention test',
            'acceptance_criteria' => ['ok'],
        ])->json('packet.id');
        $sessionId = $this->withHeaders($this->headers())->postJson("/atlas-code/works/{$obraId}/observed-sessions", [
            'work_packet_id' => $packetId,
            'provider_id' => 'claude_code',
        ])->json('session.id');

        $attention = $this->withHeaders($this->headers())->getJson('/atlas-code/attention?workspace=atlas');
        $attention->assertOk();

        $items = collect($attention->json('queue_items'));
        $matching = $items->first(function (array $it) use ($sessionId): bool {
            $tr = (array) ($it['blocker_translation'] ?? []);
            return ($tr['source'] ?? null) === 'observed_session' && ($tr['session_id'] ?? null) === $sessionId;
        });
        $this->assertNotNull($matching, 'observed_session in waiting_operator should produce an attention item');
        $this->assertSame('runtime_approval', $matching['kind']);
        $this->assertContains('approve_runtime', $matching['allowed_actions']);
        $this->assertSame('atlas_code', $matching['target_surface']);
        $this->assertSame('observed_session', $matching['blocker_translation']['source']);
    }

    public function test_observed_session_running_does_not_produce_attention_item(): void
    {
        $obraId = $this->createObra();
        $packetId = $this->withHeaders($this->headers())->postJson("/atlas-code/works/{$obraId}/work-packets", [
            'objective' => 'running test',
            'acceptance_criteria' => ['ok'],
        ])->json('packet.id');
        $sessionId = $this->withHeaders($this->headers())->postJson("/atlas-code/works/{$obraId}/observed-sessions", [
            'work_packet_id' => $packetId,
            'provider_id' => 'claude_code',
        ])->json('session.id');
        // Transition to running (operator started provider).
        $this->withHeaders($this->headers())->postJson(
            "/atlas-code/works/{$obraId}/observed-sessions/{$sessionId}/state",
            ['state' => 'running']
        )->assertOk();

        $attention = $this->withHeaders($this->headers())->getJson('/atlas-code/attention?workspace=atlas');
        $items = collect($attention->json('queue_items'));
        $matching = $items->first(function (array $it) use ($sessionId): bool {
            $tr = (array) ($it['blocker_translation'] ?? []);
            return ($tr['source'] ?? null) === 'observed_session' && ($tr['session_id'] ?? null) === $sessionId;
        });
        $this->assertNull($matching, 'running observed sessions should NOT generate attention');
    }

    public function test_observed_session_review_required_appears_as_review_needed(): void
    {
        $obraId = $this->createObra();
        $packetId = $this->withHeaders($this->headers())->postJson("/atlas-code/works/{$obraId}/work-packets", [
            'objective' => 'review test',
            'acceptance_criteria' => ['ok'],
            'allowed_files' => ['src/x.ts'],
        ])->json('packet.id');
        $sessionId = $this->withHeaders($this->headers())->postJson("/atlas-code/works/{$obraId}/observed-sessions", [
            'work_packet_id' => $packetId,
            'provider_id' => 'claude_code',
        ])->json('session.id');
        $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$obraId}/observed-sessions/{$sessionId}/state", ['state' => 'running'])
            ->assertOk();
        $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$obraId}/observed-sessions/{$sessionId}/state", ['state' => 'waiting_result_import'])
            ->assertOk();
        $this->withHeaders($this->headers())->postJson(
            "/atlas-code/works/{$obraId}/observed-sessions/{$sessionId}/import",
            ['report_text' => 'done', 'files' => ['src/x.ts']]
        )->assertOk()->assertJsonPath('session.state', 'review_required');

        $attention = $this->withHeaders($this->headers())->getJson('/atlas-code/attention?workspace=atlas');
        $items = collect($attention->json('queue_items'));
        $matching = $items->first(function (array $it) use ($sessionId): bool {
            $tr = (array) ($it['blocker_translation'] ?? []);
            return ($tr['source'] ?? null) === 'observed_session' && ($tr['session_id'] ?? null) === $sessionId;
        });
        $this->assertNotNull($matching);
        $this->assertSame('review_needed', $matching['kind']);
        $this->assertContains('approve', $matching['allowed_actions']);
        $this->assertContains('reject', $matching['allowed_actions']);
        $this->assertContains('request_repair', $matching['allowed_actions']);
    }

    public function test_observed_session_blocked_appears_as_blocked_attention_with_reason(): void
    {
        $obraId = $this->createObra();
        $packetId = $this->withHeaders($this->headers())->postJson("/atlas-code/works/{$obraId}/work-packets", [
            'objective' => 'blocked test',
            'acceptance_criteria' => ['ok'],
            'allowed_files' => ['src/Checkout/**'],
        ])->json('packet.id');
        $sessionId = $this->withHeaders($this->headers())->postJson("/atlas-code/works/{$obraId}/observed-sessions", [
            'work_packet_id' => $packetId,
            'provider_id' => 'claude_code',
        ])->json('session.id');
        $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$obraId}/observed-sessions/{$sessionId}/state", ['state' => 'running'])
            ->assertOk();
        $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$obraId}/observed-sessions/{$sessionId}/state", ['state' => 'waiting_result_import'])
            ->assertOk();
        // Import with file outside allowed → scope guard triggers blocked state.
        $this->withHeaders($this->headers())->postJson(
            "/atlas-code/works/{$obraId}/observed-sessions/{$sessionId}/import",
            ['report_text' => 'changed auth too', 'files' => ['src/Auth/login.ts']]
        )->assertOk()->assertJsonPath('session.state', 'blocked');

        $attention = $this->withHeaders($this->headers())->getJson('/atlas-code/attention?workspace=atlas');
        $items = collect($attention->json('queue_items'));
        $matching = $items->first(function (array $it) use ($sessionId): bool {
            $tr = (array) ($it['blocker_translation'] ?? []);
            return ($tr['source'] ?? null) === 'observed_session' && ($tr['session_id'] ?? null) === $sessionId;
        });
        $this->assertNotNull($matching);
        $this->assertSame('blocked_attention', $matching['kind']);
        $this->assertSame('high', $matching['severity']);
        $this->assertStringContainsString('scope_violation_detected', (string) $matching['why_now']);
    }

    public function test_empty_state_when_no_obra_no_session(): void
    {
        // Fresh table, no obra. Attention must return stable empty envelope.
        $attention = $this->withHeaders($this->headers())->getJson('/atlas-code/attention?workspace=atlas');
        $attention
            ->assertOk()
            ->assertJsonPath('queue_items', [])
            ->assertJsonPath('active_focus_item', null)
            ->assertJsonPath('health.total_items', 0);
    }
}
