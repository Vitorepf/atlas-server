<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Execution;

final class AtlasAaelExecutionPlanSemanticProver
{
    public const SCHEMA = 'atlas.aael.execution.plan_semantic_proof.v1';

    /**
     * @param  array<string,mixed>|list<mixed>  $plan
     * @return array{
     *     schema_version:string,
     *     objective_token_coverage_ratio:float,
     *     unmet_objective_anchors:list<string>,
     *     plan_steps_without_objective_anchor:list<string>
     * }
     */
    public function prove(array $plan, string $objective): array
    {
        $objectiveAnchors = $this->anchorsFromText($objective);
        $steps = $this->extractSteps($plan);

        if ($steps === []) {
            return [
                'schema_version' => self::SCHEMA,
                'objective_token_coverage_ratio' => 0.0,
                'unmet_objective_anchors' => $objectiveAnchors,
                'plan_steps_without_objective_anchor' => [],
            ];
        }

        $coveredAnchors = [];
        $stepsWithoutAnchor = [];

        foreach ($steps as $index => $step) {
            $stepTokens = $this->tokensFromMixed($step);
            $stepAnchorHits = array_values(array_intersect($objectiveAnchors, $stepTokens));

            if ($stepAnchorHits === []) {
                $stepsWithoutAnchor[] = $this->stepLabel($step, $index);

                continue;
            }

            foreach ($stepAnchorHits as $anchor) {
                $coveredAnchors[$anchor] = true;
            }
        }

        $coveredCount = count($coveredAnchors);
        $objectiveCount = count($objectiveAnchors);

        return [
            'schema_version' => self::SCHEMA,
            'objective_token_coverage_ratio' => $objectiveCount === 0 ? 0.0 : round($coveredCount / $objectiveCount, 4),
            'unmet_objective_anchors' => array_values(array_filter(
                $objectiveAnchors,
                static fn (string $anchor): bool => ! isset($coveredAnchors[$anchor]),
            )),
            'plan_steps_without_objective_anchor' => array_values($stepsWithoutAnchor),
        ];
    }

    /**
     * @param  array<string,mixed>|list<mixed>  $plan
     * @return list<array<string,mixed>>
     */
    private function extractSteps(array $plan): array
    {
        if (isset($plan['tasks']) && is_array($plan['tasks'])) {
            return array_values(array_filter($plan['tasks'], 'is_array'));
        }

        if (isset($plan['objective']) && is_string($plan['objective'])) {
            return [$plan];
        }

        if (! array_is_list($plan)) {
            return [];
        }

        return array_values(array_filter($plan, 'is_array'));
    }

    /**
     * @return list<string>
     */
    private function anchorsFromText(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}_\\\\.\/-]+/u', $text, $matches);
        $anchors = [];

        foreach ($matches[0] ?? [] as $rawToken) {
            $anchor = $this->normalizeAnchor((string) $rawToken);
            if ($anchor === null) {
                continue;
            }

            $anchors[$anchor] = $anchor;
        }

        return array_values($anchors);
    }

    /**
     * @param  array<string,mixed>  $step
     */
    private function stepLabel(array $step, int $index): string
    {
        $objective = $step['objective'] ?? null;

        return is_string($objective) && $objective !== ''
            ? $objective
            : 'step-'.($index + 1);
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function tokensFromMixed(mixed $value): array
    {
        if (is_string($value)) {
            return $this->anchorsFromText($value);
        }

        if (! is_array($value)) {
            return [];
        }

        $tokens = [];
        foreach ($value as $item) {
            foreach ($this->tokensFromMixed($item) as $token) {
                $tokens[$token] = $token;
            }
        }

        return array_values($tokens);
    }

    private function normalizeAnchor(string $token): ?string
    {
        $preserveSingleLetterAnchor = strlen($token) === 1 && ctype_alpha($token) && strtoupper($token) === $token;
        $normalized = mb_strtolower(trim($token), 'UTF-8');
        if ($normalized === '') {
            return null;
        }

        if (! $preserveSingleLetterAnchor && in_array($normalized, $this->stopWords(), true)) {
            return null;
        }

        if (! $preserveSingleLetterAnchor) {
            $normalized = preg_replace('/(ing|ed|es|s)$/u', '', $normalized) ?? $normalized;
        }

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return list<string>
     */
    private function stopWords(): array
    {
        return [
            'a',
            'an',
            'and',
            'as',
            'at',
            'by',
            'for',
            'from',
            'in',
            'into',
            'of',
            'on',
            'or',
            'the',
            'to',
            'with',
        ];
    }
}
