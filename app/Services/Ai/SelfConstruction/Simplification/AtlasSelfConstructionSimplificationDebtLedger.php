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
     *   }>,
     * }  $facts
     * @return array{schema:string, entries:list<array<string,mixed>>}
     */
    public function record(array $facts): array
    {
        $entries = [];
        foreach ((array) ($facts['entries'] ?? []) as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $category = trim((string) ($raw['category'] ?? ''));
            if (! array_key_exists($category, self::CATEGORY_PROFILE)) {
                continue;
            }

            $profile = self::CATEGORY_PROFILE[$category];
            $entries[] = array_merge([
                'category' => $category,
                'affected_targets' => array_values((array) ($raw['affected_targets'] ?? [])),
                'evidence_refs' => array_values((array) ($raw['evidence_refs'] ?? [])),
            ], $profile);
        }

        usort($entries, static fn (array $a, array $b): int => self::CATEGORY_PRIORITY[$b['category']] <=> self::CATEGORY_PRIORITY[$a['category']]);

        return [
            'schema' => self::SCHEMA,
            'entries' => array_values($entries),
        ];
    }
}
