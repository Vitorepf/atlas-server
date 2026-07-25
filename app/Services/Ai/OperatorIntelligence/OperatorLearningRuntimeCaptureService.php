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
     * (`payload.operator_text`), é isso que vale; quando não diz, `input_text` é
     * o melhor que existe e continua valendo — nunca inventamos um silêncio.
     *
     * @param  array<string,mixed>  $options
     */
    public static function operatorWords(string $input, array $options): string
    {
        return OperatorLearningRuntimeCaptureSupport::operatorWords($input, $options);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>|null
     */
    public function captureFromTrace(AiTrace $trace, string $input, array $options = []): ?array
    {
        $input = self::operatorWords($input, $options);
        $availability = $this->runtimeCaptureAvailability($trace, $options);
        if (! (bool) $availability['available']) {
            if (($availability['reason'] ?? null) === 'missing_operator_tables') {
                $failureCount = $this->incrementFailureCounter('missing_operator_tables', [
                    'trace_id' => (string) $trace->id,
                    'source_type' => (string) $trace->source_type,
                    'missing_tables' => $availability['missing_tables'],
                ]);

                return [
                    'schema_version' => 'atlas.operator_learning_runtime_capture.v1',
                    'status' => 'failed',
                    'reason' => 'missing_operator_tables',
                    'trace_id' => (string) $trace->id,
                    'missing_tables' => $availability['missing_tables'],
                    'failure_count' => $failureCount,
                ];
            }

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
