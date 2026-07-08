<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Agentic Engineering OS Implementation Reality — policy-guard decider.
 *
 * This doc is not new feature runtime; it IS a runtime gate. It separates two
 * orthogonal axes that AIs and autonomous loops keep collapsing into one:
 *
 *   doc_maturity != implementation_state
 *
 * The service turns the doc's contracts into pure, deterministic checks (NO
 * I/O — no DB, no provider, no command execution):
 *
 *   1. "Doc maturity" — classifies a doc onto DOC L0..L4 from which document
 *      parts exist (mother doc / contracts / runbook / gates+evidence).
 *
 *   2. "Implementation state" — assigns one of the 5 closed states
 *      (runtime_verified, implemented_partial, spec_only, north_star,
 *      deprecated) from the evidence actually presented, and answers the
 *      single load-bearing question: "Pode ser chamado de pronto?". Only
 *      runtime_verified is ready (within proven scope); implemented_partial
 *      is ready ONLY with caveats; the rest are never ready.
 *
 *   3. runtime_verified gate — requires code + (command OR route) + test +
 *      (receipt OR evidence OR green validation). Crucially, the doc states
 *      next_actions / required_tests / quality_gates are REQUIREMENTS, not
 *      evidence: this service refuses to let those satisfy the gate.
 *
 *   4. Autonomous-loop consumption — allow/block. A loop may only consume a
 *      claim as "pronto" when it is runtime_verified; and in a loop,
 *      runtime_verified additionally requires a cycle receipt + green test,
 *      and — when a merge is in scope — a ledger with outcome=merged AND
 *      merge_performed=true. spec_only / north_star / comparison-report /
 *      handoff are context only, never proof.
 *
 *   5. Definition of Done for an "AAEOS tem X" claim — the claim must carry
 *      owner doc + doc state + runtime state + code/command path (when it
 *      exists) + test/receipt/evidence/blocker + explicit caveat if partial.
 *      Without those fields the claim is narrative, not evidence.
 *
 * The service consumes already-collected facts about a claim and emits a
 * verdict; it never performs verification itself. Callers decide what to do.
 *
 * @see docs/engineering-knowledge-base/atlas-agentic-engineering-os-implementation-reality.md
 */
final class AtlasAgenticEngineeringOsImplementationRealityService
{
    /** Stable receipt schema id for every verdict this service emits. */
    public const SCHEMA = 'atlas.aaeos.implementation_reality.v1';

    /** Closed doc-maturity ladder, lowest to highest. */
    public const DOC_L0 = 'DOC L0';

    public const DOC_L1 = 'DOC L1';

    public const DOC_L2 = 'DOC L2';

    public const DOC_L3 = 'DOC L3';

    public const DOC_L4 = 'DOC L4';

    /** Closed implementation_state taxonomy. */
    public const STATE_RUNTIME_VERIFIED = 'runtime_verified';

    public const STATE_IMPLEMENTED_PARTIAL = 'implemented_partial';

    public const STATE_SPEC_ONLY = 'spec_only';

    public const STATE_NORTH_STAR = 'north_star';

    public const STATE_DEPRECATED = 'deprecated';

    /** Sentinel for "the runtime was not verified in this session". */
    public const STATE_UNKNOWN_RUNTIME = 'unknown_runtime_state';

    /**
     * Evidence kinds that the doc accepts as PROOF of runtime ("path de codigo,
     * comando/rota, teste verde, receipt, AP executado, ledger ou blocker
     * machine-readable").
     *
     * @var array<int,string>
     */
    private const PROOF_KINDS = ['code', 'command', 'route', 'test', 'receipt', 'ap', 'ledger', 'validation_green'];

    /**
     * Kinds the doc EXPLICITLY rejects as evidence: they are requirements or
     * context, not proof. "Nao converta next_actions, required_tests ou
     * quality_gates em prova de implementacao." Plus handoff/prompt/report/
     * north_star/spec which are "contexto ate promocao por doc dono".
     *
     * @var array<int,string>
     */
    private const NON_PROOF_KINDS = [
        'next_action', 'next_actions', 'required_test', 'required_tests',
        'quality_gate', 'quality_gates', 'handoff', 'prompt', 'report',
        'north_star', 'spec', 'factory_suggestion',
    ];

