<?php

namespace Tests\Unit\Ai\Arena;

use App\Services\Ai\Arena\ArenaCapabilityProfileService;
use App\Services\Ai\Rivals\Support\RunPaths;
use Tests\TestCase;

class ArenaCapabilityProfileServiceTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = sys_get_temp_dir().'/arena_capabilities_'.uniqid('', true);
        config()->set('atlas_rivals.storage_root', $this->storage);
        config()->set('atlas_arena.capability_labels_pt', [
            'terminal_operation' => 'Operação de terminal',
            'code_editing' => 'Edição de código',
            'context_retrieval' => 'Recuperação de contexto',
        ]);
        config()->set('atlas_arena.capability_map', [
            'terminal_bench' => [
                ['capability' => 'terminal_operation', 'weight' => 0.60],
                ['capability' => 'code_editing', 'weight' => 0.40],
            ],
            'inspect_evals' => [
                ['capability' => 'context_retrieval', 'weight' => 1.00],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storage)) {
            exec('rm -rf '.escapeshellarg($this->storage));
        }

        parent::tearDown();
    }

    public function test_profile_aggregates_suite_into_multiple_capabilities(): void
    {
        $this->writeRun('20260717_010000_terminal', 'terminal_bench', [
            $this->receipt('codex_cli@bare', 'c1', 'success', '2026-07-17T01:01:00Z'),
            $this->receipt('codex_cli@bare', 'c2', 'failure', '2026-07-17T01:02:00Z'),
            $this->receipt('codex_cli@atlas_dev', 'c1', 'success', '2026-07-17T01:03:00Z'),
            $this->receipt('codex_cli@atlas_dev', 'c2', 'success', '2026-07-17T01:04:00Z'),
        ]);

        $payload = (new ArenaCapabilityProfileService)->profile('codex_cli');

        $this->assertSame('atlas.arena.capabilities.v1', $payload['schema_version']);
        $this->assertSame('arena.capability_map.v1', $payload['mapping_version']);
        $this->assertCount(2, $payload['capabilities']);

        $byCapability = array_column($payload['capabilities'], null, 'capability');
        $this->assertSame(0.5, $byCapability['terminal_operation']['score']);
        $this->assertSame(1.0, $byCapability['terminal_operation']['with_atlas']);
        $this->assertSame(['terminal_bench'], $byCapability['terminal_operation']['suites_contributing']);
        $this->assertSame(2, $byCapability['terminal_operation']['cases_total']);

        $this->assertSame(0.5, $byCapability['code_editing']['score']);
        $this->assertArrayNotHasKey('context_retrieval', $byCapability);
    }

    public function test_engine_filter_keeps_other_engines_out(): void
    {
        $this->writeRun('20260717_010000_terminal', 'terminal_bench', [
            $this->receipt('codex_cli@bare', 'c1', 'success', '2026-07-17T01:01:00Z'),
            $this->receipt('hermes@bare', 'c1', 'failure', '2026-07-17T01:01:00Z'),
        ]);

        $payload = (new ArenaCapabilityProfileService)->profile('hermes');

        $byCapability = array_column($payload['capabilities'], null, 'capability');
        $this->assertSame(0.0, $byCapability['terminal_operation']['score']);
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
