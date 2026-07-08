<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas AI Autonomy And Power Backlog — runtime.
 *
 * Turns the governed power-backlog doc into deterministic, pure decision logic.
 * The doc is explicitly "a governed long-term power queue, NOT an immediate
 * implementation commitment", so this service enforces the governance the
 * backlog imposes on every autonomy idea instead of inventing loose superpowers
 * outside the Kernel.
 *
 * Concrete contracts implemented (from the doc body):
 *
 *  - Central permission rule (the autonomy gate). The doc states verbatim:
 *      "Detectar sozinho: permitido em shadow mode.
 *       Planejar sozinho: permitido com evidence.
 *       Testar em sandbox: permitido com gates.
 *       Alterar producao, dinheiro, privacidade ou sistema critico:
 *         somente com Decision Receipt + approval."
 *    {@see classifyAction()} maps an action kind to exactly this autonomy
 *    posture and never lets a mutating/money/privacy/critical action run
 *    autonomously.
 *
 *  - Ordered backlog + per-item gate. The "Backlog Ordenado" table lists seven
 *    items, each with an order, a candidate status and a hard gate that must be
 *    satisfied before implementation. {@see backlog()} is that read model, and
 *    {@see itemReadyToImplement()} enforces "an item may not be implemented
 *    until its documented gate is satisfied".
 *
 *  - Dynamic Compute Market report reason. Item 1 says the broker is a
 *    "read-only recommendation" that must NOT switch provider when the user
 *    passed a model manually, and must report the reason as one of
 *    `best_allowed`, `best_available`, `manual_override`.
 *    {@see computeMarketDecision()} encodes exactly that.
 *
 *  - Promotion Gate. "Um item so sai deste backlog quando tiver: AP ou ADR
 *    dedicada; owner; safety boundary; expected Evidence Ledger events;
 *    tests/scanner; rollback; doc canonica atualizada." {@see evaluatePromotion()}
 *    requires all seven and refuses promotion otherwise.
 *
 * Stateless and DB-free: every method is a pure function of its arguments. The
 * service NEVER mutates production, calls a provider, touches money/privacy or
 * writes the database — it only classifies, gates and reports.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-autonomy-power-backlog.md
 */
final class AtlasAiAutonomyPowerBacklogService
{
    public const SCHEMA_VERSION = 'atlas.ai.autonomy_power_backlog.v1';

    /** Autonomy postures (closed set) derived from the central permission rule. */
    public const POSTURE_SHADOW = 'shadow_autonomous';      // detect: allowed in shadow mode
    public const POSTURE_EVIDENCE = 'autonomous_with_evidence'; // plan: allowed with evidence
    public const POSTURE_GATED_SANDBOX = 'sandbox_with_gates';  // test: allowed with gates
    public const POSTURE_APPROVAL = 'approval_required';     // mutate prod/money/privacy/critical

    /**
     * Action kind -> autonomy posture, copied from the doc's central rule block.
     * Anything classified as POSTURE_APPROVAL may never run autonomously: it
     * needs a Decision Receipt + Inbox approval.
     *
     * @var array<string,string>
     */
    private const ACTION_POSTURE = [
        // "Detectar sozinho: permitido em shadow mode."
        'detect' => self::POSTURE_SHADOW,
        'observe' => self::POSTURE_SHADOW,
        // "Planejar sozinho: permitido com evidence."
        'plan' => self::POSTURE_EVIDENCE,
        'propose' => self::POSTURE_EVIDENCE,
        // "Testar em sandbox: permitido com gates."
        'sandbox_test' => self::POSTURE_GATED_SANDBOX,
        'simulate' => self::POSTURE_GATED_SANDBOX,
        // "Alterar producao, dinheiro, privacidade ou sistema critico:
        //  somente com Decision Receipt + approval."
        'mutate_production' => self::POSTURE_APPROVAL,
        'spend_money' => self::POSTURE_APPROVAL,
        'access_privacy' => self::POSTURE_APPROVAL,
        'mutate_critical_system' => self::POSTURE_APPROVAL,
        'change_calendar' => self::POSTURE_APPROVAL,
        'change_infrastructure' => self::POSTURE_APPROVAL,
    ];

