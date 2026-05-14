<?php

namespace App\Http\Controllers;

use App\Services\Ai\Programming\Sdd\Agents\SddAgentRoleRegistry;
use Illuminate\Http\JsonResponse;

class AtlasSddAgentRoleController extends Controller
{
    public function index(SddAgentRoleRegistry $registry): JsonResponse
    {
        return response()->json($registry->manifest());
    }

    public function show(string $name, SddAgentRoleRegistry $registry): JsonResponse
    {
        try {
            $role = $registry->get($name);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }

        return response()->json([
            'schema_version' => 'atlas.sdd_agent_role.v1',
            'key' => $name,
            'role' => $role,
        ]);
    }
}
