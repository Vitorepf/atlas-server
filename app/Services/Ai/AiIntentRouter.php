<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

class AiIntentRouter
{
    /**
     * @return array{agent:string,intent:string}
     */
    public function route(string $input, ?string $requestedAgent = null): array
    {
        if (is_string($requestedAgent) && trim($requestedAgent) !== '') {
            return [
                'agent' => trim($requestedAgent),
                'intent' => 'operator_selected',
            ];
        }

        $text = Str::lower($input);

        $rules = [
            'comunicador-claro' => [
                'caveman', 'resposta curta', 'resposta simples', 'sem codigo',
                'sem código', 'direto ao ponto', 'seja direto', 'seja simples',
                'nao quero codigo', 'não quero código', 'nao olho codigo',
                'não olho código', 'explica simples', 'clear-output-governor',
                'output governor',
            ],
            'provider-handoff' => [
                'provider handoff', 'handoff', 'trocar provider', 'troca de provider',
                'trocar modelo', 'troca de modelo', 'claude para codex', 'codex para claude',
            ],
            'session-compaction' => [
                'compactar', 'compactacao', 'compactação', 'sessao longa', 'sessão longa',
                'contexto longo', 'continuar por horas', 'conversa longa',
            ],
            'dev-quality-gate' => [
                'quality gate', 'definition of done', 'validar implementacao',
                'validar implementação', 'antes de concluir', 'codigo profissional',
                'código profissional',
            ],
            'test-repair-loop' => [
                'teste de regressao', 'teste de regressão', 'repair loop',
                'corrigir teste', 'teste falhando', 'falha de teste',
            ],
            'ui-verification' => [
                'screenshot', 'playwright', 'verificar ui', 'validar tela',
                'front quebrado', 'texto sobreposto', 'responsivo',
            ],
            'security-review' => [
                'seguranca', 'segurança', 'security review', 'threat model',
                'secret', 'secrets', 'token', 'permissao', 'permissão',
            ],
            'repo-context-pack' => [
                'repo context', 'contexto do repo', 'mapear repositorio',
                'mapear repositório', 'hotspots', 'comandos do repo',
            ],
            'skill-eval-creator' => [
                'eval de skill', 'avaliar skill', 'baseline', 'a/b',
                'comparar skill', 'skill eval',
            ],
            'memory-retrospective' => [
                'retrospectiva de memoria', 'retrospectiva de memória',
                'memory retrospective', 'aprendizado reutilizavel',
                'aprendizado reutilizável',
            ],
            'decision-advisor' => [
                'decisao', 'decisão', 'decidir', 'tradeoff', 'trade-off',
                'criterio de decisao', 'critério de decisão', 'opcoes', 'opções',
            ],
            'researcher-quick' => [
                'pesquisa', 'pesquisar', 'fonte', 'fontes', 'referencia',
                'referência', 'verificar', 'estado da arte',
            ],
            'code-reviewer' => [
                'review', 'revisar codigo', 'revisar código', 'code review',
                'diff', 'pull request', 'regressao', 'regressão',
            ],
            'desenvolvedor' => [
                'implemente', 'implementa', 'corrija', 'debug', 'refatore',
                'backend', 'frontend', 'api', 'cli', 'migration', 'migracao',
                'migração', 'schema', 'teste unitario', 'teste unitário',
                'typecheck',
            ],
            'saude' => [
                'saude', 'sono', 'hrv', 'batimento', 'energia', 'mood', 'treino',
                'prontidao', 'healthkit', 'recuperacao', 'fadiga', 'corpo',
            ],
            'financas' => [
                'dinheiro', 'financa', 'financeiro', 'investimento', 'comprar',
                'custo', 'preco', 'preço', 'caixa', 'capital', 'reserva',
            ],
            'blackink' => [
                'blackink', 'cliente', 'saas', 'bug', 'produto', 'deploy',
                'codigo', 'código', 'roadmap', 'suporte',
            ],
            'atlas' => [
                'atlas', 'inbox', 'captura', 'capturas', 'memoria', 'memória',
                'vault', 'dominio', 'domínio', 'arquitetura', 'agente', 'agentes',
                'curadoria', 'aclaramento',
            ],
            'vault-curador' => [
                'obsidian', 'nota', 'livro', 'conceito', 'modelo mental',
                'principio', 'princípio', 'memoria semantica', 'memória semântica',
            ],
            'aclarador' => [
                'aclarar', 'aclaramento', 'captura bruta', 'ideias atomicas',
                'ideias atômicas', 'tese principal', 'pergunta de autoria',
            ],
        ];

        foreach ($rules as $agent => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    return [
                        'agent' => $agent,
                        'intent' => "keyword:{$needle}",
                    ];
                }
            }
        }

        return [
            'agent' => (string) config('atlas.ai.default_agent', 'orquestrador'),
            'intent' => 'general',
        ];
    }
}
