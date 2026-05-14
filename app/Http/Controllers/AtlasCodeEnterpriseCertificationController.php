<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Ai\Programming\AtlasCodeEnterpriseCertificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Product-facing Atlas Code enterprise certification endpoint.
 *
 * The CLI remains the canonical replay command; this endpoint exposes the same
 * proof packet to Atlas Code so the desktop surface can certify itself without
 * a shell-only escape hatch.
 */
final class AtlasCodeEnterpriseCertificationController extends Controller
{
    public function show(AtlasCodeEnterpriseCertificationService $service): JsonResponse
    {
        $report = $service->latest();

        if ($report === null) {
            return response()->json([
                'schema_version' => 'atlas.code.enterprise_certification_read_model.v1',
                'status' => 'missing',
                'latest' => null,
                'next_action' => 'run_atlas_code_enterprise_certification',
            ]);
        }

        return response()->json($report);
    }

    public function store(Request $request, AtlasCodeEnterpriseCertificationService $service): JsonResponse
    {
        $data = $request->validate([
            'keep_workspace' => ['nullable', 'boolean'],
        ]);

        $report = $service->certify([
            'keep_workspace' => (bool) ($data['keep_workspace'] ?? false),
        ]);

        return response()->json(
            $report,
            ($report['atlas_code_enterprise_status'] ?? null) === 'passed' ? 201 : 409,
        );
    }
}
