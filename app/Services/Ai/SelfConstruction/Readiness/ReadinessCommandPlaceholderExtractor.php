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
}
