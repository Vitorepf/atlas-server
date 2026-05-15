<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * End-to-end contract test for the Interactive Observed Provider Workflow.
 *
 * Canon:
 *   - docs/engineering-knowledge-base/atlas-code-interactive-observed-provider-workflow-v1.md
 *   - docs/engineering-knowledge-base/atlas-code-adaptive-provider-operating-room-v1.md
 *   - docs/engineering-knowledge-base/atlas-claude-code-subscription-governance-v1.md
 *
 * Flow under test:
 *   1. Governance is subscription-only and reports headless as blocked.
 *   2. Create Obra → create Work Packet → preview export.
 *   3. Open Observed Session → packet markdown is written to workspace_path
 *      when writable, prompt copy-safe is returned, state=waiting_operator.
 *   4. Operator marks the session running, then waiting_result_import.
 *   5. Operator imports report+diff → state auto-transitions to review_required.
 *   6. Human accepts → state=accepted, completion law satisfied.
 *
 * The test enforces:
 *   - completion cannot be claimed without imported report
 *   - illegal state transitions are rejected
 *   - operating room aggregates packet+sessions and surfaces the right
 *     attention item per stage.
 */
class AtlasCodeInteractiveObservedProviderTest extends TestCase
{
    private string $workspacePath = '';

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

        // Isolated workspace path so the export writes packet.md somewhere
        // safe and we can assert it.
        $this->workspacePath = storage_path('app/test-workspaces/'.Str::random(8));
        File::ensureDirectoryExists($this->workspacePath);

