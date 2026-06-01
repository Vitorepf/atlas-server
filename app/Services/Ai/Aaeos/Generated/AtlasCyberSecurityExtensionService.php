<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas AI Cyber Security Extension — pure, deterministic governance decider.
 *
 * This service encodes the EXTENSION-LEVEL contract of the doc: it does NOT
 * re-implement the technique-by-technique refusal table (that lives in
 * AtlasRefusalMatrixService / cyber-security/refusal-matrix.md). Instead it
 * enforces the three governance decisions the extension doc owns and that no
 * other service implements:
 *
 *   1. SCOPE ADMISSION ("Scope" — 7 Included vs 8 Excluded activities). Given a
 *      requested activity it resolves admit | refuse | requires_clause, so a
 *      cyber-* skill knows whether an activity is even in the extension's remit
 *      before it consults the per-technique refusal matrix.
 *   2. NON-CONFUSION classification ("Non-Confusion Rules" 1-2). Routes a
 *      request to the correct surface: this extension vs the defensive-only
 *      `security` domain vs the `programming.security` flow (own code only).
 *   3. PROMOTION GATE ("Promotion Path" — 9 ordered conditions). The extension
 *      is `scaffold`; it only becomes the `cyber` domain when ALL 9 hold. Any
 *      missing condition keeps status `scaffold`.
 *
 * Plus the autonomy invariant from "Output Contract" / Anti-Pattern #6:
 * "Atlas observa, nao corrige" — a prepared BB payload is NEVER auto-submitted;
 * it requires explicit human approval (Proposal Inbox / Human Review).
 *
 * Pure: no DB, no side effects, deterministic. It classifies and gates; it
 * never executes recon, exploit, scan, or submission.
 *
 * @see docs/engineering-knowledge-base/cyber-security-extension.md
 */
final class AtlasCyberSecurityExtensionService
{
    /** Canonical status of the extension until the promotion gate is fully green. */
    public const STATUS_SCAFFOLD = 'scaffold';
    public const STATUS_DOMAIN = 'cyber';

    /** Scope admission verdicts (closed set). */
    public const SCOPE_ADMIT = 'admit';
    public const SCOPE_REFUSE = 'refuse';
    public const SCOPE_REQUIRES_CLAUSE = 'requires_clause';

    /** Surfaces a request can be routed to ("Non-Confusion Rules"). */
    public const SURFACE_EXTENSION = 'cyber_security_extension';
    public const SURFACE_SECURITY_DOMAIN = 'security_domain';
    public const SURFACE_PROGRAMMING_SECURITY = 'programming.security';

    /**
     * "Included" activities (Scope §Included, items 1-7). These are within the
     * extension's remit. Some still demand a clause/authorization downstream,
     * but at the admission layer they are admitted.
     *
     * @var array<string, string>  id => prose
     */
    private const INCLUDED = [
        'bug_bounty_automation' => 'Bug bounty automation against a public program (HackerOne/Bugcrowd/Intigriti/direct security@).',
        'authorized_external_pentest' => 'Pentest against an authorized external target (with BB program or signed contract).',
        'offensive_self_audit' => 'Offensive self-audit of Atlas repos via Purple validation.',
        'active_recon_in_scope' => 'Active recon strictly within the program scope.',
        'confirmed_exploit_minimal_poc' => 'Confirmed exploit with a minimal PoC (no real exfil, no persistence without clause).',
        'triage_and_dedup' => 'Triage and dedup against prior reports on the BB platform.',
        'bb_payload_generation' => 'Generation of a payload ready for submission to the destination BB program.',
    ];

    /**
     * "Excluded" activities (Scope §Excluded, items 1-8). Each excluded item is
     * either a hard refusal (no exception) or admissible only under an explicit
     * named clause. This mirrors the doc's "sem clausula" qualifiers.
     *
     * @var array<string, array{prose:string, clause:?string}>
     *   clause=null  => hard refuse, no exception at the extension layer.
     *   clause=token => requires_clause: admissible only if that clause is present.
     */
    private const EXCLUDED = [
        // 1. DoS/DDoS/resource exhaustion sem clausula explicita.
        'dos_resource_exhaustion' => ['prose' => 'DoS/DDoS/resource exhaustion.', 'clause' => 'dos_explicit_clause'],
        // 2. Mass-targeting (alvo fora do escopo do programa).
        'mass_targeting_out_of_scope' => ['prose' => 'Mass-targeting / target outside the BB program scope.', 'clause' => null],
        // 3. Supply-chain attack ofensivo (typosquat, dependency confusion).
        'supply_chain_offensive' => ['prose' => 'Offensive supply-chain attack (typosquatting, dependency confusion) against a real target.', 'clause' => null],
        // 4. Exfil de PII real.
        'pii_exfiltration' => ['prose' => 'Exfiltration of real PII (only test accounts / redacted allowed).', 'clause' => null],
        // 5. Persistence/exfil alem do PoC minimo sem clausula.
        'persistence_beyond_poc' => ['prose' => 'Persistence or exfil beyond the minimal PoC.', 'clause' => 'persistence_clause'],
        // 6. Bypass de EDR/WAF contra defensores legitimos sem clausula red-team-c2.
        'edr_waf_bypass' => ['prose' => 'EDR/WAF bypass against legitimate defenders.', 'clause' => 'red_team_c2'],
        // 7. Operacao contra alvo sem programa BB publico ou contrato assinado.
        'unauthorized_target_operation' => ['prose' => 'Operation against a target with no public BB program or signed contract.', 'clause' => null],
        // 8. Submissao automatica ao programa BB sem aprovacao humana.
        'autonomous_bb_submission' => ['prose' => 'Automatic submission to the BB program without human approval (Atlas observes, does not correct).', 'clause' => null],
    ];

