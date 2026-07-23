<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Control\AaeosCycleOutcomeRecorder;
use App\Services\Ai\Aaeos\Control\AaeosCycleRuntime;
use Illuminate\Console\Command;

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
        $receipt = $autonomos
            ? $runtime->runAutonomosCycle($intent, $hints, $dryRun)
            : $runtime->runCycle($intent, $hints, [], $dryRun);

        $receipt['learning'] = $outcomes->record($receipt);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return ($receipt['status'] ?? '') === 'halted' ? self::FAILURE : self::SUCCESS;
        }

        $this->components->twoColumnDetail('status', (string) ($receipt['status'] ?? ''));
        $this->components->twoColumnDetail('mode', (string) data_get($receipt, 'mode.mode', ''));
        $this->components->twoColumnDetail('admission', (string) data_get($receipt, 'admission.verdict', ''));
        $this->components->twoColumnDetail('difficulty', (string) data_get($receipt, 'difficulty.label', ''));
        $this->components->twoColumnDetail('evidence', (string) ($receipt['evidence_status'] ?? ''));
        $this->components->twoColumnDetail('elite_same_bar', data_get($receipt, 'elite_same_bar') ? 'true' : 'false');

        $path = data_get($receipt, 'dispatch.operate_path', []);
        if (is_array($path) && $path !== []) {
            $this->line('operate_path: '.implode(' → ', $path));
        }

        return ($receipt['status'] ?? '') === 'halted' ? self::FAILURE : self::SUCCESS;
    }
}
