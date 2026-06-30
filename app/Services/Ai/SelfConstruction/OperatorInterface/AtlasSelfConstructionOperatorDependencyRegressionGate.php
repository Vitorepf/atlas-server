<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\OperatorInterface;

/**
 * Operator-Interface DEPENDENCY-REGRESSION gate. Detects plans that would turn the operator's
 * VISIBILITY / REVIEW / EMERGENCY-ONLY controls into a steady-state CONSTRUCTION REQUIREMENT — i.e.
 * make autonomous Self-Construction depend on per-cycle operator approval.
 *
 * INPUT (a proposed plan as an array — typically a roadmap entry or experiment plan):
 *   { plan_id, title?, description?, fields:array<string,mixed> }
 *
 * BLOCKER FAMILIES (any match ⇒ blocking_fact emitted):
 *   - dependency_regression:requires_operator_approval         — field/text mentions 'operator approval' / 'await human' / 'human in the loop' as steady state
 *   - dependency_regression:cycle_requires_operator_review     — text mentions 'each cycle' + 'operator review' / 'manual review'
 *   - dependency_regression:emergency_button_as_normal_path    — text mentions 'emergency stop' / 'kill switch' as required PER-PLAN button
 *
 * OUTPUT:
 *   { schema, allowed:bool, blocking_facts:list<{kind, field, snippet}> }
 *
 * INVARIANTS:
 *   - DETERMINISTIC envelope (blocking_facts sorted by kind+field).
 *   - NO scalar score.
 *   - PURE.
 */
final class AtlasSelfConstructionOperatorDependencyRegressionGate
{
    public const SCHEMA = 'atlas.operator_interface.dependency_regression_gate.v1';

    /** Pattern => blocker kind. */
    public const PATTERN_TO_KIND = [
        '/requires?\s+operator\s+approval|await\s+human|human[- ]?in[- ]?the[- ]?loop|operator\s+must\s+approve|manual\s+sign[- ]?off|sign[- ]?off\s+required|wait(?:ing)?\s+for\s+operator|human\s+confirmation|approval\s+button|approve\s+button/i' => 'dependency_regression:requires_operator_approval',
        '/(each|every|per)\s+cycle.*operator\s+review|manual\s+review\s+per\s+cycle/i' => 'dependency_regression:cycle_requires_operator_review',
        '/emergency\s+stop|kill\s+switch|emergency\s+button/i' => 'dependency_regression:emergency_button_as_normal_path',
        '/requires?\s+(?:claude\s+code|codex|cursor|gemini)|(?:claude\s+code|codex|cursor)\s+(?:reviews?|edits?|approves?)|depends?\s+on\s+(?:an?\s+)?external\s+(?:ai\s+)?(?:assistant|provider)/i' => 'dependency_regression:external_assistant_dependency',
    ];

    /**
     * @param  array{plan_id?:string, title?:string, description?:string, fields?:array<string,mixed>}  $plan
     * @return array{schema:string, allowed:bool, blocking_facts:list<array{kind:string, field:string, snippet:string}>}
     */
    public function evaluate(array $plan): array
    {
        $blocking = [];

        $searchable = [
            'title' => (string) ($plan['title'] ?? ''),
            'description' => (string) ($plan['description'] ?? ''),
        ];
        foreach ((array) ($plan['fields'] ?? []) as $k => $v) {
            $searchable['fields.'.(string) $k] = is_scalar($v) ? (string) $v : (string) json_encode($v);
        }

        foreach ($searchable as $field => $text) {
            if ($text === '') {
                continue;
            }
            foreach (self::PATTERN_TO_KIND as $pattern => $kind) {
                // 'emergency_stop' / 'kill switch' are LEGITIMATE escape hatches — only flag them when the
                // text positions them as a STEADY-STATE per-plan button rather than a one-off escape.
                if ($kind === 'dependency_regression:emergency_button_as_normal_path'
                    && ! preg_match('/per[- ]?plan|every\s+plan|each\s+plan|required\s+for\s+each|button|control/i', $text)) {
                    continue;
                }
                if (preg_match($pattern, $text, $m)) {
                    $blocking[] = ['kind' => $kind, 'field' => $field, 'snippet' => $this->snippet($text, (string) $m[0])];
                    break; // one blocker per field is enough
                }
            }
        }

        usort($blocking, static fn (array $a, array $b): int => strcmp($a['kind'].'|'.$a['field'], $b['kind'].'|'.$b['field']));

        return [
            'schema' => self::SCHEMA,
            'allowed' => $blocking === [],
            'blocking_facts' => $blocking,
        ];
    }

    private function snippet(string $text, string $match): string
    {
        $pos = stripos($text, $match);
        if ($pos === false) {
            return $match;
        }
        $start = max(0, $pos - 20);
        $excerpt = substr($text, $start, min(120, strlen($text) - $start));

        return trim($excerpt);
    }
}
