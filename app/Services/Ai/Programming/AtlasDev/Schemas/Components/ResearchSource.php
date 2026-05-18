<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use InvalidArgumentException;

/**
 * A single audited source consulted during a research run.
 *
 * `kind` taxonomy is intentionally narrow: research must cite something that
 * Atlas can produce a hash for (`canonical_doc`, `code_symbol`, `test_log`,
 * `evidence_ledger_entry`) or an explicitly external pointer
 * (`external_reference`) that the operator approves. Free-form web URLs are
 * NOT a kind — that would let unverified content into the runtime.
 */
final class ResearchSource
{
    public const KIND_CANONICAL_DOC = 'canonical_doc';

    public const KIND_CODE_SYMBOL = 'code_symbol';

    public const KIND_TEST_LOG = 'test_log';

    public const KIND_EVIDENCE_LEDGER_ENTRY = 'evidence_ledger_entry';

    public const KIND_EXTERNAL_REFERENCE = 'external_reference';

    public const ALLOWED_KINDS = [
        self::KIND_CANONICAL_DOC,
        self::KIND_CODE_SYMBOL,
        self::KIND_TEST_LOG,
        self::KIND_EVIDENCE_LEDGER_ENTRY,
        self::KIND_EXTERNAL_REFERENCE,
    ];

    public function __construct(
        public readonly string $kind,
        public readonly string $ref,
        public readonly ?string $hash = null,
        public readonly ?string $excerpt = null,
    ) {
        if (! in_array($this->kind, self::ALLOWED_KINDS, true)) {
            throw new InvalidArgumentException(
                'ResearchSource.kind must be one of ['.implode(',', self::ALLOWED_KINDS)."], got '{$this->kind}'."
            );
        }
        if (trim($this->ref) === '') {
            throw new InvalidArgumentException('ResearchSource.ref must not be empty.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function toCanonicalArray(): array
    {
        return [
            'excerpt' => $this->excerpt,
            'hash' => $this->hash,
            'kind' => $this->kind,
            'ref' => $this->ref,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            kind: AtlasDevSchemaArray::string($payload, 'kind'),
            ref: AtlasDevSchemaArray::string($payload, 'ref'),
            hash: AtlasDevSchemaArray::nullableString($payload, 'hash'),
            excerpt: AtlasDevSchemaArray::nullableString($payload, 'excerpt'),
        );
    }
}
