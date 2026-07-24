<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Control\AaeosAdmissionVerdict;
use App\Services\Ai\Aaeos\Control\AaeosCycleOutcomeRecorder;
use App\Services\Ai\Aaeos\Control\AaeosCycleRuntime;
use App\Services\Ai\Aaeos\Control\AaeosExecutorMode;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Daily AAEOS operate port — intent-first (P2f).
 *
 * Productive technical flags were stripped: --live, --execute-provider,
 * --run-worker-once, --max-seeds, --scope. Passing them fails closed with
 * migration guidance. Keep --dry-run for plan-only.
 */
class AtlasAaeosRunCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:aaeos:run
        {intent? : Free-text engineering objective}
        {--autonomos : Force Autônomos (zero human eng loop)}
        {--mode= : Force mode: dev|forge|autonomos}
        {--dry-run : Plan-only (no live dispatch, no ledger write attempt)}
        {--json : Machine-readable receipt}
        {--live : REMOVED (P2f) — see migration guidance}
        {--max-seeds= : REMOVED (P2f) — see migration guidance}
        {--execute-provider : REMOVED (P2f) — see migration guidance}
        {--run-worker-once : REMOVED (P2f) — see migration guidance}
        {--scope= : REMOVED (P2f) — see migration guidance}';

    protected $description = 'AAEOS daily port (intent-first): Dev · Forge · Autônomos same bar. No productive technical dials.';

    /** @var array<string,string> */
    private const STRIPPED_FLAG_GUIDANCE = [
        'live' => 'Use self-construction daemon / atlas:task surfaces for live muscle; daily port is intent-first plan/admission (not a technical live dial).',
        'execute-provider' => 'Provider spend is not a daily-port dial. Configure runtime spend policy / daemon paths — never atlas:aaeos:run --execute-provider.',
        'run-worker-once' => 'Worker claim is atlas:task next / runtime daemon — not a OneShot flag on atlas:aaeos:run.',
        'max-seeds' => 'Brain seed batching is atlas:brain:seed / replenisher — not --max-seeds on the daily port.',
        'scope' => 'Scope is owned by task packets / authority envelope — not --scope on atlas:aaeos:run.',
    ];

    public function handle(AaeosCycleRuntime $runtime, AaeosCycleOutcomeRecorder $outcomes): int
    {
        if (($removed = $this->firstStrippedFlagPresent()) !== null) {
            return $this->failRemovedFlag($removed);
        }

        $intent = (string) ($this->argument('intent') ?: 'aaeos_daily_cycle');
        $dryRun = (bool) $this->option('dry-run');
        $autonomos = (bool) $this->option('autonomos');
        $modeOpt = strtolower(trim((string) $this->option('mode')));

        // P2f: daily port never accepts productive technical dispatch dials.
        $hints = [
            'source' => 'atlas_aaeos_run',
            'interactive' => ! $autonomos && $modeOpt !== AaeosExecutorMode::AUTONOMOS,
            'live_dispatch' => false,
            'max_seeds' => 0,
            'execute_provider' => false,
            'run_worker_once' => false,
            'scope' => null,
            'intent_first_daily_port' => true,
            'technical_dials_stripped' => true,
        ];

        $world = [];
        if ($autonomos || $modeOpt === AaeosExecutorMode::AUTONOMOS) {
            $world['force_mode'] = AaeosExecutorMode::AUTONOMOS;
            $hints['self_evolve'] = true;
            $hints['interactive'] = false;
        } elseif ($modeOpt !== '') {
            $world['force_mode'] = $modeOpt;
        }

        $receipt = $runtime->runCycle($intent, $hints, $world, $dryRun);
        if (! $dryRun) {
            $receipt['learning'] = $outcomes->record($receipt);
        } else {
            $receipt['learning'] = ['status' => 'skipped_dry_run'];
        }
        $receipt['p2f_technical_dials_stripped'] = true;

        if ((bool) $this->option('json')) {
            $this->jsonLine($receipt);

            return $this->exitCode($receipt);
        }

        $this->components->info('AAEOS run (intent-first)');
        $this->components->twoColumnDetail('status', (string) ($receipt['status'] ?? ''));
        $this->components->twoColumnDetail('mode', (string) data_get($receipt, 'mode.mode', ''));
        $this->components->twoColumnDetail('difficulty', (string) data_get($receipt, 'difficulty.label', data_get($receipt, 'difficulty.level', '')));
        $this->components->twoColumnDetail('admission', (string) data_get($receipt, 'admission.verdict', ''));
        $this->components->twoColumnDetail('live_dispatch', 'false');
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

    private function firstStrippedFlagPresent(): ?string
    {
        foreach (array_keys(self::STRIPPED_FLAG_GUIDANCE) as $flag) {
            if ($this->input->hasParameterOption('--'.$flag, true)) {
                return $flag;
            }
        }

        return null;
    }

    private function failRemovedFlag(string $flag): int
    {
        $guidance = self::STRIPPED_FLAG_GUIDANCE[$flag] ?? 'Flag removed in P2f intent-first daily port.';
        $payload = [
            'schema' => 'atlas.aaeos.run.removed_flag.v1',
            'status' => 'blocked',
            'reason' => 'p2f_technical_flag_removed',
            'flag' => $flag,
            'migration_guidance' => $guidance,
            'allowed_flags' => ['dry-run', 'json', 'mode', 'autonomos'],
            'removed_flags' => array_keys(self::STRIPPED_FLAG_GUIDANCE),
        ];

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);
        } else {
            $this->components->error("atlas:aaeos:run --{$flag} removed (P2f intent-first).");
            $this->line($guidance);
            $this->line('Allowed: --dry-run --json --mode --autonomos');
        }

        return self::FAILURE;
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
