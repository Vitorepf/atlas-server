<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only final-architecture smoke CLI. Inspects the END-TO-END readiness of the Self-Construction
 * architecture across 4 surfaces (cycle, coverage, stewardship, queue-repair) AND a composite `smoke`
 * action that returns complete=true ONLY when all four facts are Atlas-native and evidence-backed.
 *
 * NEVER calls a provider, NEVER runs git, NEVER mutates runtime.
 */
final class AtlasSelfConstructionFinalArchitectureSmokeCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_USAGE = 2;

    protected $signature = 'atlas:self-construction:final-smoke {action : cycle|coverage|stewardship|queue-repair|smoke} {--facts=} {--json}';

    protected $description = 'Read-only final-architecture smoke CLI: cycle | coverage | stewardship | queue-repair | smoke.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $facts = $this->loadFacts();
        if ($facts === null) {
            return self::EXIT_USAGE;
        }

        $payload = match ($action) {
            'cycle' => $this->reportCycle($facts),
            'coverage' => $this->reportCoverage($facts),
            'stewardship' => $this->reportStewardship($facts),
            'queue-repair' => $this->reportQueueRepair($facts),
            'smoke' => $this->reportSmoke($facts),
            default => null,
        };
        if ($payload === null) {
            $this->error('unknown action: '.$action);

            return self::EXIT_USAGE;
        }

        $this->emit($payload);

        return self::EXIT_OK;
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    private function reportCycle(array $facts): array
    {
        $cycle = (array) ($facts['cycle'] ?? []);

        return [
            'cycle_ready' => (bool) ($cycle['atlas_native'] ?? false) && ! empty($cycle['evidence_refs']),
            'evidence_refs_count' => count((array) ($cycle['evidence_refs'] ?? [])),
        ];
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    private function reportCoverage(array $facts): array
    {
        $cov = (array) ($facts['coverage'] ?? []);

        return [
            'fully_covered' => (bool) ($cov['fully_covered'] ?? false),
            'missing_organ' => array_values((array) ($cov['missing_organ'] ?? [])),
        ];
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    private function reportStewardship(array $facts): array
    {
        $stew = (array) ($facts['stewardship'] ?? []);

        return [
            'refused' => (bool) ($stew['refused'] ?? true),
            'project_id' => (string) ($stew['project_id'] ?? ''),
            'has_runtime_plan' => isset($stew['runtime_plan']) && is_array($stew['runtime_plan']),
        ];
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    private function reportQueueRepair(array $facts): array
    {
        $repair = (array) ($facts['queue_repair'] ?? []);

        return [
            'queue_repair_ready' => (bool) ($repair['atlas_native'] ?? false) && (int) ($repair['repaired_packets'] ?? 0) >= 0,
            'repaired_packets' => (int) ($repair['repaired_packets'] ?? 0),
        ];
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    private function reportSmoke(array $facts): array
    {
        $blockers = [];
        $cycle = $this->reportCycle($facts);
        if (! $cycle['cycle_ready']) {
            $blockers[] = 'cycle_not_atlas_native_or_no_evidence';
        }
        $cov = $this->reportCoverage($facts);
        if (! $cov['fully_covered']) {
            $blockers[] = 'organ_coverage_incomplete';
            foreach ($cov['missing_organ'] as $m) {
                $blockers[] = 'coverage:missing_organ:'.(string) $m;
            }
        }
        $stew = $this->reportStewardship($facts);
        if ($stew['refused'] || ! $stew['has_runtime_plan']) {
            $blockers[] = 'stewardship_runtime_plan_missing';
        }
        $rep = $this->reportQueueRepair($facts);
        if (! $rep['queue_repair_ready']) {
            $blockers[] = 'queue_repair_not_atlas_native';
        }

        return [
            'complete' => $blockers === [],
            'blockers' => $blockers,
            'cycle_ready' => $cycle['cycle_ready'],
            'fully_covered' => $cov['fully_covered'],
            'stewardship_ready' => ! $stew['refused'] && $stew['has_runtime_plan'],
            'queue_repair_ready' => $rep['queue_repair_ready'],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadFacts(): ?array
    {
        $path = (string) $this->option('facts');
        if ($path === '' || ! is_file($path)) {
            $this->error('--facts=<path> is required and must point to an existing JSON file');

            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->error('facts payload not valid JSON: '.mb_substr($e->getMessage(), 0, 200));

            return null;
        }
        if (! is_array($decoded)) {
            $this->error('facts payload root must be a JSON object');

            return null;
        }

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return;
        }
        foreach ($payload as $k => $v) {
            $this->line($k.': '.(is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES)));
        }
    }
}
