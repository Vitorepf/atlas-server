<?php

namespace App\Services\Ai\Programming\Sdd\Compilers;

use App\Services\Ai\Programming\Sdd\Pipeline\ContextPack;

/**
 * Compiles a Plan from an approved spec + ContextPack.
 *
 * Plan schema mirrors plan-task-and-receipt-contract.md:103-113:
 *   target_files, forbidden_files, hot_file_ownership,
 *   technical_approach, test_plan, rollback_plan
 */
class PlanCompiler
{
    /**
     * @param  array<string,mixed>  $spec
     * @return array<string,mixed>
     */
    public function compile(array $spec, ContextPack $context): array
    {
        $likely = $this->stringList($spec['likely_files'] ?? []);
        $stack = $context->stack;

        $plan = [
            'schema_version' => 'atlas.sdd_plan.v1',
            'spec_objective' => (string) ($spec['objective'] ?? ''),
            'target_files' => $likely,
            'forbidden_files' => $this->resolveForbiddenFiles($likely, $context),
            'hot_file_ownership' => $this->resolveHotFileOwnership($likely),
            'technical_approach' => $this->resolveTechnicalApproach($spec, $stack),
            'test_plan' => $this->resolveTestPlan($spec),
            'rollback_plan' => $this->resolveRollbackPlan($spec),
            'context_digest' => $context->digest,
        ];
        $plan['content_hash'] = hash(
            'sha256',
            json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
        );

        return $plan;
    }

    /**
     * @param  list<string>  $likely
     * @return list<string>
     */
    private function resolveForbiddenFiles(array $likely, ContextPack $context): array
    {
        $forbidden = [
            'app/Models/AtlasUser.php',
            'config/atlas.php',
            'database/migrations/*_baseline_*.php',
        ];
        if (in_array('atlas.risk.high.v1', $context->packages, true)) {
            $forbidden[] = 'database/migrations/*';
        }

        return array_values(array_unique(array_diff($forbidden, $likely)));
    }

    /**
     * @param  list<string>  $likely
     * @return array<string,string>
     */
    private function resolveHotFileOwnership(array $likely): array
    {
        $ownership = [];
        foreach ($likely as $file) {
            $ownership[$file] = $this->ownerForPath($file);
        }

        return $ownership;
    }

    private function ownerForPath(string $file): string
    {
        return match (true) {
            str_starts_with($file, 'app/Console/Commands/') => 'cli',
            str_starts_with($file, 'app/Http/Controllers/') => 'api',
            str_starts_with($file, 'app/Models/') => 'model',
            str_starts_with($file, 'app/Services/') => 'service',
            str_starts_with($file, 'database/migrations/') => 'database',
            str_starts_with($file, 'docs/') => 'documentation',
            str_starts_with($file, 'tests/') => 'test',
            default => 'misc',
        };
    }

    /**
     * @param  array<string,mixed>  $spec
     * @return array<string,mixed>
     */
    private function resolveTechnicalApproach(array $spec, string $stack): array
    {
        return [
            'stack' => $stack,
            'expected_behavior' => (string) ($spec['expected_behavior'] ?? ''),
            'inputs_outputs' => (string) ($spec['inputs_outputs'] ?? ''),
            'risks' => $this->stringList($spec['risks'] ?? []),
        ];
    }

    /**
     * @param  array<string,mixed>  $spec
     * @return list<array<string,string>>
     */
    private function resolveTestPlan(array $spec): array
    {
        $tests = [];
        foreach ($this->stringList($spec['tests'] ?? []) as $test) {
            $tests[] = [
                'kind' => str_contains(strtolower($test), 'phpunit') ? 'phpunit' : 'cli_check',
                'command' => $test,
            ];
        }

        return $tests;
    }

    /**
     * @param  array<string,mixed>  $spec
     * @return array<string,mixed>
     */
    private function resolveRollbackPlan(array $spec): array
    {
        return [
            'description' => (string) ($spec['rollback'] ?? 'Revert commit and rerun the validation suite.'),
            'evidence_required' => $this->stringList($spec['evidence_required'] ?? []),
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn ($v): bool => is_string($v) && trim($v) !== '',
        ));
    }
}
