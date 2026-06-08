<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AiTrace;
use App\Services\Ai\OperatorIntelligence\OperatorComprehensionExtractor;
use App\Services\Ai\OperatorIntelligence\OperatorSignalCaptureService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * The per-turn deferred surface of the comprehension extractor — runs the LLM OFF the
 * hot path (queued, shortly after an interaction) so capture happens on EVERY use
 * without adding any latency to POST /ai/interactions. Each grounded signal feeds the
 * unchanged governed pipeline (shadow / needs-review). Phase 1 = learn only.
 */
final class OperatorComprehensionExtractionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $traceId,
        public readonly string $operatorId,
    ) {}

    public function handle(OperatorComprehensionExtractor $extractor, OperatorSignalCaptureService $capture): void
    {
        if ((string) config('atlas_operator_intelligence.comprehension_extraction_mode', 'observe') === 'off') {
            return;
        }

        try {
            $trace = AiTrace::query()->find($this->traceId);
            $text = $trace ? trim((string) $trace->operator_input) : '';
            if ($text === '') {
                return;
            }

            foreach ($extractor->extract($text, ['operator_id' => $this->operatorId]) as $signal) {
                $capture->capture(array_merge($signal, [
                    'operator_id' => $this->operatorId,
                    'source_type' => 'chat_comprehension',
                    'source_ref_type' => 'ai_trace',
                    'source_ref_id' => (string) $trace->id,
                    'trace_id' => (string) $trace->id,
                    'session_id' => $trace->session_id ? (string) $trace->session_id : null,
                    'evidence_refs' => [['type' => 'ai_trace', 'id' => (string) $trace->id]],
                    'create_candidate' => true,
                ]));
            }
        } catch (Throwable) {
            // best-effort learning — a failed comprehension pass never disrupts the user
        }
    }
}
