<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\FrozenContracts;

use ReflectionClass;
use Throwable;

/**
 * Deterministic, provider-free reflective extractor for frozen-contract docblocks. Given a loop-critical class
 * (e.g. {@see \App\Services\Ai\AutonomousEvolution\Constitution\Frozen\AtlasLoopFrozenMutationOperators}), it
 * returns a structured FACT array:
 *
 *   - invariants            : list<string>   lines marked INVARIANT:/REGRA:/PETREO:/FROZEN:/FORBIDDEN:/NEVER:
 *                                            PLUS inline lines whose body mentions a frozen-language token
 *                                            (frozen / pétreo / forbidden / never / cannot, whole-word match).
 *   - forbidden_mutations   : list<string>   lines marked FORBIDDEN: / NEVER:
 *   - anchors               : list<string>   FQ names from `@see X` and inline `{@see X}` references
 *   - contract_version      : ?string        the first contract-version hint found (e.g. "@ 2026-06-18", "Slice 3", "v+3")
 *
 * Byte-deterministic: sorted lists, no timestamps. Anti-Goodhart: NO score, NO level, NO line-count proxy.
 */
final class AtlasLoopFrozenContractFactExtractor
{
    public const SCHEMA = 'atlas.loop.frozen_contract_facts.v1';

    /** Prefix tokens (case-insensitive) that explicitly mark an invariant line. */
    private const INVARIANT_PREFIXES = ['INVARIANT', 'REGRA', 'PETREO', 'PÉTREO', 'FROZEN', 'FORBIDDEN', 'NEVER'];

    /** Prefix tokens that explicitly mark a FORBIDDEN mutation. */
    private const FORBIDDEN_PREFIXES = ['FORBIDDEN', 'NEVER'];

    /** Whole-word tokens whose presence in a line marks it as carrying frozen-language semantics. */
    private const FROZEN_LANGUAGE_TOKENS = ['frozen', 'pétreo', 'petreo', 'forbidden', 'never', 'cannot'];

    /**
     * @return array{schema:string, class:string, invariants:list<string>, forbidden_mutations:list<string>, anchors:list<string>, contract_version:?string}
     */
    public function extract(string $class): array
    {
        try {
            $rc = new ReflectionClass($class);
        } catch (Throwable) {
            return $this->emptyFacts($class);
        }

        $doc = $rc->getDocComment();
        if (! is_string($doc) || $doc === '') {
            return $this->emptyFacts($class);
        }

        $lines = $this->normalizeLines($doc);

        $invariants = [];
        $forbidden = [];
        $anchors = [];
        $contractVersion = null;

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            // Strict-prefix invariants (e.g. "INVARIANT: …", "PETREO: …").
            $prefix = $this->matchPrefix($line, self::INVARIANT_PREFIXES);
            if ($prefix !== null) {
                $invariants[] = $line;
                if ($this->matchPrefix($line, self::FORBIDDEN_PREFIXES) !== null) {
                    $forbidden[] = $line;
                }

                continue;
            }

            // Inline frozen-language lines (no strict prefix but body mentions a frozen-language token).
            if ($this->containsFrozenLanguageToken($line)) {
                $invariants[] = $line;
            }
        }

        foreach ($lines as $line) {
            foreach ($this->extractAnchors($line) as $anchor) {
                $anchors[] = $anchor;
            }
            if ($contractVersion === null) {
                $contractVersion = $this->extractContractVersion($line);
            }
        }

        $invariants = $this->canonicalize($invariants);
        $forbidden = $this->canonicalize($forbidden);
        $anchors = $this->canonicalize($anchors);

        return [
            'schema' => self::SCHEMA,
            'class' => trim($class, '\\'),
            'invariants' => $invariants,
            'forbidden_mutations' => $forbidden,
            'anchors' => $anchors,
            'contract_version' => $contractVersion,
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeLines(string $doc): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $doc) ?: [] as $raw) {
            $line = trim($raw);
            // Strip docblock decoration (`/**`, `*/`, leading `*`).
            $line = preg_replace('#^(/\*\*|\*/|\*\s?)#', '', $line) ?? $line;
            $line = trim($line);
            $out[] = $line;
        }

        return $out;
    }

    /**
     * @param  list<string>  $prefixes
     */
    private function matchPrefix(string $line, array $prefixes): ?string
    {
        foreach ($prefixes as $prefix) {
            if (preg_match('/^'.preg_quote($prefix, '/').'\b\s*[:\-]?/iu', $line) === 1) {
                return $prefix;
            }
        }

        return null;
    }

    private function containsFrozenLanguageToken(string $line): bool
    {
        $haystack = mb_strtolower($line);
        foreach (self::FROZEN_LANGUAGE_TOKENS as $token) {
            if (preg_match('/\b'.preg_quote($token, '/').'\b/u', $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function extractAnchors(string $line): array
    {
        $out = [];
        // `@see X` and `{@see X}` — capture the FQN (everything up to the first whitespace, `}`, or `(`).
        if (preg_match_all('/@see\s+([^\s\}\(]+)/u', $line, $matches) > 0) {
            foreach ($matches[1] as $anchor) {
                $anchor = trim($anchor, " \t\n\r\0\x0B,;");
                if ($anchor !== '') {
                    $out[] = $anchor;
                }
            }
        }

        return $out;
    }

    private function extractContractVersion(string $line): ?string
    {
        // Common version hints: "@ 2026-06-18", "Slice N", "v+N", "v<N>"
        if (preg_match('/@\s*(\d{4}-\d{2}-\d{2})/u', $line, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/\b(Slice\s+\d+|v\+\d+|v\.\d+|v\d+(?:\.\d+)*)\b/u', $line, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    private function canonicalize(array $items): array
    {
        $items = array_values(array_unique($items));
        sort($items, SORT_STRING);

        return $items;
    }

    /**
     * @return array{schema:string, class:string, invariants:list<string>, forbidden_mutations:list<string>, anchors:list<string>, contract_version:?string}
     */
    private function emptyFacts(string $class): array
    {
        return [
            'schema' => self::SCHEMA,
            'class' => trim($class, '\\'),
            'invariants' => [],
            'forbidden_mutations' => [],
            'anchors' => [],
            'contract_version' => null,
        ];
    }
}
