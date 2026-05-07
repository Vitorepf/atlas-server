<?php

namespace App\Http\Controllers;

use App\Services\Ai\Voice\AtlasVoiceRealtimeService;
use App\Services\Ai\Voice\AtlasVoiceRivalsRunner;
use App\Services\Ai\Voice\AtlasVoiceRuntimeCertificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AtlasAiVoiceRealtimeController extends Controller
{
    public function __construct(
        private readonly AtlasVoiceRealtimeService $voice,
        private readonly AtlasVoiceRivalsRunner $rivals,
        private readonly AtlasVoiceRuntimeCertificationService $certification,
    ) {}

    public function health(Request $request): JsonResponse
    {
        return response()->json($this->voice->health($request->query()));
    }

    public function readiness(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hours' => ['nullable', 'integer', 'between:1,8760'],
        ]);

        return response()->json($this->voice->readiness($data));
    }

    public function rivals(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hours' => ['nullable', 'integer', 'between:1,8760'],
            'runtime' => ['nullable', 'in:livekit_agents_sdk'],
            'base_url' => ['nullable', 'string', 'max:240'],
            'require_sdk' => ['nullable', 'boolean'],
        ]);

        return response()->json($this->rivals->report($data));
    }

    public function eclipse(Request $request): JsonResponse
    {
        return response()->json($this->voice->eclipseStatus($request->query()));
    }

    public function contract(Request $request): JsonResponse
    {
        $data = $request->validate([
            'runtime' => ['nullable', 'in:livekit_agents_sdk'],
        ]);

        return response()->json($this->voice->runtimeContract($data));
    }

    public function bootstrap(Request $request): JsonResponse
    {
        $data = $request->validate([
            'runtime' => ['nullable', 'in:livekit_agents_sdk'],
            'base_url' => ['nullable', 'string', 'max:240'],
        ]);

        return response()->json($this->voice->runtimeBootstrapManifest($data));
    }

    public function dependencies(Request $request): JsonResponse
    {
        $request->validate([
            'runtime' => ['nullable', 'in:livekit_agents_sdk'],
        ]);

        return response()->json($this->voice->runtimeDependencyPlan());
    }

    public function runtimeCertification(Request $request): JsonResponse
    {
        $data = $request->validate([
            'runtime' => ['nullable', 'in:livekit_agents_sdk'],
            'base_url' => ['nullable', 'string', 'max:240'],
            'require_sdk' => ['nullable', 'boolean'],
        ]);

        return response()->json($this->certification->certify($data));
    }

    public function start(Request $request): JsonResponse
    {
        return response()->json($this->voice->startSession($this->validateSessionPayload($request)));
    }

    public function turn(Request $request): JsonResponse
    {
        $data = $this->validateSessionPayload($request) + $request->validate([
            'turn_id' => ['nullable', 'string', 'max:120'],
            'audio_hash' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/i'],
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

    public function wakeWord(Request $request): JsonResponse
    {
        $data = $this->validateSessionPayload($request) + $request->validate([
            'wake_word_engine' => ['nullable', 'string', 'max:120'],
            'confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'latency_ms' => ['nullable', 'integer', 'min:0'],
            'audio_bytes' => ['prohibited'],
            'raw_audio' => ['prohibited'],
        ]);

        return response()->json($this->voice->recordWakeWord($data));
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
            'response_text' => ['prohibited'],
            'raw_response_text' => ['prohibited'],
            'tts_text' => ['prohibited'],
            'response_text_hash' => ['nullable', 'required_without:audio_hash', 'string', 'regex:/^[a-f0-9]{64}$/i'],
            'tts_provider' => ['nullable', 'string', 'max:120'],
            'audio_hash' => ['nullable', 'required_without:response_text_hash', 'string', 'regex:/^[a-f0-9]{64}$/i'],
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
            'client_surface' => ['nullable', 'in:mobile,mac_edge'],
            'transport' => ['nullable', 'in:mobile_push_to_talk,livekit_webrtc'],
            'runtime' => ['nullable', 'in:livekit_agents_sdk'],
            'room_name' => ['nullable', 'string', 'max:120'],
            'participant_identity' => ['nullable', 'string', 'max:120'],
            'privacy_class' => ['nullable', 'in:p1_public,p2_internal,p3_audio,p4_secret'],
            'eclipse_active' => ['nullable', 'boolean'],
            'explicit_operator_consent' => ['nullable', 'boolean'],
            'privacy' => ['nullable', 'array'],
            'privacy.private_meeting' => ['nullable', 'boolean'],
            'privacy.microphone_disabled' => ['nullable', 'boolean'],
            'privacy.class' => ['nullable', 'in:p1_public,p2_internal,p3_audio,p4_secret'],
            'rivals_arm' => ['nullable', 'in:atlas_voice,direct_provider_baseline'],
            'access_token' => ['prohibited'],
            'token' => ['prohibited'],
            'livekit_token' => ['prohibited'],
            'api_key' => ['prohibited'],
            'api_secret' => ['prohibited'],
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
