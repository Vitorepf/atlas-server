<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

final class EscalationSummary implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.escalation_summary.v1';

    public const TARGET_FORGE = 'forge';
    public const TARGET_OBRA_CANDIDATE = 'obra_candidate';

    public const ALLOWED_TARGETS = [
        self::TARGET_FORGE,
        self::TARGET_OBRA_CANDIDATE,
    ];

    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public readonly bool $recommended,
        public readonly ?string $target,
        public readonly array $reasons,
        public readonly ?string $decisionRef,
        public readonly bool $providerSafe = true,
    ) {
        if ($this->target !== null && ! in_array($this->target, self::ALLOWED_TARGETS, true)) {
            throw new InvalidArgumentException(
                "EscalationSummary.target must be null or one of [".implode(',', self::ALLOWED_TARGETS)."], got '{$this->target}'."
            );
        }
        if ($this->recommended && $this->target === null) {
            throw new InvalidArgumentException('EscalationSummary.recommended=true requires target.');
        }
        if ($this->recommended && $this->reasons === []) {
            throw new InvalidArgumentException('EscalationSummary.recommended=true requires non-empty reasons.');
        }
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            recommended: AtlasDevSchemaArray::bool($payload, 'recommended'),
            target: AtlasDevSchemaArray::nullableString($payload, 'target'),
            reasons: AtlasDevSchemaArray::stringList($payload, 'reasons'),
            decisionRef: AtlasDevSchemaArray::nullableString($payload, 'decision_ref'),
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
            'decision_ref' => $this->decisionRef,
            'reasons' => array_values($this->reasons),
            'recommended' => $this->recommended,
            'target' => $this->target,
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
