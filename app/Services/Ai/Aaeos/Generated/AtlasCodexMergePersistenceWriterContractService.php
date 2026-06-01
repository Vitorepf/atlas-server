<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Codex Merge Post-Execution Action Persistence Writer Contract — pure,
 * deterministic, read-only contract-template logic.
 *
 * Governs the read-only contract template for a FUTURE append-only writer that
 * may persist signed post-execution Codex merge action receipts. The whole
 * point of this surface is to answer one question — "what exact contract must a
 * future writer satisfy before it can be implemented?" — while itself
 * implementing no writer, writing no ledger event, persisting no receipt,
 * accepting/validating no signature, recording no decision, approving nothing,
 * merging nothing and dispatching nothing.
 *
 * Contract (from the doc "Boundary" / "Required Capabilities" /
 * "Required Pre-Write Checks" / "Forbidden Implementation Content" sections):
 *
 *   Boundary (the template, always):
 *     execution_allowed=false, ledger_write_allowed=false,
 *     dispatch_allowed=false, approval_granted=false, merge_allowed=false,
 *     signature_valid=false, receipt_persisted=false, receipt_signed=false.
 *
 *   Required Capabilities -> requiredCapabilities()
 *     the seven behaviours a future writer must implement (append-only
 *     write-only, payload hash recomputation, source hash match enforcement,
 *     hot scope recheck enforcement, human confirmation hash enforcement,
 *     no merge authority, no dispatch authority). The template only declares
 *     them; it performs none.
 *
 *   Required Pre-Write Checks -> preWriteChecks()
 *     the ten proofs a future writer must furnish before any write. The
 *     template computes, fail-closed, which proofs are satisfied and which
 *     block; `writer_may_be_implemented` becomes true ONLY when all ten are
 *     proven AND no boundary key was flipped.
 *
 *   Forbidden Implementation Content -> forbiddenImplementationContent()
 *     the eight things a future writer implementation must never include.
 *
 * Documented invariants this code enforces (not just documents):
 *   - "must keep ... =false" boundary => boundary() returns all-false and the
 *     contract embeds it verbatim; assertBoundaryHeld() proves it never flipped.
 *   - "A future writer must implement:" (7 items) => requiredCapabilities()
 *     returns exactly those seven, closed-set.
 *   - "A future writer must prove:" (10 items) => preWriteChecks() maps each
 *     documented proof to a real predicate over the input; the moment any is
 *     unproven the proof is marked unmet and writer_may_be_implemented=false.
 *   - "The writer implementation must not include:" (8 items) =>
 *     forbiddenImplementationContent() returns exactly those eight, and
 *     contract() flags any of them found declared in a candidate implementation
 *     as a violation that blocks implementation.
 *   - "writer contract must be hash-bound to the writer preflight before any
 *     future implementation can be considered" => the preflight-ready proof and
 *     the contract-hash-bound proof are both mandatory pre-write checks.
 *
 * Non-goals honoured (read-only): it does NOT implement a writer, does NOT
 * write ledger events, does NOT persist receipts, does NOT accept signatures,
 * does NOT validate signatures, does NOT record decisions, does NOT approve
 * code, does NOT merge and does NOT dispatch work.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-contract.md
 */
final class AtlasCodexMergePersistenceWriterContractService
{
    /** Stable evidence schema id this read-only surface emits. */
    public const SCHEMA_WRITER_CONTRACT = 'atlas.self_construction_codex_review_merge_post_execution_action_persistence_writer_contract.v1';

    /** Surface label (closed set of one). */
    public const SURFACE_WRITER_CONTRACT = 'persistence_writer_contract_template';

    /**
     * The eight boundary keys (doc "Boundary"): the template must keep all false.
     *
     * @var list<string>
     */
    public const BOUNDARY_KEYS = [
        'execution_allowed',
        'ledger_write_allowed',
        'dispatch_allowed',
        'approval_granted',
        'merge_allowed',
        'signature_valid',
        'receipt_persisted',
        'receipt_signed',
    ];

