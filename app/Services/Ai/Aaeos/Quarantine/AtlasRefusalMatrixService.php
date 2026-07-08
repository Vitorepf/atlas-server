<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas AI Cyber Refusal Matrix — pure, deterministic refusal decider.
 *
 * Encodes the canonical matrix of actions that cyber-* skills NEVER execute.
 * A skill consults this matrix BEFORE proposing any action to Atlas Decide; a
 * match on a rule whose exception clause is not satisfied resolves to a
 * Refusal-with-Receipt. The service is an EXTENSION of kernel policy: it does
 * not bypass policy nor create a parallel one. It only classifies a requested
 * action and emits an auditable receipt — it never executes anything.
 *
 * Contract (from the doc "Princípios", "Schema de regra" and the rule tables):
 *   Entrada: requested action { technique, target, target_in_scope,
 *            clauses[], actor_requested, bypass_attempt }.
 *   Saida:   decision (refuse | allow_with_clause | allow) + Refusal-with-Receipt.
 *
 * Documented invariants this code enforces:
 *   - R1 "Refusal-with-Receipt sempre. Recusa silenciosa proibida."
 *        => every refusal returns a populated receipt; refusal is never silent.
 *   - R4 "Match conservador. Em duvida, recusa. False positive > false negative."
 *        => an unknown technique with no rule still refuses by default unless the
 *        caller proves the target is in scope and authorization holds.
 *   - "sem excecao" rules can NEVER be allowed even when clauses are supplied.
 *   - cyber-ref-050 (health/life systems) is refused ALWAYS, "mesmo sob clausula".
 *   - "Sinal de tentativa de bypass": a skill that retries past the cap
 *        (bypass_attempt > MAX_BYPASS_ATTEMPTS) is blocked and a bypass event is
 *        flagged for the operator; the run aborts.
 *   - R3 "Programa BB nao pode contradizer matriz": a banned technique stays
 *        refused even if the BB program nominally requested it.
 *
 * @see docs/engineering-knowledge-base/cyber-security/refusal-matrix.md
 */
final class AtlasRefusalMatrixService
{
    /** Stable receipt schema id this matrix emits (from "Refusal-with-Receipt schema"). */
    public const RECEIPT_KIND = 'cyber.refusal';

    /** Decisions (closed set). */
    public const DECISION_REFUSE = 'refuse';
    public const DECISION_ALLOW_WITH_CLAUSE = 'allow_with_clause';
    public const DECISION_ALLOW = 'allow';

    /** on_match action emitted on every matrix hit (from "Schema de regra"). */
    public const ON_MATCH_ACTION = 'refusal-with-receipt';

    /**
     * Bypass cap ("Sinal de tentativa de bypass": skill que tenta bypassar a
     * matriz N>2 vezes na mesma operacao). The 3rd+ attempt (>2) trips the
     * bypass guard: Atlas Decide blocks, event is flagged, run aborts.
     */
    public const MAX_BYPASS_ATTEMPTS = 2;

    /** Evidence Ledger event id raised when the bypass cap is exceeded. */
    public const BYPASS_EVENT = 'cyber.refusal_matrix_bypass_attempt';

    /**
     * Exception-clause modes for a rule:
     *   - none           : "sem excecao" — can NEVER be allowed.
     *   - never          : "NUNCA, mesmo sob clausula" — hard never (life-safety).
     *   - operator       : requires an explicit operator clause (+ scoped window/env).
     *   - clause         : requires a specific named clause token (e.g. red-team-c2).
     */
    public const EXC_NONE = 'none';
    public const EXC_NEVER = 'never';
    public const EXC_OPERATOR = 'operator';
    public const EXC_CLAUSE = 'clause';

