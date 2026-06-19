<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Pattern;

use InvalidArgumentException;

/**
 * LOOP-PATTERN-REGISTRY · Slice 1 — the Compiler: binds ONE governed {@see AtlasLoopPatternSpec} to ONE
 * loop objective and emits the concrete {@see AtlasLoopExecutionContract} that ProjectionEngine /
 * Orchestrator / Grinder / Certifier prove against. This is the doc's "pattern escolhido + adaptação
 * mínima -> ExecutionContract Atlas": the spec contributes the *safety frontier* (gates, terminals,
 * durability, sandbox, lane policy), the objective contributes the *territory* (scope, inputs, outputs,
 * budget), and the compiler is the deterministic, side-effect-free binding of the two.
 *
 * Why the split matters (the invariant this class protects): the objective is operator/loop-supplied and
 * therefore UNTRUSTED to define safety. It may name where to work and what to produce, but it may NOT
 * loosen a gate, widen a terminal-state set, or grant a capability — those come ONLY from the governed
 * spec. So the compiler NEVER reads success_gates / terminal_states / sandbox_profile / durability /
 * agent_lane_policy from the objective; it copies them straight from the spec. A malicious or sloppy
 * objective cannot manufacture an unsafe-but-well-formed contract.
 *
 * Fail-closed (load-bearing): two gates, in order.
 *   1) Up-front guard: an empty objective text throws InvalidArgumentException — the loop must say what it
 *      is doing before any contract is built; a blank objective is never silently tolerated.
 *   2) Structural gate: the assembled array is handed to {@see AtlasLoopExecutionContract::fromArray()},
 *      which THROWS if a success gate, a success terminal state, or a sandbox profile is missing. The
 *      compiler deliberately does NOT synthesize a placeholder gate/terminal/capability to "rescue" a
 *      degenerate spec — a spec that would yield no gate must fail loudly, not certify nothing.
 *
 * Determinism: pure function of (spec, objective). No clock, no IO, no randomness, no provider calls — the
 * same inputs always compile to the same contract, so the binding is reproducible and unit-testable.
 */
final class AtlasLoopPatternCompiler
{
    /**
     * The default memory writeback stance for every compiled contract: evidence is always recorded, but
     * raw run material is NEVER auto-promoted into governed memory. Promotion is a separate, gated act
     * (the ChampionGate / operator), never a side effect of merely running a pattern — so the compiler
     * bakes the conservative stance in and an objective cannot flip raw_promote on.
     */
    private const DEFAULT_MEMORY_WRITEBACK = [
        'evidence' => true,
        'raw_promote' => false,
    ];

    /**
     * Compile ONE spec + ONE objective into a concrete, verifiable {@see AtlasLoopExecutionContract}.
     *
     * @param  array<string,mixed>  $objective  {
     *     objective: string (required, non-empty — the work statement),
     *     allowed_scope: list<glob>      (territory the run may touch; merged with the spec's scope hints),
     *     required_inputs: list<mixed>   (what must be present before the run may start),
     *     expected_outputs: list<mixed>  (receipts/invariants the certifier must observe),
     *     budget: array<string,mixed>    (time/iterations/cost/attempts; merged onto spec defaults),
     *  }
     *
     * @throws InvalidArgumentException when the objective text is empty (up-front guard), or when the
     *                                  assembled contract would be unsafe (gate/terminal/sandbox missing).
     */
    public function compile(AtlasLoopPatternSpec $spec, array $objective): AtlasLoopExecutionContract
    {
        // GATE 1 (up-front, fail-closed): the loop must declare WHAT it is doing. A blank objective is
        // never silently filled — refuse before any contract assembly.
        $objectiveText = trim((string) ($objective['objective'] ?? ''));
        if ($objectiveText === '') {
            throw new InvalidArgumentException(
                "Cannot compile ExecutionContract for pattern '{$spec->id}': objective text is required and was empty."
            );
        }

        // SAFETY FRONTIER — taken ONLY from the governed spec. The objective is untrusted to define these.
        // success_gates / terminal_states / durability / sandbox / lane policy are copied verbatim; an
        // objective can never loosen a gate, widen a terminal set, or grant a capability.
        $contract = [
            'pattern_id' => $spec->id,
            'pattern_version' => $spec->version,
            'objective' => $objectiveText,

            // TERRITORY — taken from the objective, merged with the spec's declared defaults. These say
            // *where* the run works and *what* it must produce; they carry no authority to relax safety.
            'allowed_scope' => $this->mergeScope($spec, $objective),
            'required_inputs' => $this->normalizeList($objective['required_inputs'] ?? []),
            'expected_outputs' => $this->mergeExpectedOutputs($spec, $objective),
            'budget' => $this->mergeBudget($spec, $objective),

            // SAFETY FRONTIER (spec is the single source of truth) — passed straight through, untouched.
            'success_gates' => $spec->successGates,
            'terminal_states' => $spec->terminalStates,
            'durability_mode' => $spec->durabilityMode,
            'sandbox_profile' => $spec->sandboxProfile,
            'agent_lane_policy' => $spec->agentLanePolicy,

            // Derived governance: rollback follows durability; writeback is the conservative default.
            'rollback_policy' => $this->rollbackPolicyFor($spec->durabilityMode),
            'memory_writeback_policy' => self::DEFAULT_MEMORY_WRITEBACK,
        ];

        // GATE 2 (structural, fail-closed): the ExecutionContract factory THROWS if a gate, a success
        // terminal state, or a sandbox profile is missing. The compiler does NOT pre-fill any of those —
        // a degenerate spec (e.g. one with zero non-empty gates) must fail here, loudly, not certify a
        // contract that proves nothing.
        return AtlasLoopExecutionContract::fromArray($contract);
    }

