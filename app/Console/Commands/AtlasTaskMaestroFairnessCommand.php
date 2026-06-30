<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Maestro\Concurrency\AtlasMaestroWorkerFleetProbe;
use App\Services\Ai\SelfConstruction\Maestro\Fairness\AtlasMaestroFairnessAlertEmitter;
use App\Services\Ai\SelfConstruction\Maestro\Fairness\AtlasMaestroFairnessGiniReporter;
use Illuminate\Console\Command;

/**
 * Operator surface for the Maestro fairness primitives.
 *
 *   atlas:task:maestro:fairness gini     — print AtlasMaestroFairnessGiniReporter snapshot
 *   atlas:task:maestro:fairness alerts   — run AtlasMaestroFairnessAlertEmitter once; emit alert if breach
 *   atlas:task:maestro:fairness history  — tail the persisted alerts.jsonl
 *
 * Read-only from the operator's perspective except `alerts`, which writes one row to the
 * rolling window + (on breach) the alerts ledger. NEVER mutates the queue.
 */
final class AtlasTaskMaestroFairnessCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_USAGE = 2;

    protected $signature = 'atlas:task:maestro:fairness {action : gini|alerts|history}
        {--limit=20 : tail size (history)}
        {--json : emit machine-readable JSON}';

    protected $description = 'Maestro fairness CLI: gini | alerts | history.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'gini' => $this->gini(),
            'alerts' => $this->alerts(),
            'history' => $this->history(),
            default => $this->failWith('unknown_action:'.$action.' (expected one of gini|alerts|history)'),
        };
    }

    private function gini(): int
    {
        $snapshot = $this->resolveReporter()->report();
        $this->emit($snapshot);

        return self::EXIT_OK;
    }

    private function alerts(): int
    {
        $emitter = $this->resolveEmitter();
        if ($emitter === null) {
            return $this->failWith('alert_emitter_not_available');
        }
        $alerts = $emitter->emit();
        if ($alerts === []) {
            if ($this->option('json')) {
                $this->line('{"alerts":[]}');
            } else {
                $this->line('no alert');
            }
        } else {
            if ($this->option('json')) {
                $this->line((string) json_encode(['alerts' => $alerts], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            } else {
                foreach ($alerts as $a) {
                    $this->line(sprintf(
                        '%s axis=%s gini=%.3f threshold=%.3f cycles_over=%d max_share=%s observed_at=%s',
                        (string) ($a['kind'] ?? 'maestro_fairness_alert'),
                        (string) ($a['axis'] ?? '?'),
                        (float) ($a['gini_observed'] ?? 0),
                        (float) ($a['threshold'] ?? 0),
                        (int) ($a['cycles_over'] ?? 0),
                        (string) ($a['max_share_id'] ?? '?'),
                        (string) ($a['observed_at'] ?? ''),
                    ));
                }
            }
        }

        return self::EXIT_OK;
    }

    private function history(): int
    {
        $emitter = $this->resolveEmitter();
        if ($emitter === null) {
            return $this->failWith('alert_emitter_not_available');
        }
        $alerts = $emitter->readAlerts();
        $limit = (int) ($this->option('limit') ?? 20);

        if ($this->option('json')) {
            $this->line((string) json_encode($alerts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            foreach (array_slice($alerts, -max(1, $limit)) as $a) {
                $this->line(sprintf(
                    '%s axis=%s gini=%.3f',
                    (string) ($a['observed_at'] ?? '?'),
                    (string) ($a['axis'] ?? '?'),
                    (float) ($a['gini_observed'] ?? 0),
                ));
            }
        }

        return self::EXIT_OK;
    }

    private function resolveReporter(): AtlasMaestroFairnessGiniReporter
    {
        if (app()->bound(AtlasMaestroFairnessGiniReporter::class)) {
            return app(AtlasMaestroFairnessGiniReporter::class);
        }

        // ponytail: empty sources → valid JSON snapshot with 0-gini; live wiring added if throughput data becomes available
        return new AtlasMaestroFairnessGiniReporter(
            new AtlasMaestroWorkerFleetProbe(static fn (): array => []),
            static fn (): array => [],
        );
    }

    private function resolveEmitter(): ?AtlasMaestroFairnessAlertEmitter
    {
        if (app()->bound(AtlasMaestroFairnessAlertEmitter::class)) {
            return app(AtlasMaestroFairnessAlertEmitter::class);
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            foreach ($payload as $k => $v) {
                $this->line($k.': '.(is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES)));
            }
        }
    }

    private function failWith(string $reason): int
    {
        $this->line($reason);

        return self::EXIT_USAGE;
    }
}
