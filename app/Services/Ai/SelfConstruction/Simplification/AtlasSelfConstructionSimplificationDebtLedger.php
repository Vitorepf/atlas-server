<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Pure ledger: turns raw simplification-debt observations (duplicate circuits, stale
 * scaffolding, broken contracts, cosmetic-only findings) into a ranked, accountable
 * worklist. Debt that reduces duplicated decision paths or repairs a broken contract
 * always outranks cosmetic-only cleanup — the ledger exists to stop cosmetic churn
 * from crowding out real simplification.
 *
 * Pure / deterministic. No I/O — callers persist/act on the returned entries.
 */
final class AtlasSelfConstructionSimplificationDebtLedger
{
    public const SCHEMA = 'atlas.self_construction.simplification.debt_ledger.v1';

    public const AGE_BUCKET_UNKNOWN = 'unknown';

    public const AGE_BUCKET_FRESH = 'fresh';

    public const AGE_BUCKET_AGING = 'aging';

    public const AGE_BUCKET_STALE = 'stale';

    private const AGING_MIN_DAYS = 8;

    private const STALE_MIN_DAYS = 31;

    /** @var array<string,int> age bucket => compound-priority tie-break bonus, WITHIN a category only. */
    private const AGE_BONUS = [
        self::AGE_BUCKET_STALE => 2,
        self::AGE_BUCKET_AGING => 1,
        self::AGE_BUCKET_FRESH => 0,
        self::AGE_BUCKET_UNKNOWN => 0,
    ];


    private const CATEGORY_DUPLICATE_CIRCUIT = 'duplicate_circuit';

    private const CATEGORY_BROKEN_CONTRACT = 'broken_contract';

    private const CATEGORY_STALE_SCAFFOLD = 'stale_scaffold';

    private const CATEGORY_COSMETIC = 'cosmetic';

    /**
     * Higher priority ranks first. Categories that reduce duplicated decision paths,
     * repair broken contracts, or carry give_back risk always rank above cosmetic-only
     * findings, which are deferred rather than actioned.
     *
     * @var array<string,int>
     */
    private const CATEGORY_PRIORITY = [
        self::CATEGORY_BROKEN_CONTRACT => 3,
        self::CATEGORY_DUPLICATE_CIRCUIT => 2,
        self::CATEGORY_STALE_SCAFFOLD => 1,
        self::CATEGORY_COSMETIC => 0,
    ];

    /**
     * @var array<string,array{impact:string, risk:string, next_action:string, accountable_gate:string}>
     */
    private const CATEGORY_PROFILE = [
        self::CATEGORY_BROKEN_CONTRACT => [
            'impact' => 'fixes_broken_contract_before_next_wave',
            'risk' => 'high',
            'next_action' => 'repair_contract_before_next_wave',
            'accountable_gate' => 'architecture_council_contract_critic',
        ],
        self::CATEGORY_DUPLICATE_CIRCUIT => [
            'impact' => 'reduces_duplicated_decision_paths',
            'risk' => 'medium',
            'next_action' => 'consolidate_into_single_organ',
            'accountable_gate' => 'circuit_collapse_advisor',
        ],
        self::CATEGORY_STALE_SCAFFOLD => [
            'impact' => 'removes_dead_scaffold_path',
            'risk' => 'low',
            'next_action' => 'retire_via_dead_organ_ledger',
            'accountable_gate' => 'safe_deletion_planner',
        ],
        self::CATEGORY_COSMETIC => [
            'impact' => 'cosmetic_only',
            'risk' => 'none',
            'next_action' => 'defer_no_action_required',
            'accountable_gate' => 'none',
        ],
    ];

    /**
     * @param  array{
     *   entries?: list<array{
     *     category?: string,
     *     affected_targets?: list<string>,
     *     evidence_refs?: list<string>,
     *     first_observed_at_days_ago?: int,
     *   }>,
     * }  $facts
     * @return array{schema:string, entries:list<array<string,mixed>>}
     */
    public function record(array $facts): array
    {
        $byKey = [];
        foreach ((array) ($facts['entries'] ?? []) as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $category = trim((string) ($raw['category'] ?? ''));
            if (! array_key_exists($category, self::CATEGORY_PROFILE)) {
                continue;
            }

            $targets = array_values(array_unique(array_map('strval', (array) ($raw['affected_targets'] ?? []))));
            sort($targets, SORT_STRING);
            $evidenceRefs = array_values(array_unique(array_map('strval', (array) ($raw['evidence_refs'] ?? []))));

            $ageDays = array_key_exists('first_observed_at_days_ago', $raw)
                ? max(0, (int) $raw['first_observed_at_days_ago'])
                : null;
            $ageBucket = match (true) {
                $ageDays === null => self::AGE_BUCKET_UNKNOWN,
                $ageDays >= self::STALE_MIN_DAYS => self::AGE_BUCKET_STALE,
                $ageDays >= self::AGING_MIN_DAYS => self::AGE_BUCKET_AGING,
                default => self::AGE_BUCKET_FRESH,
            };

            // Duplicate-debt collapse: same category + same affected_targets set is ONE debt item,
            // never inflated into repeat entries — evidence and observed-age merge into the original.
            $dedupKey = $category.'|'.implode(',', $targets);
            if (isset($byKey[$dedupKey])) {
                $existing = $byKey[$dedupKey];
                $existing['evidence_refs'] = array_values(array_unique(array_merge($existing['evidence_refs'], $evidenceRefs)));
                sort($existing['evidence_refs'], SORT_STRING);
                $existing['duplicate_count']++;
                if ($ageDays !== null && ($existing['stale_age_days'] === null || $ageDays > $existing['stale_age_days'])) {
                    $existing['stale_age_days'] = $ageDays;
                    $existing['age_bucket'] = $ageBucket;
                }
                $byKey[$dedupKey] = $existing;

                continue;
            }

            $profile = self::CATEGORY_PROFILE[$category];
            $proofGap = $evidenceRefs === [];
            $nextTaskHint = $profile['next_action'].($targets !== [] ? ':'.$targets[0] : '');

            $byKey[$dedupKey] = array_merge([
                'category' => $category,
                'affected_targets' => $targets,
                'evidence_refs' => $evidenceRefs,
                'proof_gap' => $proofGap,
                'stale_age_days' => $ageDays,
                'age_bucket' => $ageBucket,
                'next_task_hint' => $nextTaskHint,
                'duplicate_count' => 1,
            ], $profile);
        }

        $entries = array_values($byKey);
        foreach ($entries as &$entry) {
            $entry['compound_priority'] = self::CATEGORY_PRIORITY[$entry['category']] * 10
                + self::AGE_BONUS[$entry['age_bucket']]
                - ($entry['proof_gap'] ? 1 : 0);
        }
        unset($entry);

        usort($entries, static fn (array $a, array $b): int => $b['compound_priority'] <=> $a['compound_priority']);

        return [
            'schema' => self::SCHEMA,
            'entries' => array_values($entries),
        ];
    }
}