    /**
     * Canonical rules from the doc "Regras canonicas" tables. Each rule:
     *   category   : human category label (from the table headers).
     *   needles    : lowercase substrings that match a requested technique/target.
     *   exception  : EXC_* mode controlling whether a clause can lift the refusal.
     *   clauses    : for EXC_CLAUSE / EXC_OPERATOR, the named clause tokens that
     *                MUST all be present for an allow_with_clause.
     *   rationale  : short prose reason (the "motivo" surfaced in the receipt).
     *
     * @var array<string, array{
     *     category:string,
     *     needles:list<string>,
     *     exception:string,
     *     clauses:list<string>,
     *     rationale:string
     * }>
     */
    private const RULES = [
        // --- DoS / Resource Exhaustion ---
        'cyber-ref-001' => [
            'category' => 'DoS / Resource Exhaustion',
            'needles' => ['dos', 'ddos', 'slowloris', 'billion laughs', 'zip bomb', 'regex bomb'],
            'exception' => self::EXC_OPERATOR,
            'clauses' => ['operator_clause', 'non_prod_dedicated_env', 'window_under_1h'],
            'rationale' => 'Denial-of-service / resource-exhaustion technique can take down real availability.',
        ],
        'cyber-ref-002' => [
            'category' => 'DoS / Resource Exhaustion',
            'needles' => ['bandwidth exhaustion'],
            'exception' => self::EXC_OPERATOR,
            'clauses' => ['operator_clause', 'non_prod_dedicated_env', 'window_under_1h'],
            'rationale' => 'Sustained bandwidth exhaustion above the documented threshold degrades real service.',
        ],
        'cyber-ref-003' => [
            'category' => 'DoS / Resource Exhaustion',
            'needles' => ['memory exhaustion', 'xxe expansion', 'xml expansion'],
            'exception' => self::EXC_OPERATOR,
            'clauses' => ['operator_clause', 'non_prod_dedicated_env', 'window_under_1h'],
            'rationale' => 'Memory-exhaustion expansion attack can crash the target process.',
        ],
        'cyber-ref-004' => [
            'category' => 'DoS / Resource Exhaustion',
            'needles' => ['account lockout', 'lockout enumeration'],
            'exception' => self::EXC_OPERATOR,
            'clauses' => ['operator_clause', 'test_only_usernames'],
            'rationale' => 'Lockout enumeration can block real user accounts.',
        ],

        // --- Mass-Targeting / Out-of-Scope ---
        'cyber-ref-010' => [
            'category' => 'Mass-Targeting / Out-of-Scope',
            'needles' => ['target_out_of_scope', 'unlisted target'],
            'exception' => self::EXC_NONE,
            'clauses' => [],
            'rationale' => 'Action against a target not listed in the BB program / scope.in.',
        ],
        'cyber-ref-011' => [
            'category' => 'Mass-Targeting / Out-of-Scope',
            'needles' => ['unvalidated external input', 'scan from external input'],
            'exception' => self::EXC_NONE,
            'clauses' => [],
            'rationale' => 'Scan driven by unvalidated external input.',
        ],
        'cyber-ref-012' => [
            'category' => 'Mass-Targeting / Out-of-Scope',
            'needles' => ['wildcard scan', 'extrapola escopo'],
            'exception' => self::EXC_NONE,
            'clauses' => [],
            'rationale' => 'Wildcard scan that extrapolates beyond the authorized scope.',
        ],

        // --- Supply-Chain Ofensivo ---
        'cyber-ref-020' => [
            'category' => 'Supply-Chain Offensive',
            'needles' => ['typosquatting', 'similar package name'],
            'exception' => self::EXC_NONE,
            'clauses' => [],
            'rationale' => 'Publishing a look-alike package in a public registry.',
        ],
        'cyber-ref-021' => [
            'category' => 'Supply-Chain Offensive',
            'needles' => ['dependency confusion'],
            'exception' => self::EXC_NONE,
            'clauses' => [],
            'rationale' => 'Dependency confusion against a real target.',
        ],
        'cyber-ref-022' => [
            'category' => 'Supply-Chain Offensive',
            'needles' => ['compromise ci pipeline', 'malicious pr pipeline'],
            'exception' => self::EXC_NONE,
            'clauses' => [],
            'rationale' => "Compromising the target's CI pipeline via a malicious PR.",
        ],
        'cyber-ref-023' => [
            'category' => 'Supply-Chain Offensive',
            'needles' => ['hidden payload pr', 'payload escondido'],
            'exception' => self::EXC_NONE,
            'clauses' => [],
            'rationale' => 'Submitting a PR with a hidden payload to external repos.',
        ],

        // --- Evasao de Defensores ---
        'cyber-ref-030' => [
            'category' => 'Defender Evasion',
            'needles' => ['edr bypass', 'av bypass', 'waf bypass', 'ids bypass'],
            'exception' => self::EXC_CLAUSE,
            'clauses' => ['red-team-c2', 'adversary_emulation_declared'],
            'rationale' => 'Bypassing EDR/AV/WAF/IDS against an unnotified defender.',
        ],
        'cyber-ref-031' => [
            'category' => 'Defender Evasion',
            'needles' => ['log tampering', 'tampering com logs'],
            'exception' => self::EXC_NONE,
            'clauses' => [],
            'rationale' => "Tampering with the target's logs after exploitation.",
        ],
        'cyber-ref-032' => [
            'category' => 'Defender Evasion',
            'needles' => ['identity spoofing', 'spoof pentester'],
            'exception' => self::EXC_NONE,
            'clauses' => [],
            'rationale' => 'Spoofing the identity of another pentester / internal team.',
        ],

        // --- PII / Dados Sensiveis ---
        'cyber-ref-040' => [
            'category' => 'PII / Sensitive Data',
            'needles' => ['collect pii beyond poc', 'pii beyond minimal poc'],
            'exception' => self::EXC_NONE,
            'clauses' => [],
            'rationale' => 'Collecting real PII beyond the minimal PoC limit.',
        ],
        'cyber-ref-041' => [
            'category' => 'PII / Sensitive Data',
            'needles' => ['health data', 'financial data', 'judicial data'],
            'exception' => self::EXC_CLAUSE,
            'clauses' => ['compliance_vertical'],
            'rationale' => 'Accessing real health/financial/judicial data without a vertical compliance clause.',
        ],
        'cyber-ref-042' => [
            'category' => 'PII / Sensitive Data',
            'needles' => ['capture real credentials', 'credentials of real users'],
            'exception' => self::EXC_NONE,
            'clauses' => [],
            'rationale' => 'Capturing credentials of real (non-test) users.',
        ],
        'cyber-ref-043' => [
            'category' => 'PII / Sensitive Data',
            'needles' => ['export evidence outside storage', 'evidence outside declared location'],
            'exception' => self::EXC_NONE,
            'clauses' => [],
            'rationale' => 'Exporting evidence outside the declared evidence_storage_location.',
        ],

        // --- Sistemas Criticos ---
        'cyber-ref-050' => [
            'category' => 'Critical Systems',
            'needles' => ['hospital', 'medical equipment', 'life safety availability'],
            'exception' => self::EXC_NEVER,
            'clauses' => [],
            'rationale' => 'Compromising availability of a health / life system — never, even under a clause.',
        ],
        'cyber-ref-051' => [
            'category' => 'Critical Systems',
            'needles' => ['scada', 'ics critical infra', 'power grid', 'water system'],
            'exception' => self::EXC_CLAUSE,
            'clauses' => ['government_approval', 'isolated_env'],
            'rationale' => 'SCADA / ICS of critical infrastructure (energy, water, transport).',
        ],
        'cyber-ref-052' => [
            'category' => 'Critical Systems',
            'needles' => ['core financial production', 'real-time settlement'],
            'exception' => self::EXC_OPERATOR,
            'clauses' => ['operator_clause', 'low_traffic_window', 'rollback_tested'],
            'rationale' => 'Core production financial systems (real-time settlement).',
        ],
        'cyber-ref-053' => [
            'category' => 'Critical Systems',
            'needles' => ['autonomous vehicle', 'aviation safety', 'safety-critical component'],
            'exception' => self::EXC_CLAUSE,
            'clauses' => ['multi_stakeholder_approval'],
            'rationale' => 'Safety-critical components (autonomous vehicles, aviation).',
        ],

        // --- Operacao Sem Autorizacao ---
        'cyber-ref-060' => [
            'category' => 'Unauthorized Operation',
            'needles' => ['no bb program', 'sem programa bb', 'no contract'],
            'exception' => self::EXC_NONE,
            'clauses' => [],
            'rationale' => 'Starting an action with no published BB program or contract.',
        ],
        'cyber-ref-061' => [
            'category' => 'Unauthorized Operation',
            'needles' => ['out of program window', 'fora da janela'],
            'exception' => self::EXC_NONE,
            'clauses' => [],
            'rationale' => 'Continuing operation outside the program window.',
        ],
        'cyber-ref-062' => [
            'category' => 'Unauthorized Operation',
            'needles' => ['no auth_proof', 'auth_proof missing', 'sem auth_proof'],
            'exception' => self::EXC_NONE,
            'clauses' => [],
            'rationale' => 'Operation without a valid auth_proof in force.',
        ],
        'cyber-ref-063' => [
            'category' => 'Unauthorized Operation',
            'needles' => ['unnamed subsidiary', 'partner not in bb'],
            'exception' => self::EXC_NONE,
            'clauses' => [],
            'rationale' => 'Operation against a subsidiary/partner not named in the BB program.',
        ],

        // --- Persistence / Exfil Excessiva ---
        'cyber-ref-070' => [
            'category' => 'Persistence / Excessive Exfil',
            'needles' => ['persistence beyond poc', 'persistencia alem'],
            'exception' => self::EXC_CLAUSE,
            'clauses' => ['explicit_persistence_clause'],
            'rationale' => 'Establishing persistence beyond what the PoC needs.',
        ],
        'cyber-ref-071' => [
            'category' => 'Persistence / Excessive Exfil',
            'needles' => ['exfil beyond max bytes', 'exfiltration over limit'],
            'exception' => self::EXC_NONE,
            'clauses' => [],
            'rationale' => 'Exfil beyond exfiltration_proof_max_bytes of the program.',
        ],
        'cyber-ref-072' => [
            'category' => 'Persistence / Excessive Exfil',
            'needles' => ['c2 channel', 'command and control'],
            'exception' => self::EXC_CLAUSE,
            'clauses' => ['red-team-c2'],
            'rationale' => 'Establishing a C2 channel without a red-team-c2 clause.',
        ],
        'cyber-ref-073' => [
            'category' => 'Persistence / Excessive Exfil',
            'needles' => ['lateral pivot out of scope', 'pivot beyond scope.in'],
            'exception' => self::EXC_NONE,
            'clauses' => [],
            'rationale' => 'Lateral pivot beyond hosts in scope.in.',
        ],

        // --- Targets Humanos / Engenharia Social ---
        'cyber-ref-080' => [
            'category' => 'Human Targets / Social Engineering',
            'needles' => ['phishing real people', 'phishing'],
            'exception' => self::EXC_CLAUSE,
            'clauses' => ['social-engineering'],
            'rationale' => 'Phishing against real people without a social-engineering clause.',
        ],
        'cyber-ref-081' => [
            'category' => 'Human Targets / Social Engineering',
            'needles' => ['phishing external people', 'phishing out of scope people'],
            'exception' => self::EXC_NONE,
            'clauses' => [],
            'rationale' => 'Phishing against people outside the program scope — no exception even with a clause.',
        ],
        'cyber-ref-082' => [
            'category' => 'Human Targets / Social Engineering',
            'needles' => ['pretexting employee', 'personal info via pretexting'],
            'exception' => self::EXC_CLAUSE,
            'clauses' => ['social-engineering', 'privacy_compliance'],
            'rationale' => "Collecting an employee's personal info via pretexting.",
        ],
        'cyber-ref-083' => [
            'category' => 'Human Targets / Social Engineering',
            'needles' => ['physical access', 'unauthorized physical'],
            'exception' => self::EXC_CLAUSE,
            'clauses' => ['physical', 'separate_authorization'],
            'rationale' => 'Unauthorized physical access.',
        ],

        // --- Compliance / Legal ---
        'cyber-ref-090' => [
            'category' => 'Compliance / Legal',
            'needles' => ['different jurisdiction', 'foreign law no approval'],
            'exception' => self::EXC_NONE,
            'clauses' => [],
            'rationale' => 'Operating in a jurisdiction with different law without local approval.',
        ],
        'cyber-ref-091' => [
            'category' => 'Compliance / Legal',
            'needles' => ['gdpr article 9', 'special category data'],
            'exception' => self::EXC_NONE,
            'clauses' => [],
            'rationale' => 'Collecting data that violates GDPR Art. 9 (special categories).',
        ],
        'cyber-ref-092' => [
            'category' => 'Compliance / Legal',
            'needles' => ['ofac', 'sanctions', 'un sanctions'],
            'exception' => self::EXC_NONE,
            'clauses' => [],
            'rationale' => 'Operation that violates sanctions (OFAC, UN).',
        ],
        'cyber-ref-093' => [
            'category' => 'Compliance / Legal',
            'needles' => ['share finding third party', 'disclose to third party'],
            'exception' => self::EXC_NONE,
            'clauses' => [],
            'rationale' => 'Sharing a finding with a third party without a clause.',
        ],

        // --- Atlas Self-Protection ---
        'cyber-ref-100' => [
            'category' => 'Atlas Self-Protection',
            'needles' => ['modify atlas runtime', 'self runtime modification'],
            'exception' => self::EXC_NONE,
            'clauses' => [],
            'rationale' => 'Modifying the Atlas runtime itself during a cyber operation.',
        ],
        'cyber-ref-101' => [
            'category' => 'Atlas Self-Protection',
            'needles' => ['bypass quality gates', 'quality gate bypass'],
            'exception' => self::EXC_NONE,
            'clauses' => [],
            'rationale' => 'Bypassing Quality Gates from a cyber-* skill.',
        ],
        'cyber-ref-102' => [
            'category' => 'Atlas Self-Protection',
            'needles' => ['disable audit', 'disable evidence ledger'],
            'exception' => self::EXC_NONE,
            'clauses' => [],
            'rationale' => 'Disabling Audit / Evidence Ledger during an operation.',
        ],
        'cyber-ref-103' => [
            'category' => 'Atlas Self-Protection',
            'needles' => ['autonomous bb report', 'submit bb report without operator'],
            'exception' => self::EXC_NONE,
            'clauses' => [],
            'rationale' => 'A cyber-* skill submitting a BB report autonomously without operator confirmation.',
        ],
    ];

