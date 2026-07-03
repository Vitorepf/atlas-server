<?php

declare(strict_types=1);

namespace App\Services\Ai\Streaming;

/**
 * Tradutor incremental do `codex exec --json` (JSONL) em eventos TIPADOS de
 * stream — a matéria-prima da visibilidade de execução no desktop (decisão
 * do operador 03/07: "não sei o que ele está fazendo, quais ferramentas está
 * usando, nem se bugou" — paridade com Claude Code/Codex/Cursor).
 *
 * Contratos:
 *  - feed() recebe buffers arbitrários (o processo emite em pedaços) e mantém
 *    o resto de linha entre chamadas; só linhas completas são interpretadas.
 *  - Linha que não é JSON (ou JSON sem mapeamento) vira evento stdout cru —
 *    NUNCA é engolida (fallback byte-compat com o comportamento antigo).
 *  - Tolerante a versões do codex: lê item.item_type OU item.type; campos
 *    ausentes degradam para strings vazias, nunca exception.
 */
final class CodexJsonlEventParser
{
    private string $remainder = '';

    /**
     * @return list<array{type:string,name:string,content:string,channel:?string,metadata:array<string,mixed>}>
     */
    public function feed(string $buffer): array
    {
        $this->remainder .= $buffer;
        $events = [];
        while (($pos = strpos($this->remainder, "\n")) !== false) {
            $line = trim(substr($this->remainder, 0, $pos));
            $this->remainder = substr($this->remainder, $pos + 1);
            if ($line === '') {
                continue;
            }
            $events[] = $this->parseLine($line);
        }

        return $events;
    }

    /** Resto sem newline final (flush no fim do processo). */
    public function flushRemainder(): ?array
    {
        $line = trim($this->remainder);
        $this->remainder = '';

        return $line === '' ? null : $this->parseLine($line);
    }

    /**
     * @return array{type:string,name:string,content:string,channel:?string,metadata:array<string,mixed>}
     */
    private function parseLine(string $line): array
    {
        $decoded = json_decode($line, true);
        if (! is_array($decoded)) {
            return $this->raw($line);
        }

        $type = (string) ($decoded['type'] ?? '');
        $item = is_array($decoded['item'] ?? null) ? $decoded['item'] : [];
        $itemType = (string) ($item['item_type'] ?? $item['type'] ?? '');

        // Marcos de turno/thread: lifecycle enxuto (a UI mostra "pensando…").
        if (in_array($type, ['thread.started', 'turn.started', 'turn.completed', 'turn.failed'], true)) {
            return [
                'type' => 'lifecycle',
                'name' => str_replace('.', '_', $type),
                'content' => '',
                'channel' => 'activity',
                'metadata' => ['usage' => $decoded['usage'] ?? null],
            ];
        }

        if (str_starts_with($type, 'item.') && $itemType !== '') {
            return match ($itemType) {
                'command_execution' => [
                    'type' => 'tool',
                    'name' => 'shell',
                    'content' => (string) ($item['command'] ?? ''),
                    'channel' => 'activity',
                    'metadata' => [
                        'phase' => $type,
                        'exit_code' => $item['exit_code'] ?? null,
                        'status' => $item['status'] ?? null,
                        'output_excerpt' => mb_substr((string) ($item['aggregated_output'] ?? ''), 0, 400),
                    ],
                ],
                'file_change', 'patch', 'patch_apply' => [
                    'type' => 'tool',
                    'name' => 'edit',
                    'content' => $this->fileChangeSummary($item),
                    'channel' => 'activity',
                    'metadata' => ['phase' => $type, 'status' => $item['status'] ?? null],
                ],
                'web_search' => [
                    'type' => 'tool',
                    'name' => 'search',
                    'content' => (string) ($item['query'] ?? ''),
                    'channel' => 'activity',
                    'metadata' => ['phase' => $type],
                ],
                'mcp_tool_call' => [
                    'type' => 'tool',
                    'name' => (string) ($item['tool'] ?? 'mcp'),
                    'content' => (string) ($item['server'] ?? ''),
                    'channel' => 'activity',
                    'metadata' => ['phase' => $type, 'status' => $item['status'] ?? null],
                ],
                'reasoning' => [
                    'type' => 'thinking',
                    'name' => 'reasoning',
                    'content' => mb_substr((string) ($item['text'] ?? $item['summary'] ?? ''), 0, 600),
                    'channel' => 'activity',
                    'metadata' => ['phase' => $type],
                ],
                'agent_message' => [
                    'type' => 'response',
                    'name' => 'assistant_message',
                    'content' => (string) ($item['text'] ?? ''),
                    'channel' => 'assistant',
                    'metadata' => ['phase' => $type],
                ],
                default => $this->raw($line),
            };
        }

        return $this->raw($line);
    }

    /** @return array{type:string,name:string,content:string,channel:?string,metadata:array<string,mixed>} */
    private function raw(string $line): array
    {
        return [
            'type' => 'stdout',
            'name' => 'stdout',
            'content' => $line,
            'channel' => null,
            'metadata' => ['source' => 'codex_unparsed_line'],
        ];
    }

    /** @param  array<string,mixed>  $item */
    private function fileChangeSummary(array $item): string
    {
        $changes = $item['changes'] ?? $item['files'] ?? null;
        if (is_array($changes) && $changes !== []) {
            $paths = [];
            foreach (array_slice($changes, 0, 5) as $change) {
                $paths[] = is_array($change) ? (string) ($change['path'] ?? '') : (string) $change;
            }

            return implode(', ', array_filter($paths));
        }

        return (string) ($item['path'] ?? '');
    }
}
