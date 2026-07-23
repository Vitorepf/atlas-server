<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas AI Cognitive Plane — Roadmap runtime.
 *
 * Turns the cognitive roadmap doc into deterministic, pure decision logic. The
 * doc is a phased delivery plan ("Dreyfus first"), and it imposes hard
 * governance on HOW phases may be sequenced, promoted and declared done. This
 * service enforces exactly that governance instead of restating the prose:
 *
 *  - Authority ordering (doc "Authority"): in conflict the winner is
 *    Tese central > Kernel > este doc > spec individual. resolveAuthority()
 *    returns the higher-ranked source between two contenders.
 *  - Golden rule / Dreyfus-first (doc "Regra de ouro" + anti-pattern
 *    "Pular Fase 1"): no other phase may START before Dreyfus Dynamic Pedagogy
 *    (Multiplier Edge Fase 1) is delivered. gatePhaseStart() blocks any phase
 *    other than Dreyfus while Dreyfus is not done.
 *  - Definition Of Done (doc "Definition Of Done por fase", 8 gates): a phase
 *    only leaves `scaffold` when ALL 8 documented gates hold. gateDefinitionOfDone()
 *    caps on the full set and lists the missing gates.
 *  - Status taxonomy (frontmatter `maintenance`): the five documented statuses.
 *    A status outside the set is reported unknown rather than guessed.
 *  - Contested/speculative rule (decisions + the anti-pattern that forbids
 *    promoting a contested/speculative capability without positive validation):
 *    C11/C12-class capabilities may only become DEFAULT after a positive
 *    validation. gatePromoteToDefault() holds them until validation passes.
 *  - AP status read model (doc "Continuidade"): AP -> status -> phase, with the
 *    documented partial/read-model distinction.
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 *
 * @see docs/engineering-knowledge-base/cognitive/roadmap.md
 */
final class AtlasCognitiveRoadmapService
{
    public const SCHEMA_VERSION = 'atlas.cognitive.roadmap.v1';

    /**
     * Authority chain, highest first (doc "Authority": Tese central > Kernel >
     * este doc > spec individual). Index 0 is the strongest authority.
     *
     * @var list<string>
     */
    public const AUTHORITY_CHAIN = [
        'tese_central',
        'kernel',
        'roadmap_doc',
        'spec_individual',
    ];

    /**
     * Documented phase-status taxonomy (frontmatter `maintenance`). Any status
     * outside this set is treated as unknown.
     *
     * @var list<string>
     */
    public const STATUSES = [
        'scaffold',
        'implemented_operational_read_model',
        'implemented_runtime',
        'implemented_surface_integrated',
        'implemented_self_improving',
    ];

    /**
     * The phase id of the golden-rule gate. Dreyfus must ship before anything
     * else (doc "Regra de ouro").
     */
    public const DREYFUS_PHASE = 'dreyfus_dynamic_pedagogy';

    /**
     * Capability maturity classes that may not become default without a positive
     * validation (frontmatter decisions + anti-pattern). C11/C12 carry these.
     *
     * @var list<string>
     */
    public const VALIDATION_GATED_CLASSES = [
        'contested',
        'speculative',
    ];

    /**
     * The eight Definition-Of-Done gates a phase must satisfy to leave
     * `scaffold` (doc "Definition Of Done por fase"), in documented order.
     *
     * @var list<string>
     */
    public const DOD_GATES = [
        'has_ap',                 // 1. Tem AP correspondente em docs/ap/AP-###-cognitive-*.md
        'code_migration_tests',   // 2. Codigo + migration + tests passam localmente
        'architecture_validate',  // 3. atlas:ai:architecture-validate --json verde
        'docs_health',            // 4. docs-health verde
        'cli_or_api_e2e',         // 5. >= 1 fluxo CLI ou API end-to-end demonstra a capability
        'evidence_events',        // 6. Evidence Ledger emite os events declarados
        'slo_targets_measured',   // 7. SLO targets declarados sao mensurados
        'doc_points_to_ap',       // 8. Doc da capability aponta para o AP e marca status exato
    ];

