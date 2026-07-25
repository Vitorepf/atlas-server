<?php

namespace App\Services\Ai\AiWorkerSupport;

use App\Models\AiJob;

/**
 * Atlas Decide context-scout brief building/application family (pure brief
 * text + executor prompt injection) extracted VERBATIM from AiWorker
 * (GOD-DEBULK D3 split).
 *
 * Pure string builders live in {@see AiWorkerAtlasScoutBriefSupport}.
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
        return AiWorkerAtlasScoutBriefSupport::successBrief(
            $scoutJob->provider,
            $scoutJob->model,
            $scoutJob->id,
            $output,
        );
    }

    public function atlasScoutFailureBrief(?AiJob $scoutJob, ?string $errorCode, ?string $errorMessage): string
    {
        return AiWorkerAtlasScoutBriefSupport::failureBrief(
            $scoutJob?->provider,
            $scoutJob?->model,
            $scoutJob?->id,
            $errorCode,
            $errorMessage,
        );
    }

    public function promptWithAtlasScoutBrief(string $prompt, string $brief): string
    {
        return AiWorkerAtlasScoutBriefSupport::promptWithBrief($prompt, $brief);
    }
}