    /**
     * Classify a doc's maturity onto DOC L0..L4 from which structural parts it
     * has. The ladder is cumulative: each level presumes the ones below it.
     *
     *   L4 = mother + contracts + runbook + (matrix|quality_bar|evidence|gates)
     *   L3 = mother + contracts + runbook, with partial gates/evidence
     *   L2 = mother + contracts; runbook/gates incomplete
     *   L1 = mother doc OR north-star only, fragmentary
     *   L0 = idea/research/source material, no contract
     *
     * @param  array<string,mixed>  $parts
     * @return array<string,mixed>
     */
    public function classifyDocMaturity(array $parts): array
    {
        $hasMother = (bool) ($parts['mother_doc'] ?? false);
        $hasContracts = (bool) ($parts['contracts'] ?? false);
        $hasRunbook = (bool) ($parts['runbook'] ?? false);
        $strongGates = (bool) ($parts['strong_gates_evidence'] ?? false);
        $partialGates = (bool) ($parts['partial_gates_evidence'] ?? false);
        $northStarOnly = (bool) ($parts['north_star_only'] ?? false);

        if ($hasMother && $hasContracts && $hasRunbook && $strongGates) {
            $level = self::DOC_L4;
            $meaning = 'Mae + contracts + runbook + matriz/quality bar/evidence/gates fortes.';
        } elseif ($hasMother && $hasContracts && $hasRunbook && $partialGates) {
            $level = self::DOC_L3;
            $meaning = 'Mae + contracts + runbook bons, gates ou evidence parciais.';
        } elseif ($hasMother && $hasContracts) {
            $level = self::DOC_L2;
            $meaning = 'Mae + contracts; runbook/gates incompletos.';
        } elseif ($hasMother || $northStarOnly) {
            $level = self::DOC_L1;
            $meaning = 'Doc-mae ou north-star fragmentario.';
        } else {
            $level = self::DOC_L0;
            $meaning = 'Ideia, pesquisa ou source material sem contrato.';
        }

        return [
            'schema' => self::SCHEMA,
            'doc_maturity' => $level,
            'meaning' => $meaning,
            // The whole point of the doc: maturity is NOT runtime.
            'is_runtime_proof' => false,
            'note' => 'doc_maturity != implementation_state; never claim runtime from this alone.',
        ];
    }

