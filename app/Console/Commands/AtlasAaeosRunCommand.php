<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Control\AaeosCycleOutcomeRecorder;
use App\Services\Ai\Aaeos\Control\AaeosCycleRuntime;
use App\Services\Ai\Aaeos\Control\AaeosExecutorMode;
use Illuminate\Console\Command;

/**
 * Daily AAEOS operate port — intent → mode → admission → live/plan dispatch.
 */
class AtlasAaeosRunCommand extends Command
{
    protected $signature = 'atlas:aaeos:run
        {intent? : Free-text engineering objective}
        {--autonomos : Force Autônomos (zero human eng loop)}
        {--mode= : Force mode: dev|forge|autonomos}
        {--live : World-changing dispatch within caps (brain/seed/session pack)}
        {--dry-run : Plan-only (no live dispatch, no ledger write attempt)}
        {--max-seeds=0 : Autônomos: max brain seed batch (0 = skip seed)}
        {--execute-provider : Allow expensive provider paths (still not auto-burn)}
        {--run-worker-once : Autônomos: also call atlas:task next once}
        {--scope= : Optional brain scope}
        {--json : Machine-readable receipt}';

    protected $description = 'AAEOS daily port: program via Dev · Forge · Autônomos (same bar). Prefer this over raw cycle.';

    public function handle(AaeosCycleRuntime $runtime, AaeosCycleOutcomeRecorder $outcomes): int
    {
        $intent = (string) ($this->argument('intent') ?: 'aaeos_daily_cycle');
        $dryRun = (bool) $this->option('dry-run');
        $live = (bool) $this->option('live');
        $autonomos = (bool) $this->option('autonomos');
        $modeOpt = strtolower(trim((string) $this->option('mode')));

        // Default daily: live for session packs / dualcore record; expensive muscle still capped.
        // dry-run wins over live.
        if ($dryRun) {
            $live = false;
        } elseif (! $this->option('live') && ! $dryRun) {
            // plan-only metadata + dualcore attempt unless --live; operator opts into world change
            $live = false;
        }

        $hints = [
            'source' => 'atlas_aaeos_run',
            'interactive' => ! $autonomos && $modeOpt !== AaeosExecutorMode::AUTONOMOS,
            'live_dispatch' => $live,
            'max_seeds' => (int) $this->option('max-seeds'),
            'execute_provider' => (bool) $this->option('execute-provider'),
            'run_worker_once' => (bool) $this->option('run-worker-once'),
            'scope' => $this->option('scope') ?: null,
        ];

        $world = [];
        if ($autonomos || $modeOpt === AaeosExecutorMode::AUTONOMOS) {
            $world['force_mode'] = AaeosExecutorMode::AUTONOMOS;
            $hints['self_evolve'] = true;
            $hints['interactive'] = false;
        } elseif (in_array($modeOpt, [AaeosExecutorMode::DEV, AaeosExecutorMode::FORGE, AaeosExecutorMode::AUTONOMOS], true)) {
            $world['force_mode'] = $modeOpt;
        }

        $receipt = $runtime->runCycle($intent, $hints, $world, $dryRun);
        $receipt['learning'] = $outcomes->record($receipt);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return $this->exitCode($receipt);
        }

        $this->components->info('AAEOS run');
        $this->components->twoColumnDetail('status', (string) ($receipt['status'] ?? ''));
        $this->components->twoColumnDetail('mode', (string) data_get($receipt, 'mode.mode', ''));
        $this->components->twoColumnDetail('difficulty', (string) data_get($receipt, 'difficulty.label', data_get($receipt, 'difficulty.level', '')));
        $this->components->twoColumnDetail('admission', (string) data_get($receipt, 'admission.verdict', ''));
        $this->components->twoColumnDetail('live_dispatch', ! empty($receipt['live_dispatch']) ? 'true' : 'false');
        $this->components->twoColumnDetail('world_source', (string) data_get($receipt, 'world.world_source', ''));
        $this->components->twoColumnDetail('queue_depth', (string) data_get($receipt, 'world.queue_depth', '0'));
        $this->components->twoColumnDetail('evidence', (string) ($receipt['evidence_status'] ?? ''));

        $effects = data_get($receipt, 'dispatch.effects', data_get($receipt, 'dispatch.live.effects', []));
        if (is_array($effects) && $effects !== []) {
            $this->line('effects: '.implode(', ', array_map(static function ($e) {
                return is_array($e) ? (string) ($e['kind'] ?? 'effect') : (string) $e;
            }, $effects)));
        }

        $next = (array) ($receipt['next_commands'] ?? []);
        if ($next !== []) {
            $this->newLine();
            $this->components->info('next');
            foreach ($next as $cmd) {
                $this->line('  · '.(string) $cmd);
            }
        }

        if ((string) ($receipt['status'] ?? '') === 'halted') {
            $this->components->warn('halted: '.implode(', ', (array) data_get($receipt, 'admission.reasons', [])));
        }

        return $this->exitCode($receipt);
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function exitCode(array $receipt): int
    {
        $status = (string) ($receipt['status'] ?? '');

        return in_array($status, ['halted', 'dispatch_failed'], true)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
