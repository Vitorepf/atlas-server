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
