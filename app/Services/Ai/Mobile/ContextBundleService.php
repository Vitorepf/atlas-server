<?php

namespace App\Services\Ai\Mobile;

use App\Models\AiContextBundle;
use Illuminate\Support\Str;

class ContextBundleService
{
    /**
     * @param  array<string,mixed>  $data
     */
    public function create(array $data): AiContextBundle
    {
        $summary = $this->string($data['summary'] ?? null, 'Contexto gerado pelo Atlas.');
        $bodyForThread = $this->string($data['body_for_thread'] ?? null, $summary);

        return AiContextBundle::query()->create([
            'user_id' => $this->string($data['user_id'] ?? null, 'vitor'),
            'purpose' => $this->string($data['purpose'] ?? null, 'inbox_context'),
            'title' => Str::limit($this->string($data['title'] ?? null, 'Contexto do Inbox'), 180, ''),
            'summary' => $summary,
            'body_for_thread' => $bodyForThread,
            'source_refs' => $this->array($data['source_refs'] ?? []),
            'trace_refs' => $this->array($data['trace_refs'] ?? []),
            'job_refs' => $this->array($data['job_refs'] ?? []),
            'metric_refs' => $this->array($data['metric_refs'] ?? []),
            'file_refs' => $this->array($data['file_refs'] ?? []),
            'diff_refs' => $this->array($data['diff_refs'] ?? []),
            'raw_payload' => $this->redact($this->array($data['raw_payload'] ?? [])),
            'redaction_status' => 'clean',
            'token_estimate' => $this->tokenEstimate($bodyForThread),
            'expires_at' => $data['expires_at'] ?? null,
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function redact(array $payload): array
    {
        $redacted = [];
        foreach ($payload as $key => $value) {
            $keyString = is_string($key) ? strtolower($key) : '';
            if (str_contains($keyString, 'token') || str_contains($keyString, 'secret') || str_contains($keyString, 'password')) {
                $redacted[$key] = '[redacted]';

                continue;
            }

            $redacted[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $redacted;
    }

    /**
     * @return array<int|string,mixed>
     */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function string(mixed $value, string $default): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    private function tokenEstimate(string $text): int
    {
        return max(1, (int) ceil(mb_strlen($text) / 4));
    }
}