    /**
     * The 9 ordered conditions of "Promotion Path". The extension promotes to
     * the `cyber` domain only when ALL are true (in any provided order); a
     * single missing condition keeps it `scaffold`.
     *
     * @var list<string>
     */
    private const PROMOTION_CONDITIONS = [
        'place_feature_gate_ok',           // 1. place-feature returns gate_status=ok, no high-overlap duplicates.
        'skills_candidate_with_evals',     // 2. cyber-* skills reach `candidate` with evals passing.
        'dedicated_orchestrator',          // 3. dedicated AtlasDomainOrchestrator implementation exists.
        'profile_registry_migration',      // 4. migration registers `cyber` in AtlasDomainProfileRegistry.
        'onboarding_scorecard_9_of_9',     // 5. AtlasDomainOnboardingScorecard returns ready 9/9.
        'canonical_spec_domain_doc',       // 6. canonical spec created at domains/cyber.md.
        'domains_readme_updated',          // 7. domains/README.md moved scaffold -> implemented/ready.
        'provider_projections_regenerated',// 8. provider projections regenerated.
        'architecture_validate_passes',    // 9. atlas:ai:architecture-validate --json passes.
    ];

    /**
     * Decide whether a requested activity is within the extension's scope.
     *
     * @param  array{activity?:string, clauses?:list<string>}  $request
     * @return array{
     *     verdict:string,
     *     activity:string,
     *     bucket:string,
     *     reasons:list<string>,
     *     required_clause:?string,
     *     human_review_required:bool
     * }
     */
    public function classifyScope(array $request): array
    {
        $activity = (string) ($request['activity'] ?? '');
        /** @var list<string> $clauses */
        $clauses = array_values(array_filter(
            (array) ($request['clauses'] ?? []),
            static fn ($c): bool => is_string($c) && $c !== '',
        ));

        // Included activities are admitted. bb_payload_generation still carries
        // the human-review flag because the payload cannot be auto-submitted.
        if (array_key_exists($activity, self::INCLUDED)) {
            return [
                'verdict' => self::SCOPE_ADMIT,
                'activity' => $activity,
                'bucket' => 'included',
                'reasons' => ['activity_in_included_scope'],
                'required_clause' => null,
                'human_review_required' => $activity === 'bb_payload_generation',
            ];
        }

        if (array_key_exists($activity, self::EXCLUDED)) {
            $rule = self::EXCLUDED[$activity];
            $clause = $rule['clause'];

            // Hard exclusion: no clause can lift it at the extension layer.
            if ($clause === null) {
                return [
                    'verdict' => self::SCOPE_REFUSE,
                    'activity' => $activity,
                    'bucket' => 'excluded_hard',
                    'reasons' => ['activity_excluded_no_exception'],
                    'required_clause' => null,
                    'human_review_required' => false,
                ];
            }

            // Clause-gated exclusion: admissible only with the named clause.
            if (in_array($clause, $clauses, true)) {
                return [
                    'verdict' => self::SCOPE_ADMIT,
                    'activity' => $activity,
                    'bucket' => 'excluded_lifted_by_clause',
                    'reasons' => ['exclusion_lifted_by_clause:'.$clause],
                    'required_clause' => $clause,
                    'human_review_required' => true,
                ];
            }

            return [
                'verdict' => self::SCOPE_REQUIRES_CLAUSE,
                'activity' => $activity,
                'bucket' => 'excluded_clause_gated',
                'reasons' => ['activity_excluded_requires_clause:'.$clause],
                'required_clause' => $clause,
                'human_review_required' => true,
            ];
        }

        // R4 conservative default: an unknown activity is refused, not admitted.
        return [
            'verdict' => self::SCOPE_REFUSE,
            'activity' => $activity,
            'bucket' => 'unknown',
            'reasons' => ['unknown_activity_conservative_refuse'],
            'required_clause' => null,
            'human_review_required' => false,
        ];
    }

