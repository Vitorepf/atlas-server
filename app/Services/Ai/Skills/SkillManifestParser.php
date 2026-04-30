<?php

namespace App\Services\Ai\Skills;

use App\Services\Ai\Security\PromptInjectionScanner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class SkillManifestParser
{
    public function parse(string $path, string $sourceTier = 'community'): SkillManifest
    {
        $path = realpath($path) ?: $path;
        if (! File::isFile($path)) {
            throw new InvalidSkillManifestException($path, ['file_not_found']);
        }

        $markdown = File::get($path);
        [$frontmatter, $body, $errors] = $this->frontmatter($markdown);
        if ($errors !== []) {
            throw new InvalidSkillManifestException($path, $errors);
        }

        $directory = dirname($path);
        $directoryName = basename($directory);
        $name = trim((string) ($frontmatter['name'] ?? $frontmatter['slug'] ?? $directoryName));
        $description = trim((string) ($frontmatter['description'] ?? $frontmatter['summary'] ?? ''));
        $warnings = [];

        if ($description === '') {
            throw new InvalidSkillManifestException($path, ['missing_description']);
        }

        if ($name === '') {
            throw new InvalidSkillManifestException($path, ['missing_name']);
        }

        if ($name !== $directoryName) {
            $warnings[] = "name_mismatch: frontmatter name [{$name}] differs from directory [{$directoryName}]";
        }

        if (mb_strlen($name) > 64) {
            $warnings[] = 'name_too_long';
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $name) || str_contains($name, '--')) {
            $warnings[] = 'name_outside_agentskills_convention';
        }

        $securityIssues = PromptInjectionScanner::scan($markdown);

        return new SkillManifest(
            name: $name,
            description: $description,
            license: $this->nullableString($frontmatter['license'] ?? null),
            compatibility: $this->nullableString($frontmatter['compatibility'] ?? null),
            metadata: is_array($frontmatter['metadata'] ?? null) ? $frontmatter['metadata'] : [],
            allowedTools: $this->nullableString($frontmatter['allowed-tools'] ?? $frontmatter['allowed_tools'] ?? null),
            body: trim($body),
            path: $path,
            directory: $directory,
            sourceTier: $sourceTier,
            contentHash: hash('sha256', $markdown),
            warnings: $warnings,
            quarantined: $securityIssues !== [],
            securityIssues: $securityIssues,
        );
    }

    /**
     * @return array{0:array<string,mixed>,1:string,2:array<int,string>}
     */
    private function frontmatter(string $markdown): array
    {
        if (! preg_match('/^---\r?\n(.*?)\r?\n---\r?\n(.*)$/s', $markdown, $matches)) {
            return [[], $markdown, ['missing_or_invalid_frontmatter_delimiters']];
        }

        $yaml = (string) $matches[1];
        $body = (string) $matches[2];

        try {
            return [$this->parseYamlSubset($yaml), $body, []];
        } catch (\Throwable $exception) {
            try {
                return [$this->parseYamlSubset($this->recoverYaml($yaml)), $body, []];
            } catch (\Throwable $second) {
                return [[], $body, ['frontmatter_parse_failed: '.$second->getMessage()]];
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function parseYamlSubset(string $yaml): array
    {
        $root = [];
        $refs = [&$root];
        $lines = preg_split('/\r?\n/', $yaml) ?: [];

        foreach ($lines as $lineNumber => $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            $indent = strlen($line) - strlen(ltrim($line, ' '));
            if ($indent % 2 !== 0) {
                throw new \RuntimeException('odd indentation at line '.($lineNumber + 1));
            }

            $level = intdiv($indent, 2);
            $trimmed = trim($line);

            if (preg_match('/^-\s*(.*)$/', $trimmed, $match)) {
                $parent = &$refs[$level];
                if (! is_array($parent)) {
                    throw new \RuntimeException('list parent is not an array at line '.($lineNumber + 1));
                }
                $parent[] = $this->parseScalar($match[1]);

                continue;
            }

            if (! preg_match('/^([A-Za-z0-9_.-]+):(?:\s*(.*))?$/', $trimmed, $match)) {
                throw new \RuntimeException('unsupported yaml line '.($lineNumber + 1));
            }

            $key = $match[1];
            $value = $match[2] ?? '';
            $parent = &$refs[$level];
            if (! is_array($parent)) {
                throw new \RuntimeException('map parent is not an array at line '.($lineNumber + 1));
            }

            $parent[$key] = $value === '' ? [] : $this->parseScalar($value);
            $refs[$level + 1] = &$parent[$key];
        }

        return $root;
    }

    private function parseScalar(string $value): mixed
    {
        $value = trim($value);
        if ($value === '' || $value === 'null' || $value === '~') {
            return null;
        }

        if (in_array(Str::lower($value), ['true', 'false'], true)) {
            return Str::lower($value) === 'true';
        }

        if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
            $inner = trim(substr($value, 1, -1));
            if ($inner === '') {
                return [];
            }

            return collect(explode(',', $inner))
                ->map(fn (string $item): mixed => $this->parseScalar($item))
                ->values()
                ->all();
        }

        if (preg_match('/^-?\d+(?:\.\d+)?$/', $value) === 1) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        return Str::of($value)->trim('"')->trim("'")->toString();
    }

    private function recoverYaml(string $yaml): string
    {
        return collect(preg_split('/\r?\n/', $yaml) ?: [])
            ->map(function (string $line): string {
                if (! preg_match('/^(\s*[A-Za-z0-9_.-]+:\s+)([^"\'][^#]*:[^#]*)$/', $line, $matches)) {
                    return $line;
                }

                $value = trim($matches[2]);
                if (str_starts_with($value, '[') || str_starts_with($value, '{')) {
                    return $line;
                }

                return $matches[1].'"'.str_replace('"', '\"', $value).'"';
            })
            ->implode("\n");
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
