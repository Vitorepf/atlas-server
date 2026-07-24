<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\Concerns;

use App\Console\Commands\Concerns\ResolvesJsonOptionWithComponentsError;
use PHPUnit\Framework\TestCase;

final class ResolvesJsonOptionWithComponentsErrorTest extends TestCase
{
    public function test_inline_json_object(): void
    {
        $host = new class
        {
            use ResolvesJsonOptionWithComponentsError;

            public object $components;

            public function __construct()
            {
                $this->components = new class
                {
                    public array $errors = [];

                    public function error(string $m): void
                    {
                        $this->errors[] = $m;
                    }
                };
            }

            public function option(string $key): mixed
            {
                return '{"a":1}';
            }

            public function run(): ?array
            {
                return $this->resolveJsonOption('payload');
            }
        };

        $this->assertSame(['a' => 1], $host->run());
        $this->assertSame([], $host->components->errors);
    }

    public function test_missing_at_path_errors(): void
    {
        $host = new class
        {
            use ResolvesJsonOptionWithComponentsError;

            public object $components;

            public function __construct()
            {
                $this->components = new class
                {
                    public array $errors = [];

                    public function error(string $m): void
                    {
                        $this->errors[] = $m;
                    }
                };
            }

            public function option(string $key): mixed
            {
                return '@/tmp/full-pass-missing-json-option.json';
            }

            public function run(): ?array
            {
                return $this->resolveJsonOption('payload');
            }
        };

        $this->assertNull($host->run());
        $this->assertNotEmpty($host->components->errors);
        $this->assertStringContainsString('file not found', $host->components->errors[0]);
    }
}
