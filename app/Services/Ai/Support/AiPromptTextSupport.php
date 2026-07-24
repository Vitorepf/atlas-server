<?php

declare(strict_types=1);

namespace App\Services\Ai\Support;

use App\Support\YesNo;
use Illuminate\Support\Str;

/**
 * Pure prompt text helpers peeled from AiPromptBuilder (full-pass density).
 */
final class AiPromptTextSupport
{
    /**
     * @return list<string>
     */
    public static function awisPromptList(mixed $values, int $limit = 6): array
    {
        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->filter(fn (mixed $value): bool => is_scalar($value))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '' && ! self::awisPromptValueIsUnsafe($value))
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    public static function awisPromptSectionLines(string $title, array $items): array
    {
        if ($items === []) {
            return [];
        }

        return [
            '',
            $title.':',
            ...array_map(fn (string $item): string => '- '.$item, $items),
        ];
    }

    public static function awisPromptScalar(mixed $value, string $fallback): string
    {
        if (! is_scalar($value)) {
            return $fallback;
        }

        $value = trim((string) $value);

        return $value !== '' && ! self::awisPromptValueIsUnsafe($value) ? $value : $fallback;
    }

    public static function awisPromptValueIsUnsafe(string $value): bool
    {
        return preg_match('/\/Users\/|thread_id|source_thread_ids|raw_conversation|response_text|operator_input|full_message/i', $value) === 1;
    }

    /**
     * @return list<string>
     */
    public static function keywords(string $text): array
    {
        $normalized = Str::of($text)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', ' ')->value();
        $stop = ['para', 'com', 'uma', 'que', 'quando', 'onde', 'como', 'de', 'do', 'da', 'dos', 'das', 'the', 'and', 'use', 'when'];

        return collect(preg_split('/\s+/', $normalized) ?: [])
            ->filter(fn (string $word): bool => mb_strlen($word) >= 4 && ! in_array($word, $stop, true))
            ->unique()
            ->values()
            ->all();
    }

    public static function stringList(mixed $values): string
    {
        if (! is_array($values)) {
            return '';
        }

        return collect($values)
            ->filter(fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn (mixed $value): string => '- '.trim((string) $value))
            ->implode("\n");
    }

    /**
     * @param  array<string|int, mixed>  $values
     */
    public static function keyValueLines(array $values): string
    {
        return collect($values)
            ->map(function (mixed $value, string|int $key): string {
                if (is_bool($value)) {
                    $value = YesNo::trueFalse($value);
                } elseif (is_array($value)) {
                    $value = implode(', ', array_map(fn (mixed $item): string => (string) $item, $value));
                } elseif (! is_scalar($value)) {
                    $value = 'n/a';
                }

                return '- '.$key.': '.trim((string) $value);
            })
            ->implode("\n");
    }
}
