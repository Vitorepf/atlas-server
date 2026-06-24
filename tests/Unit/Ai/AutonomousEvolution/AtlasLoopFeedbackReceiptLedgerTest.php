<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Feedback\AtlasLoopFeedbackReceiptLedger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Proves the feedback receipt ledger: record() returns an id + full receipt; the append-only contract (N
 * records ⇒ count N, prior receipts byte-identical); deterministic chronological list(limit); and a surface
 * with no mutation API.
 */
final class AtlasLoopFeedbackReceiptLedgerTest extends TestCase
{
    private function ledger(): AtlasLoopFeedbackReceiptLedger
    {
        return new AtlasLoopFeedbackReceiptLedger;
    }

    /** @return array<string,mixed> */
    private function application(string $reason): array
    {
        return [
            'input_records' => [['packet_class' => 'Foo', 'outcome' => 'give_back', 'reason' => $reason]],
            'mined_facts' => [
                'top_reasons' => [['reason' => $reason, 'count' => 1]],
                'give_back_rate_by_class' => ['Foo' => 1.0],
                'worker_concentration' => ['claude-1' => 1],
            ],
            'context_keys_added' => ['give_back_facts'],
        ];
    }

    public function test_record_returns_id_and_full_receipt(): void
    {
        $ledger = $this->ledger();
        $id = $ledger->record($this->application('forbidden_self_target'));

        $this->assertNotSame('', $id);
        $receipt = $ledger->list(10)[0];
        foreach (['id', 'applied_at', 'input_records_sha256', 'mined_facts_summary', 'context_keys_added'] as $field) {
            $this->assertArrayHasKey($field, $receipt);
        }
        $this->assertSame($id, $receipt['id']);
        $this->assertSame(['give_back_facts'], $receipt['context_keys_added']);
        $this->assertSame(64, strlen($receipt['input_records_sha256']), 'sha256 hex length');
    }

    public function test_append_only_prior_receipts_are_byte_identical(): void
    {
        $ledger = $this->ledger();

        $ledger->record($this->application('r1'));
        $afterOne = $ledger->list(100);

        $ledger->record($this->application('r2'));
        $ledger->record($this->application('r3'));
        $afterThree = $ledger->list(100);

        $this->assertSame(3, $ledger->count(), 'count == N');
        $this->assertSame($afterOne[0], $afterThree[0], 'prior receipt unchanged — no in-place mutation');
        // distinct ids per record.
        $this->assertSame(3, count(array_unique(array_column($afterThree, 'id'))));
    }

    public function test_list_is_chronological_and_respects_limit(): void
    {
        $ledger = $this->ledger();
        $ledger->record($this->application('a'));
        $ledger->record($this->application('b'));
        $ledger->record($this->application('c'));

        $all = $ledger->list(100);
        $this->assertSame([1, 2, 3], array_column($all, 'seq'), 'chronological by insertion');

        $this->assertCount(2, $ledger->list(2));
        $this->assertSame([1, 2], array_column($ledger->list(2), 'seq'));
        $this->assertSame([], $ledger->list(0));
    }

    public function test_no_mutation_api_is_exposed(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(AtlasLoopFeedbackReceiptLedger::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        foreach ($methods as $name) {
            $this->assertDoesNotMatchRegularExpression('/^(update|delete|mutate|edit|remove|overwrite|truncate)/i', $name, "append-only: no mutation method '{$name}'");
        }
        $this->assertContains('record', $methods);
        $this->assertContains('list', $methods);
    }
}
