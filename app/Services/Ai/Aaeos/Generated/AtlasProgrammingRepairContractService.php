<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas AI Programming Repair Contract — pre-attempt admissibility gate.
 *
 * Pure, deterministic implementation of the "Target Use" contract for the
 * Programming / Dev / Forge / Fix / worker Repair Loop. Given a proposed repair
 * request it answers a single question: is this request contract-compliant and
 * therefore allowed to reach `AtlasRepairOrchestrator::plan()` — or must it be
 * rejected (missing identity / missing evidence) or routed to a human first?
 *
 * Contract obligations enforced verbatim from the doc "Target Use":
 *   1. classify the operational failure as a `FailureClassification`
 *      (a closed `failure_domain` must be present);
 *   2. preserve `envelope_id` and `receipt_id` (both required, non-empty);
 *   3. fill `evidence_refs` with ledger events, harness runs, diffs, logs,
 *      screenshots or test output (an accepted evidence kind must be present);
 *   4. call `AtlasRepairOrchestrator::plan()` before attempts — this gate is the
 *      precondition: it returns `ready_to_plan` only when 1-3 hold;
 *   5. block heavy repair without evidence — a heavy strategy with no evidence
 *      is rejected;
 *   6. require human review for policy, privacy, security, compliance, unknown
 *      and terminal states (those domains never auto-plan).
 *
 * This service NEVER executes a command, calls a provider, mutates a codebase,
 * queues a job or touches the database. It only emits the contract verdict plus
 * an auditable receipt; the caller decides whether to invoke the orchestrator,
 * request review or reject.
 *
 * It is the complement of the escalation decider (which picks the action for an
 * already-observed failure): this one validates the *request shape* up front.
 *
 * @see docs/engineering-knowledge-base/domains/programming-repair-contract.md
 */
final class AtlasProgrammingRepairContractService
{
    /** Stable receipt schema id for the verdict this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.programming.repair_contract.v1';

    /** Verdicts (closed set). */
    public const VERDICT_READY = 'ready_to_plan';
    public const VERDICT_NEEDS_HUMAN_REVIEW = 'needs_human_review';
    public const VERDICT_REJECTED = 'rejected';

    /**
     * Identity fields the contract says must be preserved end-to-end so a repair
     * can be tied back to its originating operation and decision.
     *
     * @var list<string>
     */
    public const REQUIRED_IDENTITY = ['envelope_id', 'receipt_id'];

    /**
     * Accepted evidence kinds (doc "Target Use" / "Evidencias"): an `evidence_ref`
     * is only admissible when its `kind` is one of these.
     *
     * @var list<string>
     */
    public const ACCEPTED_EVIDENCE_KINDS = [
        'ledger_event',
        'harness_run',
        'diff',
        'log',
        'screenshot',
        'test_output',
    ];

    /**
     * Failure domains the contract reserves for a human: "require human review
     * for policy, privacy, security, compliance, unknown and terminal states".
     * Values are the canonical FailureDomain strings (failure-domain-taxonomy).
     *
     * @var list<string>
     */
    public const HUMAN_REVIEW_DOMAINS = [
        'policy.denied',
        'tool.policy_denied',
        'privacy.violation',
        'security.finding',
        'compliance.violation',
        'unknown',
    ];

    /**
     * Terminal states from which no repair may be auto-planned — they too go to
     * a human ("... and terminal states"). `repair.exhausted` is the canonical
     * one; `replay.mismatch` is a hard non-recoverable divergence.
     *
     * @var list<string>
     */
    public const TERMINAL_DOMAINS = [
        'repair.exhausted',
        'replay.mismatch',
    ];

    /**
     * Strategies that count as "heavy repair" (re-run cost), mirroring the
     * Kernel RepairPolicy default heavy set. A heavy strategy with no evidence
     * is blocked ("block heavy repair without evidence").
     *
     * @var list<string>
     */
    public const HEAVY_STRATEGIES = [
        'rerun_tool',
        'rerun_harness',
    ];