    /**
     * The full classify->verify->assign->caveat->allow/block flow for one claim.
     *
     * Expected input:
     *   doc_maturity_parts: array (fed to classifyDocMaturity)        [optional]
     *   declared_state:     string                                    [optional]
     *   deprecated:         bool   (history; never authority)         [optional]
     *   north_star:         bool   (strategy; never loop-ready)       [optional]
     *   runtime_checked:    bool   (was runtime verified this session)[default true]
     *   evidence:           array<int,array{kind:string,ref?:string}> [optional]
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function classify(array $input): array
    {
        $maturity = $this->classifyDocMaturity(
            is_array($input['doc_maturity_parts'] ?? null) ? $input['doc_maturity_parts'] : [],
        );

        $evidenceVerdict = $this->verifyEvidence(
            is_array($input['evidence'] ?? null) ? $input['evidence'] : [],
        );

        // "Se o runtime nao foi verificado na sessao, use unknown_runtime_state."
        $runtimeChecked = ($input['runtime_checked'] ?? true) === true;
        $deprecated = ($input['deprecated'] ?? false) === true;
        $northStar = ($input['north_star'] ?? false) === true;
        $declared = $this->normalizeState((string) ($input['declared_state'] ?? ''));

        $state = $this->assignState($declared, $evidenceVerdict, $runtimeChecked, $deprecated, $northStar);
        $readiness = $this->readiness($state);

        $caveats = $this->caveats($state, $evidenceVerdict);

        return [
            'schema' => self::SCHEMA,
            'doc_maturity' => $maturity['doc_maturity'],
            'implementation_state' => $state,
            'ready_to_claim' => $readiness['ready'],
            'ready_qualifier' => $readiness['qualifier'],
            'ready_rule' => $readiness['rule'],
            'evidence' => $evidenceVerdict,
            'caveats' => $caveats,
            'auditable' => true,
        ];
    }

    /**
     * Verify the evidence bag against the doc's runtime_verified gate. Returns
     * which proof axes are met and whether the gate passes. Critically, kinds
     * the doc rejects (next_actions / required_tests / quality_gates / etc.)
     * are SCRUBBED before evaluation so they can never satisfy the gate.
     *
     * Gate: code + (command|route) + test + (receipt|ledger|ap|validation_green).
     *
     * @param  array<int,array{kind?:string,ref?:string}>  $evidence
     * @return array<string,mixed>
     */
    public function verifyEvidence(array $evidence): array
    {
        $proofKinds = [];
        $rejected = [];
        foreach ($evidence as $item) {
            $kind = strtolower(trim((string) ($item['kind'] ?? '')));
            if ($kind === '') {
                continue;
            }
            if (in_array($kind, self::NON_PROOF_KINDS, true)) {
                $rejected[] = $kind;

                continue;
            }
            if (in_array($kind, self::PROOF_KINDS, true)) {
                $proofKinds[$kind] = true;
            }
        }

        $hasCode = $proofKinds['code'] ?? false;
        $hasWiring = ($proofKinds['command'] ?? false) || ($proofKinds['route'] ?? false);
        $hasTest = $proofKinds['test'] ?? false;
        $hasProofArtifact = ($proofKinds['receipt'] ?? false)
            || ($proofKinds['ledger'] ?? false)
            || ($proofKinds['ap'] ?? false)
            || ($proofKinds['validation_green'] ?? false);

        $gatePassed = $hasCode && $hasWiring && $hasTest && $hasProofArtifact;

        $missing = [];
        if (! $hasCode) {
            $missing[] = 'code_path';
        }
        if (! $hasWiring) {
            $missing[] = 'command_or_route';
        }
        if (! $hasTest) {
            $missing[] = 'green_test';
        }
        if (! $hasProofArtifact) {
            $missing[] = 'receipt_or_ledger_or_validation';
        }

        // Has SOME proof but not the full gate -> partial runtime exists.
        $hasAnyProof = $hasCode || $hasWiring || $hasTest || $hasProofArtifact;

        return [
            'runtime_verified_gate_passed' => $gatePassed,
            'has_partial_runtime' => $hasAnyProof && ! $gatePassed,
            'present' => [
                'code' => $hasCode,
                'wiring' => $hasWiring,
                'test' => $hasTest,
                'proof_artifact' => $hasProofArtifact,
            ],
            'missing_for_verified' => $missing,
            // Echo what was thrown out — transparency that requirements != evidence.
            'rejected_non_evidence' => array_values(array_unique($rejected)),
        ];
    }

    /**
     * Assign the closed implementation_state from declared intent + verified
     * evidence + session-context flags. Evidence outranks the declaration: a
     * doc cannot self-promote to runtime_verified without passing the gate.
     *
     * @param  array<string,mixed>  $evidenceVerdict
     */
    public function assignState(
        string $declared,
        array $evidenceVerdict,
        bool $runtimeChecked,
        bool $deprecated,
        bool $northStar,
    ): string {
        // deprecated is a hard historical marker — never authority, never ready.
        if ($deprecated || $declared === self::STATE_DEPRECATED) {
            return self::STATE_DEPRECATED;
        }

        // The strongest, evidence-backed outcome. Earned, not declared.
        if (($evidenceVerdict['runtime_verified_gate_passed'] ?? false) === true) {
            return self::STATE_RUNTIME_VERIFIED;
        }

        // Some runtime proven but gate incomplete -> partial (must carry caveats).
        if (($evidenceVerdict['has_partial_runtime'] ?? false) === true
            || $declared === self::STATE_IMPLEMENTED_PARTIAL) {
            return self::STATE_IMPLEMENTED_PARTIAL;
        }

        // north_star is strategy: never enters the loop as ready.
        if ($northStar || $declared === self::STATE_NORTH_STAR) {
            return self::STATE_NORTH_STAR;
        }

        // No proof at all. If the runtime was not even checked this session, the
        // honest answer is unknown_runtime_state, not spec_only.
        if (! $runtimeChecked && $declared === '') {
            return self::STATE_UNKNOWN_RUNTIME;
        }

        // Declared spec_only, or nothing to prove execution -> spec_only.
        return self::STATE_SPEC_ONLY;
    }

