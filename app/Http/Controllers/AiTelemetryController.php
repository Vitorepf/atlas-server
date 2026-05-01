<?php

namespace App\Http\Controllers;

use App\Models\AtlasMobileDevice;
use App\Services\Ai\Telemetry\AiTelemetryCollector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiTelemetryController extends Controller
{
    public function store(Request $request, AiTelemetryCollector $collector): JsonResponse
    {
        $maxBatch = max(1, (int) config('atlas.ai_metrics.telemetry_max_batch', 100));

        $data = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:'.$maxBatch],
            'events.*.event_key' => ['nullable', 'string', 'max:180'],
            'events.*.correlation_id' => ['nullable', 'uuid'],
            'events.*.trace_id' => ['nullable', 'uuid'],
            'events.*.thread_id' => ['nullable', 'uuid'],
            'events.*.session_id' => ['nullable', 'uuid'],
            'events.*.ai_job_id' => ['nullable', 'uuid'],
            'events.*.ai_job_attempt_id' => ['nullable', 'uuid'],
            'events.*.client_id' => ['nullable', 'uuid'],
            'events.*.surface' => ['required', 'string', 'in:'.implode(',', AiTelemetryCollector::SURFACES)],
            'events.*.runtime' => ['nullable', 'string', 'in:'.implode(',', AiTelemetryCollector::RUNTIMES)],
            'events.*.app_version' => ['nullable', 'string', 'max:64'],
            'events.*.cli_version' => ['nullable', 'string', 'max:64'],
            'events.*.provider' => ['nullable', 'string', 'max:80'],
            'events.*.model' => ['nullable', 'string', 'max:120'],
            'events.*.agent_slug' => ['nullable', 'string', 'max:120'],
            'events.*.event_name' => ['required', 'string', 'max:100'],
            'events.*.event_phase' => ['nullable', 'string', 'max:60'],
            'events.*.occurred_at_client' => ['nullable', 'date'],
            'events.*.duration_ms' => ['nullable', 'integer', 'min:0'],
            'events.*.numeric_value' => ['nullable', 'numeric'],
            'events.*.unit' => ['nullable', 'string', 'max:32'],
            'events.*.metadata' => ['nullable', 'array'],
            'events.*.privacy' => ['nullable', 'array'],
            'events.*.schema_version' => ['nullable', 'integer', 'min:1', 'max:32767'],
        ]);

        $device = $request->attributes->get('atlas_mobile_device');
        $events = array_map(
            fn (array $event): array => $this->eventForRequest($event, $device instanceof AtlasMobileDevice ? $device : null),
            $data['events'],
        );

        return response()->json($collector->recordBatch($events), 202);
    }

    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    private function eventForRequest(array $event, ?AtlasMobileDevice $device): array
    {
        if (! $device) {
            return $event;
        }

        $metadata = is_array($event['metadata'] ?? null) ? $event['metadata'] : [];

        return array_merge($event, [
            'surface' => 'mobile',
            'runtime' => in_array($device->platform, ['ios', 'android'], true)
                ? $device->platform
                : ($event['runtime'] ?? null),
            'app_version' => $event['app_version'] ?? $device->app_version,
            'metadata' => array_merge($metadata, [
                'atlas_mobile_device_id' => $device->id,
                'atlas_mobile_platform' => $device->platform,
            ]),
        ]);
    }
}
