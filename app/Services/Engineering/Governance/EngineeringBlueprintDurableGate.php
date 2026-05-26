<?php

declare(strict_types=1);

namespace App\Services\Engineering\Governance;

/**
 * Engineering Blueprint — Durable Execution Gate.
 *
 * Closes the matrix gap "Engineering Blueprint / Harness: Durable
 * execution enterprise e worker runtime pesado ainda precisam
 * fechamento". Mirrors the Programming Harness durable execution pattern
 * (`DurableExecutionPreflight`) but for engineering-grade blueprints
 * (specs, ADRs, gate cascades, replay) instead of programming work items.
 *
 * Engineering blueprints declare canonical engineering work that the
 * harness orchestrates over hours-to-days. The gate checks that a
 * blueprint is durable-ready BEFORE the worker picks it up.
 */
final class EngineeringBlueprintDurableGate
{
    public const SCHEMA_VERSION = 'atlas.engineering.blueprint_durable_gate.v1';

    public const REQUIRED_FIELDS = [
        'blueprint_id',
        'schema_version',
        'spec_hash',
        'gate_cascade',
        'replay_contract',
        'budget',
    ];

    public const ALLOWED_TIERS = ['standard', 'enterprise', 'critical'];

    /**
     * @param  array<string,mixed>  $blueprint
     * @return array{
     *   schema_version: string,
     *   blueprint_id: ?string,
     *   may_execute_durable: bool,
     *   tier: ?string,
     *   passed_checks: list<string>,
     *   failed_checks: array<string,string>,
     *   detail: string,
     *   evaluated_at: string
     * }
     */
    public function evaluate(array $blueprint): array
    {
        $passed = [];
        $failed = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            $value = $blueprint[$field] ?? null;
            if ($value === null || $value === '' || $value === []) {
                $failed[$field] = "required field '{$field}' missing";
            } else {
                $passed[] = $field.'_present';
            }
        }

        $tier = (string) ($blueprint['tier'] ?? 'standard');
        if (! in_array($tier, self::ALLOWED_TIERS, true)) {
            $failed['tier'] = sprintf('invalid tier "%s"; must be one of [%s]', $tier, implode(',', self::ALLOWED_TIERS));
        } else {
            $passed[] = 'tier_valid';
        }

        $cascade = (array) ($blueprint['gate_cascade'] ?? []);
        if (count($cascade) < 3) {
            $failed['gate_cascade'] = sprintf('cascade must declare >= 3 gates; got %d', count($cascade));
        } else {
            $passed[] = 'gate_cascade_min_threshold';
        }

        $budget = $blueprint['budget'] ?? null;
        if (is_array($budget)) {
            $duration = $budget['max_duration_hours'] ?? null;
            $attempts = $budget['max_repair_attempts'] ?? null;
            if (! is_int($duration) || $duration <= 0 || $duration > 168) {
                $failed['budget.max_duration_hours'] = 'must be 1..168 hours (1 week max)';
            } else {
                $passed[] = 'budget_duration_declared';
            }
            if (! is_int($attempts) || $attempts < 0 || $attempts > 10) {
                $failed['budget.max_repair_attempts'] = 'must be 0..10';
            } else {
                $passed[] = 'budget_attempts_declared';
            }
        }

        // Enterprise tier additional requirement: explicit security_scan in cascade.
        if ($tier === 'enterprise' || $tier === 'critical') {
            if (! in_array('security_scan', $cascade, true)) {
                $failed['gate_cascade.security_scan'] = sprintf('tier "%s" requires security_scan gate in cascade', $tier);
            } else {
                $passed[] = 'security_scan_required_for_tier';
            }
        }

        // Critical tier additional requirement: explicit operator_review gate.
        if ($tier === 'critical' && ! in_array('operator_review', $cascade, true)) {
            $failed['gate_cascade.operator_review'] = 'critical tier requires operator_review gate';
        } elseif ($tier === 'critical') {
            $passed[] = 'operator_review_required_for_critical';
        }

        $mayExecute = $failed === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'blueprint_id' => isset($blueprint['blueprint_id']) && is_string($blueprint['blueprint_id']) ? $blueprint['blueprint_id'] : null,
            'may_execute_durable' => $mayExecute,
            'tier' => $tier,
            'passed_checks' => $passed,
            'failed_checks' => $failed,
            'detail' => $mayExecute
                ? sprintf('Blueprint "%s" cleared for durable execution at tier "%s".', $blueprint['blueprint_id'] ?? 'unknown', $tier)
                : sprintf('Blueprint blocked: %d check(s) failed.', count($failed)),
            'evaluated_at' => now()->toAtomString(),
        ];
    }
}
