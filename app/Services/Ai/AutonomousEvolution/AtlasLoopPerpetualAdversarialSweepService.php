<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * L5-13: fortnightly adversarial sweep for Loop safety findings.
 *
 * The sweep is deliberately not a new executor. LOW findings become governed
 * backlog intents; HIGH findings are parked for operator review. No provider
 * calls, no direct code mutation, and no change to the never-merge gate.
 */
final class AtlasLoopPerpetualAdversarialSweepService
{
    public const SCHEMA_VERSION = 'atlas.loop.perpetual_adversarial_sweep.v1';

    public function __construct(
        private readonly AtlasLoopBacklogManifestService $manifest,
        private readonly AtlasLoopBacklogAutoFeederService $backlogFeeder,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function sweep(array $options = []): array
    {
        $cfg = (array) config('atlas.loop.perpetual_sweep', []);
        $enabled = (bool) ($options['enabled'] ?? $cfg['enabled'] ?? true);
        $write = (bool) ($options['write'] ?? false);
        $manifestPath = (string) ($options['manifest_path'] ?? $this->manifest->defaultPath());
        $manifestLimit = max(10, (int) ($options['manifest_limit'] ?? $cfg['manifest_limit'] ?? 200));
        $maxFindings = max(1, (int) ($options['max_findings'] ?? $cfg['max_findings'] ?? 12));
        $lowAutoFix = (bool) ($options['low_auto_fix_enabled'] ?? $cfg['low_auto_fix_enabled'] ?? false);
        $highReview = (bool) ($options['high_review_enabled'] ?? $cfg['high_review_enabled'] ?? true);
        $includeBacklogFeed = (bool) ($options['include_backlog_feed'] ?? $cfg['include_backlog_feed'] ?? true);

        if (! $enabled) {
            return $this->payload('disabled', [], [], null, [
                'write' => $write,
                'manifest_path' => $manifestPath,
                'manifest_limit' => $manifestLimit,
                'max_findings' => $maxFindings,
                'low_auto_fix_enabled' => $lowAutoFix,
                'high_review_enabled' => $highReview,
                'include_backlog_feed' => $includeBacklogFeed,
            ]);
        }

        [$findings, $feed] = $this->collectFindings($cfg, $includeBacklogFeed, $maxFindings, $manifestPath, $manifestLimit);
        $actions = [];
        foreach ($findings as $finding) {
            $actions[] = $this->actOnFinding($finding, $write, $manifestPath, $manifestLimit, $lowAutoFix, $highReview);
        }

        $status = $findings === []
            ? 'clear'
            : ($write ? 'verdict_produced' : 'dry_run');

        return $this->payload($status, $findings, $actions, $feed, [
            'write' => $write,
            'manifest_path' => $manifestPath,
            'manifest_limit' => $manifestLimit,
            'max_findings' => $maxFindings,
            'low_auto_fix_enabled' => $lowAutoFix,
            'high_review_enabled' => $highReview,
            'include_backlog_feed' => $includeBacklogFeed,
        ]);
    }

    /**
     * @param  array<string,mixed>  $cfg
     * @return array{0:list<array<string,mixed>>,1:?array<string,mixed>}
     */
    private function collectFindings(array $cfg, bool $includeBacklogFeed, int $maxFindings, string $manifestPath, int $manifestLimit): array
    {
        $findings = [];
        foreach ((array) ($cfg['carryover_findings'] ?? []) as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $finding = $this->normalizeFinding($raw, 'carryover:l4_12');
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        $feed = null;
        if ($includeBacklogFeed) {
            try {
                $feed = $this->backlogFeeder->feed(null, [
                    'write' => false,
                    'manifest_path' => $manifestPath,
                    'manifest_limit' => $manifestLimit,
                    'min_signal_count' => 1,
                    'max_items' => $maxFindings,
                    'include_scorecard_weak_receipts' => false,
                    'include_sweep_findings' => true,
                ]);
                foreach ((array) ($feed['actions'] ?? []) as $action) {
                    $item = $action['item'] ?? null;
                    if (! is_array($item) || ! str_contains((string) ($item['source'] ?? ''), 'sweep')) {
                        continue;
                    }
                    $finding = $this->normalizeFinding($item, 'auto_feed:sweep_finding');
                    if ($finding !== null) {
                        $findings[] = $finding;
                    }
                }
            } catch (Throwable $e) {
                $feed = [
                    'schema_version' => AtlasLoopBacklogAutoFeederService::SCHEMA_VERSION,
                    'status' => 'unavailable',
                    'reason' => mb_substr($e->getMessage(), 0, 160),
                ];
            }
        }

        $findings = $this->dedupeFindings($findings);
        usort($findings, static function (array $a, array $b): int {
            $severity = ['high' => 2, 'low' => 1];
            $sev = ($severity[(string) ($b['severity'] ?? 'low')] ?? 0) <=> ($severity[(string) ($a['severity'] ?? 'low')] ?? 0);
            if ($sev !== 0) {
                return $sev;
            }

            return ((float) ($b['priority'] ?? 0)) <=> ((float) ($a['priority'] ?? 0));
        });

        return [array_slice($findings, 0, $maxFindings), $feed];
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>|null
     */
    private function normalizeFinding(array $raw, string $defaultSource): ?array
    {
        $path = ltrim(trim((string) ($raw['path'] ?? '')), '/');
        if ($path === '' || ! is_file(base_path($path))) {
            return null;
        }

        $reason = trim((string) ($raw['reason'] ?? $raw['objective'] ?? 'adversarial_sweep_finding'));
        $reason = $reason !== '' ? mb_substr($reason, 0, 180) : 'adversarial_sweep_finding';
        $source = trim((string) ($raw['source'] ?? $defaultSource));
        $source = $source !== '' ? $source : $defaultSource;
        $severity = $this->severityFor($path, $reason, $raw['severity'] ?? null);
        $objective = trim((string) ($raw['objective'] ?? ''));
        if ($objective === '') {
            $objective = sprintf('Refutar e corrigir achado adversarial %s em %s.', $reason, $path);
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'path' => $path,
            'reason' => $reason,
            'severity' => $severity,
            'objective' => $objective,
            'source' => $source,
            'source_key' => (string) ($raw['source_key'] ?? hash('sha256', $source.'|'.$path.'|'.$reason)),
            'priority' => round(max(0.1, min(1.0, (float) ($raw['priority'] ?? ($severity === 'high' ? 0.92 : 0.68)))), 4),
            'observed_at' => (string) ($raw['observed_at'] ?? Carbon::now()->toIso8601String()),
        ];
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function actOnFinding(array $finding, bool $write, string $manifestPath, int $manifestLimit, bool $lowAutoFix, bool $highReview): array
    {
        $severity = (string) ($finding['severity'] ?? 'low');
        if ($severity === 'high') {
            if (! $highReview) {
                return [
                    'type' => 'perpetual_sweep_action',
                    'status' => 'high_review_disabled',
                    'finding' => $finding,
                ];
            }

            return [
                ...$this->manifest->append($manifestPath, $this->manifestItem($finding, 'perpetual_sweep:high_review'), $manifestLimit, $write),
                'perpetual_sweep_action' => 'park_high_for_operator_review',
            ];
        }

        if (! $lowAutoFix) {
            return [
                'type' => 'perpetual_sweep_action',
                'status' => 'low_auto_fix_disabled',
                'finding' => $finding,
            ];
        }

        return [
            ...$this->manifest->append($manifestPath, $this->manifestItem($finding, 'perpetual_sweep:low_auto_fix'), $manifestLimit, $write),
            'perpetual_sweep_action' => 'enqueue_low_for_governed_loop_fix',
        ];
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function manifestItem(array $finding, string $source): array
    {
        $severity = (string) ($finding['severity'] ?? 'low');

        return [
            'path' => (string) $finding['path'],
            'objective' => (string) $finding['objective'],
            'priority' => (float) $finding['priority'],
            'source' => $source,
            'source_key' => hash('sha256', $source.'|'.(string) $finding['source_key'].'|'.(string) $finding['path']),
            'reason' => (string) $finding['reason'],
            'severity' => $severity,
            'operator_review_required' => $severity === 'high',
            'autofix_mode' => $severity === 'low' ? 'governed_loop_backlog_intent' : 'blocked_until_operator_review',
            'perpetual_sweep_schema_version' => self::SCHEMA_VERSION,
            'perpetual_sweep_observed_at' => (string) $finding['observed_at'],
        ];
    }

    private function severityFor(string $path, string $reason, mixed $explicit): string
    {
        $severity = strtolower(trim((string) $explicit));
        if (in_array($severity, ['high', 'low'], true)) {
            return $severity;
        }

        $haystack = strtolower($path.' '.$reason);
        foreach (['promotiongate', 'materializer', 'harnessguard', 'frozen', 'judge', 'never-merge', 'auto-merge', 'merge', 'certif', 'gate'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return 'high';
            }
        }

        return 'low';
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @return list<array<string,mixed>>
     */
    private function dedupeFindings(array $findings): array
    {
        $out = [];
        foreach ($findings as $finding) {
            $key = (string) ($finding['path'] ?? '').'|'.(string) ($finding['reason'] ?? '').'|'.(string) ($finding['severity'] ?? 'low');
            if (! isset($out[$key]) || (float) ($finding['priority'] ?? 0) > (float) ($out[$key]['priority'] ?? 0)) {
                $out[$key] = $finding;
            }
        }

        return array_values($out);
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @param  list<array<string,mixed>>  $actions
     * @param  array<string,mixed>|null  $feed
     * @param  array<string,mixed>  $run
     * @return array<string,mixed>
     */
    private function payload(string $status, array $findings, array $actions, ?array $feed, array $run): array
    {
        $highCount = count(array_filter($findings, static fn (array $f): bool => ($f['severity'] ?? null) === 'high'));
        $lowCount = count(array_filter($findings, static fn (array $f): bool => ($f['severity'] ?? null) === 'low'));
        $enqueued = count(array_filter($actions, static fn (array $a): bool => ($a['status'] ?? null) === 'enqueued'));
        $duplicates = count(array_filter($actions, static fn (array $a): bool => ($a['status'] ?? null) === 'duplicate'));
        $dryRun = count(array_filter($actions, static fn (array $a): bool => ($a['status'] ?? null) === 'dry_run'));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'sweep' => [
                'cadence' => 'fortnightly',
                'schedule_enabled' => (bool) config('atlas.loop.perpetual_sweep.schedule_enabled', true),
                'schedule_day' => (int) config('atlas.loop.perpetual_sweep.schedule_day', 6),
                'schedule_time' => (string) config('atlas.loop.perpetual_sweep.schedule_time', '06:05'),
                'schedule_week_parity' => (int) config('atlas.loop.perpetual_sweep.schedule_week_parity', 0),
            ],
            'run' => $run,
            'finding_count' => count($findings),
            'high_count' => $highCount,
            'low_count' => $lowCount,
            'actions_count' => count($actions),
            'enqueued_count' => $enqueued,
            'duplicate_count' => $duplicates,
            'dry_run_count' => $dryRun,
            'verdict' => [
                'produced' => ! in_array($status, ['disabled'], true),
                'result' => $highCount > 0 ? 'high_findings_parked_for_review' : ($lowCount > 0 ? 'low_findings_queued_for_governed_fix' : 'clear'),
                'provider_calls' => false,
                'direct_code_mutation' => false,
                'low_auto_fix_is_backlog_intent' => true,
                'high_requires_operator_review' => true,
                'never_merge_changed' => false,
                'completion_claim_allowed' => $status !== 'disabled' && count($findings) > 0,
            ],
            'feed_report' => $feed === null ? null : [
                'status' => (string) ($feed['status'] ?? 'unknown'),
                'candidate_count' => (int) ($feed['candidate_count'] ?? 0),
                'source_counts' => (array) ($feed['source_counts'] ?? []),
            ],
            'findings' => $findings,
            'actions' => $actions,
            'manifest_path' => (string) ($run['manifest_path'] ?? $this->manifest->defaultPath()),
        ];
    }
}
