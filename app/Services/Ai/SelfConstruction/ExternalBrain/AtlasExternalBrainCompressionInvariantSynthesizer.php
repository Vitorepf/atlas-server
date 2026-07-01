<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Proof-backed invariant generator: turns a planned compression action into the exact set of
 * behavior invariants a muscle must prove before executing it. High-level compression intent
 * ("delete this organ", "merge these two") is never enough on its own — this synthesizer
 * converts each recognized action into precise proof obligations. An action outside the known
 * contract set — an unknown action, a typo, or a destructive action this synthesizer has no
 * invariant contract for — is never silently approved; it emits hold_invariants instead of
 * fabricating a permissive default.
 *
 * Input contract:
 *   action: string  ('delete'|'merge'|'collapse'|'boundary_tightening')
 *
 * KNOWN ACTION → REQUIRED INVARIANTS (fixed contract, only-adds — never shrinks silently):
 *   delete               → zero_active_consumers, behavior_lock_proof, rollback_evidence
 *   merge                → io_contract_equivalence, behavior_lock_proof, no_duplicate_side_effects
 *   collapse             → call_path_preserved, behavior_lock_proof
 *   boundary_tightening  → no_external_caller_broken, behavior_lock_proof
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainCompressionInvariantSynthesizer
{
    public const SCHEMA = 'atlas.external_brain.compression_invariant_synthesizer.v1';

    public const DECISION_INVARIANTS_REQUIRED = 'invariants_required';
    public const DECISION_HOLD_INVARIANTS     = 'hold_invariants';

    public const ACTION_DELETE              = 'delete';
    public const ACTION_MERGE               = 'merge';
    public const ACTION_COLLAPSE            = 'collapse';
    public const ACTION_BOUNDARY_TIGHTENING = 'boundary_tightening';

    /** @var array<string,list<string>> */
    private const REQUIRED_INVARIANTS_BY_ACTION = [
        self::ACTION_DELETE => [
            'zero_active_consumers',
            'behavior_lock_proof',
            'rollback_evidence',
        ],
        self::ACTION_MERGE => [
            'io_contract_equivalence',
            'behavior_lock_proof',
            'no_duplicate_side_effects',
        ],
        self::ACTION_COLLAPSE => [
            'call_path_preserved',
            'behavior_lock_proof',
        ],
        self::ACTION_BOUNDARY_TIGHTENING => [
            'no_external_caller_broken',
            'behavior_lock_proof',
        ],
    ];

    /**
     * @param  array{action?: string}  $facts
     * @return array{schema:string, decision:string, action:string, required_invariants:list<string>}
     */
    public function synthesize(array $facts): array
    {
        $action = trim((string) ($facts['action'] ?? ''));

        if (! array_key_exists($action, self::REQUIRED_INVARIANTS_BY_ACTION)) {
            return [
                'schema'                => self::SCHEMA,
                'decision'              => self::DECISION_HOLD_INVARIANTS,
                'action'                => $action,
                'required_invariants'   => [],
            ];
        }

        return [
            'schema'                => self::SCHEMA,
            'decision'              => self::DECISION_INVARIANTS_REQUIRED,
            'action'                => $action,
            'required_invariants'   => self::REQUIRED_INVARIANTS_BY_ACTION[$action],
        ];
    }
}
