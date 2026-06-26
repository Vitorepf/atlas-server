<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Support;

use App\Services\Ai\SelfConstruction\Support\NormalizesToStringList;
use Tests\TestCase;

final class NormalizesToStringListTest extends TestCase
{
    public function test_trims_whitespace_around_each_entry(): void
    {
        $host = $this->makeHost();

        $this->assertSame(
            ['alpha', 'beta', 'gamma'],
            $host->call(['  alpha  ', "\tbeta\n", ' gamma'])
        );
    }

    public function test_drops_empty_and_whitespace_only_entries(): void
    {
        $host = $this->makeHost();

        $this->assertSame(
            ['kept', 'kept_too'],
            $host->call(['', '  ', "\n\t", 'kept', '', 'kept_too'])
        );
    }

    public function test_casts_non_strings_via_string_cast(): void
    {
        $host = $this->makeHost();

        // Note: array_filter (without callback) drops values that are falsy
        // AFTER the (string) cast — so false/null become '' and are filtered.
        // true becomes '1', which survives.
        $result = $host->call([1, 2.5, true, false, null, 'already']);

        $this->assertSame(['1', '2.5', '1', 'already'], $result);
    }

    public function test_reindexes_result_as_list(): void
    {
        $host = $this->makeHost();

        $input = ['a' => 'first', 'b' => 'second', 'x' => 'third'];
        $result = $host->call($input);

        $this->assertSame([0, 1, 2], array_keys($result));
        $this->assertSame(['first', 'second', 'third'], array_values($result));
    }

    public function test_empty_input_yields_empty_list(): void
    {
        $host = $this->makeHost();

        $this->assertSame([], $host->call([]));
    }

    public function test_input_with_only_empty_strings_yields_empty_list(): void
    {
        $host = $this->makeHost();

        $this->assertSame([], $host->call(['', '  ', "\t", "\n"]));
    }

    public function test_preserves_order_of_kept_entries(): void
    {
        $host = $this->makeHost();

        $this->assertSame(
            ['c', 'a', 'b'],
            $host->call(['', 'c', 'a', '', 'b'])
        );
    }

    public function test_handles_strings_that_become_empty_after_trim(): void
    {
        $host = $this->makeHost();

        $this->assertSame(
            ['kept'],
            $host->call(['  kept  ', '   ', "\t\n"])
        );
    }

    private function makeHost(): object
    {
        return new class
        {
            use NormalizesToStringList;

            public function call(array $values): array
            {
                return $this->doStringList($values);
            }

            private function doStringList(array $values): array
            {
                return $this->stringList($values);
            }
        };
    }
}
