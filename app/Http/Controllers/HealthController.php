<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $dbConnected = true;

        try {
            DB::connection()->getPdo();
        } catch (\Throwable) {
            $dbConnected = false;
        }

        return response()->json([
            'status' => 'ok',
            'version' => config('app.version', '1.0.0'),
            'service' => 'atlas-server',
            'ts' => now()->toJSON(),
            'db_connected' => $dbConnected,
        ]);
    }
}
