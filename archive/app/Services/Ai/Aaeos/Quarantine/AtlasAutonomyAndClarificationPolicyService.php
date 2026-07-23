<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Runtime for the Atlas SDD Autonomy And Clarification Policy doc.
 *
 * Turns the doc's three load-bearing contracts into pure, deterministic decision
 * logic (no IO, no clock, no DB). Same input -> same output. The doc is the
 * authoring boundary; this code never escalates autonomy silently and never lets
 * an unsafe request act.
 *
 *   1. The Ask / Act / Block gate. The doc lists explicit conditions for each
 *      verdict. Precedence is safety-first and fixed: BLOCK dominates ASK, ASK
 *      dominates ACT. Atlas may ACT only when every documented "Act when"
 *      condition holds AND no Ask/Block trigger fires. -> evaluateRequest()
 *
 *   2. The Autonomy Levels table L0..L5, each with its documented meaning and
 *      intended use. -> levels(), levelFor()
 *
 *   3. The risk -> recommended autonomy level mapping read straight from the
 *      table's "Use" column (critical/unclear risk -> L0; medium/high -> L1;
 *      low-risk localized change -> L2; low/medium with gates -> L3). A higher
 *      level is never recommended than the risk band allows. -> recommendAutonomyLevel()
 *
 * @see docs/engineering-knowledge-base/spec-operating-system/autonomy-and-clarification-policy.md
 */
final class AtlasAutonomyAndClarificationPolicyService
{
    /** Stable evidence schema id this runtime emits. */
    public const SCHEMA_VERSION = 'atlas.sdd_autonomy_clarification_policy.v1';

    /** Closed verdict set for the Ask / Act / Block gate. */
    public const DECISION_ACT = 'act';
    public const DECISION_ASK = 'ask';
    public const DECISION_BLOCK = 'block';

    /** Ordered autonomy ladder L0..L5 (doc -> "Autonomy Levels"), low -> high. */
    public const LEVELS = ['L0', 'L1', 'L2', 'L3', 'L4', 'L5'];

    /** Accepted risk bands (lower-cased, the doc's risk vocabulary). */
    public const RISK_LOW = 'low';
    public const RISK_MEDIUM = 'medium';
    public const RISK_HIGH = 'high';
    public const RISK_CRITICAL = 'critical';
    public const RISK_UNCLEAR = 'unclear';

    /**
     * The Autonomy Levels table, verbatim intent from the doc body.
     *
     * @var array<string, array{rank: int, key: string, meaning: string, use: string}>
     */
    private const LEVEL_SPEC = [
        'L0' => ['rank' => 0, 'key' => 'manual', 'meaning' => 'Spec/plan only', 'use' => 'critical systems, unclear risk'],
        'L1' => ['rank' => 1, 'key' => 'assisted', 'meaning' => 'Human approves implementation', 'use' => 'medium/high risk'],
        'L2' => ['rank' => 2, 'key' => 'auto_patch', 'meaning' => 'Atlas creates scoped patch', 'use' => 'low-risk localized change'],
        'L3' => ['rank' => 3, 'key' => 'auto_pr', 'meaning' => 'Atlas opens PR with evidence', 'use' => 'low/medium risk with gates'],
        'L4' => ['rank' => 4, 'key' => 'restricted_merge', 'meaning' => 'Future only, very low-risk', 'use' => 'docs/copy/tests after metrics'],
        'L5' => ['rank' => 5, 'key' => 'proposal_only_learning', 'meaning' => 'Improve templates/policy by proposal', 'use' => 'self-improvement'],
    ];

    /**
     * The four documented BLOCK conditions (doc -> "Block when"). Each maps a
     * boolean signal on the request to the doc's exact reason text.
     *
     * @var array<string, string>
     */
    private const BLOCK_CONDITIONS = [
        'bypasses_validation_or_security' => 'request bypasses validation/security',
        'modifies_critical_policy_or_runtime_without_ap' => 'asks to modify critical policy/runtime without AP',
        'demands_hardcoded_behavior_against_architecture' => 'demands hardcoded behavior against architecture',
        'destructive_without_explicit_approval' => 'requires destructive action without explicit approval',
    ];

