<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution;

use App\Support\AtlasSecurity;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * M keystone · Outcome metric ledger for the Atlas 24h loop.
 *
 * Measures whether a merged cycle's DECLARED outcome actually moved, via a REAL
 * existing command (e.g. `php artisan atlas:aaeos:maturity --json`, a test-count
 * command, or existing telemetry). It NEVER invents a metric and NEVER asks a
 * provider — the only input is a reproducible local command whose numeric output
 * is parsed deterministically.
 *
 * An outcome_contract is:
 *   {
 *     metric_id:       string  (e.g. 'service_maturity', 'test_count'),
 *     baseline:        float,  (the measured value before the merge),
 *     target_delta:    float,  (the minimum signed movement that must be achieved),
 *     measure_command: string, (the real command re-run AFTER the merge),
 *     metric_json_path?: string (dot-path into JSON output; default 'metric'),
 *   }
 *
 * Verdict (deterministic, no opinion): measured_delta = measured - baseline.
 * outcome_met = measured_delta >= target_delta (signed; a negative target_delta
 * is a "must decrease" goal). A measurement that fails to run is NEVER a pass —
 * it returns STATUS_MEASUREMENT_FAILED so the caller reverts or blocks honestly.
 *
 * Persistence is append-only JSONL under storage/, one event per measured cycle.
 * This service NEVER merges, NEVER reverts, NEVER mutates git — it measures and
 * records. The caller (AutonomousEvolutionSessionService) owns the revert.
 */
final class MetricLedgerService
{
    public const LEDGER_SCHEMA = 'atlas.plan_execution.metric_outcome_ledger.v1';

    public const EVENT_SCHEMA = 'atlas.plan_execution.metric_outcome_ledger_event.v1';

    public const STATUS_OUTCOME_MET = 'outcome_met';

    public const STATUS_OUTCOME_NOT_MET = 'outcome_not_met';

    public const STATUS_MEASUREMENT_FAILED = 'measurement_failed';

    public const STATUS_NO_CONTRACT = 'no_contract';

    public const REQUIRED_CONTRACT_KEYS = ['metric_id', 'baseline', 'target_delta', 'measure_command'];

    private ?string $storageRootOverride = null;

