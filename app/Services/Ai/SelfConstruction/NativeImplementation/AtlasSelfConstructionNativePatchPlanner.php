<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NativeImplementation;

use RuntimeException;

/**
 * Symbolic native patch planner — converts a SELF-SUFFICIENT task packet + available context facts
 * into a patch plan: {target_files, template_ids, variables, required_imports, test_plan, risk_notes}.
 *
 * INPUT:
 *   $packet  — { allowed_files:list<string>, task_shape:array, scope_files:list<string>,
 *                provider_reasoning_required?:bool, context:{namespace:string, ...vars} }
 *
 * REJECTIONS (throw RuntimeException):
 *   - scope_files outside allowed_files
 *   - task_shape has no matching template
 *   - provider_reasoning_required=true (planner refuses free-form reasoning)
 *
 * OUTPUT:
 *   { schema, plan_id, target_files, template_ids, variables, required_imports, test_plan, risk_notes }
 *
 * INVARIANTS:
 *   - DETERMINISTIC plan_id = sha256(canonical {allowed_files, template_ids, variables}).
 *   - PURE.
 */
final class AtlasSelfConstructionNativePatchPlanner
{
    public const SCHEMA = 'atlas.native_implementation.patch_plan.v1';

    public function __construct(private readonly AtlasSelfConstructionNativePatchTemplateLibrary $library = new AtlasSelfConstructionNativePatchTemplateLibrary) {}

    /**
     * @param  array{
     *     allowed_files?:list<string>,
     *     task_shape?:array<string,mixed>,
     *     scope_files?:list<string>,
     *     provider_reasoning_required?:bool,
     *     context?:array<string,string>,
     *     test_files?:list<string>,
     *     objective?:string,
     *     acceptance_criteria?:list<string>,
     *     required_evidence?:list<string>,
     *     implementation_target?:string,
     *     forbidden_files?:list<string>,
     *     candidate_template_ids?:list<string>
     * }  $packet
     * @return array{schema:string, plan_id:string, target_files:list<string>, template_ids:list<string>, variables:array<string,string>, required_imports:list<string>, test_plan:array<string,mixed>, risk_notes:list<string>, minimal_template_reason:?string}
     */
    public function plan(array $packet): array
    {
        // ── Reject packets missing required fields ────────────────────────────

        $objective = trim((string) ($packet['objective'] ?? ''));
        if ($objective === '') {
            throw new RuntimeException('planner refuses: missing_objective');
        }

        if ((bool) ($packet['provider_reasoning_required'] ?? false)) {
            throw new RuntimeException('planner refuses: provider_reasoning_required=true');
        }

        $allowed = is_array($packet['allowed_files'] ?? null) ? array_values(array_map('strval', $packet['allowed_files'])) : [];
        if ($allowed === []) {
            throw new RuntimeException('planner refuses: allowed_files empty');
        }

        $acceptanceCriteria = is_array($packet['acceptance_criteria'] ?? null) ? array_values(array_map('strval', $packet['acceptance_criteria'])) : [];
        $hasRunnableAcceptance = false;
        foreach ($acceptanceCriteria as $ac) {
            if (preg_match('/(test|gate|assert|exit\s+0|runnable)/i', $ac) === 1) {
                $hasRunnableAcceptance = true;
            }
        }
        if (! $hasRunnableAcceptance) {
            throw new RuntimeException('planner refuses: missing_runnable_acceptance');
        }

        $requiredEvidence = is_array($packet['required_evidence'] ?? null) ? array_values(array_map('strval', $packet['required_evidence'])) : [];
        if ($requiredEvidence === []) {
            throw new RuntimeException('planner refuses: missing_required_evidence');
        }

        $implementationTarget = trim((string) ($packet['implementation_target'] ?? ''));
        if ($implementationTarget === '') {
            throw new RuntimeException('planner refuses: missing_implementation_target');
        }

        // ── Scope validation ──────────────────────────────────────────────────

        $scope = is_array($packet['scope_files'] ?? null) ? array_values(array_map('strval', $packet['scope_files'])) : [];
        $forbidden = is_array($packet['forbidden_files'] ?? null) ? array_values(array_map('strval', $packet['forbidden_files'])) : [];

        foreach ($scope as $f) {
            if (! in_array($f, $allowed, true)) {
                throw new RuntimeException('planner refuses: scope_file_outside_allowed:'.$f);
            }
            if (in_array($f, $forbidden, true)) {
                throw new RuntimeException('planner refuses: forbidden_path:'.$f);
            }
        }

        // Refuse broad scope: more than 10 allowed files is too broad for a native patch.
        if (count($allowed) > 10) {
            throw new RuntimeException('planner refuses: broad_scope:'.count($allowed).'_allowed_files');
        }

        // Refuse test-only scope: if ALL allowed files are test files, the plan
        // has no production target.
        $allTestFiles = true;
        foreach ($allowed as $f) {
            if (! str_contains($f, 'Test.php') && ! str_contains($f, 'tests/')) {
                $allTestFiles = false;
                break;
            }
        }
        if ($allTestFiles && count($allowed) > 0) {
            throw new RuntimeException('planner refuses: test_only_scope');
        }

        $taskShape = is_array($packet['task_shape'] ?? null) ? $packet['task_shape'] : [];
        if ((bool) ($taskShape['speculative_abstraction'] ?? false)) {
            throw new RuntimeException('planner refuses: speculative_abstraction_in_task_shape');
        }

        $explicitCandidates = is_array($packet['candidate_template_ids'] ?? null)
            ? array_values(array_map('strval', $packet['candidate_template_ids']))
            : null;
        $matches = $explicitCandidates ?? $this->library->supports($taskShape)['matches'];
        if ($matches === []) {
            throw new RuntimeException('planner refuses: no_matching_template_for_task_shape');
        }
        // Prefer minimal plan: fewest required_imports.
        usort($matches, fn (string $a, string $b): int => count($this->deriveImports($a)) <=> count($this->deriveImports($b)));
        $primary = $matches[0];

        $context = is_array($packet['context'] ?? null) ? array_map('strval', $packet['context']) : [];
        $testFiles = is_array($packet['test_files'] ?? null) ? array_values(array_map('strval', $packet['test_files'])) : [];

        // Synthesize a test file when the objective or required_evidence
        // indicates test generation is required and no test file exists yet.
        $testGenRequired = preg_match('/test|Test/', $objective) === 1
            && in_array('tests_or_gates_result', $requiredEvidence, true)
            && $testFiles === [];
        if ($testGenRequired) {
            $inferredPath = $this->inferTestPath($implementationTarget, $allowed);
            if ($inferredPath !== null) {
                $testFiles[] = $inferredPath;
            }
        }

        // minimal_template_reason: explain why the selected template beats the next candidate.
        $minimalTemplateReason = null;
        if (count($matches) > 1) {
            $winnerImports = count($this->deriveImports($primary));
            $minimalTemplateReason = 'fewest_imports:'.$primary.'='.$winnerImports;
        }

        $variables = $context;
        $templateIds = [$primary];
        $requiredImports = $this->deriveImports($primary);
        $testPlan = [
            'test_files' => $testFiles,
            'gates' => ['phpunit'],
        ];
        $riskNotes = $this->riskNotesFor($primary, $allowed);

        // missing_test_plan risk note: test files must exist and be within allowed scope.
        $hasAllowedTestPath = $testFiles !== [] && array_intersect($testFiles, $allowed) !== [];
        if (! $hasAllowedTestPath) {
            $riskNotes[] = 'missing_test_plan';
        }

        $canonical = [
            'allowed_files' => $allowed,
            'template_ids' => $templateIds,
            'variables' => $variables,
        ];
        ksort($canonical);
        $planId = hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return [
            'schema' => self::SCHEMA,
            'plan_id' => $planId,
            'target_files' => $this->mergeTargetFiles($scope !== [] ? $scope : $allowed, $testFiles),
            'template_ids' => $templateIds,
            'variables' => $variables,
            'required_imports' => $requiredImports,
            'test_plan' => $testPlan,
            'risk_notes' => $riskNotes,
            'minimal_template_reason' => $minimalTemplateReason,
        ];
    }

