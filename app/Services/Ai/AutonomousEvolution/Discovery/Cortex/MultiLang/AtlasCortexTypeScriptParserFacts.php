<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\MultiLang;

use RuntimeException;

/**
 * Pure FACT-only TypeScript parser scoped to Loop assets.
 *
 * parse(absolutePath): array of FACTS keyed by:
 *   - imports[]             — list<{from:string, named:string[]}>
 *   - exported_symbols[]    — list<{kind:string, name:string}>
 *   - type_aliases[]        — list<string>
 *   - interfaces[]          — list<string>
 *
 * NO scoring / ranking / summarising. Regex-token based; no tsc, no shell, no provider.
 * Refuses paths outside the configured loop scope roots.
 */
final class AtlasCortexTypeScriptParserFacts
{
    public const SCHEMA = 'atlas.cortex.typescript_parser_facts.v1';

    /**
     * @param  list<string>  $loopScopeRoots  absolute paths allowed
     */
    public function __construct(private readonly array $loopScopeRoots) {}

    /**
     * @return array<string,mixed>
     */
    public function parse(string $absolutePath): array
    {
        $this->assertWithinScope($absolutePath);
        if (! is_file($absolutePath)) {
            throw new TypeScriptParserOutOfScopeException('file_not_found:'.$absolutePath);
        }
        $src = (string) file_get_contents($absolutePath);

        return [
            'schema' => self::SCHEMA,
            'imports' => $this->extractImports($src),
            'exported_symbols' => $this->extractExportedSymbols($src),
            'type_aliases' => $this->extractTypeAliases($src),
            'interfaces' => $this->extractInterfaces($src),
        ];
    }

    public function registerInto(AtlasCortexLanguageRegistry $registry): void
    {
        $registry->register('typescript', [
            'parser_class' => self::class,
            'extractor_class' => self::class,
            'extensions' => ['ts', 'tsx'],
            'namespace_roots' => array_values($this->loopScopeRoots),
        ]);
    }

    private function assertWithinScope(string $absolutePath): void
    {
        $absolutePath = (string) (realpath($absolutePath) ?: $absolutePath);
        foreach ($this->loopScopeRoots as $root) {
            $root = rtrim((string) (realpath($root) ?: $root), '/');
            if ($root !== '' && str_starts_with($absolutePath, $root.'/')) {
                return;
            }
        }
        throw new TypeScriptParserOutOfScopeException('path_outside_loop_scope:'.$absolutePath);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function extractImports(string $src): array
    {
        $imports = [];
        if (preg_match_all('/^\s*import\s+(?:type\s+)?(?:(\*\s+as\s+\w+)|\{([^}]+)\}|(\w+))\s+from\s+[\'"]([^\'"]+)[\'"]/m', $src, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $m) {
                $from = (string) $m[4];
                $named = [];
                if (! empty($m[2])) {
                    foreach (explode(',', $m[2]) as $n) {
                        $name = trim($n);
                        if ($name !== '') {
                            $named[] = $name;
                        }
                    }
                } elseif (! empty($m[3])) {
                    $named[] = (string) $m[3];
                } elseif (! empty($m[1])) {
                    $named[] = (string) $m[1];
                }
                sort($named, SORT_STRING);
                $imports[] = ['from' => $from, 'named' => $named];
            }
        }
        usort($imports, static fn (array $a, array $b): int => strcmp($a['from'], $b['from']));

        return $imports;
    }

    /**
     * @return list<array<string,string>>
     */
    private function extractExportedSymbols(string $src): array
    {
        $exports = [];
        if (preg_match_all('/^\s*export\s+(?:default\s+)?(class|function|const|let|var|interface|type|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/m', $src, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $m) {
                $exports[] = ['kind' => (string) $m[1], 'name' => (string) $m[2]];
            }
        }
        usort($exports, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $exports;
    }

    /**
     * @return list<string>
     */
    private function extractTypeAliases(string $src): array
    {
        $names = [];
        if (preg_match_all('/^\s*(?:export\s+)?type\s+([A-Za-z_][A-Za-z0-9_]*)\s*=/m', $src, $m) > 0) {
            foreach ($m[1] as $name) {
                $names[] = (string) $name;
            }
        }
        $names = array_values(array_unique($names));
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * @return list<string>
     */
    private function extractInterfaces(string $src): array
    {
        $names = [];
        if (preg_match_all('/^\s*(?:export\s+)?interface\s+([A-Za-z_][A-Za-z0-9_]*)\b/m', $src, $m) > 0) {
            foreach ($m[1] as $name) {
                $names[] = (string) $name;
            }
        }
        $names = array_values(array_unique($names));
        sort($names, SORT_STRING);

        return $names;
    }
}

final class TypeScriptParserOutOfScopeException extends RuntimeException {}
