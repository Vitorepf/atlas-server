<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AtlasProject;
use App\Services\AtlasCode\AtlasCodeWorkPacketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Work Packet endpoints.
 *
 * Canon: docs/engineering-knowledge-base/atlas-code-interactive-observed-provider-workflow-v1.md
 *
 *   GET  /api/atlas-code/works/{project}/work-packets             · list
 *   POST /api/atlas-code/works/{project}/work-packets             · create
 *   GET  /api/atlas-code/works/{project}/work-packets/{packet}    · show
 *   POST /api/atlas-code/works/{project}/work-packets/{packet}/export · preview prompt+md without opening a session
 */
final class AtlasCodeWorkPacketController extends Controller
{
    public function __construct(private readonly AtlasCodeWorkPacketService $packets)
    {
    }

    public function index(AtlasProject $project): JsonResponse
    {
        return response()->json([
            'schema_version' => AtlasCodeWorkPacketService::SCHEMA_VERSION,
            'data' => $this->packets->listForObra((string) $project->getKey()),
        ]);
    }

    public function store(Request $request, AtlasProject $project): JsonResponse
    {
        $data = $request->validate([
            'objective' => ['required', 'string', 'max:480'],
            'context_summary' => ['nullable', 'string', 'max:4000'],
            'allowed_files' => ['nullable', 'array'],
            'allowed_files.*' => ['string', 'max:320'],
            'forbidden_files' => ['nullable', 'array'],
            'forbidden_files.*' => ['string', 'max:320'],
            'interfaces' => ['nullable', 'array'],
            'interfaces.*' => ['string', 'max:240'],
            'constraints' => ['nullable', 'array'],
            'constraints.*' => ['string', 'max:240'],
            'acceptance_criteria' => ['required', 'array', 'min:1'],
            'acceptance_criteria.*' => ['string', 'max:480'],
            'verification_commands' => ['nullable', 'array'],
            'verification_commands.*' => ['string', 'max:240'],
            'report_format' => ['nullable', 'string', 'max:2000'],
            'stop_rule' => ['nullable', 'string', 'max:1000'],
            'role_slot' => ['nullable', 'string', 'max:80'],
            'risk_band' => ['nullable', 'string', 'max:40'],
            'task_category' => ['nullable', 'string', 'max:80'],
            'evidence_required' => ['nullable', 'array'],
            'evidence_required.*' => ['string', 'max:80'],
        ]);

        $packet = $this->packets->create($project, $data);
        return response()->json(['packet' => $packet], 201);
    }

    public function show(AtlasProject $project, string $packet): JsonResponse
    {
        $row = $this->packets->find((string) $project->getKey(), $packet);
        if ($row === null) {
            return response()->json(['error' => 'work_packet_not_found', 'packet' => $packet], 404);
        }
        return response()->json(['packet' => $row]);
    }

    /**
     * Preview the export artifacts (prompt + markdown) without opening a session.
     * Useful for the operator to inspect what will be copied before clicking
     * "Abrir provider observado".
     */
    public function exportPreview(Request $request, AtlasProject $project, string $packet): JsonResponse
    {
        $data = $request->validate([
            'provider_id' => ['required', 'string', 'max:80'],
        ]);
        try {
            $result = $this->packets->exportForObservedSession((string) $project->getKey(), $packet, $data['provider_id']);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        return response()->json([
            'packet' => $result['packet'],
            'prompt' => $result['prompt'],
            'packet_md' => $result['packet_md'],
            'packet_md_status' => $result['packet_md_status'],
            'packet_md_path' => $result['packet_md_path'],
        ]);
    }
}
