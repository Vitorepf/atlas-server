<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AgentExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\AgentExecutionProviderPortService;
use Tests\TestCase;

/**
 * AP-795 · AP-793 provider port normalizer tests. Pure normalizer: no provider
 * is ever invoked, no file is touched. Tests cover real/planned/deferred/
 * simulated states, shell-string rejection, explicit auth modes and the AP-759/
 * AP-786 payload shapes.
 */
class AgentExecutionProviderPortServiceTest extends TestCase
{
    private function port(): AgentExecutionProviderPortService
    {
        return new AgentExecutionProviderPortService();
    }

    public function test_normalizes_flat_real_provider_facts(): void
    {
        $record = $this->port()->normalize([
            'provider' => 'cursor_cli',
            'model' => 'composer-2.5-fast',
            'command_argv' => ['php', 'artisan', 'test'],
            'worktree_path' => '/tmp/wt',
            'session_id' => 'sess-1',
            'provider_called' => true,
            'exit_code' => 0,
            'local_login' => true,
            'permission_mode' => 'scoped_worktree',
        ]);

        $this->assertSame(AgentExecutionProviderPortService::SCHEMA, $record['schema_version']);
        $this->assertSame('AP-795', $record['ap_contract']);
        $this->assertSame(AgentExecutionProviderPortService::PORT_NORMALIZED, $record['port_status']);
        $this->assertSame('cursor_cli', $record['provider_id']);
        $this->assertSame('composer-2.5-fast', $record['model_family']);
        $this->assertSame(['php', 'artisan', 'test'], $record['command_argv']);
        $this->assertSame('/tmp/wt', $record['working_directory']);
        $this->assertSame('sess-1', $record['session_id']);
        $this->assertSame(AgentExecutionProviderPortService::INVOCATION_REAL, $record['invocation_state']);
        $this->assertTrue($record['provider_invoked']);
        $this->assertSame('scoped_worktree', $record['permission_mode']);
        $this->assertSame('succeeded', $record['exit_status']['state']);
        $this->assertStringStartsWith('sha256:', $record['port_hash']);
        $this->assertFalse($record['accepts_shell_string']);
        $this->assertFalse($record['claim_policy']['executes_provider']);
    }

    public function test_accepts_ap786_cycle_payload(): void
    {
        $record = $this->port()->normalize([
            'cycle' => [
                'sandbox_id' => 'sb_1',
                'worktree_path' => '/tmp/aess',
                'provider_result' => ['provider' => 'cursor_cli', 'model' => 'composer-2.5-fast', 'provider_called' => true, 'exit_code' => 0],
                'owner_flow' => ['provider_invoked' => true, 'provider' => 'cursor_cli'],
            ],
        ]);

        $this->assertSame('cursor_cli', $record['provider_id']);
        $this->assertSame('composer-2.5-fast', $record['model_family']);
        $this->assertSame('/tmp/aess', $record['working_directory']);
        $this->assertSame(AgentExecutionProviderPortService::INVOCATION_REAL, $record['invocation_state']);
    }

    public function test_rejects_shell_string_command(): void
    {
        $record = $this->port()->normalize([
            'provider' => 'cursor_cli',
            'command' => 'rm -rf / && echo pwned',
        ]);

        $this->assertSame(AgentExecutionProviderPortService::PORT_REJECTED, $record['port_status']);
        $this->assertContains(AgentExecutionProviderPortService::VIOLATION_SHELL_STRING, $record['port_violations']);
        $this->assertSame([], $record['command_argv']);
    }

    public function test_rejects_shell_string_passed_as_command_argv(): void
    {
        $record = $this->port()->normalize([
            'provider' => 'cursor_cli',
            'command_argv' => 'php artisan test',
        ]);

        $this->assertSame(AgentExecutionProviderPortService::PORT_REJECTED, $record['port_status']);
        $this->assertContains(AgentExecutionProviderPortService::VIOLATION_SHELL_STRING, $record['port_violations']);
        $this->assertSame([], $record['command_argv']);
    }

    public function test_planned_state_for_dry_run(): void
    {
        $record = $this->port()->normalize(['provider' => 'cursor_cli', 'final_status' => 'dry_run_planned']);

        $this->assertSame(AgentExecutionProviderPortService::INVOCATION_PLANNED, $record['invocation_state']);
        $this->assertFalse($record['provider_invoked']);
    }

