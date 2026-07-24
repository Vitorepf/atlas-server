<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\Concerns;

use App\Console\Commands\Concerns\ParsesKeyValueMetadataOption;
use PHPUnit\Framework\TestCase;

final class ParsesKeyValueMetadataOptionTest extends TestCase
{
    public function test_parses_key_value_pairs(): void
    {
        $host = new class(['a=1', 'b=two', 'skip', 'x=y=z'])
        {
            use ParsesKeyValueMetadataOption;

            /**
             * @param  list<string>  $meta
             */
            public function __construct(private array $meta)
            {
            }

            public function option($key = null)
            {
                return $this->meta;
            }

            public function call(): array
            {
                return $this->metadata();
            }
        };

        $this->assertSame(['a' => '1', 'b' => 'two', 'x' => 'y=z'], $host->call());
    }
}
