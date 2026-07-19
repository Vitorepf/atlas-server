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
        config()->set('atlas_arena.min_cases_for_confidence', 4);
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

        $this->assertSame('atlas.arena.capabilities.v2', $payload['schema_version']);
        $this->assertSame('arena.capability_map.v1', $payload['mapping_version']);
        $this->assertCount(2, $payload['capabilities']);

        $byCapability = array_column($payload['capabilities'], null, 'capability');
        $this->assertSame(0.5, $byCapability['terminal_operation']['score']);
        $this->assertSame(1.0, $byCapability['terminal_operation']['with_atlas']);
        $this->assertSame(['terminal_bench'], $byCapability['terminal_operation']['suites_contributing']);
        $this->assertSame(2, $byCapability['terminal_operation']['cases_total']);

        // Cada braço traz seu N e o IC 95% de Wilson — nunca só o ponto.
        $this->assertSame(2, $byCapability['terminal_operation']['baseline_cases']);
        $this->assertSame(2, $byCapability['terminal_operation']['with_atlas_cases']);
        $this->assertIsArray($byCapability['terminal_operation']['baseline_ci']);
        $this->assertCount(2, $byCapability['terminal_operation']['baseline_ci']);
        $this->assertLessThan($byCapability['terminal_operation']['baseline_ci'][1], $byCapability['terminal_operation']['baseline_ci'][0]);
        $this->assertIsArray($byCapability['terminal_operation']['delta']);

        $this->assertSame(0.5, $byCapability['code_editing']['score']);
        $this->assertArrayNotHasKey('context_retrieval', $byCapability);
    }

    public function test_pools_cases_across_runs_for_volume(): void
    {
        // Duas rodadas da MESMA suíte/braço: o N tem que SOMAR (volume), não ficar
        // preso na última rodada. Base: 3/6 na 1ª + 2/4 na 2ª = 5/10.
        $this->writeRun('20260717_010000_a', 'terminal_bench', array_merge(
            $this->cases('codex_cli@bare', 'a', successes: 3, failures: 3, at: '2026-07-17T01:00:00Z'),
        ));
        $this->writeRun('20260718_010000_b', 'terminal_bench', array_merge(
            $this->cases('codex_cli@bare', 'b', successes: 2, failures: 2, at: '2026-07-18T01:00:00Z'),
        ));

        $payload = (new ArenaCapabilityProfileService)->profile('codex_cli');
        $byCapability = array_column($payload['capabilities'], null, 'capability');

        $this->assertSame(10, $byCapability['terminal_operation']['baseline_cases'], 'N deve somar as duas rodadas');
        $this->assertSame(0.5, $byCapability['terminal_operation']['score']);
    }

    public function test_ci_narrows_as_volume_grows(): void
    {
        $wide = $this->ciWidth($this->profileFor([
            $this->cases('codex_cli@bare', 'x', successes: 2, failures: 2, at: '2026-07-17T01:00:00Z'),
        ]));
        $narrow = $this->ciWidth($this->profileFor([
            $this->cases('codex_cli@bare', 'y', successes: 20, failures: 20, at: '2026-07-17T02:00:00Z'),
        ]));

        $this->assertGreaterThan($narrow, $wide, 'mais casos → IC mais estreito');
    }

    public function test_low_volume_flags_low_confidence_not_false_truth(): void
    {
        // 2 casos por braço, piso = 4 → medido, mas NÃO confiável = 'low'.
        $this->writeRun('20260717_010000_t', 'terminal_bench', [
            $this->receipt('codex_cli@bare', 'c1', 'success', '2026-07-17T01:01:00Z'),
            $this->receipt('codex_cli@bare', 'c2', 'failure', '2026-07-17T01:02:00Z'),
            $this->receipt('codex_cli@atlas_dev', 'c1', 'success', '2026-07-17T01:03:00Z'),
            $this->receipt('codex_cli@atlas_dev', 'c2', 'success', '2026-07-17T01:04:00Z'),
        ]);

        $byCapability = array_column((new ArenaCapabilityProfileService)->profile('codex_cli')['capabilities'], null, 'capability');
        $this->assertSame('low', $byCapability['terminal_operation']['confidence']);
        $this->assertSame(4, $byCapability['terminal_operation']['min_cases_for_confidence']);
    }

    public function test_measured_confidence_and_delta_significance_on_clear_win(): void
    {
        // Base 2/10, Atlas 9/10 — vitória clara, N ≥ piso: measured + delta signif.
        $this->writeRun('20260717_010000_w', 'terminal_bench', array_merge(
            $this->cases('codex_cli@bare', 'b', successes: 2, failures: 8, at: '2026-07-17T01:00:00Z'),
            $this->cases('codex_cli@atlas_dev', 'a', successes: 9, failures: 1, at: '2026-07-17T02:00:00Z'),
        ));

        $byCapability = array_column((new ArenaCapabilityProfileService)->profile('codex_cli')['capabilities'], null, 'capability');
        $cap = $byCapability['terminal_operation'];
        $this->assertSame('measured', $cap['confidence']);
        $this->assertGreaterThan(0.0, $cap['delta']['value']);
        $this->assertTrue($cap['delta']['significant'], 'IC do delta não cruza zero');
    }

    public function test_unmeasured_when_one_arm_absent(): void
    {
        // Só o braço base rodou: sem Atlas não há comparação → unmeasured, delta null.
        $this->writeRun('20260717_010000_u', 'terminal_bench',
            $this->cases('codex_cli@bare', 'b', successes: 5, failures: 5, at: '2026-07-17T01:00:00Z'),
        );

        $byCapability = array_column((new ArenaCapabilityProfileService)->profile('codex_cli')['capabilities'], null, 'capability');
        $cap = $byCapability['terminal_operation'];
        $this->assertSame('unmeasured', $cap['confidence']);
        $this->assertNull($cap['delta']);
        $this->assertNull($cap['with_atlas']);
        $this->assertSame(0.5, $cap['score']);
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

    /** @param list<list<array<string,mixed>>> $receiptGroups */
    private function profileFor(array $receiptGroups): array
    {
        $storage = sys_get_temp_dir().'/arena_ci_'.uniqid('', true);
        config()->set('atlas_rivals.storage_root', $storage);
        $flat = array_merge(...$receiptGroups);
        RunPaths::ensureDir(RunPaths::runDir('20260717_010000_ci'));
        file_put_contents(RunPaths::nativeManifestPath('20260717_010000_ci'), json_encode([
            'schema_version' => 'atlas.rivals2.native_execution_manifest.v1',
            'run_id' => '20260717_010000_ci', 'suite_id' => 'terminal_bench',
            'expected_executions' => count($flat),
        ]));
        file_put_contents(RunPaths::receiptsPath('20260717_010000_ci'), implode(PHP_EOL, array_map(
            fn (array $r): string => json_encode($r, JSON_UNESCAPED_SLASHES), $flat
        )).PHP_EOL);
        $out = (new ArenaCapabilityProfileService)->profile('codex_cli');
        exec('rm -rf '.escapeshellarg($storage));

        return $out;
    }

    private function ciWidth(array $payload): float
    {
        $cap = array_column($payload['capabilities'], null, 'capability')['terminal_operation'];
        [$low, $high] = $cap['baseline_ci'];

        return $high - $low;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function cases(string $armId, string $prefix, int $successes, int $failures, string $at): array
    {
        $out = [];
        for ($i = 0; $i < $successes; $i++) {
            $out[] = $this->receipt($armId, $prefix.'_s'.$i, 'success', $at);
        }
        for ($i = 0; $i < $failures; $i++) {
            $out[] = $this->receipt($armId, $prefix.'_f'.$i, 'failure', $at);
        }

        return $out;
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
