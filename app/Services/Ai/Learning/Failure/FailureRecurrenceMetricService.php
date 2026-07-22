<?php

namespace App\Services\Ai\Cognitive\Failure;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AP-819 F3 — métrica de recorrência honesta.
 *
 * G3 (anti symptom-gaming): o delta de recorrência é computado sobre as linhas
 * CRUAS de ai_job_attempts.status — um caminho que nenhum candidato de
 * harness-config pode editar. NÃO lê failure_signatures (que um pipeline
 * downstream poderia, em tese, deixar de alimentar): re-classifica cada attempt
 * cru deterministicamente e agrupa por signature_key.
 *
 * Janelas: current = [now-days, now]; previous = [now-2*days, now-days).
 * Em Obra A isto é painel/aprendizado; em Obra B compõe com a suite congelada.
 */
class FailureRecurrenceMetricService
{
    public const SCHEMA_VERSION = 'atlas.cognitive.failure_recurrence_metric.v1';

    private const FAILURE_STATUSES = ['failed', 'timeout'];

    private const MAX_ROWS = 4000;

    public function __construct(
        private readonly FailureSignatureClassifier $classifier,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function compute(int $days = 14, ?string $provider = null): array
    {
        if (! DatabaseTableAvailability::all(['ai_job_attempts'])) {
            return ['schema_version' => self::SCHEMA_VERSION, 'status' => 'table_missing'];
        }

        $days = max(1, $days);
        $now = now();
        $currentStart = $now->copy()->subDays($days);
        $previousStart = $now->copy()->subDays($days * 2);

        $rows = DB::table('ai_job_attempts')
            ->whereIn('status', self::FAILURE_STATUSES)
            ->where('created_at', '>=', $previousStart)
            ->when($provider !== null && $provider !== '', fn ($q) => $q->where('provider', $provider))
            ->orderBy('created_at')
            ->limit(self::MAX_ROWS)
            ->get();

        $clusters = [];
        foreach ($rows as $row) {
            $signature = $this->classifier->classify([
                'envelope_id' => 'recurrence_probe:'.$row->id,
                'event_type' => 'ai_job_attempt_'.$row->status,
                'domain' => (string) config('atlas.ai.failure_auto_feed.domain', 'engineering'),
                'message' => trim((string) ($row->error_message ?? '')) !== ''
                    ? (string) $row->error_message
                    : sprintf('ai job attempt %s (provider %s)', (string) $row->status, (string) ($row->provider ?? 'unknown')),
                'error_class' => $row->error_code ?? null,
                'status_code' => $row->exit_code ?? null,
                'provider' => $row->provider ?? null,
                'model' => $row->model ?? null,
            ]);
            $key = (string) $signature['signature_key'];
            $window = ((string) $row->created_at) >= $currentStart->toDateTimeString() ? 'current' : 'previous';

            if (! isset($clusters[$key])) {
                $clusters[$key] = [
                    'signature_key' => $key,
                    'sub_cause' => $signature['sub_cause'],
                    'category' => $signature['category'],
                    'context_sample' => Str::limit((string) $signature['context_summary'], 160, '…'),
                    'providers' => [],
                    'current_count' => 0,
                    'previous_count' => 0,
                ];
            }
            $clusters[$key][$window.'_count']++;
            $rowProvider = (string) ($row->provider ?? 'unknown');
            if (! in_array($rowProvider, $clusters[$key]['providers'], true)) {
                $clusters[$key]['providers'][] = $rowProvider;
            }
        }

        foreach ($clusters as $key => $cluster) {
            $delta = $cluster['current_count'] - $cluster['previous_count'];
            $clusters[$key]['delta'] = $delta;
            $clusters[$key]['direction'] = match (true) {
                $delta > 0 => 'worsening',
                $delta < 0 => 'improving',
                default => 'flat',
            };
        }

        $clusters = array_values($clusters);
        usort($clusters, fn (array $a, array $b): int => $b['current_count'] <=> $a['current_count']);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'computed',
            'days_per_window' => $days,
            'provider_filter' => $provider,
            'windows' => [
                'current' => ['from' => $currentStart->toJSON(), 'to' => $now->toJSON()],
                'previous' => ['from' => $previousStart->toJSON(), 'to' => $currentStart->toJSON()],
            ],
            'totals' => $this->totals($currentStart, $previousStart, $provider),
            'cluster_count' => count($clusters),
            'clusters' => array_slice($clusters, 0, 50),
            'measurement_basis' => 'raw ai_job_attempts.status (G3: não-editável por candidato de harness)',
        ];
    }

