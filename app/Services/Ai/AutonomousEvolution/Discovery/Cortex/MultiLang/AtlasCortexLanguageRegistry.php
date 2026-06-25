<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\MultiLang;

use RuntimeException;

/**
 * Pure FACT-only registry of supported languages with their parser/extractor bindings and
 * path conventions. NO scoring, NO ranking, NO provider call, NO shell. Bindings stay as
 * class-string references; the registry never instantiates parsers.
 */
final class AtlasCortexLanguageRegistry
{
    public const SCHEMA = 'atlas.cortex.language_registry.v1';

    /** @var array<string, array<string,mixed>> */
    private array $bindings = [];

    /**
     * @param  array<string,mixed>  $binding  {parser_class, extractor_class, extensions[], namespace_roots[]}
     */
    public function register(string $language, array $binding): void
    {
        $language = trim($language);
        if ($language === '') {
            throw new UnknownLanguageException('language_id_empty');
        }
        $this->bindings[$language] = [
            'language' => $language,
            'parser_class' => (string) ($binding['parser_class'] ?? ''),
            'extractor_class' => (string) ($binding['extractor_class'] ?? ''),
            'extensions' => array_values(array_map('strval', (array) ($binding['extensions'] ?? []))),
            'namespace_roots' => array_values(array_map('strval', (array) ($binding['namespace_roots'] ?? []))),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function get(string $language): array
    {
        if (! isset($this->bindings[$language])) {
            throw new UnknownLanguageException('unknown_language:'.$language);
        }

        return $this->bindings[$language];
    }

    /**
     * @return list<string>
     */
    public function supported(): array
    {
        $ids = array_keys($this->bindings);
        sort($ids, SORT_STRING);

        return $ids;
    }

    public function resolveForPath(string $absolutePath): ?string
    {
        $ext = strtolower((string) pathinfo($absolutePath, PATHINFO_EXTENSION));
        if ($ext === '') {
            return null;
        }
        foreach ($this->bindings as $language => $binding) {
            if (in_array($ext, array_map('strtolower', (array) $binding['extensions']), true)) {
                return (string) $language;
            }
        }

        return null;
    }
}

final class UnknownLanguageException extends RuntimeException {}