    /**
     * The "Backlog Ordenado" table verbatim: order -> item, status and the hard
     * gate that must be satisfied before implementation.
     *
     * @var array<string,array{order:int,item:string,status:string,gate:string}>
     */
    private const BACKLOG = [
        'dynamic_compute_market' => [
            'order' => 1,
            'item' => 'Dynamic Compute Market',
            'status' => 'candidate_high',
            'gate' => 'AP-99 populado com dados confiaveis',
        ],
        'tool_synthesis' => [
            'order' => 2,
            'item' => 'Tool Synthesis',
            'status' => 'candidate_high',
            'gate' => 'Super Tool Runtime registry + sandbox + security gate',
        ],
        'zero_click_shadow_mode' => [
            'order' => 3,
            'item' => 'Zero-Click Shadow Mode',
            'status' => 'candidate_high',
            'gate' => 'Observers read-only + Inbox approval + no mutation',
        ],
        'real_world_feedback_loop' => [
            'order' => 4,
            'item' => 'Real-World Feedback Loop',
            'status' => 'candidate_medium_high',
            'gate' => 'Domain gates, rollback, budget e approval',
        ],
        'scenario_simulation_harness' => [
            'order' => 5,
            'item' => 'Scenario Simulation Harness',
            'status' => 'candidate_medium_high',
            'gate' => 'GraphRAG, outcome tracking, calibration metrics',
        ],
        'multimodal_continuous_context' => [
            'order' => 6,
            'item' => 'Contexto Multimodal Continuo',
            'status' => 'candidate_medium',
            'gate' => 'Opt-in, privacy, redaction, provider-safe storage',
        ],
        'cross_domain_heuristic_transfer' => [
            'order' => 7,
            'item' => 'Cross-Domain Heuristic Transfer',
            'status' => 'candidate_long',
            'gate' => 'Memory quality, taxonomy e evidence forte por dominio',
        ],
    ];

    /**
     * The seven requirements an item must satisfy to LEAVE the backlog
     * (the "Promotion Gate" section), in documented order.
     *
     * @var list<string>
     */
    public const PROMOTION_REQUIREMENTS = [
        'dedicated_ap_or_adr',
        'owner',
        'safety_boundary',
        'expected_evidence_ledger_events',
        'tests_or_scanner',
        'rollback',
        'canonical_doc_updated',
    ];

    /** Dynamic Compute Market report reasons (closed set, from item 1). */
    public const MARKET_BEST_ALLOWED = 'best_allowed';
    public const MARKET_BEST_AVAILABLE = 'best_available';
    public const MARKET_MANUAL_OVERRIDE = 'manual_override';

