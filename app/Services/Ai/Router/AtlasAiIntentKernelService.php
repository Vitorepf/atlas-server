<?php

declare(strict_types=1);

namespace App\Services\Ai\Router;

use Illuminate\Support\Str;

final class AtlasAiIntentKernelService
{
    public const SCHEMA_VERSION = 'atlas.ai.intent_kernel.v1';

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function classify(array $data): array
    {
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $rawIntent = $this->string($data['input_text'] ?? null)
            ?? $this->string(data_get($payload, 'raw_intent'))
            ?? '';
        $attachments = is_array(data_get($payload, 'attachments')) ? (array) data_get($payload, 'attachments') : [];
        $haystack = Str::lower($rawIntent."\n".$this->attachmentText($attachments));
        $workspacePresent = $this->workspace($payload) !== null;
        $signals = $this->signals($haystack, $attachments);
        $intent = $this->intentClass($signals, $workspacePresent);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'intent_class' => $intent['intent_class'],
            'confidence' => $intent['confidence'],
            'risk_level' => $this->riskLevel($signals, $workspacePresent),
            'workspace_present' => $workspacePresent,
            'planning_required' => $signals['plan_like'] || ($signals['patch_like'] && ! $workspacePresent) || $signals['forge_like'],
            'research_required' => $signals['research_like'] || ($signals['patch_like'] && ! $workspacePresent),
            'forge_candidate' => $signals['forge_like'],
            'evidence_signals' => array_keys(array_filter($signals)),
        ];
    }

    /**
     * @param  array<string,bool>  $signals
     * @return array{intent_class:string,confidence:string}
     */
    private function intentClass(array $signals, bool $workspacePresent): array
    {
        if ($signals['review_like']) {
            return ['intent_class' => 'review', 'confidence' => 'strong'];
        }

        if ($signals['debug_like']) {
            return ['intent_class' => 'debug', 'confidence' => 'strong'];
        }

        if ($signals['forge_like']) {
            return ['intent_class' => 'forge', 'confidence' => 'strong'];
        }

        if ($signals['plan_like']) {
            return ['intent_class' => 'plan', 'confidence' => 'strong'];
        }

        if ($signals['patch_like']) {
            return ['intent_class' => $workspacePresent ? 'dev' : 'plan', 'confidence' => $workspacePresent ? 'strong' : 'medium'];
        }

        if ($signals['explain_like']) {
            return ['intent_class' => 'explain', 'confidence' => 'strong'];
        }

        if ($signals['research_like']) {
            return ['intent_class' => 'research', 'confidence' => 'strong'];
        }

        return ['intent_class' => 'conversation', 'confidence' => 'low'];
    }

    /**
     * @return array<string,bool>
     */
    private function signals(string $haystack, array $attachments): array
    {
        return [
            'review_like' => $this->hasDiffOrPr($attachments, $haystack),
            'debug_like' => $this->containsAny($haystack, ['stack trace', 'stacktrace', 'traceback', 'debug ', 'debugue', 'logs', 'log ', 'erro em producao', 'erro em produção', 'exception', 'observability']),
            'forge_like' => $this->containsAny($haystack, ['obra ', 'multi-semana', 'multi semana', 'sistema inteiro', 'sistema todo', 'app inteiro', 'one shot enterprise', 'one-shot enterprise']),
            'plan_like' => $this->containsAny($haystack, ['planeje', 'planejar', 'plano', 'plan ', 'planning', 'roadmap', 'estruture', 'arquitetura antes', 'antes de implementar']),
            'patch_like' => $this->containsAny($haystack, ['implemente', 'implementa', 'corrija', 'corrigir', 'refatore', 'refactor', 'mude ', 'altere ', 'crie ', 'adicione ', 'fix ', 'implement ', 'patch']),
            'explain_like' => $this->containsAny($haystack, ['explique', 'explica', 'explain', 'resuma', 'summarize']),
            'research_like' => $this->containsAny($haystack, ['pesquisa', 'pesquise', 'pesquisar', 'research', 'fontes', 'referencias', 'referências', 'estado da arte', 'como funciona', 'how does', 'difference between', 'qual a diferença']),
        ];
    }

    /**
     * @param  array<string,bool>  $signals
     */
    private function riskLevel(array $signals, bool $workspacePresent): string
    {
        if ($signals['forge_like']) {
            return 'high';
        }

        if ($signals['patch_like'] && $workspacePresent) {
            return 'medium';
        }

        if ($signals['debug_like'] || $signals['review_like']) {
            return 'medium';
        }

        return 'low';
    }

    private function workspace(array $payload): ?string
    {
        return $this->string(data_get($payload, 'workspace'))
            ?? $this->string(data_get($payload, 'tool_permissions.workspace'))
            ?? $this->string(data_get($payload, 'forge_workspace.workspace_path'));
    }

    private function hasDiffOrPr(array $attachments, string $haystack): bool
    {
        return $this->containsAny($haystack, ['diff', 'pull request', ' pr ', 'review do diff'])
            || collect($attachments)->contains(fn (mixed $attachment): bool => is_array($attachment)
                && $this->containsAny(Str::lower((string) ($attachment['kind'] ?? '').' '.(string) ($attachment['name'] ?? '')), ['diff', 'patch', 'pull request']));
    }

    private function attachmentText(array $attachments): string
    {
        return collect($attachments)
            ->filter(fn (mixed $attachment): bool => is_array($attachment))
            ->map(fn (array $attachment): string => (string) ($attachment['kind'] ?? '').' '.(string) ($attachment['name'] ?? ''))
            ->implode("\n");
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
