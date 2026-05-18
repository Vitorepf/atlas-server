<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

/**
 * Single hypothesis emitted by Atlas Dev Debug Intelligence.
 *
 * A suspected cause is a structured guess about WHY a failure happened.
 * Each carries its own confidence and is paired with a `Why` rationale + a
 * pointer (`file_hint` / `line_hint` / `evidence_ref_kinds`) so the
 * RepairCandidate downstream can target the right surface.
 */
final class DebugSuspectedCause implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.debug_suspected_cause.v1';

    /** Cause category — taxonomy aligned with `FastPathErrorLedgerEntry::FAILURE_MODE_*`. */
    public const CATEGORY_WRONG_FILE = 'wrong_file';

    public const CATEGORY_WRONG_SCOPE = 'wrong_scope';

    public const CATEGORY_MISSED_TEST = 'missed_test';

    public const CATEGORY_BAD_REPAIR = 'bad_repair';

    public const CATEGORY_MISSED_ESCALATION = 'missed_escalation';

    public const CATEGORY_FALSE_ESCALATION = 'false_escalation';

    public const CATEGORY_PROMPT_PROJECTION_ERROR = 'prompt_projection_error';

    public const CATEGORY_CONTEXT_ERROR = 'context_error';

    public const CATEGORY_DEPENDENCY = 'dependency';

    public const CATEGORY_RACE_CONDITION = 'race_condition';

    public const CATEGORY_NULL_OR_MISSING = 'null_or_missing';

    public const CATEGORY_TYPE_MISMATCH = 'type_mismatch';

    public const CATEGORY_AUTH_PERMISSION = 'auth_permission';

    public const CATEGORY_CONFIG = 'config';

    public const CATEGORY_OTHER = 'other';

    public const ALLOWED_CATEGORIES = [
        self::CATEGORY_WRONG_FILE,
        self::CATEGORY_WRONG_SCOPE,
        self::CATEGORY_MISSED_TEST,
        self::CATEGORY_BAD_REPAIR,
        self::CATEGORY_MISSED_ESCALATION,
        self::CATEGORY_FALSE_ESCALATION,
        self::CATEGORY_PROMPT_PROJECTION_ERROR,
        self::CATEGORY_CONTEXT_ERROR,
        self::CATEGORY_DEPENDENCY,
        self::CATEGORY_RACE_CONDITION,
        self::CATEGORY_NULL_OR_MISSING,
        self::CATEGORY_TYPE_MISMATCH,
        self::CATEGORY_AUTH_PERMISSION,
        self::CATEGORY_CONFIG,
        self::CATEGORY_OTHER,
    ];

    /**
     * @param  list<string>  $evidenceRefKinds  evidence kinds that back this cause
     */
    public function __construct(
        public readonly string $category,
        public readonly string $why,
        public readonly float $confidence,
        public readonly ?string $fileHint = null,
        public readonly ?int $lineHint = null,
        public readonly ?string $symbolHint = null,
        public readonly array $evidenceRefKinds = [],
    ) {
        if (! in_array($this->category, self::ALLOWED_CATEGORIES, true)) {
            throw new InvalidArgumentException(
                'DebugSuspectedCause.category must be one of ['.implode(',', self::ALLOWED_CATEGORIES)."], got '{$this->category}'."
            );
        }
        if (trim($this->why) === '') {
            throw new InvalidArgumentException('DebugSuspectedCause.why must not be empty.');
        }
        if ($this->confidence < 0.0 || $this->confidence > 1.0) {
            throw new InvalidArgumentException(
                "DebugSuspectedCause.confidence must be in [0.0, 1.0], got {$this->confidence}."
            );
        }
        if ($this->lineHint !== null && $this->lineHint < 1) {
            throw new InvalidArgumentException('DebugSuspectedCause.line_hint must be >= 1 when provided.');
        }
        foreach ($this->evidenceRefKinds as $i => $kind) {
            if (! is_string($kind) || trim($kind) === '') {
                throw new InvalidArgumentException("DebugSuspectedCause.evidence_ref_kinds[{$i}] must be a non-empty string.");
            }
        }
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'category' => $this->category,
            'confidence' => $this->confidence,
            'evidence_ref_kinds' => array_values($this->evidenceRefKinds),
            'file_hint' => $this->fileHint,
            'line_hint' => $this->lineHint,
            'symbol_hint' => $this->symbolHint,
            'why' => $this->why,
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
        if (! array_key_exists('confidence', $payload) || ! is_numeric($payload['confidence'])) {
            throw new InvalidArgumentException("Field 'confidence' must be numeric.");
        }

        return new self(
            category: AtlasDevSchemaArray::string($payload, 'category'),
            why: AtlasDevSchemaArray::string($payload, 'why'),
            confidence: (float) $payload['confidence'],
            fileHint: AtlasDevSchemaArray::nullableString($payload, 'file_hint'),
            lineHint: AtlasDevSchemaArray::nullableInt($payload, 'line_hint'),
            symbolHint: AtlasDevSchemaArray::nullableString($payload, 'symbol_hint'),
            evidenceRefKinds: AtlasDevSchemaArray::stringList($payload, 'evidence_ref_kinds'),
        );
    }
}
