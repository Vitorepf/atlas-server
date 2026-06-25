<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Aael\Execution\Debugger\AtlasAaelExecutionDebuggerReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\Debugger\AtlasAaelExecutionStepwisePauseGate;
use Illuminate\Console\Command;

/**
 * Operator-only surface for the AAEL stepwise debugger. Exposes pause | inspect | step | continue
 * over the StepwisePauseGate, the InspectorSnapshot pre/post pair, and the hash-chained debugger
 * receipt ledger. FACTS only — no scores, no judgement.
 *
 * Exit codes:
 *   0 — success
 *   2 — run does not exist (no receipts on disk for that run_id)
 *   3 — operation requires an armed pause but none is currently armed
 *   1 — other input errors
 */
final class AtlasAaelExecutionDebuggerCommand extends Command
{
    public const SNAPSHOT_SOURCE_BINDING = 'atlas.aael.debugger.snapshot_source';

    public const PAUSE_STORAGE_ROOT_BINDING = 'atlas.aael.debugger.pause_storage_root';

    public const LEDGER_STORAGE_ROOT_BINDING = 'atlas.aael.debugger.ledger_storage_root';

    public const EVENT_PAUSE_ARMED = 'pause_armed';

    public const EVENT_INSPECTED = 'inspect_pre';

    public const EVENT_STEP_ADVANCED = 'step_advanced';

    public const EVENT_RESUMED = 'resumed';

    protected $signature = 'atlas:aael:debug {action : pause|inspect|step|continue} {--run= : AAEL run id} {--at-step= : optional step index for pause} {--json : machine-readable output}';

    protected $description = 'Operator CLI for the AAEL stepwise debugger (pause | inspect | step | continue).';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $runId = (string) $this->option('run');
        if ($runId === '' && $action !== '') {
            return $this->stderrEmit('run_id_required', 'run_id is required', 1);
        }