    /**
     * Conta ocorrências CRUAS de um cluster em [from, to) — re-classifica
     * deterministicamente cada attempt do range e compara signature_key.
     * Base do auto-reverse do autopilot (G3: caminho ineditável por candidato).
     */
    public function clusterCountBetween(string $signatureKey, \Carbon\CarbonInterface $from, \Carbon\CarbonInterface $to): int
    {
        if (! DatabaseTableAvailability::all(['ai_job_attempts'])) {
            return 0;
        }

        $rows = DB::table('ai_job_attempts')
            ->whereIn('status', self::FAILURE_STATUSES)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->orderBy('created_at')
            ->limit(self::MAX_ROWS)
            ->get();

        $count = 0;
        foreach ($rows as $row) {
            $signature = $this->classifier->classify([
                'envelope_id' => 'autopilot_probe:'.$row->id,
                'event_type' => 'ai_job_attempt_'.$row->status,
                'domain' => (string) config('atlas.ai.failure_auto_feed.domain', 'engineering'),
                'message' => trim((string) ($row->error_message ?? '')) !== ''
                    ? (string) $row->error_message
                    : sprintf('ai job attempt %s (provider %s)', (string) $row->status, (string) ($row->provider ?? 'unknown')),
                'error_class' => $row->error_code ?? null,
                'status_code' => $row->exit_code ?? null,
                'provider' => $row->provider ?? null,
                'model' => $row->model ?? null,
            ]);
            if ((string) $signature['signature_key'] === $signatureKey) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Total finished (non-processing) ai_job_attempts in [from, to). Used by the
     * autopilot G3 gate to distinguish "the cluster genuinely stopped recurring"
     * (real post-window evidence, count dropped) from "the table is simply empty
     * in this window" (no evidence at all — which must NOT count as improvement).
     */
    public function totalAttemptsBetween(\Carbon\CarbonInterface $from, \Carbon\CarbonInterface $to): int
    {
        if (! DatabaseTableAvailability::all(['ai_job_attempts'])) {
            return 0;
        }

        return DB::table('ai_job_attempts')
            ->whereNotIn('status', ['processing'])
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->count();
    }

    /**
     * @return array<string,mixed>
     */
    private function totals(\Carbon\CarbonInterface $currentStart, \Carbon\CarbonInterface $previousStart, ?string $provider): array
    {
        $base = fn () => DB::table('ai_job_attempts')
            ->when($provider !== null && $provider !== '', fn ($q) => $q->where('provider', $provider));

        $currentFailed = (clone $base())->whereIn('status', self::FAILURE_STATUSES)
            ->where('created_at', '>=', $currentStart)->count();
        $currentAll = (clone $base())->whereNotIn('status', ['processing'])
            ->where('created_at', '>=', $currentStart)->count();
        $previousFailed = (clone $base())->whereIn('status', self::FAILURE_STATUSES)
            ->where('created_at', '>=', $previousStart)->where('created_at', '<', $currentStart)->count();
        $previousAll = (clone $base())->whereNotIn('status', ['processing'])
            ->where('created_at', '>=', $previousStart)->where('created_at', '<', $currentStart)->count();

        return [
            'current' => [
                'failed' => $currentFailed,
                'finished_attempts' => $currentAll,
                'failure_rate' => $currentAll > 0 ? round($currentFailed / $currentAll, 4) : null,
            ],
            'previous' => [
                'failed' => $previousFailed,
                'finished_attempts' => $previousAll,
                'failure_rate' => $previousAll > 0 ? round($previousFailed / $previousAll, 4) : null,
            ],
            'failed_delta' => $currentFailed - $previousFailed,
        ];
    }
}
