<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiTrace;
use App\Models\AtlasLiveActivityPushToken;
use App\Models\AtlasLiveActivityStartToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Registro do token ActivityKit de uma execução já existente.
 *
 * A rota fica na mesma lane `atlas.token` das interações nativas. Não reutiliza
 * o token Expo, não transforma credencial do dispositivo em token APNs e não
 * serializa o segredo de volta ao cliente.
 */
class AtlasLiveActivityController extends Controller
{
    private const LIVE_SESSIONS_SCHEMA = 'atlas.ai.sessions.live.v1';

    /** Active, provider-safe presentation sessions known for one installation. */
    public function liveSessions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'installation' => ['required', 'string', 'min:16', 'max:128'],
        ]);

        $sessions = AtlasLiveActivityPushToken::query()
            ->with(['trace.thread'])
            ->where('installation_id', $data['installation'])
            ->where('status', 'active')
            ->orderByDesc('started_at')
            ->limit(20)
            ->get()
            ->map(fn (AtlasLiveActivityPushToken $registration): ?array => $this->liveSessionReceipt($registration))
            ->filter()
            ->values()
            ->all();

        return response()->json([
            'schema_version' => self::LIVE_SESSIONS_SCHEMA,
            'count' => count($sessions),
            'sessions' => $sessions,
            'generated_at' => now()->toJSON(),
        ]);
    }

    public function storeStartToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'installation_id' => ['required', 'string', 'min:16', 'max:128'],
            'push_token' => ['required', 'string', 'max:1024'],
            'environment' => ['required', 'in:sandbox,production'],
        ]);

        $registration = AtlasLiveActivityStartToken::query()->firstOrNew([
            'installation_id' => $data['installation_id'],
        ]);
        $registration->fill([
            'push_token' => $data['push_token'],
            'push_token_hash' => hash('sha256', $data['push_token']),
            'environment' => $data['environment'],
            'last_seen_at' => now(),
        ])->save();

        return response()->json([
            'registration' => [
                'id' => $registration->id,
                'installation_id' => $registration->installation_id,
                'status' => 'active',
            ],
        ], $registration->wasRecentlyCreated ? 201 : 200);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'trace_id' => ['required', 'uuid'],
            'activity_id' => ['required', 'string', 'max:128'],
            'installation_id' => ['required', 'string', 'min:16', 'max:128'],
            'push_token' => ['required', 'string', 'max:1024'],
            'environment' => ['required', 'in:sandbox,production'],
            'started_at' => ['required', 'date'],
            'frequent_updates_enabled' => ['required', 'boolean'],
        ]);

        AiTrace::query()->findOrFail($data['trace_id']);
        $existing = AtlasLiveActivityPushToken::query()
            ->where('activity_id', $data['activity_id'])
            ->first();

        // Uma activity não pode trocar de trace. Isso evita que um token
        // rotativo de um turno seja sequestrado por outra execução.
        if ($existing && $existing->trace_id !== $data['trace_id']) {
            abort(409, 'A Live Activity já pertence a outra execução.');
        }

        $registration = $existing ?? new AtlasLiveActivityPushToken([
            'trace_id' => $data['trace_id'],
            'activity_id' => $data['activity_id'],
        ]);
        $registration->fill([
            'push_token' => $data['push_token'],
            'push_token_hash' => hash('sha256', $data['push_token']),
            'installation_id' => $data['installation_id'],
            'environment' => $data['environment'],
            'status' => 'active',
            'started_at' => $data['started_at'],
            'invalidated_at' => null,
            'last_seen_at' => now(),
            'frequent_updates_enabled' => (bool) $data['frequent_updates_enabled'],
        ])->save();

        return response()->json([
            'registration' => $this->receipt($registration->refresh()),
        ], $existing ? 200 : 201);
    }

    public function invalidate(Request $request, string $activityId): JsonResponse
    {
        $data = $request->validate([
            'trace_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:80'],
        ]);

        $registration = AtlasLiveActivityPushToken::query()
            ->where('activity_id', $activityId)
            ->where('trace_id', $data['trace_id'])
            ->firstOrFail();

        if ($registration->status !== 'invalidated') {
            $registration->update([
                'status' => 'invalidated',
                'invalidated_at' => now(),
                'last_seen_at' => now(),
            ]);
        }

        return response()->json([
            'registration' => $this->receipt($registration->refresh()),
        ]);
    }

    /** @return array{id:string,trace_id:string,activity_id:string,status:string} */
    private function receipt(AtlasLiveActivityPushToken $registration): array
    {
        return [
            'id' => $registration->id,
            'trace_id' => $registration->trace_id,
            'activity_id' => $registration->activity_id,
            'status' => $registration->status,
        ];
    }

    /** @return array{thread_id:?string,title:?string,phase_title:?string,timing:?string,elapsed_active_ms:?int,running_since:?string}|null */
    private function liveSessionReceipt(AtlasLiveActivityPushToken $registration): ?array
    {
        $trace = $registration->trace;
        if (! $trace || in_array($trace->status, ['succeeded', 'completed', 'failed', 'cancelled'], true)) {
            return null;
        }

        $metadata = is_array($trace->metadata) ? $trace->metadata : [];
        $presentation = data_get($metadata, 'presentation_state');
        $presentation = is_array($presentation) ? $presentation : [];
        $timer = data_get($presentation, 'timer');
        $timer = is_array($timer) ? $timer : [];

        return [
            'thread_id' => $trace->thread_id,
            'title' => $this->shortString($trace->thread?->title ?? data_get($metadata, 'thread_title')),
            'phase_title' => $this->shortString(
                data_get($presentation, 'phase_title')
                ?? data_get($presentation, 'phaseTitle')
                ?? data_get($presentation, 'title')
            ),
            'timing' => $this->timerTiming(data_get($timer, 'timing')),
            'elapsed_active_ms' => $this->nonNegativeInt(data_get($timer, 'elapsed_active_ms')),
            'running_since' => $this->shortString(data_get($timer, 'running_since'), 80),
        ];
    }

    private function shortString(mixed $value, int $limit = 160): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function timerTiming(mixed $value): ?string
    {
        return is_string($value) && in_array($value, ['running', 'paused', 'finished'], true)
            ? $value
            : null;
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        if (! is_int($value) && ! is_float($value) && ! is_numeric($value)) {
            return null;
        }

        return max(0, (int) $value);
    }
}
