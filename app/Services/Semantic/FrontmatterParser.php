<?php

namespace App\Services\Semantic;

use Illuminate\Support\Str;

class FrontmatterParser
{
    public function parse(string $markdown): array
    {
        if (! str_starts_with($markdown, "---\n") && ! str_starts_with($markdown, "---\r\n")) {
            return ['frontmatter' => [], 'body' => $markdown, 'errors' => ['missing_frontmatter']];
        }

        if (! preg_match("/^---\r?\n(.*?)\r?\n---\r?\n(.*)$/s", $markdown, $matches)) {
            return ['frontmatter' => [], 'body' => $markdown, 'errors' => ['invalid_frontmatter_delimiters']];
        }

        $errors = [];
        try {
            $frontmatter = $this->parseYamlSubset($matches[1]);
        } catch (\Throwable $exception) {
            $frontmatter = [];
            $errors[] = 'frontmatter_parse_failed: '.$exception->getMessage();
        }

        return [
            'frontmatter' => $frontmatter,
            'body' => trim($matches[2]),
            'errors' => $errors,
        ];
    }

    public function validate(array $frontmatter): array
    {
        $errors = [];
        foreach (['id', 'type', 'title', 'status', 'summary'] as $field) {
            if (! isset($frontmatter[$field]) || trim((string) $frontmatter[$field]) === '') {
                $errors[] = "missing_{$field}";
            }
        }

        $hasWhenToUse = ! empty($frontmatter['when_to_use']) && is_array($frontmatter['when_to_use']);
        $hasTriggers = ! empty($frontmatter['trigger_signals']) && is_array($frontmatter['trigger_signals']);
        if (! $hasWhenToUse && ! $hasTriggers) {
            $errors[] = 'missing_activation_fields';
        }

        return $errors;
    }

    public function build(array $frontmatter, string $body): string
    {
        return "---\n".$this->dumpYaml($frontmatter)."---\n\n".trim($body)."\n";
    }

    private function parseYamlSubset(string $yaml): array
    {
        $lines = preg_split('/\r?\n/', $yaml) ?: [];
        $data = [];
        $currentKey = null;

        foreach ($lines as $line) {
            if (trim($line) === '' || str_starts_with(trim($line), '#')) {
                continue;
            }

            if (preg_match('/^([A-Za-z0-9_]+):(?:\s*(.*))?$/', $line, $match)) {
                $currentKey = $match[1];
                $value = $match[2] ?? '';
                $data[$currentKey] = $value === '' ? [] : $this->parseScalar($value);

                continue;
            }

            if ($currentKey && preg_match('/^\s{2}-\s*(.*)$/', $line, $match)) {
                if (! is_array($data[$currentKey])) {
                    $data[$currentKey] = [];
                }
                $data[$currentKey][] = $this->parseScalar($match[1]);

                continue;
            }

            if ($currentKey && preg_match('/^\s{2}([A-Za-z0-9_]+):(?:\s*(.*))?$/', $line, $match)) {
                if (! is_array($data[$currentKey])) {
                    $data[$currentKey] = [];
                }
                $data[$currentKey][$match[1]] = ($match[2] ?? '') === '' ? [] : $this->parseScalar($match[2]);

                continue;
            }

            if ($currentKey && preg_match('/^\s{4}-\s*(.*)$/', $line, $match)) {
                $keys = array_keys($data[$currentKey]);
                $lastKey = end($keys);
                if ($lastKey !== false) {
                    if (! is_array($data[$currentKey][$lastKey])) {
                        $data[$currentKey][$lastKey] = [];
                    }
                    $data[$currentKey][$lastKey][] = $this->parseScalar($match[1]);
                }
            }
        }

        return $data;
    }

    private function parseScalar(string $value): mixed
    {
        $value = trim($value);
        if ($value === '' || $value === 'null' || $value === '~') {
            return null;
        }
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }
        if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
            $inner = trim(substr($value, 1, -1));
            if ($inner === '') {
                return [];
            }

            return array_map(fn (string $item): mixed => $this->parseScalar($item), explode(',', $inner));
        }
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        return Str::of($value)->trim('"')->trim("'")->toString();
    }

    private function dumpYaml(array $data, int $indent = 0): string
    {
        $yaml = '';
        $spaces = str_repeat(' ', $indent);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $yaml .= "{$spaces}{$key}:";
                if ($value === []) {
                    $yaml .= " []\n";

                    continue;
                }
                $yaml .= "\n";
                if (array_is_list($value)) {
                    foreach ($value as $item) {
                        $yaml .= $spaces.'  - '.$this->formatScalar($item)."\n";
                    }
                } else {
                    $yaml .= $this->dumpYaml($value, $indent + 2);
                }

                continue;
            }

            $yaml .= "{$spaces}{$key}: ".$this->formatScalar($value)."\n";
        }

        return $yaml;
    }

    private function formatScalar(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_numeric($value)) {
            return (string) $value;
        }

        $string = (string) $value;
        if ($string === '' || preg_match('/[:\[\]#\n]/', $string)) {
            return '"'.str_replace('"', '\"', $string).'"';
        }

        return $string;
    }
}
