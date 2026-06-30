<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ArchitectureCouncil;

/**
 * Pure extractor. Turns architecture contract FACTS into explicit, machine-checkable invariants the
 * Verification Court tests + the Merge Governor gates can enforce.
 *
 * Output: {schema_version, invariants:list<{invariant_id, organ, must_hold, violation_effect, test_hint}>,
 *          blockers:list<string>}
 *
 * Canonical invariants emitted when the contract carries the matching flag:
 *   - separation_of_powers       : when contract.separates_roles == true
 *   - atlas_native_ownership     : when contract.owning_runtime == 'atlas_native'
 *   - shared_main_topology       : when contract.workspace_topology == 'shared_local_main_with_scope_lock'
 *   - evidence_required          : when contract.requires_evidence == true
 *   - no_self_certification      : when contract.allows_self_certification == false (i.e. forbidden)
 *
 * Empty contracts are blocked. Duplicate invariant_ids inside contract.extra_invariants are blocked.
 */
final class AtlasArchitectureCouncilInvariantExtractor
{
    public const SCHEMA = 'atlas.architecture_council.invariant_extract.v1';

    /**
     * @param  array<string,mixed>  $contract
     * @return array<string,mixed>
     */
    public function extract(array $contract): array
    {
        if ($contract === []) {
            return $this->envelope([], ['empty_contract']);
        }

        $organ = (string) ($contract['organ'] ?? 'unknown_organ');
        $emitted = [];

        if ((bool) ($contract['separates_roles'] ?? false)) {
            $emitted[] = [
                'invariant_id' => 'separation_of_powers',
                'organ' => $organ,
                'must_hold' => 'writer ≠ judge (no organ may both produce and certify the same artifact)',
                'violation_effect' => 'block_promotion',
                'test_hint' => 'assert that '.$organ.' writer and judge classes are NOT the same class',
            ];
        }
        $owningRuntime = (string) ($contract['owning_runtime'] ?? '');
        if ($owningRuntime === 'atlas_native') {
            $emitted[] = [
                'invariant_id' => 'atlas_native_ownership',
                'organ' => $organ,
                'must_hold' => 'steady-state runtime owner remains atlas_native',
                'violation_effect' => 'block_promotion',
                'test_hint' => 'audit::atlas_native == true (no operator/human/provider in steady-state path)',
            ];
            $emitted[] = [
                'invariant_id' => 'runtime_boundary_ownership',
                'organ' => $organ,
                'must_hold' => 'runtime boundary is owned and enforced by atlas_native (no external boundary crossing)',
                'violation_effect' => 'block_promotion',
                'test_hint' => 'assert runtime_boundary is scoped to atlas_native domain',
            ];
        } elseif ($owningRuntime !== '') {
            return $this->envelope([], ['invalid_organ_ownership:'.$owningRuntime]);
        }
        if ((string) ($contract['workspace_topology'] ?? '') === 'shared_local_main_with_scope_lock') {
            $emitted[] = [
                'invariant_id' => 'shared_main_topology',
                'organ' => $organ,
                'must_hold' => 'no worktree spawn; shared local main + per-packet scope lock',
                'violation_effect' => 'block_promotion',
                'test_hint' => 'assert workspace_policy.isolation == shared_local_main_with_scope_lock',
            ];
        }
        if ((bool) ($contract['requires_evidence'] ?? false)) {
            $emitted[] = [
                'invariant_id' => 'evidence_required',
                'organ' => $organ,
                'must_hold' => 'every promotion carries non-empty evidence_refs',
                'violation_effect' => 'park_for_evidence',
                'test_hint' => 'assert verified_evidence is non-empty before merge',
            ];
            $emitted[] = [
                'invariant_id' => 'evidence_contract_presence',
                'organ' => $organ,
                'must_hold' => 'evidence contract is declared and non-empty in the organ spec',
                'violation_effect' => 'park_for_evidence',
                'test_hint' => 'assert evidence_contract key is present and non-empty in contract',
            ];
        }
        if (array_key_exists('allows_self_certification', $contract)) {
            if ((bool) $contract['allows_self_certification'] === true) {
                return $this->envelope([], ['self_certification_forbidden']);
            }
            $emitted[] = [
                'invariant_id' => 'no_self_certification',
                'organ' => $organ,
                'must_hold' => 'an organ may not certify its own output',
                'violation_effect' => 'block_promotion',
                'test_hint' => 'assert verifier_organ != producer_organ',
            ];
        }

        $extras = is_array($contract['extra_invariants'] ?? null) ? array_values($contract['extra_invariants']) : [];
        $emptyMustHold = [];
        foreach ($extras as $extra) {
            if (! is_array($extra) || ! isset($extra['invariant_id'])) {
                continue;
            }
            $mustHold = (string) ($extra['must_hold'] ?? '');
            if ($mustHold === '') {
                $emptyMustHold[] = (string) $extra['invariant_id'];

                continue;
            }
            $emitted[] = [
                'invariant_id' => (string) $extra['invariant_id'],
                'organ' => (string) ($extra['organ'] ?? $organ),
                'must_hold' => $mustHold,
                'violation_effect' => (string) ($extra['violation_effect'] ?? 'block_promotion'),
                'test_hint' => (string) ($extra['test_hint'] ?? ''),
            ];
        }
        if ($emptyMustHold !== []) {
            return $this->envelope([], array_map(static fn (string $id): string => 'empty_must_hold:'.$id, $emptyMustHold));
        }

        // Duplicate detection BEFORE ordering.
        $seen = [];
        $duplicates = [];
        foreach ($emitted as $inv) {
            $id = (string) $inv['invariant_id'];
            if (isset($seen[$id])) {
                $duplicates[] = $id;
            }
            $seen[$id] = true;
        }
        if ($duplicates !== []) {
            return $this->envelope([], ['duplicate_invariant_ids:'.implode(',', array_values(array_unique($duplicates)))]);
        }

        // Deterministic order by invariant_id ASC.
        usort($emitted, static fn (array $a, array $b): int => strcmp((string) $a['invariant_id'], (string) $b['invariant_id']));

        return $this->envelope($emitted, []);
    }

    /**
     * @param  list<array<string,mixed>>  $invariants
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function envelope(array $invariants, array $blockers): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'invariants' => $invariants,
            'blockers' => array_values($blockers),
        ];
    }
}
