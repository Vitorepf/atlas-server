<?php

declare(strict_types=1);

namespace App\Services\Ai\Forge;

final class ObraUniversalQualityGateScorerService
{
    private const SCHEMA_VERSION = 'atlas.obra.universal_gates.v1';

    private const GATES_TOTAL = 12;

    /**
     * Universal Quality Gate field keys, in canonical doc order.
     * Gate number is the 1-based position in this list.
     *
     * @var list<string>
     */
    private const GATE_KEYS = [
        'objective',
        'definition_of_done',
        'structure',
        'next_step',
        'decisions',
        'evidence_refs',
        'risks',
        'tradeoffs',
        'current_version',
        'output_intent',
        'learning',
        'parent_objective',
    ];

    /**
     * Evaluate the 12 Universal Quality Gates over an obra's structured fields.
     *
     * Each gate is a present/non-empty predicate: an absent key, null, an
     * empty string, a whitespace-only string, or an empty array all count as
     * NOT passed (non-empty semantics, never mere key-existence).
     *
     * @param  array<string, mixed>  $obra
     * @return array{
     *     schema_version: string,
     *     gates: list<array{gate: int, key: string, passed: bool}>,
     *     gates_passed: int,
     *     gates_total: int,
     *     all_passed: bool,
     *     first_unmet_gate: int|null,
     *     first_unmet_key: string|null,
     *     has_objective: bool
     * }
     */
    public function score(array $obra): array
    {
        $gates = [];
        $gatesPassed = 0;
        $firstUnmetGate = null;
        $firstUnmetKey = null;

        foreach (self::GATE_KEYS as $index => $key) {
            $gateNumber = $index + 1;
            $passed = $this->fieldIsPresentAndNonEmpty($obra, $key);

            if ($passed) {
                $gatesPassed++;
            } elseif ($firstUnmetGate === null) {
                $firstUnmetGate = $gateNumber;
                $firstUnmetKey = $key;
            }

            $gates[] = [
                'gate' => $gateNumber,
                'key' => $key,
                'passed' => $passed,
            ];
        }

        $allPassed = $gatesPassed === self::GATES_TOTAL;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'gates' => $gates,
            'gates_passed' => $gatesPassed,
            'gates_total' => self::GATES_TOTAL,
            'all_passed' => $allPassed,
            'first_unmet_gate' => $firstUnmetGate,
            'first_unmet_key' => $firstUnmetKey,
            'has_objective' => $this->fieldIsPresentAndNonEmpty($obra, 'objective'),
        ];
    }

    /**
     * @param  array<string, mixed>  $obra
     */
    private function fieldIsPresentAndNonEmpty(array $obra, string $key): bool
    {
        if (! array_key_exists($key, $obra)) {
            return false;
        }

        return $this->valueIsNonEmpty($obra[$key]);
    }

    private function valueIsNonEmpty(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }
}
