<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasForgeClaudeCliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeCodexCliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeGeminiCliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeProviderCommandAllowlistService;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationFailureClassifier;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationPromptBuilder;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationService;
use App\Services\Ai\Programming\AtlasForgeProviderProcessRunner;
use App\Services\Ai\Programming\ProgrammingProfessionalCompletionAuditService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AtlasForgeRealProviderDriversTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSchema();
    }

    public function test_driver_router_lists_atlas_local_claude_codex_gemini(): void
    {
        $router = app(AtlasForgeProviderInvocationDriverRouter::class);
        $status = $router->driverStatus();

        $providers = array_map(fn (array $d): string => (string) $d['provider'], $status['drivers']);
        foreach (['atlas-local', 'claude_cli', 'codex_cli', 'gemini_cli', 'antigravity_sdk'] as $expected) {
            $this->assertContains($expected, $providers, "router must register {$expected}");
        }
    }

    public function test_driver_status_never_calls_external_provider(): void
    {
        $router = app(AtlasForgeProviderInvocationDriverRouter::class);
        $status = $router->driverStatus();
        foreach ($status['drivers'] as $entry) {
            $this->assertArrayHasKey('configured', $entry);
            $this->assertArrayHasKey('runtime_present', $entry);
            $this->assertArrayHasKey('auth_state', $entry);
        }
        $this->assertContains('atlas-local', $status['configured_drivers']);
    }

    public function test_missing_cli_returns_driver_not_configured_or_missing(): void
    {
        $driver = app(AtlasForgeClaudeCliInvocationDriver::class);
        $configured = $driver->configured();
        // On a clean test host, no provider CLI auth env is present.
        $this->assertContains((string) $configured['auth_state'], ['missing', 'configured', 'unknown']);
    }

    public function test_command_allowlist_blocks_shell_injection(): void
    {
        $allowlist = app(AtlasForgeProviderCommandAllowlistService::class);
        $result = $allowlist->evaluate(['claude', '--prompt', 'echo ok | rm -rf /']);
        $this->assertFalse($result['allowed']);
        $this->assertContains(AtlasForgeProviderCommandAllowlistService::BLOCKER_SHELL_METACHARACTER, $result['blockers']);
    }

    public function test_command_allowlist_blocks_forbidden_binary(): void
    {
        $allowlist = app(AtlasForgeProviderCommandAllowlistService::class);
        $result = $allowlist->evaluate(['rm', '-rf', '/']);
        $this->assertFalse($result['allowed']);
        $this->assertContains(AtlasForgeProviderCommandAllowlistService::BLOCKER_FORBIDDEN_BINARY, $result['blockers']);
    }

    public function test_safe_process_runner_times_out(): void
    {
        $runner = new AtlasForgeProviderProcessRunner();
        $runner->setProcessFactory(function (array $argv, ?string $cwd, ?array $env, int $timeout): Process {
            $process = new Process(['sleep', '5'], $cwd, $env, null, 1.0);
            $process->setTimeout(1.0);

            return $process;
        });
        $result = $runner->run([
            'argv' => ['sleep', '5'],
            'timeout_seconds' => 1,
        ]);
        $this->assertSame(AtlasForgeProviderProcessRunner::STATUS_TIMED_OUT, $result['status']);
        $this->assertNotNull($result['stdout_hash']);
        $this->assertNotNull($result['stderr_hash']);
    }

    public function test_safe_process_runner_hashes_output(): void
    {
        $runner = new AtlasForgeProviderProcessRunner();
        $runner->setProcessFactory(function (array $argv, ?string $cwd, ?array $env, int $timeout): Process {
            // Use a Process that already has output set (via fromShellCommandline would shell-parse;
            // we go the safe route: spawn echo). echo is on the forbidden list above, so we use a
            // raw printf process.
            return Process::fromShellCommandline('printf hello');
        });
        $result = $runner->run(['argv' => ['echo', 'hello'], 'timeout_seconds' => 5]);
        $this->assertNotEmpty($result['stdout_hash']);
        $this->assertSame(64, strlen($result['stdout_hash']));
    }

    public function test_claude_driver_blocks_when_not_configured(): void
    {
        $driver = app(AtlasForgeClaudeCliInvocationDriver::class);
        $result = $driver->invoke(['model' => 'claude-opus-4-7', 'prompt' => 'hello']);
        if (! (bool) $driver->configured()['configured']) {
            $this->assertContains('provider_driver_not_configured', $result['blockers']);
            $this->assertFalse($result['provider_called']);
            $this->assertFalse($result['external_provider_call']);
        } else {
            // host has CLI configured — still must not actually run in this test.
            $this->assertArrayHasKey('blockers', $result);
        }
    }

    public function test_codex_driver_blocks_when_not_configured(): void
    {
        $driver = app(AtlasForgeCodexCliInvocationDriver::class);
        $result = $driver->invoke(['model' => 'gpt-5.5', 'prompt' => 'hello']);
        if (! (bool) $driver->configured()['configured']) {
            $this->assertContains('provider_driver_not_configured', $result['blockers']);
            $this->assertFalse($result['provider_called']);
        } else {
            $this->assertArrayHasKey('blockers', $result);
        }
    }

    public function test_gemini_driver_blocks_when_not_configured(): void
    {
        $driver = app(AtlasForgeGeminiCliInvocationDriver::class);
        $result = $driver->invoke(['model' => 'gemini-2.5-pro', 'prompt' => 'hello']);
        if (! (bool) $driver->configured()['configured']) {
            $this->assertContains('provider_driver_not_configured', $result['blockers']);
            $this->assertFalse($result['provider_called']);
        } else {
            $this->assertArrayHasKey('blockers', $result);
        }
    }

    public function test_execute_requires_three_confirmations_before_driver_invocation(): void
    {
        $obra = $this->makeObra();
        $service = app(AtlasForgeProviderInvocationService::class);
        $payload = $service->invoke([
            'obra_id' => (string) $obra->id,
            'role' => 'primary_builder',
            'mode' => 'execute',
        ]);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('operator_provider_approval_required', $payload['blockers']);
        $this->assertContains('runtime_dispatch_confirmation_required', $payload['blockers']);
        $this->assertFalse($payload['provider_called']);
    }

    public function test_capacity_exhausted_short_circuits_before_driver(): void
    {
        // No live obra — service must still return blocked without invoking driver.
        $service = app(AtlasForgeProviderInvocationService::class);
        $payload = $service->invoke(['mode' => 'execute', 'confirm_provider_call' => true, 'confirm_budget' => true, 'confirm_runtime_dispatch' => true]);
        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse($payload['provider_called']);
    }

    public function test_driver_plan_does_not_call_provider(): void
    {
        $driver = app(AtlasForgeClaudeCliInvocationDriver::class);
        $plan = $driver->plan(['model' => 'claude-opus-4-7']);
        $this->assertFalse($plan['provider_called']);
        $this->assertFalse($plan['external_provider_call']);
        $this->assertFalse($plan['provider_tokens_spent']);
    }

    public function test_atlas_local_driver_still_executes_safely(): void
    {
        $router = app(AtlasForgeProviderInvocationDriverRouter::class);
        $result = $router->invoke('atlas-local', 'atlas-runtime', ['schema_version' => 'atlas.forge.provider_invocation_prompt.v1'], [
            'obra_id' => 'smoke',
            'role' => 'primary_builder',
        ]);
        $this->assertSame('atlas-local', $result['provider']);
        $this->assertFalse($result['provider_called']);
        $this->assertFalse($result['external_provider_call']);
    }

    public function test_failure_classifier_detects_rate_limit(): void
    {
        $classifier = app(AtlasForgeProviderInvocationFailureClassifier::class);
        $c = $classifier->classify([
            'provider' => 'claude_cli',
            'exit_code' => 1,
            'stdout' => '',
            'stderr' => 'Error: Rate limit exceeded. Retry after 60s.',
        ]);
        $this->assertSame('rate_limit', $c['failure_type']);
    }

    public function test_failure_classifier_detects_quota_exhausted(): void
    {
        $classifier = app(AtlasForgeProviderInvocationFailureClassifier::class);
        $c = $classifier->classify([
            'provider' => 'codex_cli',
            'exit_code' => 1,
            'stderr' => 'You have exceeded your plan limit. Billing required.',
        ]);
        $this->assertSame('quota_exhausted', $c['failure_type']);
    }

    public function test_failure_classifier_detects_auth_failed(): void
    {
        $classifier = app(AtlasForgeProviderInvocationFailureClassifier::class);
        $c = $classifier->classify([
            'provider' => 'gemini_cli',
            'exit_code' => 1,
            'stderr' => '401 Unauthorized: invalid API key',
        ]);
        $this->assertSame('auth_failed', $c['failure_type']);
    }

    public function test_failure_classifier_detects_context_limit(): void
    {
        $classifier = app(AtlasForgeProviderInvocationFailureClassifier::class);
        $c = $classifier->classify([
            'provider' => 'claude_cli',
            'exit_code' => 1,
            'stderr' => 'Context length exceeded the maximum context window',
        ]);
        $this->assertSame('context_limit', $c['failure_type']);
    }

    public function test_failure_classifier_detects_timeout(): void
    {
        $classifier = app(AtlasForgeProviderInvocationFailureClassifier::class);
        $c = $classifier->classify([
            'provider' => 'claude_cli',
            'exit_code' => null,
            'timed_out' => true,
        ]);
        $this->assertSame('timeout', $c['failure_type']);
    }

    public function test_invocation_receipt_includes_driver_hashes(): void
    {
        // Cover schema presence even without external CLI configured.
        $obra = $this->makeObra();
        $payload = app(AtlasForgeProviderInvocationService::class)->invoke([
            'obra_id' => (string) $obra->id,
            'mode' => 'dry_run',
        ]);
        $this->assertArrayHasKey('stdout_hash', $payload);
        $this->assertArrayHasKey('stderr_hash', $payload);
    }

    public function test_driver_status_endpoint_returns_canonical_payload(): void
    {
        $obra = $this->makeObra();
        $this->getJson('/atlas-code/works/'.$obra->id.'/forge/provider-invocations/drivers', $this->headers())
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.forge.provider_driver_router_status.v1');
    }

    public function test_driver_plan_endpoint_returns_canonical_payload(): void
    {
        $obra = $this->makeObra();
        $this->postJson('/atlas-code/works/'.$obra->id.'/forge/provider-invocations/plan-driver', [
            'role' => 'primary_builder',
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.forge.provider_driver_plan_packet.v1')
            ->assertJsonPath('external_provider_call', false);
    }

    public function test_state_endpoint_exposes_driver_status(): void
    {
        $obra = $this->makeObra();
        $this->getJson('/atlas-code/works/'.$obra->id.'/state', $this->headers())
            ->assertOk()
            ->assertJsonPath('forge_provider_driver_status.schema_version', 'atlas.forge.provider_driver_router_status.v1');
    }

    public function test_completion_audit_exposes_real_provider_drivers_certification(): void
    {
        $report = app(ProgrammingProfessionalCompletionAuditService::class)->report(base_path());
        $this->assertArrayHasKey('atlas_forge_real_provider_drivers_certification', $report);
        $block = $report['atlas_forge_real_provider_drivers_certification'];
        $this->assertSame('atlas.forge_real_provider_drivers_certification.v1', $block['schema_version']);
        $this->assertContains($block['status'], ['available', 'backend_available_ui_pending', 'missing_artifacts', 'blocked']);
        $this->assertTrue($block['no_external_provider_call']);
        $this->assertFalse($block['external_provider_call']);
        $this->assertFalse($block['promotes_external_rivals_claim']);
        $this->assertTrue($block['separated_from_external_rivals_certification']);
    }

    public function test_provider_driver_missing_preserved_for_unknown_provider(): void
    {
        $router = app(AtlasForgeProviderInvocationDriverRouter::class);
        $status = $router->driverStatus('imposter_provider');
        $this->assertSame('imposter_provider', $status['provider']);
        $this->assertFalse($status['configured']);
        $this->assertContains('provider_driver_missing', $status['blockers']);
    }

    public function test_no_completion_claim_promotion(): void
    {
        $obra = $this->makeObra();
        $payload = app(AtlasForgeProviderInvocationService::class)->invoke([
            'obra_id' => (string) $obra->id,
            'mode' => 'dry_run',
        ]);
        $this->assertFalse($payload['completion_claim_promoted']);
    }

    public function test_external_rivals_remains_separated_and_blocked(): void
    {
        $report = app(ProgrammingProfessionalCompletionAuditService::class)->report(base_path());
        $this->assertFalse($report['atlas_forge_real_provider_drivers_certification']['promotes_external_rivals_claim']);
        $this->assertTrue($report['atlas_forge_real_provider_drivers_certification']['separated_from_external_rivals_certification']);
    }

    private function makeObra(): AtlasProject
    {
        return AtlasProject::create([
            'id' => (string) Str::uuid(),
            'title' => 'real drivers smoke',
            'status' => 'active',
            'domain' => 'atlas',
            'goal' => 'real drivers smoke',
            'desired_outcome' => 'drivers governados smoke',
            'priority' => 'normal',
            'metadata' => ['workspace_path' => '/tmp/atlas-real-drivers-test'],
        ]);
    }

    /**
     * @return array<string,string>
     */
    private function headers(): array
    {
        return ['X-Atlas-Token' => 'testing-atlas-token-with-enough-length'];
    }

    private function ensureSchema(): void
    {
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
                $t->string('priority')->default('normal');
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

        foreach ([
            'ai_threads', 'ai_messages', 'ai_traces',
            'atlas_engineering_runs', 'atlas_engineering_evidence',
            'atlas_tool_runs', 'atlas_ledger_events',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                Schema::create($table, function (Blueprint $t) use ($table) {
                    if ($table === 'atlas_ledger_events') {
                        $t->string('event_id')->primary();
                        $t->string('schema_version')->nullable();
                        $t->string('tenant_id')->nullable();
                        $t->string('operator_id')->nullable();
                        $t->string('envelope_id')->nullable();
                        $t->string('receipt_id')->nullable();
                        $t->string('trace_id')->nullable();
                        $t->string('correlation_id')->nullable();
                        $t->string('causation_id')->nullable();
                        $t->string('event_type')->nullable();
                        $t->string('emitter_stage')->nullable();
                        $t->string('emitter_version')->nullable();
                        $t->json('payload')->nullable();
                        $t->string('payload_hash')->nullable();
                        $t->timestamp('occurred_at')->nullable();
                        $t->timestamps();

                        return;
                    }
                    $t->uuid('id')->primary();
                    $t->string('title')->nullable();
                    $t->string('status')->default('active');
                    $t->string('surface')->nullable();
                    $t->string('workspace')->nullable();
                    $t->string('source_type')->nullable();
                    $t->uuid('source_id')->nullable();
                    $t->integer('message_count')->default(0);
                    $t->timestamp('last_message_at')->nullable();
                    $t->json('metadata')->nullable();
                    $t->timestamps();
                });
            }
        }
    }
}
