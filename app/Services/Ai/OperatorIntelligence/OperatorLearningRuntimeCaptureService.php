<?php

namespace App\Services\Ai\OperatorIntelligence;

use App\Jobs\OperatorComprehensionExtractionJob;
use App\Models\AiTrace;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class OperatorLearningRuntimeCaptureService
{
    private const REQUIRED_TABLES = [
        'operator_learning_signals',
        'operator_learning_candidates',
        'operator_profile_items',
        'operator_profile_policy_rules',
    ];

    public function __construct(
        private readonly OperatorLearningSignalDetector $detector,
        private readonly OperatorSignalCaptureService $capture,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>|null
     */
    public function captureFromTrace(AiTrace $trace, string $input, array $options = []): ?array
    {
        if (! $this->isRuntimeCaptureAvailable($trace, $options)) {
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
            Log::warning('operator_learning_runtime_capture_failed', [
                'trace_id' => $trace->id,
                'source_type' => $trace->source_type,
                'error' => $e->getMessage(),
            ]);

            return [
                'schema_version' => 'atlas.operator_learning_runtime_capture.v1',
                'status' => 'failed',
                'reason' => 'exception',
                'trace_id' => (string) $trace->id,
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function isRuntimeCaptureAvailable(AiTrace $trace, array $options): bool
    {
        if (! (bool) config('atlas_operator_intelligence.enabled', true)) {
            return false;
        }
        if (! (bool) config('atlas_operator_intelligence.chat_capture_enabled', true)) {
            return false;
        }

        $allowed = config('atlas_operator_intelligence.chat_capture_source_types', ['manual', 'app', 'voice_realtime']);
        $allowed = is_array($allowed) ? $allowed : ['manual', 'app', 'voice_realtime'];
        $sourceType = (string) ($trace->source_type ?: ($options['source_type'] ?? ''));
        if (! in_array($sourceType, $allowed, true)) {
            return false;
        }

        foreach (self::REQUIRED_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function operatorId(array $options): string
    {
        $operatorId = data_get($options, 'payload.operator_id')
            ?: data_get($options, 'payload.operator.id')
            ?: data_get($options, 'operator_id')
            ?: config('atlas_operator_intelligence.default_operator_id', 'default');

        return is_string($operatorId) && trim($operatorId) !== ''
            ? trim($operatorId)
            : 'default';
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function scopeId(AiTrace $trace, array $options, string $scopeType): ?string
    {
        if ($scopeType === 'global') {
            return null;
        }

        return data_get($options, 'payload.workspace')
            ?: data_get($options, 'payload.project_id')
            ?: ($trace->thread_id ? (string) $trace->thread_id : null);
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function receipt(array $result): array
    {
        return [
            'schema_version' => 'atlas.operator_learning_runtime_capture.v1',
            'status' => 'captured',
            'signal_id' => data_get($result, 'signal.id'),
            'candidate_id' => data_get($result, 'candidate.id'),
            'candidate_status' => data_get($result, 'candidate.status'),
            'taxonomy_item_id' => data_get($result, 'signal.taxonomy_item_id'),
            'automation' => data_get($result, 'automation'),
        ];
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
}
