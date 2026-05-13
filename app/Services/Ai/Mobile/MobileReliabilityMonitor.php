<?php

namespace App\Services\Ai\Mobile;

use App\Models\AiInboxItem;
use App\Models\AiJob;
use App\Models\MobilePushDelivery;
use App\Services\AuditLogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MobileReliabilityMonitor
{
    public const SCHEDULER_TICK_KEY = 'atlas:scheduler:last_tick_at';

    private const LAST_STATUS_KEY = 'atlas:mobile:reliability:last_status';

    private const ALERT_COOLDOWN_PREFIX = 'atlas:mobile:reliability:alert:';

    public function __construct(
        private readonly ExpoCircuitBreaker $circuit,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function check(bool $apply = false): array
    {
        $snapshot = $this->snapshot();
        $transition = $apply ? $this->recordTransition($snapshot) : ['recorded' => false, 'reason' => 'dry_run'];
        $alert = $apply ? $this->sendAlertIfNeeded($snapshot) : ['sent' => false, 'reason' => 'dry_run'];

        return [
            ...$snapshot,
            'dry_run' => ! $apply,
            'transition' => $transition,
            'alert' => $alert,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $checks = [
            $this->mobilePushConfigurationCheck(),
            $this->schedulerStaleCheck(),
            $this->performanceReportFreshCheck(),
            $this->pushDegradedCheck(),
            $this->circuitStuckCheck(),
            $this->jobsSilentCheck(),
        ];

        $status = $this->aggregateStatus($checks);

        return [
            'status' => $status,
            'generated_at' => now()->toJSON(),
            'reasons' => collect($checks)
                ->filter(fn (array $check): bool => $check['status'] !== 'healthy')
                ->map(fn (array $check): string => (string) $check['reason'])
                ->values()
                ->all(),
            'checks' => $checks,
        ];
    }

    public static function recordSchedulerTick(): void
    {
        Cache::put(self::SCHEDULER_TICK_KEY, now()->toJSON(), now()->addDay());
    }

    /**
     * @return array<string,mixed>
     */
    private function mobilePushConfigurationCheck(): array
    {
        $enabled = (bool) config('atlas.mobile.enabled', false);

        return $this->checkResult(
            'mobile_push_configuration',
            $enabled ? 'healthy' : 'warning',
            $enabled ? 'Mobile push habilitado.' : 'Mobile push desabilitado por configuracao.',
            [
                'mobile_enabled' => $enabled,
                'push_dispatch_enabled' => $enabled,
                'blocked_reason' => $enabled ? null : 'atlas_mobile_disabled',
                'env_key' => 'ATLAS_MOBILE_ENABLED',
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function schedulerStaleCheck(): array
    {
        $thresholdMinutes = max(1, (int) config('atlas.mobile.alerts.scheduler_stale_minutes', 5));
        $lastTick = Cache::get(self::SCHEDULER_TICK_KEY);

        if (! is_string($lastTick) || trim($lastTick) === '') {
            return $this->checkResult(
                'scheduler_stale',
                'critical',
                'Scheduler sem heartbeat registrado.',
                ['last_tick_at' => null, 'threshold_minutes' => $thresholdMinutes],
            );
        }

        try {
            $lastTickAt = Carbon::parse($lastTick);
        } catch (Throwable) {
            return $this->checkResult(
                'scheduler_stale',
                'critical',
                'Scheduler heartbeat invalido.',
                ['last_tick_at' => $lastTick, 'threshold_minutes' => $thresholdMinutes],
            );
        }

        $ageMinutes = $lastTickAt->diffInMinutes(now());

        if ($lastTickAt->lt(now()->subMinutes($thresholdMinutes))) {
            return $this->checkResult(
                'scheduler_stale',
                'critical',
                'Scheduler nao tickou dentro da janela esperada.',
                [
                    'last_tick_at' => $lastTickAt->toJSON(),
                    'age_minutes' => $ageMinutes,
                    'threshold_minutes' => $thresholdMinutes,
                ],
            );
        }

        return $this->checkResult(
            'scheduler_stale',
            'healthy',
            'Scheduler heartbeat recente.',
            [
                'last_tick_at' => $lastTickAt->toJSON(),
                'age_minutes' => $ageMinutes,
                'threshold_minutes' => $thresholdMinutes,
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function performanceReportFreshCheck(): array
    {
        if (! (bool) config('atlas.ai_metrics.performance_report_enabled', true)) {
            return $this->checkResult('performance_report_fresh', 'healthy', 'Relatorio de performance desabilitado.', ['skipped' => true]);
        }

        if (! (bool) config('atlas.ai_metrics.performance_report_emit', true)) {
            return $this->checkResult('performance_report_fresh', 'healthy', 'Emissao mobile do relatorio de performance desabilitada.', ['skipped' => true]);
        }

        if (! Schema::hasTable('ai_inbox_items')) {
            return $this->checkResult('performance_report_fresh', 'warning', 'Tabela de Inbox ausente para validar entrega do relatorio.', ['skipped' => true]);
        }

        $timezone = (string) config('atlas.ai_metrics.performance_report_timezone', config('app.timezone', 'UTC'));
        $timezone = trim($timezone) !== '' ? $timezone : 'UTC';
        $now = Carbon::now($timezone);
        $graceMinutes = max(0, (int) config('atlas.ai_metrics.performance_report_grace_minutes', 90));
        $dueAt = $this->performanceReportDueAt($now, $graceMinutes);
        $reportDate = $now->copy()->subDay()->toDateString();
        $dedupeKey = 'atlas-ai-performance:daily:'.$reportDate;
        $evidence = [
            'report_date' => $reportDate,
            'dedupe_key' => $dedupeKey,
            'timezone' => $timezone,
            'due_at' => $dueAt->toJSON(),
            'grace_minutes' => $graceMinutes,
        ];

        if ($now->lt($dueAt)) {
            return $this->checkResult('performance_report_fresh', 'healthy', 'Relatorio diario ainda dentro da janela esperada de entrega.', [
                ...$evidence,
                'not_due_yet' => true,
            ]);
        }

        $item = AiInboxItem::query()
            ->where('category', 'atlas_ai_performance')
            ->where('dedupe_key', $dedupeKey)
            ->latest('updated_at')
            ->first();

        if (! $item instanceof AiInboxItem) {
            if (
                (bool) config('atlas.ai_metrics.performance_report_alert_require_history', true)
                && ! $this->hasPriorDailyPerformanceReportDelivery($dedupeKey)
            ) {
                return $this->checkResult('performance_report_fresh', 'healthy', 'Aguardando primeira entrega do relatorio diario antes de alertar ausencia.', [
                    ...$evidence,
                    'first_delivery_pending' => true,
                    'skipped' => true,
                ]);
            }

            return $this->checkResult('performance_report_fresh', 'critical', 'Relatorio diario de performance nao foi entregue no Inbox depois da janela esperada.', $evidence);
        }

        return $this->checkResult('performance_report_fresh', 'healthy', 'Relatorio diario de performance entregue no Inbox.', [
            ...$evidence,
            'item_id' => $item->id,
            'item_status' => $item->status,
            'item_updated_at' => $item->updated_at?->toJSON(),
        ]);
    }

    private function hasPriorDailyPerformanceReportDelivery(string $currentDedupeKey): bool
    {
        return AiInboxItem::query()
            ->where('category', 'atlas_ai_performance')
            ->where('dedupe_key', 'like', 'atlas-ai-performance:daily:%')
            ->where('dedupe_key', '<>', $currentDedupeKey)
            ->exists();
    }

    private function performanceReportDueAt(Carbon $now, int $graceMinutes): Carbon
    {
        $raw = (string) config('atlas.ai_metrics.performance_report_time', '07:05');
        $hour = 7;
        $minute = 5;

        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($raw), $matches) === 1) {
            $hour = max(0, min(23, (int) $matches[1]));
            $minute = max(0, min(59, (int) $matches[2]));
        }

        return $now->copy()
            ->startOfDay()
            ->setTime($hour, $minute)
            ->addMinutes($graceMinutes);
    }

    /**
     * @return array<string,mixed>
     */
    private function pushDegradedCheck(): array
    {
        if (! Schema::hasTable('mobile_push_deliveries')) {
            return $this->checkResult('push_degraded', 'healthy', 'Tabela de push ainda nao existe.', ['skipped' => true]);
        }

        $sampleSize = max(1, (int) config('atlas.mobile.alerts.push_sample_size', 20));
        $minSample = max(1, (int) config('atlas.mobile.alerts.push_min_sample', 5));
        $threshold = max(0.0, min(1.0, (float) config('atlas.mobile.alerts.push_success_rate_threshold', 0.5)));
        $successStatuses = ['sent', 'receipt_ok'];
        $countedStatuses = ['sent', 'receipt_ok', 'failed_transient', 'failed_permanent', 'receipt_error'];

        $deliveries = MobilePushDelivery::query()
            ->whereIn('status', $countedStatuses)
            ->whereNotNull('attempted_at')
            ->latest('attempted_at')
            ->limit($sampleSize)
            ->get(['status', 'attempted_at']);

        $total = $deliveries->count();
        $success = $deliveries->whereIn('status', $successStatuses)->count();
        $successRate = $total > 0 ? round($success / $total, 4) : null;

        $evidence = [
            'sample_size' => $sampleSize,
            'min_sample' => $minSample,
            'observed_total' => $total,
            'success_count' => $success,
            'success_rate' => $successRate,
            'threshold' => $threshold,
        ];

        if ($total < $minSample) {
            return $this->checkResult('push_degraded', 'healthy', 'Amostra de push insuficiente para alertar.', $evidence);
        }

        if ($successRate !== null && $successRate < $threshold) {
            return $this->checkResult('push_degraded', 'warning', 'Taxa de sucesso de push abaixo do limite.', $evidence);
        }

        return $this->checkResult('push_degraded', 'healthy', 'Taxa de sucesso de push dentro do esperado.', $evidence);
    }

    /**
     * @return array<string,mixed>
     */
    private function circuitStuckCheck(): array
    {
        $state = $this->circuit->state();
        $thresholdMinutes = max(1, (int) config('atlas.mobile.alerts.circuit_stuck_minutes', 30));

        if (($state['state'] ?? 'closed') !== 'open') {
            return $this->checkResult(
                'circuit_stuck',
                'healthy',
                'Expo circuit breaker fechado.',
                ['state' => $state, 'threshold_minutes' => $thresholdMinutes],
            );
        }

        $openedAt = null;
        if (is_string($state['opened_at'] ?? null)) {
            try {
                $openedAt = Carbon::parse((string) $state['opened_at']);
            } catch (Throwable) {
                $openedAt = null;
            }
        }
        $ageMinutes = $openedAt ? $openedAt->diffInMinutes(now()) : null;
        $evidence = [
            'state' => $state,
            'opened_at' => $openedAt?->toJSON(),
            'age_minutes' => $ageMinutes,
            'threshold_minutes' => $thresholdMinutes,
        ];

        if ($openedAt === null || $openedAt->lt(now()->subMinutes($thresholdMinutes))) {
            return $this->checkResult('circuit_stuck', 'warning', 'Expo circuit breaker aberto ha tempo demais.', $evidence);
        }

        return $this->checkResult('circuit_stuck', 'healthy', 'Expo circuit breaker aberto recentemente, aguardando recuperacao.', $evidence);
    }

    /**
     * @return array<string,mixed>
     */
    private function jobsSilentCheck(): array
    {
        if (! Schema::hasTable('ai_jobs')) {
            return $this->checkResult('jobs_silent', 'healthy', 'Tabela de jobs ainda nao existe.', ['skipped' => true]);
        }

        $thresholdHours = max(1, (int) config('atlas.mobile.alerts.jobs_silent_hours', 6));
        $totalJobs = AiJob::query()->count();
        if ($totalJobs === 0) {
            return $this->checkResult(
                'jobs_silent',
                'healthy',
                'Nenhum job registrado ainda.',
                ['total_jobs' => 0, 'threshold_hours' => $thresholdHours],
            );
        }

        $activeQuery = AiJob::query()->whereIn('status', ['queued', 'processing']);
        $activeCount = (clone $activeQuery)->count();
        $oldestActiveUpdatedAt = (clone $activeQuery)->oldest('updated_at')->value('updated_at');
        $latestFinishedAt = AiJob::query()
            ->whereIn('status', ['succeeded', 'failed', 'cancelled'])
            ->whereNotNull('finished_at')
            ->latest('finished_at')
            ->value('finished_at');

        $latestFinished = $latestFinishedAt ? Carbon::parse($latestFinishedAt) : null;
        $oldestActive = $oldestActiveUpdatedAt ? Carbon::parse($oldestActiveUpdatedAt) : null;
        $staleBoundary = now()->subHours($thresholdHours);
        $evidence = [
            'total_jobs' => $totalJobs,
            'active_jobs' => $activeCount,
            'latest_finished_at' => $latestFinished?->toJSON(),
            'oldest_active_updated_at' => $oldestActive?->toJSON(),
            'threshold_hours' => $thresholdHours,
        ];

        if ($activeCount === 0) {
            return $this->checkResult('jobs_silent', 'healthy', 'Sem jobs ativos pendurados.', $evidence);
        }

        if ($latestFinished === null) {
            return $this->checkResult('jobs_silent', 'warning', 'Existem jobs ativos, mas nenhum job finalizado registrado.', $evidence);
        }

        if ($latestFinished->lt($staleBoundary)) {
            return $this->checkResult('jobs_silent', 'warning', 'Jobs ativos existem, mas nenhum job finalizou recentemente.', $evidence);
        }

        return $this->checkResult('jobs_silent', 'healthy', 'Jobs ativos com finalizacao recente registrada.', $evidence);
    }

    /**
     * @param  array<int,array<string,mixed>>  $checks
     */
    private function aggregateStatus(array $checks): string
    {
        $rank = ['healthy' => 0, 'warning' => 1, 'critical' => 2];

        return collect($checks)
            ->map(fn (array $check): int => $rank[$check['status']] ?? 0)
            ->max() === 2 ? 'critical' : (collect($checks)->contains(fn (array $check): bool => $check['status'] === 'warning') ? 'warning' : 'healthy');
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    private function recordTransition(array $snapshot): array
    {
        $status = (string) $snapshot['status'];
        $previous = Cache::get(self::LAST_STATUS_KEY);

        if ($previous === $status) {
            return ['recorded' => false, 'reason' => 'unchanged', 'previous_status' => $previous, 'status' => $status];
        }

        Cache::put(self::LAST_STATUS_KEY, $status, now()->addDays(7));

        if ($status === 'healthy' && in_array($previous, ['warning', 'critical'], true)) {
            $eventType = 'system.health.recovered';
            $this->audit->record('system.health.recovered', [
                'subject_type' => 'mobile_reliability_monitor',
                'subject_id' => null,
                'actor_type' => 'system',
                'actor_id' => null,
                'severity' => 'info',
                'summary' => 'Core Reliability Monitor voltou ao estado healthy.',
                'evidence' => ['previous_status' => $previous, 'snapshot' => $snapshot],
                'privacy' => ['sensitivity' => 'private'],
            ]);

            $this->writeLocalAlert($eventType, $snapshot, $previous);

            return ['recorded' => true, 'event_type' => $eventType, 'previous_status' => $previous, 'status' => $status];
        }

        if (in_array($status, ['warning', 'critical'], true) && $previous !== $status) {
            $eventType = 'system.health.degraded';
            $this->audit->record('system.health.degraded', [
                'subject_type' => 'mobile_reliability_monitor',
                'subject_id' => null,
                'actor_type' => 'system',
                'actor_id' => null,
                'severity' => $status === 'critical' ? 'critical' : 'warning',
                'summary' => 'Core Reliability Monitor detectou degradacao.',
                'evidence' => ['previous_status' => $previous, 'snapshot' => $snapshot],
                'privacy' => ['sensitivity' => 'private'],
            ]);

            $this->writeLocalAlert($eventType, $snapshot, is_string($previous) ? $previous : null);

            return ['recorded' => true, 'event_type' => $eventType, 'previous_status' => $previous, 'status' => $status];
        }

        return ['recorded' => false, 'reason' => 'no_transition_event', 'previous_status' => $previous, 'status' => $status];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    private function sendAlertIfNeeded(array $snapshot): array
    {
        if (($snapshot['status'] ?? 'healthy') === 'healthy') {
            return ['sent' => false, 'reason' => 'healthy'];
        }

        $url = (string) config('atlas.mobile.alerts.webhook_url', '');
        if (trim($url) === '') {
            return ['sent' => false, 'reason' => 'webhook_not_configured'];
        }

        $cooldownMinutes = max(1, (int) config('atlas.mobile.alerts.cooldown_minutes', 30));
        $cooldownKey = self::ALERT_COOLDOWN_PREFIX.hash('sha256', implode('|', $snapshot['reasons'] ?? []));

        if (Cache::has($cooldownKey)) {
            return ['sent' => false, 'reason' => 'cooldown', 'cooldown_minutes' => $cooldownMinutes];
        }

        $payload = [
            'source' => 'atlas_mobile_reliability_monitor',
            'status' => $snapshot['status'],
            'generated_at' => $snapshot['generated_at'],
            'reasons' => $snapshot['reasons'],
            'checks' => $snapshot['checks'],
        ];

        try {
            $response = Http::timeout(max(1, (int) config('atlas.mobile.alerts.http_timeout_seconds', 5)))
                ->post($url, $payload);
        } catch (Throwable $throwable) {
            $this->audit->record('system.health.alert_failed', [
                'subject_type' => 'mobile_reliability_monitor',
                'subject_id' => null,
                'actor_type' => 'system',
                'actor_id' => null,
                'severity' => 'warning',
                'summary' => 'Falha ao enviar alert webhook do Core Reliability Monitor.',
                'evidence' => ['error' => $throwable->getMessage(), 'status' => $snapshot['status']],
                'privacy' => ['sensitivity' => 'private'],
            ]);

            return ['sent' => false, 'reason' => 'webhook_exception', 'error' => $throwable->getMessage()];
        }

        if (! $response->successful()) {
            $this->audit->record('system.health.alert_failed', [
                'subject_type' => 'mobile_reliability_monitor',
                'subject_id' => null,
                'actor_type' => 'system',
                'actor_id' => null,
                'severity' => 'warning',
                'summary' => 'Alert webhook do Core Reliability Monitor retornou erro.',
                'evidence' => ['http_status' => $response->status(), 'status' => $snapshot['status']],
                'privacy' => ['sensitivity' => 'private'],
            ]);

            return ['sent' => false, 'reason' => 'webhook_failed', 'http_status' => $response->status()];
        }

        Cache::put($cooldownKey, now()->toJSON(), now()->addMinutes($cooldownMinutes));

        return ['sent' => true, 'reason' => 'sent', 'http_status' => $response->status(), 'cooldown_minutes' => $cooldownMinutes];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    private function writeLocalAlert(string $eventType, array $snapshot, ?string $previousStatus): void
    {
        if (! (bool) config('atlas.mobile.alerts.local_log_enabled', true)) {
            return;
        }

        $path = (string) (config('atlas.mobile.alerts.local_log_path') ?: storage_path('logs/atlas-health-alerts.jsonl'));

        try {
            File::ensureDirectoryExists(dirname($path));
            File::append($path, json_encode([
                'event_type' => $eventType,
                'status' => $snapshot['status'] ?? 'unknown',
                'previous_status' => $previousStatus,
                'generated_at' => $snapshot['generated_at'] ?? now()->toJSON(),
                'reasons' => $snapshot['reasons'] ?? [],
                'checks' => $snapshot['checks'] ?? [],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
        } catch (Throwable $throwable) {
            $this->audit->record('system.health.local_log_failed', [
                'subject_type' => 'mobile_reliability_monitor',
                'subject_id' => null,
                'actor_type' => 'system',
                'actor_id' => null,
                'severity' => 'warning',
                'summary' => 'Falha ao escrever local alert log do Core Reliability Monitor.',
                'evidence' => ['path' => $path, 'error' => $throwable->getMessage()],
                'privacy' => ['sensitivity' => 'private'],
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function checkResult(string $name, string $status, string $reason, array $evidence): array
    {
        return [
            'name' => $name,
            'status' => $status,
            'reason' => $reason,
            'evidence' => $evidence,
        ];
    }
}