    /**
     * Evaluate one requested action against the matrix and return a decision
     * plus a Refusal-with-Receipt.
     *
     * @param array<string,mixed> $request
     *        technique       : string  the requested technique/action label.
     *        target          : string  the requested target (optional).
     *        target_in_scope : bool    is the target listed in the BB scope.in? (default false → conservative).
     *        clauses         : list    named exception-clause tokens the operator supplied (default []).
     *        actor_requested : string  operator | provider_tool (default provider_tool).
     *        bypass_attempt  : int     0-based count of prior refusals retried this op (default 0).
     *        skill_id        : string  the cyber-* skill consulting the matrix (default cyber-bb-runner).
     *        envelope_id     : string  correlation id (optional).
     *
     * @return array<string,mixed> the decision + receipt (Refusal-with-Receipt schema)
     */
    public function evaluate(array $request): array
    {
        $technique = $this->str($request['technique'] ?? null) ?? '';
        $target = $this->str($request['target'] ?? null) ?? '';
        $targetInScope = (bool) ($request['target_in_scope'] ?? false);
        $clauses = $this->normalizeClauses($request['clauses'] ?? []);
        $actor = $this->normalizeActor($request['actor_requested'] ?? null);
        $bypassAttempt = $this->normalizeAttempt($request['bypass_attempt'] ?? null);
        $skillId = $this->str($request['skill_id'] ?? null) ?? 'cyber-bb-runner';
        $envelopeId = $this->str($request['envelope_id'] ?? null);

        // Bypass guard ("Sinal de tentativa de bypass"): retrying a refusal past
        // the cap blocks the run, flags an Evidence Ledger event and notifies the
        // operator. This is checked first so a flood of retries always aborts.
        $bypassTripped = $bypassAttempt > self::MAX_BYPASS_ATTEMPTS;

        [$ruleId, $rule] = $this->matchRule($technique, $target, $targetInScope);

        // No rule matched. R4 conservative default: in doubt, refuse — UNLESS the
        // caller proved the target is in scope (a positively-authorized action).
        if ($ruleId === null) {
            if ($targetInScope && ! $bypassTripped) {
                return $this->allowReceipt($skillId, $envelopeId, $actor, $technique, $target);
            }

            return $this->refusalReceipt(
                skillId: $skillId,
                envelopeId: $envelopeId,
                ruleId: null,
                rule: null,
                actor: $actor,
                technique: $technique,
                target: $target,
                exceptionEvaluated: false,
                missing: $bypassTripped ? ['bypass_cap_exceeded'] : ['target_not_in_scope_conservative_refusal'],
                bypassAttempt: $bypassAttempt,
                bypassTripped: $bypassTripped,
                decision: self::DECISION_REFUSE,
            );
        }

        // A rule matched. Resolve the exception clause.
        $mode = $rule['exception'];
        $requiredClauses = $rule['clauses'];

        // EXC_NEVER (cyber-ref-050) and EXC_NONE ("sem excecao") can NEVER be
        // lifted, regardless of clauses supplied. Always refuse.
        if ($mode === self::EXC_NEVER || $mode === self::EXC_NONE) {
            return $this->refusalReceipt(
                skillId: $skillId,
                envelopeId: $envelopeId,
                ruleId: $ruleId,
                rule: $rule,
                actor: $actor,
                technique: $technique,
                target: $target,
                exceptionEvaluated: false,
                missing: $mode === self::EXC_NEVER ? ['no_clause_can_lift_this_rule'] : ['rule_has_no_exception_clause'],
                bypassAttempt: $bypassAttempt,
                bypassTripped: $bypassTripped,
                decision: self::DECISION_REFUSE,
            );
        }

        // EXC_OPERATOR / EXC_CLAUSE: every required clause token must be present.
        $missing = array_values(array_diff($requiredClauses, $clauses));
        $allClausesPresent = $missing === [];

        // Even with all clauses present, a tripped bypass guard aborts the run.
        if ($allClausesPresent && ! $bypassTripped) {
            return $this->refusalReceipt(
                skillId: $skillId,
                envelopeId: $envelopeId,
                ruleId: $ruleId,
                rule: $rule,
                actor: $actor,
                technique: $technique,
                target: $target,
                exceptionEvaluated: true,
                missing: [],
                bypassAttempt: $bypassAttempt,
                bypassTripped: false,
                decision: self::DECISION_ALLOW_WITH_CLAUSE,
            );
        }

        // Missing one or more clauses (or bypass tripped) → refuse, listing what
        // is missing so the operator can supply the exception clause.
        return $this->refusalReceipt(
            skillId: $skillId,
            envelopeId: $envelopeId,
            ruleId: $ruleId,
            rule: $rule,
            actor: $actor,
            technique: $technique,
            target: $target,
            exceptionEvaluated: true,
            missing: $bypassTripped ? array_values(array_unique([...$missing, 'bypass_cap_exceeded'])) : $missing,
            bypassAttempt: $bypassAttempt,
            bypassTripped: $bypassTripped,
            decision: self::DECISION_REFUSE,
        );
    }