    /**
     * Route a request to the correct surface ("Non-Confusion Rules" 1-2).
     *
     * Defensive-only intents (threat/privacy/compliance/incident review with no
     * exploit/scan/secrets) belong to the `security` domain. Security review of
     * the operator's OWN code belongs to `programming.security`. Offensive work
     * against an external authorized target belongs to THIS extension.
     *
     * @param  array{
     *     intent?:string,
     *     offensive?:bool,
     *     target_is_own_code?:bool,
     *     external_authorized_target?:bool
     * }  $request
     * @return array{surface:string, reasons:list<string>, is_extension:bool}
     */
    public function routeSurface(array $request): array
    {
        $intent = (string) ($request['intent'] ?? '');
        $offensive = (bool) ($request['offensive'] ?? false);
        $ownCode = (bool) ($request['target_is_own_code'] ?? false);
        $externalAuthorized = (bool) ($request['external_authorized_target'] ?? false);

        $defensiveIntents = ['threat_review', 'privacy_review', 'compliance_review', 'incident_review'];

        // Non-Confusion #1: defensive-only review with no offense -> security domain.
        if (! $offensive && in_array($intent, $defensiveIntents, true)) {
            return [
                'surface' => self::SURFACE_SECURITY_DOMAIN,
                'reasons' => ['defensive_only_intent_belongs_to_security_domain'],
                'is_extension' => false,
            ];
        }

        // Non-Confusion #2: security review of OWN code -> programming.security flow.
        if ($ownCode && ! $externalAuthorized) {
            return [
                'surface' => self::SURFACE_PROGRAMMING_SECURITY,
                'reasons' => ['own_code_security_review_belongs_to_programming_security'],
                'is_extension' => false,
            ];
        }

        // Offensive work against an authorized external target -> this extension.
        return [
            'surface' => self::SURFACE_EXTENSION,
            'reasons' => ['offensive_authorized_external_target_belongs_to_cyber_extension'],
            'is_extension' => true,
        ];
    }

    /**
     * Autonomy gate ("Output Contract" #4-#5, Anti-Pattern #6): a prepared BB
     * payload may be SUBMITTED only after explicit human approval. Atlas
     * observes; it does not correct.
     *
     * @param  array{payload_prepared?:bool, human_approved?:bool}  $request
     * @return array{may_submit:bool, action:string, reasons:list<string>}
     */
    public function submissionGate(array $request): array
    {
        $prepared = (bool) ($request['payload_prepared'] ?? false);
        $approved = (bool) ($request['human_approved'] ?? false);

        if (! $prepared) {
            return [
                'may_submit' => false,
                'action' => 'await_payload',
                'reasons' => ['payload_not_prepared'],
            ];
        }

        if (! $approved) {
            return [
                'may_submit' => false,
                'action' => 'route_to_human_review',
                'reasons' => ['autonomous_submission_forbidden_requires_human_approval'],
            ];
        }

        return [
            'may_submit' => true,
            'action' => 'submit_after_approval',
            'reasons' => ['human_approved_submission_permitted'],
        ];
    }

    /**
     * Evaluate the promotion gate ("Promotion Path"). Status flips to `cyber`
     * only when ALL 9 conditions are satisfied; otherwise it stays `scaffold`
     * and the missing conditions are reported.
     *
     * @param  array<string, bool>  $signals  condition_id => satisfied
     * @return array{
     *     status:string,
     *     promotable:bool,
     *     satisfied:list<string>,
     *     missing:list<string>,
     *     satisfied_count:int,
     *     total:int
     * }
     */
    public function evaluatePromotion(array $signals): array
    {
        $satisfied = [];
        $missing = [];

        foreach (self::PROMOTION_CONDITIONS as $condition) {
            if (($signals[$condition] ?? false) === true) {
                $satisfied[] = $condition;
            } else {
                $missing[] = $condition;
            }
        }

        $promotable = $missing === [];

        return [
            'status' => $promotable ? self::STATUS_DOMAIN : self::STATUS_SCAFFOLD,
            'promotable' => $promotable,
            'satisfied' => $satisfied,
            'missing' => $missing,
            'satisfied_count' => count($satisfied),
            'total' => count(self::PROMOTION_CONDITIONS),
        ];
    }

    /**
     * Stable manifest of the extension's pinned facts (for the CLI default and
     * drift checks).
     *
     * @return array{
     *     status:string,
     *     included_count:int,
     *     excluded_count:int,
     *     hard_excluded_count:int,
     *     clause_gated_excluded_count:int,
     *     promotion_condition_count:int,
     *     surfaces:list<string>,
     *     autonomy_invariant:string
     * }
     */
    public function manifest(): array
    {
        $hard = 0;
        $clauseGated = 0;
        foreach (self::EXCLUDED as $rule) {
            if ($rule['clause'] === null) {
                $hard++;
            } else {
                $clauseGated++;
            }
        }

        return [
            'status' => self::STATUS_SCAFFOLD,
            'included_count' => count(self::INCLUDED),
            'excluded_count' => count(self::EXCLUDED),
            'hard_excluded_count' => $hard,
            'clause_gated_excluded_count' => $clauseGated,
            'promotion_condition_count' => count(self::PROMOTION_CONDITIONS),
            'surfaces' => [
                self::SURFACE_EXTENSION,
                self::SURFACE_SECURITY_DOMAIN,
                self::SURFACE_PROGRAMMING_SECURITY,
            ],
            'autonomy_invariant' => 'atlas_observes_does_not_auto_submit',
        ];
    }
}
