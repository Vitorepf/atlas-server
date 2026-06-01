<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Self-Construction Durable Reservation Implementation Preflight Contract —
 * pure, deterministic, READ-ONLY surface.
 *
 * This is the FINAL read-only check that aggregates the whole durable-reservation
 * design (schema, repository, collision guard, lease lifecycle, readiness
 * projection) into one preflight packet that ANY future implementation must pass
 * BEFORE it is allowed to create migrations, repository code, storage writes or
 * claim persistence. It is the aggregating sibling of the per-stage post-approval
 * preflight ({@see AtlasDurableReservationPostApprovalPreflightService}):
 *
 *   - the post-approval preflight asks "the human signed — is it STILL safe?";
 *   - this contract asks "are ALL SEVEN design contracts present, traceable and
 *     unchanged, and are ALL SEVEN gates green, with dispatch still disabled?".
 *
 * Per the doc, "Preflight generation is read-only and cannot approve or execute
 * implementation." So every result keeps `implementation_allowed=false` ALWAYS,
 * and a clean run only flips a `preflight_packet_emitted` clearance flag — the
 * non-execution guarantee (migrations / storage / claims / dispatch) stays false
 * unconditionally, because emitting a packet is not the act of executing.
 *
 * Documented rules this code ENFORCES (not merely documents):
 *   - "Required Contract Hashes" => preflight() requires ALL SEVEN documented
 *     hashes to be present (non-empty) AND not drifted since approval. A missing
 *     hash fires `contract_hash_missing`; a drifted hash fires
 *     `contract_hash_drifted` (both are "Blocking Conditions").
 *   - "Required Gates" => evaluateGates() checks ALL SEVEN documented gates
 *     (self-construction tests, traceability, docs-health, architecture-validate,
 *     diff check, hot-scope absence, dispatch disabled). A gate passes only by an
 *     exact proven `true` signal; missing/null/wrong-typed is fail-closed.
 *   - "Blocking Conditions" => evaluateBlockers() maps each of the seven
 *     documented conditions to a missing hash / failed gate / requested write or
 *     dispatch and lists every one that fires. If ANY fires, status is `blocked`
 *     and `preflight_packet_emitted=false`.
 *   - "Completion Criteria" => a clean run emits a deterministic read-only packet
 *     carrying hash presence, gate results, blockers and the non-execution
 *     guarantee, and assertGuaranteeHeld() proves no result ever flipped a
 *     guarantee key.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-implementation-preflight-contract.md
 */
final class AtlasDurableReservationImplementationPreflightContractService
{
    /** Stable evidence schema id this read-only surface emits. */
    public const SCHEMA = 'atlas.self_construction_durable_reservation_implementation_preflight_contract.v1';

    /** Surface label (closed set). */
    public const SURFACE = 'durable_reservation_implementation_preflight_contract';

    /** Status when every hash is present+unchanged and every gate is green. */
    public const STATUS_PREFLIGHT_PACKET_EMITTED = 'preflight_packet_emitted';

    /** Status when any hash is missing/drifted or any gate fails / write asked. */
    public const STATUS_BLOCKED = 'blocked';

    /**
     * Doc "Required Contract Hashes" — the seven hashes that must ALL be present
     * (non-empty) AND traceable (unchanged since approval) before implementation
     * may start. Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const REQUIRED_CONTRACT_HASHES = [
        'post_approval_preflight_hash',
        'implementation_packet_hash',
        'storage_schema_hash',
        'repository_contract_hash',
        'collision_guard_hash',
        'lease_lifecycle_hash',
        'readiness_projection_hash',
    ];

    /**
     * Doc "Required Gates" — the seven gates, in documented order. Key = gate
     * token (reported with pass/fail); value = the exact input signal that, when
     * proven true, satisfies that gate. Absence is fail-closed.
     *
     * @var array<string,string>
     */
    public const REQUIRED_GATES = [
        'self_construction_focused_tests_pass' => 'self_construction_focused_tests_pass',
        'traceability_audit_clean' => 'traceability_audit_clean',
        'docs_health_clean' => 'docs_health_clean',
        'architecture_validate_clean' => 'architecture_validate_clean',
        'diff_check_clean' => 'diff_check_clean',
        'hot_voice_kernel_scopes_absent_from_approved_files' => 'no_hot_voice_kernel_scopes_in_approved_files',
        'dispatch_remains_disabled' => 'dispatch_disabled',
    ];

    /**
     * Doc "Blocking Conditions" — the seven conditions that block implementation.
     * Key = blocker token (emitted when it fires); value = the proven-safe signal
     * that clears it. Each maps to a missing/drifted hash, a failed gate or a
     * forbidden request; absence is fail-closed. Order preserved as documented.
     *
     * @var array<string,string>
     */
    public const BLOCKING_CONDITIONS = [
        'contract_hash_missing' => 'all_contract_hashes_present',
        'contract_hash_drifted' => 'no_contract_hash_drifted',
        'traceability_not_clean' => 'traceability_audit_clean',
        'docs_health_or_architecture_validate_failed' => 'docs_health_and_architecture_validate_clean',
        'migration_scope_broader_than_approved' => 'migration_scope_within_approved',
        'storage_writes_requested_before_approval' => 'no_storage_writes_requested',
        'dispatch_requested' => 'dispatch_disabled',
    ];

