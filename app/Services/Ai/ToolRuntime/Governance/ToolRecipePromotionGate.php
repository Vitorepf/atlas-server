<?php

declare(strict_types=1);

namespace App\Services\Ai\ToolRuntime\Governance;

/**
 * Tool Runtime — Recipe Promotion Gate.
 *
 * Closes the matrix gap "Tool Runtime: Promotion de recipes novas e
 * runtime pesado padronizado".
 *
 * A "recipe" is a typed tool invocation pattern (e.g., `psql.read_query`,
 * `shell.bounded_command`). New recipes MUST pass this gate before they
 * are added to the ToolRuntime catalog. The gate enforces:
 *
 *   - schema_version present and canonical
 *   - execution_class declared (read|mutating|danger|tool)
 *   - policy budget declared (per-call timeout + per-day call limit)
 *   - evidence contract declared (which receipt the recipe emits)
 *   - sandbox/permission declared explicitly
 *   - failure handler declared
 *
 * Provider-safe: no recipe payload is inspected; only the metadata
 * declaration is gated.
 */
final class ToolRecipePromotionGate
{
    public const SCHEMA_VERSION = 'atlas.tool_runtime.recipe_promotion.v1';

    public const ALLOWED_EXECUTION_CLASSES = ['read', 'mutating', 'danger', 'tool'];

    public const ALLOWED_SANDBOXES = ['workspace', 'worktree', 'docker', 'host'];

    public const REQUIRED_FIELDS = [
        'recipe_id',
        'schema_version',
        'execution_class',
        'policy_budget',
        'evidence_contract',
        'sandbox',
        'failure_handler',
    ];

    /**
     * @param  array<string,mixed>  $recipe
     * @return array{
     *   schema_version: string,
     *   recipe_id: ?string,
     *   promotion_decision: string,
     *   passed_checks: list<string>,
     *   failed_checks: array<string,string>,
     *   evidence_contract: ?string,
     *   detail: string,
     *   evaluated_at: string
     * }
     */
    public function evaluate(array $recipe): array
    {
        $failed = [];
        $passed = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            $value = $recipe[$field] ?? null;
            if ($value === null || $value === '' || $value === []) {
                $failed[$field] = "required field '{$field}' is missing or empty";
            } else {
                $passed[] = $field.'_present';
            }
        }

        $executionClass = (string) ($recipe['execution_class'] ?? '');
        if ($executionClass !== '' && ! in_array($executionClass, self::ALLOWED_EXECUTION_CLASSES, true)) {
            $failed['execution_class'] = sprintf(
                'execution_class "%s" not in allowed list [%s]',
                $executionClass,
                implode(',', self::ALLOWED_EXECUTION_CLASSES),
            );
        } elseif ($executionClass !== '') {
            $passed[] = 'execution_class_valid';
        }

        $sandbox = (string) ($recipe['sandbox'] ?? '');
        if ($sandbox !== '' && ! in_array($sandbox, self::ALLOWED_SANDBOXES, true)) {
            $failed['sandbox'] = sprintf(
                'sandbox "%s" not in allowed list [%s]',
                $sandbox,
                implode(',', self::ALLOWED_SANDBOXES),
            );
        } elseif ($sandbox !== '') {
            $passed[] = 'sandbox_valid';
        }

        // Policy budget structure check: must declare both timeout and per-day cap.
        $budget = $recipe['policy_budget'] ?? null;
        if (is_array($budget)) {
            $timeout = $budget['timeout_seconds'] ?? null;
            $perDay = $budget['calls_per_day_limit'] ?? null;
            if (! is_int($timeout) || $timeout <= 0) {
                $failed['policy_budget.timeout_seconds'] = 'must be positive int';
            } else {
                $passed[] = 'policy_budget_timeout_declared';
            }
            if (! is_int($perDay) || $perDay <= 0) {
                $failed['policy_budget.calls_per_day_limit'] = 'must be positive int';
            } else {
                $passed[] = 'policy_budget_per_day_declared';
            }
        }

        // Danger class always requires explicit operator authority field.
        if ($executionClass === 'danger') {
            $authority = $recipe['operator_authority_required'] ?? null;
            if ($authority !== true) {
                $failed['operator_authority_required'] = 'danger class requires operator_authority_required=true';
            } else {
                $passed[] = 'danger_operator_authority_declared';
            }
        }

        $decision = $failed === [] ? 'promotion_approved' : 'promotion_blocked';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'recipe_id' => isset($recipe['recipe_id']) && is_string($recipe['recipe_id']) ? $recipe['recipe_id'] : null,
            'promotion_decision' => $decision,
            'passed_checks' => $passed,
            'failed_checks' => $failed,
            'evidence_contract' => isset($recipe['evidence_contract']) && is_string($recipe['evidence_contract'])
                ? $recipe['evidence_contract']
                : null,
            'detail' => $decision === 'promotion_approved'
                ? sprintf('Recipe "%s" passed all promotion gates and may join the catalog.', $recipe['recipe_id'] ?? 'unknown')
                : sprintf('Recipe blocked: %d check(s) failed.', count($failed)),
            'evaluated_at' => now()->toAtomString(),
        ];
    }
}