    /**
     * AP status read model (doc "Continuidade"). status uses the documented
     * vocabulary; `partial` flags the three explicitly `implemented_partial` APs.
     *
     * @var array<string,array{ap:string,capability:string,status:string,phase:string,partial:bool}>
     */
    private const AP_STATUS = [
        'ap_163' => ['ap' => 'AP-163', 'capability' => 'Dreyfus Dynamic Pedagogy', 'status' => 'implemented_operational_read_model', 'phase' => 'Multiplier Edge Fase 1', 'partial' => false],
        'ap_164' => ['ap' => 'AP-164', 'capability' => 'Worked Example Engine + Process Fading Scheduler', 'status' => 'implemented_operational_read_model', 'phase' => 'C13 Core', 'partial' => false],
        'ap_165' => ['ap' => 'AP-165', 'capability' => 'Process Pattern Catalog', 'status' => 'implemented_operational_read_model', 'phase' => 'C13 Core', 'partial' => false],
        'ap_166' => ['ap' => 'AP-166', 'capability' => 'Failure Signature Classifier + Bayesian Tracker', 'status' => 'implemented_operational_read_model', 'phase' => 'C13 Core', 'partial' => false],
        'ap_167' => ['ap' => 'AP-167', 'capability' => 'Self-Regulated Learning Orchestrator', 'status' => 'implemented_operational_read_model', 'phase' => 'C13 Core', 'partial' => false],
        'ap_168' => ['ap' => 'AP-168', 'capability' => 'Productive Failure Flow', 'status' => 'implemented_partial', 'phase' => 'C13/C14 bridge', 'partial' => true],
        'ap_169' => ['ap' => 'AP-169', 'capability' => 'Personal Worked Examples Generator', 'status' => 'implemented_partial', 'phase' => 'Multiplier Edge 2-bis', 'partial' => true],
        'ap_170' => ['ap' => 'AP-170', 'capability' => 'Predictive Failure Insertion', 'status' => 'implemented_partial', 'phase' => 'Multiplier Edge 4-bis', 'partial' => true],
    ];

