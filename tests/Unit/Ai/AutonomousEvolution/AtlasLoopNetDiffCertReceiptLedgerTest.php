<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopNetDiffCertReceiptLedger;
use ReflectionClass;
use Tests\TestCase;

final class AtlasLoopNetDiffCertReceiptLedgerTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-netdiff-receipts-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function ledger(): AtlasLoopNetDiffCertReceiptLedger
    {
        return new AtlasLoopNetDiffCertReceiptLedger($this->path);
    }

    private function receipt(string $certId, string $attemptId, string $verdict = 'pass'): array
    {
        return [
            'cert_id' => $certId,
            'attempt_id' => $attemptId,
            'verdict' => $verdict,
            'metric_kind' => 'minimize',
            'baseline' => 10,
            'candidate' => 7,
            'signed_delta' => -3,
            'min_delta_used' => 1,
            'tolerance_used' => 0,
            'baseline_sha' => 'base'.$attemptId,
            'candidate_sha' => 'cand'.$attemptId,
            'collector_reason' => 'ok',
            'judge_reason' => 'delta_met',
        ];
    }

    public function test_append_is_idempotent_per_cert_and_attempt(): void
    {
        $ledger = $this->ledger();
        $first = $ledger->append($this->receipt('cert-1', 'a1'));
        $again = $ledger->append($this->receipt('cert-1', 'a1'));

        $this->assertSame($first['id'], $again['id'], 'same (cert_id,attempt_id) => same id');
        $this->assertCount(1, $ledger->history('cert-1'), 'idempotent append => ONE row');
    }

    public function test_exposes_zero_mutation_methods(): void
    {
        $rc = new ReflectionClass(AtlasLoopNetDiffCertReceiptLedger::class);
        $names = array_map(static fn ($m): string => strtolower($m->getName()), $rc->getMethods(\ReflectionMethod::IS_PUBLIC));

        $this->assertNotContains('update', $names);
        $this->assertNotContains('delete', $names);
        $this->assertContains('append', $names);
        $this->assertContains('history', $names);
    }

    public function test_history_chain_links_each_receipt_to_the_previous_canonical_sha(): void
    {
        $ledger = $this->ledger();
        $ledger->append($this->receipt('cert-x', 'a1'));
        $ledger->append($this->receipt('cert-x', 'a2'));
        $ledger->append($this->receipt('cert-x', 'a3'));

        $history = $ledger->history('cert-x');
        $this->assertCount(3, $history);

        // First receipt has no prior link.
        $this->assertNull($history[0]['prior_receipt_chain_sha']);

        // Each subsequent receipt chains to the sha256 of the previous FULL receipt's canonical JSON.
        for ($i = 1; $i < count($history); $i++) {
            $expected = hash('sha256', $ledger->canonicalJson($history[$i - 1]));
            $this->assertNotNull($history[$i]['prior_receipt_chain_sha']);
            $this->assertSame($expected, $history[$i]['prior_receipt_chain_sha'], "chain break at index {$i}");
        }
    }

    public function test_history_is_scoped_per_cert(): void
    {
        $ledger = $this->ledger();
        $ledger->append($this->receipt('cert-a', 'a1'));
        $ledger->append($this->receipt('cert-b', 'b1'));
        $ledger->append($this->receipt('cert-a', 'a2'));

        $this->assertCount(2, $ledger->history('cert-a'));
        $this->assertCount(1, $ledger->history('cert-b'));
    }
}
