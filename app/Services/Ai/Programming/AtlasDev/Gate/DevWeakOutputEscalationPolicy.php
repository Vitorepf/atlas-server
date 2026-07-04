<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Gate;

/**
 * Model-amplifier escalation policy for Atlas Dev.
 *
 * When weak model output repeats, the system climbs the model ladder instead of
 * burning repair attempts on the same tier. This class only DECIDES; execution
 * stays with the existing repair loop and the provider resolver.
 *
 * Config defaults (from config/atlas_task_governance.php → dev_weak_output_escalation):
 *   ladder: ['small', 'medium', 'frontier']
 *   attempts_per_tier: 2
 */
final class DevWeakOutputEscalationPolicy
{
    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, mixed> $config dev_weak_output_escalation config section
     */
    public function __construct(array $config = [])
    {
        // Defaults from config/atlas_task_governance.php
        $this->config = array_merge([
            'ladder' => ['small', 'medium', 'frontier'],
            'attempts_per_tier' => 2,
        ], $config);
    }

    /**
     * @param array<int, array<string, mixed>> $weakSignalHistory ordered weak-output facts
     * @return array{action: 'retry_same_tier'|'escalate_tier'|'stop_and_surface', next_tier: string, reason: string}
     */
    public function decide(array $weakSignalHistory, string $currentTier): array
    {
        $ladder = (array) ($this->config['ladder'] ?? ['small', 'medium', 'frontier']);
        $attemptsPerTier = (int) ($this->config['attempts_per_tier'] ?? 2);

        // Unknown tier → stop_and_surface
        $currentIndex = array_search($currentTier, $ladder, true);
        if ($currentIndex === false) {
            return [
                'action' => 'stop_and_surface',
                'next_tier' => $currentTier,
                'reason' => "unknown_tier: {$currentTier}",
            ];
        }

        $weakCount = count($weakSignalHistory);

        // Still within the budget for this tier → retry
        if ($weakCount < $attemptsPerTier) {
            return [
                'action' => 'retry_same_tier',
                'next_tier' => $currentTier,
                'reason' => "weak_count_{$weakCount}_below_{$attemptsPerTier}_threshold",
            ];
        }

        // Budget exhausted → escalate or stop
        $nextIndex = $currentIndex + 1;

        if ($nextIndex < count($ladder)) {
            $nextTier = $ladder[$nextIndex];
            return [
                'action' => 'escalate_tier',
                'next_tier' => $nextTier,
                'reason' => "weak_count_{$weakCount}_exhausted_{$currentTier}_escalating_to_{$nextTier}",
            ];
        }

        // Already at the top of the ladder → stop
        return [
            'action' => 'stop_and_surface',
            'next_tier' => $currentTier,
            'reason' => "weak_count_{$weakCount}_at_top_tier_{$currentTier}_no_further_escalation",
        ];
    }
}