    /**
     * "Pode ser chamado de pronto?" per the doc's implementation_state table.
     *
     * @return array{ready:bool, qualifier:string, rule:string}
     */
    public function readiness(string $state): array
    {
        return match ($state) {
            self::STATE_RUNTIME_VERIFIED => [
                'ready' => true,
                'qualifier' => 'within_proven_scope',
                'rule' => 'Codigo + comando/rota + teste + receipt/evidence ou validacao verde.',
            ],
            self::STATE_IMPLEMENTED_PARTIAL => [
                'ready' => false,
                'qualifier' => 'not_as_complete_caveats_required',
                'rule' => 'Existe runtime, mas gaps/caveats precisam aparecer no claim.',
            ],
            self::STATE_SPEC_ONLY => [
                'ready' => false,
                'qualifier' => 'governs_future_only',
                'rule' => 'Doc governa futuro; nao prova execucao.',
            ],
            self::STATE_NORTH_STAR => [
                'ready' => false,
                'qualifier' => 'strategy_not_loop_input',
                'rule' => 'Direcao estrategica; nao entra em loop operacional como pronto.',
            ],
            self::STATE_DEPRECATED => [
                'ready' => false,
                'qualifier' => 'history_not_authority',
                'rule' => 'Historico; pode orientar migracao, nunca autoridade atual.',
            ],
            default => [
                'ready' => false,
                'qualifier' => 'runtime_not_verified_this_session',
                'rule' => 'unknown_runtime_state ate verificar codigo/teste/receipt.',
            ],
        };
    }

    /**
     * Decide whether an autonomous loop (Stewardship / Area Focus) may consume a
     * claim as "pronto". The loop bar is STRICTER than a human claim:
     *
     *   - state must be runtime_verified; AND
     *   - a cycle receipt must be present; AND
     *   - a green test must be present; AND
     *   - when a merge is in scope, the ledger must say outcome=merged AND
     *     merge_performed=true.
     *
     * Anything spec_only / north_star / handoff is blocked: "Loop
     * autonomo seleciona backlog a partir de promessa nao implementada" is the
     * exact failure mode this guard exists to prevent.
     *
     * Expected input:
     *   implementation_state: string
     *   cycle_receipt:        bool
     *   test_green:           bool
     *   merge_in_scope:       bool
     *   ledger_outcome:       string  (e.g. "merged")
     *   merge_performed:      bool
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function loopConsumption(array $input): array
    {
        $state = $this->normalizeState((string) ($input['implementation_state'] ?? ''));
        $cycleReceipt = ($input['cycle_receipt'] ?? false) === true;
        $testGreen = ($input['test_green'] ?? false) === true;
        $mergeInScope = ($input['merge_in_scope'] ?? false) === true;
        $ledgerOutcome = strtolower(trim((string) ($input['ledger_outcome'] ?? '')));
        $mergePerformed = ($input['merge_performed'] ?? false) === true;

        $reasons = [];
        $allow = true;

        if ($state !== self::STATE_RUNTIME_VERIFIED) {
            $allow = false;
            $reasons[] = "state_not_runtime_verified:{$state}";
        }
        if (! $cycleReceipt) {
            $allow = false;
            $reasons[] = 'missing_cycle_receipt';
        }
        if (! $testGreen) {
            $allow = false;
            $reasons[] = 'missing_green_test';
        }
        if ($mergeInScope && ! ($ledgerOutcome === 'merged' && $mergePerformed)) {
            $allow = false;
            $reasons[] = 'merge_not_proven_in_ledger';
        }

        if ($allow) {
            $reasons[] = 'loop_consumption_admitted';
        }

        return [
            'schema' => self::SCHEMA,
            'allow_consumption' => $allow,
            'decision' => $allow ? 'allow' : 'block',
            'reasons' => $reasons,
            'requires' => [
                'runtime_verified' => true,
                'cycle_receipt' => true,
                'green_test' => true,
                'ledger_merged_when_merge_in_scope' => true,
            ],
            'auditable' => true,
        ];
    }

    /**
     * Definition of Done for an "AAEOS tem X" claim. Without every required
     * field the claim is narrative, not evidence. caveat_if_partial is only
     * required when the runtime state is implemented_partial.
     *
     * Expected input keys (truthy = present):
     *   owner_doc, doc_state, runtime_state, code_or_command,
     *   test_receipt_evidence_or_blocker, caveat_if_partial
     *
     * @param  array<string,mixed>  $claim
     * @return array<string,mixed>
     */
    public function definitionOfDone(array $claim): array
    {
        $runtimeState = $this->normalizeState((string) ($claim['runtime_state'] ?? ''));
        $isPartial = $runtimeState === self::STATE_IMPLEMENTED_PARTIAL;

        $missing = [];
        if (! $this->present($claim, 'owner_doc')) {
            $missing[] = 'owner_doc';
        }
        if (! $this->present($claim, 'doc_state')) {
            $missing[] = 'doc_state';
        }
        if (! $this->present($claim, 'runtime_state')) {
            $missing[] = 'runtime_state';
        }
        // code/command path is required "when it exists" — modeled as a present
        // field; absence is allowed only when the claim is not runtime-bearing.
        if (! $this->present($claim, 'code_or_command')
            && in_array($runtimeState, [self::STATE_RUNTIME_VERIFIED, self::STATE_IMPLEMENTED_PARTIAL], true)) {
            $missing[] = 'code_or_command';
        }
        if (! $this->present($claim, 'test_receipt_evidence_or_blocker')) {
            $missing[] = 'test_receipt_evidence_or_blocker';
        }
        if ($isPartial && ! $this->present($claim, 'caveat_if_partial')) {
            $missing[] = 'caveat_if_partial';
        }

        $valid = $missing === [];

        return [
            'schema' => self::SCHEMA,
            'valid_claim' => $valid,
            'verdict' => $valid ? 'evidence' : 'narrative',
            'missing_fields' => $missing,
            'auditable' => true,
        ];
    }

