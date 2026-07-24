<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHintToPathTranslator;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathYieldEwma;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPatternLearningLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainReflectionStream;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * ASI-08 — Read-only PathYieldEwma exposure over the pattern-learning ledger.
 */
final class AtlasBrainPathYieldCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:brain:path-yield
        {--alpha=0.3 : EWMA smoothing factor (0..1)}
        {--json : Emit canonical JSON payload}';

    protected $description = 'Read-only PathYieldEwma report over the pattern-learning ledger + reflection stream.';

    public function handle(
        AtlasBrainPatternLearningLedger $ledger,
        AtlasBrainReflectionStream $reflection,
        AtlasBrainPathYieldEwma $ewma,
        AtlasBrainHintToPathTranslator $translator,
    ): int {
        $alpha = (float) $this->option('alpha');
        $ledgerRows = $ledger->entries();
        $reflectionRows = $reflection->entries();

        $tail = [];
        foreach ($ledgerRows as $row) {
            $tail[] = [
                'action_hint' => (string) ($row['action_hint'] ?? ''),
                'result_kind' => (string) ($row['result_kind'] ?? ''),
                'proven_real' => ($row['proven_real'] ?? null) === true,
            ];
        }
        foreach ($reflectionRows as $row) {
            $tail[] = [
                'action_hint' => (string) (data_get($row, 'signals.0', '')),
                'result_kind' => $this->mapReflectionKind((string) ($row['result_kind'] ?? '')),
                'proven_real' => ($row['proven_real'] ?? null) === true,
            ];
        }

        $sampleCount = count(array_filter(
            $tail,
            static fn (array $row): bool => ($row['action_hint'] ?? '') !== '' && ($row['result_kind'] ?? '') !== '',
        ));
        $status = $sampleCount === 0 ? 'insufficient_signal' : 'ready';

        $report = $ewma->compute($tail, $translator, $alpha);
        $report['status'] = $status;
        $report['source_counts'] = [
            'pattern_learning_ledger' => count($ledgerRows),
            'reflection_stream' => count($reflectionRows),
            'joined_samples' => $sampleCount,
        ];
        $report['ledger_path'] = $ledger->path();

        if ((bool) $this->option('json')) {
            $this->line($this->encode($report));
        } else {
            $this->line(sprintf('schema=%s status=%s alpha=%.2f', $report['schema'], $report['status'], (float) $report['alpha']));
            $rows = [];
            foreach ((array) $report['by_path'] as $path => $stats) {
                $rows[] = [(string) $path, (string) ($stats['ewma'] ?? 0), (string) ($stats['samples'] ?? 0)];
            }
            $this->table(['path', 'ewma', 'samples'], $rows);
        }

        return self::SUCCESS;
    }

    private function mapReflectionKind(string $kind): string
    {
        return match ($kind) {
            AtlasBrainReflectionStream::KIND_SUCCESS => AtlasBrainPatternLearningLedger::RESULT_ACCEPTED,
            AtlasBrainReflectionStream::KIND_BLOCKED => AtlasBrainPatternLearningLedger::RESULT_BLOCKED,
            AtlasBrainReflectionStream::KIND_CLEAN_NO_OP => AtlasBrainPatternLearningLedger::RESULT_NO_OP,
            default => AtlasBrainPatternLearningLedger::RESULT_REJECTED,
        };
    }
}
