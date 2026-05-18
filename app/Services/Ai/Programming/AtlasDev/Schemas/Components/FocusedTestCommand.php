<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

/**
 * A single focused test command that Test Selection Intelligence recommends.
 *
 * The trio (command, reason, confidence) is the minimum unit auditors need to
 * decide whether the selection is trustworthy and what gap remains. Confidence
 * is bucketed (low|medium|high) on purpose — fine-grained numeric scores are
 * not load-bearing and just create noise in receipts.
 */
final class FocusedTestCommand implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.focused_test_command.v1';

    public const CONFIDENCE_LOW = 'low';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_HIGH = 'high';

    public const ALLOWED_CONFIDENCES = [
        self::CONFIDENCE_LOW,
        self::CONFIDENCE_MEDIUM,
        self::CONFIDENCE_HIGH,
    ];

    public function __construct(
        public readonly string $command,
        public readonly string $reason,
        public readonly string $confidence,
        public readonly bool $providerSafe = true,
    ) {
        if ($this->command === '') {
            throw new InvalidArgumentException('FocusedTestCommand.command must not be empty.');
        }
        if ($this->reason === '') {
            throw new InvalidArgumentException('FocusedTestCommand.reason must not be empty.');
        }
        if (! in_array($this->confidence, self::ALLOWED_CONFIDENCES, true)) {
            throw new InvalidArgumentException(
                'FocusedTestCommand.confidence must be one of ['.implode(',', self::ALLOWED_CONFIDENCES)."], got '{$this->confidence}'."
            );
        }
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            command: AtlasDevSchemaArray::string($payload, 'command'),
            reason: AtlasDevSchemaArray::string($payload, 'reason'),
            confidence: AtlasDevSchemaArray::string($payload, 'confidence'),
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
            'confidence' => $this->confidence,
            'reason' => $this->reason,
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
