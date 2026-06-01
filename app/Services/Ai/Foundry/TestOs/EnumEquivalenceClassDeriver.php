<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\TestOs;

use InvalidArgumentException;

final class EnumEquivalenceClassDeriver
{
    private const KIND_VALID = 'valid';

    private const KIND_INVALID = 'invalid';

    private const INVALID_SEED = '__not_a_member__';

    /**
     * Derive equivalence classes for an enum parameter specification.
     *
     * Produces one valid class per distinct enum member (duplicates collapse to
     * a single class) plus exactly one invalid class whose representative is a
     * sentinel value proven absent from the enum via in_array. The sentinel is
     * derived deterministically: a fixed seed is extended one stable character at
     * a time until in_array confirms it is a non-member.
     *
     * @param  array<string, mixed>  $paramSpec
     * @return array{classes: list<array{label: string, representative: mixed, kind: string}>}
     */
    public function derive(array $paramSpec): array
    {
        if (! array_key_exists('enum', $paramSpec)) {
            throw new InvalidArgumentException('paramSpec is missing the enum key.');
        }

        $enum = $paramSpec['enum'];

        if (! is_array($enum) || $enum === []) {
            throw new InvalidArgumentException('paramSpec enum must be a non-empty list of members.');
        }

        $name = $this->stringify($paramSpec['name'] ?? 'param');

        $distinct = $this->distinctMembers($enum);

        $classes = [];
        foreach ($distinct as $member) {
            $classes[] = [
                'label' => $name.'=='.$this->stringify($member),
                'representative' => $member,
                'kind' => self::KIND_VALID,
            ];
        }

        $invalidRepresentative = $this->deriveAbsentSentinel($distinct);

        $classes[] = [
            'label' => $name.'!='.$invalidRepresentative,
            'representative' => $invalidRepresentative,
            'kind' => self::KIND_INVALID,
        ];

        return ['classes' => $classes];
    }

    /**
     * Collapse duplicate members while preserving first-seen order.
     *
     * @param  array<int|string, mixed>  $enum
     * @return list<mixed>
     */
    private function distinctMembers(array $enum): array
    {
        $distinct = [];

        foreach ($enum as $member) {
            if (! in_array($member, $distinct, true)) {
                $distinct[] = $member;
            }
        }

        return $distinct;
    }

    /**
     * Build a string sentinel proven absent from the members via in_array.
     *
     * Starts from a fixed seed and appends a stable character until membership
     * checks fail; termination is guaranteed because the member list is finite.
     *
     * @param  list<mixed>  $members
     */
    private function deriveAbsentSentinel(array $members): string
    {
        $candidate = self::INVALID_SEED;

        while (in_array($candidate, $members, true)) {
            $candidate .= 'x';
        }

        return $candidate;
    }

    private function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return gettype($value);
    }
}
