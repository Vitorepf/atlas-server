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

    /** @var array<string,list<string>> */
    public const REQUIRED = [
        'decision' => ['contexto', 'alternativas', 'porque', 'expiry'],
        'harness_learning' => ['sintoma', 'causa', 'fix', 'versao'],
        'gotcha' => ['sintoma', 'causa', 'fix', 'versao'],
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
                'schema_version' => self::SCHEMA_VERSION,
                'status' => self::STATUS_UNSCHEMATIZED,
                self::FIELD_VALID => true,
                self::FIELD_MISSING => [],
                'fail_open_entry_allowed' => true,
            ];
        }

        $missing = array_values(array_filter(
            $required,
            static fn (string $field): bool => self::isMissingFact($facts[$field] ?? null),
        ));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $missing === [] ? self::STATUS_VALID : self::STATUS_MISSING_FIELDS,
            self::FIELD_VALID => $missing === [],
            self::FIELD_MISSING => $missing,
            'fail_open_entry_allowed' => true,
            'source' => [
                'required_on_write' => false,
                'llm_extraction_hot_path' => false,
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
