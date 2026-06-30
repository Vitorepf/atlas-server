<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Provider-free planning contract for outside research, repositories, Atlas journals, and internal
 * design patterns. Expands a thin local idea into source categories, comparison questions,
 * freshness requirements, and adoption risks.
 *
 * Pure: NEVER fetches URLs, dispatches jobs, or writes ledgers. The output is a plan structure
 * the brain can act on — not live data.
 *
 * Output schema: {
 *   schema, idea, source_categories, comparison_questions,
 *   freshness_requirements, adoption_risks, provenance_required, accepted
 * }
 */
final class AtlasExternalBrainResearchPatternPlan
{
    public const SCHEMA = 'atlas.external_brain.research_pattern_plan.v1';

    private const SOURCE_CATEGORIES = [
        'atlas_journals'        => 'Prior Atlas loop journals and evidence ledger entries',
        'internal_patterns'     => 'Existing Atlas services, traits, and design patterns in the codebase',
        'oss_repositories'      => 'Open-source repositories implementing similar concepts',
        'academic_papers'       => 'ArXiv or published papers on the underlying technique',
        'industry_case_studies' => 'Real-world deployments or post-mortems on the pattern',
    ];

    /**
     * Expand a thin idea into a full research plan.
     *
     * @param  string  $idea           Short description of what to research
     * @param  array<string,mixed>  $hints  Optional caller hints: focus_areas, exclude_categories, max_age_days
     * @return array<string,mixed>
     */
    public function plan(string $idea, array $hints = []): array
    {
        $idea = trim($idea);

        if ($idea === '') {
            return $this->envelope($idea, [], [], [], [], false);
        }

        $exclude = array_map('trim', (array) ($hints['exclude_categories'] ?? []));
        $focusAreas = array_values(array_filter(array_map('trim', (array) ($hints['focus_areas'] ?? []))));
        $maxAgeDays = max(1, (int) ($hints['max_age_days'] ?? 180));

        $sourceCategories = array_diff_key(self::SOURCE_CATEGORIES, array_flip($exclude));

        $comparisonQuestions = $this->buildComparisonQuestions($idea, $focusAreas);
        $freshnessRequirements = $this->buildFreshnessRequirements($sourceCategories, $maxAgeDays);
        $adoptionRisks = $this->buildAdoptionRisks($idea);

        return $this->envelope($idea, $sourceCategories, $comparisonQuestions, $freshnessRequirements, $adoptionRisks, true);
    }

    /**
     * Validate that a completed research entry has provenance, comparison, and an Atlas adaptation hypothesis.
     *
     * @param  array<string,mixed>  $entry
     */
    public function accept(array $entry): bool
    {
        return isset($entry['provenance'])
            && is_string($entry['provenance']) && $entry['provenance'] !== ''
            && isset($entry['comparison_summary'])
            && is_string($entry['comparison_summary']) && $entry['comparison_summary'] !== ''
            && isset($entry['atlas_adaptation_hypothesis'])
            && is_string($entry['atlas_adaptation_hypothesis']) && $entry['atlas_adaptation_hypothesis'] !== '';
    }

    /** @param  array<string,string>  $sourceCategories */
    private function buildComparisonQuestions(string $idea, array $focusAreas): array
    {
        $base = [
            "What existing Atlas patterns overlap with \"{$idea}\"?",
            "Which OSS implementations are production-proven for \"{$idea}\"?",
            "What are the key trade-offs between the candidate approaches?",
            "What does the Atlas evidence ledger say about prior attempts at \"{$idea}\"?",
            "Which approach has the best fit with Atlas local-first and provider-free constraints?",
        ];

        foreach ($focusAreas as $area) {
            $base[] = "How does \"{$area}\" intersect with \"{$idea}\" in each candidate source?";
        }

        return $base;
    }

    /**
     * @param  array<string,string>  $sourceCategories
     * @return array<string,mixed>
     */
    private function buildFreshnessRequirements(array $sourceCategories, int $maxAgeDays): array
    {
        $reqs = [];
        foreach (array_keys($sourceCategories) as $cat) {
            $reqs[$cat] = [
                'max_age_days' => match ($cat) {
                    'atlas_journals', 'internal_patterns' => min($maxAgeDays, 30),
                    'oss_repositories'                    => min($maxAgeDays, 90),
                    default                               => $maxAgeDays,
                },
                'must_cite_source' => true,
            ];
        }

        return $reqs;
    }

    private function buildAdoptionRisks(string $idea): array
    {
        return [
            'provider_dependency'   => "Adopting \"{$idea}\" introduces a runtime provider call where none exists today",
            'goodhart_drift'        => "Measuring \"{$idea}\" as a proxy metric causes optimization for the metric instead of the goal",
            'incomplete_wiring'     => "Pattern is imported as a stub but callers are never wired — orphan organ risk",
            'stale_research'        => 'Evidence used is older than the freshness requirement and may not reflect current practice',
        ];
    }

    /**
     * @param  array<string,string>  $sourceCategories
     * @param  array<string,mixed>[]  $comparisonQuestions
     * @param  array<string,mixed>  $freshnessRequirements
     * @param  array<string,string>  $adoptionRisks
     * @return array<string,mixed>
     */
    private function envelope(
        string $idea,
        array $sourceCategories,
        array $comparisonQuestions,
        array $freshnessRequirements,
        array $adoptionRisks,
        bool $accepted,
    ): array {
        return [
            'schema'                 => self::SCHEMA,
            'idea'                   => $idea,
            'source_categories'      => $sourceCategories,
            'comparison_questions'   => array_values($comparisonQuestions),
            'freshness_requirements' => $freshnessRequirements,
            'adoption_risks'         => $adoptionRisks,
            'provenance_required'    => true,
            'accepted'               => $accepted,
        ];
    }
}
