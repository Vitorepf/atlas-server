<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\Concerns;

use App\Console\Commands\Concerns\ReadsNonEmptyLiteralStringOption;
use App\Console\Commands\Concerns\ReadsNonEmptyUntrimmedStringOption;
use App\Console\Commands\Concerns\ReadsRawStringOption;
use PHPUnit\Framework\TestCase;

final class StringOptionVariantTraitsTest extends TestCase
{
    public function test_raw_preserves_empty_string(): void
    {
        $host = new class
        {
            use ReadsRawStringOption;

            public function option(string $key): mixed
            {
                return '';
            }

            public function go(): ?string
            {
                return $this->stringOption('x');
            }
        };
        $this->assertSame('', $host->go());
    }

    public function test_untrimmed_rejects_whitespace_only_keeps_padding(): void
    {
        $host = new class
        {
            use ReadsNonEmptyUntrimmedStringOption;

            public string $raw = '  hi  ';

            public function option(string $key): mixed
            {
                return $this->raw;
            }

            public function go(): ?string
            {
                return $this->stringOption('x');
            }
        };
        $this->assertSame('  hi  ', $host->go());
        $host->raw = '   ';
        $this->assertNull($host->go());
    }

    public function test_literal_nonempty_keeps_spaces_only(): void
    {
        $host = new class
        {
            use ReadsNonEmptyLiteralStringOption;

            public function option(string $key): mixed
            {
                return '  ';
            }

            public function go(): ?string
            {
                return $this->stringOption('x');
            }
        };
        $this->assertSame('  ', $host->go());
    }
}
