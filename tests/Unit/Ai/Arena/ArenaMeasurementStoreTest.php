<?php

namespace Tests\Unit\Ai\Arena;

use App\Services\Ai\Arena\ArenaMeasurementStore;
use App\Services\Ai\Rivals\Support\RunPaths;
use Tests\TestCase;

class ArenaMeasurementStoreTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = sys_get_temp_dir().'/arena_store_'.uniqid('', true);
        config()->set('atlas_rivals.storage_root', $this->storage);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->storage));
        parent::tearDown();
    }

    public function test_blocked_candidate_via_bridge_error_codes_is_not_a_false_zero(): void
    {
        // REGRESSÃO (2026-07-20): 67 recibos históricos tinham
        // candidate_preparation_blocked SÓ em
        // metadata.runtime_bridge.provider_call.error_codes (failure_reason vazio,
        // failure_class=model_failure) → a exclusão que só lia failure_reason não
        // disparava e o braço Atlas levava 0 FALSO. Bloqueado antes do corretor =
        // NÃO MEDIDO, nunca derrota.
        $this->writeRun('20260720_020000_tb', 'terminal_bench', [
            // 0 falso: bloqueado antes de ser corrigido — deve SUMIR do pool
            [
                'arm_id' => 'codex_cli@atlas_dev',
                'case_id' => 'blocked',
                'repetition' => 1,
                'status' => 'failure',
                'failure_class' => 'model_failure',
                'failure_reason' => null,
                'wall_ms' => 1000,
                'finished_at' => '2026-07-20T02:01:00Z',
                'metadata' => ['runtime_bridge' => ['provider_call' => ['error_codes' => [
                    'candidate_preparation_blocked:sandbox_sandbox_apply_failed:create_target_already_exists:answer.json',
                ]]]],
            ],
            // derrota REAL do modelo — tem que continuar contando
            [
                'arm_id' => 'codex_cli@atlas_dev',
                'case_id' => 'real_loss',
                'repetition' => 1,
                'status' => 'failure',
                'failure_class' => 'model_failure',
                'wall_ms' => 1000,
                'finished_at' => '2026-07-20T02:02:00Z',
            ],
            // vitória real — controle
            [
                'arm_id' => 'codex_cli@atlas_dev',
                'case_id' => 'real_win',
                'repetition' => 1,
                'status' => 'success',
                'wall_ms' => 1000,
                'finished_at' => '2026-07-20T02:03:00Z',
            ],
        ]);

        $rows = (new ArenaMeasurementStore)->measurements();

        $this->assertCount(1, $rows);
        $this->assertSame('terminal_bench', $rows[0]['suite']);
        $this->assertSame('with_atlas', $rows[0]['arm']);
        $this->assertSame(2, $rows[0]['cases_total'], 'bloqueado não pode contar nem como caso');
        $this->assertSame(1, $rows[0]['cases_passed']);
        $this->assertSame(1, $rows[0]['cases_failed'], 'só a derrota real conta como derrota');
    }

    public function test_blocked_candidate_with_success_status_still_counts(): void
    {
        // Se o corretor chegou a pontuar sucesso, o error_code histórico do bridge
        // não pode apagar a vitória — a exclusão só vale para status != success.
        $this->writeRun('20260720_030000_tb', 'terminal_bench', [
            [
                'arm_id' => 'codex_cli@atlas_dev',
                'case_id' => 'retried_ok',
                'repetition' => 1,
                'status' => 'success',
                'wall_ms' => 1000,
                'finished_at' => '2026-07-20T03:01:00Z',
                'metadata' => ['runtime_bridge' => ['provider_call' => ['error_codes' => [
                    'candidate_preparation_blocked:transient',
                ]]]],
            ],
        ]);

        $rows = (new ArenaMeasurementStore)->measurements();

        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['cases_passed']);
        $this->assertSame(0, $rows[0]['cases_failed']);
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
}
