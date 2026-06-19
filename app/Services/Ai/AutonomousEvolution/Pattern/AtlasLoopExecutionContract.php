<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Pattern;

use InvalidArgumentException;

/**
 * LOOP-PATTERN-REGISTRY · Slice 1 — the ExecutionContract: the concrete, verifiable work order the
 * {@see AtlasLoopPatternCompiler} produces by binding ONE selected {@see AtlasLoopPatternSpec} to ONE
 * loop objective. It is the hand-off artifact ProjectionEngine / Orchestrator / Grinder / Certifier can
 * prove against — the doc's "pattern escolhido + adaptação mínima -> ExecutionContract Atlas".
 *
 * Fail-closed: a contract cannot exist without success gates, a terminal-state set that includes a
 * success state, and a declared sandbox profile. {@see fromArray()} throws on any of those. This is the
 * structural guarantee that "the loop chose a pattern" can never silently degrade into "the loop is
 * running unbounded, ungated work".
 */
final class AtlasLoopExecutionContract
{
    /**
     * @param  array<string,mixed>  $allowedScope          paths/areas/artifacts the run may touch or propose
     * @param  array<string,mixed>  $requiredInputs        what must be present before the run may start
     * @param  array<string,mixed>  $expectedOutputs       receipts/invariants the certifier must observe
     * @param  list<string>         $successGates          reproducible independent proofs (≥1, fail-closed)
     * @param  list<string>         $terminalStates        honest end states, includes a success state
     * @param  array<string,mixed>  $sandboxProfile        capability frontier (deny-by-default)
     * @param  array<string,mixed>  $agentLanePolicy       lane split; verifier independent of implementer
     * @param  array<string,mixed>  $budget                time/iterations/cost/attempts/territory/escalation
     * @param  array<string,mixed>  $rollbackPolicy        how a failed/blocked run is reverted
     * @param  array<string,mixed>  $memoryWritebackPolicy what is recorded to Evidence/Learning (no raw promote)
     */
    private function __construct(
        public readonly string $patternId,
        public readonly string $patternVersion,
        public readonly string $objective,
        public readonly array $allowedScope,
        public readonly array $requiredInputs,
        public readonly array $expectedOutputs,
        public readonly array $successGates,
        public readonly array $terminalStates,
        public readonly string $durabilityMode,
        public readonly array $sandboxProfile,
        public readonly array $agentLanePolicy,
        public readonly array $budget,
        public readonly array $rollbackPolicy,
        public readonly array $memoryWritebackPolicy,
    ) {
    }

    /**
     * Fail-closed factory. There is no way to obtain a contract missing a gate, a success terminal
     * state, or a sandbox profile — the compiler relies on this so it cannot emit an unsafe contract.
     *
     * @param  array<string,mixed>  $data
     *
     * @throws InvalidArgumentException listing exactly what is missing.
     */
    public static function fromArray(array $data): self
    {
        $missing = self::missingFields($data);
        if ($missing !== []) {
            throw new InvalidArgumentException(
                'Incomplete ExecutionContract: missing/invalid ['.implode(', ', $missing).']'
            );
        }

        return new self(
            patternId: (string) $data['pattern_id'],
            patternVersion: (string) $data['pattern_version'],
            objective: (string) $data['objective'],
            allowedScope: (array) ($data['allowed_scope'] ?? []),
            requiredInputs: (array) ($data['required_inputs'] ?? []),
            expectedOutputs: (array) ($data['expected_outputs'] ?? []),
            successGates: array_values(array_filter(array_map(
                static fn ($g): string => trim((string) $g),
                (array) $data['success_gates']
            ), static fn (string $g): bool => $g !== '')),
            terminalStates: array_values(array_map(static fn ($s): string => (string) $s, (array) $data['terminal_states'])),
            durabilityMode: (string) $data['durability_mode'],
            sandboxProfile: (array) $data['sandbox_profile'],
            agentLanePolicy: (array) ($data['agent_lane_policy'] ?? []),
            budget: (array) ($data['budget'] ?? []),
            rollbackPolicy: (array) ($data['rollback_policy'] ?? []),
            memoryWritebackPolicy: (array) ($data['memory_writeback_policy'] ?? []),
        );
    }

    /**
     * The fail-closed conditions. Empty list == a complete, safe contract.
     *
     * @param  array<string,mixed>  $data
     * @return list<string>
     */
    public static function missingFields(array $data): array
    {
        $missing = [];

        if (trim((string) ($data['pattern_id'] ?? '')) === '') {
            $missing[] = 'pattern_id';
        }
        if (trim((string) ($data['pattern_version'] ?? '')) === '') {
            $missing[] = 'pattern_version';
        }
        if (trim((string) ($data['objective'] ?? '')) === '') {
            $missing[] = 'objective';
        }

        // GATE (fail-closed): at least one non-empty success gate.
        $gates = array_values(array_filter(array_map(
            static fn ($g): string => trim((string) $g),
            (array) ($data['success_gates'] ?? [])
        ), static fn (string $g): bool => $g !== ''));
        if ($gates === []) {
            $missing[] = 'success_gates';
        }

        // TERMINAL (fail-closed): a non-empty set that includes a success terminal state.
        $terminals = array_map(static fn ($s): string => (string) $s, (array) ($data['terminal_states'] ?? []));
        if ($terminals === [] || ! in_array(AtlasLoopPatternSpec::TERMINAL_SUCCESS, $terminals, true)) {
            $missing[] = 'terminal_states';
        }

        if (! in_array((string) ($data['durability_mode'] ?? ''), AtlasLoopPatternSpec::DURABILITY_MODES, true)) {
            $missing[] = 'durability_mode';
        }

        // SANDBOX (fail-closed): a declared profile with a non-empty allow-list (read_only counts).
        $sandbox = (array) ($data['sandbox_profile'] ?? []);
        if (($sandbox['allowed'] ?? []) === []) {
            $missing[] = 'sandbox_profile';
        }

        return $missing;
    }

    public function isComplete(): bool
    {
        return self::missingFields($this->toArray()) === [];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'pattern_id' => $this->patternId,
            'pattern_version' => $this->patternVersion,
            'objective' => $this->objective,
            'allowed_scope' => $this->allowedScope,
            'required_inputs' => $this->requiredInputs,
            'expected_outputs' => $this->expectedOutputs,
            'success_gates' => $this->successGates,
            'terminal_states' => $this->terminalStates,
            'durability_mode' => $this->durabilityMode,
            'sandbox_profile' => $this->sandboxProfile,
            'agent_lane_policy' => $this->agentLanePolicy,
            'budget' => $this->budget,
            'rollback_policy' => $this->rollbackPolicy,
            'memory_writeback_policy' => $this->memoryWritebackPolicy,
        ];
    }
}
