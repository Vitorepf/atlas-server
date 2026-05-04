<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\AtlasMaintenanceWindow;
use App\Models\AtlasPowerSession;
use App\Services\MacAgent\MacAgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MobileMacAgentController extends Controller
{
    public function status(MacAgentService $agent): JsonResponse
    {
        return response()->json($agent->status(refresh: true) + [
            'recent_events' => $agent->recentEvents(12),
            'maintenance_windows' => AtlasMaintenanceWindow::query()
                ->where('host_key', MacAgentService::HOST_KEY)
                ->where('enabled', true)
                ->orderBy('wake_time')
                ->limit(10)
                ->get()
                ->map(fn (AtlasMaintenanceWindow $window): array => $this->windowPayload($window))
                ->values()
                ->all(),
        ]);
    }

    public function startRemoteSession(Request $request, MacAgentService $agent): JsonResponse
    {
        $device = $request->attributes->get('atlas_mobile_device');
        $data = $request->validate([
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:720'],
            'reason' => ['nullable', 'string', 'max:240'],
        ]);

        $minutes = (int) ($data['duration_minutes'] ?? 240);
        $deviceId = is_object($device) ? (string) $device->id : null;
        $reason = (string) ($data['reason'] ?? 'Modo remoto mobile');
        $expiresAt = now()->addMinutes($minutes);
        $existing = $this->activeRemoteSessionForDevice($deviceId);

        if ($existing) {
            $metadata = $existing->metadata ?? [];
            $metadata['duration_minutes'] = $minutes;
            $metadata['mobile_device_label'] = is_object($device) ? ($device->device_label ?? null) : null;
            $metadata['renewed_at'] = now()->toJSON();
            $existing->update([
                'reason' => $reason,
                'expires_at' => $expiresAt,
                'metadata' => $metadata,
            ]);
            $session = $existing->refresh();
            $agent->event('power_session_renewed', 'info', 'Power session renewed from mobile.', [
                'duration_minutes' => $minutes,
                'expires_at' => $expiresAt->toJSON(),
            ], $session);
        } else {
            $session = $agent->startSession(
                kind: 'remote_manual',
                reason: $reason,
                expiresAt: $expiresAt,
                source: 'mobile_remote_mode',
                deviceId: $deviceId,
                metadata: [
                    'duration_minutes' => $minutes,
                    'mobile_device_label' => is_object($device) ? ($device->device_label ?? null) : null,
                ],
            );
        }

        return response()->json([
            'ok' => true,
            'session' => $agent->sessionPayload($session),
            'status' => $agent->status(refresh: true),
            'idempotent' => $existing !== null,
        ], $existing ? 200 : 201);
    }

    public function stopRemoteSession(string $session, MacAgentService $agent): JsonResponse
    {
        $model = AtlasPowerSession::query()
            ->where('host_key', MacAgentService::HOST_KEY)
            ->whereKey($session)
            ->first();

        if (! $model) {
            throw ValidationException::withMessages(['session' => 'Sessao de energia nao encontrada.']);
        }

        $stoppedSessions = [];
        $targets = AtlasPowerSession::query()
            ->where('host_key', MacAgentService::HOST_KEY)
            ->where('kind', 'remote_manual')
            ->where('status', 'active')
            ->where(function ($query) use ($model): void {
                if ($model->created_by_device_id) {
                    $query->where('created_by_device_id', $model->created_by_device_id);
                } else {
                    $query->whereKey($model->id);
                }
            })
            ->get();

        foreach ($targets as $target) {
            $stopped = $agent->stopSession($target, 'mobile_stop');
            if ($stopped) {
                $stoppedSessions[] = $agent->sessionPayload($stopped);
            }
        }

        return response()->json([
            'ok' => true,
            'session' => $stoppedSessions[0] ?? null,
            'stopped_sessions' => $stoppedSessions,
            'status' => $agent->status(refresh: true),
        ]);
    }

    public function sleepNow(MacAgentService $agent): JsonResponse
    {
        $ok = $agent->sleepNow('mobile_sleep_now');

        return response()->json([
            'ok' => $ok,
            'status' => $agent->status(refresh: true),
        ], $ok ? 200 : 409);
    }

    public function cleanupCaffeinate(MacAgentService $agent): JsonResponse
    {
        $cleanup = $agent->cleanupOrphanCaffeinateJobs();

        return response()->json([
            'ok' => true,
            'cleanup' => $cleanup,
            'status' => $agent->status(refresh: true),
        ]);
    }

    public function bootstrap(Request $request, MacAgentService $agent): JsonResponse
    {
        $data = $request->validate([
            'wake_time' => ['nullable', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ]);

        $bootstrap = $agent->safeBootstrap([
            'name' => 'Janela Atlas',
            'wake_time' => (string) ($data['wake_time'] ?? '02:00'),
            'duration_minutes' => (int) ($data['duration_minutes'] ?? 120),
            'timezone' => (string) ($data['timezone'] ?? MacAgentService::DEFAULT_TIMEZONE),
        ]);

        return response()->json($bootstrap + [
            'status' => $bootstrap['status'] + [
                'recent_events' => $agent->recentEvents(12),
                'maintenance_windows' => AtlasMaintenanceWindow::query()
                    ->where('host_key', MacAgentService::HOST_KEY)
                    ->where('enabled', true)
                    ->orderBy('wake_time')
                    ->limit(10)
                    ->get()
                    ->map(fn (AtlasMaintenanceWindow $item): array => $this->windowPayload($item))
                    ->values()
                    ->all(),
            ],
        ]);
    }

    public function storeMaintenanceWindow(Request $request, MacAgentService $agent): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'wake_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'days_of_week' => ['nullable', 'array'],
            'days_of_week.*' => ['integer', 'min:1', 'max:7'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $window = $agent->upsertMaintenanceWindow($data);

        return response()->json([
            'ok' => true,
            'window' => $this->windowPayload($window),
        ], 201);
    }

    public function deleteMaintenanceWindow(string $window): JsonResponse
    {
        $model = AtlasMaintenanceWindow::query()
            ->where('host_key', MacAgentService::HOST_KEY)
            ->whereKey($window)
            ->first();

        if (! $model) {
            throw ValidationException::withMessages(['window' => 'Janela de manutencao nao encontrada.']);
        }

        $model->delete();

        return response()->json(['ok' => true]);
    }

    private function windowPayload(AtlasMaintenanceWindow $window): array
    {
        return [
            'id' => $window->id,
            'name' => $window->name,
            'enabled' => $window->enabled,
            'timezone' => $window->timezone,
            'wake_time' => $window->wake_time,
            'duration_minutes' => $window->duration_minutes,
            'days_of_week' => $window->days_of_week ?? [],
            'last_scheduled_at' => $window->last_scheduled_at?->toJSON(),
            'last_started_at' => $window->last_started_at?->toJSON(),
            'last_completed_at' => $window->last_completed_at?->toJSON(),
            'metadata' => $window->metadata ?? [],
        ];
    }

    private function activeRemoteSessionForDevice(?string $deviceId): ?AtlasPowerSession
    {
        if (! $deviceId) {
            return null;
        }

        return AtlasPowerSession::query()
            ->where('host_key', MacAgentService::HOST_KEY)
            ->where('kind', 'remote_manual')
            ->where('source', 'mobile_remote_mode')
            ->where('status', 'active')
            ->where('created_by_device_id', $deviceId)
            ->orderByDesc('created_at')
            ->first();
    }
}
