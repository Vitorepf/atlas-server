<?php

namespace App\Services\Ai\Cognitive\Failure;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Compounding\AtlasCaptureQualityGate;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AP-819 F1 — auto-feed do cérebro de falhas.
 *
 * O corpus failure_signatures era estruturalmente vazio: o único alimentador era o
 * CLI manual (atlas:failure record). Este harvester colhe falhas REAIS de runtime —
 * ai_job_attempts status ∈ {failed,timeout} (inclui provider_exception, que o
 * AiWorker materializa como attempt failed) + ledger OPERATION_FAILED — e as projeta
 * no classificador existente, preservando o pipeline recurrence→alert (≥3).
 *
 * Observe-only por construção: escreve SOMENTE no corpus de falhas + alertas;
 * nenhuma proposta, nenhuma mutação downstream. Flag default-OFF.
 *
 * Idempotência: envelope_id determinístico por linha-fonte (job_attempt:<id> /
 * ledger_event:<event_id>) — a mesma linha nunca é colhida duas vezes; ocorrências
 * DISTINTAS do mesmo erro são gravadas de propósito (recurrence_count é o sinal).
 *
 * Ruído: AtlasCaptureQualityGate barra fixture_echo/meta_stub/contentless (o
 * precedente da semana de ~100% ruído). low_substance é ADMITIDO com anotação —
 * uma falha tersa ("exit 1") ainda é sinal legítimo de falha.
 */
class FailureAutoFeedHarvester
{
    public const SCHEMA_VERSION = 'atlas.cognitive.failure_auto_feed.v1';

    private const HARVEST_STATUSES = ['failed', 'timeout'];

    public function __construct(
        private readonly FailureSignatureRepository $signatures,
        private readonly FailureRepetitionAlerter $alerter,
        private readonly AtlasCaptureQualityGate $qualityGate,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('atlas.ai.failure_auto_feed.enabled', false);
    }

