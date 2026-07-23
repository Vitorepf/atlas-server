<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control;

/**
 * Fail-closed admission for human-out-of-loop defaults.
 * Policy-pure: no I/O (world is injected).
 */
final class AaeosAdmissionPolicy
{
    public const SCHEMA = 'atlas.aaeos.admission.v1';

    /**
     * @param  array<string,mixed>  $objective
     * @param  array<string,mixed>  $difficulty
     * @param  array<string,mixed>  $modeSelection
     * @param  array<string,mixed>  $world
     * @return array{
     *   schema:string,
     *   verdict:string,
     *   allows_execution:bool,
     *   reasons:list<string>,
     *   mode:string,
     *   difficulty_level:int
     * }
     */
    public function admit(array $objective, array $difficulty, array $modeSelection, array $world = []): array
    {
        $reasons = [];
        $mode = (string) ($modeSelection['mode'] ?? '');
        $level = (int) ($difficulty['level'] ?? AaeosDifficultyLevel::L1);

        if (! AaeosExecutorMode::isValid($mode)) {
            return $this->pack(AaeosAdmissionVerdict::HALT_SOVEREIGN, ['invalid_mode'], $mode, $level);
        }

        if ((bool) ($objective['irreversible'] ?? false)) {
            $reasons[] = 'irreversible_risk_requires_sovereign';
            return $this->pack(AaeosAdmissionVerdict::HALT_SOVEREIGN, $reasons, $mode, $level);
        }

        if ((bool) ($objective['business_ambiguous'] ?? false)
            && $mode !== AaeosExecutorMode::DEV) {
            $reasons[] = 'business_ambiguous_without_interactive_dev';
            return $this->pack(AaeosAdmissionVerdict::HALT_SOVEREIGN, $reasons, $mode, $level);
        }

        if ((bool) ($world['incident_open'] ?? false) && $level >= AaeosDifficultyLevel::L4) {
            $reasons[] = 'open_incident_blocks_high_difficulty_auto';
            return $this->pack(AaeosAdmissionVerdict::HALT_SOVEREIGN, $reasons, $mode, $level);
        }

        if ((float) ($world['budget_pressure'] ?? 0.0) >= 0.9 && $level >= AaeosDifficultyLevel::L3) {
            $reasons[] = 'budget_pressure_high_notify';
            return $this->pack(AaeosAdmissionVerdict::AUTO_NOTIFY, $reasons, $mode, $level);
        }

        if ((int) ($world['recent_failure_count'] ?? 0) >= 3) {
            $reasons[] = 'recent_failures_require_notify';
            return $this->pack(AaeosAdmissionVerdict::AUTO_NOTIFY, $reasons, $mode, $level);
        }

        if ($level >= AaeosDifficultyLevel::L5) {
            $reasons[] = 'frontier_l5_auto_with_notify';
            return $this->pack(AaeosAdmissionVerdict::AUTO_NOTIFY, $reasons, $mode, $level);
        }

        if ($level >= AaeosDifficultyLevel::L4 && $mode === AaeosExecutorMode::DEV) {
            $reasons[] = 'l4_on_dev_should_escalate_forge_notify';
            return $this->pack(AaeosAdmissionVerdict::AUTO_NOTIFY, $reasons, $mode, $level);
        }

        if ((int) ($world['queue_depth'] ?? 0) > 100 && $mode === AaeosExecutorMode::AUTONOMOS) {
            $reasons[] = 'queue_pressure_notify';
            return $this->pack(AaeosAdmissionVerdict::AUTO_NOTIFY, $reasons, $mode, $level);
        }

        $reasons[] = 'admitted_auto_same_bar';
        return $this->pack(AaeosAdmissionVerdict::AUTO, $reasons, $mode, $level);
    }

    /**
     * @param  list<string>  $reasons
     * @return array{
     *   schema:string,
     *   verdict:string,
     *   allows_execution:bool,
     *   reasons:list<string>,
     *   mode:string,
     *   difficulty_level:int
     * }
     */
    private function pack(string $verdict, array $reasons, string $mode, int $level): array
    {
        return [
            'schema' => self::SCHEMA,
            'verdict' => $verdict,
            'allows_execution' => AaeosAdmissionVerdict::allowsExecution($verdict),
            'reasons' => $reasons,
            'mode' => $mode,
            'difficulty_level' => $level,
        ];
    }
}