    /**
     * Convenience predicate for a skill gate: is this action refused right now?
     * (true => skill must NOT propose it to Atlas Decide.)
     *
     * @param array<string,mixed> $request
     */
    public function isRefused(array $request): bool
    {
        return $this->evaluate($request)['decision'] === self::DECISION_REFUSE;
    }

    /**
     * Match a requested action against the rule table. A rule matches when any of
     * its technique needles is a substring of the technique OR target, OR — for
     * the out-of-scope rule cyber-ref-010 — when a concrete target is supplied and
     * the caller did NOT prove it is in scope (R4 conservative).
     *
     * @return array{0:?string,1:?array{category:string,needles:list<string>,exception:string,clauses:list<string>,rationale:string}}
     */
    private function matchRule(string $technique, string $target, bool $targetInScope): array
    {
        $hayTechnique = strtolower($technique);
        $hayTarget = strtolower($target);

        foreach (self::RULES as $ruleId => $rule) {
            foreach ($rule['needles'] as $needle) {
                if (str_contains($hayTechnique, $needle) || ($hayTarget !== '' && str_contains($hayTarget, $needle))) {
                    return [$ruleId, $rule];
                }
            }
        }

        // Conservative out-of-scope catch (cyber-ref-010): a named target that the
        // caller did not assert is in scope is treated as out-of-scope targeting.
        if ($target !== '' && ! $targetInScope) {
            return ['cyber-ref-010', self::RULES['cyber-ref-010']];
        }

        return [null, null];
    }

