<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\ClosedLoop\AtlasMaestroOutcomePatternMiner;
use App\Services\Ai\SelfConstruction\Maestro\ClosedLoop\AtlasMaestroOutcomeShapeLedger;
use Tests\TestCase;

final class AtlasMaestroOutcomePatternMinerTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-maestro-pattern-miner-'.bin2hex(random_bytes(5)).'.jsonl';
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path.'.lock'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_mine_emits_factual_buckets_with_support_and_delivery_rate(): void
    {
        $ledger = new AtlasMaestroOutcomeShapeLedger($this->path);
        foreach (['delivered', 'delivered', 'delivered', 'delivered', 'delivered', 'give_back', 'rejected', 'stale'] as $i => $outcome) {
            $ledger->record('high-'.$i, $this->shapeFacts(), $outcome);
        }
        $ledger->record('low-1', $this->shapeFacts(['origin_kind' => 'doc_gap', 'allowed_files_count' => 7]), 'give_back');
        $ledger->record('low-2', $this->shapeFacts(['origin_kind' => 'doc_gap', 'allowed_files_count' => 7]), 'stale');

        $facts = (new AtlasMaestroOutcomePatternMiner($ledger))->mine();

        $high = $facts['origin_kind']['orphan'];
        $this->assertSame(5, $high['delivered']);
        $this->assertSame(1, $high['give_back']);
        $this->assertSame(1, $high['rejected']);
        $this->assertSame(1, $high['stale']);
        $this->assertSame(8, $high['total']);
        $this->assertFalse($high['insufficient_support']);
        $this->assertSame(5 / 8, $high['delivery_rate']);

        $low = $facts['origin_kind']['doc_gap'];
        $this->assertSame(2, $low['total']);
        $this->assertTrue($low['insufficient_support']);
        $this->assertNull($low['delivery_rate']);
        $this->assertNoCompositeScore($facts);
    }

    public function test_new_dimensions_are_mined_from_raw_ledger_rows(): void
    {
        $schema = AtlasMaestroOutcomeShapeLedger::SCHEMA;
        for ($i = 0; $i < 8; $i++) {
            file_put_contents($this->path, json_encode([
                'schema' => $schema, 'task_packet_id' => 'strat-'.$i,
                'origin_kind' => 'orphan', 'allowed_files_count' => 2,
                'scope_in_size' => 2, 'acceptance_criteria_count' => 3,
                'required_evidence_count' => 1, 'has_tests_path' => true,
                'wave_bucket' => 'w0', 'file_family' => 'SelfConstruction',
                'task_shape' => 'bug-fix', 'worker_id' => 'claude-muscle-2',
                'proof_command_class' => 'artisan-test', 'outcome' => 'delivered',
            ])."\n", FILE_APPEND);
        }

        $ledger = new AtlasMaestroOutcomeShapeLedger($this->path);
        $facts = (new AtlasMaestroOutcomePatternMiner($ledger))->mine();

        foreach (['file_family', 'task_shape', 'worker_id', 'proof_command_class'] as $dim) {
            $this->assertArrayHasKey($dim, $facts, "mine() must include dimension {$dim}");
        }
        $this->assertSame(1.0, $facts['file_family']['SelfConstruction']['delivery_rate']);
        $this->assertSame(1.0, $facts['task_shape']['bug-fix']['delivery_rate']);
        $this->assertSame(1.0, $facts['worker_id']['claude-muscle-2']['delivery_rate']);
        $this->assertSame(1.0, $facts['proof_command_class']['artisan-test']['delivery_rate']);
        $this->assertNoCompositeScore($facts);
    }

    public function test_strategy_signals_returns_high_delivery_rate_buckets_sorted(): void
    {
        $schema = AtlasMaestroOutcomeShapeLedger::SCHEMA;
        for ($i = 0; $i < 8; $i++) {
            file_put_contents($this->path, json_encode([
                'schema' => $schema, 'task_packet_id' => 'hi-'.$i,
                'origin_kind' => 'orphan', 'allowed_files_count' => 2,
                'scope_in_size' => 2, 'acceptance_criteria_count' => 3,
                'required_evidence_count' => 1, 'has_tests_path' => true,
                'wave_bucket' => 'w0', 'file_family' => 'SelfConstruction',
                'task_shape' => 'bug-fix', 'worker_id' => 'claude-muscle-2',
                'proof_command_class' => 'artisan-test', 'outcome' => 'delivered',
            ])."\n", FILE_APPEND);
        }
        for ($i = 0; $i < 8; $i++) {
            file_put_contents($this->path, json_encode([
                'schema' => $schema, 'task_packet_id' => 'lo-'.$i,
                'origin_kind' => 'orphan', 'allowed_files_count' => 2,
                'scope_in_size' => 2, 'acceptance_criteria_count' => 3,
                'required_evidence_count' => 1, 'has_tests_path' => true,
                'wave_bucket' => 'w0', 'file_family' => 'Maestro',
                'task_shape' => 'refactor', 'worker_id' => 'codex-1',
                'proof_command_class' => 'artisan-test',
                'outcome' => $i < 4 ? 'delivered' : 'give_back',
            ])."\n", FILE_APPEND);
        }

        $ledger = new AtlasMaestroOutcomeShapeLedger($this->path);
        $signals = (new AtlasMaestroOutcomePatternMiner($ledger))->strategySignals();

        $this->assertNotEmpty($signals);
        foreach ($signals as $signal) {
            $this->assertArrayHasKey('dimension', $signal);
            $this->assertArrayHasKey('bucket', $signal);
            $this->assertArrayHasKey('delivery_rate', $signal);
            $this->assertArrayHasKey('support', $signal);
            $this->assertGreaterThanOrEqual(0.5, $signal['delivery_rate']);
        }

        $rates = array_column($signals, 'delivery_rate');
        $sorted = $rates;
        rsort($sorted);
        $this->assertSame($sorted, $rates, 'strategySignals must be sorted by delivery_rate DESC');
    }

    public function test_strategy_signals_empty_when_no_sufficient_support(): void
    {
        $ledger = new AtlasMaestroOutcomeShapeLedger($this->path);
        $ledger->record('t1', $this->shapeFacts(), 'delivered');
        $ledger->record('t2', $this->shapeFacts(), 'delivered');

        $this->assertSame([], (new AtlasMaestroOutcomePatternMiner($ledger))->strategySignals());
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function shapeFacts(array $overrides = []): array
    {
        return $overrides + [
            'origin_kind' => 'orphan',
            'allowed_files_count' => 2,
            'scope_in_size' => 2,
            'acceptance_criteria_count' => 3,
            'required_evidence_count' => 1,
            'has_tests_path' => true,
            'wave_bucket' => 'w0',
        ];
    }

    private function assertNoCompositeScore(mixed $value): void
    {
        if (! is_array($value)) {
            return;
        }
        foreach (['score', 'quality', 'rank', 'ranking'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $value);
        }
        foreach ($value as $child) {
            $this->assertNoCompositeScore($child);
        }
    }
}
