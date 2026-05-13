<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Services\Ai\Mobile\MobilePushService;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtlasMobilePushReplayController extends Controller
{
    private const PRIOR_DRY_RUN_MAX_AGE_MINUTES = 15;

    public function __invoke(Request $request, MobilePushService $push, AuditLogService $audit): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'between:1,200'],
            'apply' => ['nullable', 'boolean'],
            'confirm_external_dispatch' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $apply = (bool) ($data['apply'] ?? false);
        if ($apply && (! (bool) ($data['confirm_external_dispatch'] ?? false) || trim((string) ($data['reason'] ?? '')) === '')) {
            return response()->json([
                'status' => 'confirmation_required',
                'message' => 'External mobile push replay requires explicit confirmation and an operator reason.',
                'rules' => [
                    'dry_run_default' => true,
                    'external_notification_possible' => true,
                    'confirm_external_dispatch_required' => true,
                    'operator_reason_required' => true,
                ],
            ], 422);
        }

        $actorId = (string) ($request->user()?->getAuthIdentifier() ?? 'api');

        if ($apply && ! $this->hasRecentDryRunReceipt($actorId)) {
            return response()->json([
                'status' => 'prior_dry_run_required',
                'message' => 'External mobile push replay requires a recent dry-run receipt with candidates before apply.',
                'rules' => [
                    'dry_run_default' => true,
                    'prior_dry_run_required' => true,
                    'prior_dry_run_max_age_minutes' => self::PRIOR_DRY_RUN_MAX_AGE_MINUTES,
                    'prior_dry_run_candidate_required' => true,
                    'external_notification_possible' => true,
                    'confirm_external_dispatch_required' => true,
                    'operator_reason_required' => true,
                ],
            ], 422);
        }

        $result = [
            ...$push->replayPendingDispatches((int) ($data['limit'] ?? 50), ! $apply),
            'operator_reason' => $apply ? trim((string) $data['reason']) : null,
            'external_dispatch_confirmed' => $apply,
        ];

        $audit->record('mobile.push_replay.requested', [
            'subject_type' => 'mobile_push_replay',
            'actor_type' => 'operator',
            'actor_id' => $actorId,
            'severity' => $apply ? 'warning' : 'info',
            'summary' => $apply
                ? 'Operator applied pending mobile push replay.'
                : 'Operator ran pending mobile push replay dry-run.',
            'evidence' => [
                'schema_version' => 'atlas.mobile.push_replay.operator_receipt.v1',
                'dry_run' => (bool) $result['dry_run'],
                'limit' => (int) $result['limit'],
                'candidate_count' => (int) $result['candidate_count'],
                'dispatched_count' => (int) $result['dispatched_count'],
                'mobile_enabled' => (bool) $result['mobile_enabled'],
                'external_dispatch_confirmed' => (bool) $result['external_dispatch_confirmed'],
                'operator_reason_hash' => $apply ? hash('sha256', trim((string) $data['reason'])) : null,
                'item_ids' => collect((array) $result['items'])->pluck('id')->values()->all(),
            ],
            'privacy' => [
                'classification' => 'operational',
                'raw_push_tokens_exposed' => false,
                'raw_device_ids_exposed' => false,
                'operator_reason_stored_as_hash' => true,
            ],
        ]);

        return response()->json([
            'status' => 'ok',
            'push_replay' => $result,
        ]);
    }

    private function hasRecentDryRunReceipt(string $actorId): bool
    {
        return AuditEvent::query()
            ->where('event_type', 'mobile.push_replay.requested')
            ->where('actor_type', 'operator')
            ->where('actor_id', $actorId)
            ->where('occurred_at', '>=', now()->subMinutes(self::PRIOR_DRY_RUN_MAX_AGE_MINUTES))
            ->latest('occurred_at')
            ->limit(20)
            ->get()
            ->contains(function (AuditEvent $event): bool {
                return (bool) data_get($event->evidence, 'dry_run')
                    && (int) data_get($event->evidence, 'candidate_count', 0) > 0;
            });
    }
}
