<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AgentExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\AgentExecutionProviderPortService;
use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\AgentExecutionSessionStoreService;
use Tests\TestCase;

/**
 * AP-795 · AP-793 session store tests. Storage is redirected to a temp dir so no
 * test touches real storage and no provider is ever invoked. Tests cover JSONL
 * append-only idempotency by session_hash, secret redaction (api key/bearer/raw
 * resume token/raw prompt never persisted), and replay/latestByCycleId.
 */
class AgentExecutionSessionStoreServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap795_'.uniqid('', true);
        @mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->tmp.'/*') as $f) {
            @unlink((string) $f);
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function store(): AgentExecutionSessionStoreService
    {
        $svc = new AgentExecutionSessionStoreService(new AgentExecutionProviderPortService());
        $svc->setStorageRootForTesting($this->tmp);

        return $svc;
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'cycle_id' => 'aesc_1',
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'provider' => 'cursor_cli',
            'model' => 'composer-2.5-fast',
            'command_argv' => ['php', 'artisan', 'test'],
            'worktree_path' => '/tmp/wt',
            'session_id' => 'sess-1',
            'provider_called' => true,
            'exit_code' => 0,
        ], $overrides);
    }

    public function test_records_real_invocation_with_hashes_and_schema(): void
    {
        $rec = $this->store()->record($this->input());

        $this->assertSame(AgentExecutionSessionStoreService::SCHEMA, $rec['schema_version']);
        $this->assertSame('AP-795', $rec['ap_contract']);
        $this->assertSame('real', $rec['invocation_state']);
        $this->assertTrue($rec['provider_invoked']);
        $this->assertStringStartsWith('sha256:', $rec['session_hash']);
        $this->assertStringStartsWith('ases_', $rec['agent_session_id']);
        $this->assertStringStartsWith('sha256:', $rec['command_hash']);
        $this->assertStringStartsWith('sha256:', $rec['worktree_hash']);
        $this->assertArrayHasKey('generated_at', $rec);
        $this->assertArrayHasKey('recorded_at', $rec);
        $this->assertFalse($rec['claim_policy']['provider_invoked']);
    }

    public function test_records_planned_and_simulated_states(): void
    {
        $planned = $this->store()->record(['cycle_id' => 'aesc_p', 'provider' => 'cursor_cli', 'final_status' => 'dry_run_planned']);
        $this->assertSame('planned', $planned['invocation_state']);
        $this->assertFalse($planned['provider_invoked']);

        $simulated = $this->store()->record(['cycle_id' => 'aesc_s', 'provider' => 'cursor_cli', 'simulated' => true]);
        $this->assertSame('simulated', $simulated['invocation_state']);
    }

    public function test_idempotent_by_session_hash(): void
    {
        $store = $this->store();
        $first = $store->record($this->input());
        $second = $store->record($this->input());

        $this->assertSame($first['session_hash'], $second['session_hash']);

        $lines = file($store->sessionsFilePath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $this->assertCount(1, $lines, 'a duplicate execution must not append a second JSONL line');
    }

    public function test_distinct_executions_each_append(): void
    {
        $store = $this->store();
        $store->record($this->input(['cycle_id' => 'aesc_1']));
        $store->record($this->input(['cycle_id' => 'aesc_2']));

        $lines = file($store->sessionsFilePath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $this->assertCount(2, $lines);
    }

    public function test_never_persists_raw_secrets(): void
    {
        $store = $this->store();
        $store->record($this->input([
            'api_key' => 'sk-proj-LEAKKKKKKKKKKKKKKKKKK1234',
            'resume_token' => 'sk-proj-RESUMESECRET1234567890ABCD',
            'prompt' => 'do X using Authorization: Bearer abcdef1234567890LEAK',
            'context' => 'secret=topsecretvalue123456',
            'stream_events' => [['kind' => 'text', 'text' => 'token: ghp_ABCDEFGHIJKLMNOP1234567890']],
        ]));

        $raw = (string) file_get_contents($store->sessionsFilePath());
        $this->assertStringNotContainsString('LEAKKKKK', $raw);
        $this->assertStringNotContainsString('RESUMESECRET', $raw);
        $this->assertStringNotContainsString('abcdef1234567890LEAK', $raw);
        $this->assertStringNotContainsString('topsecretvalue123456', $raw);
        $this->assertStringNotContainsString('ghp_ABCDEFGHIJKLMNOP1234567890', $raw);
    }

    public function test_resume_token_stored_as_presence_and_hash_only(): void
    {
        $rec = $this->store()->record($this->input(['resume_token' => 'sk-proj-RESUMESECRET1234567890ABCD']));

        $this->assertTrue($rec['resume_token_present']);
        $this->assertStringStartsWith('sha256:', $rec['resume_token_hash']);
        $this->assertStringNotContainsString('RESUMESECRET', $rec['resume_token_hash']);
    }

    public function test_prompt_and_context_stored_as_hashes_not_content(): void
    {
        $rec = $this->store()->record($this->input([
            'prompt' => 'implement the fix',
            'context' => 'finding detail',
        ]));

        $this->assertStringStartsWith('sha256:', $rec['prompt_hash']);
        $this->assertStringStartsWith('sha256:', $rec['context_hash']);
        $this->assertArrayNotHasKey('prompt', $rec);
        $this->assertArrayNotHasKey('context', $rec);
    }

    public function test_replay_by_session_id(): void
    {
        $store = $this->store();
        $store->record($this->input(['cycle_id' => 'aesc_1', 'session_id' => 'sess-A']));
        $store->record($this->input(['cycle_id' => 'aesc_2', 'session_id' => 'sess-A']));
        $store->record($this->input(['cycle_id' => 'aesc_3', 'session_id' => 'sess-B']));

        $this->assertCount(2, $store->replay('sess-A'));
        $this->assertCount(1, $store->replay('sess-B'));
        $this->assertSame([], $store->replay('does-not-exist'));
    }

    public function test_replay_by_agent_session_id(): void
    {
        $store = $this->store();
        $rec = $store->record($this->input());

        $replayed = $store->replay($rec['agent_session_id']);
        $this->assertCount(1, $replayed);
        $this->assertSame($rec['agent_session_id'], $replayed[0]['agent_session_id']);
    }

    public function test_latest_by_cycle_id(): void
    {
        $store = $this->store();
        $store->record($this->input(['cycle_id' => 'aesc_x', 'session_id' => 'sess-1']));
        $store->record($this->input(['cycle_id' => 'aesc_x', 'session_id' => 'sess-2']));

        $latest = $store->latestByCycleId('aesc_x');
        $this->assertNotNull($latest);
        $this->assertSame('sess-2', $latest['session_id']);
        $this->assertNull($store->latestByCycleId('missing'));
    }

    public function test_consumes_prenormalized_provider_port_facts(): void
    {
        $port = (new AgentExecutionProviderPortService())->normalize($this->input());
        $rec = $this->store()->record(['provider_port' => $port, 'cycle_id' => 'aesc_pre']);

        $this->assertSame('cursor_cli', $rec['provider_id']);
        $this->assertSame('real', $rec['invocation_state']);
        $this->assertSame('aesc_pre', $rec['cycle_id']);
    }
}
