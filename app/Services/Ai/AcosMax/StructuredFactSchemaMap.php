<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

final class StructuredFactSchemaMap
{
    public const SCHEMA_VERSION = 'atlas.memory.structured_facts.v1';

    /** @var array<string,list<string>> */
    private const REQUIRED = [
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
                'status' => 'unschematized',
                'valid' => true,
                'missing' => [],
                'fail_open_entry_allowed' => true,
            ];
        }

        $missing = array_values(array_filter(
            $required,
            static fn (string $field): bool => ! array_key_exists($field, $facts) || $facts[$field] === null || $facts[$field] === '',
        ));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $missing === [] ? 'valid' : 'missing_fields',
            'valid' => $missing === [],
            'missing' => $missing,
            'fail_open_entry_allowed' => true,
            'source' => [
                'required_on_write' => false,
                'llm_extraction_hot_path' => false,
            ],
        ];
    }
}