    /**
     * Normalize loose vocabulary onto the closed implementation_state set.
     * Unknown / empty collapses to '' so callers can decide (never silently a
     * ready state).
     */
    public function normalizeState(string $state): string
    {
        $n = strtolower(trim($state));

        return match ($n) {
            'runtime_verified', 'verified', 'solid_runtime' => self::STATE_RUNTIME_VERIFIED,
            'implemented_partial', 'partial', 'partial_runtime' => self::STATE_IMPLEMENTED_PARTIAL,
            'spec_only', 'spec' => self::STATE_SPEC_ONLY,
            'north_star', 'northstar' => self::STATE_NORTH_STAR,
            'deprecated' => self::STATE_DEPRECATED,
            'unknown_runtime_state', 'unknown' => self::STATE_UNKNOWN_RUNTIME,
            default => '',
        };
    }

    /**
     * Caveats a caller MUST surface alongside the state.
     *
     * @param  array<string,mixed>  $evidenceVerdict
     * @return array<int,string>
     */
    private function caveats(string $state, array $evidenceVerdict): array
    {
        $caveats = [];
        if ($state === self::STATE_IMPLEMENTED_PARTIAL) {
            $caveats[] = 'partial_runtime_declare_gaps';
            foreach ((array) ($evidenceVerdict['missing_for_verified'] ?? []) as $gap) {
                $caveats[] = "gap:{$gap}";
            }
        }
        if ($state === self::STATE_SPEC_ONLY) {
            $caveats[] = 'spec_only_not_execution';
        }
        if ($state === self::STATE_NORTH_STAR) {
            $caveats[] = 'north_star_not_loop_ready';
        }
        if ($state === self::STATE_DEPRECATED) {
            $caveats[] = 'deprecated_not_current_authority';
        }
        if ($state === self::STATE_UNKNOWN_RUNTIME) {
            $caveats[] = 'runtime_unverified_this_session';
        }

        return $caveats;
    }

    /**
     * Truthy-presence check for a claim field: non-empty string, true, or
     * non-empty array all count as "present".
     *
     * @param  array<string,mixed>  $claim
     */
    private function present(array $claim, string $key): bool
    {
        $value = $claim[$key] ?? null;
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }

        return $value === true;
    }
}