    /**
     * Non-execution guarantee keys (doc: "Final preflight must keep migrations,
     * storage writes, claim persistence and dispatch disabled" / "Preflight
     * generation is read-only and cannot approve or execute implementation").
     * Every result keeps all of these false — emitting a packet is never the act
     * of executing.
     *
     * @var list<string>
     */
    public const GUARANTEE_KEYS = [
        'migrations_created',
        'storage_writes_performed',
        'claim_persistence_performed',
        'dispatch_enabled',
    ];

    /**
     * The four documented non-execution guarantee keys, all forced false.
     *
     * @return array<string,false>
     */
    public function guarantee(): array
    {
        $out = [];
        foreach (self::GUARANTEE_KEYS as $key) {
            $out[$key] = false;
        }

        return $out;
    }

    /**
     * Evaluate per-hash presence + drift from $state['contract_hashes'].
     *
     * For each of the seven documented hashes returns:
     *   - present: the hash key exists with a non-empty string value;
     *   - drifted: the matching "<hash>_matches" signal is NOT proven true while
     *     the hash IS present (an absent or unverified hash counts as drifted so
     *     it can never silently pass as "unchanged").
     *
     * @param array<string,mixed> $state recognised: `contract_hashes` (map of
     *   hash key => string), plus per-hash "<hash>_matches" boolean signals.
     * @return array<string,array{present:bool,drifted:bool}>
     */
    public function evaluateHashes(array $state = []): array
    {
        $hashes = isset($state['contract_hashes']) && is_array($state['contract_hashes'])
            ? $state['contract_hashes']
            : [];

        $out = [];
        foreach (self::REQUIRED_CONTRACT_HASHES as $hash) {
            $value = $hashes[$hash] ?? null;
            $present = is_string($value) && $value !== '';
            // A hash is "unchanged" only if present AND its match signal proven.
            $unchanged = $present && $this->proven($state, $hash.'_matches');

            $out[$hash] = [
                'present' => $present,
                'drifted' => ! $unchanged,
            ];
        }

        return $out;
    }

    /**
     * Evaluate all seven documented Required Gates against the signed state.
     *
     * A gate passes only when its signal is proven with an exact boolean true
     * (fail-closed). Returns an ordered map of gate token => bool.
     *
     * @param array<string,mixed> $state recognised signals are the values of
     *   self::REQUIRED_GATES (e.g. traceability_audit_clean=true).
     * @return array<string,bool> gate token => passed
     */
    public function evaluateGates(array $state = []): array
    {
        $out = [];
        foreach (self::REQUIRED_GATES as $gate => $signal) {
            $out[$gate] = $this->proven($state, $signal);
        }

        return $out;
    }

    /**
     * Evaluate the seven documented Blocking Conditions against the state.
     *
     * A blocker fires UNLESS its clearing signal is proven true (fail-closed).
     * Four derived signals are computed from the hash table and finer inputs:
     *   - `all_contract_hashes_present`: every one of the seven hashes present;
     *   - `no_contract_hash_drifted`: no hash drifted (each present + matched);
     *   - `docs_health_and_architecture_validate_clean`: both gates green;
     *   - storage/scope/dispatch use direct proven signals.
     * Returns the ordered list of fired blocker tokens (empty = clean).
     *
     * @param array<string,mixed> $state
     * @return list<string> fired blocker tokens
     */
    public function evaluateBlockers(array $state = []): array
    {
        $hashes = $this->evaluateHashes($state);

        $allPresent = true;
        $noneDrifted = true;
        foreach ($hashes as $row) {
            if (! $row['present']) {
                $allPresent = false;
            }
            if ($row['drifted']) {
                $noneDrifted = false;
            }
        }

        $derived = $state;
        $derived['all_contract_hashes_present'] = $allPresent;
        $derived['no_contract_hash_drifted'] = $noneDrifted;
        $derived['docs_health_and_architecture_validate_clean'] =
            $this->proven($state, 'docs_health_clean') && $this->proven($state, 'architecture_validate_clean');

        $fired = [];
        foreach (self::BLOCKING_CONDITIONS as $blocker => $clearingSignal) {
            if (! $this->proven($derived, $clearingSignal)) {
                $fired[] = $blocker;
            }
        }

        return $fired;
    }

