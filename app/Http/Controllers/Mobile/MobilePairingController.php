<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\MobileDeviceResource;
use App\Services\Ai\Mobile\MobileGatewayRateLimiter;
use App\Services\Ai\Mobile\MobilePairingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MobilePairingController extends Controller
{
    public function initiate(Request $request, MobilePairingService $pairing, MobileGatewayRateLimiter $rateLimiter): JsonResponse
    {
        $data = $request->validate([
            'device_label' => ['nullable', 'string', 'max:80'],
            'user_id' => ['nullable', 'string', 'max:255'],
        ]);
        $userId = $data['user_id'] ?? 'vitor';

        $rateLimiter->assertPairingInitiateAllowed($request, $userId);

        return response()->json($pairing->initiate(
            $data['device_label'] ?? 'Atlas mobile device',
            $userId,
        ));
    }

    public function confirm(Request $request, MobilePairingService $pairing, MobileGatewayRateLimiter $rateLimiter): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'min:4', 'max:32'],
            'pairing_id' => ['nullable', 'uuid'],
            'platform' => ['required', Rule::in(['ios', 'android'])],
            'device_label' => ['nullable', 'string', 'max:80'],
            'expo_push_token' => ['nullable', 'string', 'max:500'],
            'app_version' => ['nullable', 'string', 'max:32'],
            'os_version' => ['nullable', 'string', 'max:64'],
            'notification_permissions' => ['nullable', 'string', 'max:32'],
        ]);

        $rateLimiter->assertPairingConfirmAllowed($request, $data['pairing_id'] ?? null, $data['code']);

        $result = $pairing->confirm($data['code'], $data);

        return response()->json([
            'device_token' => $result['device_token'],
            'device' => (new MobileDeviceResource($result['device']))->resolve(),
        ]);
    }
}
