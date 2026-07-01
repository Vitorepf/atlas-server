<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\ProviderNegotiation;

use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\BidReceiptHashChain;
use PHPUnit\Framework\TestCase;

final class BidReceiptHashChainTest extends TestCase
{
    public function test_body_hash_is_stable_when_top_level_key_order_changes(): void
    {
        $chain = new BidReceiptHashChain();

        $a = $chain->bodyHash(['task_id' => 't1', 'winner_provider_id' => 'codex', 'decisive_criterion' => 'cost']);
        $b = $chain->bodyHash(['decisive_criterion' => 'cost', 'task_id' => 't1', 'winner_provider_id' => 'codex']);

        $this->assertSame($a, $b);
    }

    public function test_body_hash_is_stable_when_nested_array_key_order_changes(): void
    {
        $chain = new BidReceiptHashChain();

        $a = $chain->bodyHash(['task_id' => 't1', 'criteria_trace' => [['provider_id' => 'p1', 'eliminated_by' => 'cost', 'cmp' => -1]]]);
        $b = $chain->bodyHash(['task_id' => 't1', 'criteria_trace' => [['cmp' => -1, 'eliminated_by' => 'cost', 'provider_id' => 'p1']]]);

        $this->assertSame($a, $b);
    }

    public function test_stdclass_field_is_normalized_safely_without_throwing(): void
    {
        $chain = new BidReceiptHashChain();

        $obj = new \stdClass();
        $obj->foo = 'bar';
        $obj->baz = 1;

        $hash = $chain->bodyHash(['task_id' => 't1', 'meta' => $obj]);

        $this->assertSame(64, strlen($hash));
    }

    public function test_stdclass_field_normalizes_to_the_same_hash_as_equivalent_array(): void
    {
        $chain = new BidReceiptHashChain();

        $obj = new \stdClass();
        $obj->baz = 1;
        $obj->foo = 'bar';

        $a = $chain->bodyHash(['task_id' => 't1', 'meta' => $obj]);
        $b = $chain->bodyHash(['task_id' => 't1', 'meta' => ['foo' => 'bar', 'baz' => 1]]);

        $this->assertSame($a, $b);
    }

    public function test_resource_field_is_rejected(): void
    {
        $chain = new BidReceiptHashChain();
        $resource = fopen('php://memory', 'r');

        $this->expectException(\InvalidArgumentException::class);
        try {
            $chain->bodyHash(['task_id' => 't1', 'bad' => $resource]);
        } finally {
            fclose($resource);
        }
    }

    public function test_closure_field_is_rejected(): void
    {
        $chain = new BidReceiptHashChain();

        $this->expectException(\InvalidArgumentException::class);
        $chain->bodyHash(['task_id' => 't1', 'bad' => static fn () => 'x']);
    }

    public function test_changing_body_content_changes_the_chain_link(): void
    {
        $chain = new BidReceiptHashChain();

        $hashA = $chain->bodyHash(['task_id' => 't1', 'winner_provider_id' => 'codex']);
        $hashB = $chain->bodyHash(['task_id' => 't1', 'winner_provider_id' => 'gpt']);

        $linkA = $chain->chainLink(str_repeat('0', 64), $hashA);
        $linkB = $chain->chainLink(str_repeat('0', 64), $hashB);

        $this->assertNotSame($hashA, $hashB);
        $this->assertNotSame($linkA, $linkB);
        $this->assertSame(64, strlen($linkA));
    }

    public function test_chain_link_is_deterministic_for_same_inputs(): void
    {
        $chain = new BidReceiptHashChain();

        $a = $chain->chainLink('prev-x', 'body-y');
        $b = $chain->chainLink('prev-x', 'body-y');

        $this->assertSame($a, $b);
    }
}
