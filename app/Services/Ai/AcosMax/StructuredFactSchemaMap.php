<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;

final class StructuredFactSchemaMap
{
    public const SCHEMA_VERSION = 'atlas.memory.structured_facts.v1';

    public const STATUS_UNSCHEMATIZED = 'unschematized';

    public const STATUS_VALID = 'valid';

    public const STATUS_MISSING_FIELDS = 'missing_fields';

    public const FIELD_MISSING = 'missing';

    public const FIELD_VALID = 'valid';
    public const FIELD_FAIL_OPEN_ENTRY_ALLOWED = 'fail_open_entry_allowed';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_STATUS = 'status';
    public const FIELD_DECISION = 'decision';
    public const FIELD_GOTCHA = 'gotcha';
    public const FIELD_HARNESS_LEARNING = 'harness_learning';
    public const FIELD_LLM_EXTRACTION_HOT_PATH = 'llm_extraction_hot_path';
    public const FIELD_SOURCE = 'source';
    public const FIELD_REQUIRED_ON_WRITE = 'required_on_write';
    public const FIELD_CAUSA = 'causa';
    public const FIELD_ALTERNATIVAS = 'alternativas';
    public const FIELD_FIX = 'fix';
    public const FIELD_PORQUE = 'porque';

    /** @var array<string,list<string>> */
    public const REQUIRED = [
        self::FIELD_DECISION => ['contexto', self::FIELD_ALTERNATIVAS, self::FIELD_PORQUE, 'expiry'],
        self::FIELD_HARNESS_LEARNING => ['sintoma', self::FIELD_CAUSA, self::FIELD_FIX, 'versao'],
        self::FIELD_GOTCHA => ['sintoma', self::FIELD_CAUSA, self::FIELD_FIX, 'versao'],
    ];

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public static function validate(string $memoryType, array $facts): array
    {
        $required = self::REQUIRED[$memoryType] ?? null;
        if ($required === null) {
            return [
                self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
                self::FIELD_STATUS => self::STATUS_UNSCHEMATIZED,
                self::FIELD_VALID => true,
                self::FIELD_MISSING => [],
                self::FIELD_FAIL_OPEN_ENTRY_ALLOWED => true,
            ];
        }

        $missing = array_values(array_filter(
            $required,
            static fn (string $field): bool => self::isMissingFact($facts[$field] ?? null),
        ));

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_STATUS => $missing === [] ? self::STATUS_VALID : self::STATUS_MISSING_FIELDS,
            self::FIELD_VALID => $missing === [],
            self::FIELD_MISSING => $missing,
            self::FIELD_FAIL_OPEN_ENTRY_ALLOWED => true,
            self::FIELD_SOURCE => [
                self::FIELD_REQUIRED_ON_WRITE => false,
                self::FIELD_LLM_EXTRACTION_HOT_PATH => false,
            ],
        ];
    }

    private static function isMissingFact(mixed $value): bool
    {
        if ($value === null || is_string($value)) {
            return AiValueNormalizer::trimmedStringOrNull($value) === null;
        }

        return false;
    }
}
