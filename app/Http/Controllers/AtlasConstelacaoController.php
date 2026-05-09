<?php

namespace App\Http\Controllers;

use App\Models\AtlasMobileDevice;
use App\Services\Ai\Surface\ConstelacaoPositionsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtlasConstelacaoController extends Controller
{
    public function positions(Request $request, ConstelacaoPositionsService $positions): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'domain' => ['nullable', 'string', 'max:80'],
            'lens' => ['nullable', 'string', 'max:40'],
            'tenant_id' => ['nullable', 'string', 'max:120'],
            'operator_id' => ['nullable', 'string', 'max:120'],
        ]);

        /** @var AtlasMobileDevice|null $device */
        $device = $request->attributes->get('atlas_mobile_device');
        if ($device instanceof AtlasMobileDevice) {
            $data['operator_id'] ??= (string) $device->user_id;
            $data['client_surface'] = 'atlas_app_mobile';
        }

        return response()->json($positions->positions($data));
    }
}