    public function test_simulated_and_deferred_states(): void
    {
        $simulated = $this->port()->normalize(['provider' => 'cursor_cli', 'simulated' => true]);
        $this->assertSame(AgentExecutionProviderPortService::INVOCATION_SIMULATED, $simulated['invocation_state']);
        $this->assertFalse($simulated['provider_invoked']);

        $deferred = $this->port()->normalize(['provider' => 'forge', 'status' => 'provider_bridge_missing']);
        $this->assertSame(AgentExecutionProviderPortService::INVOCATION_DEFERRED, $deferred['invocation_state']);
    }

    public function test_real_state_is_never_inferred_from_shape_alone(): void
    {
        // A rich-looking payload with no explicit invocation signal stays planned.
        $record = $this->port()->normalize([
            'provider' => 'cursor_cli',
            'model' => 'composer-2.5-fast',
            'command_argv' => ['php', 'artisan', 'test'],
            'worktree_path' => '/tmp/wt',
            'exit_code' => 0,
        ]);

        $this->assertSame(AgentExecutionProviderPortService::INVOCATION_PLANNED, $record['invocation_state']);
        $this->assertFalse($record['provider_invoked']);
    }

    public function test_explicit_invocation_state_is_honored(): void
    {
        $record = $this->port()->normalize(['provider' => 'cursor_cli', 'invocation_state' => 'simulated', 'provider_called' => true]);

        $this->assertSame(AgentExecutionProviderPortService::INVOCATION_SIMULATED, $record['invocation_state']);
    }

    public function test_auth_mode_is_always_one_of_five_modes(): void
    {
        $modes = [
            AgentExecutionProviderPortService::AUTH_LOCAL_ACCOUNT,
            AgentExecutionProviderPortService::AUTH_API_KEY,
            AgentExecutionProviderPortService::AUTH_LOCAL_MODEL,
            AgentExecutionProviderPortService::AUTH_BLOCKED,
            AgentExecutionProviderPortService::AUTH_UNKNOWN,
        ];

        $this->assertSame(AgentExecutionProviderPortService::AUTH_API_KEY, $this->port()->normalize(['provider' => 'codex_cli', 'api_key_present' => true])['auth_mode']);
        $this->assertSame(AgentExecutionProviderPortService::AUTH_LOCAL_ACCOUNT, $this->port()->normalize(['provider' => 'cursor_cli', 'local_login' => true])['auth_mode']);
        $this->assertSame(AgentExecutionProviderPortService::AUTH_LOCAL_MODEL, $this->port()->normalize(['provider' => 'minimax_self_host'])['auth_mode']);
        $this->assertSame(AgentExecutionProviderPortService::AUTH_BLOCKED, $this->port()->normalize(['provider' => 'forge', 'provider_authorized' => false])['auth_mode']);
        $this->assertSame(AgentExecutionProviderPortService::AUTH_UNKNOWN, $this->port()->normalize(['provider' => 'cursor_cli'])['auth_mode']);

        foreach ([[], ['provider' => 'x'], ['auth_mode' => 'garbage']] as $payload) {
            $this->assertContains($this->port()->normalize($payload)['auth_mode'], $modes);
        }
    }

    public function test_argv_is_redacted_and_no_raw_secret_in_port(): void
    {
        $record = $this->port()->normalize([
            'provider' => 'cursor_cli',
            'command_argv' => ['curl', '-H', 'Authorization: Bearer abcdef1234567890SECRET'],
            'provider_called' => true,
        ]);

        $joined = implode(' ', $record['command_argv']);
        $this->assertStringNotContainsString('abcdef1234567890SECRET', $joined);
    }

    public function test_usage_and_stream_events_are_normalized(): void
    {
        $record = $this->port()->normalize([
            'provider' => 'cursor_cli',
            'provider_called' => true,
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50, 'total_tokens' => 150, 'limit_state' => 'ok'],
            'duration_ms' => 1234,
            'stream_events' => [
                ['kind' => 'text', 'text' => 'hello'],
                ['type' => 'weird', 'content' => 'x'],
            ],
        ]);

        $this->assertSame(100, $record['usage']['input_tokens']);
        $this->assertSame(150, $record['usage']['total_tokens']);
        $this->assertSame(1234, $record['usage']['duration_ms']);
        $this->assertCount(2, $record['stream_events']);
        $this->assertSame('text', $record['stream_events'][0]['kind']);
        $this->assertSame('other', $record['stream_events'][1]['kind']);
    }
}
