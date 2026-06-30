<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

final class SelfConstructionCapabilityLadderLevelClassifier
{
    private const SCHEMA_VERSION = 'atlas.self_construction.capability_ladder.v1';

    /**
     * Ordered prerequisite signal for each ladder level above the baseline.
     * Index 0 gates L1, index 1 gates L2, ... index 7 gates L8.
     *
     * @var list<string>
     */
    private const PREREQUISITE_SIGNALS = [
        'has_canonical_doc',
        'has_spec',
        'has_scaffold',
        'manual_command_passes',
        'agent_executable_with_receipt',
        'repeated_safe_runs',
        'learning_proposals_safe',
        'strategic_selection_proven',
    ];

    /**
     * Label for each ladder level, indexed by level (0..8).
     *
     * @var list<string>
     */
    private const LEVEL_LABELS = [
        'named',
        'documented',
        'specified',
        'scaffolded',
        'executable_manual',
        'agent_executable',
        'autonomous_restricted',
        'self_improving_governed',
        'strategic_self_construction',
    ];

    /**
     * @param array<string, mixed> $signals
     *
     * @return array{
     *     schema_version: string,
     *     level: int,
     *     label: string,
     *     achieved_prerequisites: list<string>,
     *     broken_at: int|null,
     *     is_anti_confusion_safe: bool
     * }
     */
    public function classify(array $signals): array
    {
        $level = 0;
        $achievedPrerequisites = [];
        $brokenAt = null;

        foreach (self::PREREQUISITE_SIGNALS as $index => $signalKey) {
            if ($this->signalIsTrue($signals, $signalKey)) {
                $level = $index + 1;
                $achievedPrerequisites[] = $signalKey;

                continue;
            }

            $brokenAt = $index + 1;
            break;
        }

        $label = self::LEVEL_LABELS[$level];

        $missingPrerequisites = [];
        $nextLeverageSignal = null;
        if ($brokenAt !== null) {
            $nextLeverageSignal = self::PREREQUISITE_SIGNALS[$brokenAt - 1] ?? null;
            for ($i = $brokenAt - 1; $i < count(self::PREREQUISITE_SIGNALS); $i++) {
                $missingPrerequisites[] = self::PREREQUISITE_SIGNALS[$i];
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'level' => $level,
            'label' => $label,
            'achieved_prerequisites' => $achievedPrerequisites,
            'broken_at' => $brokenAt,
            'is_anti_confusion_safe' => $this->labelMatchesLevel($level, $label),
            'missing_prerequisites' => $missingPrerequisites,
            'next_leverage_signal' => $nextLeverageSignal,
            'finality_gap' => $level < 8,
        ];
    }

    /**
     * @param array<string, mixed> $signals
     */
    private function signalIsTrue(array $signals, string $key): bool
    {
        return ($signals[$key] ?? false) === true;
    }

    private function labelMatchesLevel(int $level, string $label): bool
    {
        return (self::LEVEL_LABELS[$level] ?? null) === $label;
    }
}