    /**
     * Resolve which of two conflicting sources wins per the documented Authority
     * chain (Tese central > Kernel > este doc > spec individual). An unknown
     * source is treated as the weakest and never wins by default.
     *
     * @return array{
     *   schema_version:string,
     *   left:string,
     *   right:string,
     *   left_known:bool,
     *   right_known:bool,
     *   winner:?string,
     *   reason:string
     * }
     */
    public function resolveAuthority(string $left, string $right): array
    {
        $l = strtolower(trim($left));
        $r = strtolower(trim($right));

        $leftRank = array_search($l, self::AUTHORITY_CHAIN, true);
        $rightRank = array_search($r, self::AUTHORITY_CHAIN, true);

        $leftKnown = $leftRank !== false;
        $rightKnown = $rightRank !== false;

        // Unknown sources rank below every known authority.
        $leftRankN = $leftKnown ? (int) $leftRank : PHP_INT_MAX;
        $rightRankN = $rightKnown ? (int) $rightRank : PHP_INT_MAX;

        if (! $leftKnown && ! $rightKnown) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'left' => $l,
                'right' => $r,
                'left_known' => false,
                'right_known' => false,
                'winner' => null,
                'reason' => 'both_sources_outside_authority_chain',
            ];
        }

        // Lower index = higher authority.
        if ($leftRankN === $rightRankN) {
            $winner = $l;
            $reason = 'same_authority_no_conflict';
        } elseif ($leftRankN < $rightRankN) {
            $winner = $l;
            $reason = 'higher_authority_in_chain';
        } else {
            $winner = $r;
            $reason = 'higher_authority_in_chain';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'left' => $l,
            'right' => $r,
            'left_known' => $leftKnown,
            'right_known' => $rightKnown,
            'winner' => $winner,
            'reason' => $reason,
        ];
    }

    /**
     * Golden rule (doc "Regra de ouro" + anti-pattern "Pular Fase 1"): no phase
     * other than Dreyfus may START before Dreyfus Dynamic Pedagogy is delivered.
     * Starting Dreyfus itself is always allowed. Starting anything else while
     * Dreyfus is not done is blocked.
     *
     * @return array{
     *   schema_version:string,
     *   phase:string,
     *   is_dreyfus:bool,
     *   dreyfus_done:bool,
     *   allowed:bool,
     *   reason:string
     * }
     */
    public function gatePhaseStart(string $phaseId, bool $dreyfusDone): array
    {
        $phase = strtolower(trim($phaseId));
        $isDreyfus = $phase === self::DREYFUS_PHASE;

        if ($isDreyfus) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'phase' => $phase,
                'is_dreyfus' => true,
                'dreyfus_done' => $dreyfusDone,
                'allowed' => true,
                'reason' => 'dreyfus_is_the_first_phase',
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'phase' => $phase,
            'is_dreyfus' => false,
            'dreyfus_done' => $dreyfusDone,
            'allowed' => $dreyfusDone,
            'reason' => $dreyfusDone
                ? 'dreyfus_delivered_other_phases_unblocked'
                : 'golden_rule_dreyfus_must_ship_first',
        ];
    }

    /**
     * Definition Of Done (doc, 8 gates): a phase only leaves `scaffold` when ALL
     * eight documented gates hold. Returns the satisfied count, the missing
     * gates and the effective status (stays `scaffold` until complete).
     *
     * @param  array<string,bool>  $gates  subset of DOD_GATES keys -> satisfied
     * @return array{
     *   schema_version:string,
     *   total_gates:int,
     *   satisfied:int,
     *   missing:list<string>,
     *   done:bool,
     *   effective_status:string,
     *   reason:?string
     * }
     */
    public function gateDefinitionOfDone(array $gates): array
    {
        $missing = [];
        $satisfied = 0;

        foreach (self::DOD_GATES as $gate) {
            if (($gates[$gate] ?? false) === true) {
                $satisfied++;
            } else {
                $missing[] = $gate;
            }
        }

        $done = $missing === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'total_gates' => count(self::DOD_GATES),
            'satisfied' => $satisfied,
            'missing' => $missing,
            'done' => $done,
            // Until every DoD gate is green the phase stays scaffold.
            'effective_status' => $done ? 'implemented_operational_read_model' : 'scaffold',
            'reason' => $done ? null : 'definition_of_done_incomplete',
        ];
    }

    /**
     * Contested/speculative promotion rule (frontmatter decisions + the
     * anti-pattern that forbids promoting a contested/speculative capability
     * without positive validation): a capability whose maturity class is
     * contested or speculative may only become DEFAULT after a positive
     * validation. Stable capabilities promote freely.
     *
     * @return array{
     *   schema_version:string,
     *   capability:string,
     *   maturity_class:string,
     *   validation_gated:bool,
     *   validation_passed:bool,
     *   promote_to_default:bool,
     *   reason:string
     * }
     */
    public function gatePromoteToDefault(
        string $capability,
        string $maturityClass,
        bool $validationPassed = false,
    ): array {
        $cap = trim($capability);
        $class = strtolower(trim($maturityClass));
        $gated = in_array($class, self::VALIDATION_GATED_CLASSES, true);

        if (! $gated) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'capability' => $cap,
                'maturity_class' => $class,
                'validation_gated' => false,
                'validation_passed' => $validationPassed,
                'promote_to_default' => true,
                'reason' => 'stable_capability_no_validation_gate',
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'capability' => $cap,
            'maturity_class' => $class,
            'validation_gated' => true,
            'validation_passed' => $validationPassed,
            'promote_to_default' => $validationPassed,
            'reason' => $validationPassed
                ? 'validation_positive_promotion_allowed'
                : 'contested_or_speculative_requires_positive_validation',
        ];
    }

    /**
     * AP status read model (doc "Continuidade"). Each row carries the documented
     * status, phase and whether the AP is an explicitly partial implementation.
     *
     * @return array{
     *   schema_version:string,
     *   aps:list<array{id:string,ap:string,capability:string,status:string,phase:string,partial:bool,status_known:bool}>
     * }
     */
    public function apStatus(): array
    {
        $rows = [];
        foreach (self::AP_STATUS as $id => $row) {
            // implemented_partial is a documented operational state but is not
            // part of the canonical phase-status taxonomy; flag known-ness too.
            $rows[] = $row + [
                'id' => $id,
                'status_known' => in_array($row['status'], self::STATUSES, true),
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'aps' => array_values(array_map(static function (array $r): array {
                return [
                    'id' => $r['id'],
                    'ap' => $r['ap'],
                    'capability' => $r['capability'],
                    'status' => $r['status'],
                    'phase' => $r['phase'],
                    'partial' => $r['partial'],
                    'status_known' => $r['status_known'],
                ];
            }, $rows)),
        ];
    }

    /**
     * Primary entry point: produce the full cognitive-roadmap governance
     * snapshot used by the command and as a single source of the doc's contract.
     *
     * @return array{
     *   schema_version:string,
     *   authority_chain:list<string>,
     *   statuses:list<string>,
     *   dod_gates:list<string>,
     *   ap_status:array<string,mixed>,
     *   authority_example:array<string,mixed>,
     *   golden_rule_example:array<string,mixed>,
     *   dod_example:array<string,mixed>,
     *   promotion_example:array<string,mixed>
     * }
     */
    public function snapshot(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'authority_chain' => self::AUTHORITY_CHAIN,
            'statuses' => self::STATUSES,
            'dod_gates' => self::DOD_GATES,
            'ap_status' => $this->apStatus(),
            // Worked example: the roadmap doc loses to the Kernel in conflict.
            'authority_example' => $this->resolveAuthority('roadmap_doc', 'kernel'),
            // Worked example: starting Latticework before Dreyfus is delivered is blocked.
            'golden_rule_example' => $this->gatePhaseStart('cross_domain_latticework', false),
            // Worked example: a phase missing two DoD gates stays scaffold.
            'dod_example' => $this->gateDefinitionOfDone([
                'has_ap' => true,
                'code_migration_tests' => true,
                'architecture_validate' => true,
                'docs_health' => true,
                'cli_or_api_e2e' => true,
                'evidence_events' => false,
                'slo_targets_measured' => false,
                'doc_points_to_ap' => true,
            ]),
            // Worked example: a contested capability cannot become default without validation.
            'promotion_example' => $this->gatePromoteToDefault('dual_n_back', 'contested', false),
        ];
    }
}
