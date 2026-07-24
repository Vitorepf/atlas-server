<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\Concerns;

use App\Console\Commands\Concerns\LoadsFactsJsonOption;
use PHPUnit\Framework\TestCase;

final class LoadsFactsJsonOptionTest extends TestCase
{
    public function test_load_facts_reads_json_object(): void
    {
        $path = sys_get_temp_dir().'/atlas-facts-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(['ok' => true, 'n' => 1], JSON_THROW_ON_ERROR));

        try {
            $host = new LoadsFactsJsonOptionHost(['facts' => $path, 'json' => true]);
            $facts = $host->callLoadFacts();
            $this->assertIsArray($facts);
            $this->assertTrue($facts['ok']);
            $this->assertSame(1, $facts['n']);
            $this->assertSame([], $host->messages);
        } finally {
            @unlink($path);
        }
    }

    public function test_load_facts_missing_path_returns_null_and_usage_json(): void
    {
        $host = new LoadsFactsJsonOptionHost(['facts' => '', 'json' => true]);
        $this->assertNull($host->callLoadFacts());
        $this->assertNotEmpty($host->messages);
        $decoded = json_decode((string) $host->messages[0], true);
        $this->assertIsArray($decoded);
        $this->assertSame('usage_error', $decoded['status'] ?? null);
    }

    public function test_load_facts_rejects_scalar_json_root(): void
    {
        $path = sys_get_temp_dir().'/atlas-facts-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode('not-an-object', JSON_THROW_ON_ERROR));

        try {
            $host = new LoadsFactsJsonOptionHost(['facts' => $path, 'json' => true]);
            $this->assertNull($host->callLoadFacts());
            $decoded = json_decode((string) ($host->messages[0] ?? ''), true);
            $this->assertSame('usage_error', $decoded['status'] ?? null);
        } finally {
            @unlink($path);
        }
    }
}

/**
 * Minimal host for the trait (mirrors Illuminate Command option/line/error surface).
 */
final class LoadsFactsJsonOptionHost
{
    use LoadsFactsJsonOption;

    /** @var list<string> */
    public array $messages = [];

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(private array $options)
    {
    }

    public function callLoadFacts(): ?array
    {
        return $this->loadFacts();
    }

    public function option($key = null, $default = null): mixed
    {
        if ($key === null) {
            return $this->options;
        }

        return $this->options[$key] ?? $default;
    }

    public function line($string, $style = null, $verbosity = null): void
    {
        $this->messages[] = (string) $string;
    }

    public function error($string, $verbosity = null): void
    {
        $this->messages[] = (string) $string;
    }
}
