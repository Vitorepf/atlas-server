<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control;

/**
 * Selects Dev | Forge | Autonomos. Policy-pure (no I/O).
 *
 * @see docs/engineering-knowledge-base/atlas-elite-executors-dev-forge-autonomos.md
 */
final class AaeosModeSelector
{
    public const SCHEMA = 'atlas.aaeos.mode_selection.v1';

    /**
     * @param  array<string,mixed>  $objective
     * @param  array<string,mixed>  $difficulty  from AaeosDifficultyClassifier
     * @param  array<string,mixed>  $world
     * @return array{schema:string,mode:string,reason:string,same_bar:true,difficulty_level:int}
     */
    public function select(array $objective, array $difficulty, array $world = []): array
    {
        $forced = strtolower(trim((string) ($world['force_mode'] ?? '')));
        if (AaeosExecutorMode::isValid($forced)) {
            return $this->result($forced, 'forced_by_caller', $difficulty);
        }

        $interactive = (bool) ($objective['interactive'] ?? false);
        $multi = (bool) ($objective['multi_packet'] ?? false);
        $selfEvolve = (bool) ($objective['self_evolve'] ?? false);
        $level = (int) ($difficulty['level'] ?? AaeosDifficultyLevel::L1);
        $source = strtolower((string) ($objective['source'] ?? ''));

        if ($selfEvolve || in_array($source, ['brain', 'autonomos', 'night', 'queue'], true)
            || (bool) ($world['queue_default'] ?? false)) {
            return $this->result(AaeosExecutorMode::AUTONOMOS, 'zero_operator_queue_or_self_evolve', $difficulty);
        }

        if ($multi || $level >= AaeosDifficultyLevel::L4
            || in_array($source, ['forge', 'obra'], true)
            || (bool) ($world['obra'] ?? false)) {
            return $this->result(AaeosExecutorMode::FORGE, 'long_obra_or_multi_packet', $difficulty);
        }

        if ($interactive || in_array($source, ['human', 'dev', 'cli', 'ask', 'session'], true)) {
            return $this->result(AaeosExecutorMode::DEV, 'interactive_human_intent', $difficulty);
        }

        // Default of the agentic era: autonomos, not silent human wait.
        return $this->result(AaeosExecutorMode::AUTONOMOS, 'default_out_of_loop', $difficulty);
    }

    /**
     * @param  array<string,mixed>  $difficulty
     * @return array{schema:string,mode:string,reason:string,same_bar:true,difficulty_level:int}
     */
    private function result(string $mode, string $reason, array $difficulty): array
    {
        return [
            'schema' => self::SCHEMA,
            'mode' => $mode,
            'reason' => $reason,
            'same_bar' => true,
            'difficulty_level' => (int) ($difficulty['level'] ?? AaeosDifficultyLevel::L1),
        ];
    }
}
