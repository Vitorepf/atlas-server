<?php

namespace App\Http\Controllers;

use App\Services\Ai\Voice\AtlasVoiceRealtimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AtlasAiVoiceRealtimeController extends Controller
{
    public function __construct(private readonly AtlasVoiceRealtimeService $voice) {}

    public function health(Request $request): JsonResponse
    {
        return response()->json($this->voice->health($request->query()));
    }

    public function eclipse(Request $request): JsonResponse
    {
        return response()->json($this->voice->eclipseStatus($request->query()));
    }

    public function contract(Request $request): JsonResponse
    {
        return response()->json($this->voice->runtimeContract($request->query()));
    }

    public function bootstrap(Request $request): JsonResponse
    {
        return response()->json($this->voice->runtimeBootstrapManifest($request->query()));
    }

    public function start(Request $request): JsonResponse
    {
        return response()->json($this->voice->startSession($this->validateSessionPayload($request)));
    }

    public function turn(Request $request): JsonResponse
    {
        $data = $this->validateSessionPayload($request) + $request->validate([
            'turn_id' => ['nullable', 'string', 'max:120'],
            'audio_hash' => ['nullable', 'string', 'max:160'],
            'audio_duration_ms' => ['nullable', 'integer', 'min:0'],
            'audio_bytes' => ['prohibited'],
            'raw_audio' => ['prohibited'],
            'transcript' => ['nullable', 'string', 'max:12000'],
            'language' => ['nullable', 'string', 'max:20'],
            'domain_hint' => ['nullable', 'string', 'max:120'],
            'flow_hint' => ['nullable', 'string', 'max:120'],
            'turn_to_first_audio_ms' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json($this->voice->handleTurn($data));
    }

    public function interrupted(Request $request): JsonResponse
    {
        $data = $this->validateSessionPayload($request) + $request->validate([
            'turn_id' => ['nullable', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:120'],
            'interrupted_stage' => ['nullable', 'string', 'max:120'],
            'latency_ms' => ['nullable', 'integer', 'min:0'],
            'audio_bytes' => ['prohibited'],
            'raw_audio' => ['prohibited'],
        ]);

        return response()->json($this->voice->interruptTurn($data));
    }

    public function synthesized(Request $request): JsonResponse
    {
        $data = $this->validateRuntimeCallbackPayload($request) + $request->validate([
            'response_text' => ['nullable', 'string', 'max:12000'],
            'tts_provider' => ['nullable', 'string', 'max:120'],
            'audio_hash' => ['nullable', 'string', 'max:160'],
            'audio_duration_ms' => ['nullable', 'integer', 'min:0'],
            'audio_bytes' => ['prohibited'],
            'raw_audio' => ['prohibited'],
        ]);

        return response()->json($this->voice->recordSynthesis($data));
    }

    public function played(Request $request): JsonResponse
    {
        $data = $this->validateRuntimeCallbackPayload($request) + $request->validate([
            'played_duration_ms' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json($this->voice->recordPlayback($data));
    }

    public function failed(Request $request): JsonResponse
    {
        $data = $this->validateRuntimeCallbackPayload($request) + $request->validate([
            'failure_code' => ['nullable', 'string', 'max:160'],
            'error_class' => ['nullable', 'string', 'max:160'],
        ]);

        return response()->json($this->voice->recordRuntimeFailure($data));
    }

    public function providerHealth(Request $request): JsonResponse
    {
        $data = $this->validateRuntimeCallbackPayload($request) + $request->validate([
            'provider' => ['nullable', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:160'],
        ]);

        return response()->json($this->voice->recordProviderHealth($data));
    }

    public function end(Request $request): JsonResponse
    {
        $data = $this->validateSessionPayload($request) + $request->validate([
            'reason' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json($this->voice->endSession($data));
    }

    /**
     * @return array<string,mixed>
     */
    private function validateSessionPayload(Request $request): array
    {
        return $request->validate([
            'session_id' => ['nullable', 'string', 'max:120'],
            'envelope_id' => ['nullable', 'string', 'max:160'],
            'receipt_id' => ['nullable', 'string', 'max:160'],
            'tenant_id' => ['nullable', 'string', 'max:120'],
            'operator_id' => ['nullable', 'string', 'max:120'],
            'operator' => ['nullable', 'array'],
            'operator.tenant_id' => ['nullable', 'string', 'max:120'],
            'operator.operator_id' => ['nullable', 'string', 'max:120'],
            'client_surface' => ['nullable', 'string', 'max:80'],
            'transport' => ['nullable', 'string', 'max:80'],
            'runtime' => ['nullable', 'string', 'max:80'],
            'privacy_class' => ['nullable', 'string', 'max:80'],
            'eclipse_active' => ['nullable', 'boolean'],
            'explicit_operator_consent' => ['nullable', 'boolean'],
            'privacy' => ['nullable', 'array'],
            'privacy.private_meeting' => ['nullable', 'boolean'],
            'privacy.microphone_disabled' => ['nullable', 'boolean'],
            'privacy.class' => ['nullable', 'string', 'max:80'],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function validateRuntimeCallbackPayload(Request $request): array
    {
        return $this->validateSessionPayload($request) + $request->validate([
            'turn_id' => ['nullable', 'string', 'max:120'],
            'runtime' => ['nullable', 'string', 'max:120'],
            'provider' => ['nullable', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:120'],
            'latency_ms' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
