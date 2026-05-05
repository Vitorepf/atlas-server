<?php

namespace App\Services\Ai\PersonalDevelopment;

use Illuminate\Support\Str;

class PersonalDevelopmentInputNormalizer
{
    /**
     * @var array<int,string>
     */
    private const ALLOWED_KEYS = [
        'intent',
        'goal',
        'observations',
        'constraints',
        'available_time',
        'energy_notes',
        'focus_notes',
        'learning_notes',
        'current_routine',
        'desired_outcome',
        'review_window',
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array{input:array<string,mixed>,contract:array<string,mixed>}
     */
    public function normalize(array $input): array
    {
        $normalized = [];
        $ignored = [];

        foreach ($input as $key => $value) {
            if (! is_string($key) || ! in_array($key, self::ALLOWED_KEYS, true)) {
                $ignored[] = (string) $key;

                continue;
            }

            $clean = $this->cleanValue($value);

            if ($clean === null || $clean === [] || $clean === '') {
                continue;
            }

            $normalized[$key] = $clean;
        }

        return [
            'input' => $normalized,
            'contract' => [
                'schema_version' => 1,
                'accepted_keys' => array_keys($normalized),
                'ignored_keys' => array_values(array_unique($ignored)),
                'allowed_keys' => self::ALLOWED_KEYS,
                'has_intent' => $this->hasIntent($normalized),
                'raw_input_retained' => false,
            ],
        ];
    }

    /**
     * @return array<int,string>
     */
    public static function allowedKeys(): array
    {
        return self::ALLOWED_KEYS;
    }

    private function cleanValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return Str::limit(trim($value), 2000, '');
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return null;
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $clean = Str::limit(trim($item), 500, '');
                if ($clean !== '') {
                    $items[] = $clean;
                }

                continue;
            }

            if (is_bool($item) || is_int($item) || is_float($item)) {
                $items[] = $item;
            }
        }

        return array_slice($items, 0, 20);
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function hasIntent(array $input): bool
    {
        return trim((string) ($input['intent'] ?? $input['goal'] ?? $input['desired_outcome'] ?? '')) !== '';
    }
}
