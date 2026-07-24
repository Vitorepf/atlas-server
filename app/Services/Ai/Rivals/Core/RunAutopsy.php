<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Support\EventsLifecycleContract;
use App\Services\Ai\Rivals\Support\RunPaths;
use App\Support\YesNo;

/**
 * Flight-recorder aggregate for one run — facts only, no provider spend.
 */
final class RunAutopsy
{
    /**
     * @return array<string, mixed>
     */
    public function build(string $runId): array
    {
        $dir = RunPaths::runDir($runId);
        $plan = is_file(RunPaths::planPath($runId))
            ? (json_decode((string) file_get_contents(RunPaths::planPath($runId)), true) ?? [])
            : [];
        $statePath = $dir.'/state.json';
        $state = is_file($statePath)
            ? (json_decode((string) file_get_contents($statePath), true) ?? [])
            : [];
        $adj = is_file(RunPaths::adjudicationPath($runId))
            ? (json_decode((string) file_get_contents(RunPaths::adjudicationPath($runId)), true) ?? [])
            : [];
        $report = is_file(RunPaths::reportPath($runId))
            ? (json_decode((string) file_get_contents(RunPaths::reportPath($runId)), true) ?? [])
            : null;
        $upliftPath = $dir.'/uplift.json';
        $uplift = is_file($upliftPath)
            ? (json_decode((string) file_get_contents($upliftPath), true) ?? null)
            : null;

        $eventsPath = RunPaths::eventsPath($runId);
        $events = [];
        $lastHeartbeatAt = null;
        if (is_file($eventsPath)) {
            foreach (file($eventsPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $event = json_decode($line, true);
                if (! is_array($event)) {
                    continue;
                }
                $events[] = [
                    'timestamp' => $event['timestamp'] ?? null,
                    'event_type' => $event['event_type'] ?? null,
                    'data_keys' => array_keys((array) ($event['data'] ?? [])),
                ];
                $lastHeartbeatAt = $event['timestamp'] ?? $lastHeartbeatAt;
            }
        }

        $receipts = [];
        $failureClasses = [];
        if (is_file(RunPaths::receiptsPath($runId))) {
            foreach (file(RunPaths::receiptsPath($runId), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $row = json_decode(trim($line), true);
                if (! is_array($row)) {
                    continue;
                }
                $fc = $row['failure_class'] ?? ($row['status'] === 'success' ? 'success' : null);
                if (is_string($fc) && $fc !== '') {
                    $failureClasses[$fc] = ($failureClasses[$fc] ?? 0) + 1;
                }
                $receipts[] = [
                    'case_id' => $row['case_id'] ?? null,
                    'arm_id' => $row['arm_id'] ?? null,
                    'repetition' => $row['repetition'] ?? null,
                    'status' => $row['status'] ?? null,
                    'failure_class' => $row['failure_class'] ?? null,
                    'failure_reason' => $row['failure_reason'] ?? null,
                    'tokens_in_present' => ($row['field_presence']['tokens_in']['present'] ?? null),
                    'tokens_in_reason' => ($row['field_presence']['tokens_in']['reason'] ?? null),
                    'tokens_out_reason' => ($row['field_presence']['tokens_out']['reason'] ?? null),
                    'cost_usd_reason' => ($row['field_presence']['cost_usd']['reason'] ?? null),
                    'wall_ms' => $row['wall_ms'] ?? null,
                ];
            }
        }

        $native = [];
        $normalizeOnly = [];
        foreach (glob(RunPaths::nativeReceiptsDir($runId).'/*.json') ?: [] as $path) {
            if (str_contains($path, '/logs/')) {
                continue;
            }
            $nr = json_decode((string) file_get_contents($path), true) ?? [];
            $mode = (string) (($nr['runner']['mode'] ?? '') ?: '');
            $stdoutBlob = $this->blobBytes($nr['stdout'] ?? null);
            $stderrBlob = $this->blobBytes($nr['stderr'] ?? null);
            $row = [
                'execution_id' => $nr['execution_id'] ?? basename($path, '.json'),
                'status' => $nr['status'] ?? null,
                'exit_code' => $nr['exit_code'] ?? null,
                'runner_mode' => $mode,
                'wall_ms' => $nr['wall_ms'] ?? null,
                'stdout_bytes' => $stdoutBlob === null ? null : strlen($stdoutBlob),
                'stderr_bytes' => $stderrBlob === null ? null : strlen($stderrBlob),
                'stdout_sha256' => $stdoutBlob === null ? null : hash('sha256', $stdoutBlob),
            ];
            $native[] = $row;
            if ($mode === 'normalize_only') {
                $normalizeOnly[] = $row['execution_id'];
            }
        }

        $unitFinished = count(array_filter(
            $events,
            fn (array $e): bool => in_array($e['event_type'], EventsLifecycleContract::UNIT_FINISHED, true),
        ));
        $eventsComplete = EventsLifecycleContract::isClaimGradeComplete($eventsPath);

        $heartbeatAgeSeconds = null;
        if (is_string($lastHeartbeatAt) && $lastHeartbeatAt !== '') {
            $ts = strtotime($lastHeartbeatAt);
            if ($ts !== false) {
                $heartbeatAgeSeconds = max(0, time() - $ts);
            }
        }

        return [
            'schema_version' => 'atlas.rivals2.autopsy.v1',
            'run_id' => $runId,
            'suite_id' => $plan['suite_id'] ?? ($state['suite_id'] ?? null),
            'state' => $state['state'] ?? null,
            'plan_provenance' => [
                'git_head' => $plan['environment']['repo_commit'] ?? ($plan['git_head'] ?? null),
                'dirty' => $plan['environment']['workspace_dirty'] ?? ($plan['workspace_dirty'] ?? null),
                'launcher' => $plan['launcher'] ?? null,
            ],
            'events' => [
                'path' => $eventsPath,
                'present' => is_file($eventsPath),
                'count' => count($events),
                'unit_finished_count' => $unitFinished,
                'complete' => $eventsComplete,
                'last_timestamp' => $lastHeartbeatAt,
                'heartbeat_age_seconds' => $heartbeatAgeSeconds,
                'tail' => array_slice($events, -20),
            ],
            'receipts' => [
                'count' => count($receipts),
                'failure_classes' => $failureClasses,
                'rows' => $receipts,
            ],
            'native_receipts' => [
                'count' => count($native),
                'normalize_only' => $normalizeOnly,
                'rows' => $native,
            ],
            'adjudication' => [
                'pipeline_valid' => ($adj['pipeline_valid'] ?? false) === true,
                'internal_claim_allowed' => ($adj['internal_claim_allowed'] ?? false) === true,
                'pipeline_blockers' => array_values((array) ($adj['pipeline_blockers'] ?? [])),
                'internal_claim_blockers' => array_values((array) ($adj['internal_claim_blockers'] ?? [])),
                'not_ready_reasons' => array_values((array) ($adj['not_ready_reasons'] ?? [])),
            ],
            'report' => $report === null ? null : [
                'present' => true,
                'pipeline_valid' => ($report['pipeline_valid'] ?? false) === true,
                'internal_claim_allowed' => ($report['internal_claim_allowed'] ?? false) === true,
                'row_count' => count((array) ($report['rows'] ?? [])),
                'report_hash' => $report['report_hash'] ?? null,
            ],
            'uplift' => $uplift === null ? null : [
                'uplift_kind' => $uplift['uplift_kind'] ?? null,
                'internal_claim_allowed' => ($uplift['internal_claim_allowed'] ?? false) === true,
                'stop_the_line' => ($uplift['stop_the_line'] ?? false) === true,
                'proven_pair_count' => $uplift['proven_pair_count'] ?? null,
                'excluded_pair_keys' => array_values((array) ($uplift['excluded_pair_keys'] ?? [])),
            ],
            'trust' => [
                'is_atlas_fact' => ($adj['internal_claim_allowed'] ?? false) === true
                    && $eventsComplete
                    && $normalizeOnly === []
                    && (($adj['pipeline_valid'] ?? false) === true)
                    && ! $this->receiptsIndicateHarnessOmit($receipts)
                    && $this->measurementComplete($report, $receipts),
                'blockers' => array_values(array_filter([
                    $eventsComplete ? null : 'events_incomplete',
                    $normalizeOnly === [] ? null : 'normalize_only_present',
                    (($adj['internal_claim_allowed'] ?? false) === true) ? null : 'internal_claim_blocked',
                    (($adj['pipeline_valid'] ?? false) === true) ? null : 'pipeline_invalid',
                    $this->receiptsIndicateHarnessOmit($receipts) ? 'harness_omit' : null,
                    $this->measurementComplete($report, $receipts) ? null : 'measurement_incomplete',
                ])),
            ],
        ];
    }

    public function toMarkdown(array $autopsy): string
    {
        $md = "# Rivals autopsy `{$autopsy['run_id']}`\n\n";
        $md .= '- suite: '.($autopsy['suite_id'] ?? '?')."\n";
        $md .= '- state: '.($autopsy['state'] ?? '?')."\n";
        $md .= '- events_complete: '.(YesNo::trueFalse($autopsy['events']['complete'] ?? false))."\n";
        $md .= '- is_atlas_fact: '.(YesNo::trueFalse($autopsy['trust']['is_atlas_fact'] ?? false))."\n";
        $md .= '- claim blockers: '.implode(', ', (array) ($autopsy['adjudication']['internal_claim_blockers'] ?? []))."\n";
        $md .= '- trust blockers: '.implode(', ', (array) ($autopsy['trust']['blockers'] ?? []))."\n";
        $md .= '- failure_classes: '.json_encode($autopsy['receipts']['failure_classes'] ?? [])."\n";
        $md .= '- normalize_only: '.implode(', ', (array) ($autopsy['native_receipts']['normalize_only'] ?? []))."\n";
        if (is_array($autopsy['uplift'] ?? null)) {
            $md .= '- uplift: '.($autopsy['uplift']['uplift_kind'] ?? '?')
                .' proven='.($autopsy['uplift']['proven_pair_count'] ?? 0)
                .' excluded='.count((array) ($autopsy['uplift']['excluded_pair_keys'] ?? []))."\n";
        }

        return $md;
    }

    /**
     * @param  list<array<string, mixed>>  $receipts
     */
    private function receiptsIndicateHarnessOmit(array $receipts): bool
    {
        foreach ($receipts as $row) {
            foreach (['tokens_in_reason', 'tokens_out_reason', 'cost_usd_reason'] as $key) {
                $reason = (string) ($row[$key] ?? '');
                if ($reason !== '' && preg_match('/omit|harness_omit|inspect_logs_omit/i', $reason) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>|null  $report
     * @param  list<array<string, mixed>>  $receipts
     */
    private function measurementComplete(?array $report, array $receipts): bool
    {
        if ($this->receiptsIndicateHarnessOmit($receipts)) {
            return false;
        }
        $rows = is_array($report) ? array_values(array_filter((array) ($report['rows'] ?? []), 'is_array')) : [];
        if ($rows === []) {
            return false;
        }
        foreach ($rows as $row) {
            if (($row['avg_tokens_in'] ?? null) !== null || ($row['tokens_in_avg'] ?? null) !== null) {
                return true;
            }
        }
        foreach ($receipts as $row) {
            if (($row['tokens_in_present'] ?? null) === true) {
                return true;
            }
        }

        return false;
    }

    private function blobBytes(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES) ?: null;
    }
}
