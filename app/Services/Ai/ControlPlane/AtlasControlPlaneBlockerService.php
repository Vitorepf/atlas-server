<?php

namespace App\Services\Ai\ControlPlane;

use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Aggregates open/critical blockers across all runtimes: Evidence (AiBlocker),
 * Mission Foundation (missions with status `blocked` or `failed`), Policy
 * (pending approval requests), and Domain (handoffs marked blocked).
 *
 * Tolerant to any runtime being absent.
 */
class AtlasControlPlaneBlockerService
{
    public const SCHEMA = 'atlas.ai.control_plane.blocker.v1';

    /**
     * @return array<string,mixed>
     */
    public function snapshot(int $recentLimit = 20): array
    {
        $sources = [
            'evidence' => $this->evidenceBlockers($recentLimit),
            'missions' => $this->missionBlockers($recentLimit),
            'approvals' => $this->approvalRequests($recentLimit),
            'handoffs' => $this->handoffBlockers($recentLimit),
        ];

        $total = 0;
        $critical = 0;
        $allRecent = [];
        foreach ($sources as $bucket => $data) {
            $total += (int) ($data['count'] ?? 0);
            $critical += (int) ($data['critical'] ?? 0);
            foreach (($data['recent'] ?? []) as $row) {
                $allRecent[] = $row + ['source' => $bucket];
            }
        }

        usort(
            $allRecent,
            static fn ($a, $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')),
        );
        $allRecent = array_slice($allRecent, 0, $recentLimit);

        return [
            'schema' => self::SCHEMA,
            'total' => $total,
            'critical' => $critical,
            'by_source' => array_map(static fn (array $d): int => (int) ($d['count'] ?? 0), $sources),
            'sources' => $sources,
            'recent' => $allRecent,
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function evidenceBlockers(int $limit): array
    {
        $model = '\\App\\Models\\AiBlocker';
        if (! Schema::hasTable('ai_blockers') || ! class_exists($model)) {
            return ['status' => AtlasControlPlaneStatus::MISSING, 'count' => 0, 'critical' => 0, 'recent' => []];
        }
        try {
            $base = $model::query()->where('status', 'open');
            $count = (int) $base->clone()->count();
            $critical = (int) $base->clone()->where('severity', 'critical')->count();
            $recent = $base->clone()
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get()
                ->map(static fn ($b): array => [
                    'id' => $b->id,
                    'uuid' => $b->uuid,
                    'target_type' => $b->target_type,
                    'target_id' => $b->target_id,
                    'blocker_type' => $b->blocker_type,
                    'severity' => $b->severity,
                    'reason' => $b->reason,
                    'created_at' => optional($b->created_at)->toJSON(),
                ])->all();

            return [
                'status' => AtlasControlPlaneStatus::READY,
                'count' => $count,
                'critical' => $critical,
                'recent' => $recent,
            ];
        } catch (Throwable $e) {
            return ['status' => AtlasControlPlaneStatus::DEGRADED, 'count' => 0, 'critical' => 0, 'recent' => [], 'detail' => $e->getMessage()];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function missionBlockers(int $limit): array
    {
        $model = '\\App\\Models\\AiMission';
        if (! Schema::hasTable('ai_missions') || ! class_exists($model)) {
            return ['status' => AtlasControlPlaneStatus::MISSING, 'count' => 0, 'critical' => 0, 'recent' => []];
        }
        try {
            $base = $model::query()->whereIn('status', ['blocked', 'failed']);
            $count = (int) $base->clone()->count();
            $critical = (int) $base->clone()->where('risk_level', 'critical')->count();
            $recent = $base->clone()
                ->orderByDesc('updated_at')
                ->limit($limit)
                ->get()
                ->map(static fn ($m): array => [
                    'id' => $m->id,
                    'uuid' => $m->uuid,
                    'target_type' => 'mission',
                    'target_id' => $m->id,
                    'status' => $m->status,
                    'risk_level' => $m->risk_level,
                    'reason' => $m->blocker_reason,
                    'created_at' => optional($m->updated_at)->toJSON(),
                ])->all();

            return [
                'status' => AtlasControlPlaneStatus::READY,
                'count' => $count,
                'critical' => $critical,
                'recent' => $recent,
            ];
        } catch (Throwable $e) {
            return ['status' => AtlasControlPlaneStatus::DEGRADED, 'count' => 0, 'critical' => 0, 'recent' => [], 'detail' => $e->getMessage()];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function approvalRequests(int $limit): array
    {
        $model = '\\App\\Models\\AiApprovalRequest';
        if (! Schema::hasTable('ai_approval_requests') || ! class_exists($model)) {
            return ['status' => AtlasControlPlaneStatus::MISSING, 'count' => 0, 'critical' => 0, 'recent' => []];
        }
        try {
            $base = $model::query()->where('status', 'pending');
            $count = (int) $base->clone()->count();
            $recent = $base->clone()
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get()
                ->map(static fn ($a): array => [
                    'id' => $a->id,
                    'uuid' => $a->uuid,
                    'target_type' => 'approval_request',
                    'target_id' => $a->id,
                    'status' => $a->status,
                    'reason' => $a->requested_action,
                    'created_at' => optional($a->created_at)->toJSON(),
                ])->all();

            return [
                'status' => AtlasControlPlaneStatus::READY,
                'count' => $count,
                'critical' => 0,
                'recent' => $recent,
            ];
        } catch (Throwable $e) {
            return ['status' => AtlasControlPlaneStatus::DEGRADED, 'count' => 0, 'critical' => 0, 'recent' => [], 'detail' => $e->getMessage()];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function handoffBlockers(int $limit): array
    {
        $model = '\\App\\Models\\AiDomainHandoff';
        if (! Schema::hasTable('ai_domain_handoffs') || ! class_exists($model)) {
            return ['status' => AtlasControlPlaneStatus::MISSING, 'count' => 0, 'critical' => 0, 'recent' => []];
        }
        try {
            $base = $model::query()->whereIn('status', ['blocked', 'rejected', 'expired']);
            $count = (int) $base->clone()->count();
            $recent = $base->clone()
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get()
                ->map(static fn ($h): array => [
                    'id' => $h->id,
                    'uuid' => $h->uuid,
                    'target_type' => 'domain_handoff',
                    'target_id' => $h->id,
                    'status' => $h->status,
                    'reason' => $h->reason,
                    'created_at' => optional($h->created_at)->toJSON(),
                ])->all();

            return [
                'status' => AtlasControlPlaneStatus::READY,
                'count' => $count,
                'critical' => 0,
                'recent' => $recent,
            ];
        } catch (Throwable $e) {
            return ['status' => AtlasControlPlaneStatus::DEGRADED, 'count' => 0, 'critical' => 0, 'recent' => [], 'detail' => $e->getMessage()];
        }
    }
}
