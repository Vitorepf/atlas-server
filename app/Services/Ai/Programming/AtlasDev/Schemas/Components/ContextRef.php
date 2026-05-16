<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

final class ContextRef implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.context_ref.v1';

    public const KIND_MEMORY = 'memory';

    public const KIND_KNOWLEDGE = 'knowledge';

    public const KIND_CODE = 'code';

    public const KIND_DOC = 'doc';

    public const KIND_SECTION = 'section';

    public const KIND_SYMBOL = 'symbol';

    public const KIND_FILE = 'file';

    public const KIND_ROUTE = 'route';

    public const KIND_COMMAND = 'command';

    public const KIND_TEST = 'test';

    public const KIND_DOC_LINK = 'doc_link';

    public const KIND_LEARNING = 'learning';

    public const KIND_DECISION = 'decision';

    public const KIND_TECHNICAL_CONTEXT = 'technical_context';

    public const KIND_HARNESS_LEARNING = 'harness_learning';

    public function __construct(
        public readonly string $kind,
        public readonly string $ref,
        public readonly string $reason,
    ) {
        if ($kind === '' || $ref === '' || $reason === '') {
            throw new InvalidArgumentException('ContextRef requires non-empty kind, ref and reason.');
        }
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'kind' => $this->kind,
            'ref' => $this->ref,
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
        return true;
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            kind: AtlasDevSchemaArray::string($payload, 'kind'),
            ref: AtlasDevSchemaArray::string($payload, 'ref'),
            reason: AtlasDevSchemaArray::string($payload, 'reason'),
        );
    }
}