    /**
     * Infer the test file path from an implementation target and allowed scope.
     *
     * Converts app/Models/Foo.php → tests/Unit/Models/FooTest.php.
     * Only returns a path when the test file does not already exist in allowed.
     *
     * @param  list<string>  $allowed
     */
    private function inferTestPath(string $implementationTarget, array $allowed): ?string
    {
        if ($implementationTarget === '' || ! str_starts_with($implementationTarget, 'app/')) {
            return null;
        }
        // app/Path/To/Class.php → tests/Unit/Path/To/ClassTest.php
        $relative = substr($implementationTarget, 4); // remove 'app/'
        if (! str_ends_with($relative, '.php')) {
            return null;
        }
        $base = substr($relative, 0, -4); // remove '.php'
        $inferred = 'tests/Unit/'.$base.'Test.php';

        // Don't infer a test that's already in the allowed set.
        if (in_array($inferred, $allowed, true)) {
            return null;
        }

        return $inferred;
    }

    /**
     * @param  list<string>  $targetFiles
     * @param  list<string>  $testFiles
     * @return list<string>
     */
    private function mergeTargetFiles(array $targetFiles, array $testFiles): array
    {
        $merged = array_values(array_unique(array_merge($targetFiles, $testFiles)));
        sort($merged, SORT_STRING);

        return $merged;
    }

    /**
     * @return list<string>
     */
    private function deriveImports(string $templateId): array
    {
        return match ($templateId) {
            'cli_wrapper' => ['Illuminate\\Console\\Command'],
            'unit_test_scaffold' => ['PHPUnit\\Framework\\TestCase'],
            default => [],
        };
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function riskNotesFor(string $templateId, array $allowed): array
    {
        $notes = [];
        if ($templateId === 'cli_wrapper') {
            $notes[] = 'cli_wrapper:keep_handler_thin';
        }
        if (count($allowed) > 5) {
            $notes[] = 'large_allowed_files_set:'.count($allowed);
        }

        return $notes;
    }
}
