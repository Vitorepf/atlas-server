<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

class AtlasFinalResponseSanitizer
{
    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    public function sanitize(string $text): array
    {
        $original = trim($text);
        if ($original === '') {
            return ['', ['changed' => false, 'reason' => null]];
        }

        $extracted = $this->extractProviderTranscriptResult($original);
        if ($extracted !== null) {
            return [$extracted, [
                'changed' => $extracted !== $original,
                'reason' => 'provider_transcript_result_extracted',
            ]];
        }

        if ($this->looksLikeQualityRepairPromptEcho($original)) {
            $clean = $this->stripQualityRepairPromptEcho($original);
            if ($clean !== '') {
                return [$clean, [
                    'changed' => $clean !== $original,
                    'reason' => 'quality_repair_prompt_echo_stripped',
                ]];
            }
        }

        if ($this->hasInternalLeakMarkers($original)) {
            return ['Não consegui preparar uma resposta segura para exibição. A saída interna foi bloqueada; reenvie o pedido para gerar uma resposta limpa.', [
                'changed' => true,
                'reason' => 'internal_context_leak_blocked',
            ]];
        }

        return [$original, ['changed' => false, 'reason' => null]];
    }

    public function forOperator(string $text, int $limit = 12000): string
    {
        [$clean] = $this->sanitize($text);

        return Str::limit($clean, $limit, "\n...[resposta anterior truncada pelo Atlas]");
    }

    private function extractProviderTranscriptResult(string $text): ?string
    {
        $events = $this->jsonObjectsFromText($text);
        if ($events === []) {
            return null;
        }

        $result = '';
        $assistantText = '';

        foreach ($events as $event) {
            $eventResult = $this->stringValue($event['result'] ?? null);
            if (($event['type'] ?? null) === 'result' && $eventResult !== '') {
                $result = $eventResult;
                continue;
            }

            $textFromPayload = $this->assistantTextFromPayload($event);
            if ($textFromPayload !== '') {
                $assistantText .= $textFromPayload;
            }
        }

        $candidate = trim($result !== '' ? $result : $assistantText);
        if ($candidate === '') {
            return null;
        }

        return $this->hasInternalLeakMarkers($candidate) ? null : $candidate;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function jsonObjectsFromText(string $text): array
    {
        $events = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || ! str_starts_with($line, '{')) {
                continue;
            }

            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }

        if ($events !== []) {
            return $events;
        }

        $decoded = json_decode(trim($text), true);
        if (is_array($decoded)) {
            return [$decoded];
        }

        return [];
    }

    private function assistantTextFromPayload(array $payload): string
    {
        $message = is_array($payload['message'] ?? null) ? $payload['message'] : $payload;
        $content = $message['content'] ?? null;
        if (! is_array($content)) {
            return $this->stringValue($message['text'] ?? null);
        }

        $text = '';
        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) !== 'text') {
                continue;
            }

            $text .= $this->stringValue($block['text'] ?? null);
        }

        return $text;
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function looksLikeQualityRepairPromptEcho(string $text): bool
    {
        $lower = Str::lower($text);

        return str_contains($lower, 'reescreva a resposta abaixo como saída final do atlas')
            && str_contains($lower, 'pedido original:')
            && str_contains($lower, 'resposta anterior:')
            && str_contains($lower, 'falhas detectadas:');
    }

    private function stripQualityRepairPromptEcho(string $text): string
    {
        $parts = preg_split('/\n\s*Regras:\s*\n/i', $text, 2);
        if (! is_array($parts) || count($parts) < 2) {
            return '';
        }

        $afterRules = trim((string) $parts[1]);
        $lines = preg_split('/\R/', $afterRules) ?: [];
        while ($lines !== [] && str_starts_with(trim((string) $lines[0]), '-')) {
            array_shift($lines);
        }

        $clean = trim(implode("\n", $lines));

        return $this->hasInternalLeakMarkers($clean) ? '' : $clean;
    }

    public function hasInternalLeakMarkers(string $text): bool
    {
        $lower = Str::lower($text);

        foreach ([
            '"type":"system"',
            '"type": "system"',
            '"subtype":"init"',
            '"subtype": "init"',
            '"mcp_servers"',
            '"permissionmode"',
            '"apikeysource"',
            '"claude_code_version"',
            '"modelusage"',
            '"cache_creation_input_tokens"',
            '"permission_denials"',
            '"terminal_reason"',
            '"fast_mode_state"',
            'context_pack',
            'context_pack_hash',
            '"trace_id"',
            '"thread_id"',
            '"session_id"',
        ] as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        return (bool) (
            preg_match('/\b(trace_id|thread_id|session_id)\b/i', $text)
            && preg_match('/("type"\s*:|"tools"\s*:|"metadata"\s*:|provider|modelusage|permissionmode|cache_creation|mcp_servers|uuid)/i', $text)
        );
    }
}