        // Clean filesystem state to avoid bleed across tests.
        $packetsBase = storage_path('app/atlas-code/work-packets');
        $sessionsBase = storage_path('app/atlas-code/observed-sessions');
        if (is_dir($packetsBase)) {
            File::deleteDirectory($packetsBase);
        }
        if (is_dir($sessionsBase)) {
            File::deleteDirectory($sessionsBase);
        }
    }

    protected function tearDown(): void
    {
        if ($this->workspacePath !== '' && is_dir($this->workspacePath)) {
            File::deleteDirectory($this->workspacePath);
        }
        parent::tearDown();
    }

    private function createObra(): string
    {
        $id = (string) Str::uuid();
        DB::table('atlas_projects')->insert([
            'id' => $id,
            'title' => 'Obra observada · Atlas Code',
            'description' => 'intent observed test',
            'status' => 'active',
            'domain' => 'atlas',
            'goal' => 'governar provider interativo',
            'priority' => 'medium',
            'metadata' => json_encode([
                'origin' => 'atlas-code',
                'workspace_slug' => 'atlas',
                'workspace_name' => 'Atlas',
                'workspace_path' => $this->workspacePath,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $id;
    }

    public function test_provider_governance_endpoint_reports_configurable_policy(): void
    {
        $response = $this->withHeaders($this->headers())->getJson('/atlas-code/providers/governance');

        $response
            ->assertOk()
            ->assertJsonPath('governance.schema_version', 'atlas.code.provider_governance.v2')
            // Policy defaults: test_only keeps Rivals/tests alive but blocks productive headless.
            ->assertJsonPath('governance.claude_programmatic_policy', 'test_only')
            ->assertJsonPath('governance.allow_rivals_programmatic', true)
            ->assertJsonPath('governance.allow_programmatic_tests', true)
            ->assertJsonPath('governance.allow_productive_headless', false)
            ->assertJsonPath('governance.allow_api_payg', false)
            ->assertJsonPath('governance.productive_headless_allowed', false)
            ->assertJsonPath('governance.programmatic_invocation_allowed', true)
            ->assertJsonPath('governance.operator_presence_required', true);

        // Per-label table is present and exposes canonical labels.
        $labels = (array) $response->json('governance.allowed_labels');
        $this->assertContains('rivals_baseline', $labels);
        $this->assertContains('provider_integration_test', $labels);
        $this->assertContains('benchmark', $labels);
        $this->assertContains('approved_experiment', $labels);
        $this->assertContains('productive_headless', $labels);

        // Rivals and tests are permitted by the default policy; productive_headless is not.
        $this->assertTrue($response->json('governance.label_decisions.rivals_baseline.allowed'));
        $this->assertTrue($response->json('governance.label_decisions.provider_integration_test.allowed'));
        $this->assertFalse($response->json('governance.label_decisions.productive_headless.allowed'));
        $this->assertSame(
            'productive_headless_blocked_by_default',
            $response->json('governance.label_decisions.productive_headless.reason')
        );

        $providers = collect($response->json('governance.providers'))->pluck('id')->all();
        $this->assertContains('claude_code', $providers);
        $this->assertContains('codex_cli', $providers);
        $this->assertContains('gemini_cli', $providers);
        $this->assertContains('manual_external', $providers);
    }

    public function test_governance_policy_blocks_everything_under_blocked_state(): void
    {
        config()->set('atlas_code_provider_governance.claude_programmatic_policy', 'blocked');
        $response = $this->withHeaders($this->headers())->getJson('/atlas-code/providers/governance');

        $response
            ->assertOk()
            ->assertJsonPath('governance.effective_policy', 'blocked')
            ->assertJsonPath('governance.full_block', true)
            ->assertJsonPath('governance.interactive_only', true)
            ->assertJsonPath('governance.programmatic_invocation_allowed', false);

        // Every label is blocked under full_block.
        $decisions = (array) $response->json('governance.label_decisions');
        foreach (['rivals_baseline', 'provider_integration_test', 'benchmark', 'productive_headless'] as $label) {
            $this->assertFalse(
                $decisions[$label]['allowed'] ?? true,
                "Label {$label} should be blocked under full_block policy."
            );
            $this->assertSame('policy_blocked', $decisions[$label]['reason'] ?? '');
        }
    }

    public function test_governance_policy_interactive_only_blocks_programmatic_keeps_observed(): void
    {
        config()->set('atlas_code_provider_governance.claude_programmatic_policy', 'interactive_only');
        $response = $this->withHeaders($this->headers())->getJson('/atlas-code/providers/governance');

        $response
            ->assertOk()
            ->assertJsonPath('governance.effective_policy', 'interactive_only')
            ->assertJsonPath('governance.interactive_only', true)
            ->assertJsonPath('governance.full_block', false)
            ->assertJsonPath('governance.programmatic_invocation_allowed', false);

        // Interactive observed sessions still register the provider list
        // (UI uses them to open Claude observed even when programmatic is off).
        $providers = collect($response->json('governance.providers'))->pluck('id')->all();
        $this->assertContains('claude_code', $providers);
    }

    public function test_create_work_packet_persists_and_returns_canonical_shape(): void
    {
        $obraId = $this->createObra();

        $response = $this->withHeaders($this->headers())->postJson("/atlas-code/works/{$obraId}/work-packets", [
            'objective' => 'Trocar copy do checkout em Blackink',
            'context_summary' => 'Build com Tailwind. Componente Checkout.tsx.',
            'allowed_files' => ['src/Checkout/Checkout.tsx'],
            'forbidden_files' => ['src/Auth/**'],
            'acceptance_criteria' => ['Copy reflete novo texto', 'Testes verdes'],
            'verification_commands' => ['pnpm test --filter Checkout'],
            'role_slot' => 'implementation_lead',
            'risk_band' => 'low',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('packet.schema_version', 'atlas.code.work_packet.v1')
            ->assertJsonPath('packet.obra_id', $obraId)
            ->assertJsonPath('packet.status', 'ready')
            ->assertJsonPath('packet.role_slot', 'implementation_lead');

        $this->assertStringContainsString($obraId, $response->json('packet.obra_id'));
    }

    public function test_open_observed_session_writes_packet_md_and_returns_prompt(): void
    {
        $obraId = $this->createObra();

        $packetResponse = $this->withHeaders($this->headers())->postJson("/atlas-code/works/{$obraId}/work-packets", [
            'objective' => 'Refatorar handler de retry no provider invocation',
            'context_summary' => 'Retry policy precisa ser idempotente.',
            'allowed_files' => ['app/Services/Ai/Programming/ProviderInvocation.php'],
            'forbidden_files' => ['database/migrations/**'],
            'acceptance_criteria' => ['Retry idempotente', 'Teste cobrindo 3 cenários'],
            'verification_commands' => ['php artisan test --filter=ProviderInvocation'],
        ]);
        $packetId = $packetResponse->json('packet.id');

        $sessionResponse = $this->withHeaders($this->headers())->postJson("/atlas-code/works/{$obraId}/observed-sessions", [
            'work_packet_id' => $packetId,
            'provider_id' => 'claude_code',
        ]);

        $sessionResponse
            ->assertCreated()
            ->assertJsonPath('session.schema_version', 'atlas.code.observed_session.v1')
            ->assertJsonPath('session.state', 'waiting_operator')
            ->assertJsonPath('session.provider_id', 'claude_code')
            ->assertJsonPath('session.invocation_mode', 'interactive_observed')
            ->assertJsonPath('session.governance.invocation_mode_allowed', true)
            ->assertJsonPath('session.governance.invocation_mode', 'interactive_observed')
            ->assertJsonPath('session.packet_md_status', 'written');

        $packetMdPath = $sessionResponse->json('session.packet_md_path');
        $this->assertIsString($packetMdPath);
        $this->assertFileExists($packetMdPath);
        $this->assertStringContainsString('Atlas Work Packet', file_get_contents($packetMdPath));

        $prompt = $sessionResponse->json('session.prompt');
        $this->assertIsString($prompt);
        $this->assertStringContainsString('packet local', $prompt);
        $this->assertStringContainsString('interativa observada', $prompt);
        // The prompt MUST mention forbidden modes — as negations — so the
        // provider receives an explicit defense-in-depth reminder. The right
        // check is that the prohibition is declared, not that the strings
        // are absent.
        $this->assertMatchesRegularExpression('/Não rode .*claude -p/u', $prompt);
        $this->assertMatchesRegularExpression('/não chame Agent SDK/u', $prompt);
        $this->assertStringNotContainsString('execute claude -p', $prompt);
    }

    public function test_observed_session_state_machine_blocks_invalid_transitions(): void
    {
        $obraId = $this->createObra();
        $packetId = $this->withHeaders($this->headers())->postJson("/atlas-code/works/{$obraId}/work-packets", [
            'objective' => 'doc fix',
            'acceptance_criteria' => ['frase corrigida'],
        ])->json('packet.id');
        $sessionId = $this->withHeaders($this->headers())->postJson("/atlas-code/works/{$obraId}/observed-sessions", [
            'work_packet_id' => $packetId,
            'provider_id' => 'claude_code',
        ])->json('session.id');

        // Trying to accept before importing must fail (completion law).
        $denied = $this->withHeaders($this->headers())->postJson(
            "/atlas-code/works/{$obraId}/observed-sessions/{$sessionId}/decide",
            ['action' => 'accept']
        );
        $denied->assertStatus(422)->assertJsonPath('error', 'observed_session_cannot_accept_without_imported_report');

        // Valid path: waiting_operator → running → waiting_result_import → imported → review_required → accepted.
        $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$obraId}/observed-sessions/{$sessionId}/state", ['state' => 'running'])
            ->assertOk()
            ->assertJsonPath('session.state', 'running');

        $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$obraId}/observed-sessions/{$sessionId}/state", ['state' => 'waiting_result_import'])
            ->assertOk()
            ->assertJsonPath('session.state', 'waiting_result_import');

        $import = $this->withHeaders($this->headers())->postJson(
            "/atlas-code/works/{$obraId}/observed-sessions/{$sessionId}/import",
            [
                'report_text' => "## Resumo\nFrase corrigida.\n## Arquivos\n- docs/foo.md",
                'files' => ['docs/foo.md'],
                'diff_excerpt' => "--- a/docs/foo.md\n+++ b/docs/foo.md\n@@\n-old\n+new",
            ]
        );
        $import->assertOk()->assertJsonPath('session.state', 'review_required');
        $this->assertNotNull($import->json('session.diff_hash'));
        $this->assertNotNull($import->json('session.result_imported_at'));

        $accept = $this->withHeaders($this->headers())->postJson(
            "/atlas-code/works/{$obraId}/observed-sessions/{$sessionId}/decide",
            ['action' => 'accept', 'reason' => 'gates verdes, copy ok']
        );
        $accept->assertOk()->assertJsonPath('session.state', 'accepted');
        $this->assertNotNull($accept->json('session.human_decision'));
    }

    public function test_claude_code_one_shot_creates_packet_and_session_in_single_request(): void
    {
        $obraId = $this->createObra();

        $response = $this->withHeaders($this->headers())->postJson("/atlas-code/works/{$obraId}/observed-sessions/claude-code", [
            'objective' => 'Renomear export do componente Login',
            'acceptance_criteria' => ['Imports atualizados', 'Build passa'],
            'allowed_files' => ['src/Login/Login.tsx', 'src/Login/index.ts'],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('session.state', 'waiting_operator')
            ->assertJsonPath('session.provider_id', 'claude_code')
            ->assertJsonPath('packet.status', 'ready')
            ->assertJsonPath('packet.allowed_files.0', 'src/Login/Login.tsx');
    }

    public function test_open_observed_session_returns_blocker_when_workspace_path_missing(): void
    {
        // Create an Obra with a workspace_path that points to a non-existent dir.
        $obraId = (string) Str::uuid();
        DB::table('atlas_projects')->insert([
            'id' => $obraId,
            'title' => 'Obra sem workspace',
            'description' => 'intent',
            'status' => 'active',
            'domain' => 'atlas',
            'goal' => 'foo',
            'priority' => 'medium',
            'metadata' => json_encode([
                'origin' => 'atlas-code',
                'workspace_slug' => 'atlas',
                'workspace_path' => '/tmp/atlas-nonexistent-'.bin2hex(random_bytes(4)),
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $packetId = $this->withHeaders($this->headers())->postJson("/atlas-code/works/{$obraId}/work-packets", [
            'objective' => 'whatever',
            'acceptance_criteria' => ['x'],
        ])->json('packet.id');

        $response = $this->withHeaders($this->headers())->postJson("/atlas-code/works/{$obraId}/observed-sessions", [
            'work_packet_id' => $packetId,
            'provider_id' => 'claude_code',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('observed_session_blocked:workspace_missing', (string) $response->json('error'));
    }

    public function test_import_result_with_scope_violation_moves_session_to_blocked(): void
    {
        $obraId = $this->createObra();

        // Packet declares allowed_files only inside src/Checkout — any file
        // outside must trigger scope guard.
        $packetId = $this->withHeaders($this->headers())->postJson("/atlas-code/works/{$obraId}/work-packets", [
            'objective' => 'Refatorar checkout',
            'acceptance_criteria' => ['Componente renderiza'],
            'allowed_files' => ['src/Checkout/**'],
            'forbidden_files' => ['src/Auth/**'],
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

        $import = $this->withHeaders($this->headers())->postJson(
            "/atlas-code/works/{$obraId}/observed-sessions/{$sessionId}/import-result",
            [
                'report_text' => 'Mudou tudo, inclusive auth',
                'files' => ['src/Checkout/Cart.tsx', 'src/Auth/login.ts'],
            ]
        );

        $import->assertOk()->assertJsonPath('session.state', 'blocked');
        $this->assertStringContainsString('scope_violation_detected', (string) $import->json('session.blocker_reason'));
        $violations = (array) $import->json('session.scope_guard.violations');
        $this->assertNotEmpty($violations);
    }

    public function test_run_gates_returns_honest_results_for_imported_session(): void
    {
        $obraId = $this->createObra();
        $packetId = $this->withHeaders($this->headers())->postJson("/atlas-code/works/{$obraId}/work-packets", [
            'objective' => 'Polir Login.tsx',
            'acceptance_criteria' => ['Lint verde', 'Snapshot atualizado'],
            'allowed_files' => ['src/Login/Login.tsx'],
            'verification_commands' => ['pnpm test --filter Login', 'pnpm lint'],
        ])->json('packet.id');

        $sessionId = $this->withHeaders($this->headers())->postJson("/atlas-code/works/{$obraId}/observed-sessions", [
            'work_packet_id' => $packetId,
            'provider_id' => 'claude_code',
        ])->json('session.id');

        $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$obraId}/observed-sessions/{$sessionId}/mark-running")
            ->assertOk()->assertJsonPath('session.state', 'running');
        $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$obraId}/observed-sessions/{$sessionId}/state", ['state' => 'waiting_result_import'])
            ->assertOk();
        $this->withHeaders($this->headers())->postJson(
            "/atlas-code/works/{$obraId}/observed-sessions/{$sessionId}/import-result",
            [
                'report_text' => 'Login polido conforme criterios',
                'files' => ['src/Login/Login.tsx'],
                'diff_excerpt' => "--- a\n+++ b\n@@\n-old\n+new",
            ]
        )->assertOk()->assertJsonPath('session.state', 'review_required');

        $gates = $this->withHeaders($this->headers())
            ->postJson("/atlas-code/works/{$obraId}/observed-sessions/{$sessionId}/run-gates");

        $gates->assertOk()->assertJsonPath('session.state', 'gates_passed');
        $gateIds = collect($gates->json('session.gates'))->pluck('gate_id')->all();
        $this->assertContains('scope_guard', $gateIds);
        $this->assertContains('acceptance_criteria_declared', $gateIds);
        $this->assertContains('verification_commands_declared', $gateIds);
        $this->assertContains('report_imported', $gateIds);
        $this->assertContains('diff_imported', $gateIds);

        // Verification commands must be reported as available-but-not-run
        // (Atlas never executes them).
        $vc = collect($gates->json('session.gates'))->firstWhere('gate_id', 'verification_commands_declared');
        $this->assertSame('commands_available_but_not_run', $vc['status']);

        // Even with all advisory gates "passed", completion must require
        // human acceptance — try to accept and it should work only because
        // the report was imported. Reject if no report:
        $accept = $this->withHeaders($this->headers())->postJson(
            "/atlas-code/works/{$obraId}/observed-sessions/{$sessionId}/decide",
            ['action' => 'accept', 'reason' => 'gates ok']
        );
        $accept->assertOk()->assertJsonPath('session.state', 'accepted');
    }

    public function test_decide_programmatic_invocation_returns_per_label_decisions(): void
    {
        $service = $this->app->make(\App\Services\AtlasCode\AtlasCodeProviderGovernanceService::class);

        // Defaults (test_only): rivals + tests permitted, productive blocked.
        $rivals = $service->decideProgrammaticInvocation('rivals_baseline');
        $this->assertTrue($rivals['allowed']);
        $this->assertSame('permitted_by_policy_and_switch', $rivals['reason']);

        $tests = $service->decideProgrammaticInvocation('provider_integration_test');
        $this->assertTrue($tests['allowed']);

        $bench = $service->decideProgrammaticInvocation('benchmark');
        $this->assertTrue($bench['allowed']);

        $productive = $service->decideProgrammaticInvocation('productive_headless');
        $this->assertFalse($productive['allowed']);
        $this->assertSame('productive_headless_blocked_by_default', $productive['reason']);
        $this->assertTrue($productive['requires_operator_override']);

        // Unknown labels always rejected.
        $unknown = $service->decideProgrammaticInvocation('rogue_label');
        $this->assertFalse($unknown['allowed']);
        $this->assertStringContainsString('label_not_recognized', $unknown['reason']);

        // Tightening to interactive_only kills programmatic, including rivals.
        config()->set('atlas_code_provider_governance.claude_programmatic_policy', 'interactive_only');
        $rivalsBlocked = $service->decideProgrammaticInvocation('rivals_baseline');
        $this->assertFalse($rivalsBlocked['allowed']);
        $this->assertSame('policy_interactive_only', $rivalsBlocked['reason']);
    }

    public function test_operating_room_aggregates_packets_sessions_and_attention(): void
    {
        $obraId = $this->createObra();
        $packetId = $this->withHeaders($this->headers())->postJson("/atlas-code/works/{$obraId}/work-packets", [
            'objective' => 'fix bug',
            'acceptance_criteria' => ['teste passa'],
        ])->json('packet.id');

        // Before any session, attention should be `ready_to_open_provider`.
        $beforeSession = $this->withHeaders($this->headers())
            ->getJson("/atlas-code/works/{$obraId}/forge/operating-room");
        $beforeSession
            ->assertOk()
            ->assertJsonPath('operating_room.schema_version', 'atlas.code.provider_operating_room.v1')
            ->assertJsonPath('operating_room.attention.kind', 'ready_to_open_provider')
            ->assertJsonPath('operating_room.work_packets.counts.total', 1)
            ->assertJsonPath('operating_room.observed_sessions.counts.total', 0)
            ->assertJsonPath('operating_room.safety_summary.claude_programmatic_policy', 'test_only')
            ->assertJsonPath('operating_room.safety_summary.allow_rivals_programmatic', true)
            ->assertJsonPath('operating_room.safety_summary.productive_headless_allowed', false)
            ->assertJsonPath('operating_room.safety_summary.full_block', false);

        // Provider board contains the bootstrap roles (claude_code, codex_cli, gemini_cli).
        $boardProviders = collect($beforeSession->json('operating_room.provider_board'))
            ->pluck('provider_id')
            ->all();
        $this->assertContains('claude_code', $boardProviders);
        $this->assertContains('codex_cli', $boardProviders);

        // Open a session and re-check attention rotates.
        $sessionId = $this->withHeaders($this->headers())->postJson("/atlas-code/works/{$obraId}/observed-sessions", [
            'work_packet_id' => $packetId,
            'provider_id' => 'claude_code',
        ])->json('session.id');

        $afterSession = $this->withHeaders($this->headers())
            ->getJson("/atlas-code/works/{$obraId}/forge/operating-room");
        $afterSession
            ->assertOk()
            ->assertJsonPath('operating_room.attention.kind', 'waiting_operator')
            ->assertJsonPath('operating_room.attention.target_session_id', $sessionId)
            ->assertJsonPath('operating_room.observed_sessions.counts.waiting_operator', 1);
    }
}
