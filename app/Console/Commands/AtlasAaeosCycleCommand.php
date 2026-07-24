<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Control\AaeosCycleOutcomeRecorder;
use App\Services\Ai\Aaeos\Control\AaeosCycleRuntime;
use App\Services\Ai\Aaeos\Control\AaeosAdmissionVerdict;
use Illuminate\Console\Command;
use App\Support\YesNo;

/**
 * AAEOS GOD/SOTA control-plane cycle surface.
 */
class AtlasAaeosCycleCommand extends Command
{
    protected $signature = 'atlas:aaeos:cycle
        {intent? : Free-text objective / intent}
        {--autonomos : Force zero-operator Autonomos mode}
        {--live : Live dispatch within caps}
        {--max-seeds=0 : Autonomos seed cap}
        {--dry-run : Do not write evidence ledger / no live}
        {--json : Machine-readable JSON receipt}';

    protected $description = 'Run one AAEOS control cycle: intent → mode → admission → dispatch (Dev|Forge|Autonomos).';

    public function handle(AaeosCycleRuntime $runtime, AaeosCycleOutcomeRecorder $outcomes): int
    {
        $intent = (string) ($this->argument('intent') ?: 'aaeos_default_cycle');
        $dryRun = (bool) $this->option('dry-run');
        $autonomos = (bool) $this->option('autonomos');

        $hints = [
            'source' => 'cli',
            'interactive' => ! $autonomos,
            'live_dispatch' => (bool) $this->option('live') && ! $dryRun,
            'max_seeds' => (int) $this->option('max-seeds'),
        ];
        $autonomosHints = array_merge($hints, [
            'source' => 'autonomos',
            'interactive' => false,
            'self_evolve' => true,
        ]);
        $receipt = $autonomos
            ? $runtime->runAutonomosCycle($intent, $autonomosHints, $dryRun)
            : $runtime->runCycle($intent, $hints, [], $dryRun);

        if (! $dryRun) {
            $receipt['learning'] = $outcomes->record($receipt);
        } else {
            $receipt['learning'] = ['status' => 'skipped_dry_run'];
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return $this->exitCode($receipt);
        }

        $this->components->twoColumnDetail('status', (string) ($receipt['status'] ?? ''));
        $this->components->twoColumnDetail('mode', (string) data_get($receipt, 'mode.mode', ''));
        $this->components->twoColumnDetail('admission', (string) data_get($receipt, 'admission.verdict', ''));
        $this->components->twoColumnDetail('difficulty', (string) data_get($receipt, 'difficulty.label', ''));
        $this->components->twoColumnDetail('evidence', (string) ($receipt['evidence_status'] ?? ''));
        $this->components->twoColumnDetail('elite_same_bar', data_getYesNo::trueFalse($receipt, 'elite_same_bar'));

        $path = data_get($receipt, 'dispatch.operate_path', []);
        if (is_array($path) && $path !== []) {
            $this->line('operate_path: '.implode(' → ', $path));
        }

        return $this->exitCode($receipt);
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function exitCode(array $receipt): int
    {
        $status = (string) ($receipt['status'] ?? '');
        if (in_array($status, ['halted', 'dispatch_failed', 'repair_required', 'blocked'], true)) {
            return self::FAILURE;
        }

        return (string) data_get($receipt, 'admission.verdict', '') === AaeosAdmissionVerdict::REPAIR_REQUIRED
            ? self::FAILURE
            : self::SUCCESS;
    }
}