    /** @var null|callable(string,string):array{ok:bool,exit_code:?int,out:string,err:string} */
    private $measurerOverride = null;

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
    }

    /**
     * Inject a fake measurer for the E2E test seam: no real command is run, the
     * fake returns the same shape a real Process run would. Production code never
     * sets this; the default measurer runs the declared command for real.
     *
     * @param  null|callable(string,string):array{ok:bool,exit_code:?int,out:string,err:string}  $measurer
     */
    public function setMeasurerForTesting(?callable $measurer): void
    {
        $this->measurerOverride = $measurer;
    }

    /**
     * Validate + normalize an operator/finding-declared outcome_contract. Returns
     * null when no usable contract is present (the cycle is then measure-exempt).
     *
     * @param  array<string,mixed>  $raw
     * @return array{metric_id:string,baseline:float,target_delta:float,measure_command:string,metric_json_path:string}|null
     */
    public function normalizeContract(array $raw): ?array
    {
        foreach (self::REQUIRED_CONTRACT_KEYS as $key) {
            if (! array_key_exists($key, $raw)) {
                return null;
            }
        }

        $metricId = trim((string) $raw['metric_id']);
        $command = trim((string) $raw['measure_command']);
        if ($metricId === '' || $command === '' || ! is_numeric($raw['baseline']) || ! is_numeric($raw['target_delta'])) {
            return null;
        }

        $jsonPath = trim((string) ($raw['metric_json_path'] ?? 'metric'));

        return [
            'metric_id' => $metricId,
            'baseline' => (float) $raw['baseline'],
            'target_delta' => (float) $raw['target_delta'],
            'measure_command' => $command,
            'metric_json_path' => $jsonPath === '' ? 'metric' : $jsonPath,
        ];
    }

    /**
     * Re-measure the declared metric AFTER a merge and decide whether the declared
     * delta moved. Appends an append-only ledger event. NEVER reverts.
     *
     * @param  array{metric_id:string,baseline:float,target_delta:float,measure_command:string,metric_json_path:string}  $contract
     * @param  array<string,mixed>  $context  area_id/focus/finding_id/merge_hash/cycle_id
     * @return array<string,mixed>
     */
    public function measureOutcome(array $contract, array $context): array
    {
        $repoRoot = (string) ($context['repo_root'] ?? base_path());
        $measurement = $this->measure($contract['measure_command'], $repoRoot);

        if (! $measurement['ok']) {
            return $this->finalize($contract, $context, [
                'status' => self::STATUS_MEASUREMENT_FAILED,
                'outcome_met' => false,
                'measured_value' => null,
                'measured_delta' => null,
                'measurement_exit_code' => $measurement['exit_code'],
                'measurement_error' => $measurement['err'],
            ]);
        }

        $measured = $this->extractMetric($measurement['out'], $contract['metric_json_path']);
        if ($measured === null) {
            return $this->finalize($contract, $context, [
                'status' => self::STATUS_MEASUREMENT_FAILED,
                'outcome_met' => false,
                'measured_value' => null,
                'measured_delta' => null,
                'measurement_exit_code' => $measurement['exit_code'],
                'measurement_error' => 'metric_value_not_parseable',
            ]);
        }

        $delta = $measured - $contract['baseline'];
        $met = $delta >= $contract['target_delta'];

        return $this->finalize($contract, $context, [
            'status' => $met ? self::STATUS_OUTCOME_MET : self::STATUS_OUTCOME_NOT_MET,
            'outcome_met' => $met,
            'measured_value' => $measured,
            'measured_delta' => $delta,
            'measurement_exit_code' => $measurement['exit_code'],
            'measurement_error' => null,
        ]);
    }

    /**
     * Run the declared command (or the injected fake) and capture its output.
     *
     * @return array{ok:bool,exit_code:?int,out:string,err:string}
     */
    private function measure(string $command, string $repoRoot): array
    {
        if ($this->measurerOverride !== null) {
            return ($this->measurerOverride)($command, $repoRoot);
        }

        if (! is_dir($repoRoot)) {
            return ['ok' => false, 'exit_code' => null, 'out' => '', 'err' => 'repo_root_missing:'.$repoRoot];
        }

        $process = Process::fromShellCommandline($command, $repoRoot, AtlasSecurity::processEnv(profile: 'tool'), null, 600);
        $process->run();

        return [
            'ok' => $process->isSuccessful(),
            'exit_code' => $process->getExitCode(),
            'out' => AtlasSecurity::redactString($process->getOutput()),
            'err' => AtlasSecurity::redactString($process->getErrorOutput()),
        ];
    }

    /**
     * Parse a numeric metric from command output. Prefers a JSON dot-path; falls
     * back to the first standalone number. Returns null when no number is found
     * (never fabricates a value).
     */
    private function extractMetric(string $output, string $jsonPath): ?float
    {
        $trimmed = trim($output);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            $value = data_get($decoded, $jsonPath);
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        if (preg_match('/-?\d+(?:\.\d+)?/', $trimmed, $m) === 1) {
            return (float) $m[0];
        }

        return null;
    }

    /**
     * @param  array{metric_id:string,baseline:float,target_delta:float,measure_command:string,metric_json_path:string}  $contract
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $verdict
     * @return array<string,mixed>
     */
    private function finalize(array $contract, array $context, array $verdict): array
    {
        $event = [
            'schema_version' => self::EVENT_SCHEMA,
            'recorded_at' => $this->now(),
            'metric_id' => $contract['metric_id'],
            'baseline' => $contract['baseline'],
            'target_delta' => $contract['target_delta'],
            'measure_command' => $contract['measure_command'],
            'area_id' => (string) ($context['area_id'] ?? ''),
            'focus' => (string) ($context['focus'] ?? ''),
            'finding_id' => (string) ($context['finding_id'] ?? ''),
            'cycle_id' => (string) ($context['cycle_id'] ?? ''),
            'merge_hash' => (string) ($context['merge_hash'] ?? ''),
        ] + $verdict;

        $this->append((string) ($context['area_id'] ?? 'unscoped'), $event);

        return $event;
    }

    /**
     * @param  array<string,mixed>  $event
     */
    private function append(string $areaId, array $event): void
    {
        $path = $this->ledgerPath($areaId);
        File::ensureDirectoryExists(dirname($path));
        File::append($path, json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
    }

    public function ledgerPath(string $areaId): string
    {
        $root = $this->storageRootOverride
            ?? (function_exists('storage_path')
                ? storage_path('atlas/plan_execution/metric_outcome_ledger')
                : sys_get_temp_dir().'/atlas/plan_execution/metric_outcome_ledger');

        return rtrim($root, '/').'/'.$this->slug($areaId).'.jsonl';
    }

    private function slug(string $value): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($value)));

        return trim($slug, '_') ?: 'unscoped';
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
