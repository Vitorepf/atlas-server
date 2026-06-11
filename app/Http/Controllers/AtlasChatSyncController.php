<?php

namespace App\Http\Controllers;

use App\Services\Ai\AiGatewayService;
use App\Services\Ai\AiWorker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * G7 — a ponte SÍNCRONA texto→resultado do chat.
 *
 * O chat sempre foi async-only (202 + job em background + SSE/polling). Esta
 * ponte fecha o ciclo numa ÚNICA chamada HTTP: o MESMO pipeline de criação
 * (AiGatewayService::enqueueInteraction — router, placement, gates idênticos)
 * seguido da MESMA execução do worker (AiWorker::runNextForTrace — o caminho
 * que o worker desktop usa), só que inline no request. Nenhuma máquina nova de
 * execução: 100% reuso dos dois trilhos provados (o padrão que o Vox provou).
 *
 * Gasto: ocorre apenas quando o OPERADOR posta aqui (nunca autônomo). Flag
 * default-OFF; latência limitada pelo timeout normal do job + o teto raise-only
 * do request (o mesmo padrão do create path).
 */
class AtlasChatSyncController extends Controller
{
    public function store(Request $request, AiGatewayService $gateway, AiWorker $worker): JsonResponse
    {
        if (! (bool) config('atlas.ai.sync_bridge.enabled', false)) {
            return response()->json([
                'status' => 'disabled',
                'flag' => 'ATLAS_AI_SYNC_BRIDGE_ENABLED',
            ], 503);
        }

        $validated = $request->validate([
            'input' => ['required', 'string', 'min:2', 'max:16000'],
            'options' => ['nullable', 'array'],
        ]);

        // Raise-only (espelha o create path): a execução inline pode levar minutos.
        $ceiling = max(60, (int) config('atlas.ai.sync_bridge.max_execution_seconds', 420));
        if ((int) ini_get('max_execution_time') !== 0 && (int) ini_get('max_execution_time') < $ceiling) {
            @set_time_limit($ceiling);
        }

        $options = is_array($validated['options'] ?? null) ? $validated['options'] : [];
        $options['surface'] = $options['surface'] ?? 'sync_bridge';

        $trace = $gateway->enqueueInteraction($validated['input'], $options);

        try {
            $job = $worker->runNextForTrace((string) $trace->id);
        } catch (Throwable $exception) {
            // A execução inline falhou DEPOIS do trace existir: resposta honesta
            // com o trace para o caller cair no caminho async normal (poll/SSE).
            return response()->json([
                'schema_version' => 'atlas.ai.sync_bridge.v1',
                'status' => 'execution_error',
                'trace_id' => (string) $trace->id,
                'error' => mb_substr($exception->getMessage(), 0, 500),
                'fallback' => 'GET /ai/interactions/'.$trace->id,
            ], 200);
        }

        if ($job === null) {
            // Nada elegível para executar inline (ex.: outro worker pegou antes) —
            // o caller usa o trilho async existente. Honesto, nunca trava.
            return response()->json([
                'schema_version' => 'atlas.ai.sync_bridge.v1',
                'status' => 'pending_async',
                'trace_id' => (string) $trace->id,
                'fallback' => 'GET /ai/interactions/'.$trace->id,
            ], 202);
        }

        return response()->json([
            'schema_version' => 'atlas.ai.sync_bridge.v1',
            'status' => (string) $job->status,
            'trace_id' => (string) $trace->id,
            'job_id' => (string) $job->id,
            'provider' => (string) $job->provider,
            'result_text' => $job->result_text,
            'error_code' => $job->error_code,
            'error_message' => $job->error_message,
        ]);
    }
}