        return match ($action) {
            'pause' => $this->doPause($runId),
            'inspect' => $this->doInspect($runId),
            'step' => $this->doStep($runId),
            'continue' => $this->doContinue($runId),
            default => $this->stderrEmit('unknown_action', 'unknown action: '.$action, 1),
        };
    }

    private function doPause(string $runId): int
    {
        $atStep = $this->option('at-step');
        $atStepInt = ($atStep === null || $atStep === '') ? null : (int) $atStep;
        $this->gate()->arm($runId, $atStepInt);
        $entry = $this->ledger()->append(
            $runId,
            $atStepInt ?? 0,
            self::EVENT_PAUSE_ARMED,
            gmdate('Y-m-d\TH:i:s\Z'),
            ['at_step' => $atStepInt],
        );

        return $this->emit([
            'event' => self::EVENT_PAUSE_ARMED,
            'run' => $runId,
            'at_step' => $atStepInt,
            'receipt' => $entry,
        ], 0);
    }

    private function doInspect(string $runId): int
    {
        if (! $this->runExists($runId)) {
            return $this->stderrEmit('run_not_found', 'run not found: '.$runId, 2);
        }
        $snapshot = $this->loadSnapshot($runId);
        $entry = $this->ledger()->append(
            $runId,
            (int) ($snapshot['step_index'] ?? 0),
            self::EVENT_INSPECTED,
            gmdate('Y-m-d\TH:i:s\Z'),
            ['snapshot_digest' => hash('sha256', (string) json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))],
        );

        $payload = [
            'event' => self::EVENT_INSPECTED,
            'run' => $runId,
            'step_index' => (int) ($snapshot['step_index'] ?? 0),
            'step_kind' => (string) ($snapshot['step_kind'] ?? ''),
            'files_touched' => (array) ($snapshot['files_touched'] ?? []),
            'diff_counts' => (array) ($snapshot['diff_counts'] ?? []),
            'receipt' => $entry,
        ];

        return $this->emit($payload, 0);
    }

    private function doStep(string $runId): int
    {
        if (! $this->runExists($runId)) {
            return $this->stderrEmit('run_not_found', 'run not found: '.$runId, 2);
        }
        if (! $this->pauseArmed($runId)) {
            return $this->stderrEmit('no_active_pause', 'no active pause for run: '.$runId, 3);
        }
        $current = $this->currentArmedStep($runId);
        $next = ($current ?? 0) + 1;
        $this->gate()->disarm($runId);
        $this->gate()->arm($runId, $next);
        $entry = $this->ledger()->append(
            $runId,
            $next,
            self::EVENT_STEP_ADVANCED,
            gmdate('Y-m-d\TH:i:s\Z'),
            ['from_step' => $current, 'to_step' => $next],
        );

        return $this->emit([
            'event' => self::EVENT_STEP_ADVANCED,
            'run' => $runId,
            'at_step' => $next,
            'receipt' => $entry,
        ], 0);
    }

    private function doContinue(string $runId): int
    {
        if (! $this->runExists($runId)) {
            return $this->stderrEmit('run_not_found', 'run not found: '.$runId, 2);
        }
        if (! $this->pauseArmed($runId)) {
            return $this->stderrEmit('no_active_pause', 'no active pause for run: '.$runId, 3);
        }
        $this->gate()->disarm($runId);
        $entry = $this->ledger()->append(
            $runId,
            0,
            self::EVENT_RESUMED,
            gmdate('Y-m-d\TH:i:s\Z'),
        );

        return $this->emit([
            'event' => self::EVENT_RESUMED,
            'run' => $runId,
            'receipt' => $entry,
        ], 0);
    }

    private function runExists(string $runId): bool
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $runId) ?? $runId;
        $path = rtrim($this->ledgerStorageRoot(), '/').'/'.$safe.'.receipts.jsonl';

        return is_file($path);
    }

    private function pauseArmed(string $runId): bool
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $runId) ?? $runId;
        $path = rtrim($this->pauseStorageRoot(), '/').'/'.$safe.'.pause.json';

        return is_file($path);
    }

    private function currentArmedStep(string $runId): ?int
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $runId) ?? $runId;
        $path = rtrim($this->pauseStorageRoot(), '/').'/'.$safe.'.pause.json';
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        if (! is_array($decoded) || ! isset($decoded['at_step_index'])) {
            return null;
        }
        $v = $decoded['at_step_index'];

        return $v === null ? null : (int) $v;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadSnapshot(string $runId): array
    {
        if (! $this->getLaravel()->bound(self::SNAPSHOT_SOURCE_BINDING)) {
            return ['step_index' => 0, 'step_kind' => '', 'files_touched' => [], 'diff_counts' => []];
        }
        $source = $this->getLaravel()->make(self::SNAPSHOT_SOURCE_BINDING);
        if (! is_callable($source)) {
            return ['step_index' => 0, 'step_kind' => '', 'files_touched' => [], 'diff_counts' => []];
        }
        $out = $source($runId);

        return is_array($out) ? $out : [];
    }

    private function pauseStorageRoot(): string
    {
        if ($this->getLaravel()->bound(self::PAUSE_STORAGE_ROOT_BINDING)) {
            return (string) $this->getLaravel()->make(self::PAUSE_STORAGE_ROOT_BINDING);
        }

        return storage_path('app/atlas/aael/debugger/pause');
    }

    private function ledgerStorageRoot(): string
    {
        if ($this->getLaravel()->bound(self::LEDGER_STORAGE_ROOT_BINDING)) {
            return (string) $this->getLaravel()->make(self::LEDGER_STORAGE_ROOT_BINDING);
        }

        return storage_path('app/atlas/aael/debugger/receipts');
    }

    private function gate(): AtlasAaelExecutionStepwisePauseGate
    {
        return new AtlasAaelExecutionStepwisePauseGate($this->pauseStorageRoot());
    }

    private function ledger(): AtlasAaelExecutionDebuggerReceiptLedger
    {
        return new AtlasAaelExecutionDebuggerReceiptLedger($this->ledgerStorageRoot());
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $exit): int
    {
        ksort($payload, SORT_STRING);
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (! $this->option('json')) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $this->line((string) json_encode($payload, $flags));

        return $exit;
    }

    private function stderrEmit(string $code, string $message, int $exit): int
    {
        $output = $this->output;
        if ($output instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface) {
            $output->getErrorOutput()->writeln($message);
        } else {
            $this->line($message);
        }
        $this->line((string) json_encode(['error' => $code, 'message' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $exit;
    }
}
