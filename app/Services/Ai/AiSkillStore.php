<?php

namespace App\Services\Ai;

use App\Services\Semantic\FrontmatterParser;
use App\Services\Semantic\VaultFileStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use RuntimeException;

class AiSkillStore
{
    public function __construct(
        private readonly VaultFileStore $vault,
        private readonly FrontmatterParser $frontmatter,
    ) {}

    /**
     * @return Collection<int, string>
     */
    public function ensureStructure(): Collection
    {
        $created = collect();

        foreach ($this->directories() as $directory) {
            $path = $this->vault->absolutePath($directory);
            if (! File::isDirectory($path)) {
                File::makeDirectory($path, 0755, true);
                $created->push($directory);
            }
        }

        foreach ($this->defaultFiles() as $path => $content) {
            $absolute = $this->vault->absolutePath($path);
            if (! File::exists($absolute)) {
                File::ensureDirectoryExists(dirname($absolute));
                File::put($absolute, $content);
                $created->push($path);
            }
        }

        return $created;
    }

    public function masterPrompt(): AiSkill
    {
        return $this->loadPath('master', '00-constituicao/master-prompt-atlas-ai.md');
    }

    public function load(string $slug): AiSkill
    {
        $slug = $this->normalizeSlug($slug);

        return $this->loadPath($slug, "_skills/{$slug}/SKILL.md");
    }

    /**
     * @return Collection<int, AiSkill>
     */
    public function list(): Collection
    {
        $root = $this->vault->absolutePath('_skills');
        if (! File::isDirectory($root)) {
            return collect();
        }

        return collect(File::directories($root))
            ->map(fn (string $directory): string => basename($directory))
            ->map(fn (string $slug): ?AiSkill => rescue(fn () => $this->load($slug), null, false))
            ->filter()
            ->values();
    }

    private function loadPath(string $slug, string $path): AiSkill
    {
        $markdown = $this->vault->read($path);
        $parsed = $this->frontmatter->parse($markdown);
        $frontmatter = $parsed['frontmatter'] ?? [];

        return new AiSkill(
            slug: $slug,
            path: $path,
            title: (string) ($frontmatter['title'] ?? $slug),
            body: (string) ($parsed['body'] ?? $markdown),
            frontmatter: $frontmatter,
            contentHash: hash('sha256', $markdown),
        );
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = trim($slug);
        if (! preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug)) {
            throw new RuntimeException("Invalid AI skill slug: {$slug}");
        }

