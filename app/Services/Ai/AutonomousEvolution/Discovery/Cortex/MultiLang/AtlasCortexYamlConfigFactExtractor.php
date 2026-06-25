<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\MultiLang;

use RuntimeException;

/**
 * FACT-only extractor over YAML config files within the Loop scope.
 *
 * extract($absolutePath) returns:
 *   {
 *     keys: list<{path: 'a.b.c', type: 'string'|'int'|'bool'|'list'|'map'|'null'|'float', line: int}>,
 *     top_level_kinds: list<string>,
 *     sha256: string,
 *   }
 *
 * No scoring. No judgement. No provider call.
 *
 * Refuses any path outside Loop scope roots (the AutonomousEvolution / Cortex / Loop directories).
 * Output keys are sorted by path → two runs produce byte-identical json.
 */
final class AtlasCortexYamlConfigFactExtractor
{
    public const LANGUAGE_ID = 'yaml';

    public const SCHEMA = 'atlas.cortex.yaml_config_facts.v1';

    /** @var list<string> */
    public const ALLOWED_SCOPE_FRAGMENTS = [
        'app/Services/Ai/AutonomousEvolution',
        'app/Console/Commands',
        '/.atlas/',
        '/atlas/',
    ];

    public static function registerInto(AtlasCortexLanguageRegistry $registry): void
    {
        $registry->register(self::LANGUAGE_ID, [
            'parser_class' => self::class,
            'extractor_class' => self::class,
            'extensions' => ['yaml', 'yml'],
            'namespace_roots' => self::ALLOWED_SCOPE_FRAGMENTS,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function extract(string $absolutePath): array
    {
        $this->assertInScope($absolutePath);
        if (! is_file($absolutePath)) {
            throw new RuntimeException('yaml_path_not_found:'.$absolutePath);
        }

        $bytes = (string) file_get_contents($absolutePath);
        $parsed = $this->parseSimpleYaml($bytes);

        $keys = [];
        $this->walk('', $parsed, $keys);
        usort($keys, static fn (array $a, array $b): int => strcmp((string) $a['path'], (string) $b['path']));

        $topLevelKinds = [];
        if (is_array($parsed)) {
            foreach ($parsed as $k => $v) {
                $topLevelKinds[] = $this->typeOf($v);
            }
            sort($topLevelKinds, SORT_STRING);
        }

        return [
            'schema_version' => self::SCHEMA,
            'keys' => $keys,
            'top_level_kinds' => $topLevelKinds,
            'sha256' => hash('sha256', $bytes),
        ];
    }

    /**
     * @param  mixed  $value
     * @param  list<array<string,mixed>>  $keys
     */
    private function walk(string $prefix, mixed $value, array &$keys): void
    {
        if (is_array($value)) {
            $isList = array_keys($value) === range(0, count($value) - 1);
            if ($isList) {
                $keys[] = ['path' => $prefix, 'type' => 'list', 'line' => 0];
                foreach ($value as $i => $v) {
                    $childPath = $prefix === '' ? '['.$i.']' : $prefix.'['.$i.']';
                    $this->walk($childPath, $v, $keys);
                }

                return;
            }
            if ($prefix !== '') {
                $keys[] = ['path' => $prefix, 'type' => 'map', 'line' => 0];
            }
            foreach ($value as $k => $v) {
                $childPath = $prefix === '' ? (string) $k : $prefix.'.'.$k;
                $this->walk($childPath, $v, $keys);
            }

            return;
        }
        $keys[] = ['path' => $prefix, 'type' => $this->typeOf($value), 'line' => 0];
    }

    private function typeOf(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_string($value) => 'string',
            is_array($value) => array_keys($value) === range(0, count($value) - 1) ? 'list' : 'map',
            default => 'unknown',
        };
    }

    /**
     * Minimal YAML subset parser sufficient for the Loop's flat/nested config style
     * (key: value, indented children, "- item" lists, scalars: bool/int/float/null/string).
     * The FACT contract only needs structural facts (paths + primitive types) — it does NOT
     * need full YAML 1.2 semantics.
     *
     * @return array<string,mixed>
     */
    private function parseSimpleYaml(string $bytes): array
    {
        $lines = preg_split('/\r?\n/', $bytes) ?: [];
        $stack = [['indent' => -1, 'ref' => null]];
        $root = [];
        $stack[0]['ref'] = &$root;

        $lastListContainerIndent = -1;
        $lastMapKey = null;

        foreach ($lines as $raw) {
            $stripped = preg_replace('/#.*$/', '', $raw) ?? '';
            if (trim($stripped) === '') {
                continue;
            }
            preg_match('/^(\s*)/', $stripped, $m);
            $indent = strlen($m[1] ?? '');
            $content = ltrim($stripped);

            while (count($stack) > 1 && $indent <= $stack[count($stack) - 1]['indent']) {
                array_pop($stack);
            }
            $top = &$stack[count($stack) - 1];

            if (str_starts_with($content, '- ')) {
                $item = $this->scalar(trim(substr($content, 2)));
                if (! is_array($top['ref'])) {
                    $top['ref'] = [];
                }
                $top['ref'][] = $item;

                continue;
            }
            if (preg_match('/^([A-Za-z0-9_\-]+):\s*(.*)$/', $content, $kv)) {
                $key = $kv[1];
                $value = $kv[2];
                if ($value === '' || $value === null) {
                    if (! is_array($top['ref'])) {
                        $top['ref'] = [];
                    }
                    $top['ref'][$key] = [];
                    $stack[] = ['indent' => $indent, 'ref' => &$top['ref'][$key]];

                    continue;
                }
                if (! is_array($top['ref'])) {
                    $top['ref'] = [];
                }
                $top['ref'][$key] = $this->scalar($value);
            }
        }

        return $root;
    }

    private function scalar(string $raw): mixed
    {
        $raw = trim($raw);
        if ($raw === '~' || strcasecmp($raw, 'null') === 0 || $raw === '') {
            return null;
        }
        if (strcasecmp($raw, 'true') === 0) {
            return true;
        }
        if (strcasecmp($raw, 'false') === 0) {
            return false;
        }
        if (preg_match('/^-?\d+$/', $raw)) {
            return (int) $raw;
        }
        if (preg_match('/^-?\d+\.\d+$/', $raw)) {
            return (float) $raw;
        }
        if ((str_starts_with($raw, '"') && str_ends_with($raw, '"')) || (str_starts_with($raw, "'") && str_ends_with($raw, "'"))) {
            return substr($raw, 1, -1);
        }

        return $raw;
    }

    private function assertInScope(string $absolutePath): void
    {
        foreach (self::ALLOWED_SCOPE_FRAGMENTS as $fragment) {
            if (str_contains($absolutePath, $fragment)) {
                return;
            }
        }
        throw new OutOfScopeException('yaml_path_outside_loop_scope:'.$absolutePath);
    }
}

final class OutOfScopeException extends RuntimeException {}
