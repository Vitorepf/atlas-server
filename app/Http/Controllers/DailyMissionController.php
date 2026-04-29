<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShowDailyMissionRequest;
use App\Http\Requests\UpsertDailyMissionRequest;
use App\Http\Resources\DailyMissionResource;
use App\Models\DailyMission;
use App\Support\Metadata;
use Illuminate\Http\JsonResponse;

class DailyMissionController extends Controller
{
    public function showToday(ShowDailyMissionRequest $request): JsonResponse
    {
        $data = $request->validated();
        [$date, $timezone] = $this->resolveDateAndTimezone($data);

        $mission = DailyMission::query()
            ->whereDate('mission_date', $date)
            ->first();

        return response()->json([
            'date' => $date,
            'timezone' => $timezone,
            'mission' => $mission ? (new DailyMissionResource($mission))->resolve() : null,
        ]);
    }

    public function upsertToday(UpsertDailyMissionRequest $request): JsonResponse
    {
        $data = $request->validated();
        [$date, $timezone] = $this->resolveDateAndTimezone($data);

        $mission = DailyMission::withTrashed()->firstOrNew(['mission_date' => $date]);
        $created = ! $mission->exists;

        $mission->fill([
            'mission_timezone' => $timezone,
            'title' => $data['title'],
            'detail' => $data['detail'] ?? null,
            'status' => $data['status'] ?? 'active',
            'metadata' => Metadata::forStorage($data['metadata'] ?? []),
        ]);
        $mission->save();

        if ($mission->trashed()) {
            $mission->restore();
        }

        return (new DailyMissionResource($mission->refresh()))
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    private function resolveDateAndTimezone(array $data): array
    {
        $timezone = $data['timezone'] ?? config('app.timezone', 'UTC');
        $date = $data['date'] ?? now($timezone)->toDateString();

        return [$date, $timezone];
    }
}
