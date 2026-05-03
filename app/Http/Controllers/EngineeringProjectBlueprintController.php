<?php

namespace App\Http\Controllers;

use App\Models\AtlasProject;
use App\Services\Engineering\EngineeringProjectBlueprintService;
use App\Services\Engineering\EngineeringTaskGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EngineeringProjectBlueprintController extends Controller
{
    public function show(AtlasProject $project, EngineeringProjectBlueprintService $blueprints): JsonResponse
    {
        $latest = $blueprints->latest($project);
        $frozen = $blueprints->latest($project, 'frozen');

        return response()->json([
            'project_id' => $project->id,
            'latest' => $latest ? $blueprints->payload($latest) : null,
            'frozen' => $frozen ? $blueprints->payload($frozen) : null,
            'blueprint' => $latest?->blueprint_json,
        ]);
    }

    public function prepare(Request $request, AtlasProject $project, EngineeringProjectBlueprintService $blueprints): JsonResponse
    {
        return response()->json($blueprints->prepare($project, $request->validate([
            'created_by' => ['nullable', 'string', 'max:120'],
        ])));
    }

    public function create(Request $request, AtlasProject $project, EngineeringProjectBlueprintService $blueprints): JsonResponse
    {
        $payload = $blueprints->create($project, $request->validate([
            'created_by' => ['nullable', 'string', 'max:120'],
        ]));

        return response()->json($payload, 201);
    }

    public function validateBlueprint(Request $request, AtlasProject $project, EngineeringProjectBlueprintService $blueprints): JsonResponse
    {
        return response()->json($blueprints->validateProject($project, $request->validate([
            'version' => ['nullable', 'integer', 'min:1'],
        ])));
    }

    public function freeze(Request $request, AtlasProject $project, EngineeringProjectBlueprintService $blueprints): JsonResponse
    {
        return response()->json($blueprints->freeze($project, $request->validate([
            'version' => ['nullable', 'integer', 'min:1'],
            'exception_reason' => ['nullable', 'string', 'max:2000'],
            'approved_by' => ['nullable', 'string', 'max:120'],
            'created_by' => ['nullable', 'string', 'max:120'],
        ])), 201);
    }

    public function generateTasks(
        Request $request,
        AtlasProject $project,
        EngineeringProjectBlueprintService $blueprints,
        EngineeringTaskGenerationService $tasks,
    ): JsonResponse {
        $data = $request->validate([
            'version' => ['nullable', 'integer', 'min:1'],
            'force' => ['nullable', 'boolean'],
        ]);

        $record = $blueprints->latest($project, 'frozen');
        if (isset($data['version'])) {
            $record = $project->engineeringProjectBlueprints()
                ->where('version', (int) $data['version'])
                ->where('status', 'frozen')
                ->first();
        }

        abort_unless($record, 404);

        return response()->json($tasks->generate($record, $data), 201);
    }
}
