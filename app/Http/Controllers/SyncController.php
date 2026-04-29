<?php

namespace App\Http\Controllers;

use App\Http\Requests\SyncRequest;
use App\Http\Resources\BehaviorLogResource;
use App\Http\Resources\BehaviorResource;
use App\Http\Resources\CaptureResource;
use App\Http\Resources\CheckinResource;
use App\Http\Resources\DigitalActivitySnapshotResource;
use App\Http\Resources\DigitalSessionResource;
use App\Http\Resources\HealthSnapshotResource;
use App\Http\Resources\PassiveSignalResource;
use App\Models\Behavior;
use App\Models\BehaviorLog;
use App\Models\Capture;
use App\Models\Checkin;
use App\Models\DigitalActivitySnapshot;
use App\Models\DigitalSession;
use App\Models\HealthSnapshot;
use App\Models\PassiveSignal;
use App\Models\SyncLog;
use App\Services\BitaculaService;
use App\Services\CaptureService;
use App\Support\Metadata;
use Illuminate\Http\JsonResponse;

class SyncController extends Controller
{
    public function __invoke(SyncRequest $request, CaptureService $captures, BitaculaService $bitacula): JsonResponse
    {
        $startedAt = microtime(true);
        $data = $request->validated();
        $lastSyncAt = $data['last_sync_at'] ?? null;
        $syncedAt = now();
        $capturesUploaded = 0;
        $checkinsUploaded = 0;
        $passiveSignalsUploaded = 0;
        $healthSnapshotsUploaded = 0;
        $behaviorsUploaded = 0;
        $behaviorLogsUploaded = 0;
        $digitalSessionsUploaded = 0;
        $digitalSnapshotsUploaded = 0;

        foreach ($data['captures_to_upload'] ?? [] as $captureData) {
            $captures->create([
                ...$captureData,
                'domain' => $captureData['domain'] ?? 'outro',
                'metadata' => Metadata::forStorage($captureData['metadata'] ?? []),
            ]);
            $capturesUploaded++;
        }

        foreach ($data['checkins_to_upload'] ?? [] as $checkinData) {
            Checkin::withTrashed()->firstOrCreate(
                ['client_id' => $checkinData['client_id']],
                [
                    ...$checkinData,
                    'metadata' => Metadata::forStorage($checkinData['metadata'] ?? []),
                ],
            );
            $checkinsUploaded++;
        }

        foreach ($data['passive_signals_to_upload'] ?? [] as $signalData) {
            $signal = PassiveSignal::withTrashed()
                ->where('client_id', $signalData['client_id'])
                ->first();
            $payload = [
                ...$signalData,
                'metadata' => Metadata::forStorage($signalData['metadata'] ?? []),
            ];

            if ($signal) {
                $signal->fill($payload);
                if ($signal->isDirty()) {
                    $signal->save();
                }
            } else {
                PassiveSignal::create($payload);
            }

            $passiveSignalsUploaded++;
        }

        foreach ($data['health_snapshots_to_upload'] ?? [] as $snapshotData) {
            $snapshot = HealthSnapshot::withTrashed()
                ->where('client_id', $snapshotData['client_id'])
                ->first();
            $payload = $this->normalizeHealthSnapshotPayload($snapshotData);

            if ($snapshot) {
                $snapshot->fill($payload);
                if ($snapshot->isDirty()) {
                    $snapshot->save();
                }

                if ($snapshot->trashed()) {
                    $snapshot->restore();
                }
            } else {
                HealthSnapshot::create($payload);
            }

            $healthSnapshotsUploaded++;
        }

        foreach ($data['behaviors_to_upload'] ?? [] as $behaviorData) {
            $bitacula->upsertBehavior($behaviorData);
            $behaviorsUploaded++;
        }

        foreach ($data['behavior_logs_to_upload'] ?? [] as $logData) {
            $bitacula->upsertBehaviorLog($logData);
            $behaviorLogsUploaded++;
        }

        foreach ($data['digital_sessions_to_upload'] ?? [] as $sessionData) {
            $session = DigitalSession::withTrashed()
                ->where('client_id', $sessionData['client_id'])
                ->first();
            $payload = [
                ...$sessionData,
                'raw_payload' => Metadata::forStorage($sessionData['raw_payload'] ?? []),
                'metadata' => Metadata::forStorage($sessionData['metadata'] ?? []),
            ];

            if ($session) {
                $session->fill($payload);
                if ($session->isDirty()) {
                    $session->save();
                }

                if ($session->trashed()) {
                    $session->restore();
                }
            } else {
                DigitalSession::create($payload);
            }

            $digitalSessionsUploaded++;
        }

        foreach ($data['digital_activity_snapshots_to_upload'] ?? [] as $snapshotData) {
            $snapshot = DigitalActivitySnapshot::withTrashed()
                ->where('client_id', $snapshotData['client_id'])
                ->first();
            $payload = [
                ...$snapshotData,
                'focus_mode_active_min' => Metadata::forStorage($snapshotData['focus_mode_active_min'] ?? []),
                'category_breakdown' => Metadata::forStorage($snapshotData['category_breakdown'] ?? []),
                'source_breakdown' => Metadata::forStorage($snapshotData['source_breakdown'] ?? []),
                'raw_rize_data' => Metadata::forStorage($snapshotData['raw_rize_data'] ?? []),
                'raw_screentime_data' => Metadata::forStorage($snapshotData['raw_screentime_data'] ?? []),
                'metadata' => Metadata::forStorage($snapshotData['metadata'] ?? []),
            ];

            if ($snapshot) {
                $snapshot->fill($payload);
                if ($snapshot->isDirty()) {
                    $snapshot->save();
                }

                if ($snapshot->trashed()) {
                    $snapshot->restore();
                }
            } else {
                DigitalActivitySnapshot::create($payload);
            }

            $digitalSnapshotsUploaded++;
        }

        $capturesToDownload = Capture::withTrashed()
            ->when($lastSyncAt, fn ($query) => $query->where('updated_at', '>', $lastSyncAt))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit(200)
            ->get();

        $checkinsToDownload = Checkin::withTrashed()
            ->when($lastSyncAt, fn ($query) => $query->where('updated_at', '>', $lastSyncAt))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit(200)
            ->get();

        $passiveSignalsToDownload = PassiveSignal::withTrashed()
            ->when($lastSyncAt, fn ($query) => $query->where('updated_at', '>', $lastSyncAt))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit(200)
            ->get();

        $healthSnapshotsToDownload = HealthSnapshot::withTrashed()
            ->when($lastSyncAt, fn ($query) => $query->where('updated_at', '>', $lastSyncAt))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit(200)
            ->get();

        $behaviorsToDownload = Behavior::withTrashed()
            ->when($lastSyncAt, fn ($query) => $query->where('updated_at', '>', $lastSyncAt))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit(200)
            ->get();

        $behaviorLogsToDownload = BehaviorLog::withTrashed()
            ->when($lastSyncAt, fn ($query) => $query->where('updated_at', '>', $lastSyncAt))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit(200)
            ->get();

        $digitalSessionsToDownload = DigitalSession::withTrashed()
            ->when($lastSyncAt, fn ($query) => $query->where('updated_at', '>', $lastSyncAt))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit(200)
            ->get();

        $digitalSnapshotsToDownload = DigitalActivitySnapshot::withTrashed()
            ->when($lastSyncAt, fn ($query) => $query->where('updated_at', '>', $lastSyncAt))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit(200)
            ->get();

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        SyncLog::create([
            'device_id' => $data['device_id'],
            'synced_at' => $syncedAt,
            'captures_uploaded' => $capturesUploaded,
            'captures_downloaded' => $capturesToDownload->count(),
            'checkins_uploaded' => $checkinsUploaded,
            'checkins_downloaded' => $checkinsToDownload->count(),
            'passive_signals_uploaded' => $passiveSignalsUploaded,
            'passive_signals_downloaded' => $passiveSignalsToDownload->count(),
            'health_snapshots_uploaded' => $healthSnapshotsUploaded,
            'health_snapshots_downloaded' => $healthSnapshotsToDownload->count(),
            'behaviors_uploaded' => $behaviorsUploaded,
            'behaviors_downloaded' => $behaviorsToDownload->count(),
            'behavior_logs_uploaded' => $behaviorLogsUploaded,
            'behavior_logs_downloaded' => $behaviorLogsToDownload->count(),
            'digital_sessions_uploaded' => $digitalSessionsUploaded,
            'digital_sessions_downloaded' => $digitalSessionsToDownload->count(),
            'digital_snapshots_uploaded' => $digitalSnapshotsUploaded,
            'digital_snapshots_downloaded' => $digitalSnapshotsToDownload->count(),
            'duration_ms' => $durationMs,
            'metadata' => Metadata::forStorage($data['metadata'] ?? []),
        ]);

        return response()->json([
            'synced_at' => $syncedAt->toJSON(),
            'captures_uploaded' => $capturesUploaded,
            'captures_downloaded' => CaptureResource::collection($capturesToDownload)->resolve(),
            'checkins_uploaded' => $checkinsUploaded,
            'checkins_downloaded' => CheckinResource::collection($checkinsToDownload)->resolve(),
            'passive_signals_uploaded' => $passiveSignalsUploaded,
            'passive_signals_downloaded' => PassiveSignalResource::collection($passiveSignalsToDownload)->resolve(),
            'health_snapshots_uploaded' => $healthSnapshotsUploaded,
            'health_snapshots_downloaded' => HealthSnapshotResource::collection($healthSnapshotsToDownload)->resolve(),
            'behaviors_uploaded' => $behaviorsUploaded,
            'behaviors_downloaded' => BehaviorResource::collection($behaviorsToDownload)->resolve(),
            'behavior_logs_uploaded' => $behaviorLogsUploaded,
            'behavior_logs_downloaded' => BehaviorLogResource::collection($behaviorLogsToDownload)->resolve(),
            'digital_sessions_uploaded' => $digitalSessionsUploaded,
            'digital_sessions_downloaded' => DigitalSessionResource::collection($digitalSessionsToDownload)->resolve(),
            'digital_snapshots_uploaded' => $digitalSnapshotsUploaded,
            'digital_snapshots_downloaded' => DigitalActivitySnapshotResource::collection($digitalSnapshotsToDownload)->resolve(),
            'next_full_sync_recommended_at' => $syncedAt->copy()->addHour()->toJSON(),
        ]);
    }

    private function normalizeHealthSnapshotPayload(array $snapshotData): array
    {
        return [
            ...$snapshotData,
            'confidence' => $this->normalizeConfidence($snapshotData['confidence'] ?? null),
            'metrics' => Metadata::forStorage($snapshotData['metrics'] ?? []),
            'readiness' => Metadata::forStorage($snapshotData['readiness'] ?? []),
            'sleep' => Metadata::forStorage($snapshotData['sleep'] ?? []),
            'recovery' => Metadata::forStorage($snapshotData['recovery'] ?? []),
            'load' => Metadata::forStorage($snapshotData['load'] ?? []),
            'subjective' => Metadata::forStorage($snapshotData['subjective'] ?? []),
            'body' => Metadata::forStorage($snapshotData['body'] ?? []),
            'metadata' => Metadata::forStorage($snapshotData['metadata'] ?? []),
        ];
    }

    private function normalizeConfidence(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $number = (float) $value;
        if (! is_finite($number)) {
            return null;
        }

        if ($number > 1) {
            $number /= 100;
        }

        return max(0.0, min(1.0, round($number, 3)));
    }
}
