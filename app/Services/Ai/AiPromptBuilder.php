<?php

namespace App\Services\Ai;

use App\Models\SemanticNote;
use App\Services\Semantic\SemanticSearchService;
use Illuminate\Support\Str;

class AiPromptBuilder
{
    public function __construct(
        private readonly AiSkillStore $skills,
        private readonly AiIntentRouter $router,
        private readonly SemanticSearchService $search,
    ) {}

    public function build(string $input, array $options = []): AiPrompt
    {
        $route = $this->router->route($input, $options['agent_slug'] ?? null);
        $agent = (string) $route['agent'];
        $intent = (string) $route['intent'];

        $master = $this->skills->masterPrompt();
        $skill = $this->skills->load($agent);
        $notes = $this->contextNotes($input, $options);
        $contextRefs = $notes->map(fn (SemanticNote $note): array => [
            'type' => 'semantic_note',
            'id' => $note->id,
            'path' => $note->path,
            'title' => $note->title,
            'score' => isset($note->score) ? round((float) $note->score, 4) : null,
        ])->values()->all();

        $prompt = implode("\n\n", array_filter([
            "# Identidade Atlas\n\n{$master->body}",
            "# Skill ativa: {$skill->title}\n\n{$skill->body}",
            $this->formatContext($notes),
            $this->workflowInstructions($options),
            "# Pedido do operador\n\n{$input}",
            <<<'TXT'
# Instrucoes de execucao

Responda em portugues brasileiro.
Use a identidade Atlas acima.
Se o contexto recuperado nao for suficiente, declare a lacuna.
Nao mencione detalhes internos de provider, CLI, prompts ou traces, a menos que o operador peca.
TXT,
        ]));

        return new AiPrompt(
            prompt: $prompt,
            agentSlug: $agent,
            intent: $intent,
            skillVersions: [
                'master' => [
                    'path' => $master->path,
                    'hash' => $master->contentHash,
                    'version' => $master->version(),
                ],
                $agent => [
                    'path' => $skill->path,
                    'hash' => $skill->contentHash,
                    'version' => $skill->version(),
                ],
            ],
            contextRefs: $contextRefs,
            model: $options['model'] ?? null,
        );
    }

    private function workflowInstructions(array $options): string
    {
        $mode = data_get($options, 'payload.atlas_workflow_mode');

        return match ($mode) {
            'plan' => <<<'TXT'
# Modo de trabalho: Planejar

Nao execute acao externa. Estruture o problema, explicite premissas, riscos, ordem de implementacao, criterios de verificacao e o proximo passo concreto. Se houver tradeoff, mostre a decisao recomendada e por que ela e melhor para o Atlas agora.
TXT,
            'review' => <<<'TXT'
# Modo de trabalho: Revisar

Assuma postura de revisao rigorosa. Procure bugs, inconsistencias, riscos de arquitetura, pontos de quebra, lacunas de teste e divergencias com a identidade do Atlas. Priorize achados acionaveis antes de resumo. Nao reescreva tudo se o problema for local.
TXT,
            default => '',
        };
    }

    private function contextNotes(string $input, array $options)
    {
        if (($options['include_semantic_context'] ?? true) === false) {
            return collect();
        }

        $limit = (int) ($options['context_note_limit'] ?? config('atlas.ai.context_note_limit', 5));
        if ($limit <= 0) {
            return collect();
        }

        return $this->search->search($input, [], $limit);
    }

    private function formatContext($notes): string
    {
        if ($notes->isEmpty()) {
            return '';
        }

        $excerptChars = (int) config('atlas.ai.context_excerpt_chars', 1200);
        $body = $notes->map(function (SemanticNote $note) use ($excerptChars): string {
            $excerpt = Str::limit((string) ($note->body_excerpt ?: $note->summary), $excerptChars, '...');
            $score = isset($note->score) ? ' score='.round((float) $note->score, 3) : '';

            return <<<TXT
## {$note->title}{$score}
path: {$note->path}
tipo: {$note->type}
resumo: {$note->summary}
trecho: {$excerpt}
TXT;
        })->implode("\n\n");

        return "# Contexto recuperado do AtlasVault\n\n{$body}";
    }
}
