<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopFunnelService;
use App\Services\Ai\AutonomousEvolution\AtlasLoopObservabilityDigest;
use App\Services\Ai\AutonomousEvolution\Observability\AtlasLoopProxyDriftFactDetector;
use App\Services\Ai\AutonomousEvolution\Observability\AtlasLoopQueueDryingAlarmDetector;
use App\Services\Ai\AutonomousEvolution\Observability\AtlasLoopStagnationAlarmDetector;
use Illuminate\Console\Command;
use Throwable;

/**
 * OBSERVABILITY DASHBOARD — consolidate the 3 alarm detectors (stagnation / proxy-drift / queue-drying) + the
 * existing funnel snapshot + the pipeline observability digest into ONE read-only view a human or a 24/7 cron
 * can run anytime. `--json` for watchdog parsing.
 *
 * EXIT CODE is the actionable signal: 0 when every detector is clear, 2 when ANY detector reports an alarm
 * (stagnated / drifting / drying). ANTI-GOODHART: exit 2 is a NOTIFICATION trigger, NOT a merge gate — the
 * operator decides what to do. PURE READ-ONLY: it only reads — it never persists, never writes any file, and
 * emits no signals, taking no decision in the operator's place. Each section is fail-open so a missing table
 * degrades one box to `unavailable` rather than crashing the dashboard.
 */
final class AtlasLoopObservabilityDashboardCommand extends Command
{
    protected $signature = 'atlas:loop:observability:dashboard {campaignId} {--json} {--window=3600}';

    protected $description = 'Read-only consolidated loop observability dashboard (3 detectors + funnel + pipeline digest); exit 2 on any alarm.';

    public function handle(): int
    {
        $campaignId = (string) $this->argument('campaignId');
        $window = max(1, (int) $this->option('window'));

        $stagnation = $this->safe(static fn (): array => app(AtlasLoopStagnationAlarmDetector::class)->evaluate($campaignId, $window));
        $proxyDrift = $this->safe(static fn (): array => app(AtlasLoopProxyDriftFactDetector::class)->evaluate($campaignId));
        $queueDrying = $this->safe(static fn (): array => app(AtlasLoopQueueDryingAlarmDetector::class)->evaluate($campaignId, $window));
        $funnel = $this->safe(static fn (): array => app(AtlasLoopFunnelService::class)->snapshot($campaignId));
        $pipelineDigest = $this->safe(static fn (): array => app(AtlasLoopObservabilityDigest::class)->section($campaignId));

        $dashboard = [
            'stagnation' => $stagnation,
            'proxy_drift' => $proxyDrift,
            'queue_drying' => $queueDrying,
            'funnel' => $funnel,
            'pipeline_digest' => $pipelineDigest,
        ];

        $stagnated = (bool) ($stagnation['stagnated'] ?? false);
        $drifting = (bool) ($proxyDrift['drifting'] ?? false);
        $drying = (bool) ($queueDrying['drying'] ?? false);
        $anyAlarm = $stagnated || $drifting || $drying;

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($dashboard, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $anyAlarm ? 2 : self::SUCCESS;
        }

        $this->renderHuman($campaignId, $window, $stagnated, $drifting, $drying, $dashboard);

        return $anyAlarm ? 2 : self::SUCCESS;
    }

    /**
     * Run ONE section fail-open: a missing table / any error degrades it to `unavailable` rather than crashing
     * the whole read-only dashboard.
     *
     * @param  callable():array<string,mixed>  $probe
     * @return array<string,mixed>
     */
    private function safe(callable $probe): array
    {
        try {
            return $probe();
        } catch (Throwable $e) {
            return ['status' => 'unavailable', 'reason' => mb_substr($e->getMessage(), 0, 160)];
        }
    }

    /**
     * @param  array<string,mixed>  $dashboard
     */
    private function renderHuman(string $campaignId, int $window, bool $stagnated, bool $drifting, bool $drying, array $dashboard): void
    {
        $this->components->info(sprintf('Atlas Loop observability — campaign %s (window %ds)', $campaignId, $window));

        $this->components->twoColumnDetail('STAGNATION', $this->badge($stagnated).' reason='.(string) ($dashboard['stagnation']['reason_code'] ?? '?'));
        $this->components->twoColumnDetail('PROXY DRIFT', $this->badge($drifting).' ratio='.(string) ($dashboard['proxy_drift']['drift_ratio'] ?? '?'));
        $this->components->twoColumnDetail('QUEUE DRYING', $this->badge($drying).' slope='.(string) ($dashboard['queue_drying']['slope_signal'] ?? '?'));

        $this->line('');
        $this->line('FUNNEL: '.(string) json_encode($dashboard['funnel'], JSON_UNESCAPED_SLASHES));
        $this->line('PIPELINE DIGEST: '.(string) json_encode($dashboard['pipeline_digest'], JSON_UNESCAPED_SLASHES));

        $this->line('');
        $this->line('EXIT: '.(($stagnated || $drifting || $drying) ? '2 (ALARM — operator notification trigger, NOT a merge gate)' : '0 (all clear)'));
    }

    private function badge(bool $alarm): string
    {
        return $alarm ? 'ALARM' : 'PASS';
    }
}
