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
     *     test_files?:list<string>
     * }  $packet
     * @return array{schema:string, plan_id:string, target_files:list<string>, template_ids:list<string>, variables:array<string,string>, required_imports:list<string>, test_plan:array<string,mixed>, risk_notes:list<string>}
     */
    public function plan(array $packet): array
    {
        if ((bool) ($packet['provider_reasoning_required'] ?? false)) {
            throw new RuntimeException('planner refuses: provider_reasoning_required=true');
        }
        $allowed = is_array($packet['allowed_files'] ?? null) ? array_values(array_map('strval', $packet['allowed_files'])) : [];
        $scope = is_array($packet['scope_files'] ?? null) ? array_values(array_map('strval', $packet['scope_files'])) : [];
        if ($allowed === []) {
            throw new RuntimeException('planner refuses: allowed_files empty');
        }
        $forbidden = is_array($packet['forbidden_files'] ?? null) ? array_values(array_map('strval', $packet['forbidden_files'])) : [];
        foreach ($scope as $f) {
            if (! in_array($f, $allowed, true)) {
                throw new RuntimeException('planner refuses: scope_file_outside_allowed:'.$f);
            }
            if (in_array($f, $forbidden, true)) {
                throw new RuntimeException('planner refuses: forbidden_path:'.$f);
            }
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

        $variables = $context;
        $templateIds = [$primary];
        $requiredImports = $this->deriveImports($primary);
        $testPlan = [
            'test_files' => $testFiles,
            'gates' => ['phpunit'],
        ];
        $riskNotes = $this->riskNotesFor($primary, $allowed);

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
            'target_files' => $scope !== [] ? $scope : $allowed,
            'template_ids' => $templateIds,
            'variables' => $variables,
            'required_imports' => $requiredImports,
            'test_plan' => $testPlan,
            'risk_notes' => $riskNotes,
        ];
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