    /**
     * Required capabilities a FUTURE writer must implement (doc "Required
     * Capabilities"). Order preserved. The template performs none of these.
     *
     * @var list<string>
     */
    public const REQUIRED_CAPABILITIES = [
        'append_only_ledger_write_only_behavior',
        'payload_hash_recomputation',
        'source_hash_match_enforcement',
        'hot_scope_recheck_enforcement',
        'human_confirmation_hash_enforcement',
        'no_merge_authority',
        'no_dispatch_authority',
    ];

    /**
     * Required pre-write checks a FUTURE writer must prove (doc "Required
     * Pre-Write Checks"). Each maps to a boolean signal in the input; absence is
     * treated as unproven (fail-closed). Order preserved.
     *
     * @var list<string>
     */
    public const PRE_WRITE_CHECKS = [
        'writer_preflight_ready',
        'writer_contract_hash_bound_to_implementation',
        'writer_capabilities_verified',
        'all_payload_fields_non_null',
        'payload_hash_recomputed_by_writer',
        'source_hash_match_enforced',
        'hot_scope_recheck_enforced',
        'human_confirmation_hash_enforced',
        'merge_authority_absent',
        'dispatch_authority_absent',
    ];

    /**
     * Forbidden implementation content (doc "Forbidden Implementation
     * Content"). A future writer implementation must include NONE of these.
     * Order preserved.
     *
     * @var list<string>
     */
    public const FORBIDDEN_IMPLEMENTATION_CONTENT = [
        'merge_execution',
        'dispatch_execution',
        'signature_acceptance',
        'signature_validation',
        'approval_recording',
        'decision_recording',
        'packet_claiming',
        'packet_completion',
    ];

    /**
     * The eight documented boundary keys, all forced false.
     *
     * @return array<string,false>
     */
    public function boundary(): array
    {
        $out = [];
        foreach (self::BOUNDARY_KEYS as $key) {
            $out[$key] = false;
        }

        return $out;
    }

    /**
     * Required-capabilities surface. Declares the seven behaviours a future
     * writer must implement. Implements none of them.
     *
     * @return array{
     *   surface:string, schema:string,
     *   required_capabilities:list<string>, count:int,
     *   boundary:array<string,false>, implements_writer:false
     * }
     */
    public function requiredCapabilities(): array
    {
        return [
            'surface' => self::SURFACE_WRITER_CONTRACT,
            'schema' => self::SCHEMA_WRITER_CONTRACT,
            'required_capabilities' => self::REQUIRED_CAPABILITIES,
            'count' => count(self::REQUIRED_CAPABILITIES),
            'boundary' => $this->boundary(),
            'implements_writer' => false,
        ];
    }

    /**
     * Forbidden-implementation-content surface. Declares the eight things a
     * future writer implementation must never include, and (when handed a
     * candidate's declared content) lists which forbidden items it declares.
     *
     * @param list<string> $declaredContent items a candidate writer claims to
     *   implement; any that intersect the forbidden set are flagged.
     * @return array{
     *   surface:string, schema:string,
     *   forbidden_content:list<string>,
     *   declared_forbidden:list<string>, clean:bool,
     *   boundary:array<string,false>
     * }
     */
    public function forbiddenImplementationContent(array $declaredContent = []): array
    {
        $declaredForbidden = array_values(array_filter(
            self::FORBIDDEN_IMPLEMENTATION_CONTENT,
            static fn (string $item): bool => in_array($item, $declaredContent, true),
        ));

        return [
            'surface' => self::SURFACE_WRITER_CONTRACT,
            'schema' => self::SCHEMA_WRITER_CONTRACT,
            'forbidden_content' => self::FORBIDDEN_IMPLEMENTATION_CONTENT,
            'declared_forbidden' => $declaredForbidden,
            'clean' => $declaredForbidden === [],
            'boundary' => $this->boundary(),
        ];
    }

