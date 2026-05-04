<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\AtlasMaintenanceWindow;
use App\Models\AtlasPowerSession;
use App\Services\MacAgent\MacAgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class MobileMacAgentController extends Controller
{
    public function status(MacAgentService $agent): JsonResponse
    {
        return response()->json($agent->status(refresh: true) + [
            'recent_events' => $agent->recentEvents(12),
            'maintenance_windows' => AtlasMaintenanceWindow::query()
                ->where('host_key', MacAgentService::HOST_KEY)
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
        $session = $agent->startSession(
            kind: 'remote_manual',
            reason: (string) ($data['reason'] ?? 'Modo remoto mobile'),
            expiresAt: now()->addMinutes($minutes),
            source: 'mobile_remote_mode',
            deviceId: is_object($device) ? (string) $device->id : null,
            metadata: [
                'duration_minutes' => $minutes,
                'mobile_device_label' => is_object($device) ? ($device->device_label ?? null) : null,
            ],
        );

        return response()->json([
            'ok' => true,
            'session' => $agent->sessionPayload($session),
            'status' => $agent->status(refresh: true),
        ], 201);
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

        $stopped = $agent->stopSession($model, 'mobile_stop');

        return response()->json([
            'ok' => true,
            'session' => $stopped ? $agent->sessionPayload($stopped) : null,
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
}
