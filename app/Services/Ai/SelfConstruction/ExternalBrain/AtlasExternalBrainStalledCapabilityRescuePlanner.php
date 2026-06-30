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
 *   2. state=implemented|tested              → rescue (wiring work needed)
 *   3. stall_count >= 3 AND state planned|queued → retire
 *   4. default                               → monitor
 *
 * Capabilities already at state=integrated|used are not stalled — they are skipped.
 */
final class AtlasExternalBrainStalledCapabilityRescuePlanner
{
    public const SCHEMA = 'atlas.external_brain.stalled_capability_rescue_planner.v1';

    public const ACTION_RESCUE = 'rescue';

    public const ACTION_COLLAPSE = 'collapse';

    public const ACTION_RETIRE = 'retire';

    public const ACTION_MONITOR = 'monitor';

    public const STALL_RETIRE_THRESHOLD = 3;

    private const PROGRESS_STATES = ['implemented', 'tested'];

    private const PLANNED_STATES = ['planned', 'queued'];

    private const INTEGRATED_STATES = ['integrated', 'used'];

    /** @var array<string,list<string>> */
    private const EVIDENCE_BY_ACTION = [
        self::ACTION_RESCUE => ['integration_test', 'wiring_proof'],
        self::ACTION_COLLAPSE => ['canonical_owner_confirmation', 'overlap_proof'],
        self::ACTION_RETIRE => ['no_active_consumer', 'stall_history'],
        self::ACTION_MONITOR => [],
    ];

    /**
     * @param  list<array<string,mixed>>  $capabilities  Each: id, state, stall_count,
     *                                                     replacement_owner_id, replacement_owner_integrated
     * @return array<string,mixed>
     */
    public function plan(array $capabilities): array
    {
        $entries = [];
        $byAction = [
            self::ACTION_RESCUE => [],
            self::ACTION_COLLAPSE => [],
            self::ACTION_RETIRE => [],
            self::ACTION_MONITOR => [],
        ];

        foreach ($capabilities as $cap) {
            $id = (string) ($cap['id'] ?? '');
            $state = (string) ($cap['state'] ?? 'planned');
            $stallCount = max(0, (int) ($cap['stall_count'] ?? 0));
            $replacementOwner = (string) ($cap['replacement_owner_id'] ?? '');
            $replacementIntegrated = (bool) ($cap['replacement_owner_integrated'] ?? false);

            if (in_array($state, self::INTEGRATED_STATES, true)) {
                continue;
            }

            if ($replacementOwner !== '' && $replacementIntegrated) {
                $action = self::ACTION_COLLAPSE;
            } elseif (in_array($state, self::PROGRESS_STATES, true)) {
                $action = self::ACTION_RESCUE;
            } elseif ($stallCount >= self::STALL_RETIRE_THRESHOLD && in_array($state, self::PLANNED_STATES, true)) {
                $action = self::ACTION_RETIRE;
            } else {
                $action = self::ACTION_MONITOR;
            }

            $entry = [
                'capability_id' => $id,
                'action' => $action,
                'stall_count' => $stallCount,
                'evidence_requirements' => self::EVIDENCE_BY_ACTION[$action],
            ];

            if ($action === self::ACTION_COLLAPSE) {
                $entry['collapse_into'] = $replacementOwner;
            }

            $entries[] = $entry;
            $byAction[$action][] = $id;
        }

        return [
            'schema_version' => self::SCHEMA,
            'total_evaluated' => count($entries),
            'entries' => $entries,
            'by_action' => $byAction,
        ];
    }
}
