<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionContext;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class ReadinessProjectionContextTest extends TestCase
{
    public function test_table_probes_are_frozen_and_fail_closed_for_unprobed_tables(): void
    {
        $context = new ReadinessProjectionContext(
            ['atlas_probed_present' => true, 'atlas_probed_absent' => false],
            CarbonImmutable::parse('2026-07-22T12:00:00Z'),
        );

        $this->assertTrue($context->hasTable('atlas_probed_present'));
        $this->assertFalse($context->hasTable('atlas_probed_absent'));
        $this->assertFalse($context->hasTable('atlas_never_probed'), 'unprobed table must fail closed');
        $this->assertTrue($context->wasProbed('atlas_probed_absent'));
        $this->assertFalse($context->wasProbed('atlas_never_probed'));
        $this->assertSame(['atlas_probed_present', 'atlas_probed_absent'], $context->probedTables());
        $this->assertSame('2026-07-22T12:00:00+00:00', $context->now()->toIso8601String());
    }

    public function test_snapshots_are_frozen_at_build_time(): void
    {
        $context = new ReadinessProjectionContext(
            [],
            CarbonImmutable::now(),
            ['queue_registry' => ['total_count' => 3], 'nullable' => null],
        );

        $this->assertSame(['total_count' => 3], $context->snapshot('queue_registry'));
        $this->assertTrue($context->hasSnapshot('nullable'));
        $this->assertNull($context->snapshot('nullable'));
        $this->assertFalse($context->hasSnapshot('missing'));
        $this->assertNull($context->snapshot('missing'));
    }

    public function test_chain_memo_computes_each_key_exactly_once(): void
    {
        $context = new ReadinessProjectionContext([], CarbonImmutable::now());
        $calls = 0;
        $compute = function () use (&$calls): array {
            $calls++;

            return ['chain_hash' => 'abc'];
        };

        $first = $context->rememberChain('review_merge.chain_a', $compute);
        $second = $context->rememberChain('review_merge.chain_a', $compute);
        $other = $context->rememberChain('review_merge.chain_b', static fn (): string => 'other');

        $this->assertSame(['chain_hash' => 'abc'], $first);
        $this->assertSame($first, $second);
        $this->assertSame(1, $calls, 'memo must compute once per key per context');
        $this->assertSame('other', $other);
    }

    public function test_memoized_null_is_not_recomputed(): void
    {
        $context = new ReadinessProjectionContext([], CarbonImmutable::now());
        $calls = 0;
        $compute = function () use (&$calls) {
            $calls++;

            return null;
        };

        $context->rememberChain('null_chain', $compute);
        $context->rememberChain('null_chain', $compute);

        $this->assertSame(1, $calls);
    }
}