    /**
     * Build a Refusal-with-Receipt (refuse or allow_with_clause) following the
     * doc "Refusal-with-Receipt schema". A receipt is ALWAYS populated — R1: a
     * refusal is never silent.
     *
     * @param array{category:string,needles:list<string>,exception:string,clauses:list<string>,rationale:string}|null $rule
     * @param list<string> $missing
     * @return array<string,mixed>
     */
    private function refusalReceipt(
        string $skillId,
        ?string $envelopeId,
        ?string $ruleId,
        ?array $rule,
        string $actor,
        string $technique,
        string $target,
        bool $exceptionEvaluated,
        array $missing,
        int $bypassAttempt,
        bool $bypassTripped,
        string $decision,
    ): array {
        $rationale = $rule['rationale']
            ?? 'No matching rule; conservative refusal (R4: in doubt, refuse).';

        return [
            'receipt_kind' => self::RECEIPT_KIND,
            'schema' => self::RECEIPT_KIND,
            'decision' => $decision,
            'on_match' => self::ON_MATCH_ACTION,
            'envelope_id' => $envelopeId,
            'skill_id' => $skillId,
            'rule_id' => $ruleId,
            'category' => $rule['category'] ?? null,
            'requested_action' => [
                'technique' => $technique,
                'target' => $target,
            ],
            'actor_requested' => $actor,
            'rationale' => $rationale,
            'exception_clause_evaluated' => $exceptionEvaluated,
            'exception_clause_missing' => array_values($missing),
            'notify' => ['operator'],
            // A refusal must never be silent (R1): a refuse decision always carries
            // a receipt the caller is obliged to emit.
            'silent' => false,
            'auditable' => true,
            'bypass_attempt' => $bypassAttempt,
            'bypass_tripped' => $bypassTripped,
            'bypass_event' => $bypassTripped ? self::BYPASS_EVENT : null,
            'abort_run' => $bypassTripped,
        ];
    }

