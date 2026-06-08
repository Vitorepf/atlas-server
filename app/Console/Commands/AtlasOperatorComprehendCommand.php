<?php

namespace App\Console\Commands;

use App\Models\AiTrace;
use App\Services\Ai\OperatorIntelligence\OperatorComprehensionExtractor;
use App\Services\Ai\OperatorIntelligence\OperatorSignalCaptureService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * The BATCH surface for the comprehension extractor — the smart place to run the LLM
 * (never on the hot path). Re-reads recent operator turns (AiTrace.operator_input),
 * runs the anti-hallucination extractor, and feeds every grounded signal into the
 * governed candidate pipeline (shadow / needs-review). Phase 1 = LEARN only: nothing
 * auto-applies. `--dry-run` previews without persisting.
 */
class AtlasOperatorComprehendCommand extends Command
{
    protected $signature = 'atlas:ai:operator-comprehend
        {--since=24h : Lookback window (e.g. 24h, 7d)}
        {--operator= : Operator id (default from config)}
        {--limit= : Max traces to scan}
        {--dry-run : Extract + report, persist nothing}
        {--json : Machine-readable output}';

    protected $description = 'Learn the operator profile by comprehension: run the LLM extractor over recent turns into the governed review queue (capture-only).';

    public function handle(OperatorComprehensionExtractor $extractor, OperatorSignalCaptureService $capture): int
    {
        if ((string) config('atlas_operator_intelligence.comprehension_extraction_mode', 'observe') === 'off') {
            $this->warn('Comprehension extraction is OFF (atlas_operator_intelligence.comprehension_extraction_mode).');

            return self::SUCCESS;
        }
        foreach (['ai_traces', 'operator_learning_signals'] as $t) {
            if (! Schema::hasTable($t)) {
                $this->warn('Table '.$t.' unavailable — nothing to do.');

                return self::SUCCESS;
            }
        }

        $since = now()->sub($this->window((string) $this->option('since')));
        $operatorId = (string) ($this->option('operator') ?: config('atlas_operator_intelligence.default_operator_id', 'default'));
        $limit = (int) ($this->option('limit') ?: config('atlas_operator_intelligence.comprehension_batch_limit', 200));
        $dryRun = (bool) $this->option('dry-run');
        $sourceTypes = (array) config('atlas_operator_intelligence.chat_capture_source_types', ['manual', 'app', 'voice_realtime']);

        $traces = AiTrace::query()
            ->whereNotNull('operator_input')
            ->where('created_at', '>=', $since)
            ->whereIn('source_type', $sourceTypes)
            ->latest()
            ->limit(max(1, min(2000, $limit)))
            ->get();

        $processed = 0;
        $captured = 0;
        $byItem = [];
        foreach ($traces as $trace) {
            $text = trim((string) $trace->operator_input);
            if ($text === '') {
                continue;
            }
            $processed++;
            foreach ($extractor->extract($text, ['operator_id' => $operatorId]) as $signal) {
                if (! $dryRun) {
                    $res = $capture->capture(array_merge($signal, [
                        'operator_id' => $operatorId,
                        'source_type' => 'chat_comprehension',
                        'source_ref_type' => 'ai_trace',
                        'source_ref_id' => (string) $trace->id,
                        'trace_id' => (string) $trace->id,
                        'session_id' => $trace->session_id ? (string) $trace->session_id : null,
                        'evidence_refs' => [['type' => 'ai_trace', 'id' => (string) $trace->id]],
                        'create_candidate' => true,
                    ]));
                    if (data_get($res, 'signal.id') === null) {
                        continue;
                    }
                }
                $captured++;
                $byItem[$signal['taxonomy_item_id']] = ($byItem[$signal['taxonomy_item_id']] ?? 0) + 1;
            }
        }
        arsort($byItem);

        $report = [
            'schema_version' => OperatorComprehensionExtractor::SCHEMA_VERSION,
            'operator_id' => $operatorId,
            'dry_run' => $dryRun,
            'traces_scanned' => $traces->count(),
            'traces_with_text' => $processed,
            'signals_captured' => $captured,
            'distinct_items' => count($byItem),
            'by_item' => $byItem,
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info(sprintf('Operator comprehension%s — scanned %d traces, captured %d signals across %d taxonomy items.',
            $dryRun ? ' (dry-run)' : '', $processed, $captured, count($byItem)));
        $rows = [];
        foreach (array_slice($byItem, 0, 20, true) as $id => $n) {
            $rows[] = [$id, (string) $n];
        }
        if ($rows !== []) {
            $this->table(['taxonomy item', 'signals'], $rows);
        }
        $this->line('Captured signals land in the review queue (shadow). Nothing auto-applied — Phase 1 = learn only.');

        return self::SUCCESS;
    }

    private function window(string $since): \DateInterval
    {
        if (preg_match('/^(\d+)\s*([hd])$/i', trim($since), $m) === 1) {
            $n = max(1, (int) $m[1]);

            return strtolower($m[2]) === 'd' ? new \DateInterval('P'.$n.'D') : new \DateInterval('PT'.$n.'H');
        }

        return new \DateInterval('PT24H');
    }
}