    /**
     * Primary surface: the read-only IMPLEMENTATION PREFLIGHT CONTRACT packet.
     *
     * Aggregates the seven contract hashes (presence + drift), runs the seven
     * Required Gates and the seven Blocking Conditions. The packet is emitted as
     * clean ONLY when every hash is present and unchanged, every gate passes and
     * no blocker fires; otherwise status is `blocked` and
     * `preflight_packet_emitted=false`. Either way the non-execution guarantee
     * holds — nothing is migrated, written, persisted or dispatched by producing
     * this packet.
     *
     * @param array<string,mixed> $state hash table + gate / blocker signals (see
     *   evaluateHashes / evaluateGates / evaluateBlockers).
     * @return array{
     *   surface:string, schema:string, status:string,
     *   required_contract_hashes:list<string>,
     *   required_gates:list<string>,
     *   blocking_conditions:list<string>,
     *   contract_hashes:array<string,array{present:bool,drifted:bool}>,
     *   contract_hashes_all_present:bool,
     *   contract_hashes_none_drifted:bool,
     *   gates:array<string,bool>,
     *   gates_all_passed:bool,
     *   active_blockers:list<string>,
     *   preflight_packet_emitted:bool,
     *   guarantee:array<string,false>,
     *   implementation_allowed:false, is_execution:false
     * }
     */
    public function preflight(array $state = []): array
    {
        $hashes = $this->evaluateHashes($state);

        $allPresent = true;
        $noneDrifted = true;
        foreach ($hashes as $row) {
            if (! $row['present']) {
                $allPresent = false;
            }
            if ($row['drifted']) {
                $noneDrifted = false;
            }
        }

        $gates = $this->evaluateGates($state);
        $gatesAllPassed = ! in_array(false, $gates, true);

        $activeBlockers = $this->evaluateBlockers($state);
        $noBlockers = $activeBlockers === [];

        // The packet is emitted clean only when EVERYTHING holds: all hashes
        // present, none drifted, all gates green and no blocker firing.
        $emitted = $allPresent && $noneDrifted && $gatesAllPassed && $noBlockers;

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'status' => $emitted ? self::STATUS_PREFLIGHT_PACKET_EMITTED : self::STATUS_BLOCKED,
            'required_contract_hashes' => self::REQUIRED_CONTRACT_HASHES,
            'required_gates' => array_keys(self::REQUIRED_GATES),
            'blocking_conditions' => array_keys(self::BLOCKING_CONDITIONS),
            'contract_hashes' => $hashes,
            'contract_hashes_all_present' => $allPresent,
            'contract_hashes_none_drifted' => $noneDrifted,
            'gates' => $gates,
            'gates_all_passed' => $gatesAllPassed,
            'active_blockers' => $activeBlockers,
            // "Emitted" means the read-only packet is clean, never "executed".
            'preflight_packet_emitted' => $emitted,
            'guarantee' => $this->guarantee(),
            // Restated per the doc's hard non-execution guarantee.
            'implementation_allowed' => false,
            'is_execution' => false,
        ];
    }

    /**
     * Composite entrypoint: build the preflight packet and prove the
     * non-execution guarantee held across it.
     *
     * @param array<string,mixed> $state forwarded to preflight()
     * @return array{
     *   schema:string,
     *   preflight:array<string,mixed>,
     *   guarantee_held:bool, guarantee_violations:list<string>
     * }
     */
    public function evaluate(array $state = []): array
    {
        $packet = $this->preflight($state);
        $violations = $this->assertGuaranteeHeld([$packet]);

        return [
            'schema' => self::SCHEMA,
            'preflight' => $packet,
            'guarantee_held' => $violations === [],
            'guarantee_violations' => $violations,
        ];
    }

    /**
     * Prove that no result ever flipped a non-execution guarantee key (or the two
     * restated guarantees) to a truthy value. Returns "surface.key" violations
     * (empty = intact).
     *
     * @param list<array<string,mixed>> $results
     * @return list<string>
     */
    public function assertGuaranteeHeld(array $results): array
    {
        $violations = [];
        foreach ($results as $result) {
            $label = is_string($result['surface'] ?? null) ? $result['surface'] : 'unknown';

            $guarantee = is_array($result['guarantee'] ?? null) ? $result['guarantee'] : [];
            foreach (self::GUARANTEE_KEYS as $key) {
                // Missing key OR truthy value both count as a breach.
                if (! array_key_exists($key, $guarantee) || $guarantee[$key] !== false) {
                    $violations[] = $label.'.'.$key;
                }
            }

            // The packet restates two guarantees outside the guarantee array.
            foreach (['implementation_allowed', 'is_execution'] as $extra) {
                if (array_key_exists($extra, $result) && $result[$extra] !== false) {
                    $violations[] = $label.'.'.$extra;
                }
            }
        }

        return $violations;
    }

    /**
     * Read a signal. Only an exact boolean true clears a gate/blocker; anything
     * else (missing key, null, truthy-string, 1) is fail-closed. This makes the
     * final preflight impossible to trip accidentally.
     *
     * @param array<string,mixed> $state
     */
    private function proven(array $state, string $key): bool
    {
        if (! array_key_exists($key, $state)) {
            return false;
        }

        return $state[$key] === true;
    }
}
