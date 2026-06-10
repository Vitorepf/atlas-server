<?php

namespace App\Services\Ai\Mobile;

use App\Models\AiContextBundle;
use App\Models\AiInboxItem;
use App\Models\MobilePairingCode;
use App\Models\MobilePushDelivery;
use App\Services\AuditLogService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MobileMaintenanceService
{
    public function __construct(private readonly AuditLogService $audit)
    {
    }

    /**
     * Transiciona items vencidos para `status='expired'`.
     *
     * @return array{transitioned:int,dry_run:bool}
     */
    public function expireStale(bool $dryRun = false): array
    {
        if (! DatabaseTableAvailability::has('ai_inbox_items')) {
            return ['transitioned' => 0, 'dry_run' => $dryRun];
        }

        $query = AiInboxItem::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->whereNotIn('status', ['resolved', 'dismissed', 'expired']);

        $count = $query->count();

        if ($count === 0 || $dryRun) {
            return ['transitioned' => $count, 'dry_run' => $dryRun];
        }

        DB::transaction(function () use ($query): void {
            $query->update([
                'status' => 'expired',
                'resolved_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->audit->record('inbox.expired_batch', [
            'subject_type' => 'ai_inbox_items',
            'subject_id' => null,
            'actor_type' => 'system',
            'actor_id' => null,
            'severity' => 'info',
            'summary' => "Expired {$count} stale inbox items.",
            'evidence' => ['count' => $count],
            'privacy' => ['sensitivity' => 'private'],
        ]);

        return ['transitioned' => $count, 'dry_run' => false];
    }

    /**
     * Cleanup destrutivo de tabelas que crescem indefinidamente.
     *
     * @param  array<string,mixed>  $overrides
     * @return array{
     *   pairing_codes:int,
     *   inbox_items:int,
     *   context_bundles:int,
     *   push_deliveries:int,
     *   dry_run:bool
     * }
     */
    public function cleanup(bool $dryRun = true, array $overrides = []): array
    {
        $pairingDays = (int) ($overrides['pairing_codes_after_days']
            ?? config('atlas.mobile.cleanup.pairing_codes_after_days', 7));
        $inboxDays = (int) ($overrides['inbox_resolved_after_days']
            ?? config('atlas.mobile.cleanup.inbox_resolved_after_days', 90));
        $bundleDays = (int) ($overrides['bundles_orphan_after_days']
            ?? config('atlas.mobile.cleanup.bundles_orphan_after_days', 30));
        $deliveryDays = (int) ($overrides['deliveries_after_days']
            ?? config('atlas.mobile.cleanup.deliveries_after_days', 90));

        $now = now();
        $result = [
            'pairing_codes' => 0,
            'inbox_items' => 0,
            'context_bundles' => 0,
            'push_deliveries' => 0,
            'dry_run' => $dryRun,
        ];

        $result['pairing_codes'] = $this->cleanPairingCodes($now, $pairingDays, $dryRun);
        $result['inbox_items'] = $this->cleanInboxItems($now, $inboxDays, $dryRun);
        $result['context_bundles'] = $this->cleanOrphanBundles($now, $bundleDays, $dryRun);
        $result['push_deliveries'] = $this->cleanPushDeliveries($now, $deliveryDays, $dryRun);

        if (! $dryRun && array_sum(array_intersect_key($result, array_flip([
            'pairing_codes', 'inbox_items', 'context_bundles', 'push_deliveries',
        ]))) > 0) {
            $this->audit->record('mobile.cleanup.completed', [
                'subject_type' => 'mobile_maintenance',
                'subject_id' => null,
                'actor_type' => 'system',
                'actor_id' => null,
                'severity' => 'info',
                'summary' => 'Mobile maintenance cleanup completed.',
                'evidence' => array_intersect_key($result, array_flip([
                    'pairing_codes', 'inbox_items', 'context_bundles', 'push_deliveries',
                ])),
                'privacy' => ['sensitivity' => 'private'],
            ]);
        }

        return $result;
    }

    private function cleanPairingCodes(Carbon $now, int $afterDays, bool $dryRun): int
    {
        if (! DatabaseTableAvailability::has('mobile_pairing_codes')) {
            return 0;
        }

        $cutoff = $now->copy()->subDays(max(1, $afterDays));

        $query = MobilePairingCode::query()
            ->where(function ($outer) use ($cutoff): void {
                $outer
                    ->where(function ($q) use ($cutoff): void {
                        $q->whereNotNull('consumed_at')->where('consumed_at', '<', $cutoff);
                    })
                    ->orWhere(function ($q) use ($cutoff): void {
                        $q->whereNull('consumed_at')->where('expires_at', '<', $cutoff);
                    });
            });

        $count = $query->count();

        if ($count > 0 && ! $dryRun) {
            $query->delete();
        }

        return $count;
    }

    private function cleanInboxItems(Carbon $now, int $afterDays, bool $dryRun): int
    {
        if (! DatabaseTableAvailability::has('ai_inbox_items')) {
            return 0;
        }

        $cutoff = $now->copy()->subDays(max(1, $afterDays));

        $query = AiInboxItem::query()
            ->whereIn('status', ['resolved', 'dismissed', 'expired'])
            ->where(function ($q) use ($cutoff): void {
                $q
                    ->where('resolved_at', '<', $cutoff)
                    ->orWhere(function ($inner) use ($cutoff): void {
                        $inner->whereNull('resolved_at')->where('dismissed_at', '<', $cutoff);
                    })
                    ->orWhere(function ($inner) use ($cutoff): void {
                        $inner
                            ->whereNull('resolved_at')
                            ->whereNull('dismissed_at')
                            ->where('updated_at', '<', $cutoff);
                    });
            });

        $count = $query->count();

        if ($count > 0 && ! $dryRun) {
            $query->delete();
        }

        return $count;
    }

    private function cleanOrphanBundles(Carbon $now, int $afterDays, bool $dryRun): int
    {
        if (! DatabaseTableAvailability::has('ai_context_bundles')) {
            return 0;
        }

        $cutoff = $now->copy()->subDays(max(1, $afterDays));

        $query = AiContextBundle::query()
            ->where('updated_at', '<', $cutoff)
            ->where(function ($q) use ($cutoff): void {
                $q
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<', $cutoff);
            });

        if (DatabaseTableAvailability::has('ai_inbox_items')) {
            $query->whereNotExists(function ($sub): void {
                $sub
                    ->select(DB::raw(1))
                    ->from('ai_inbox_items')
                    ->whereColumn('ai_inbox_items.context_bundle_id', 'ai_context_bundles.id');
            });
        }

        $count = $query->count();

        if ($count > 0 && ! $dryRun) {
            $query->delete();
        }

        return $count;
    }

    private function cleanPushDeliveries(Carbon $now, int $afterDays, bool $dryRun): int
    {
        if (! DatabaseTableAvailability::has('mobile_push_deliveries')) {
            return 0;
        }

        $cutoff = $now->copy()->subDays(max(1, $afterDays));

        $query = MobilePushDelivery::query()->where('created_at', '<', $cutoff);

        $count = $query->count();

        if ($count > 0 && ! $dryRun) {
            $query->delete();
        }

        return $count;
    }
}
