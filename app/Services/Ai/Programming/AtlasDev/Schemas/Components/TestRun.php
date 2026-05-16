<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

final class TestRun implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.test_run.v1';

    public function __construct(
        public readonly string $command,
        public readonly bool $ok,
        public readonly int $exitCode,
        public readonly int $durationMs,
        public readonly string $outputHash,
        public readonly ?string $outputPath,
        public readonly bool $providerSafe = true,
    ) {
        if ($this->command === '') {
            throw new InvalidArgumentException('TestRun.command must not be empty.');
        }
        if ($this->durationMs < 0) {
            throw new InvalidArgumentException('TestRun.duration_ms must be non-negative.');
        }
        if ($this->outputHash === '') {
            throw new InvalidArgumentException('TestRun.output_hash must not be empty.');
        }
        if ($this->ok && $this->exitCode !== 0) {
            throw new InvalidArgumentException('TestRun.ok=true requires exit_code=0.');
        }
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            command: AtlasDevSchemaArray::string($payload, 'command'),
            ok: AtlasDevSchemaArray::bool($payload, 'ok'),
            exitCode: AtlasDevSchemaArray::int($payload, 'exit_code'),
            durationMs: AtlasDevSchemaArray::int($payload, 'duration_ms'),
            outputHash: AtlasDevSchemaArray::string($payload, 'output_hash'),
            outputPath: AtlasDevSchemaArray::nullableString($payload, 'output_path'),
            providerSafe: array_key_exists('provider_safe', $payload)
                ? AtlasDevSchemaArray::bool($payload, 'provider_safe')
                : true,
        );
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'command' => $this->command,
            'duration_ms' => $this->durationMs,
            'exit_code' => $this->exitCode,
            'ok' => $this->ok,
            'output_hash' => $this->outputHash,
            'output_path' => $this->outputPath,
        ]);
    }

    public function toProviderSafeArray(): array
    {
        return $this->toCanonicalArray();
    }

    public function toJson(): string
    {
        return CanonicalJson::encode($this->toCanonicalArray());
    }

    public function hash(): string
    {
        return CanonicalHasher::hash($this->toCanonicalArray());
    }

    public function isProviderSafe(): bool
    {
        return $this->providerSafe;
    }
}
