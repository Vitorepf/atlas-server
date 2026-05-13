<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\MobileDeviceResource;
use App\Models\AtlasMobileDevice;
use App\Services\Ai\Mobile\MobileNotificationPreferences;
use App\Services\Ai\Mobile\MobilePairingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileDeviceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $devices = AtlasMobileDevice::query()
            ->where('user_id', $device->user_id)
            ->latest('paired_at')
            ->get();

        return response()->json([
            'current_device_id' => $device->id,
            'current_device' => (new MobileDeviceResource($device))->resolve(),
            'devices' => MobileDeviceResource::collection($devices)->resolve(),
        ]);
    }

    public function updatePushToken(Request $request, MobilePairingService $pairing): JsonResponse
    {
        $data = $request->validate([
            'expo_push_token' => ['nullable', 'string', 'max:500'],
            'notification_permissions' => ['nullable', 'string', 'max:32'],
        ]);

        $device = $pairing->updatePushToken(
            $this->device($request),
            $data['expo_push_token'] ?? null,
            $data['notification_permissions'] ?? null,
        );

        return response()->json([
            'device' => (new MobileDeviceResource($device))->resolve(),
        ]);
    }

    public function updateNotificationPreferences(Request $request, MobileNotificationPreferences $preferences): JsonResponse
    {
        $data = $request->validate([
            'critical_push_enabled' => ['sometimes', 'boolean'],
            'telemetry_health_push_enabled' => ['sometimes', 'boolean'],
            'daily_report_push_enabled' => ['sometimes', 'boolean'],
            'quiet_hours_enabled' => ['sometimes', 'boolean'],
            'proactive_push_enabled' => ['sometimes', 'boolean'],
            'manual_eclipse_enabled' => ['sometimes', 'boolean'],
        ]);

        $device = $this->device($request);
        $metadata = is_array($device->metadata) ? $device->metadata : [];
        $metadata[MobileNotificationPreferences::METADATA_KEY] = $preferences->merge($device, $data);

        $device->update([
            'metadata' => $metadata,
            'last_seen_at' => now(),
        ]);

        return response()->json([
            'device' => (new MobileDeviceResource($device->refresh()))->resolve(),
        ]);
    }

    public function revoke(Request $request, AtlasMobileDevice $device, MobilePairingService $pairing): JsonResponse
    {
        $current = $this->device($request);
        abort_unless($device->user_id === $current->user_id, 404);

        $revoked = $pairing->revoke($device, 'mobile_device');

        return response()->json([
            'device' => (new MobileDeviceResource($revoked))->resolve(),
        ]);
    }

    private function device(Request $request): AtlasMobileDevice
    {
        /** @var AtlasMobileDevice $device */
        $device = $request->attributes->get('atlas_mobile_device');

        return $device;
    }
}
