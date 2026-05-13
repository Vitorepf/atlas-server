<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AiDecision;
use App\Models\AtlasLedgerEvent;
use Illuminate\Http\JsonResponse;

/**
 * Atlas Code · Decision Receipt v2 normalized contract.
 *
 * Legacy `/ai/decisions/{decision}` returns `{ decision: ... }` and exposes
 * raw fields that don't map 1:1 to Receipt v2. This wrapper produces a
 * stable shape for the desktop right rail:
 *
 *   GET /api/atlas-code/decisions/{decision}/receipt
 *
 * Shape mirrors @atlas/domain · DecisionReceipt.
 */
final class AtlasCodeReceiptShowController extends Controller
{
    public function show(AiDecision $decision): JsonResponse
    {
        $signals = is_array($decision->signals) ? $decision->signals : [];
        $candidates = is_array($decision->candidates) ? $decision->candidates : [];
        $constraints = is_array($decision->constraints) ? $decision->constraints : [];

        // Confidence band
        $score = (int) ($decision->confidence_score ?? 0);
        $confidence = match (true) {
            $score >= 80 => 'high',
            $score >= 50 => 'medium',
            $score > 0 => 'low',
            default => 'unknown',
        };

        $fallback = [];
        foreach ($candidates as $cand) {
            if (! is_array($cand)) continue;
            $name = $cand['provider'] ?? $cand['name'] ?? null;
            if (is_string($name) && $name !== ($decision->selected_provider ?? null)) {
                $fallback[] = (string) $name;
            }
        }

        $signature = $this->latestSignature($decision);

        return response()->json([
            'id' => (string) $decision->getKey(),
            'traceId' => $decision->trace_id ? (string) $decision->trace_id : null,
            'primary' => (string) ($decision->selected_provider ?? 'unknown'),
            'model' => (string) ($decision->selected_model ?? ''),
            'confidence' => $confidence,
            'confidenceScore' => $score,
            'routeMode' => (string) ($decision->route_mode ?? ''),
            'taskType' => (string) ($decision->task_type ?? ''),
            'riskLevel' => (string) ($decision->risk_level ?? ''),
            'budgetEstUsd' => (float) ($constraints['budget_est_usd'] ?? 0),
            'budgetUsedUsd' => (float) (data_get($decision->metrics_snapshot, 'budget_used_usd') ?? 0),
            'fallbackChain' => array_values(array_unique($fallback)),
            'signedBy' => $signature['signed_by'],
            'signature' => $signature['signature'],
            'signedAt' => $signature['signed_at'],
            'reason' => (string) ($decision->reason ?? ''),
            'createdAt' => $decision->created_at?->toJSON(),
        ]);
    }

    /**
     * @return array{signed_by: ?string, signature: ?string, signed_at: ?string}
     */
    private function latestSignature(AiDecision $decision): array
    {
        $event = AtlasLedgerEvent::query()
            ->where('receipt_id', (string) $decision->getKey())
            ->where('event_type', 'atlas_code.receipt.signed')
            ->latest('occurred_at')
            ->first();

        if (! $event) {
            return ['signed_by' => null, 'signature' => null, 'signed_at' => null];
        }

        return [
            'signed_by' => is_string(data_get($event->payload, 'signer_id')) ? data_get($event->payload, 'signer_id') : null,
            'signature' => is_string(data_get($event->payload, 'signature')) ? data_get($event->payload, 'signature') : null,
            'signed_at' => is_string(data_get($event->payload, 'signed_at')) ? data_get($event->payload, 'signed_at') : null,
        ];
    }
}
