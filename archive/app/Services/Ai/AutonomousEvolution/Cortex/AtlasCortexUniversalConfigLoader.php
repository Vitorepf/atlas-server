<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Cortex;

use InvalidArgumentException;

/**
 * Reads a per-repo Cortex configuration file (`cortex.yaml` preferred; `cortex.toml` fallback) at the supplied
 * `$repoRoot` and produces the canonical config array consumed by the universal contract:
 *
 *   - `scope_roots`     : list<string> repo-relative dirs to comprehend
 *   - `doc_roots`       : list<string> repo-relative doc roots (mirrors AtlasLoopComprehendCommand --docs=*)
 *   - `forbidden_globs` : list<string> repo-relative globs that must be flagged forbidden
 *   - `clone_min_lines` : int          minimum lines to count as a clone
 *   - `schema_id`       : string       MUST equal "atlas.cortex.facts.v1"
 *
 * PORTABLE / PURE: the loader has ZERO references to Laravel container, Atlas config, env, base_path, or
 * storage_path. It accepts an optional `$rawOverride` so tests inject raw YAML content without writing files.
 *
 * NOT a duplicate of `config/atlas.php`: that file carries Atlas runtime flags; `cortex.yaml` is the per-repo
 * declarative recipe a foreign repo ships to be cortex-able.
 */
final class AtlasCortexUniversalConfigLoader
{
    public const EXPECTED_SCHEMA_ID = 'atlas.cortex.facts.v1';

    /**
     * @return array{scope_roots:list<string>, doc_roots:list<string>, forbidden_globs:list<string>, clone_min_lines:int, schema_id:string}
     */
    public function load(string $repoRoot, ?string $rawOverride = null): array
    {
        $raw = $rawOverride;
        if ($raw === null) {
            $raw = $this->readConfigFromRepo($repoRoot);
        }
        if ($raw === null || trim($raw) === '') {
            throw new InvalidArgumentException('Cortex config not found at '.$repoRoot.'/cortex.yaml or '.$repoRoot.'/cortex.toml');
        }

        $parsed = $this->parse($raw);
        if (! is_array($parsed)) {
            throw new InvalidArgumentException('Cortex config did not parse to an associative array');
        }

        return $this->normalise($parsed);
    }

    private function readConfigFromRepo(string $repoRoot): ?string
    {
        $repoRoot = rtrim($repoRoot, '/');
        foreach (['/cortex.yaml', '/cortex.yml', '/cortex.toml'] as $rel) {
            $path = $repoRoot.$rel;
            if (is_file($path) && is_readable($path)) {
                $contents = @file_get_contents($path);

                return $contents === false ? null : $contents;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function parse(string $raw): ?array
    {
        $trimmed = trim($raw);
        // JSON path: YAML is a superset of JSON so a JSON-encoded payload is valid here.
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($trimmed, true);

            return is_array($decoded) ? $decoded : null;
        }

        return $this->parseYamlSubset($raw);
    }

    /**
     * Minimal YAML subset parser handling exactly what `cortex.yaml` declares:
     *   - top-level `key: value` scalars (strings, ints, bools)
     *   - top-level `key:` followed by an indented list of `- value` items
     *
     * Comments (`# ...`) and blank lines are skipped. Nested mappings are NOT supported — the cortex config
     * is intentionally flat so this is sufficient.
     *
     * @return array<string,mixed>
     */
    private function parseYamlSubset(string $raw): array
    {
        $lines = preg_split('/\R/', $raw) ?: [];
        $out = [];
        $currentList = null;
        foreach ($lines as $line) {
            $line = rtrim($line);
            if ($line === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            if ($currentList !== null && preg_match('/^\s+-\s*(.*)$/', $line, $m) === 1) {
                $out[$currentList][] = $this->scalar(trim($m[1]));

                continue;
            }
            if (preg_match('/^([A-Za-z0-9_\-]+):\s*(.*)$/', $line, $m) === 1) {
                $key = (string) $m[1];
                $value = trim((string) $m[2]);
                if ($value === '') {
                    $out[$key] = [];
                    $currentList = $key;
                } else {
                    $out[$key] = $this->scalar($value);
                    $currentList = null;
                }

                continue;
            }
            $currentList = null;
        }

        return $out;
    }

    private function scalar(string $raw): mixed
    {
        $trimmed = trim($raw);
        if ($trimmed === 'true') {
            return true;
        }
        if ($trimmed === 'false') {
            return false;
        }
        if ($trimmed === 'null' || $trimmed === '~') {
            return null;
        }
        if (preg_match('/^-?\d+$/', $trimmed) === 1) {
            return (int) $trimmed;
        }
        // Strip wrapping quotes if any.
        if (strlen($trimmed) >= 2 && (($trimmed[0] === '"' && $trimmed[-1] === '"') || ($trimmed[0] === "'" && $trimmed[-1] === "'"))) {
            return substr($trimmed, 1, -1);
        }

        return $trimmed;
    }

    /**
     * @param  array<string,mixed>  $parsed
     * @return array{scope_roots:list<string>, doc_roots:list<string>, forbidden_globs:list<string>, clone_min_lines:int, schema_id:string}
     */
    private function normalise(array $parsed): array
    {
        $schemaId = (string) ($parsed['schema_id'] ?? '');
        if ($schemaId !== self::EXPECTED_SCHEMA_ID) {
            throw new InvalidArgumentException('Cortex config schema_id must equal '.self::EXPECTED_SCHEMA_ID.'; got '.($schemaId === '' ? '(missing)' : $schemaId));
        }

        $scopeRoots = $this->stringList($parsed['scope_roots'] ?? []);
        if ($scopeRoots === []) {
            throw new InvalidArgumentException('Cortex config scope_roots must be a non-empty list');
        }

        $docRoots = $this->stringList($parsed['doc_roots'] ?? []);
        $forbiddenGlobs = $this->stringList($parsed['forbidden_globs'] ?? []);
        $cloneMin = isset($parsed['clone_min_lines']) ? (int) $parsed['clone_min_lines'] : 0;

        return [
            'scope_roots' => $scopeRoots,
            'doc_roots' => $docRoots,
            'forbidden_globs' => $forbiddenGlobs,
            'clone_min_lines' => $cloneMin,
            'schema_id' => $schemaId,
        ];
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $v) {
            $s = trim((string) $v);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return $out;
    }
}
