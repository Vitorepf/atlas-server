<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Telemetry;

use InvalidArgumentException;

final class AtlasLoopTelemetryFactSchemaRegistry
{
    /** @var list<string> */
    private const FORBIDDEN_KEYS = ['score', 'rank', 'grade', 'quality', 'judgement'];

    /** @var array<string, array{schema_id:string,required_keys:list<string>,key_types:array<string,string>,forbidden_keys:list<string>}> */
    private const SCHEMAS = [
        'claim' => [
            'schema_id' => 'atlas.loop.telemetry.claim.v1',
            'required_keys' => ['cycle_id', 'kind', 'occurred_at_iso', 'scope', 'schema_version', 'payload'],
            'key_types' => [
                'cycle_id' => 'string',
                'kind' => 'string',
                'occurred_at_iso' => 'string',
                'scope' => 'string',
                'schema_version' => 'string',
                'payload' => 'array',
            ],
            'forbidden_keys' => self::FORBIDDEN_KEYS,
        ],
        'lease' => [
            'schema_id' => 'atlas.loop.telemetry.lease.v1',
            'required_keys' => ['cycle_id', 'kind', 'occurred_at_iso', 'scope', 'schema_version', 'payload'],
            'key_types' => [
                'cycle_id' => 'string',
                'kind' => 'string',
                'occurred_at_iso' => 'string',
                'scope' => 'string',
                'schema_version' => 'string',
                'payload' => 'array',
            ],
            'forbidden_keys' => self::FORBIDDEN_KEYS,
        ],
        'serve' => [
            'schema_id' => 'atlas.loop.telemetry.serve.v1',
            'required_keys' => ['cycle_id', 'kind', 'occurred_at_iso', 'scope', 'schema_version', 'payload'],
            'key_types' => [
                'cycle_id' => 'string',
                'kind' => 'string',
                'occurred_at_iso' => 'string',
                'scope' => 'string',
                'schema_version' => 'string',
                'payload' => 'array',
            ],
            'forbidden_keys' => self::FORBIDDEN_KEYS,
        ],
        'report' => [
            'schema_id' => 'atlas.loop.telemetry.report.v1',
            'required_keys' => ['cycle_id', 'kind', 'occurred_at_iso', 'scope', 'schema_version', 'payload'],
            'key_types' => [
                'cycle_id' => 'string',
                'kind' => 'string',
                'occurred_at_iso' => 'string',
                'scope' => 'string',
                'schema_version' => 'string',
                'payload' => 'array',
            ],
            'forbidden_keys' => self::FORBIDDEN_KEYS,
        ],
        'merge' => [
            'schema_id' => 'atlas.loop.telemetry.merge.v1',
            'required_keys' => ['cycle_id', 'kind', 'occurred_at_iso', 'scope', 'schema_version', 'payload'],
            'key_types' => [
                'cycle_id' => 'string',
                'kind' => 'string',
                'occurred_at_iso' => 'string',
                'scope' => 'string',
                'schema_version' => 'string',
                'payload' => 'array',
            ],
            'forbidden_keys' => self::FORBIDDEN_KEYS,
        ],
    ];

    /**
     * @return array{schema_id:string,required_keys:list<string>,key_types:array<string,string>,forbidden_keys:list<string>}
     */
    public function schemaFor(string $kind): array
    {
        $kind = trim($kind);
        if (! isset(self::SCHEMAS[$kind])) {
            throw new InvalidArgumentException('Unknown loop telemetry fact kind: '.$kind);
        }

        return self::SCHEMAS[$kind];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok:bool,reasons:list<string>}
     */
    public function validate(string $kind, array $payload): array
    {
        $schema = $this->schemaFor($kind);
        $reasons = [];

        foreach ($schema['required_keys'] as $requiredKey) {
            if (! array_key_exists($requiredKey, $payload)) {
                $reasons[] = 'missing-required:'.$requiredKey;
            }
        }

        foreach ($schema['key_types'] as $key => $expectedType) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            if (! $this->matchesType($payload[$key], $expectedType)) {
                $reasons[] = 'wrong-type:'.$key;
            }
        }

        foreach ($this->forbiddenKeyHits($payload, $schema['forbidden_keys']) as $hit) {
            $reasons[] = 'forbidden-key-present:'.$hit;
        }

        $reasons = array_values(array_unique($reasons));

        return [
            'ok' => $reasons === [],
            'reasons' => $reasons,
        ];
    }

    private function matchesType(mixed $value, string $expectedType): bool
    {
        return match ($expectedType) {
            'string' => is_string($value),
            'array' => is_array($value),
            'int' => is_int($value),
            'bool' => is_bool($value),
            'float' => is_float($value),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     * @param  list<string>  $forbiddenKeys
     * @return list<string>
     */
    private function forbiddenKeyHits(array $payload, array $forbiddenKeys): array
    {
        $hits = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $forbiddenKeys, true)) {
                $hits[] = strtolower($key);
            }

            if (is_array($value)) {
                array_push($hits, ...$this->forbiddenKeyHits($value, $forbiddenKeys));
            }
        }

        $hits = array_values(array_unique($hits));
        sort($hits);

        return $hits;
    }
}