    /**
     * Classify a candidate autonomous action against the doc's central
     * permission rule.
     *
     * An unknown / unrecognized action is treated as the most restrictive
     * posture (approval required) so a novel power can never leak past the gate
     * by being unnamed.
     *
     * @return array{
     *   schema_version:string,
     *   action:string,
     *   action_known:bool,
     *   posture:string,
     *   may_run_autonomously:bool,
     *   requires_decision_receipt:bool,
     *   requires_approval:bool,
     *   requires_evidence:bool,
     *   requires_gates:bool,
     *   reason:string
     * }
     */
    public function classifyAction(string $action): array
    {
        $key = strtolower(trim($action));
        $known = $key !== '' && array_key_exists($key, self::ACTION_POSTURE);
        $posture = $known ? self::ACTION_POSTURE[$key] : self::POSTURE_APPROVAL;

        $requiresApproval = $posture === self::POSTURE_APPROVAL;

        $reason = match (true) {
            ! $known => 'unknown_action_defaults_to_approval',
            $posture === self::POSTURE_SHADOW => 'detect_allowed_in_shadow_mode',
            $posture === self::POSTURE_EVIDENCE => 'plan_allowed_with_evidence',
            $posture === self::POSTURE_GATED_SANDBOX => 'sandbox_allowed_with_gates',
            default => 'mutation_requires_decision_receipt_and_approval',
        };

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'action' => $key,
            'action_known' => $known,
            'posture' => $posture,
            // Only non-mutating postures may proceed without explicit approval.
            'may_run_autonomously' => ! $requiresApproval,
            'requires_decision_receipt' => $requiresApproval,
            'requires_approval' => $requiresApproval,
            'requires_evidence' => $posture === self::POSTURE_EVIDENCE,
            'requires_gates' => $posture === self::POSTURE_GATED_SANDBOX,
            'reason' => $reason,
        ];
    }

    /**
     * The ordered backlog read model (the "Backlog Ordenado" table), sorted by
     * documented order.
     *
     * @return array{schema_version:string,count:int,items:list<array{id:string,order:int,item:string,status:string,gate:string}>}
     */
    public function backlog(): array
    {
        $items = [];
        foreach (self::BACKLOG as $id => $row) {
            $items[] = [
                'id' => $id,
                'order' => $row['order'],
                'item' => $row['item'],
                'status' => $row['status'],
                'gate' => $row['gate'],
            ];
        }

        usort($items, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'count' => count($items),
            'items' => $items,
        ];
    }

    /**
     * Decide whether a backlog item may be implemented now. The doc binds each
     * item to a hard gate ("Gate antes de implementar"); the item stays a
     * candidate until that gate is satisfied. An unknown item id is refused.
     *
     * @return array{
     *   schema_version:string,
     *   id:string,
     *   known:bool,
     *   ready:bool,
     *   item:?string,
     *   order:?int,
     *   status:?string,
     *   gate:?string,
     *   reason:string
     * }
     */
    public function itemReadyToImplement(string $id, bool $gateSatisfied): array
    {
        $key = strtolower(trim($id));
        $row = self::BACKLOG[$key] ?? null;

        if ($row === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'id' => $key,
                'known' => false,
                'ready' => false,
                'item' => null,
                'order' => null,
                'status' => null,
                'gate' => null,
                'reason' => 'unknown_backlog_item',
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'id' => $key,
            'known' => true,
            'ready' => $gateSatisfied,
            'item' => $row['item'],
            'order' => $row['order'],
            'status' => $row['status'],
            'gate' => $row['gate'],
            'reason' => $gateSatisfied
                ? 'gate_satisfied_may_implement'
                : 'gate_not_satisfied_remains_candidate',
        ];
    }

    /**
     * Dynamic Compute Market (item 1) recommendation reason.
     *
     * Contract from the doc:
     *  - "sem trocar provider quando usuario passou modelo manual" — a manual
     *    model override is honoured and the broker does NOT switch provider;
     *  - otherwise report whether the recommendation is the best *allowed* by
     *    policy/budget, or the best *available* if no policy restriction binds.
     *
     * @param  ?string  $manualModel    a model the operator pinned by hand, if any
     * @param  ?string  $bestAvailable  empirically best provider/model for the task
     * @param  ?string  $bestAllowed    best provider/model permitted by policy/budget
     * @return array{
     *   schema_version:string,
     *   recommended_model:?string,
     *   reason:string,
     *   switched_provider:bool,
     *   read_only:bool
     * }
     */
    public function computeMarketDecision(
        ?string $manualModel = null,
        ?string $bestAvailable = null,
        ?string $bestAllowed = null,
    ): array {
        $manual = is_string($manualModel) && trim($manualModel) !== '' ? trim($manualModel) : null;
        $available = is_string($bestAvailable) && trim($bestAvailable) !== '' ? trim($bestAvailable) : null;
        $allowed = is_string($bestAllowed) && trim($bestAllowed) !== '' ? trim($bestAllowed) : null;

        // Manual override wins and is never overridden by the broker.
        if ($manual !== null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'recommended_model' => $manual,
                'reason' => self::MARKET_MANUAL_OVERRIDE,
                'switched_provider' => false,
                'read_only' => true,
            ];
        }

        // Policy/budget restriction binds -> recommend the best *allowed*.
        if ($allowed !== null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'recommended_model' => $allowed,
                'reason' => self::MARKET_BEST_ALLOWED,
                'switched_provider' => $available !== null && $allowed !== $available,
                'read_only' => true,
            ];
        }

        // No restriction -> recommend the best *available*.
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'recommended_model' => $available,
            'reason' => self::MARKET_BEST_AVAILABLE,
            'switched_provider' => false,
            'read_only' => true,
        ];
    }

    /**
     * Evaluate the Promotion Gate: an item may only LEAVE the backlog when all
     * seven documented requirements are present. Any missing requirement blocks
     * promotion and is reported.
     *
     * @param  array<string,bool>  $signals  requirement key -> satisfied
     * @return array{
     *   schema_version:string,
     *   promotable:bool,
     *   satisfied:list<string>,
     *   missing:list<string>,
     *   reason:?string
     * }
     */
    public function evaluatePromotion(array $signals): array
    {
        $satisfied = [];
        $missing = [];
        foreach (self::PROMOTION_REQUIREMENTS as $requirement) {
            if (($signals[$requirement] ?? false) === true) {
                $satisfied[] = $requirement;
            } else {
                $missing[] = $requirement;
            }
        }

        $promotable = $missing === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'promotable' => $promotable,
            'satisfied' => $satisfied,
            'missing' => $missing,
            'reason' => $promotable
                ? null
                : 'promotion_gate_requires_all_requirements',
        ];
    }

    /**
     * Primary entry point: produce the full autonomy-power-backlog governance
     * snapshot used by the command and as a single source of the doc's contract.
     *
     * @return array{
     *   schema_version:string,
     *   permission_rule:array<string,string>,
     *   backlog:array<string,mixed>,
     *   promotion_requirements:list<string>,
     *   action_example:array<string,mixed>,
     *   market_example:array<string,mixed>,
     *   promotion_example:array<string,mixed>
     * }
     */
    public function snapshot(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            // The central permission rule as a posture-per-stage map.
            'permission_rule' => [
                'detect' => self::POSTURE_SHADOW,
                'plan' => self::POSTURE_EVIDENCE,
                'sandbox_test' => self::POSTURE_GATED_SANDBOX,
                'mutate_production' => self::POSTURE_APPROVAL,
            ],
            'backlog' => $this->backlog(),
            'promotion_requirements' => self::PROMOTION_REQUIREMENTS,
            // Worked example: mutating production is never autonomous.
            'action_example' => $this->classifyAction('mutate_production'),
            // Worked example: a manual model pin is honoured, no provider switch.
            'market_example' => $this->computeMarketDecision('claude-opus', 'gemini-pro', 'claude-sonnet'),
            // Worked example: a half-filled promotion is blocked.
            'promotion_example' => $this->evaluatePromotion([
                'dedicated_ap_or_adr' => true,
                'owner' => true,
                'safety_boundary' => true,
            ]),
        ];
    }
}
