<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\Concerns;

use App\Console\Commands\Concerns\LoadsNamedJsonOption;
use PHPUnit\Framework\TestCase;

final class LoadsNamedJsonOptionTest extends TestCase
{
    public function test_loads_json_object_from_path_option(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'facts');
        file_put_contents($path, '{"a":1}');
        $host = new class($path)
        {
            use LoadsNamedJsonOption;

            public array $errors = [];

            public function __construct(private string $path) {}

            public function option(string $key): mixed
            {
                return $this->path;
            }

            private function emitError(string $status, string $reason): void
            {
                $this->errors[] = [$status, $reason];
            }

            public function go(): ?array
            {
                return $this->loadJson('facts');
            }
        };
        try {
            $this->assertSame(['a' => 1], $host->go());
            $this->assertSame([], $host->errors);
        } finally {
            @unlink($path);
        }
    }

    public function test_missing_path_emits_usage_error(): void
    {
        $host = new class
        {
            use LoadsNamedJsonOption;

            public array $errors = [];

            public function option(string $key): mixed
            {
                return '/tmp/does-not-exist-named-json.json';
            }

            private function emitError(string $status, string $reason): void
            {
                $this->errors[] = [$status, $reason];
            }

            public function go(): ?array
            {
                return $this->loadJson('facts');
            }
        };
        $this->assertNull($host->go());
        $this->assertSame('usage_error', $host->errors[0][0]);
    }
}
