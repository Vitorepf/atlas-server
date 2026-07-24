<?php

namespace App\Services\Ai\Mobile;

use App\Models\AiInboxItem;
use App\Models\AiJob;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;

class JobResultInboxEmitter
{
    public function __construct(
        private readonly ContextBundleService $bundles,
        private readonly AtlasInboxService $inbox,
    ) {}

    public function emitIfImportant(AiJob $job, ?string $finalStatus = null): ?AiInboxItem
    {
        if (DatabaseTableAvailability::missing(['ai_inbox_items', 'ai_context_bundles']) !== []) {
            return null;
        }

        $status = $finalStatus ?: $job->status;
        if (! in_array($status, ['succeeded', 'failed', 'cancelled'], true)) {
            return null;
        }

        if (! $this->shouldEmit($job, $status)) {
            return null;
        }

        if ($existing = $this->existingActiveItem($job)) {
            return $existing;
        }

        $importance = $this->importance($job);
        $summary = $this->summary($job, $status);
        $bundle = $this->bundles->create([
            'purpose' => 'job_result',
            'title' => $this->title($job, $status),
            'summary' => $summary,
            'body_for_thread' => $this->threadBody($job, $status, $summary, $importance),
            'trace_refs' => $job->trace_id ? [['id' => $job->trace_id, 'type' => 'ai_trace']] : [],
            'job_refs' => [[
                'id' => $job->id,
                'kind' => $job->kind,
                'status' => $status,
                'provider' => $job->provider,
                'model' => $job->model,
            ]],
            'raw_payload' => [
                'job_id' => $job->id,
                'trace_id' => $job->trace_id,
                'kind' => $job->kind,
                'status' => $status,
                'importance' => $importance,
                'provider' => $job->provider,
                'model' => $job->model,
                'agent_slug' => $job->agent_slug,
                'error_code' => $job->error_code,
                'error_message' => $job->error_message,
                'result_json' => $job->result_json,
                'metadata' => $job->metadata,
            ],
            'expires_at' => now()->addDays(30),
        ]);

        return $this->inbox->create([
            'type' => 'job_result',
            'category' => $job->kind,
            'severity' => $this->severity($status, $importance),
            'title' => $this->title($job, $status),
            'summary' => $summary,
            'body' => $this->body($job, $status, $importance),
            'source_type' => 'ai_job',
            'source_id' => $job->id,
            'initiator' => 'job',
            'context_bundle_id' => $bundle->id,
            'dedupe_key' => 'job_result:'.$job->id,
            'available_actions' => $this->availableActions($status, $importance),
            'payload' => [
                'job_id' => $job->id,
                'trace_id' => $job->trace_id,
                'kind' => $job->kind,
                'status' => $status,
                'importance' => $importance,
                'provider' => $job->provider,
                'model' => $job->model,
                'error_code' => $job->error_code,
                'duration_ms' => $this->durationMs($job),
            ],
            'push_policy' => [
                'send' => $status === 'failed' ? 'immediate' : 'auto',
                'reason' => 'important_job_result',
            ],
            'priority_score' => $status === 'failed' ? 85 : 65,
            'expires_at' => now()->addDays(30),
        ]);
    }

    private function existingActiveItem(AiJob $job): ?AiInboxItem
    {
        return AiInboxItem::query()
            ->where('dedupe_key', 'job_result:'.$job->id)
            ->whereNotIn('status', ['resolved', 'dismissed', 'expired'])
            ->latest('created_at')
            ->first();
    }

