<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

final class SelfConstructionCapabilityPromotionStepAdvisor
{
    private const SCHEMA_VERSION = 'atlas.self_construction.promotion_step.v1';

    private const FLOOR_LEVEL = 0;

    private const CEILING_LEVEL = 8;

    /**
     * Ordered promotion steps mirroring the canonical Promotion Requirements
     * table (capability-maturity-ladder.md). Each step is keyed by the source
     * level it promotes FROM and carries the single gating signal whose proof
     * unlocks the next level plus the canonical required-proof text.
     *
     * @var array<int, array{label: string, next_level: int, signal: string, proof: string}>
     */
    private const PROMOTION_STEPS = [
        0 => [
            'label' => 'L0->L1',
            'next_level' => 1,
            'signal' => 'has_canonical_doc',
            'proof' => 'Canonical doc and owner.',
        ],
        1 => [
            'label' => 'L1->L2',
            'next_level' => 2,
            'signal' => 'has_spec',
            'proof' => 'AP/spec, acceptance criteria, risk and non-goals.',
        ],
        2 => [
            'label' => 'L2->L3',
            'next_level' => 3,
            'signal' => 'has_scaffold',
            'proof' => 'Scaffold with tests or explicit scaffold marker.',
        ],
        3 => [
            'label' => 'L3->L4',
            'next_level' => 4,
            'signal' => 'manual_command_passes',
            'proof' => 'Passing manual command or test proving behavior.',
        ],
        4 => [
            'label' => 'L4->L5',
            'next_level' => 5,
            'signal' => 'agent_executable_with_receipt',
            'proof' => 'Agent can execute with Decision Receipt and gates.',
        ],
        5 => [
            'label' => 'L5->L6',
            'next_level' => 6,
            'signal' => 'repeated_safe_runs',
            'proof' => 'Repeated successful runs, rollback and drift checks.',
        ],
        6 => [
            'label' => 'L6->L7',
            'next_level' => 7,
            'signal' => 'learning_proposals_safe',
            'proof' => 'Learning proposals improve future runs without unsafe mutation.',
        ],
        7 => [
            'label' => 'L7->L8',
            'next_level' => 8,
            'signal' => 'strategic_selection_proven',
            'proof' => 'Priority engine, build graph and metrics prove strategic selection quality.',
        ],
    ];

    /**
     * @param array<string, mixed> $signals
     *
     * @return array{
     *     schema_version: string,
     *     next_promotion: ?string,
     *     next_level: ?int,
     *     required_proof: ?string,
     *     missing_proof: list<string>,
     *     is_promotable_now: bool,
     *     at_ceiling: bool
     * }
     */
    public function adviseNext(int $currentLevel, array $signals): array
    {
        $normalizedLevel = $this->normalizeLevel($currentLevel);

        if ($normalizedLevel >= self::CEILING_LEVEL) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'next_promotion' => null,
                'next_level' => null,
                'required_proof' => null,
                'missing_proof' => [],
                'is_promotable_now' => false,
                'at_ceiling' => true,
            ];
        }

        $step = self::PROMOTION_STEPS[$normalizedLevel];

        $missingProof = $this->missingProof($step['signal'], $signals);
        $isPromotableNow = $missingProof === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'next_promotion' => $step['label'],
            'next_level' => $step['next_level'],
            'required_proof' => $step['proof'],
            'missing_proof' => $missingProof,
            'is_promotable_now' => $isPromotableNow,
            'at_ceiling' => false,
        ];
    }

    /**
     * Below-floor levels clamp up to the floor; at-or-above-ceiling (including
     * out-of-range) collapse to the ceiling so there is no next promotion.
     */
    private function normalizeLevel(int $currentLevel): int
    {
        if ($currentLevel < self::FLOOR_LEVEL) {
            return self::FLOOR_LEVEL;
        }

        if ($currentLevel > self::CEILING_LEVEL) {
            return self::CEILING_LEVEL;
        }

        return $currentLevel;
    }

    /**
     * The gating signal keys for this step that are still not strictly true.
     *
     * @param array<string, mixed> $signals
     *
     * @return list<string>
     */
    private function missingProof(string $gatingSignal, array $signals): array
    {
        if ($this->signalSatisfied($gatingSignal, $signals)) {
            return [];
        }

        return [$gatingSignal];
    }

    /**
     * @param array<string, mixed> $signals
     */
    private function signalSatisfied(string $key, array $signals): bool
    {
        return ($signals[$key] ?? false) === true;
    }
}
