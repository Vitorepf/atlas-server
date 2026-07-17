<?php

namespace Tests\Feature\Ai\Arena;

use App\Services\Ai\Rivals\Support\RunPaths;
use Tests\TestCase;

class ArenaRunControllerTest extends TestCase
{
    private const TOKEN = 'test-token-with-enough-length-123';

    /** @var array<string,string> */
    private array $headers = ['X-Atlas-Token' => self::TOKEN];

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = sys_get_temp_dir().'/arena_http_'.uniqid('', true);
        config()->set('atlas.token', self::TOKEN);
        config()->set('atlas_rivals.storage_root', $this->storage);
        config()->set('atlas_arena.suites', ['terminal_bench', 'bfcl']);
        config()->set('atlas_arena.weights', [
            'terminal_bench' => 0.5,
            'bfcl' => 0.5,
        ]);
        config()->set('atlas_arena.capability_map', [
            'terminal_bench' => [
                ['capability' => 'terminal_operation', 'weight' => 1.0],
            ],
        ]);
        config()->set('atlas_arena.capability_labels_pt', [
            'terminal_operation' => 'Operação de terminal',
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storage)) {
            exec('rm -rf '.escapeshellarg($this->storage));
        }

        parent::tearDown();
    }

    public function test_arena_routes_require_atlas_token(): void
    {
        $this->getJson('/arena/composite')->assertStatus(401);
        $this->postJson('/arena/runs', [])->assertStatus(401);
    }

    public function test_get_contracts_are_allowlisted_and_read_existing_measurements(): void
    {
        $this->writeRun('20260717_010000_terminal', 'terminal_bench', [
            $this->receipt('codex_cli@bare', 'c1', 'success', '2026-07-17T01:01:00Z'),
            $this->receipt('codex_cli@bare', 'c2', 'failure', '2026-07-17T01:02:00Z'),
            $this->receipt('codex_cli@atlas_dev', 'c1', 'success', '2026-07-17T01:03:00Z'),
            $this->receipt('codex_cli@atlas_dev', 'c2', 'success', '2026-07-17T01:04:00Z'),
        ], state: 'native_running');
        $this->appendEvent('20260717_010000_terminal', 'unit_finished', ['case_id' => 'c1']);

        $this->getJson('/arena/composite', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.arena.composite.v1')
            ->assertJsonPath('engines.0.engine', 'codex_cli')
            ->assertJsonPath('engines.0.atlas_multiplier', 2);

        $this->getJson('/arena/scoreboard', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.arena.scoreboard.v1')
            ->assertJsonPath('suites.0.suite', 'terminal_bench')
            ->assertJsonPath('suites.0.adapter_installed', true)
            ->assertJsonPath('suites.0.engines.0.with_atlas_score', 1);

        $this->getJson('/arena/capabilities?engine=codex_cli', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.arena.capabilities.v1')
            ->assertJsonPath('capabilities.0.capability', 'terminal_operation')
            ->assertJsonPath('capabilities.0.with_atlas', 1);

        $this->getJson('/arena/runs/live', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.arena.runs_live.v1')
            ->assertJsonPath('runs.0.status', 'running')
            ->assertJsonPath('runs.0.cases_done', 1)
            ->assertJsonPath('runs.0.cases_total', 4);
    }

    public function test_start_requires_actor_and_reason(): void
    {
        $this->postJson('/arena/runs', [
            'suites' => ['terminal_bench'],
            'engine' => 'codex_cli',
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('reason', 'operator_actor_required');

        $this->postJson('/arena/runs', [
            'suites' => ['terminal_bench'],
            'engine' => 'codex_cli',
            'operator_actor' => 'vitor',
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('reason', 'operator_reason_required');
    }

    public function test_start_rejects_suite_without_adapter(): void
    {
        $this->postJson('/arena/runs', [
            'suites' => ['not_installed'],
            'engine' => 'codex_cli',
            'arms' => ['baseline'],
            'operator_actor' => 'vitor',
            'operator_reason' => 'medir terminal',
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('reason', 'adapter_missing')
            ->assertJsonPath('suite', 'not_installed');
    }

    public function test_start_enqueues_honestly_without_faking_progress(): void
    {
        $response = $this->postJson('/arena/runs', [
            'suites' => ['terminal_bench'],
            'engine' => 'codex_cli',
            'arms' => ['baseline', 'with_atlas'],
            'operator_actor' => 'vitor',
            'operator_reason' => 'janela de medição aprovada',
        ], $this->headers)
            ->assertStatus(202)
            ->assertJsonPath('schema_version', 'atlas.arena.start_receipt.v1')
            ->assertJsonPath('status', 'enqueued')
            ->assertJsonPath('runs_planned', 2)
            ->assertJsonPath('started', false)
            ->assertJsonPath('worker_implemented', false);

        $this->assertNotSame('', (string) $response->json('receipt_hash'));

        $this->getJson('/arena/runs/live', $this->headers)
            ->assertOk()
            ->assertJsonPath('runs.0.status', 'queued')
            ->assertJsonPath('runs.0.suite', 'terminal_bench')
            ->assertJsonPath('runs.0.engine', 'codex_cli')
            ->assertJsonPath('runs.0.arm', 'baseline');
    }

    /** @param list<array<string, mixed>> $receipts */
    private function writeRun(string $runId, string $suiteId, array $receipts, string $state = 'reported'): void
    {
        RunPaths::ensureDir(RunPaths::runDir($runId));
        file_put_contents(RunPaths::nativeManifestPath($runId), json_encode([
            'schema_version' => 'atlas.rivals2.native_execution_manifest.v1',
            'run_id' => $runId,
            'suite_id' => $suiteId,
            'expected_executions' => count($receipts),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents(RunPaths::runDir($runId).'/state.json', json_encode([
            'schema_version' => 'atlas.rivals2.run_state.v2',
            'run_id' => $runId,
            'state' => $state,
            'updated_at' => '2026-07-17T01:05:00Z',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents(
            RunPaths::receiptsPath($runId),
            implode(PHP_EOL, array_map(
                fn (array $receipt): string => json_encode($receipt, JSON_UNESCAPED_SLASHES),
                $receipts
            )).PHP_EOL
        );
    }

    private function appendEvent(string $runId, string $eventType, array $data): void
    {
        file_put_contents(RunPaths::eventsPath($runId), json_encode([
            'timestamp' => '2026-07-17T01:05:00Z',
            'event_type' => $eventType,
            'data' => $data,
        ], JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND);
    }

    private function receipt(string $armId, string $caseId, string $status, string $finishedAt): array
    {
        return [
            'arm_id' => $armId,
            'case_id' => $caseId,
            'repetition' => 1,
            'status' => $status,
            'wall_ms' => 1000,
            'finished_at' => $finishedAt,
        ];
    }
}
