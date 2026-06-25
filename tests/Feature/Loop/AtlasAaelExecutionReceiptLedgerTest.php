<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Aael\AtlasAaelExecutionReceiptLedger;
use App\Services\Ai\AutonomousEvolution\AtlasAaelLoopExecutionBridge;
use Tests\TestCase;

final class AtlasAaelExecutionReceiptLedgerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-aael-receipts-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            foreach ((array) glob($this->root.'/*') as $f) {
                @unlink((string) $f);
            }
            @rmdir($this->root);
        }
        parent::tearDown();
    }

    private function ledger(?string $root = null, string $clockValue = '2026-06-24T12:00:00+00:00'): AtlasAaelExecutionReceiptLedger
    {
        $l = new AtlasAaelExecutionReceiptLedger($root ?? $this->root);
        $l->setClock(fn (): string => $clockValue);

        return $l;
    }

    private function runner(): object
    {
        return new class
        {
            public function run(array $tasks, array $options = []): array
            {
                return [
                    'schema_version' => 'fake',
                    'propose_only' => true,
                    'merged_to_main' => false,
                    'tasks_processed' => count($tasks),
                    'proposals_certified_for_review' => 0,
                    'stop_reason' => 'queue_exhausted',
                    'elapsed_seconds' => 0.0,
                    'proposals' => [],
                    'explorations' => array_map(static fn ($t): array => ['objective' => $t['objective'] ?? ''], $tasks),
                ];
            }
        };
    }

    private function opportunity(string $objective): array
    {
        return [
            'opportunity_id' => 'opp-'.$objective,
            'objective' => $objective,
            'ambition' => 'beat',
            'scope' => 'mixed',
            'task' => [
                'objective' => $objective,
                'acceptance' => [
                    'metric' => 'unit_test_pass_count',
                    'baseline' => 0,
                    'target' => 1,
                    'comparator' => 'greater_than',
                ],
            ],
        ];
    }

    public function test_bridge_writes_one_idempotent_receipt_validating_the_schema(): void
    {
        $ledger = $this->ledger();
        $bridge = new AtlasAaelLoopExecutionBridge($this->runner(), null, null, null, $ledger);

        $opps = [$this->opportunity('improve-foo')];
        $r1 = $bridge->execute($opps);

        $this->assertSame(AtlasAaelExecutionReceiptLedger::STATUS_OK, $r1['ledger_status']);
        $this->assertNotEmpty($r1['execution_id']);

        $files = (array) glob($this->root.'/*.json');
        $this->assertCount(1, $files);
        $body1 = (string) file_get_contents((string) $files[0]);

        $decoded = json_decode($body1, true);
        $this->assertSame(AtlasAaelExecutionReceiptLedger::SCHEMA, $decoded['schema_version']);
        foreach (['execution_id', 'prover_verdict', 'runner_result', 'drift_audit'] as $key) {
            $this->assertArrayHasKey($key, $decoded);
        }

        // Idempotent rewrite — identical inputs + identical clock => byte-identical file, status=ok.
        $r2 = $bridge->execute($opps);
        $this->assertSame(AtlasAaelExecutionReceiptLedger::STATUS_OK, $r2['ledger_status']);
        $this->assertSame($r1['execution_id'], $r2['execution_id']);
        $body2 = (string) file_get_contents((string) $files[0]);
        $this->assertSame($body1, $body2, 'identical inputs must yield byte-identical files');
    }

    public function test_list_returns_receipts_ordered_by_timestamp_desc(): void
    {
        $bridgeAt = function (string $iso): AtlasAaelLoopExecutionBridge {
            $l = $this->ledger($this->root, $iso);

            return new AtlasAaelLoopExecutionBridge($this->runner(), null, null, null, $l);
        };

        $bridgeAt('2026-06-24T10:00:00+00:00')->execute([$this->opportunity('a')]);
        $bridgeAt('2026-06-24T12:00:00+00:00')->execute([$this->opportunity('b')]);
        $bridgeAt('2026-06-24T11:00:00+00:00')->execute([$this->opportunity('c')]);

        $rows = $this->ledger()->list();
        $this->assertSame(
            ['2026-06-24T12:00:00+00:00', '2026-06-24T11:00:00+00:00', '2026-06-24T10:00:00+00:00'],
            array_column($rows, 'recorded_at'),
        );
    }

    public function test_read_only_storage_surfaces_ledger_status_error_without_corrupting_loop_output(): void
    {
        $badRoot = '/dev/null/atlas-aael-cannot-write';
        $ledger = $this->ledger($badRoot);
        $bridge = new AtlasAaelLoopExecutionBridge($this->runner(), null, null, null, $ledger);

        $result = $bridge->execute([$this->opportunity('improve-bar')]);

        $this->assertSame(AtlasAaelExecutionReceiptLedger::STATUS_ERROR, $result['ledger_status']);
        // Loop output preserved — every canonical envelope key is still present and the loop_run shape
        // is intact even though the ledger could not write.
        foreach (['schema_version', 'opportunities_total', 'executed_tasks', 'deferred', 'loop_run', 'merged_to_main'] as $key) {
            $this->assertArrayHasKey($key, $result, "ledger error must NOT corrupt envelope key {$key}");
        }
        $this->assertSame(false, $result['merged_to_main']);
        $this->assertArrayHasKey('stop_reason', $result['loop_run']);
    }

    public function test_overwrite_with_mutated_content_is_refused_as_conflict(): void
    {
        $ledger = $this->ledger();
        $opps = [$this->opportunity('immutable')];
        $first = $ledger->record($opps, ['v' => 1], ['x' => 1], []);
        $this->assertSame(AtlasAaelExecutionReceiptLedger::STATUS_OK, $first['status']);

        // Same execution_id reproduces only with same payload + same recorded_at — emulate the collision
        // by clobbering the file with mutated bytes, then asking the ledger to record again under the
        // same input. The ledger sees an existing file with content != current body ⇒ conflict.
        file_put_contents($first['path'], '{"mutated":true}');
        $second = $ledger->record($opps, ['v' => 1], ['x' => 1], []);
        $this->assertSame(AtlasAaelExecutionReceiptLedger::STATUS_CONFLICT, $second['status']);
        $this->assertSame('{"mutated":true}', (string) file_get_contents($first['path']), 'conflict must NOT rewrite');
    }
}
