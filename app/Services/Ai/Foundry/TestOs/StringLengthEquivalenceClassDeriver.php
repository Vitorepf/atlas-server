<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\TestOs;

use InvalidArgumentException;

final class StringLengthEquivalenceClassDeriver
{
    private const FILL_CHARACTER = 'a';

    /**
     * Derive the four string-length equivalence classes for a string parameter
     * whose validity is bounded by inclusive [min, max] length constraints.
     *
     * Each representative is materialised with str_repeat so that strlen()
     * equals the declared length exactly.
     *
     * @param  array{name?: string, type?: string, min?: int, max?: int}  $paramSpec
     * @return array{classes: list<array{label: string, representative: string, length: int, kind: string}>}
     */
    public function derive(array $paramSpec): array
    {
        if (! array_key_exists('min', $paramSpec) || ! array_key_exists('max', $paramSpec)) {
            throw new InvalidArgumentException('paramSpec requires both min and max length bounds.');
        }

        $min = (int) $paramSpec['min'];
        $max = (int) $paramSpec['max'];

        if ($min < 0) {
            throw new InvalidArgumentException('min length must not be negative.');
        }

        if ($max < $min) {
            throw new InvalidArgumentException('max length must be greater than or equal to min length.');
        }

        $emptyKind = $min > 0 ? 'invalid' : 'boundary';

        return [
            'classes' => [
                $this->class('empty', 0, $emptyKind),
                $this->class('min-len', $min, 'boundary'),
                $this->class('max-len', $max, 'boundary'),
                $this->class('over-max', $max + 1, 'invalid'),
            ],
        ];
    }

    /**
     * @return array{label: string, representative: string, length: int, kind: string}
     */
    private function class(string $label, int $length, string $kind): array
    {
        return [
            'label' => $label,
            'representative' => str_repeat(self::FILL_CHARACTER, $length),
            'length' => $length,
            'kind' => $kind,
        ];
    }
}
