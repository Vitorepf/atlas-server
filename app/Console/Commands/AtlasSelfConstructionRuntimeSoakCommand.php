<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\RuntimeDaemon\AtlasSelfConstructionRuntimeRegressionAuditor;
use App\Services\Ai\SelfConstruction\RuntimeDaemon\AtlasSelfConstructionRuntimeSoakRunner;
use App\Services\Ai\SelfConstruction\RuntimeDaemon\AtlasSelfConstructionRuntimeSoakScenarioBuilder;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Virtual daemon endurance proof CLI. Bounded, dry-run by default, never sleeps for real time,
 * never calls providers/source-control/external worker sessions.
 *
 * Subcommands:
 *   scenario — print the virtual scenario the soak would execute (read-only).
 *   dry-run  — run the soak with apply=false, no tick callbacks.
 *   run      — run the soak; honours --apply (defaults to dry-run otherwise).
 *   audit    — feed a soak report into the regression auditor; returns pass|hold|blocked.
 */
final class AtlasSelfConstructionRuntimeSoakCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:self-construction:runtime-soak
        {action : scenario|dry-run|run|audit}
        {--facts=}
        {--ticks=16}
        {--apply}
        {--json}';

    /** @var string */
    protected $description = 'Virtual runtime soak CLI: scenario | dry-run | run | audit.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $facts = $this->readJson((string) ($this->option('facts') ?? ''));
        $ticks = max(1, min(64, (int) $this->option('ticks')));
        $apply = (bool) $this->option('apply');

        $payload = match ($action) {
            'scenario' => $this->scenario($ticks, $facts),
            'dry-run' => $this->dryRun($ticks, $facts),
            'run' => $this->runAction($ticks, $facts, $apply),
            'audit' => $this->audit($facts),
            default => ['status' => 'unknown_action', 'action' => $action],
        };
        $this->line($this->encode($payload));

        return ($payload['status'] ?? 'ok') === 'ok' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>|null  $facts
     * @return array<string,mixed>
     */
    private function scenario(int $ticks, ?array $facts): array
    {
        $builder = $this->app()->make(AtlasSelfConstructionRuntimeSoakScenarioBuilder::class);
        $options = is_array($facts['scenario_options'] ?? null) ? $facts['scenario_options'] : [];
        $options['max_ticks'] = $ticks;
        $scenario = $builder->build($options);

        return ['status' => 'ok', 'action' => 'scenario', 'scenario' => $scenario];
    }

    /**
     * @param  array<string,mixed>|null  $facts
     * @return array<string,mixed>
     */
    private function dryRun(int $ticks, ?array $facts): array
    {
        $scenario = $this->scenario($ticks, $facts)['scenario'];
        $report = $this->app()->make(AtlasSelfConstructionRuntimeSoakRunner::class)->run($scenario, ['apply' => false]);

        return ['status' => 'ok', 'action' => 'dry-run', 'report' => $report];
    }

    /**
     * @param  array<string,mixed>|null  $facts
     * @return array<string,mixed>
     */
    private function runAction(int $ticks, ?array $facts, bool $apply): array
    {
        $scenario = $this->scenario($ticks, $facts)['scenario'];
        // Default to dry-run unless --apply was passed; even with --apply we never wire any callback
        // that calls providers/source-control/external workers — facts may supply a deterministic callback.
        $callback = null;
        if ($apply && is_array($facts['tick_callback_responses'] ?? null)) {
            $responses = $facts['tick_callback_responses'];
            $i = 0;
            $callback = static function (array $tick) use (&$i, $responses): array {
                $resp = $responses[$i] ?? ['status' => 'no_response'];
                $i++;

                return is_array($resp) ? $resp : ['status' => 'invalid'];
            };
        }
        $options = ['apply' => $apply, 'tick_callback' => $callback];
        $report = $this->app()->make(AtlasSelfConstructionRuntimeSoakRunner::class)->run($scenario, $options);

        return ['status' => 'ok', 'action' => 'run', 'apply' => $apply, 'report' => $report];
    }

    /**
     * @param  array<string,mixed>|null  $facts
     * @return array<string,mixed>
     */
    private function audit(?array $facts): array
    {
        if (! is_array($facts) || ! isset($facts['soak_report'])) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON with soak_report required'];
        }
        $auditor = $this->app()->make(AtlasSelfConstructionRuntimeRegressionAuditor::class);
        $verdict = $auditor->audit((array) $facts['soak_report'], (array) ($facts['audit_facts'] ?? []));

        return ['status' => 'ok', 'action' => 'audit', 'verdict' => $verdict];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if ($path === '' || ! is_file($path)) {
            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @return \Illuminate\Contracts\Container\Container */
    private function app()
    {
        return $this->getLaravel();
    }
}