    /**
     * The five documented ASK conditions (doc -> "Ask when").
     *
     * @var array<string, string>
     */
    private const ASK_CONDITIONS = [
        'target_screen_or_file_unknown' => 'target screen/file is unknown',
        'business_object_ambiguous' => 'business object is ambiguous',
        'security_or_permission_rule_unclear' => 'security/permission rule is unclear',
        'design_system_conflicts_with_user_wording' => 'design system conflicts with user wording',
        'multiple_valid_interpretations' => 'multiple valid interpretations exist',
    ];

    /**
     * The five documented ACT preconditions (doc -> "Act when"). ALL must hold
     * for Atlas to act. Keyed by the request signal; value is the doc text.
     *
     * @var array<string, string>
     */
    private const ACT_CONDITIONS = [
        'target_context_known' => 'target context is known',
        'business_object_clear' => 'business object is clear',
        'design_api_rules_known' => 'design/API rules are known',
        'risk_low_or_medium' => 'risk is low/medium',
        'gates_can_run' => 'gates can run',
    ];

    /**
     * Decide whether Atlas may Act, must Ask, or must Block a request.
     *
     * Precedence is fixed and safety-first: any BLOCK trigger -> block; else any
     * ASK trigger -> ask; else ACT only if every "Act when" precondition holds.
     * If no block/ask fires but an act precondition is missing, the gate falls
     * back to ASK (it never silently acts on an unmet precondition).
     *
     * @param  array<string, mixed>  $signals  truthy flags keyed by the condition ids above
     * @return array{
     *     schema_version: string,
     *     decision: string,
     *     reasons: list<string>,
     *     triggered: list<string>,
     *     unmet_act_conditions: list<string>,
     *     may_act: bool
     * }
     */
    public function evaluateRequest(array $signals): array
    {
        $blockTriggers = $this->triggeredReasons($signals, self::BLOCK_CONDITIONS);
        if ($blockTriggers !== []) {
            return $this->verdict(self::DECISION_BLOCK, $blockTriggers, []);
        }

        $askTriggers = $this->triggeredReasons($signals, self::ASK_CONDITIONS);

        // Every "Act when" precondition must be explicitly satisfied.
        $unmetActIds = [];
        $unmetActReasons = [];
        foreach (self::ACT_CONDITIONS as $id => $text) {
            if (! $this->truthy($signals, $id)) {
                $unmetActIds[] = $id;
                $unmetActReasons[] = 'act precondition not satisfied: '.$text;
            }
        }

        if ($askTriggers !== []) {
            return $this->verdict(self::DECISION_ASK, $askTriggers, $unmetActIds);
        }

        if ($unmetActReasons !== []) {
            return $this->verdict(self::DECISION_ASK, $unmetActReasons, $unmetActIds);
        }

        return $this->verdict(self::DECISION_ACT, ['all act preconditions satisfied; no ask/block trigger'], []);
    }

