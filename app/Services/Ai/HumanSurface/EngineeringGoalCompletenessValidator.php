<?php

declare(strict_types=1);

namespace App\Services\Ai\HumanSurface;

final class EngineeringGoalCompletenessValidator
{
    private const SCHEMA_VERSION = 'atlas.human_surface.engineering_goal_completeness.v1';

    /**
     * Canonical slot order. present_slots is the ordered subset that fired and
     * missing_slots is the ordered complement, both following this fixed order.
     *
     * @var list<string>
     */
    private const CANONICAL_SLOTS = [
        'target_artifact',
        'change_type',
        'verification',
        'scope_bound',
    ];

    /**
     * One lowercase-token probe set per slot. A slot fires when the lowercased
     * goal contains at least one of its tokens. No static answer table: every
     * decision is computed from the goal text against these rules.
     *
     * @var array<string, list<string>>
     */
    private const SLOT_TOKENS = [
        'target_artifact' => [
            '.php', '.ts', 'app/', 'tests/', 'classe ', 'class ', 'metodo', 'componente',
        ],
        'change_type' => [
            'adiciona', 'add', 'cria', 'corrige', 'fix', 'refator', 'remove', 'extrai',
        ],
        'verification' => [
            'teste', 'test', 'assert', 'deve ', 'espera', 'returns',
        ],
        'scope_bound' => [
            'um metodo', 'em app/', 'no arquivo', 'apenas', 'somente', 'um unico', 'so o',
        ],
    ];

    /**
     * @return array{schema_version: string, complete: bool, present_slots: list<string>, missing_slots: list<string>}
     */
    public function validate(string $goal): array
    {
        $haystack = strtolower(trim($goal));

        if ($haystack === '') {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'complete' => false,
                'present_slots' => [],
                'missing_slots' => self::CANONICAL_SLOTS,
            ];
        }

        $present = [];
        $missing = [];

        foreach (self::CANONICAL_SLOTS as $slot) {
            if ($this->slotFires($haystack, self::SLOT_TOKENS[$slot])) {
                $present[] = $slot;
            } else {
                $missing[] = $slot;
            }
        }

        $complete = in_array('target_artifact', $present, true)
            && in_array('change_type', $present, true);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'complete' => $complete,
            'present_slots' => $present,
            'missing_slots' => $missing,
        ];
    }

    /**
     * @param  list<string>  $tokens
     */
    private function slotFires(string $haystack, array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            if (str_contains($haystack, $token)) {
                return true;
            }
        }

        return false;
    }
}
