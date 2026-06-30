<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure planner: given capability records with lifecycle states, identifies
 * capabilities that are stalled (never reached integrated/used) and emits
 * a rescue, collapse, retire, or monitor action for each.
 *
 * Priority (first match wins):
 *   1. Has an integrated replacement owner → collapse (no rescue work for the stale one)
 *   2. state=implemented|tested AND rescue is viable → rescue (wiring work needed)
 *   2b. state=implemented|tested AND rescue NOT viable:
 *         duplicate_owner_count > 0 → collapse (another owner handles it)
 *         otherwise                 → retire
 *   3. stall_count >= 3 AND state planned|queued → retire
 *   4. default                               → monitor
 *
 * RESCUE VIABILITY:
 *   Rescue is NOT viable when (stale AND duplicated AND unused AND high_cost).
 *   Secondary: give_backs > 0 AND unused AND stale AND implementation_completeness < 0.5.
 *   All other combinations remain rescue-eligible (default).
 *
 * Capabilities already at state=integrated|used are not stalled — they are skipped.
 */
final class AtlasExternalBrainStalledCapabilityRescuePlanner
{
    public const SCHEMA = 'atlas.external_brain.stalled_capability_rescue_planner.v1';

    public const ACTION_RESCUE   = 'rescue';
    public const ACTION_COLLAPSE = 'collapse';
    public const ACTION_RETIRE   = 'retire';
    public const ACTION_MONITOR  = 'monitor';

    public const STALL_RETIRE_THRESHOLD      = 3;
    public const RESCUE_STALE_DAYS_THRESHOLD = 30;
    public const RESCUE_MAX_COST             = 1.0;
    public const RESCUE_LOW_COST_THRESHOLD   = 0.5;

    private const PROGRESS_STATES   = ['implemented', 'tested'];
    private const PLANNED_STATES    = ['planned', 'queued'];
    private const INTEGRATED_STATES = ['integrated', 'used'];

    /** @var array<string,list<string>> */
    private const EVIDENCE_BY_ACTION = [
        self::ACTION_RESCUE   => ['integration_test', 'wiring_proof'],
        self::ACTION_COLLAPSE => ['canonical_owner_confirmation', 'overlap_proof'],
        self::ACTION_RETIRE   => ['no_active_consumer', 'stall_history'],
        self::ACTION_MONITOR  => [],
    ];

    /**
     * @param  list<array<string,mixed>>  $capabilities  Each: id, state, stall_count,
     *                                                     replacement_owner_id, replacement_owner_integrated,
     *                                                     consumer_count, duplicate_owner_count,
     *                                                     last_green_commit_age_days,
     *                                                     outstanding_give_back_count,
     *                                                     implementation_completeness,
     *                                                     estimated_rescue_cost
     * @return array<string,mixed>
     */
    public function plan(array $capabilities): array
    {
        $entries  = [];
        $byAction = [
            self::ACTION_RESCUE   => [],
            self::ACTION_COLLAPSE => [],
            self::ACTION_RETIRE   => [],
            self::ACTION_MONITOR  => [],
        ];
        $highPriorityRescueCount = 0;

        foreach ($capabilities as $cap) {
            $id                    = (string) ($cap['id']                         ?? '');
            $state                 = (string) ($cap['state']                      ?? 'planned');
            $stallCount            = max(0, (int) ($cap['stall_count']            ?? 0));
            $replacementOwner      = (string) ($cap['replacement_owner_id']       ?? '');
            $replacementIntegrated = (bool) ($cap['replacement_owner_integrated'] ?? false);
            $duplicateOwnerCount   = max(0, (int) ($cap['duplicate_owner_count']  ?? 0));
            $isProgressing         = (bool) ($cap['is_progressing']               ?? false);

            if (in_array($state, self::INTEGRATED_STATES, true)) {
                continue;
            }

            $unblockCause = $this->computeUnblockCause($cap);

            if ($replacementOwner !== '' && $replacementIntegrated) {
                $action = self::ACTION_COLLAPSE;
            } elseif (in_array($state, self::PROGRESS_STATES, true)) {
                // AC2: progressing without an explicit blocker → not stalled, monitor instead
                if ($isProgressing && $unblockCause === 'unknown') {
                    $action = self::ACTION_MONITOR;
                } elseif ($this->isRescueViable($cap)) {
                    $action = self::ACTION_RESCUE;
                } elseif ($duplicateOwnerCount > 0) {
                    $action = self::ACTION_COLLAPSE;
                } else {
                    $action = self::ACTION_RETIRE;
                }
            } elseif ($stallCount >= self::STALL_RETIRE_THRESHOLD && in_array($state, self::PLANNED_STATES, true)) {
                $action = self::ACTION_RETIRE;
            } else {
                $action = self::ACTION_MONITOR;
            }

            $rescuePriority = 'n/a';
            if ($action === self::ACTION_RESCUE) {
                $impactLevel   = (string) ($cap['impact_level']         ?? 'medium');
                $estimatedCost = max(0.0, (float) ($cap['estimated_rescue_cost'] ?? 1.0));
                $rescuePriority = ($impactLevel === 'high' && $estimatedCost <= self::RESCUE_LOW_COST_THRESHOLD)
                    ? 'high'
                    : 'normal';

                if ($rescuePriority === 'high') {
                    $highPriorityRescueCount++;
                }
            }

            $entry = [
                'capability_id'         => $id,
                'action'                => $action,
                'stall_count'           => $stallCount,
                'evidence_requirements' => self::EVIDENCE_BY_ACTION[$action],
                'unblock_cause'         => $unblockCause,
                'rescue_priority'       => $rescuePriority,
                'next_action'           => $this->nextAction($action, $unblockCause, $replacementOwner),
            ];

            if ($action === self::ACTION_COLLAPSE && $replacementOwner !== '') {
                $entry['collapse_into'] = $replacementOwner;
            }

            $entries[]           = $entry;
            $byAction[$action][] = $id;
        }

        return [
            'schema_version'             => self::SCHEMA,
            'total_evaluated'            => count($entries),
            'high_priority_rescue_count' => $highPriorityRescueCount,
            'entries'                    => $entries,
            'by_action'                  => $byAction,
        ];
    }