    /**
     * Required-pre-write-checks surface.
     *
     * Computes, fail-closed, which of the ten documented proofs the candidate
     * writer has furnished. `writer_may_be_implemented` is true ONLY when every
     * documented proof is met (and nothing here flips the boundary). Proves
     * nothing about an actual writer — there is none.
     *
     * @param array<string,mixed> $input boolean proof signals keyed by
     *   PRE_WRITE_CHECKS entries. Missing/non-true => unproven.
     * @return array{
     *   surface:string, schema:string,
     *   checks:array<string,bool>, unmet_checks:list<string>,
     *   writer_may_be_implemented:bool,
     *   boundary:array<string,false>, implements_writer:false
     * }
     */
    public function preWriteChecks(array $input): array
    {
        $checks = [];
        $unmet = [];
        foreach (self::PRE_WRITE_CHECKS as $check) {
            $met = $this->signal($input, $check);
            $checks[$check] = $met;
            if (! $met) {
                $unmet[] = $check;
            }
        }

        return [
            'surface' => self::SURFACE_WRITER_CONTRACT,
            'schema' => self::SCHEMA_WRITER_CONTRACT,
            'checks' => $checks,
            'unmet_checks' => $unmet,
            // Implementation can be *considered* only when every proof is met.
            'writer_may_be_implemented' => $unmet === [],
            'boundary' => $this->boundary(),
            'implements_writer' => false,
        ];
    }

    /**
     * Primary entrypoint: assemble the full writer contract template —
     * capabilities + pre-write checks + forbidden content — and decide whether a
     * future writer implementation may even be considered.
     *
     * `implementation_may_be_considered` is true ONLY when every pre-write proof
     * is met AND the candidate declares no forbidden content AND the boundary
     * held across every surface. By the doc's own closing paragraph, this is a
     * gate on *consideration*, never an authorization to implement or invoke.
     *
     * @param array{
     *   pre_write_proofs?:array<string,mixed>,
     *   declared_content?:list<string>
     * } $input
     * @return array{
     *   schema:string, surface:string,
     *   required_capabilities:array<string,mixed>,
     *   pre_write_checks:array<string,mixed>,
     *   forbidden_implementation_content:array<string,mixed>,
     *   boundary_held:bool, boundary_violations:list<string>,
     *   implementation_may_be_considered:bool,
     *   blocked_reasons:list<string>
     * }
     */
    public function contract(array $input = []): array
    {
        $capabilities = $this->requiredCapabilities();
        $checks = $this->preWriteChecks($input['pre_write_proofs'] ?? []);
        $forbidden = $this->forbiddenImplementationContent($input['declared_content'] ?? []);

        $violations = $this->assertBoundaryHeld([$capabilities, $checks, $forbidden]);

        $blocked = [];
        foreach ($checks['unmet_checks'] as $unmet) {
            $blocked[] = 'pre_write_check_unmet:'.$unmet;
        }
        foreach ($forbidden['declared_forbidden'] as $item) {
            $blocked[] = 'forbidden_content_declared:'.$item;
        }
        foreach ($violations as $violation) {
            $blocked[] = 'boundary_violation:'.$violation;
        }

        return [
            'schema' => self::SCHEMA_WRITER_CONTRACT,
            'surface' => self::SURFACE_WRITER_CONTRACT,
            'required_capabilities' => $capabilities,
            'pre_write_checks' => $checks,
            'forbidden_implementation_content' => $forbidden,
            'boundary_held' => $violations === [],
            'boundary_violations' => $violations,
            'implementation_may_be_considered' => $blocked === [],
            'blocked_reasons' => $blocked,
        ];
    }

    /**
     * Prove that no surface ever flipped a boundary key to a truthy value.
     * Returns the list of "surface.key" violations (empty = boundary intact).
     *
     * @param list<array<string,mixed>> $surfaces
     * @return list<string>
     */
    public function assertBoundaryHeld(array $surfaces): array
    {
        $violations = [];
        foreach ($surfaces as $surface) {
            $label = is_string($surface['surface'] ?? null) ? $surface['surface'] : 'unknown';
            $boundary = is_array($surface['boundary'] ?? null) ? $surface['boundary'] : [];
            foreach (self::BOUNDARY_KEYS as $key) {
                // Missing key OR truthy value both count as a breach.
                if (! array_key_exists($key, $boundary) || $boundary[$key] !== false) {
                    $violations[] = $label.'.'.$key;
                }
            }
        }

        return $violations;
    }

    /**
     * Read a boolean proof signal. Strict: only an exact boolean true clears a
     * proof; missing keys and any non-true value count as unproven (fail-closed).
     *
     * @param array<string,mixed> $input
     */
    private function signal(array $input, string $key): bool
    {
        return ($input[$key] ?? null) === true;
    }
}