    /**
     * @return array<string,mixed>
     */
    public function harvest(?int $windowHours = null, ?int $limit = null, bool $force = false): array
    {
        if (! $force && ! $this->enabled()) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'disabled',
                'flag' => 'ATLAS_FAILURE_AUTO_FEED_ENABLED',
            ];
        }

        if (! $this->signatures->tableReady()) {
            return ['schema_version' => self::SCHEMA_VERSION, 'status' => 'table_missing'];
        }

        $windowHours = max(1, $windowHours ?? (int) config('atlas.ai.failure_auto_feed.window_hours', 24));
        $limit = max(1, min(1000, $limit ?? (int) config('atlas.ai.failure_auto_feed.max_per_run', 200)));

        $candidates = array_slice([
            ...$this->attemptCandidates($windowHours, $limit),
            ...$this->ledgerCandidates($windowHours, $limit),
        ], 0, $limit);

        $existing = $this->existingEnvelopeIds(array_column($candidates, 'envelope_id'));

        $report = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'window_hours' => $windowHours,
            'scanned' => count($candidates),
            'harvested' => 0,
            'skipped_existing' => 0,
            'skipped_noise' => [],
            'admitted_low_substance' => 0,
            'alerts_triggered' => 0,
            'signatures' => [],
        ];

        foreach ($candidates as $payload) {
            if (in_array($payload['envelope_id'], $existing, true)) {
                $report['skipped_existing']++;

                continue;
            }

            $verdict = $this->qualityGate->assess([
                'kind' => 'failure_signature',
                'claim' => (string) $payload['message'],
                'content' => [
                    'event_type' => $payload['event_type'],
                    'error_class' => $payload['error_class'] ?? null,
                    'provider' => $payload['provider'] ?? null,
                    'status_code' => $payload['status_code'] ?? null,
                ],
            ]);
            if (! $verdict['admit'] && $verdict['reason'] !== AtlasCaptureQualityGate::REASON_LOW_SUBSTANCE) {
                $reason = (string) $verdict['reason'];
                $report['skipped_noise'][$reason] = ($report['skipped_noise'][$reason] ?? 0) + 1;

                continue;
            }
            if (! $verdict['admit']) {
                $report['admitted_low_substance']++;
            }

            $signature = $this->signatures->record($payload);
            if (($signature['status'] ?? null) === 'table_missing') {
                continue;
            }

            $report['harvested']++;
            $alert = $this->alerter->evaluate($signature);
            if (($alert['status'] ?? null) === 'triggered') {
                $report['alerts_triggered']++;
            }

            $report['signatures'][] = [
                'id' => $signature['id'] ?? null,
                'signature_key' => $signature['signature_key'] ?? null,
                'domain' => $signature['domain'] ?? null,
                'sub_cause' => $signature['sub_cause'] ?? null,
                'provider' => data_get($signature, 'canonical_features.provider'),
                'recurrence_count' => $signature['recurrence_count'] ?? null,
            ];
        }

        return $report;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function attemptCandidates(int $windowHours, int $limit): array
    {
        if (! DatabaseTableAvailability::all(['ai_job_attempts'])) {
            return [];
        }

        return DB::table('ai_job_attempts')
            ->whereIn('status', self::HARVEST_STATUSES)
            ->where('created_at', '>=', now()->subHours($windowHours))
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => [
                'envelope_id' => 'job_attempt:'.$row->id,
                'event_type' => 'ai_job_attempt_'.$row->status,
                'domain' => (string) config('atlas.ai.failure_auto_feed.domain', 'engineering'),
                'message' => $this->attemptMessage($row),
                'error_class' => $row->error_code ?? null,
                'status_code' => $row->exit_code ?? null,
                'provider' => $row->provider ?? null,
                'model' => $row->model ?? null,
                'tool' => data_get($this->jsonArray($row->metadata ?? null), 'tool'),
                'status' => $row->status,
                'occurred_at' => $this->dateString($row->finished_at ?? $row->created_at ?? null),
            ])
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function ledgerCandidates(int $windowHours, int $limit): array
    {
        if (! DatabaseTableAvailability::all(['atlas_ledger_events'])) {
            return [];
        }

        return AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::OperationFailed->value)
            ->where('occurred_at', '>=', now()->subHours($windowHours))
            ->orderBy('occurred_at')
            ->limit($limit)
            ->get()
            ->map(function (AtlasLedgerEvent $event): array {
                $payload = (array) $event->payload;

                return [
                    'envelope_id' => 'ledger_event:'.$event->event_id,
                    'source_ledger_event_id' => $event->event_id,
                    'event_type' => 'operation_failed',
                    'domain' => (string) (data_get($payload, 'domain') ?? config('atlas.ai.failure_auto_feed.domain', 'engineering')),
                    'message' => (string) (data_get($payload, 'message')
                        ?? data_get($payload, 'reason')
                        ?? data_get($payload, 'error.message')
                        ?? 'operation failed'),
                    'error_class' => data_get($payload, 'error_class', data_get($payload, 'error_code')),
                    'status_code' => data_get($payload, 'status_code'),
                    'provider' => data_get($payload, 'provider'),
                    'tool' => data_get($payload, 'tool'),
                    'occurred_at' => $event->occurred_at?->toJSON(),
                ];
            })
            ->all();
    }

    /**
     * @param  list<string>  $envelopeIds
     * @return list<string>
     */
    private function existingEnvelopeIds(array $envelopeIds): array
    {
        if ($envelopeIds === []) {
            return [];
        }

        return DB::table('failure_signatures')
            ->whereIn('envelope_id', $envelopeIds)
            ->pluck('envelope_id')
            ->all();
    }

    private function attemptMessage(object $row): string
    {
        $message = trim((string) ($row->error_message ?? ''));
        if ($message === '') {
            $message = trim((string) ($row->stderr_excerpt ?? ''));
        }
        if ($message === '') {
            $message = sprintf('ai job attempt %s (provider %s, exit %s)',
                (string) $row->status,
                (string) ($row->provider ?? 'unknown'),
                $row->exit_code !== null ? (string) $row->exit_code : 'n/a',
            );
        }

        return Str::limit($message, 500, '');
    }

    private function dateString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return (string) $value;
    }

    /**
     * @return array<mixed>
     */
    private function jsonArray(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }
}
