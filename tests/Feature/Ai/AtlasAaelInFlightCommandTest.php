<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\Aael\Execution\InFlight\AtlasAaelInFlightReceiptLedger;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AtlasAaelInFlightCommandTest extends TestCase
{
    private string $ledgerPath = '';

    private string $runId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(4));
        $this->ledgerPath = sys_get_temp_dir().'/atlas-aael-inflight-'.$tag.'.jsonl';
        $this->runId = 'run-'.$tag;

        $ledger = new AtlasAaelInFlightReceiptLedger($this->ledgerPath);
        app()->instance(AtlasAaelInFlightReceiptLedger::class, $ledger);

        // Seed a deterministic stream: step 1 holds, step 2 BREAKS permanently.
        $ledger->appendValidation($this->runId, 1, [
            'schema_version' => 'atlas.aael.inflight_step_validator.v1',
            'passed' => true,
            'facts' => [[
                'invariant_id' => 'inv.alpha',
                'holds' => true,
                'observed_value' => 1,
                'declared_value' => 1,
                'delta' => 0,
                'step_index' => 1,
                'reason' => null,
            ]],
            'reason' => null,
        ]);
        $ledger->appendValidation($this->runId, 2, [
            'schema_version' => 'atlas.aael.inflight_step_validator.v1',
            'passed' => false,
            'facts' => [[
                'invariant_id' => 'inv.alpha',
                'holds' => false,
                'observed_value' => 5,
                'declared_value' => 1,
                'delta' => 4,
                'step_index' => 2,
                'reason' => 'drifted',
            ]],
            'reason' => ['code' => 'invariant_broken', 'step_index' => 2],
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function runCmd(array $params): array
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:aael:inflight', $params, $buf);

        return ['exit' => $exit, 'output' => $buf->fetch()];
    }

    public function test_history_json_returns_seeded_receipts_in_append_order(): void
    {
        $r = $this->runCmd(['action' => 'history', 'run_id' => $this->runId, '--json' => true]);
        self::assertSame(0, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        self::assertIsArray($payload);
        self::assertCount(2, $payload);
        $seqs = array_column($payload, 'seq');
        $sorted = $seqs;
        sort($sorted, SORT_NUMERIC);
        self::assertSame($sorted, $seqs);
        self::assertSame([1, 2], array_column($payload, 'step_index'));
    }

    public function test_drift_human_contains_first_break_step_index_and_no_score_words(): void
    {
        $r = $this->runCmd(['action' => 'drift', 'run_id' => $this->runId]);
        self::assertSame(0, $r['exit']);
        self::assertStringContainsString('first_break_step_index', $r['output']);
        self::assertStringContainsString('2', $r['output']);
        foreach (['score', 'quality', 'grade'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $r['output']);
        }
    }

    public function test_validate_emits_fact_shape_for_recorded_step(): void
    {
        $r = $this->runCmd([
            'action' => 'validate',
            'run_id' => $this->runId,
            'step_index' => 2,
            '--json' => true,
        ]);
        self::assertSame(0, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('facts', $payload);
        self::assertSame(false, $payload['passed']);
    }

    public function test_unknown_action_returns_non_zero(): void
    {
        $r = $this->runCmd(['action' => 'bogus', 'run_id' => $this->runId]);
        self::assertNotSame(0, $r['exit']);
        self::assertStringContainsString('unknown_action', $r['output']);
    }
}
