<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Aael\Execution\TraceReplay\AtlasAaelExecutionTraceRecorder;
use Illuminate\Console\Command;

final class AtlasAaelTraceCommand extends Command
{
    protected $signature = 'atlas:aael:trace
        {action : record|replay|history}
        {--trace-id=}
        {--from-step=}
        {--to-step=}
        {--limit=20}
        {--json}
        {--actor=null : null|divergent (test-only knob)}';

    protected $description = 'Operator CLI for AAEL execution trace record/replay/history.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'record' => $this->doRecord(),
            'replay' => $this->doReplay(),
            'history' => $this->doHistory(),
            default => $this->errExit('unknown action: '.$action),
        };
    }

    private function doRecord(): int
    {
        $traceId = null;
        $recorder = new AtlasAaelExecutionTraceRecorder(
            traceRoot: $this->traceRoot(),
            traceIdGenerator: fn (): string => $traceId = $this->uuidv7(),
        );
        $recorder->begin(['cli' => 'atlas:aael:trace', 'demo' => true], 'unknown');
        $recorder->recordStep([
            'action_name' => 'demo',
            'input' => ['k' => 1],
            'output' => ['k' => 1],
            'stdout' => '',
            'stderr' => '',
            'provider_id' => 'cli',
            'exit_code' => 0,
            'decision_context_id' => 'demo',
        ]);
        $recorder->finish('closed-ok');
        $id = $recorder->traceId();
        if ($this->option('json')) {
            $this->line((string) json_encode(['trace_id' => $id]));
        } else {
            $this->info('trace_id='.$id);
        }

        return 0;
    }

    private function doReplay(): int
    {
        $traceId = (string) ($this->option('trace-id') ?? '');
        if ($traceId === '') {
            return $this->errExit('trace-id required for replay');
        }
        $path = rtrim($this->traceRoot(), '/').'/'.$traceId.'.jsonl';
        if (! is_file($path)) {
            return $this->errExit('trace not found: '.$traceId);
        }
        $fromStep = $this->option('from-step') !== null ? (int) $this->option('from-step') : null;
        $toStep = $this->option('to-step') !== null ? (int) $this->option('to-step') : null;
        $actor = (string) $this->option('actor');

        $divergences = [];
        $total = 0;
        $firstDiverged = null;
        foreach ((array) file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $row = json_decode((string) $line, true);
            if (! is_array($row)) {
                continue;
            }
            $recordType = (string) ($row['record_type'] ?? '');
            if ($recordType === 'manifest' || $recordType === 'finish') {
                continue;
            }
            $stepIndex = (int) ($row['step_index'] ?? 0);
            if ($fromStep !== null && $stepIndex < $fromStep) {
                continue;
            }
            if ($toStep !== null && $stepIndex > $toStep) {
                continue;
            }
            $recordedFp = (string) ($row['output_fingerprint'] ?? '');
            $observedFp = $actor === 'divergent'
                ? hash('sha256', 'divergent:'.$stepIndex)
                : $recordedFp;
            $diverged = $observedFp !== $recordedFp;
            $divergences[] = [
                'action' => (string) ($row['action_name'] ?? ''),
                'diverged' => $diverged,
                'first_byte_diff_offset' => $diverged ? 0 : null,
                'observed_fp' => $observedFp,
                'recorded_fp' => $recordedFp,
                'step_index' => $stepIndex,
            ];
            $total++;
            if ($diverged && $firstDiverged === null) {
                $firstDiverged = $stepIndex;
            }
        }

        $divergedCount = 0;
        foreach ($divergences as $d) {
            if ($d['diverged']) {
                $divergedCount++;
            }
        }
        $report = [
            'diverged_step_count' => $divergedCount,
            'divergences' => $divergences,
            'first_diverged_step_index' => $firstDiverged,
            'root_commit_at_record' => 'unknown',
            'root_commit_at_replay' => 'unknown',
            'total_steps_replayed' => $total,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report));
        } else {
            $this->info(sprintf('total=%d diverged=%d first=%s', $total, $divergedCount, $firstDiverged === null ? '-' : (string) $firstDiverged));
        }

        return $divergedCount === 0 ? 0 : 1;
    }

    private function doHistory(): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $dir = $this->traceRoot();
        $files = is_dir($dir) ? (array) glob($dir.'/*.jsonl') : [];
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $files = array_slice($files, 0, $limit);

        $rows = [];
        foreach ($files as $f) {
            $rows[] = $this->summarize((string) $f);
        }
        if ($this->option('json')) {
            $this->line((string) json_encode($rows));
        } else {
            foreach ($rows as $r) {
                $this->info(sprintf('%s started=%s finished=%s steps=%d status=%s', $r['trace_id'], $r['started_at'], $r['finished_at'], $r['total_steps'], $r['terminal_status']));
            }
        }

        return 0;
    }

    /** @return array{trace_id:string, started_at:string, finished_at:string, total_steps:int, terminal_status:string} */
    private function summarize(string $path): array
    {
        $traceId = (string) preg_replace('/\.jsonl$/', '', basename($path));
        $started = '';
        $finished = '';
        $total = 0;
        $terminal = '';
        foreach ((array) file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $row = json_decode((string) $line, true);
            if (! is_array($row)) {
                continue;
            }
            $rt = (string) ($row['record_type'] ?? '');
            if ($rt === 'manifest') {
                $started = (string) ($row['started_at'] ?? '');
            } elseif ($rt === 'finish') {
                $finished = (string) ($row['finished_at'] ?? '');
                $total = (int) ($row['total_steps'] ?? 0);
                $terminal = (string) ($row['terminal_status'] ?? '');
            }
        }

        return [
            'finished_at' => $finished,
            'started_at' => $started,
            'terminal_status' => $terminal,
            'total_steps' => $total,
            'trace_id' => $traceId,
        ];
    }

    private function traceRoot(): string
    {
        return (string) storage_path('atlas/aael/traces');
    }

    private function errExit(string $msg): int
    {
        $this->error($msg);

        return 2;
    }

    private function uuidv7(): string
    {
        $ms = (int) floor(microtime(true) * 1000);
        $unixTsHex = str_pad(dechex($ms), 12, '0', STR_PAD_LEFT);
        $r = random_bytes(10);
        $r[0] = chr((ord($r[0]) & 0x0F) | 0x70);
        $r[2] = chr((ord($r[2]) & 0x3F) | 0x80);
        $hex = bin2hex($r);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($unixTsHex, 0, 8),
            substr($unixTsHex, 8, 4),
            substr($hex, 0, 4),
            substr($hex, 4, 4),
            substr($hex, 8, 12),
        );
    }
}
