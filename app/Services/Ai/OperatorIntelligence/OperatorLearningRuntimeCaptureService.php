<?php

namespace App\Services\Ai\OperatorIntelligence;

use App\Jobs\OperatorComprehensionExtractionJob;
use App\Models\AiTrace;
use App\Services\Ai\OperatorIntelligence\Support\OperatorLearningRuntimeCaptureSupport;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class OperatorLearningRuntimeCaptureService
{
    public const REQUIRED_TABLES = [
        'operator_learning_signals',
        'operator_learning_candidates',
        'operator_profile_items',
        'operator_profile_policy_rules',
        'operator_profile_feedback_events',
        'operator_profile_snapshots',
        'operator_pattern_detections',
        'operator_skill_proposals',
    ];

    public const FAILURE_COUNTER_CACHE_KEY = 'atlas.operator_learning_runtime_capture.failure_count';

    public const LAST_FAILURE_CACHE_KEY = 'atlas.operator_learning_runtime_capture.last_failure';

    public function __construct(
        private readonly OperatorLearningSignalDetector $detector,
        private readonly OperatorSignalCaptureService $capture,
    ) {}

    /**
     * O que o OPERADOR escreveu — nunca a muleta que a superfície anexou.
     *
     * Medido em 15/07/2026: 8 dos 13 sinais aprendidos sobre o operador eram
     * prosa da própria máquina — "O coletor determinístico leu o Git e o ledger
     * de `atlas-server` agora e apurou…" — gravada como REGRA DELE. O Atlas
     * estava aprendendo quem o operador é a partir de frases que o operador
     * nunca escreveu.
     *
     * A causa é estrutural, não um bug de string: superfícies legitimamente
     * prefixam contexto no `input_text` — o card do Código anexa os fatos do
     * git, a mensagem longa vira ponteiro. Para o modelo aquilo é entrada. Para
     * quem aprende QUEM É O OPERADOR, é a máquina se ouvindo falar e anotando
     * como se fosse ele. Num substrato de soberania pessoal esse é o pior
     * estrago possível: volta como "regra dele" para sempre, e ele não escreveu
     * nada daquilo.
     *
     * A guarda mora aqui, e não no gateway, porque é lei do aprendizado: quem
     * for aprender o operador amanhã, por outra porta, herda a proteção sem
     * precisar saber que ela existe.
     *
     * Quem sabe o que o operador digitou é a superfície. Quando ela diz
     * (`payload.operator_text`), é isso que vale. Quando não diz, NÃO
     * aprendemos — a versão anterior desta guarda caía em `input_text` "porque
     * é o melhor que existe ali", e a medição derrubou essa premissa: nenhuma
     * superfície jamais mandou `operator_text` (zero produtores no repo), então
     * o galho do fallback era 100% das capturas, e `input_text` não é o melhor
     * que existe — é o prompt montado. Nos 13 sinais vivos ele trazia ~1,5k de
     * fatos que o próprio Atlas colheu mais o preâmbulo de sistema; a fala do
     * operador eram as quatro palavras no fim.
     *
     * Não aprender é recuperável; gravar a voz da máquina como regra dele não é.
     *
     * @param  array<string,mixed>  $options
     */
    public static function operatorWords(array $options): ?string
    {
        return OperatorLearningRuntimeCaptureSupport::declaredOperatorWords($options);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>|null
     */
    public function captureFromTrace(AiTrace $trace, string $input, array $options = []): ?array
    {
        $declared = self::operatorWords($options);
        if ($declared === null) {
            // Não é silêncio inventado: é recusa a atribuir ao operador um texto
            // que ninguém disse ser dele. O recibo torna a lacuna CONTÁVEL, para
            // a superfície que falta aparecer como número e não como sumiço.
            //
            // O recibo era MONTADO e devolvido, e ninguém o gravava: `attachReceipt` só
            // era chamado no caminho de sucesso, e o único caller
            // (`AiGatewayService::captureOperatorLearningFromTrace`) descarta o retorno.
            // A lacuna que este bloco existe para tornar contável não aparecia em lugar
            // nenhum — nem no trace, nem no contador de falhas, que só conta `failed`.
            // Uma recusa que não deixa marca é indistinguível de uma captura que nunca
            // foi tentada, e é justamente a diferença entre "falta a superfície" e
            // "está tudo bem".
            return $this->comRecibo($trace, [
                'schema_version' => 'atlas.operator_learning_runtime_capture.v1',
                'status' => 'skipped',
                'reason' => 'operator_text_not_declared',
                'trace_id' => (string) $trace->id,
                'source_type' => (string) $trace->source_type,
            ]);
        }
        $input = $declared;
        $availability = $this->runtimeCaptureAvailability($trace, $options);
        if (! (bool) $availability['available']) {
            if (($availability['reason'] ?? null) === 'missing_operator_tables') {
                $failureCount = $this->incrementFailureCounter('missing_operator_tables', [
                    'trace_id' => (string) $trace->id,
                    'source_type' => (string) $trace->source_type,
                    'missing_tables' => $availability['missing_tables'],
                ]);

                return $this->comRecibo($trace, [
                    'schema_version' => 'atlas.operator_learning_runtime_capture.v1',
                    'status' => 'failed',
                    'reason' => 'missing_operator_tables',
                    'trace_id' => (string) $trace->id,
                    'missing_tables' => $availability['missing_tables'],
                    'failure_count' => $failureCount,
                ]);
            }

            // Sem recibo de propósito: `chat_capture_disabled` e o gate de source type
            // não são LACUNA, são "este trace nunca foi candidato". Marcar todo trace de
            // agente/sistema encheria a metadata da maioria absoluta das interações com
            // um aviso que não descreve defeito nenhum.
            return null;
        }

        $operatorId = $this->operatorId($options);

        // Per-turn deferred comprehension — dispatched OFF the hot path so the LLM
        // extractor runs on every meaningful turn (beyond the regex floor) with zero
        // added latency. Default-OFF; runs BEFORE the regex return so it fires even
        // when the regex finds nothing (the whole point of comprehension).
        if ((bool) config('atlas_operator_intelligence.comprehension_per_turn_enabled', true)
            && ! app()->runningUnitTests()
            && mb_strlen(trim($input)) >= 16) {
            try {
                OperatorComprehensionExtractionJob::dispatch((string) $trace->id, $operatorId);
            } catch (Throwable) {
                // best-effort; the inline regex floor below still runs
            }
        }

        $detected = $this->detector->detect($input, ['operator_id' => $operatorId]);
        if ($detected === null) {
            return null;
        }

        try {
            $payload = array_merge($detected, [
                'operator_id' => $operatorId,
                'source_type' => 'chat_explicit_operator_signal',
                'source_ref_type' => 'ai_trace',
                'source_ref_id' => (string) $trace->id,
                'trace_id' => (string) $trace->id,
                'session_id' => $trace->session_id ? (string) $trace->session_id : null,
                'scope_id' => $this->scopeId($trace, $options, (string) ($detected['scope_type'] ?? 'global')),
                'evidence_refs' => [
                    [
                        'type' => 'ai_trace',
                        'id' => (string) $trace->id,
                    ],
                ],
                'metadata' => array_merge((array) ($detected['metadata'] ?? []), [
                    'runtime_capture' => 'ai_gateway.enqueue_interaction',
                    // Marca de origem: a superfície declarou este texto como do
                    // operador. Quem minerar amanhã distingue isto das linhas
                    // antigas, gravadas do prompt montado, sem reler o conteúdo.
                    'operator_text_declared' => true,
                    'gateway_source_type' => $trace->source_type,
                    'gateway_kind' => $options['kind'] ?? 'interaction',
                    'app_surface' => data_get($options, 'payload.app_surface'),
                    'thread_id' => $trace->thread_id ? (string) $trace->thread_id : null,
                ]),
            ]);

            $result = $this->capture->capture($payload);
            $receipt = $this->receipt($result);
            $this->attachReceipt($trace, $receipt);

            return $receipt;
        } catch (Throwable $e) {
            $failureCount = $this->incrementFailureCounter('exception', [
                'trace_id' => (string) $trace->id,
                'source_type' => (string) $trace->source_type,
                'error' => $e->getMessage(),
            ]);

            Log::warning('operator_learning_runtime_capture_failed', [
                'trace_id' => $trace->id,
                'source_type' => $trace->source_type,
                'error' => $e->getMessage(),
                'failure_count' => $failureCount,
            ]);

            return [
                'schema_version' => 'atlas.operator_learning_runtime_capture.v1',
                'status' => 'failed',
                'reason' => 'exception',
                'trace_id' => (string) $trace->id,
                'failure_count' => $failureCount,
            ];
        }
    }

    /**
     * @return array{schema_version:string,enabled:bool,chat_capture_enabled:bool,required_tables:list<string>,missing_tables:list<string>,failure_count:int,last_failure:array<string,mixed>|null,persistence:string}
     */
    public function captureFailureReport(): array
    {
        $lastFailure = Cache::get(self::LAST_FAILURE_CACHE_KEY);

        return [
            'schema_version' => 'atlas.operator_learning_runtime_capture_failures.v1',
            'enabled' => (bool) config('atlas_operator_intelligence.enabled', true),
            'chat_capture_enabled' => (bool) config('atlas_operator_intelligence.chat_capture_enabled', true),
            'required_tables' => self::REQUIRED_TABLES,
            'missing_tables' => DatabaseTableAvailability::missing(self::REQUIRED_TABLES),
            'failure_count' => (int) Cache::get(self::FAILURE_COUNTER_CACHE_KEY, 0),
            'last_failure' => is_array($lastFailure) ? $lastFailure : null,
            'persistence' => 'cache_counter_no_jsonl',
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array{available:bool,reason:string|null,missing_tables:list<string>}
     */
    private function runtimeCaptureAvailability(AiTrace $trace, array $options): array
    {
        if (! (bool) config('atlas_operator_intelligence.enabled', true)) {
            return ['available' => false, 'reason' => 'operator_intelligence_disabled', 'missing_tables' => []];
        }
        if (! (bool) config('atlas_operator_intelligence.chat_capture_enabled', true)) {
            return ['available' => false, 'reason' => 'chat_capture_disabled', 'missing_tables' => []];
        }

        $allowed = config('atlas_operator_intelligence.chat_capture_source_types', ['manual', 'app', 'voice_realtime']);
        $allowed = is_array($allowed) ? $allowed : ['manual', 'app', 'voice_realtime'];
        $sourceType = (string) ($trace->source_type ?: ($options['source_type'] ?? ''));
        $sourceGate = OperatorLearningRuntimeCaptureSupport::sourceTypeGate($sourceType, $allowed);
        if (! (bool) $sourceGate['available']) {
            return $sourceGate;
        }

        $missingTables = DatabaseTableAvailability::missing(self::REQUIRED_TABLES);
        if ($missingTables !== []) {
            return ['available' => false, 'reason' => 'missing_operator_tables', 'missing_tables' => $missingTables];
        }

        return ['available' => true, 'reason' => null, 'missing_tables' => []];
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function operatorId(array $options): string
    {
        return OperatorLearningRuntimeCaptureSupport::resolveOperatorId(
            $options,
            (string) config('atlas_operator_intelligence.default_operator_id', 'default'),
        );
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function scopeId(AiTrace $trace, array $options, string $scopeType): ?string
    {
        return OperatorLearningRuntimeCaptureSupport::scopeIdFromOptions(
            $options,
            $trace->thread_id ? (string) $trace->thread_id : null,
            $scopeType,
        );
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function receipt(array $result): array
    {
        return OperatorLearningRuntimeCaptureSupport::receipt($result);
    }

    /**
     * Grava o recibo no trace e o devolve. Existe para que uma recusa deixe MARCA no
     * mesmo lugar onde a captura bem-sucedida deixa — o caller descarta o retorno, entao
     * o valor devolvido nunca foi observabilidade de verdade.
     *
     * Nunca deixa a recusa virar exceção: se o próprio trace não puder ser atualizado, o
     * recibo ainda volta. Perder a marca é ruim; derrubar a interação do operador por
     * causa da marca seria pior.
     *
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    private function comRecibo(AiTrace $trace, array $receipt): array
    {
        try {
            $this->attachReceipt($trace, $receipt);
        } catch (\Throwable $e) {
            Log::warning('operator_learning_receipt_attach_failed', [
                'trace_id' => (string) $trace->id,
                'reason' => $receipt['reason'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        return $receipt;
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function attachReceipt(AiTrace $trace, array $receipt): void
    {
        $metadata = is_array($trace->metadata) ? $trace->metadata : [];
        $metadata['operator_learning_capture'] = $receipt;

        $trace->forceFill(['metadata' => $metadata])->save();
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function incrementFailureCounter(string $reason, array $context): int
    {
        Cache::add(self::FAILURE_COUNTER_CACHE_KEY, 0);
        $count = Cache::increment(self::FAILURE_COUNTER_CACHE_KEY);
        $count = is_int($count) ? $count : ((int) Cache::get(self::FAILURE_COUNTER_CACHE_KEY, 0) + 1);

        Cache::put(self::FAILURE_COUNTER_CACHE_KEY, $count);
        Cache::put(self::LAST_FAILURE_CACHE_KEY, array_merge($context, [
            'reason' => $reason,
            'recorded_at' => now()->toIso8601String(),
            'failure_count' => $count,
        ]));

        return $count;
    }
}
