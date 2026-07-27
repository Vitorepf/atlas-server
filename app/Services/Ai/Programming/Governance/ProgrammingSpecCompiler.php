<?php

namespace App\Services\Ai\Programming\Governance;

use App\Models\AtlasProgrammingWorkItem;
use App\Services\Ai\Kernel\Architecture\AtlasFeaturePlacementService;
use App\Services\Ai\Programming\Sdd\Compilers\SpecCritic;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use Throwable;

/**
 * Template-driven spec compiler.
 *
 * Synthesizes a draft spec from the existing intake signals + placement + code
 * intelligence summary. Does NOT call any LLM — the compiler only assembles
 * machine-readable scaffolding that a human or downstream agent can refine
 * before attaching.
 *
 * Output schema mirrors the canonical spec fields enforced by
 * `ProgrammingSpecBeforeCodeGate` so a compiled spec is gate-ready.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
 */
class ProgrammingSpecCompiler
{
    public function __construct(
        private readonly AtlasFeaturePlacementService $placement,
        private readonly EngineeringCodeIntelligenceService $codeIntelligence,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function compile(AtlasProgrammingWorkItem $workItem): array
    {
        $intent = trim($workItem->intent_text);
        $signals = (array) data_get($workItem->metadata_json, 'classification_signals', []);
        $placement = $this->resolvePlacement($workItem);
        $ciSummary = $this->resolveCodeIntelligenceSummary();

        $compiled = [
            'objective' => $this->objective($intent),
            'context' => $this->context($intent, $placement, $ciSummary),
            'expected_behavior' => $this->expectedBehavior($intent, $workItem->intent_type),
            'likely_files' => $this->likelyFiles($placement),
            'inputs_outputs' => $this->inputsOutputs($workItem->intent_type),
            'risks' => $this->risks($workItem->risk_level, $signals),
            'tests' => $this->tests($workItem->intent_type),
            'evidence_required' => $this->evidenceRequired($workItem->scope_mode),
            'rollback' => $this->rollback($workItem->scope_mode),
            'completion_criteria' => $this->completionCriteria($workItem->scope_mode),
        ];

        return [
            'schema_version' => 'atlas.programming.spec_compiled.v1',
            'compiled_from' => [
                'intent_type' => $workItem->intent_type,
                'scope_mode' => $workItem->scope_mode,
                'risk_level' => $workItem->risk_level,
                'placement_used' => $placement['placement_used'],
                'code_intelligence_status' => $ciSummary['status'] ?? 'unknown',
            ],
            'spec' => $compiled,
        ];
    }

    /**
     * @param  array<string,mixed>  $compiled
     * @return array<string,mixed>
     */
    public function critique(array $compiled): array
    {
        $spec = (array) ($compiled['spec'] ?? $compiled);
        $issues = [];

        foreach ([
            'objective', 'context', 'expected_behavior', 'rollback',
        ] as $field) {
            $value = $spec[$field] ?? null;
            if (! is_string($value) || strlen(trim($value)) < 16) {
                $issues[] = ['field' => $field, 'severity' => 'high', 'reason' => 'too_short_or_missing'];
            }
        }

        foreach (['likely_files', 'risks', 'tests', 'evidence_required', 'completion_criteria'] as $field) {
            $value = $spec[$field] ?? [];
            if (! is_array($value) || $value === []) {
                $issues[] = ['field' => $field, 'severity' => 'high', 'reason' => 'empty_list'];
            }
        }

        $vagueWords = SpecCritic::VAGUE_WORDS;
        $objective = strtolower((string) ($spec['objective'] ?? ''));
        foreach ($vagueWords as $word) {
            if (str_contains($objective, $word)) {
                $issues[] = ['field' => 'objective', 'severity' => 'medium', 'reason' => "vague_word:{$word}"];
            }
        }

        if (! $this->hasMeasurableCriterion($spec['completion_criteria'] ?? [])) {
            $issues[] = ['field' => 'completion_criteria', 'severity' => 'medium', 'reason' => 'no_measurable_criterion'];
        }

        $blocking = array_values(array_filter(
            $issues,
            static fn (array $issue): bool => ($issue['severity'] ?? 'low') === 'high',
        ));

        return [
            'schema_version' => 'atlas.programming.spec_critic.v1',
            'status' => $issues === [] ? 'clean' : ($blocking !== [] ? 'rejected' : 'warnings_only'),
            'issues' => $issues,
            'blocking_issues' => $blocking,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function resolvePlacement(AtlasProgrammingWorkItem $workItem): array
    {
        $cached = (array) $workItem->placement_json;
        if ($cached !== [] && ! empty($cached['placement'] ?? null)) {
            return [
                'placement_used' => 'cached_on_work_item',
                'payload' => $cached,
            ];
        }

        try {
            $payload = $this->placement->place($workItem->intent_text, []);

            return [
                'placement_used' => 'placed_now',
                'payload' => $payload,
            ];
        } catch (Throwable $e) {
            return [
                'placement_used' => 'unavailable',
                'payload' => ['error' => $e::class.':'.$e->getMessage()],
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveCodeIntelligenceSummary(): array
    {
        try {
            return $this->codeIntelligence->summary();
        } catch (Throwable $e) {
            return ['status' => 'unavailable', 'error' => $e::class.':'.$e->getMessage()];
        }
    }

    private function objective(string $intent): string
    {
        return $intent !== '' ? $intent : 'Specify the objective of this work item.';
    }

    /**
     * @param  array<string,mixed>  $placement
     * @param  array<string,mixed>  $ciSummary
     */
    private function context(string $intent, array $placement, array $ciSummary): string
    {
        $layer = (string) data_get($placement, 'payload.placement.layer', '');
        $domain = (string) data_get($placement, 'payload.placement.domain', '');
        $moduleCount = (int) ($ciSummary['module_count'] ?? 0);
        $bits = ['Intent: '.$intent];
        if ($layer !== '' || $domain !== '') {
            $bits[] = "Placement: layer={$layer}, domain={$domain}";
        }
        $bits[] = "Code Intelligence status: {$ciSummary['status']} ({$moduleCount} modules indexed).";

        return implode(' ', $bits);
    }

    private function expectedBehavior(string $intent, string $intentType): string
    {
        return match ($intentType) {
            'bugfix' => 'Defective behavior described in the intent stops happening; existing happy paths remain unchanged.',
            'feature' => 'New capability described in the intent is callable and exercised by tests.',
            'refactor' => 'Public behavior is preserved; internal structure matches the intent without API drift.',
            'docs' => 'Canonical docs reflect the intent; docs-health passes.',
            'migration' => 'Schema/data change applies idempotently; existing reads/writes keep working.',
            'test' => 'New tests cover the scenarios in the intent and pass against current code.',
            'architecture' => 'Architecture change in the intent is reflected in the canonical index, code intelligence, and tests.',
            'cartography' => 'Cartography artifact in the intent is published or refreshed without breaking consumers.',
            'self_construction' => 'Self-construction step in the intent runs end-to-end with evidence and respects all governance gates.',
            default => 'Behavior described in the intent is observable and tests document it.',
        };
    }

    /**
     * @param  array<string,mixed>  $placement
     * @return list<string>
     */
    private function likelyFiles(array $placement): array
    {
        $owners = (array) data_get($placement, 'payload.owner_docs', []);
        $files = [];
        foreach ($owners as $owner) {
            $path = is_array($owner) ? ($owner['path'] ?? null) : null;
            if (is_string($path) && $path !== '') {
                $files[] = $path;
            }
        }
        if ($files === []) {
            $files[] = '(unknown — fill in before attaching the spec)';
        }

        return array_values(array_unique($files));
    }

    private function inputsOutputs(string $intentType): string
    {
        return match ($intentType) {
            'feature' => 'Inputs: caller invocation. Outputs: documented response/state change.',
            'bugfix' => 'Inputs: reproduction case. Outputs: corrected behavior + regression test.',
            'migration' => 'Inputs: migration command. Outputs: schema delta + idempotency guarantee.',
            default => 'Inputs and outputs to be declared by the implementer before execution.',
        };
    }

    /**
     * @param  array<string,mixed>  $signals
     * @return list<string>
     */
    private function risks(string $riskLevel, array $signals): array
    {
        $risks = [];
        if ($riskLevel === 'high' || $riskLevel === 'critical') {
            $risks[] = 'High-impact change: schedule review and postgres gate when applicable.';
        }
        $matches = (array) ($signals['high_risk_matches'] ?? []);
        foreach ($matches as $match) {
            if (is_string($match)) {
                $risks[] = "High-risk signal in intent: '{$match}'.";
            }
        }
        if ($risks === []) {
            $risks[] = 'Verify regression coverage in adjacent modules before completion.';
        }

        return $risks;
    }

    /**
     * @return list<string>
     */
    private function tests(string $intentType): array
    {
        return match ($intentType) {
            'bugfix' => ['vendor/bin/phpunit (regression test for the original failure)'],
            'feature' => ['vendor/bin/phpunit (feature test exercising the new capability)'],
            'refactor' => ['vendor/bin/phpunit (existing suite must stay green)'],
            'migration' => ['php artisan migrate --env=testing', 'vendor/bin/phpunit (post-migration suite)'],
            'docs' => ['php artisan atlas:engineering:knowledge docs-health --json'],
            default => ['vendor/bin/phpunit (proportional coverage)'],
        };
    }

    /**
     * @return list<string>
     */
    private function evidenceRequired(string $scopeMode): array
    {
        $base = ['phpunit_green'];
        if ($scopeMode === ProgrammingScopeMode::Structural->value) {
            $base[] = 'docs_health_ok';
            $base[] = 'engineering_knowledge_index_code_clean';
        }

        return $base;
    }

    private function rollback(string $scopeMode): string
    {
        return $scopeMode === ProgrammingScopeMode::Structural->value
            ? 'Revert commit, run migrations down if applicable, re-run docs-health and the validation suite.'
            : 'Revert commit and re-run the validation suite.';
    }

    /**
     * @return list<string>
     */
    private function completionCriteria(string $scopeMode): array
    {
        $base = [
            'All required programming governance gates green.',
            'Validation tests pass.',
        ];
        if ($scopeMode === ProgrammingScopeMode::Structural->value) {
            $base[] = 'docs-health, sync and index-code re-run after the change.';
            $base[] = 'Cartography gap resolved or explicitly recorded.';
        }

        return $base;
    }

    /**
     * @param  mixed  $criteria
     */
    private function hasMeasurableCriterion($criteria): bool
    {
        if (! is_array($criteria)) {
            return false;
        }
        $needles = ['test', 'phpunit', 'gate', 'green', 'docs-health', 'index-code', 'sync', 'pass', 'count', 'commands'];
        foreach ($criteria as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            $lower = strtolower($entry);
            foreach ($needles as $needle) {
                if (str_contains($lower, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }
}