    /**
     * Evaluate one proposed repair request against the contract.
     *
     * @param array<string,mixed> $request
     *        envelope_id    : string  originating operation envelope id
     *        receipt_id     : string  originating decision receipt id
     *        failure_domain : string  closed FailureDomain value (classification)
     *        evidence_refs  : list<array{kind?:string,ref?:string}|string>
     *        strategy       : string  proposed repair strategy (default none)
     *
     * @return array<string,mixed> the contract verdict + auditable receipt
     */
    public function evaluate(array $request): array
    {
        $envelopeId = $this->str($request['envelope_id'] ?? null);
        $receiptId = $this->str($request['receipt_id'] ?? null);
        $domain = $this->domain($request['failure_domain'] ?? null);
        $strategy = $this->str($request['strategy'] ?? null) ?? 'none';

        [$evidenceRefs, $acceptedEvidence] = $this->evidence($request['evidence_refs'] ?? []);
        $hasEvidence = $acceptedEvidence !== [];

        $missingIdentity = [];
        if ($envelopeId === null) {
            $missingIdentity[] = 'envelope_id';
        }
        if ($receiptId === null) {
            $missingIdentity[] = 'receipt_id';
        }

        $isHeavy = in_array($strategy, self::HEAVY_STRATEGIES, true);
        $requiresHuman = $this->requiresHumanReview($domain);
        $isTerminal = $domain !== null && in_array($domain, self::TERMINAL_DOMAINS, true);

        $reasons = [];
        $violations = [];

        // Obligation 1 — failure must be classified (closed domain present).
        if ($domain === null) {
            $violations[] = 'failure_not_classified';
        }

        // Obligation 2 — preserve envelope_id and receipt_id.
        foreach ($missingIdentity as $field) {
            $violations[] = "missing_identity:{$field}";
        }

        // Obligation 3 — evidence_refs must hold at least one accepted kind.
        if (! $hasEvidence) {
            $violations[] = 'evidence_refs_empty';
        }

        // Obligation 5 — heavy repair without evidence is blocked. (Captured by
        // the empty-evidence violation above, but recorded explicitly so the
        // receipt names the heavy-repair rule that fired.)
        if ($isHeavy && ! $hasEvidence) {
            $violations[] = 'heavy_repair_without_evidence';
        }

        // Verdict resolution (priority order):
        // rejected (hard contract breach) > needs_human_review > ready_to_plan.
        if ($violations !== []) {
            $verdict = self::VERDICT_REJECTED;
            $reasons = $violations;
        } elseif ($requiresHuman) {
            $verdict = self::VERDICT_NEEDS_HUMAN_REVIEW;
            $reasons[] = $isTerminal
                ? "terminal_state_requires_human_review:{$domain}"
                : "domain_requires_human_review:{$domain}";
        } else {
            // Obligation 4 — contract satisfied: this request may call plan().
            $verdict = self::VERDICT_READY;
            $reasons[] = 'contract_satisfied_ready_to_plan';
        }

        $contractSatisfied = $verdict === self::VERDICT_READY;

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'verdict' => $verdict,
            'ready_to_plan' => $contractSatisfied,
            'may_call_orchestrator' => $contractSatisfied,
            'failure_classified' => $domain !== null,
            'failure_domain' => $domain,
            'identity_preserved' => $missingIdentity === [],
            'missing_identity' => $missingIdentity,
            'has_evidence' => $hasEvidence,
            'evidence_count' => count($evidenceRefs),
            'accepted_evidence_count' => count($acceptedEvidence),
            'strategy' => $strategy,
            'heavy_strategy' => $isHeavy,
            'requires_human_review' => $requiresHuman,
            'terminal_state' => $isTerminal,
            'violations' => $violations,
            'auditable' => true,
            'reasons' => $reasons,
        ];
    }

    /**
     * Convenience predicate used by the loop driver: is this request allowed to
     * reach `AtlasRepairOrchestrator::plan()` at all?
     *
     * @param array<string,mixed> $request
     */
    public function readyToPlan(array $request): bool
    {
        return $this->evaluate($request)['verdict'] === self::VERDICT_READY;
    }

    /**
     * Does this failure domain force a human into the loop before any plan?
     */
    public function requiresHumanReview(?string $domain): bool
    {
        if ($domain === null) {
            return false;
        }

        return in_array($domain, self::HUMAN_REVIEW_DOMAINS, true)
            || in_array($domain, self::TERMINAL_DOMAINS, true);
    }

    /**
     * Manifest of the contract this service enforces — emitted by the CLI with
     * no arguments so the obligations are inspectable without a request.
     *
     * @return array<string,mixed>
     */
    public function contractManifest(): array
    {
        return [
            'schema' => self::RECEIPT_SCHEMA,
            'source_doc' => 'docs/engineering-knowledge-base/domains/programming-repair-contract.md',
            'obligations' => [
                'classify_failure_classification',
                'preserve_envelope_id_and_receipt_id',
                'fill_evidence_refs',
                'call_orchestrator_plan_before_attempts',
                'block_heavy_repair_without_evidence',
                'human_review_for_policy_privacy_security_compliance_unknown_terminal',
            ],
            'required_identity' => self::REQUIRED_IDENTITY,
            'accepted_evidence_kinds' => self::ACCEPTED_EVIDENCE_KINDS,
            'human_review_domains' => self::HUMAN_REVIEW_DOMAINS,
            'terminal_domains' => self::TERMINAL_DOMAINS,
            'heavy_strategies' => self::HEAVY_STRATEGIES,
            'verdicts' => [
                self::VERDICT_READY,
                self::VERDICT_NEEDS_HUMAN_REVIEW,
                self::VERDICT_REJECTED,
            ],
        ];
    }

    private function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function domain(mixed $value): ?string
    {
        $domain = $this->str($value);

        return $domain === null ? null : strtolower($domain);
    }

    /**
     * Normalize evidence_refs into [all refs, accepted refs]. A ref may be a
     * string (treated as an opaque ref of unknown kind) or a map with kind+ref;
     * only refs whose kind is in ACCEPTED_EVIDENCE_KINDS count as accepted.
     *
     * @param mixed $value
     *
     * @return array{0:list<array{kind:?string,ref:?string}>,1:list<array{kind:string,ref:?string}>}
     */
    private function evidence(mixed $value): array
    {
        if (! is_array($value)) {
            return [[], []];
        }

        $all = [];
        $accepted = [];

        foreach ($value as $entry) {
            if (is_string($entry)) {
                $ref = $this->str($entry);
                if ($ref === null) {
                    continue;
                }
                $all[] = ['kind' => null, 'ref' => $ref];

                continue;
            }

            if (! is_array($entry)) {
                continue;
            }

            $kind = $this->str($entry['kind'] ?? null);
            $kind = $kind === null ? null : strtolower($kind);
            $ref = $this->str($entry['ref'] ?? null);

            $normalized = ['kind' => $kind, 'ref' => $ref];
            $all[] = $normalized;

            if ($kind !== null && in_array($kind, self::ACCEPTED_EVIDENCE_KINDS, true)) {
                $accepted[] = ['kind' => $kind, 'ref' => $ref];
            }
        }

        return [$all, $accepted];
    }
}
