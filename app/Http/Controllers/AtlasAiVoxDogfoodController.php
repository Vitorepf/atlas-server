<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Ai\Vox\Dogfood\VoxDogfoodService;
use App\Services\Ai\Vox\VoxSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Atlas Vox Wave 7.9 (Claude Z) — read/write surface for dogfood session
 * evidence. Distinct from rivals (`AtlasAiVoxMetricsController`):
 *
 *   - POST /ai/vox/dogfood/session — Vitor records one real Vox session.
 *   - GET  /ai/vox/dogfood/report  — aggregated dogfood report.
 *
 * Hard rules:
 *   - This controller NEVER executes anything, never calls a provider,
 *     never reaches into `Services/Ai/Voice/`.
 *   - Raw audio fields are 422-rejected (Lei-0.75) just like rivals.
 *   - `notes` is capped at 1000 chars; `metadata` is small JSON.
 *   - V4 unlock is not touched here.
 */
final class AtlasAiVoxDogfoodController extends Controller
{
    public function __construct(
        private readonly VoxDogfoodService $dogfood,
    ) {}

    public function recordSession(Request $request): JsonResponse
    {
        $this->rejectAudioFields($request);

        $payload = $request->validate([
            'mode' => ['required', 'string', 'in:'.implode(',', VoxDogfoodService::allowedModes())],
            'outcome' => ['required', 'string', 'in:'.implode(',', VoxDogfoodService::allowedOutcomes())],
            'vox_session_id' => ['nullable', 'string', 'max:120'],
            'started_at' => ['nullable', 'string', 'max:40'],
            'duration_ms' => ['nullable', 'integer', 'min:0', 'max:86400000'],
            'used_hotkey' => ['nullable', 'boolean'],
            'used_real_stt' => ['nullable', 'boolean'],
            'used_governed_execute' => ['nullable', 'boolean'],
            'regret_flag' => ['nullable', 'boolean'],
            'eclipse_used' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:'.VoxDogfoodService::NOTES_MAX_CHARS],
            'metadata' => ['nullable', 'array'],
        ]);

        // Belt-and-braces: the service guards again, but we validate here so
        // the client gets a clean 422 with field-level errors before the
        // service throws InvalidArgumentException.
        if (isset($payload['metadata']) && is_array($payload['metadata'])) {
            $encoded = json_encode($payload['metadata']);
            if ($encoded === false || strlen($encoded) > VoxDogfoodService::METADATA_MAX_BYTES) {
                throw ValidationException::withMessages([
                    'metadata' => 'metadata must encode to at most '.VoxDogfoodService::METADATA_MAX_BYTES.' bytes',
                ]);
            }
        }

        try {
            $result = $this->dogfood->record($payload);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['dogfood' => $e->getMessage()]);
        }

        /** @var \App\Models\AtlasVoxDogfoodSession $session */
        $session = $result['session'];

        return response()->json([
            'schema' => VoxDogfoodService::SCHEMA_SESSION,
            'session' => [
                'dogfood_session_id' => $session->dogfood_session_id,
                'vox_session_id' => $session->vox_session_id,
                'mode' => $session->mode,
                'outcome' => $session->outcome,
                'used_hotkey' => $session->used_hotkey,
                'used_real_stt' => $session->used_real_stt,
                'used_governed_execute' => $session->used_governed_execute,
                'regret_flag' => $session->regret_flag,
                'eclipse_used' => $session->eclipse_used,
                'started_at' => $session->started_at?->toIso8601String(),
                'duration_ms' => $session->duration_ms,
                'notes' => $session->notes,
                'metadata' => $session->metadata,
                'created_at' => $session->created_at?->toIso8601String(),
            ],
            'events' => [$result['event']],
        ], 201);
    }

    public function report(): JsonResponse
    {
        return response()->json($this->dogfood->report());
    }

    /**
     * Mirror the rivals controller's audio-field rejection. There's no
     * legitimate reason for a dogfood payload to ever carry audio bytes,
     * and Lei-0.75 forbids raw audio at the Kernel boundary.
     */
    private function rejectAudioFields(Request $request): void
    {
        $forbidden = VoxSchema::prohibitedAudioFields();
        $all = $request->all();
        foreach ($forbidden as $field) {
            if (array_key_exists($field, $all)) {
                throw ValidationException::withMessages([
                    $field => "Field '{$field}' is forbidden — raw audio must never reach the Kernel (Lei 0.75)",
                ]);
            }
        }
    }
}
