<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\PlanVisible;

use App\Models\AtlasProgrammingWorkItem;
use App\Services\Ai\Programming\AtlasDev\Schemas\PlanVisible;

/**
 * Atlas Dev A2 — Plan Visible telemetry projection.
 *
 * Computes `dev_plan_approval_rate` and `dev_plan_revision_count` from
 * the persisted `plan_json` column on `atlas_programming_work_items`,
 * plus the embedded revision history in `metadata_json.plan_revisions`.
 *
 * The service is read-only and emits a canonical snapshot envelope.
 * Telemetry consumers (ACRUI, surface dashboard, Decision Receipts)
 * read this envelope without coupling to the underlying table layout.
 *
 *  - approval_rate    = approved_count / total_decided_count        (0..1)
 *  - revision_count   = sum of plan_revisions[].count across items  (int)
 *
 * Empty input domain (zero work items) yields `pending_data` status —
 * never zeros that misrepresent reality. Honesty > pretty number.
 */
final class AtlasDevPlanTelemetry
{
    public const SCHEMA_VERSION = 'atlas.dev.plan_telemetry_snapshot.v1';

    public const STATUS_OK = 'ok';

    public const STATUS_PENDING_DATA = 'pending_data';

    /**
     * @return array{
     *   schema_version: string,
     *   status: string,
     *   window: array{from: ?string, to: ?string},
     *   counts: array{
     *     total_work_items_with_plan: int,
     *     approved: int,
     *     rejected: int,
     *     pending: int,
     *     total_revisions: int
     *   },
     *   rates: array{
     *     approval_rate: ?float,
     *     rejection_rate: ?float,
     *     pending_rate: ?float
     *   },
     *   revisions_avg_per_item: ?float
     * }
     */
    public function snapshot(?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array
    {
        $query = AtlasProgrammingWorkItem::query()
            ->whereNotNull('plan_hash');

        if ($from !== null) {
            $query->where('updated_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('updated_at', '<', $to);
        }

        $items = $query->get(['plan_json', 'metadata_json']);
        $total = $items->count();

        if ($total === 0) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => self::STATUS_PENDING_DATA,
                'window' => [
                    'from' => $from?->format(\DateTimeInterface::ATOM),
                    'to' => $to?->format(\DateTimeInterface::ATOM),
                ],
                'counts' => [
                    'total_work_items_with_plan' => 0,
                    'approved' => 0,
                    'rejected' => 0,
                    'pending' => 0,
                    'total_revisions' => 0,
                ],
                'rates' => [
                    'approval_rate' => null,
                    'rejection_rate' => null,
                    'pending_rate' => null,
                ],
                'revisions_avg_per_item' => null,
            ];
        }

        $approved = 0;
        $rejected = 0;
        $pending = 0;
        $totalRevisions = 0;

        foreach ($items as $item) {
            $plan = (array) ($item->plan_json ?? []);
            $status = (string) ($plan['approval_status'] ?? PlanVisible::APPROVAL_STATUS_PENDING);
            match ($status) {
                PlanVisible::APPROVAL_STATUS_APPROVED => $approved++,
                PlanVisible::APPROVAL_STATUS_REJECTED => $rejected++,
                default => $pending++,
            };

            $meta = (array) ($item->metadata_json ?? []);
            $revisions = (array) ($meta['plan_revisions'] ?? []);
            $totalRevisions += count($revisions);
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => self::STATUS_OK,
            'window' => [
                'from' => $from?->format(\DateTimeInterface::ATOM),
                'to' => $to?->format(\DateTimeInterface::ATOM),
            ],
            'counts' => [
                'total_work_items_with_plan' => $total,
                'approved' => $approved,
                'rejected' => $rejected,
                'pending' => $pending,
                'total_revisions' => $totalRevisions,
            ],
            'rates' => [
                'approval_rate' => round($approved / $total, 4),
                'rejection_rate' => round($rejected / $total, 4),
                'pending_rate' => round($pending / $total, 4),
            ],
            'revisions_avg_per_item' => round($totalRevisions / $total, 4),
        ];
    }
}
