<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness;

/**
 * Extracts command placeholder tokens (e.g. <task>, --lease=<id>) from an
 * Artisan command string.
 *
 * Extracted from AtlasSelfConstructionReadinessService to reduce the god-class.
 * Pure regex parser — no instance state, no constructor arguments.
 */
final class ReadinessCommandPlaceholderExtractor
{
    /**
     * @return list<string>
     */
    public static function fieldsFromCommand(string $command): array
    {
        preg_match_all('/<[^>]+>/', $command, $matches);

        return array_values(array_unique(array_map('strval', $matches[0] ?? [])));
    }

    /**
     * Pure structural inspection — detects nested and unterminated placeholder shapes without executing
     * the command. Nested = a matched token whose inner content contains another '<'. Unterminated = any
     * '<' that is never closed by a matching '>'.
     *
     * @return array{placeholders:list<string>, nested_found:bool, unterminated_found:bool, warnings:list<string>}
     */
    public static function inspect(string $command): array
    {
        $placeholders = self::fieldsFromCommand($command);
        $warnings = [];

        $nestedFound = false;
        foreach ($placeholders as $ph) {
            $inner = substr($ph, 1, -1);
            if (str_contains($inner, '<')) {
                $nestedFound = true;
                $warnings[] = "nested placeholder detected: {$ph}";
            }
        }

        $open = 0;
        $len = strlen($command);
        for ($i = 0; $i < $len; $i++) {
            if ($command[$i] === '<') {
                $open++;
            } elseif ($command[$i] === '>' && $open > 0) {
                $open--;
            }
        }
        $unterminatedFound = $open > 0;
        if ($unterminatedFound) {
            $warnings[] = "unterminated placeholder: {$open} unclosed '<' bracket(s)";
        }

        return [
            'placeholders' => $placeholders,
            'nested_found' => $nestedFound,
            'unterminated_found' => $unterminatedFound,
            'warnings' => $warnings,
        ];
    }

    /**
     * Find placeholders across multiple source kinds: command strings, nested
     * argument arrays, JSON payloads, and documentation snippets. Each finding
     * includes the placeholder token, its path, the source kind, and whether it
     * blocks readiness. Escaped literal examples marked non-executable are ignored.
     *
     * A source is considered non-executable (escaped) when:
     *  - The string starts with "# " (hash-commented shell example)
     *  - The key/value explicitly contains "example" and "non-executable" in its context
     *
     * @param  array<string, mixed>  $input
     *         commands     : list<string>  shell command strings
     *         args         : list<array<string, string>>  nested argument arrays
     *         json_payloads: list<string>  raw JSON strings
     *         doc_snippets : list<string>  documentation snippet strings
     * @return list<array{placeholder:string, path:string, source_kind:string, blocks_readiness:bool}>
     */
    public static function findPlaceholders(array $input): array
    {
        $findings = [];

        // ── Commands ────────────────────────────────────────────────────────
        foreach ((array) ($input['commands'] ?? []) as $i => $cmd) {
            if (! is_string($cmd)) {
                continue;
            }
            // Skip commands explicitly marked as non-executable examples.
            if (str_starts_with(trim($cmd), '# ') || str_contains(strtolower($cmd), 'non-executable') || str_contains(strtolower($cmd), 'example only')) {
                continue;
            }
            $placeholders = self::fieldsFromCommand($cmd);
            foreach ($placeholders as $ph) {
                $findings[] = [
                    'placeholder' => $ph,
                    'path' => "commands[{$i}]",
                    'source_kind' => 'command',
                    'blocks_readiness' => true,
                ];
            }
        }

        // ── Nested argument arrays ────────────────────────────────────────────
        foreach ((array) ($input['args'] ?? []) as $key => $value) {
            self::walkArgs('args.'.(string) $key, $value, $findings, 'args');
        }

        // ── JSON payloads (decode and scan) ──────────────────────────────────
        foreach ((array) ($input['json_payloads'] ?? []) as $i => $json) {
            if (! is_string($json)) {
                continue;
            }
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                self::walkArgs("json_payloads[{$i}]", $decoded, $findings, 'json_payload');
            } else {
                // String-only JSON — regex scan
                $placeholders = self::fieldsFromCommand($json);
                foreach ($placeholders as $ph) {
                    $findings[] = [
                        'placeholder' => $ph,
                        'path' => "json_payloads[{$i}]",
                        'source_kind' => 'json_payload',
                        'blocks_readiness' => true,
                    ];
                }
            }
        }

        // ── Documentation snippets ────────────────────────────────────────────
        foreach ((array) ($input['doc_snippets'] ?? []) as $i => $snippet) {
            if (! is_string($snippet)) {
                continue;
            }
            // Skip examples explicitly marked as non-executable or example-only.
            if (str_contains(strtolower($snippet), 'non-executable') || str_contains(strtolower($snippet), 'example only')) {
                continue;
            }
            $placeholders = self::fieldsFromCommand($snippet);
            foreach ($placeholders as $ph) {
                $findings[] = [
                    'placeholder' => $ph,
                    'path' => "doc_snippets[{$i}]",
                    'source_kind' => 'doc_snippet',
                    'blocks_readiness' => true,
                ];
            }
        }

        return $findings;
    }

    /**
     * Recursively walk nested arrays looking for placeholder values in strings.
     *
     * @param  string  $path      current dot-notation path
     * @param  mixed   $value     the value to inspect
     * @param  list<array{placeholder:string, path:string, source_kind:string, blocks_readiness:bool}>  $findings  (reference)
     * @param  string  $kind      source kind label ('args' | 'json_payload')
     */
    private static function walkArgs(string $path, mixed $value, array &$findings, string $kind): void
    {
        if (is_string($value)) {
            $placeholders = self::fieldsFromCommand($value);
            foreach ($placeholders as $ph) {
                $findings[] = [
                    'placeholder' => $ph,
                    'path' => $path,
                    'source_kind' => $kind,
                    'blocks_readiness' => true,
                ];
            }
        } elseif (is_array($value)) {
            foreach ($value as $k => $v) {
                self::walkArgs($path.'.'.$k, $v, $findings, $kind);
            }
        }
    }
}