    private function shouldEmit(AiJob $job, string $status): bool
    {
        if ((bool) data_get($job->payload, 'suppress_mobile_notification', false)
            || (bool) data_get($job->metadata, 'suppress_mobile_notification', false)) {
            return false;
        }

        $importance = $this->importance($job);
        if (in_array($importance, ['high', 'critical'], true)) {
            return true;
        }

        if ((bool) data_get($job->payload, 'mobile_notify', false)
            || (bool) data_get($job->payload, 'notify_mobile', false)
            || (bool) data_get($job->metadata, 'mobile_notify', false)
            || (bool) data_get($job->metadata, 'notify_mobile', false)) {
            return true;
        }

        return $status === 'failed' && (
            (bool) data_get($job->payload, 'notify_on_failure', false)
            || (bool) data_get($job->metadata, 'notify_on_failure', false)
        );
    }

    private function importance(AiJob $job): string
    {
        $value = data_get($job->payload, 'mobile.importance')
            ?? data_get($job->payload, 'importance')
            ?? data_get($job->metadata, 'mobile.importance')
            ?? data_get($job->metadata, 'importance')
            ?? 'normal';

        return in_array($value, ['low', 'normal', 'high', 'critical'], true) ? $value : 'normal';
    }

    private function severity(string $status, string $importance): string
    {
        if ($status === 'failed') {
            return $importance === 'critical' ? 'critical' : 'warning';
        }

        return $importance === 'critical' ? 'warning' : 'info';
    }

    private function title(AiJob $job, string $status): string
    {
        $label = $job->kind ?: 'job';

        return $status === 'failed'
            ? "Job falhou: {$label}"
            : "Job finalizado: {$label}";
    }

    private function summary(AiJob $job, string $status): string
    {
        if ($status === 'failed') {
            return Str::limit($job->error_message ?: $job->error_code ?: 'Job falhou sem mensagem detalhada.', 220, '...');
        }

        return Str::limit($job->result_text ?: data_get($job->result_json, 'summary') ?: 'Job terminou com sucesso.', 220, '...');
    }

    private function body(AiJob $job, string $status, string $importance): string
    {
        $lines = [
            'Status: '.$status,
            'Importancia: '.$importance,
            'Kind: '.($job->kind ?: 'unknown'),
            'Provider: '.($job->provider ?: 'unknown'),
            'Model: '.($job->model ?: 'unknown'),
        ];

        if ($duration = $this->durationMs($job)) {
            $lines[] = 'Duracao: '.$duration.'ms';
        }

        if ($status === 'failed') {
            $lines[] = 'Erro: '.($job->error_message ?: $job->error_code ?: 'sem erro detalhado');
        } else {
            $lines[] = 'Resultado: '.$this->summary($job, $status);
        }

        return implode("\n", $lines);
    }

    private function threadBody(AiJob $job, string $status, string $summary, string $importance): string
    {
        return implode("\n\n", [
            'Atlas gerou este contexto porque um job marcado como importante terminou.',
            $this->body($job, $status, $importance),
            'Resumo para decisao: '.$summary,
            'Use esta thread para decidir se o resultado exige acao, rerun, investigacao ou apenas arquivamento.',
        ]);
    }

    private function durationMs(AiJob $job): ?int
    {
        if (! $job->started_at || ! $job->finished_at) {
            return null;
        }

        return max(0, (int) $job->started_at->diffInMilliseconds($job->finished_at));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function availableActions(string $status, string $importance): array
    {
        $actions = [
            ['id' => 'view_trace', 'label' => 'Ver trace', 'style' => 'primary'],
            ['id' => 'discuss', 'label' => 'Discutir com Atlas', 'style' => 'default'],
            ['id' => 'dismiss', 'label' => 'Descartar', 'style' => 'default'],
        ];

        if (in_array($importance, ['high', 'critical'], true) || $status === 'failed') {
            $actions[] = ['id' => 'approve_job_result', 'label' => 'Aprovar resultado', 'style' => 'success'];
            $actions[] = ['id' => 'reject_job_result', 'label' => 'Rejeitar resultado', 'style' => 'danger'];
        }

        if ($status === 'failed' && $importance !== 'low') {
            $actions[] = ['id' => 'rerun_job_result', 'label' => 'Solicitar rerun', 'style' => 'warning'];
        }

        return $actions;
    }
}