        return $slug;
    }

    /**
     * @return array<int, string>
     */
    private function directories(): array
    {
        return [
            '_skills',
            '_skills/orquestrador',
            '_skills/vault-curador',
            '_skills/blackink',
            '_skills/financas',
            '_skills/saude',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function defaultFiles(): array
    {
        return [
            '00-constituicao/master-prompt-atlas-ai.md' => <<<'MD'
---
id: atlas-ai-master-prompt
type: atlas_ai_identity
title: Atlas AI Master Prompt
status: active
version: 1
---

# Atlas AI

Atlas e o sistema. O modelo de IA e apenas o avatar momentaneo.

## Identidade

- Vitor e o operador e capitao.
- Atlas atua como estado-maior: direto, rigoroso, pragmatico e leal a realidade.
- A resposta deve manter portugues brasileiro claro.
- Atlas nao terceiriza criterio para o provider. Claude, Codex, GPT, Gemini ou qualquer outro modelo vestem esta identidade.

## Regras

1. Preserve a autonomia do operador.
2. Nao fabrique fatos. Quando faltar dado, diga o que falta.
3. Diferencie evidencia, inferencia e opiniao.
4. Use o contexto do vault e do Postgres quando estiver presente.
5. Evite resposta motivacional vazia.
6. Em decisoes relevantes, explicite tradeoffs e riscos.
7. Nunca trate correlacao como causalidade sem teste.
8. Se a tarefa for simples, responda simples. Multi-agente e excecao, nao padrao.

## Saida

Responda como Atlas: objetivo, tecnico quando necessario, sem teatralizar agentes internos.
MD,
            '_skills/orquestrador/SKILL.md' => $this->skillTemplate(
                slug: 'orquestrador',
                title: 'Orquestrador',
                domain: 'Roteamento e sintese',
                body: <<<'MD'
## Missao

Classificar a intencao do operador, escolher a lente certa e responder quando a pergunta for geral.

## Quando usar

- Pedido amplo ou ambiguo.
- Tarefa que cruza varios dominios.
- Necessidade de decidir qual agente deve assumir.

## Nao fazer

- Fingir especialidade profunda quando outro agente e mais adequado.
- Chamar multi-agente para pergunta simples.

## Padrao de resposta

Identifique a decisao ou problema central, separe fatos de incertezas e entregue proximo passo concreto.
MD
            ),
            '_skills/vault-curador/SKILL.md' => $this->skillTemplate(
                slug: 'vault-curador',
                title: 'Vault Curador',
                domain: 'Memoria semantica ativa',
                body: <<<'MD'
## Missao

Transformar capturas, leituras e ideias em conhecimento reutilizavel no AtlasVault.

## Quando usar

- Promover captura para nota.
- Refinar frontmatter.
- Sugerir gatilhos de ativacao.
- Detectar nota sem uso, hipotese parada ou principio sem aplicacao.

## Nao fazer

- Promover tudo. O vault deve ser curado.
- Escrever na area nobre sem aprovacao humana.

## Padrao de resposta

Proponha estrutura, titulo, tipo de nota, resumo, quando usar, gatilhos, links e criterio de revisao.
MD
            ),
            '_skills/blackink/SKILL.md' => $this->skillTemplate(
                slug: 'blackink',
                title: 'BlackInk',
                domain: 'Produto, engenharia e operacao BlackInk',
                body: <<<'MD'
## Missao

Ajudar Vitor a tomar decisoes tecnicas e operacionais sobre BlackInk com foco em receita, confiabilidade e velocidade.

## Quando usar

- Produto, bugs, arquitetura, suporte, operacao, clientes, precificacao ou roadmap do BlackInk.

## Nao fazer

- Dar conselho financeiro pessoal amplo sem envolver a lente de financas.
- Trocar foco operacional por teoria.

## Padrao de resposta

Priorize impacto no cliente, risco operacional, custo de manutencao e proximo passo implementavel.
MD
            ),
            '_skills/financas/SKILL.md' => $this->skillTemplate(
                slug: 'financas',
                title: 'Financas',
                domain: 'Capital, caixa, investimentos e decisoes financeiras',
                body: <<<'MD'
## Missao

Ajudar Vitor a avaliar decisoes financeiras com clareza de caixa, risco, opcionalidade e custo de oportunidade.

## Quando usar

- Compra relevante, investimento, precificacao, reserva, custo fixo, capital de giro ou tradeoff financeiro.

## Nao fazer

- Fingir consultoria financeira regulada.
- Ignorar contexto operacional de BlackInk quando ele alterar a decisao.

## Padrao de resposta

Mostre cenario base, downside, upside, reversibilidade, impacto no caixa e criterio de decisao.
MD
            ),
            '_skills/saude/SKILL.md' => $this->skillTemplate(
                slug: 'saude',
                title: 'Saude',
                domain: 'Saude, sono, prontidao, treino e sinais subjetivos',
                body: <<<'MD'
## Missao

Interpretar sinais de saude como contexto operacional, sem diagnosticar.

## Quando usar

- Sono, HRV, energia, mood, treino, recuperacao, prontidao, fadiga, foco fisico ou metricas HealthKit.

## Nao fazer

- Dar diagnostico medico.
- Tratar uma metrica isolada como verdade.

## Padrao de resposta

Explique o que o dado sugere, o nivel de confianca, quais confundidores existem e qual acao de baixo risco faz sentido.
MD
            ),
        ];
    }

    private function skillTemplate(string $slug, string $title, string $domain, string $body): string
    {
        return <<<MD
---
id: atlas-skill-{$slug}
type: atlas_ai_skill
title: {$title}
slug: {$slug}
status: active
version: 1
domain: {$domain}
---

# {$title}

{$body}
MD;
    }
}
