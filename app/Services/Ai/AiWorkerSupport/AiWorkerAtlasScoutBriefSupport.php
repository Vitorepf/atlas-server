<?php

declare(strict_types=1);

namespace App\Services\Ai\AiWorkerSupport;

use Illuminate\Support\Str;

/**
 * Pure Atlas Decide context-scout brief string builders (full-pass peel from AtlasScoutBriefSection).
 * No DI, no models — callers pass already-extracted scalars.
 */
final class AiWorkerAtlasScoutBriefSupport
{
    public static function successBrief(mixed $provider, mixed $model, mixed $jobId, string $output): string
    {
        $provider = (string) $provider;
        $model = (string) $model;
        $jobId = (string) $jobId;

        return trim(<<<TEXT
Atlas Decide context scout concluido.
provider: {$provider}
model: {$model}
job_id: {$jobId}

{$output}
TEXT);
    }

    public static function failureBrief(
        mixed $provider,
        mixed $model,
        mixed $jobId,
        ?string $errorCode,
        ?string $errorMessage,
    ): string {
        $provider = (string) ($provider ?: 'unknown');
        $model = (string) ($model ?: 'unknown');
        $jobId = (string) ($jobId ?: 'unknown');
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

    public static function promptWithBrief(string $prompt, string $brief): string
    {
        $brief = Str::limit(trim($brief), 20000, '...');

        return rtrim($prompt)."\n\n# Atlas Decide Context Scout\n\n{$brief}\n";
    }
}
