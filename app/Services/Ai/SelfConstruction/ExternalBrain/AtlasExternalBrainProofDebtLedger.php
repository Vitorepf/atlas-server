<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Proof-gap ledger for compression targets: simplification should never repeatedly create
 * blocked tasks when the missing proof work was already knowable upfront. Each target is
 * evaluated against four required proofs — tests, replay, rollback, contract — and any missing
 * proof becomes an explicit required_prework item that holds the target's compression
 * eligibility, instead of a passive documentation note nobody acts on.
 *
 * Input shape:
 *   { targets: list<{
 *       target?:              string,
 *       has_tests?:           bool,
 *       has_replay_proof?:    bool,
 *       has_rollback_proof?:  bool,
 *       has_contract_proof?:  bool,
 *   }> }
 *
 * A target is compression-eligible only when it carries ZERO proof debt (all four proofs
 * present); any missing proof holds it and names the exact prework needed to clear the debt.
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainProofDebtLedger
{
    public const SCHEMA = 'atlas.self_construction.external_brain.proof_debt_ledger.v1';

    private const PROOF_FLAGS = [
        'tests' => 'has_tests',
        'replay' => 'has_replay_proof',
        'rollback' => 'has_rollback_proof',
        'contract' => 'has_contract_proof',
    ];

    /**
     * @param  array{targets?: list<array<string,mixed>>}  $facts
     * @return array<string,mixed>
     */
    public function ledger(array $facts): array
    {
        $targets = is_array($facts['targets'] ?? null) ? $facts['targets'] : [];

        $rows = [];
        foreach ($targets as $t) {
            if (! is_array($t)) {
                continue;
            }
            $target = trim((string) ($t['target'] ?? ''));
            if ($target === '') {
                continue;
            }

            $proofDebt = [];
            foreach (self::PROOF_FLAGS as $proof => $flag) {
                if (! (bool) ($t[$flag] ?? false)) {
                    $proofDebt[] = $proof;
                }
            }

            $eligible = $proofDebt === [];

            $rows[$target] = [
                'proof_debt' => $proofDebt,
                'eligible' => $eligible,
                'required_prework' => array_map(
                    static fn (string $proof): string => "provide_{$proof}_proof_for_{$target}",
                    $proofDebt,
                ),
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'targets' => $rows,
            'eligible_targets' => array_values(array_keys(array_filter($rows, static fn (array $r): bool => $r['eligible']))),
            'held_targets' => array_values(array_keys(array_filter($rows, static fn (array $r): bool => ! $r['eligible']))),
        ];
    }
}
