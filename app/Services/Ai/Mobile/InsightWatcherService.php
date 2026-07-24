<?php

namespace App\Services\Ai\Mobile;

use App\Models\AiProviderHealthSnapshot;
use App\Models\AtlasInitiativeRun;
use App\Models\DigitalActivitySnapshot;
use App\Models\HealthSnapshot;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Collection;

class InsightWatcherService
{
    public function __construct(private readonly InsightInboxEmitter $insights) {}

    /**
     * @return array{ok:bool,run_id:?string,dry_run:bool,candidates:array<int,array<string,mixed>>,emitted_item_ids:array<int,string>,emitted_count:int}
     */
    public function run(bool $dryRun = false): array
    {
        $run = $this->startRun($dryRun);

        try {
            $candidates = collect([
                ...$this->healthCandidates(),
                ...$this->digitalCandidates(),
                ...$this->providerHealthCandidates(),
            ])
                ->filter(fn (array $candidate): bool => (float) ($candidate['confidence'] ?? 0) >= $this->minConfidence())
                ->unique('dedupe_key')
                ->sortByDesc(fn (array $candidate): float => (float) ($candidate['confidence'] ?? 0))
                ->values()
                ->all();

            $emitted = [];
            if (! $dryRun) {
                foreach ($candidates as $candidate) {
                    $item = $this->insights->emit([
                        ...$candidate,
                        'source_type' => 'atlas_initiative_run',
                        'source_id' => $run?->id,
                    ]);
                    if ($item) {
                        $emitted[] = $item->id;
                    }
                }
            }

            $this->finishRun($run, 'succeeded', $candidates, $emitted);

            return [
                'ok' => true,
                'run_id' => $run?->id,
                'dry_run' => $dryRun,
                'candidates' => $candidates,
                'emitted_item_ids' => $emitted,
                'emitted_count' => count($emitted),
            ];
        } catch (\Throwable $throwable) {
            $this->finishRun($run, 'failed', [], [], $throwable->getMessage());

            throw $throwable;
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function healthCandidates(): array
    {
        if (! DatabaseTableAvailability::has('health_snapshots')) {
            return [];
        }

        $latest = HealthSnapshot::query()
            ->whereNull('deleted_at')
            ->whereNotNull('readiness_score')
            ->orderByDesc('snapshot_date')
            ->orderByDesc('computed_at')
            ->first();

        if (! $latest || ! $latest->snapshot_date) {
            return [];
        }

        $date = $latest->snapshot_date->toDateString();
        $baselineDays = max(3, (int) config('atlas.mobile.insight_watch.health_baseline_days', 14));
        $baseline = HealthSnapshot::query()
            ->whereNull('deleted_at')
            ->whereDate('snapshot_date', '<', $date)
            ->whereDate('snapshot_date', '>=', $latest->snapshot_date->subDays($baselineDays)->toDateString())
            ->get(['readiness_score', 'sleep_duration_hours', 'snapshot_date']);

        $readinessValues = $baseline
            ->pluck('readiness_score')
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): float => (float) $value)
            ->values();

        if ($readinessValues->count() < max(2, (int) config('atlas.mobile.insight_watch.health_min_baseline_samples', 3))) {
            return [];
        }

        $latestReadiness = (float) $latest->readiness_score;
        $baselineReadiness = round((float) $readinessValues->avg(), 1);
        $drop = round($baselineReadiness - $latestReadiness, 1);
        $dropThreshold = (float) config('atlas.mobile.insight_watch.health_readiness_drop_threshold', 15);
        if ($drop < $dropThreshold) {
            return [];
        }

        $sleepValues = $baseline
            ->pluck('sleep_duration_hours')
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): float => (float) $value)
            ->values();
        $baselineSleep = $sleepValues->isNotEmpty() ? round((float) $sleepValues->avg(), 2) : null;
        $latestSleep = is_numeric($latest->sleep_duration_hours) ? round((float) $latest->sleep_duration_hours, 2) : null;
        $confidence = min(0.94, 0.64 + ($drop / 100));
        $severity = $drop >= $dropThreshold + 10 ? 'warning' : 'info';

        return [[
            'title' => 'Readiness caiu abaixo do padrao recente',
            'summary' => "Readiness em {$date}: {$latestReadiness} vs media recente {$baselineReadiness}.",
            'body' => implode("\n\n", array_filter([
                "O Atlas detectou uma queda de {$drop} pontos no readiness em relacao ao padrao dos ultimos {$baselineDays} dias.",
                $latestSleep !== null
                    ? "Sono registrado: {$latestSleep}h".($baselineSleep !== null ? " vs media recente {$baselineSleep}h." : '.')
                    : null,
                'Isso nao e diagnostico medico. E um sinal operacional para conversar sobre carga, sono, treino, foco e agenda antes de decidir qualquer ajuste.',
            ])),
            'body_for_thread' => $this->threadBody('saude', [
                'latest_snapshot_id' => $latest->id,
                'latest_date' => $date,
                'latest_readiness' => $latestReadiness,
                'baseline_readiness' => $baselineReadiness,
                'readiness_drop' => $drop,
                'latest_sleep_hours' => $latestSleep,
                'baseline_sleep_hours' => $baselineSleep,
                'baseline_days' => $baselineDays,
            ]),
            'category' => 'saude',
            'insight_kind' => 'health_readiness_drop',
            'severity' => $severity,
            'dedupe_key' => "insight:health:readiness-drop:{$date}",
            'metric_refs' => [
                ['name' => 'latest_readiness_score', 'value' => $latestReadiness, 'date' => $date],
                ['name' => 'baseline_readiness_score', 'value' => $baselineReadiness, 'window_days' => $baselineDays],
                ['name' => 'readiness_drop', 'value' => $drop],
            ],
            'source_refs' => [['type' => 'health_snapshot', 'id' => $latest->id, 'date' => $date]],
            'raw_payload' => ['latest_snapshot' => $latest->only(['id', 'source', 'snapshot_date', 'readiness_score', 'sleep_duration_hours', 'confidence'])],
            'confidence' => $confidence,
        ]];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function digitalCandidates(): array
    {
        if (! DatabaseTableAvailability::has('digital_activity_snapshots')) {
            return [];
        }

        $latest = DigitalActivitySnapshot::query()
            ->whereNull('deleted_at')
            ->whereNotNull('algorithmic_input_min')
            ->orderByDesc('snapshot_date')
            ->orderByDesc('computed_at')
            ->first();

        if (! $latest || ! $latest->snapshot_date) {
            return [];
        }

        $date = $latest->snapshot_date->toDateString();
        $baselineDays = max(3, (int) config('atlas.mobile.insight_watch.digital_baseline_days', 14));
        $baseline = DigitalActivitySnapshot::query()
            ->whereNull('deleted_at')
            ->whereDate('snapshot_date', '<', $date)
            ->whereDate('snapshot_date', '>=', $latest->snapshot_date->subDays($baselineDays)->toDateString())
            ->get(['algorithmic_input_min', 'deep_work_total_min', 'snapshot_date']);

        $algorithmicValues = $this->numericValues($baseline, 'algorithmic_input_min');
        if ($algorithmicValues->count() < max(2, (int) config('atlas.mobile.insight_watch.digital_min_baseline_samples', 3))) {
            return [];
        }

        $latestAlgorithmic = (float) $latest->algorithmic_input_min;
        $baselineAlgorithmic = round((float) $algorithmicValues->avg(), 1);
        $spike = round($latestAlgorithmic - $baselineAlgorithmic, 1);
        $spikeThreshold = (float) config('atlas.mobile.insight_watch.digital_algorithmic_spike_minutes', 45);
        if ($spike < $spikeThreshold) {
            return [];
        }

        $deepWorkValues = $this->numericValues($baseline, 'deep_work_total_min');
        $baselineDeepWork = $deepWorkValues->isNotEmpty() ? round((float) $deepWorkValues->avg(), 1) : null;
        $latestDeepWork = AiValueNormalizer::finiteFloatOrNull($latest->deep_work_total_min);

        return [[
            'title' => 'Uso algoritmico subiu acima do padrao',
            'summary' => "Uso algoritmico em {$date}: {$latestAlgorithmic}min vs media {$baselineAlgorithmic}min.",
            'body' => implode("\n\n", array_filter([
                "O Atlas detectou aumento de {$spike}min de input algoritmico contra o padrao recente.",
                $latestDeepWork !== null
                    ? "Deep work registrado: {$latestDeepWork}min".($baselineDeepWork !== null ? " vs media {$baselineDeepWork}min." : '.')
                    : null,
                'O ponto nao e julgar o uso, e decidir se isso afetou foco, energia ou agenda e se vale criar alguma regra ou ajuste.',
            ])),
            'body_for_thread' => $this->threadBody('foco digital', [
                'latest_snapshot_id' => $latest->id,
                'latest_date' => $date,
                'latest_algorithmic_input_min' => $latestAlgorithmic,
                'baseline_algorithmic_input_min' => $baselineAlgorithmic,
                'algorithmic_spike_min' => $spike,
                'latest_deep_work_total_min' => $latestDeepWork,
                'baseline_deep_work_total_min' => $baselineDeepWork,
                'baseline_days' => $baselineDays,
            ]),
            'category' => 'foco',
            'insight_kind' => 'digital_algorithmic_spike',
            'severity' => 'info',
            'dedupe_key' => "insight:digital:algorithmic-spike:{$date}",
            'metric_refs' => [
                ['name' => 'latest_algorithmic_input_min', 'value' => $latestAlgorithmic, 'date' => $date],
                ['name' => 'baseline_algorithmic_input_min', 'value' => $baselineAlgorithmic, 'window_days' => $baselineDays],
                ['name' => 'algorithmic_spike_min', 'value' => $spike],
            ],
            'source_refs' => [['type' => 'digital_activity_snapshot', 'id' => $latest->id, 'date' => $date]],
            'confidence' => min(0.9, 0.62 + ($spike / 240)),
        ]];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function providerHealthCandidates(): array
    {
        if (! DatabaseTableAvailability::has('ai_provider_health_snapshots')) {
            return [];
        }

        $painThreshold = (int) config('atlas.mobile.insight_watch.provider_pain_threshold', 70);

        return AiProviderHealthSnapshot::query()
            ->where('checked_at', '>=', now()->subDay())
            ->orderByDesc('checked_at')
            ->get()
            ->unique('provider')
            ->filter(function (AiProviderHealthSnapshot $snapshot) use ($painThreshold): bool {
                $status = strtolower((string) $snapshot->status);

                return ($snapshot->operational_pain_score ?? 0) >= $painThreshold
                    || ! in_array($status, ['ok', 'healthy', 'available', 'online', 'up', 'passed'], true);
            })
            ->map(function (AiProviderHealthSnapshot $snapshot) use ($painThreshold): array {
                $checkedAt = $snapshot->checked_at?->toDateString() ?? now()->toDateString();
                $pain = (int) ($snapshot->operational_pain_score ?? 0);
                $provider = (string) $snapshot->provider;

                return [
                    'title' => "Provider {$provider} com saude operacional ruim",
                    'summary' => "Status {$snapshot->status}; pain score {$pain}/100.",
                    'body' => "O Atlas detectou degradacao no provider {$provider}. Isso pode afetar latencia, falhas de jobs ou qualidade percebida. Vale discutir fallback, troca temporaria de provider ou investigacao de credenciais/rede.",
                    'body_for_thread' => $this->threadBody('provider health', [
                        'provider' => $provider,
                        'status' => $snapshot->status,
                        'checked_at' => $snapshot->checked_at?->toJSON(),
                        'operational_pain_score' => $pain,
                        'threshold' => $painThreshold,
                        'message' => $snapshot->message,
                        'metadata' => $snapshot->metadata ?? [],
                    ]),
                    'category' => 'atlas',
                    'insight_kind' => 'provider_health_degraded',
                    'severity' => 'warning',
                    'dedupe_key' => "insight:provider-health:{$provider}:{$checkedAt}",
                    'metric_refs' => [
                        ['name' => 'operational_pain_score', 'value' => $pain, 'provider' => $provider],
                        ['name' => 'provider_status', 'value' => $snapshot->status, 'provider' => $provider],
                    ],
                    'source_refs' => [['type' => 'ai_provider_health_snapshot', 'id' => $snapshot->id, 'provider' => $provider]],
                    'confidence' => $pain >= $painThreshold ? 0.82 : 0.7,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,object>  $items
     * @return Collection<int,float>
     */
    private function numericValues(Collection $items, string $field): Collection
    {
        return $items
            ->pluck($field)
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): float => (float) $value)
            ->values();
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function threadBody(string $domain, array $payload): string
    {
        return implode("\n\n", [
            "Atlas criou este insight de {$domain} a partir de watcher automatico.",
            'Dados observados: '.json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'Objetivo da conversa: entender se o sinal importa, decidir acao e transformar em tarefa/proposta se fizer sentido.',
        ]);
    }

    private function minConfidence(): float
    {
        return (float) config('atlas.mobile.insight_watch.min_confidence', 0.65);
    }

    private function startRun(bool $dryRun): ?AtlasInitiativeRun
    {
        if (! DatabaseTableAvailability::has('atlas_initiative_runs')) {
            return null;
        }

        return AtlasInitiativeRun::query()->create([
            'kind' => 'insight_watch',
            'status' => 'running',
            'started_at' => now(),
            'scope' => [
                'dry_run' => $dryRun,
                'watchers' => ['health_readiness', 'digital_activity', 'provider_health'],
            ],
            'findings' => [],
            'emitted_inbox_item_ids' => [],
            'metadata' => ['watcher' => 'insight_watcher_v1'],
        ]);
    }

    /**
     * @param  array<int,array<string,mixed>>  $candidates
     * @param  array<int,string>  $emitted
     */
    private function finishRun(?AtlasInitiativeRun $run, string $status, array $candidates, array $emitted, ?string $error = null): void
    {
        if (! $run) {
            return;
        }

        $run->update([
            'status' => $status,
            'finished_at' => now(),
            'findings' => $candidates,
            'emitted_inbox_item_ids' => $emitted,
            'error_message' => $error,
        ]);
    }
}
