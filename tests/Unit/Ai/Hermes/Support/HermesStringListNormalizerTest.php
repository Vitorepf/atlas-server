<?php

namespace Tests\Unit\Ai\Hermes\Support;

use App\Services\Ai\Hermes\Support\HermesStringListNormalizer;
use Tests\TestCase;

class HermesStringListNormalizerTest extends TestCase
{
    public function test_bounded_normalizes_arrays_and_csv_like_old_adapters(): void
    {
        $this->assertSame(
            ['alpha', 'beta'],
            HermesStringListNormalizer::bounded([' alpha ', 'beta', 'alpha', '', null], 10, 20),
        );

        $this->assertSame(
            ['alpha', 'beta'],
            HermesStringListNormalizer::bounded(' alpha, beta , alpha ', 10, 20),
        );
    }

    public function test_bounded_preserves_old_adapter_filtering_and_scalar_rules(): void
    {
        $this->assertSame([], HermesStringListNormalizer::bounded(123, 10, 20));
        $this->assertSame([], HermesStringListNormalizer::bounded(['0'], 10, 20));
    }

    public function test_csv_preserves_unbounded_scope_list_semantics(): void
    {
        $this->assertSame(
            ['Read', '/tmp/project'],
            HermesStringListNormalizer::csv(' Read, /tmp/project, 0, Read ', 4000),
        );
    }

    public function test_result_packet_bounded_preserves_packet_scalar_and_zero_rules(): void
    {
        $this->assertSame(['123'], HermesStringListNormalizer::resultPacketBounded(123, 10, 20));
        $this->assertSame(['0'], HermesStringListNormalizer::resultPacketBounded(['0'], 10, 20));
    }

    public function test_invocation_values_preserve_policy_field_semantics(): void
    {
        $this->assertSame(
            ['alpha', '0', '123', 'alpha'],
            HermesStringListNormalizer::invocationValues([' alpha ', '0', 123, '', 'alpha'], 20),
        );

        $this->assertSame(['alpha', 'beta'], HermesStringListNormalizer::invocationValues(' alpha, beta ', 20));
        $this->assertSame([], HermesStringListNormalizer::invocationValues(123, 20));
        $this->assertSame(['alph'], HermesStringListNormalizer::invocationValues([' alpha-long '], 4));
    }

    public function test_applies_limit_item_limit_and_dedupe(): void
    {
        $this->assertSame(
            ['alph', 'beta'],
            HermesStringListNormalizer::bounded([' alpha-long ', ' beta ', ' gamma '], 2, 4),
        );
    }

    public function test_array_unique_preserves_profile_zero_unless_falsy_filter_requested(): void
    {
        $this->assertSame(
            ['file', '0', '123'],
            HermesStringListNormalizer::arrayUnique([' file ', '0', 123, '', 'file'], 20),
        );

        $this->assertSame(
            ['file', '123'],
            HermesStringListNormalizer::arrayUnique([' file ', '0', 123, '', 'file'], 20, dropFalsyStrings: true),
        );
    }

    public function test_array_items_preserves_duplicate_cardinality_for_count_receipts(): void
    {
        $this->assertSame(
            ['done', 'done', '123'],
            HermesStringListNormalizer::arrayItems([' done ', 'done', '0', 123, ''], 20, dropFalsyStrings: true),
        );
    }

    public function test_lower_array_or_default_preserves_router_fallback_contract(): void
    {
        $this->assertSame(
            ['ops', 'research'],
            HermesStringListNormalizer::lowerArrayOrDefault([' OPS ', 'research', '0', 'OPS'], ['fallback']),
        );

        $this->assertSame(['fallback'], HermesStringListNormalizer::lowerArrayOrDefault('ops', ['fallback']));
        $this->assertSame(['fallback'], HermesStringListNormalizer::lowerArrayOrDefault([' ', 123], ['fallback']));
    }
}
