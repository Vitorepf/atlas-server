<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Proof-first admission gate for the task fabric: a muscle wastes a cycle every time it claims
 * a destructive compression task whose proof work was never done. This emitter reads the same
 * four required proofs as the proof-debt ledger — tests, replay, rollback, contract — per target
 * and emits proof_prework tasks BEFORE any compress task, and emits a compress task for a
 * target ONLY when all four proofs are present and green. A target with any missing proof never
 * gets a compress task in the same batch; it gets exactly the prework it's missing instead.
 *
 * Input contract:
 *   targets: list<array{
 *     target?:              string,
 *     action?:              string  ('delete'|'merge'|'simplify', default 'simplify'),
 *     has_tests?:           bool,
 *     has_replay_proof?:    bool,
 *     has_rollback_proof?:  bool,
 *     has_contract_proof?:  bool,
 *   }>
 *
 * Ordering: every proof_prework task in the batch precedes every compress task, regardless of
 * target order in the input — a muscle draining this queue always clears proof debt first.
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainProofFirstTaskEmitter
{
    public const SCHEMA = 'atlas.external_brain.proof_first_task_emitter.v1';

    public const TASK_TYPE_PROOF_PREWORK = 'proof_prework';
    public const TASK_TYPE_COMPRESS      = 'compress';

    private const PROOF_FLAGS = [
        'tests'    => 'has_tests',
        'replay'   => 'has_replay_proof',
        'rollback' => 'has_rollback_proof',
        'contract' => 'has_contract_proof',
    ];

    /**
     * @param  array{targets?: list<array<string,mixed>>}  $facts
     * @return array{schema:string, tasks:list<array<string,mixed>>}
     */
    public function emit(array $facts): array
    {
        $targets = is_array($facts['targets'] ?? null) ? $facts['targets'] : [];

        $proofPreworkTasks = [];
        $compressTasks = [];

        foreach ($targets as $target) {
            if (! is_array($target)) {
                continue;
            }

            $targetName = trim((string) ($target['target'] ?? ''));
            if ($targetName === '') {
                continue;
            }

            $action = (string) ($target['action'] ?? 'simplify');

            $proofDebt = [];
            foreach (self::PROOF_FLAGS as $proof => $flag) {
                if (! (bool) ($target[$flag] ?? false)) {
                    $proofDebt[] = $proof;
                }
            }

            if ($proofDebt !== []) {
                $proofPreworkTasks[] = [
                    'type'              => self::TASK_TYPE_PROOF_PREWORK,
                    'target'            => $targetName,
                    'required_prework'  => array_map(
                        static fn (string $proof): string => "provide_{$proof}_proof_for_{$targetName}",
                        $proofDebt,
                    ),
                ];

                continue;
            }

            $compressTasks[] = [
                'type'   => self::TASK_TYPE_COMPRESS,
                'target' => $targetName,
                'action' => $action,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'tasks'  => array_merge($proofPreworkTasks, $compressTasks),
        ];
    }
}