    /**
     * Rollback policy derived purely from the durability mode — the shape of state recovery follows the
     * shape of state persistence, so this is a function of the spec, not the objective.
     *
     * @return array<string,mixed>
     */
    private function rollbackPolicyFor(string $durabilityMode): array
    {
        return match ($durabilityMode) {
            // No durable state to keep: throw the whole worktree away on a failed/blocked run.
            AtlasLoopPatternSpec::DURABILITY_SINGLE_CYCLE => [
                'mode' => 'discard_worktree',
                'durability' => $durabilityMode,
            ],
            // Durable state machine: roll back to the last good checkpoint and resume, don't nuke progress.
            AtlasLoopPatternSpec::DURABILITY_RESUMABLE => [
                'mode' => 'checkpoint_resume',
                'durability' => $durabilityMode,
            ],
            // Campaign-supervised: the supervisor owns rollback of the campaign as a whole.
            AtlasLoopPatternSpec::DURABILITY_CAMPAIGN => [
                'mode' => 'supervised_rollback',
                'durability' => $durabilityMode,
            ],
            // External queue adapter: the external system is authoritative; we requeue rather than revert.
            AtlasLoopPatternSpec::DURABILITY_EXTERNAL_QUEUE => [
                'mode' => 'external_requeue',
                'durability' => $durabilityMode,
            ],
            // Defensive default (should be unreachable: the spec validated durability into the vocab).
            default => [
                'mode' => 'discard_worktree',
                'durability' => $durabilityMode,
            ],
        };
    }

    /**
     * The allowed scope is the union of what the objective explicitly permits and the spec's own scope
     * hints (params_schema.allowed_globs / output_schema globs, when present). The objective WIDENS the
     * territory but never the capability frontier — scope is "which paths", sandbox is "what powers", and
     * only the latter is a safety control owned solely by the spec.
     *
     * @param  array<string,mixed>  $objective
     * @return list<string>
     */
    private function mergeScope(AtlasLoopPatternSpec $spec, array $objective): array
    {
        $fromObjective = $this->normalizeStringList($objective['allowed_scope'] ?? []);
        $fromSpec = $this->normalizeStringList($spec->paramsSchema['allowed_globs'] ?? []);

        return array_values(array_unique([...$fromObjective, ...$fromSpec]));
    }

    /**
     * Expected outputs combine the objective's stated outputs with the keys the spec's output_schema
     * declares the certifier will look for — so a compiled contract always at least names the receipts
     * the pattern was designed to prove, even if the objective forgot to.
     *
     * @param  array<string,mixed>  $objective
     * @return list<mixed>
     */
    private function mergeExpectedOutputs(AtlasLoopPatternSpec $spec, array $objective): array
    {
        $fromObjective = $this->normalizeList($objective['expected_outputs'] ?? []);
        $fromSpecSchema = array_values(array_map(
            static fn ($k): string => (string) $k,
            array_keys($spec->outputSchema)
        ));

        return array_values(array_unique([...$fromObjective, ...$fromSpecSchema], SORT_REGULAR));
    }

    /**
     * Budget = the spec's declared defaults overlaid with the objective's explicit caps. The objective
     * may tighten or set budget knobs (time/iterations/cost/attempts) for this run; the spec supplies any
     * defaults it declared in params_schema.budget. Budget is a resource bound, not a safety frontier, so
     * the objective is allowed to drive it.
     *
     * @param  array<string,mixed>  $objective
     * @return array<string,mixed>
     */
    private function mergeBudget(AtlasLoopPatternSpec $spec, array $objective): array
    {
        $specDefaults = (array) ($spec->paramsSchema['budget'] ?? []);
        $fromObjective = (array) ($objective['budget'] ?? []);

        // Objective values win on key collision (it is configuring THIS run); spec fills the gaps.
        return [...$specDefaults, ...$fromObjective];
    }

    /**
     * @param  mixed  $value
     * @return list<mixed>
     */
    private function normalizeList(mixed $value): array
    {
        return array_values((array) $value);
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        return array_values(array_filter(
            array_map(static fn ($v): string => trim((string) $v), (array) $value),
            static fn (string $v): bool => $v !== ''
        ));
    }
}
