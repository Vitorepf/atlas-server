<?php

declare(strict_types=1);

namespace App\Services\Ai\OpenBrainContextPack;

use App\Services\Ai\Context\AtlasCanonicalContextRef;
use App\Services\Ai\Memory\AtlasMemoryRecallConcentrationDemotion;
use App\Services\Ai\OpenBrainContextInjection\TextNormalizeSupport;
use App\Services\Ai\Support\AiValueNormalizer;
use Throwable;

/**
 * GOD-DEBULK split of {@see \App\Services\Ai\AtlasOpenBrainContextPackService}.
 * Shared leaf primitives (opts/list/sizing/ref-matching) used across the AOBG
 * context-pack section collaborators. Verbatim bodies, no injected state.
 */
final class Support
{
    /**
     * @return array<int,string>
     */
    public function stringList(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        $clean = [];
        foreach ($values as $item) {
            if (is_string($item) && trim($item) !== '') {
                $clean[] = trim($item);
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * @param  array<int,string>  $values
     * @return array<int,string>
     */
    public function uniqueStrings(array $values): array
    {
        return array_values(array_unique(array_values(array_filter(array_map(
            static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
            $values,
        ), static fn (string $value): bool => $value !== ''))));
    }

    /**
     * @param  array<string,mixed>  $opts
     */
    public function intOpt(array $opts, string $key, int $default): int
    {
        $raw = $opts[$key] ?? null;
        if (is_int($raw)) {
            return max(0, $raw);
        }
        if (is_string($raw)) {
            $numeric = AiValueNormalizer::finiteFloatOrNull(trim($raw));
            if ($numeric !== null) {
                return (int) max(0, (int) floor($numeric));
            }
        }

        return max(0, $default);
    }

    /**
     * @param  array<string,mixed>  $opts
     */
    public function boolOpt(array $opts, string $key, bool $default): bool
    {
        $raw = $opts[$key] ?? null;
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw)) {
            return $raw !== 0;
        }
        if (is_string($raw)) {
            $normalized = strtolower(trim($raw));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return $default;
    }

    /**
     * @param  array<string,mixed>  $opts
     */
    public function stringOpt(array $opts, string $key): ?string
    {
        return TextNormalizeSupport::stringOpt($opts, $key);
    }

    /**
     * @param  array<string,mixed>  $values
     */
    public function floatMapValue(array $values, string $key, float $default): float
    {
        $value = AiValueNormalizer::finiteFloatOrNull($values[$key] ?? null);
        if ($value === null) {
            return $default;
        }

        return max(0.1, min(1.0, $value));
    }

    public function normalizedIntentText(string $value): string
    {
        $value = mb_strtolower($value);
        $value = strtr($value, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e',
            'í' => 'i',
            'ó' => 'o', 'õ' => 'o', 'ô' => 'o',
            'ú' => 'u',
            'ç' => 'c',
            'ñ' => 'n',
        ]);

        return preg_replace('/\s+/', ' ', trim($value)) ?: '';
    }

    public function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Obra 5 / MEM-04 + OPT-01 — demote dominant-memory refs on the initial AOBG pack.
     *
     * @return array<int,string>
     */
    public function concentrationDemoteContextRefs(): array
    {
        try {
            $demotion = new AtlasMemoryRecallConcentrationDemotion;

            return $demotion->demoteContextRefsForEntries($demotion->dominantEntryIds());
        } catch (Throwable) {
            return [];
        }
    }

    public function elapsedMs(int $startedAt): float
    {
        return round(max(0, hrtime(true) - $startedAt) / 1_000_000, 3);
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     */
    public function codeItemsChars(array $items): int
    {
        $chars = 0;
        foreach ($items as $item) {
            $chars += strlen(
                (string) ($item['id'] ?? '')
                .(string) ($item['file_path'] ?? '')
                .(string) ($item['signature'] ?? ''),
            );
        }

        return $chars;
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     */
    public function memoryItemsChars(array $items): int
    {
        $chars = 0;
        foreach ($items as $item) {
            $chars += strlen(
                (string) ($item['title'] ?? '')
                .(string) ($item['summary'] ?? '')
                .(string) ($item['body'] ?? ''),
            );
        }

        return $chars;
    }

    /**
     * @param  array<int,array<string,mixed>>  $paths
     */
    public function realityPathsChars(array $paths): int
    {
        $chars = 0;
        foreach ($paths as $path) {
            $chars += strlen((string) json_encode($path, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return $chars;
    }

    /**
     * @param  array<int,string>  $candidateRefs
     * @param  array<int,string>  $demoteRefs
     */
    public function matchesDemotedRef(array $candidateRefs, array $demoteRefs): bool
    {
        $candidateSet = array_fill_keys($this->contextRefMatchForms($candidateRefs), true);
        foreach ($this->contextRefMatchForms($demoteRefs) as $demoteRef) {
            if (isset($candidateSet[$demoteRef])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int,string>  $refs
     * @return array<int,string>
     */
    public function contextRefMatchForms(array $refs): array
    {
        $forms = [];
        foreach (AtlasCanonicalContextRef::uniqueStrings($refs) as $ref) {
            $forms[] = $ref;

            if (! $this->isSha256Hex($ref)) {
                $forms[] = hash('sha256', $ref);
            }
        }

        return AtlasCanonicalContextRef::uniqueStrings($forms);
    }

    public function isSha256Hex(string $ref): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $ref) === 1;
    }
}
