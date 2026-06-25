<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Fairness;

/**
 * FACT-only emitter: rolls a window of the last N gini snapshots from
 * {@see AtlasMaestroFairnessGiniReporter} and appends one alert per breach onset to
 * `alerts.jsonl`.
 *
 * A breach is "N consecutive snapshots whose gini for an axis (workers OR task_classes) exceeds
 * the configured threshold". On breach-ONSET (current window all-over, previous window not all-over),
 * one alert per breaching axis is appended.
 *
 * STRICTLY FACT-only: emits records, persists rolling window, NEVER mutates the queue, NEVER kills
 * workers, NEVER rebalances.
 */
final class AtlasMaestroFairnessAlertEmitter
{
    public const ALERT_KIND = 'maestro_fairness_alert';

    public const SCHEMA = 'atlas.maestro.fairness_alert.v1';

    /** @var object */
    private object $reporter;

    public function __construct(
        object $reporter,
        private readonly string $windowPath,
        private readonly string $alertsPath,
        private readonly int $windowSize = 5,
        private readonly float $threshold = 0.6,
    ) {
        $this->reporter = $reporter;
        foreach ([dirname($this->windowPath), dirname($this->alertsPath)] as $dir) {
            if (! is_dir($dir)) {
                @mkdir($dir, 0o755, true);
            }
        }
    }

    /**
     * Run one observation cycle. Returns the alerts emitted on this call (0..2).
     *
     * @return list<array<string,mixed>>
     */
    public function emit(?string $observedAt = null): array
    {
        $observedAt = $observedAt ?? gmdate('Y-m-d\TH:i:s\Z');
        if (! method_exists($this->reporter, 'report')) {
            return [];
        }
        $report = $this->reporter->report();

        $window = $this->loadWindow();
        $previousWindow = $window;
        $window[] = [
            'observed_at' => $observedAt,
            'gini_workers' => (float) ($report['gini_workers'] ?? 0),
            'gini_task_classes' => (float) ($report['gini_task_classes'] ?? 0),
            'max_worker_share_id' => (string) ($report['max_worker_share_id'] ?? ''),
            'max_task_class_share_id' => (string) ($report['max_task_class_share_id'] ?? ''),
        ];
        if (count($window) > $this->windowSize) {
            $window = array_slice($window, -$this->windowSize);
        }

        $alerts = [];
        if (count($window) >= $this->windowSize) {
            foreach (['workers', 'task_classes'] as $axis) {
                $key = 'gini_'.$axis;
                $currentOver = $this->allOver($window, $key, $this->threshold);
                $previousOver = count($previousWindow) >= $this->windowSize && $this->allOver(array_slice($previousWindow, -$this->windowSize), $key, $this->threshold);
                if ($currentOver && ! $previousOver) {
                    $alert = [
                        'schema' => self::SCHEMA,
                        'kind' => self::ALERT_KIND,
                        'axis' => $axis,
                        'gini_observed' => (float) $window[count($window) - 1][$key],
                        'threshold' => $this->threshold,
                        'cycles_over' => count($window),
                        'max_share_id' => (string) $window[count($window) - 1][$axis === 'workers' ? 'max_worker_share_id' : 'max_task_class_share_id'],
                        'observed_at' => $observedAt,
                    ];
                    $this->appendAlert($alert);
                    $alerts[] = $alert;
                }
            }
        }

        $this->saveWindow($window);

        return $alerts;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function readAlerts(): array
    {
        if (! is_file($this->alertsPath)) {
            return [];
        }
        $rows = [];
        foreach ((array) file($this->alertsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function loadWindow(): array
    {
        if (! is_file($this->windowPath)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($this->windowPath), true);
        if (! is_array($decoded) || ! isset($decoded['window']) || ! is_array($decoded['window'])) {
            return [];
        }

        return array_values($decoded['window']);
    }

    /**
     * @param  list<array<string,mixed>>  $window
     * @param  list<array<string,mixed>>  $window
     */
    private function allOver(array $window, string $key, float $threshold): bool
    {
        foreach ($window as $row) {
            if ((float) ($row[$key] ?? 0) <= $threshold) {
                return false;
            }
        }

        return $window !== [];
    }

    /**
     * @param  array<string,mixed>  $alert
     */
    private function appendAlert(array $alert): void
    {
        $line = json_encode($alert, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $handle = @fopen($this->alertsPath, 'a');
        if ($handle === false) {
            return;
        }
        try {
            fwrite($handle, $line."\n");
            fflush($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  list<array<string,mixed>>  $window
     */
    private function saveWindow(array $window): void
    {
        $bytes = json_encode(['window' => $window], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        @file_put_contents($this->windowPath, (string) $bytes);
    }
}
