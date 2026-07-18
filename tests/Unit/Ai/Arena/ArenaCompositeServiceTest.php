<?php

namespace Tests\Unit\Ai\Arena;

use App\Services\Ai\Arena\ArenaCompositeService;
use App\Services\Ai\Rivals\Support\RunPaths;
use Tests\TestCase;

class ArenaCompositeServiceTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = sys_get_temp_dir().'/arena_composite_'.uniqid('', true);
        config()->set('atlas_rivals.storage_root', $this->storage);
        config()->set('atlas_arena.suites', ['terminal_bench', 'bfcl', 'inspect_evals']);
        config()->set('atlas_arena.weights', [
            'terminal_bench' => 0.25,
            'bfcl' => 0.25,
            'inspect_evals' => 0.50,
        ]);
        config()->set('atlas_arena.arm_pair_window_minutes', 60);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storage)) {
            exec('rm -rf '.escapeshellarg($this->storage));
        }

        parent::tearDown();
    }

    public function test_composite_uses_partial_weights_and_only_pairs_multiplier_on_same_window(): void
    {
        $this->writeRun('20260717_010000_terminal', 'terminal_bench', [
            $this->receipt('codex_cli@bare', 'c1', 'success', '2026-07-17T01:01:00Z'),
            $this->receipt('codex_cli@bare', 'c2', 'failure', '2026-07-17T01:02:00Z'),
            $this->receipt('codex_cli@atlas_dev', 'c1', 'success', '2026-07-17T01:03:00Z'),
            $this->receipt('codex_cli@atlas_dev', 'c2', 'success', '2026-07-17T01:04:00Z'),
        ]);
        $this->writeRun('20260717_011500_bfcl', 'bfcl', [
            $this->receipt('codex_cli@bare', 'b1', 'success', '2026-07-17T01:16:00Z'),
        ]);

        $payload = (new ArenaCompositeService)->composite();

        $this->assertSame('atlas.arena.composite.v1', $payload['schema_version']);
        $this->assertSame(3, $payload['suites_total']);
        $this->assertSame(2, $payload['suites_measured']);
        $this->assertSame(0.5, $payload['engines'][0]['coverage']);
        $this->assertSame(0.75, $payload['engines'][0]['without_atlas_composite']);
        $this->assertSame(1.0, $payload['engines'][0]['with_atlas_composite']);
        $this->assertSame(2.0, $payload['engines'][0]['atlas_multiplier']);
        $this->assertNotEmpty($payload['engines'][0]['history']);
        $this->assertStringNotContainsString($this->storage, json_encode($payload));
        $this->assertStringNotContainsString('c1', json_encode($payload));
    }

    public function test_multiplier_is_absent_when_arms_are_not_in_same_window(): void
    {
        $this->writeRun('20260717_010000_terminal_baseline', 'terminal_bench', [
            $this->receipt('codex_cli@bare', 'c1', 'success', '2026-07-17T01:01:00Z'),
            $this->receipt('codex_cli@bare', 'c2', 'failure', '2026-07-17T01:02:00Z'),
        ]);
        $this->writeRun('20260717_060000_terminal_atlas', 'terminal_bench', [
            $this->receipt('codex_cli@atlas_dev', 'c1', 'success', '2026-07-17T06:01:00Z'),
            $this->receipt('codex_cli@atlas_dev', 'c2', 'success', '2026-07-17T06:02:00Z'),
        ]);

        $payload = (new ArenaCompositeService)->composite();

        $this->assertSame(0.5, $payload['engines'][0]['without_atlas_composite']);
        $this->assertSame(1.0, $payload['engines'][0]['with_atlas_composite']);
        $this->assertNull($payload['engines'][0]['atlas_multiplier']);
    }

    public function test_weights_must_sum_to_one(): void
    {
        config()->set('atlas_arena.weights', [
            'terminal_bench' => 0.6,
            'bfcl' => 0.6,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('arena_weights_must_sum_to_one');

        (new ArenaCompositeService)->composite();
    }

    public function test_harness_only_engines_never_appear_in_public_payloads(): void
    {
        $this->writeRun('20260717_010000_terminal', 'terminal_bench', [
            $this->receipt('codex_cli@bare', 'c1', 'success', '2026-07-17T01:01:00Z'),
            $this->receipt('mockllm@bare', 'c1', 'success', '2026-07-17T01:02:00Z'),
            $this->receipt('mockllm@atlas_dev', 'c1', 'success', '2026-07-17T01:03:00Z'),
            $this->receipt('local_fake_model@bare', 'c1', 'success', '2026-07-17T01:04:00Z'),
        ]);

        $service = new ArenaCompositeService;
        $composite = json_encode($service->composite());
        $scoreboard = json_encode($service->scoreboard());

        $this->assertStringNotContainsString('mockllm', $composite);
        $this->assertStringNotContainsString('local_fake_model', $composite);
        $this->assertStringNotContainsString('mockllm', $scoreboard);
        $this->assertStringNotContainsString('local_fake_model', $scoreboard);
        $this->assertSame('codex_cli', json_decode($composite, true)['engines'][0]['engine']);
    }

    /** @param list<array<string, mixed>> $receipts */
    private function writeRun(string $runId, string $suiteId, array $receipts): void
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
            'state' => 'reported',
            'updated_at' => '2026-07-17T01:30:00Z',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents(
            RunPaths::receiptsPath($runId),
            implode(PHP_EOL, array_map(
                fn (array $receipt): string => json_encode($receipt, JSON_UNESCAPED_SLASHES),
                $receipts
            )).PHP_EOL
        );
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