    /**
     * The full Autonomy Levels table L0..L5, ordered low -> high.
     *
     * @return array{schema_version: string, count: int, levels: list<array{level: string, rank: int, key: string, meaning: string, use: string}>}
     */
    public function levels(): array
    {
        $rows = [];
        foreach (self::LEVELS as $level) {
            $spec = self::LEVEL_SPEC[$level];
            $rows[] = [
                'level' => $level,
                'rank' => $spec['rank'],
                'key' => $spec['key'],
                'meaning' => $spec['meaning'],
                'use' => $spec['use'],
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'count' => count($rows),
            'levels' => $rows,
        ];
    }

    /**
     * Look up one autonomy level's documented contract.
     *
     * @return array{level: string, known: bool, rank: int|null, key: string|null, meaning: string|null, use: string|null}
     */
    public function levelFor(string $level): array
    {
        $level = strtoupper(trim($level));
        if (! array_key_exists($level, self::LEVEL_SPEC)) {
            return ['level' => $level, 'known' => false, 'rank' => null, 'key' => null, 'meaning' => null, 'use' => null];
        }
        $spec = self::LEVEL_SPEC[$level];

        return [
            'level' => $level,
            'known' => true,
            'rank' => $spec['rank'],
            'key' => $spec['key'],
            'meaning' => $spec['meaning'],
            'use' => $spec['use'],
        ];
    }

    /**
     * Recommend the autonomy level for a risk band, straight from the table's
     * "Use" column. Gates only matter for the low/medium band: with gates the
     * ceiling is L3 (auto_pr with evidence), without gates it is L2 (scoped
     * patch). Critical/unclear risk always collapses to L0 (spec/plan only).
     *
     * @return array{
     *     schema_version: string,
     *     risk: string,
     *     risk_known: bool,
     *     gates_available: bool,
     *     recommended_level: string,
     *     rank: int,
     *     reason: string
     * }
     */
    public function recommendAutonomyLevel(string $risk, bool $gatesAvailable = false): array
    {
        $risk = strtolower(trim($risk));

        [$level, $reason, $known] = match ($risk) {
            self::RISK_CRITICAL, self::RISK_UNCLEAR => ['L0', 'critical/unclear risk -> spec/plan only', true],
            self::RISK_HIGH => ['L1', 'high risk -> human approves implementation', true],
            self::RISK_MEDIUM => $gatesAvailable
                ? ['L3', 'medium risk with gates -> Atlas opens PR with evidence', true]
                : ['L1', 'medium risk without gates -> human approves implementation', true],
            self::RISK_LOW => $gatesAvailable
                ? ['L3', 'low risk with gates -> Atlas opens PR with evidence', true]
                : ['L2', 'low-risk localized change -> Atlas creates scoped patch', true],
            // Unknown risk band is treated as unclear: collapse to the safest level.
            default => ['L0', 'unknown risk band treated as unclear -> spec/plan only', false],
        };

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'risk' => $risk,
            'risk_known' => $known,
            'gates_available' => $gatesAvailable,
            'recommended_level' => $level,
            'rank' => self::LEVEL_SPEC[$level]['rank'],
            'reason' => $reason,
        ];
    }

    /**
     * Governance snapshot: the level table plus the documented gate conditions.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'levels' => $this->levels()['levels'],
            'block_when' => array_values(self::BLOCK_CONDITIONS),
            'ask_when' => array_values(self::ASK_CONDITIONS),
            'act_when' => array_values(self::ACT_CONDITIONS),
            'precedence' => 'block > ask > act',
        ];
    }

    /**
     * @param  array<string, mixed>  $signals
     * @param  array<string, string>  $conditions
     * @return list<string>
     */
    private function triggeredReasons(array $signals, array $conditions): array
    {
        $reasons = [];
        foreach ($conditions as $id => $text) {
            if ($this->truthy($signals, $id)) {
                $reasons[] = $text;
            }
        }

        return $reasons;
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function truthy(array $signals, string $key): bool
    {
        return array_key_exists($key, $signals) && $signals[$key] === true;
    }

    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $unmetActIds
     * @return array{schema_version: string, decision: string, reasons: list<string>, triggered: list<string>, unmet_act_conditions: list<string>, may_act: bool}
     */
    private function verdict(string $decision, array $reasons, array $unmetActIds): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'decision' => $decision,
            'reasons' => array_values($reasons),
            'triggered' => array_values($reasons),
            'unmet_act_conditions' => array_values($unmetActIds),
            'may_act' => $decision === self::DECISION_ACT,
        ];
    }
}
