<?php

namespace App\Services\Ai\AiWorkerSupport;

use App\Models\AiJob;
use Illuminate\Support\Str;

/**
 * Atlas Decide context-scout brief building/application family (pure brief
 * text + executor prompt injection) extracted VERBATIM from AiWorker
 * (GOD-DEBULK D3 split).
 *
 * Facade AiWorker keeps same-signature delegators; call-site/signature/ctor
 * scanner pins stay on the facade. No scanner pin token moved with this family.
 */
class AtlasScoutBriefSection
{
    /**
     * @param  array<string,mixed>  $extraMetadata
     */
    public function applyAtlasScoutBriefToExecutor(AiJob $executor, ?AiJob $scoutJob, string $brief, string $dependencyState, array $extraMetadata = []): AiJob
    {
        $metadata = array_merge($executor->metadata ?? [], [
            'dependency_state' => $dependencyState,
            'dependency_resolved_at' => now()->toJSON(),
            'dependency_job_id' => $scoutJob?->id ?: data_get($executor->metadata, 'dependency_job_id'),
            'dependency_provider' => $scoutJob?->provider ?: data_get($executor->metadata, 'dependency_provider'),
            'dependency_model' => $scoutJob?->model ?: data_get($executor->metadata, 'dependency_model'),
        ], $extraMetadata);
        $payload = is_array($executor->payload) ? $executor->payload : [];
        $payload['atlas_decide_execution'] = array_merge(
            is_array($payload['atlas_decide_execution'] ?? null) ? $payload['atlas_decide_execution'] : [],
            [
                'dependency_state' => $dependencyState,
                'dependency_resolved_at' => $metadata['dependency_resolved_at'],
                'dependency_job_id' => $metadata['dependency_job_id'],
            ],
        );

        $executor->forceFill([
            'prompt' => $this->promptWithAtlasScoutBrief($executor->prompt, $brief),
            'available_at' => now(),
            'reserved_at' => null,
            'started_at' => null,
            'worker_id' => null,
            'payload' => $payload,
            'metadata' => $metadata,
        ])->save();

        return $executor->refresh()->load('trace');
    }

    public function atlasScoutBrief(AiJob $scoutJob, string $output): string
    {
        return trim(<<<TEXT
Atlas Decide context scout concluido.
provider: {$scoutJob->provider}
model: {$scoutJob->model}
job_id: {$scoutJob->id}

{$output}
TEXT);
    }

    public function atlasScoutFailureBrief(?AiJob $scoutJob, ?string $errorCode, ?string $errorMessage): string
    {
        $provider = $scoutJob?->provider ?: 'unknown';
        $model = $scoutJob?->model ?: 'unknown';
        $jobId = $scoutJob?->id ?: 'unknown';
        $errorCode = $errorCode ?: 'scout_unavailable';
        $errorMessage = $errorMessage ?: 'Scout de contexto indisponivel; siga com o contexto original e marque incertezas.';

        return trim(<<<TEXT
Atlas Decide context scout degradado.
provider: {$provider}
model: {$model}
job_id: {$jobId}
error_code: {$errorCode}
error_message: {$errorMessage}

Siga com o contexto original. Se a tarefa depender de arquivos, logs ou decisões nao carregadas, explicite a lacuna antes de concluir.
TEXT);
    }

    public function promptWithAtlasScoutBrief(string $prompt, string $brief): string
    {
        $brief = Str::limit(trim($brief), 20000, '...');

        return rtrim($prompt)."\n\n# Atlas Decide Context Scout\n\n{$brief}\n";
    }
}
