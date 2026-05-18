<?php

namespace App\Services\Ai\Evidence;

use App\Models\AiAuditEvent;
use App\Models\AiBlocker;
use App\Models\AiCertification;
use App\Models\AiClaim;
use App\Models\AiEvidencePack;
use App\Models\AiReceipt;

class EvidenceControlPlaneService
{
    public const SCHEMA = 'atlas.ai.evidence.control_plane.v1';

    /**
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $openBlockers = AiBlocker::query()->where('status', BlockerService::STATUS_OPEN)->count();
        $criticalBlockers = AiBlocker::query()
            ->where('status', BlockerService::STATUS_OPEN)
            ->where('severity', BlockerService::SEVERITY_CRITICAL)
            ->count();

        $certifications = AiCertification::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $unverifiedClaims = AiClaim::query()
            ->whereIn('verification_status', [
                ClaimVerificationService::STATUS_UNVERIFIED,
                ClaimVerificationService::STATUS_INSUFFICIENT,
                ClaimVerificationService::STATUS_BLOCKED,
            ])->count();

        $recentBlockers = AiBlocker::query()
            ->where('status', BlockerService::STATUS_OPEN)
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(static fn (AiBlocker $b): array => [
                'id' => $b->id,
                'target_type' => $b->target_type,
                'target_id' => $b->target_id,
                'kind' => $b->blocker_type,
                'severity' => $b->severity,
                'reason' => $b->reason,
                'created_at' => optional($b->created_at)->toJSON(),
            ])->all();

        $recentEvents = AiAuditEvent::query()
            ->latest('created_at')
            ->limit(20)
            ->get()
            ->map(static fn (AiAuditEvent $e): array => [
                'id' => $e->id,
                'event_type' => $e->event_type,
                'target_type' => $e->target_type,
                'target_id' => $e->target_id,
                'event_hash' => $e->event_hash,
                'created_at' => optional($e->created_at)->toJSON(),
            ])->all();

        $totals = [
            'evidence_packs' => AiEvidencePack::query()->count(),
            'receipts' => AiReceipt::query()->count(),
            'claims' => AiClaim::query()->count(),
            'certifications' => AiCertification::query()->count(),
            'blockers' => AiBlocker::query()->count(),
            'audit_events' => AiAuditEvent::query()->count(),
        ];

        return [
            'schema' => self::SCHEMA,
            'totals' => $totals,
            'certifications_by_status' => $certifications,
            'open_blockers_count' => $openBlockers,
            'critical_open_blockers_count' => $criticalBlockers,
            'unverified_claims_count' => $unverifiedClaims,
            'recent_blockers' => $recentBlockers,
            'recent_events' => $recentEvents,
            'generated_at' => now()->toJSON(),
        ];
    }
}