    private function nextAction(string $action, string $unblockCause, string $replacementOwner): string
    {
        return match ($action) {
            self::ACTION_RESCUE   => $unblockCause === 'unknown'
                ? 'wire_integration_and_add_proof'
                : "resolve_{$unblockCause}_then_wire_integration",
            self::ACTION_COLLAPSE => $replacementOwner !== ''
                ? "collapse_into:{$replacementOwner}"
                : 'collapse_into_canonical_owner',
            self::ACTION_RETIRE  => 'retire_no_active_consumer',
            default              => 'continue_monitoring_progress',
        };
    }

    private function computeUnblockCause(array $cap): string
    {
        if ((bool) ($cap['has_contradiction'] ?? false)) {
            return 'contradiction';
        }
        if ((bool) ($cap['has_missing_file'] ?? false)) {
            return 'missing_file';
        }
        if ((bool) ($cap['has_scope_gap'] ?? false)) {
            return 'scope_gap';
        }
        if ((bool) ($cap['has_missing_test'] ?? false)) {
            return 'missing_test';
        }
        return 'unknown';
    }

    private function isRescueViable(array $cap): bool
    {
        $consumerCount     = max(0, (int) ($cap['consumer_count']               ?? 0));
        $duplicateOwners   = max(0, (int) ($cap['duplicate_owner_count']        ?? 0));
        $greenAge          = max(0, (int) ($cap['last_green_commit_age_days']   ?? 0));
        $giveBacks         = max(0, (int) ($cap['outstanding_give_back_count']  ?? 0));
        $completeness      = max(0.0, min(1.0, (float) ($cap['implementation_completeness'] ?? 0.0)));
        $estimatedCost     = max(0.0, (float) ($cap['estimated_rescue_cost']    ?? 0.0));

        $isStale      = $greenAge > self::RESCUE_STALE_DAYS_THRESHOLD;
        $isDuplicated = $duplicateOwners > 0;
        $isUnused     = $consumerCount === 0;
        $isHighCost   = $estimatedCost > self::RESCUE_MAX_COST;

        // Primary: stale + duplicated + unused + high cost → clearly not worth rescuing
        if ($isStale && $isDuplicated && $isUnused && $isHighCost) {
            return false;
        }

        // Secondary: repeated give-backs on unused stale capability, unless much work was done
        $highCompleteness = $completeness >= 0.5;
        if ($giveBacks > 0 && $isUnused && $isStale && ! $highCompleteness) {
            return false;
        }

        return true;
    }
}
