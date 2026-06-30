<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure map: scores each Atlas domain for shallow-context risk and blocks
 * architecture targets that would be built on insufficient understanding.
 *
 * Risk signals (cumulative):
 *   missing owner docs          → +3
 *   stale context pack (>30d)   → +2
 *   low code coverage (<50%)    → +2
 *   unresolved_contradictions   → +2 each, capped at +4
 *   recent_failed_assumptions   → +2 each, capped at +4
 *
 * Context levels:
 *   shallow_context  ← risk >= 5  (blocks architecture changes)
 *   partial_context  ← risk >= 3
 *   adequate_context ← risk < 3
 *
 * next_context_actions lists repair steps for each shallow_context domain.
 * blocked_architecture_targets is the intersection of proposed targets and shallow domains.
 */
final class AtlasExternalBrainComprehensionDeepeningMap
{
    public const SCHEMA = 'atlas.external_brain.comprehension_deepening_map.v1';

    public const SHALLOW_THRESHOLD = 5;

    public const PARTIAL_THRESHOLD = 3;

    public const STALE_AGE_THRESHOLD_DAYS = 30;

    public const LOW_COVERAGE_THRESHOLD = 50.0;

    /**
     * @param  array<string,mixed>  $input  domains list + architecture_targets list
     * @return array{schema_version:string, ranked_domains:list<array<string,mixed>>, blocked_architecture_targets:list<string>, next_context_actions:list<array<string,mixed>>}
     */
    public function map(array $input): array
    {
        $domains = is_array($input['domains'] ?? null) ? $input['domains'] : [];
        $architectureTargets = array_values(array_filter(
            array_map('strval', (array) ($input['architecture_targets'] ?? [])),
            static fn (string $t): bool => $t !== '',
        ));

        $rankedDomains = [];
        $shallowIds = [];
        $nextContextActions = [];

        foreach ($domains as $domain) {
            $id = (string) ($domain['domain_id'] ?? '');
            $hasOwnerDocs = (bool) ($domain['has_owner_docs'] ?? true);
            $contextPackAge = max(0, (int) ($domain['context_pack_age_days'] ?? 0));
            $codeCoverage = (float) ($domain['code_coverage'] ?? 100.0);
            $contradictions = max(0, (int) ($domain['unresolved_contradictions'] ?? 0));
            $failedAssumptions = max(0, (int) ($domain['recent_failed_assumptions'] ?? 0));

            $risk = 0;
            $signals = [];

            if (! $hasOwnerDocs) {
                $risk += 3;
                $signals[] = 'missing_owner_docs';
            }
            if ($contextPackAge > self::STALE_AGE_THRESHOLD_DAYS) {
                $risk += 2;
                $signals[] = 'stale_context_pack';
            }
            if ($codeCoverage < self::LOW_COVERAGE_THRESHOLD) {
                $risk += 2;
                $signals[] = 'low_code_coverage';
            }
            if ($contradictions > 0) {
                $risk += min(4, $contradictions * 2);
                $signals[] = 'unresolved_contradictions';
            }
            if ($failedAssumptions > 0) {
                $risk += min(4, $failedAssumptions * 2);
                $signals[] = 'recent_failed_assumptions';
            }

            if ($risk >= self::SHALLOW_THRESHOLD) {
                $level = 'shallow_context';
                $shallowIds[] = $id;

                $actions = [];
                if (! $hasOwnerDocs) {
                    $actions[] = 'read_owner_docs';
                }
                if ($contextPackAge > self::STALE_AGE_THRESHOLD_DAYS) {
                    $actions[] = 'refresh_context_pack';
                }
                if ($contradictions > 0) {
                    $actions[] = 'resolve_contradictions';
                }
                if ($actions !== []) {
                    $nextContextActions[] = ['domain_id' => $id, 'actions' => $actions];
                }
            } elseif ($risk >= self::PARTIAL_THRESHOLD) {
                $level = 'partial_context';
            } else {
                $level = 'adequate_context';
            }

            $rankedDomains[] = [
                'domain_id' => $id,
                'risk_score' => $risk,
                'context_level' => $level,
                'risk_signals' => $signals,
            ];
        }

        usort($rankedDomains, static fn (array $a, array $b): int => $b['risk_score'] <=> $a['risk_score'] ?: strcmp($a['domain_id'], $b['domain_id']));

        $blocked = array_values(array_filter($architectureTargets, static fn (string $t): bool => in_array($t, $shallowIds, true)));

        return [
            'schema_version' => self::SCHEMA,
            'ranked_domains' => array_values($rankedDomains),
            'blocked_architecture_targets' => $blocked,
            'next_context_actions' => $nextContextActions,
        ];
    }
}
