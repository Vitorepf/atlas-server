<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiTrace;
use App\Models\AtlasLiveActivityPushToken;
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
}
