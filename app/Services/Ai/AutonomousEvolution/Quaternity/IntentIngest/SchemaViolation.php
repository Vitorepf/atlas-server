<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest;

/** A single schema-validation FACT — typed (missing_field, wrong_type, unknown_field, enum_violation). */
final class SchemaViolation
{
    public const KIND_MISSING_FIELD = 'missing_field';

    public const KIND_WRONG_TYPE = 'wrong_type';

    public const KIND_UNKNOWN_FIELD = 'unknown_field';

    public const KIND_ENUM_VIOLATION = 'enum_violation';

    public function __construct(
        public readonly string $kind,
        public readonly string $field,
        public readonly ?string $detail = null,
    ) {}

    /**
     * @return array{kind:string, field:string, detail:?string}
     */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'field' => $this->field, 'detail' => $this->detail];
    }
}
