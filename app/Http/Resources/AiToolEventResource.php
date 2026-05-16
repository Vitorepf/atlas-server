<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * AiToolEventResource — surface de ferramentas executadas pela IA
 * (read/write/exec/MCP/git/etc.) para que o cliente possa renderizar
 * tool receipts inline (estilo Codex CLI) e live activity footer.
 */
class AiToolEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trace_id' => $this->trace_id,
            'tool' => $this->tool,
            'kind' => $this->classifyKind((string) $this->tool),
            'permission_status' => $this->permission_status,
            'approval_source' => $this->approval_source,
            'input_summary' => Metadata::forResponse($this->input_summary),
            'output_summary' => Metadata::forResponse($this->output_summary),
            'changed_files' => Metadata::listForResponse($this->changed_files),
            'exit_code' => $this->exit_code,
            'duration_ms' => $this->duration_ms,
            'error' => $this->error,
            'occurred_at' => $this->occurred_at?->toJSON(),
        ];
    }

    /**
     * Classifica a ferramenta em uma categoria canônica para que o
     * cliente escolha o ícone certo sem ter que conhecer cada provider.
     * Nunca lança exceção — fallback é "unknown".
     */
    private function classifyKind(string $tool): string
    {
        $normalized = strtolower(trim($tool));
        if ($normalized === '') {
            return 'unknown';
        }

        $rules = [
            'read' => ['read', 'cat', 'open', 'view'],
            'write' => ['write', 'create', 'apply_patch'],
            'edit' => ['edit', 'replace', 'patch', 'multiedit'],
            'bash' => ['bash', 'shell', 'sh', 'zsh'],
            'execute' => ['execute', 'exec', 'run'],
            'search' => ['search', 'find'],
            'grep' => ['grep', 'ripgrep', 'rg'],
            'list' => ['ls', 'list'],
            'glob' => ['glob'],
            'mcp' => ['mcp', 'mcp_'],
            'git' => ['git', 'gh ', 'github'],
            'web_fetch' => ['web_fetch', 'webfetch', 'fetch_url', 'curl', 'http'],
            'web_search' => ['web_search', 'websearch', 'google', 'duckduckgo'],
            'agent_dispatch' => ['agent', 'task', 'subagent'],
            'todo' => ['todo', 'todowrite'],
            'plan' => ['plan', 'exitplanmode'],
        ];

        foreach ($rules as $kind => $patterns) {
            foreach ($patterns as $pattern) {
                if (str_contains($normalized, $pattern)) {
                    return $kind;
                }
            }
        }

        return 'unknown';
    }
}
