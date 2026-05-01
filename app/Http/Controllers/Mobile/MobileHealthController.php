<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\AtlasMobileDevice;
use App\Services\Ai\Mobile\MobileHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileHealthController extends Controller
{
    public function show(Request $request, MobileHealthService $health): JsonResponse
    {
        /** @var AtlasMobileDevice|null $device */
        $device = $request->attributes->get('atlas_mobile_device');
        $userId = $device?->user_id ?? 'vitor';

        return response()->json($health->snapshot($userId));
    }
}
