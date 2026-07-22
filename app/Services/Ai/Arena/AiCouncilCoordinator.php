<?php

namespace App\Services\Ai\Arena;

use App\Models\AiJob;
use App\Models\AiTrace;
use Illuminate\Support\Facades\DB;

class AiCouncilCoordinator
{
    public function sync(AiTrace $trace): AiTrace
    {
        return DB::transaction(function () use ($trace): AiTrace {
            /** @var AiTrace $locked */
            $locked = AiTrace::query()
                ->whereKey($trace->id)
                ->lockForUpdate()
                ->firstOrFail();

            $jobs = $locked->jobs()
                ->orderByRaw("CASE provider WHEN 'claude_cli' THEN 0 WHEN 'codex_cli' THEN 1 ELSE 2 END")
                ->orderBy('created_at')
                ->get();

            $counts = [
                'queued' => $jobs->where('status', 'queued')->count(),
                'processing' => $jobs->where('status', 'processing')->count(),
                'succeeded' => $jobs->where('status', 'succeeded')->count(),
                'failed' => $jobs->where('status', 'failed')->count(),
                'cancelled' => $jobs->where('status', 'cancelled')->count(),
            ];
            $metadata = array_merge($locked->metadata ?? [], [
                'execution_policy' => 'dual_review',
                'council_status' => $this->statusForCounts($counts),
                'council_progress' => $counts,
                // C21: a leitura de CADA membro fica registrada com campos
                // verificáveis (provider, model, status, hash, latência) —
                // a casca mostra posição por papel sem raciocínio privado e
                // sem fabricar "veredito" que o sistema não produz.
                'council_review' => self::councilReview($jobs->all()),
            ]);

            if (($counts['queued'] + $counts['processing']) > 0) {
                $locked->update([
                    'status' => 'processing',
                    'metadata' => $metadata,
                ]);

                return $locked->refresh()->load(['job', 'jobs']);
            }

            $succeeded = $jobs->where('status', 'succeeded');
            if ($counts['cancelled'] > 0 && $succeeded->isEmpty() && $counts['failed'] === 0) {
                $locked->update([
                    'status' => 'cancelled',
                    'response_text' => $this->cancelledResponse($jobs->all()),
                    'completed_at' => now(),
                    'metadata' => $metadata,
                ]);

                return $locked->refresh()->load(['job', 'jobs']);
            }

            if ($succeeded->isEmpty()) {
                $locked->update([
                    'status' => 'failed',
                    'response_text' => $this->failedResponse($jobs->all()),
                    'completed_at' => now(),
                    'metadata' => $metadata,
                ]);

                return $locked->refresh()->load(['job', 'jobs']);
            }

            $response = $this->combinedResponse($jobs->all());

            $locked->update([
                'status' => 'succeeded',
                'provider' => 'claude_codex',
                'response_hash' => hash('sha256', $response),
                'response_text' => $response,
                'latency_ms' => $this->maxLatency($jobs->all()),
                'completed_at' => now(),
                'metadata' => $metadata,
            ]);

            return $locked->refresh()->load(['job', 'jobs']);
        });
    }

    /**
     * @param  array{queued:int,processing:int,succeeded:int,failed:int,cancelled:int}  $counts
     */
    private function statusForCounts(array $counts): string
    {
        if (($counts['queued'] + $counts['processing']) > 0) {
            return 'processing';
        }

        if ($counts['succeeded'] > 0) {
            return 'succeeded';
        }

        return $counts['cancelled'] > 0 && $counts['failed'] === 0 ? 'cancelled' : 'failed';
    }

    /**
     * @param  array<int, AiJob>  $jobs
     */
    /**
     * C21 — registro público por membro do conselho. Função PURA sobre os
     * jobs: somente campos reais e provider-safe; divergência aparece como
     * status distinto entre membros, nunca como um "voto" inventado.
     *
     * @param  array<int, AiJob>  $jobs
     * @return array<int, array<string, mixed>>
     */
    public static function councilReview(array $jobs): array
    {
        return array_values(array_map(static function (AiJob $job): array {
            return array_filter([
                'provider' => $job->provider,
                'model' => $job->model,
                'status' => $job->status,
                'response_hash' => $job->result_text !== null && $job->result_text !== ''
                    ? hash('sha256', (string) $job->result_text)
                    : null,
                'error_code' => $job->error_code,
                'latency_ms' => $job->started_at !== null && $job->finished_at !== null
                    ? (int) $job->started_at->diffInMilliseconds($job->finished_at)
                    : null,
            ], static fn ($v) => $v !== null);
        }, $jobs));
    }

    private function combinedResponse(array $jobs): string
    {
        $sections = collect($jobs)->map(function (AiJob $job): string {
            $title = $this->providerTitle($job->provider);
            if ($job->status === 'succeeded') {
                $text = trim((string) $job->result_text);

                return "## {$title}\n\n{$text}";
            }

            if ($job->status === 'cancelled') {
                return "## {$title}\n\nCancelado pelo operador.";
            }

            $error = trim((string) ($job->error_message ?: $job->error_code ?: 'Falha sem detalhe.'));

            return "## {$title}\n\nFalhou: {$error}";
        })->implode("\n\n");

        return <<<TXT
# Conselho Atlas — Claude + Codex

Dois provedores analisaram o mesmo pedido. O Atlas preserva as duas leituras para auditoria; para executar, escolha um executor unico depois da revisao.

{$sections}

## Leitura operacional

- Use esta resposta como deliberacao, nao como execucao automatica.
- Quando houver divergencia, trate como sinal para uma revisao humana ou uma nova rodada mais especifica.
- Se a tarefa envolver codigo, execute com um unico agente e use o outro para revisar.
TXT;
    }

    /**
     * @param  array<int, AiJob>  $jobs
     */
    private function failedResponse(array $jobs): string
    {
        $sections = collect($jobs)->map(function (AiJob $job): string {
            $title = $this->providerTitle($job->provider);
            $error = trim((string) ($job->error_message ?: $job->error_code ?: 'Falha sem detalhe.'));

            return "- {$title}: {$error}";
        })->implode("\n");

        return <<<TXT
# Conselho Atlas falhou

Nenhum provedor concluiu esta rodada.

{$sections}
TXT;
    }

    /**
     * @param  array<int, AiJob>  $jobs
     */
    private function cancelledResponse(array $jobs): string
    {
        $sections = collect($jobs)->map(function (AiJob $job): string {
            $title = $this->providerTitle($job->provider);

            return "- {$title}: cancelado";
        })->implode("\n");

        return <<<TXT
# Conselho Atlas cancelado

Nenhum provedor concluiu esta rodada antes do cancelamento.

{$sections}
TXT;
    }

    /**
     * @param  array<int, AiJob>  $jobs
     */
    private function maxLatency(array $jobs): ?int
    {
        $finishedJobs = collect($jobs)
            ->filter(fn (AiJob $job): bool => $job->started_at !== null && $job->finished_at !== null)
            ->map(fn (AiJob $job): int => (int) $job->started_at->diffInMilliseconds($job->finished_at));

        return $finishedJobs->isEmpty() ? null : (int) $finishedJobs->max();
    }

    private function providerTitle(?string $provider): string
    {
        return match ($provider) {
            'claude_cli' => 'Claude',
            'codex_cli' => 'Codex',
            default => $provider ?: 'Provider',
        };
    }
}
