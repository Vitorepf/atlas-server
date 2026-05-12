<?php

declare(strict_types=1);

namespace App\Services\Vault;

/**
 * Minimal YAML parser focused on the frontmatter shape used in Atlas docs and the AtlasVault.
 *
 * Supports: scalars (string, int, float, bool, null), inline lists `[a, b]`,
 * indented block lists, inline objects (stored as raw string), comments and
 * single-line key/value pairs. Nested objects beyond one level are kept as strings.
 *
 * The cartography never writes — this parser is read-only.
 */
final class FrontmatterParser
{
    /**
     * @return array{frontmatter: array<string, mixed>, body: string}
     */
    public function parse(string $content): array
    {
        $content = ltrim($content, "\xEF\xBB\xBF"); // strip BOM
        if (! preg_match('/^---\r?\n/', $content)) {
            return ['frontmatter' => [], 'body' => $content];
        }
        $afterOpen = preg_replace('/^---\r?\n/', '', $content, 1) ?? $content;
        $closeMatch = preg_match('/^---\r?\n/m', $afterOpen, $matches, PREG_OFFSET_CAPTURE);
        if ($closeMatch !== 1) {
            return ['frontmatter' => [], 'body' => $content];
        }
        $closeOffset = (int) $matches[0][1];
        $yamlBlock = substr($afterOpen, 0, $closeOffset);
        $body = substr($afterOpen, $closeOffset + strlen($matches[0][0]));

        return [
            'frontmatter' => $this->parseYaml($yamlBlock),
            'body' => ltrim($body, "\r\n"),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseYaml(string $yaml): array
    {
        $lines = preg_split('/\r?\n/', $yaml) ?: [];
        $result = [];
        $currentKey = null;

        foreach ($lines as $rawLine) {
            $line = rtrim($rawLine, "\r");
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            // block list continuation:  "  - item"
            if ($currentKey !== null && preg_match('/^\s+-\s*(.*)$/', $line, $m)) {
                $itemRaw = $m[1];
                if (! isset($result[$currentKey]) || ! is_array($result[$currentKey])) {
                    $result[$currentKey] = [];
                }
                $result[$currentKey][] = $this->parseScalar($itemRaw);
                continue;
            }

            // key: value
            if (preg_match('/^([\w\-\.]+):\s*(.*)$/', $line, $m)) {
                $key = $m[1];
                $value = trim($m[2]);
                $currentKey = $key;

                if ($value === '') {
                    // multi-line block follows; initialize empty list/scalar placeholder
                    if (! array_key_exists($key, $result)) {
                        $result[$key] = [];
                    }
                    continue;
                }

                // Inline list
                if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
                    $inner = trim(substr($value, 1, -1));
                    if ($inner === '') {
                        $result[$key] = [];
                    } else {
                        $result[$key] = array_map(
                            fn (string $v) => $this->parseScalar(trim($v)),
                            preg_split('/,(?![^\[]*\])/', $inner) ?: []
                        );
                    }
                    continue;
                }

                // Inline object — stored as raw string (we don't need depth here)
                if (str_starts_with($value, '{') && str_ends_with($value, '}')) {
                    $result[$key] = $value;
                    continue;
                }

                $result[$key] = $this->parseScalar($value);
                continue;
            }

            // Otherwise: orphan content (ignored)
        }

        // collapse empty placeholder lists into [] when they were initialized
        return $result;
    }

    private function parseScalar(string $val): mixed
    {
        $val = trim($val);
        if ($val === '') {
            return null;
        }
        if (preg_match('/^"(.*)"$/', $val, $m)) {
            return $m[1];
        }
        if (preg_match("/^'(.*)'$/", $val, $m)) {
            return $m[1];
        }
        $lower = strtolower($val);
        if ($lower === 'true' || $lower === 'yes') {
            return true;
        }
        if ($lower === 'false' || $lower === 'no') {
            return false;
        }
        if ($lower === 'null' || $val === '~') {
            return null;
        }
        if (preg_match('/^-?\d+$/', $val)) {
            return (int) $val;
        }
        if (preg_match('/^-?\d+\.\d+$/', $val)) {
            return (float) $val;
        }

        return $val;
    }
}
