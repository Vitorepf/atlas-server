<?php

namespace App\Http\Controllers;

use App\Http\Resources\AuditEventResource;
use App\Models\AuditEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min(200, (int) $request->integer('limit', 50)));

        $events = AuditEvent::query()
            ->when($request->query('event_type'), fn ($query, $type) => $query->where('event_type', $type))
            ->when($request->query('subject_type'), fn ($query, $type) => $query->where('subject_type', $type))
            ->when($request->query('subject_id'), fn ($query, $id) => $query->where('subject_id', $id))
            ->when($request->query('severity'), fn ($query, $severity) => $query->where('severity', $severity))
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'events' => AuditEventResource::collection($events)->resolve(),
        ]);
    }
}
