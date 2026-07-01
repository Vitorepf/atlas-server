<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Composes a rollback receipt for a governed simplification wave, binding
 * before/after targets, removed and replacement symbols, proof refs, and
 * rollback steps. reversible is false whenever before-state hash,
 * replacement symbol data, or proof references are missing — an incomplete
 * receipt cannot honestly claim it can be undone.
 */
final class AtlasSelfConstructionSimplificationRollbackReceiptComposer
{
    private const SCHEMA = 'atlas.self_construction.simplification_rollback_receipt.v1';

    /**
     * @param  array<string,mixed>  $wave
     * @return array<string,mixed>
     */
    public function compose(array $wave): array
    {
        $beforeHash = trim((string) ($wave['before_hash'] ?? ''));
        $replacementSymbols = array_values((array) ($wave['replacement_symbols'] ?? []));
        $proofRefs = array_values((array) ($wave['proof_refs'] ?? []));

        $blockers = [];
        if ($beforeHash === '') {
            $blockers[] = 'before_hash_missing';
        }
        if ($replacementSymbols === []) {
            $blockers[] = 'replacement_symbols_missing';
        }
        if ($proofRefs === []) {
            $blockers[] = 'proof_refs_missing';
        }

        return [
            'schema_version' => self::SCHEMA,
            'wave_id' => (string) ($wave['wave_id'] ?? ''),
            'before_targets' => array_values((array) ($wave['before_targets'] ?? [])),
            'after_targets' => array_values((array) ($wave['after_targets'] ?? [])),
            'removed_symbols' => array_values((array) ($wave['removed_symbols'] ?? [])),
            'replacement_symbols' => $replacementSymbols,
            'proof_refs' => $proofRefs,
            'rollback_steps' => array_values((array) ($wave['rollback_steps'] ?? [])),
            'blockers' => $blockers,
            'reversible' => $blockers === [],
        ];
    }
}
