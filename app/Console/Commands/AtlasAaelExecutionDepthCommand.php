<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Aael\Execution\AtlasAaelExecutionEmergencyAbortGate;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\AtlasAaelExecutionPlanInvariantChecker;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\AtlasAaelExecutionPlanSemanticProver;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\AtlasAaelExecutionSafeStateRecoverer;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * AAEL Execution Depth CLI — one subaction per packet 01-04 class.
 *
 * Each subaction prints FACTS only — no derived score, no pass/fail summary line. Exit code is
 * always 0 on successful FACT emission (including aborted=true); only operational errors (bad path,
 * malformed JSON) return 1.
 */
final class AtlasAaelExecutionDepthCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:aael:depth
        {action : prove|invariants|abort|recover}
        {--plan= : path to plan json}
        {--objective= : objective string}
        {--invariants=* : declared invariants}
        {--reason= : abort reason}
        {--checkpoint= : safe-state id}
        {--json : Emit machine-readable JSON}';

    /** @var string */
    protected $description = 'AAEL execution-depth CLI: prove | invariants | abort | recover (FACT-only).';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $json = (bool) $this->option('json');

        try {
            return match ($action) {
                'prove' => $this->doProve($json),
                'invariants' => $this->doInvariants($json),
                'abort' => $this->doAbort($json),
                'recover' => $this->doRecover($json),
                default => $this->failJson('unknown_action:'.$action, $json),
            };
        } catch (Throwable $e) {
            return $this->failJson($e->getMessage(), $json);
        }
    }

    private function doProve(bool $json): int
    {
        $planPath = (string) $this->option('plan');
        $objective = (string) $this->option('objective');
        $plan = $this->loadPlan($planPath);
        if ($plan === null) {
            return $this->failJson('invalid_plan_path:'.$planPath, $json);
        }
        $prover = new AtlasAaelExecutionPlanSemanticProver;
        $payload = $prover->prove($plan, $objective);
        $this->emit($json, $payload);

        return self::SUCCESS;
    }

    private function doInvariants(bool $json): int
    {
        $planPath = (string) $this->option('plan');
        $plan = $this->loadPlan($planPath);
        if ($plan === null) {
            return $this->failJson('invalid_plan_path:'.$planPath, $json);
        }
        $invariants = array_values((array) $this->option('invariants'));
        $checker = new AtlasAaelExecutionPlanInvariantChecker;
        $payload = $checker->check($plan, $invariants);
        $this->emit($json, $payload);

        return self::SUCCESS;
    }

    private function doAbort(bool $json): int
    {
        $sentinel = $this->sentinelPath();
        $gate = new AtlasAaelExecutionEmergencyAbortGate($sentinel);
        $reason = (string) $this->option('reason');
        $checkpoint = (string) $this->option('checkpoint');

        $payload = $reason === ''
            ? $gate->check()
            : $gate->raise($reason, $checkpoint !== '' ? $checkpoint : null);
        $this->emit($json, $payload);

        return self::SUCCESS;
    }

    private function doRecover(bool $json): int
    {
        $checkpointId = (string) $this->option('checkpoint');
        try {
            $recoverer = new AtlasAaelExecutionSafeStateRecoverer($this->safeStateDir());
            $payload = $recoverer->recover($checkpointId !== '' ? $checkpointId : null);
        } catch (Throwable $e) {
            return $this->failJson($e->getMessage(), $json);
        }
        $this->emit($json, $payload);

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadPlan(string $path): ?array
    {
        if ($path === '' || ! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function sentinelPath(): string
    {
        if (app()->bound('atlas.aael.sentinel_path')) {
            return (string) app('atlas.aael.sentinel_path');
        }

        return storage_path('app/atlas/aael/sentinel.json');
    }

    private function safeStateDir(): string
    {
        if (app()->bound('atlas.aael.safe_state_dir')) {
            return (string) app('atlas.aael.safe_state_dir');
        }

        return storage_path('app/atlas/aael/safe-state');
    }

    private function emit(bool $json, array $payload): void
    {
        if ($json) {
            $this->line($this->encode($payload));

            return;
        }
        $this->line(print_r($payload, true));
    }

    private function failJson(string $reason, bool $json): int
    {
        if ($json) {
            $this->line((string) json_encode(['error' => $reason], JSON_UNESCAPED_SLASHES));
        } else {
            $this->error($reason);
        }

        return self::FAILURE;
    }
}
