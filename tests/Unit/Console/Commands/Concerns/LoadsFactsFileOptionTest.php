<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\Concerns;

use App\Console\Commands\Concerns\LoadsFactsFileOption;
use PHPUnit\Framework\TestCase;

final class LoadsFactsFileOptionTest extends TestCase
{
    public function test_missing_file_returns_empty_array(): void
    {
        $host = new class
        {
            use LoadsFactsFileOption;

            public function option(string $key): mixed
            {
                return '/tmp/does-not-exist-facts-full-pass.json';
            }

            public function load(): array
            {
                return $this->loadFacts();
            }
        };

        $this->assertSame([], $host->load());
    }

    public function test_valid_json_object_loads(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'facts');
        file_put_contents($path, '{"ok":true,"n":1}');

        $host = new class($path)
        {
            use LoadsFactsFileOption;

            public function __construct(private string $path) {}

            public function option(string $key): mixed
            {
                return $this->path;
            }

            public function load(): array
            {
                return $this->loadFacts();
            }
        };

        try {
            $this->assertSame(['ok' => true, 'n' => 1], $host->load());
        } finally {
            @unlink($path);
        }
    }
}
