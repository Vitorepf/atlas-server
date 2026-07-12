<?php

declare(strict_types=1);

namespace App\Services\Ai\Governance;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class GovernanceAmendmentLedger
{
    public const SCHEMA_VERSION = 'atlas.governance.amendment_ledger.v1';

    public function __construct(private readonly ?string $path = null) {}

    public function path(): string
    {
        if ($this->path !== null) {
            return $this->path;
        }

        $base = $this->runningUnderPhpunit()
            ? sys_get_temp_dir().DIRECTORY_SEPARATOR.'atlas-phpunit'.DIRECTORY_SEPARATOR.$this->testToken().DIRECTORY_SEPARATOR.'atlas'.DIRECTORY_SEPARATOR.'governance'
            : (function_exists('storage_path') ? storage_path('atlas/governance') : sys_get_temp_dir().DIRECTORY_SEPARATOR.'atlas'.DIRECTORY_SEPARATOR.'governance');

        return $base.DIRECTORY_SEPARATOR.'governance-amendments.jsonl';
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function history(): array
    {
        $path = $this->path();
        if (! is_file($path)) {
            return [];
        }

        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return array<string,mixed>
     */
    public function append(array $entry): array
    {
        $entry = array_replace([
            'schema_version' => self::SCHEMA_VERSION,
            'recorded_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
        ], $entry);
        $entry['entry_hash'] = 'sha256:'.hash('sha256', json_encode($entry, JSON_THROW_ON_ERROR));

        $path = $this->path();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents(
            $path,
            json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );

        return $entry;
    }

    private function runningUnderPhpunit(): bool
    {
        return getenv('APP_ENV') === 'testing'
            || ($_ENV['APP_ENV'] ?? null) === 'testing'
            || ($_SERVER['APP_ENV'] ?? null) === 'testing';
    }

    private function testToken(): string
    {
        return (string) (getenv('TEST_TOKEN') ?: getmypid());
    }
}
