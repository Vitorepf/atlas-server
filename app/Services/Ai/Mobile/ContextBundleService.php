<?php

namespace App\Services\Ai\Mobile;

use App\Models\AiContextBundle;
use App\Support\AtlasSecurity;
use Illuminate\Support\Str;

class ContextBundleService
{
    use MobileArrayHelper;

    /**
     * @param  array<string,mixed>  $data
     */
    public function create(array $data): AiContextBundle
    {
        $rawTitle = $this->string($data['title'] ?? null, 'Contexto do Inbox');
        $rawSummary = $this->string($data['summary'] ?? null, 'Contexto gerado pelo Atlas.');
        $rawBodyForThread = $this->string($data['body_for_thread'] ?? null, $rawSummary);
        $rawPayload = $this->array($data['raw_payload'] ?? []);

        $title = Str::limit(AtlasSecurity::redactString($rawTitle), 180, '');
        $summary = AtlasSecurity::redactString($rawSummary);
        $bodyForThread = AtlasSecurity::redactString($rawBodyForThread);
        $rawPayload = AtlasSecurity::redactArray($rawPayload);
        $redactionStatus = $title !== Str::limit($rawTitle, 180, '')
            || $summary !== $rawSummary
            || $bodyForThread !== $rawBodyForThread
            || $rawPayload !== $this->array($data['raw_payload'] ?? [])
                ? 'redacted'
                : 'clean';

        return AiContextBundle::query()->create([
            'user_id' => $this->string($data['user_id'] ?? null, 'vitor'),
            'purpose' => $this->string($data['purpose'] ?? null, 'inbox_context'),
            'title' => $title,
            'summary' => $summary,
            'body_for_thread' => $bodyForThread,
            'source_refs' => AtlasSecurity::redactArray($this->array($data['source_refs'] ?? [])),
            'trace_refs' => AtlasSecurity::redactArray($this->array($data['trace_refs'] ?? [])),
            'job_refs' => AtlasSecurity::redactArray($this->array($data['job_refs'] ?? [])),
            'metric_refs' => AtlasSecurity::redactArray($this->array($data['metric_refs'] ?? [])),
            'file_refs' => AtlasSecurity::redactArray($this->array($data['file_refs'] ?? [])),
            'diff_refs' => AtlasSecurity::redactArray($this->array($data['diff_refs'] ?? [])),
            'raw_payload' => $rawPayload,
            'redaction_status' => $redactionStatus,
            'token_estimate' => $this->tokenEstimate($bodyForThread),
            'expires_at' => $data['expires_at'] ?? null,
        ]);
    }

    /**
     * @return array<int|string,mixed>
     */
    private function string(mixed $value, string $default): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    private function tokenEstimate(string $text): int
    {
        return max(1, (int) ceil(mb_strlen($text) / 4));
    }
}