    /**
     * Build the allow receipt for a positively-authorized in-scope action with no
     * matrix rule hit. Still auditable, still notifies — but not a refusal.
     *
     * @return array<string,mixed>
     */
    private function allowReceipt(
        string $skillId,
        ?string $envelopeId,
        string $actor,
        string $technique,
        string $target,
    ): array {
        return [
            'receipt_kind' => self::RECEIPT_KIND,
            'schema' => self::RECEIPT_KIND,
            'decision' => self::DECISION_ALLOW,
            'on_match' => null,
            'envelope_id' => $envelopeId,
            'skill_id' => $skillId,
            'rule_id' => null,
            'category' => null,
            'requested_action' => [
                'technique' => $technique,
                'target' => $target,
            ],
            'actor_requested' => $actor,
            'rationale' => 'No matrix rule matched and the target is asserted in scope.',
            'exception_clause_evaluated' => false,
            'exception_clause_missing' => [],
            'notify' => ['operator'],
            'silent' => false,
            'auditable' => true,
            'bypass_attempt' => 0,
            'bypass_tripped' => false,
            'bypass_event' => null,
            'abort_run' => false,
        ];
    }

    /** @return list<string> the immutable rule_id list this matrix enforces. */
    public function ruleIds(): array
    {
        return array_keys(self::RULES);
    }

    /**
     * @param mixed $clauses
     * @return list<string>
     */
    private function normalizeClauses(mixed $clauses): array
    {
        if (! is_array($clauses)) {
            return [];
        }

        $clean = [];
        foreach ($clauses as $c) {
            if (is_string($c) && trim($c) !== '') {
                $clean[] = strtolower(trim($c));
            }
        }

        return array_values(array_unique($clean));
    }

    private function normalizeActor(mixed $actor): string
    {
        if (is_string($actor)) {
            $key = strtolower(trim($actor));
            if ($key === 'operator' || $key === 'provider_tool') {
                return $key;
            }
        }

        // Unknown actor is treated as provider_tool (the less-trusted caller).
        return 'provider_tool';
    }

    private function normalizeAttempt(mixed $attempt): int
    {
        if (is_int($attempt) && $attempt >= 0) {
            return $attempt;
        }
        if (is_string($attempt) && ctype_digit($attempt)) {
            return (int) $attempt;
        }

        return 0;
    }

    private function str(mixed $v): ?string
    {
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }

        return null;
    }
}
