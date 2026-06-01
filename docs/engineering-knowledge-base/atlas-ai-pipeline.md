---
id: atlas-ai-pipeline
type: engineering_knowledge
title: Atlas AI Pipeline
status: active
category: architecture
priority: 100
summary: Documento fundador que define o pipeline unico de qualquer requisicao Atlas AI, com etapas, responsabilidades e invariantes.
tags:
  - atlas-ai
  - pipeline
  - orchestration
capabilities:
  - unified_pipeline
  - domain_pipeline_contract
  - execution_governance
decisions:
  - Todo fluxo operacional relevante deve passar pelo pipeline unico.
  - Domains customizam etapas, mas nao reinventam a ordem macro.
  - Surfaces chamam o pipeline; elas nao pulam intent, policy, gates ou evidencia.
  - Domain Profile e Flow Profile devem ser resolvidos antes de Atlas Decide compilar a execucao.
  - Atlas Decide emite Decision Receipt; ele nao executa o fluxo de dominio.
  - Por padrao, Atlas Decide escolhe o melhor provider/modelo permitido para a tarefa; override manual e excecao registrada.
maintenance:
  - Atualizar quando uma etapa macro do pipeline mudar.
  - Todo domain novo deve declarar como implementa cada etapa.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-vision.md
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-resolver-corpus-audit.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-pipeline

graph_title: Atlas AI Pipeline

graph_world: atlas

graph_layer: flow

graph_kind: flow

graph_parent: atlas-ai-pipeline

graph_status: active

graph_source: repo
human_name: Atlas AI Pipeline
canonical_name: Atlas AI Pipeline
technical_name: atlas-ai-pipeline
cartography_type: flow
canonical_source: docs/engineering-knowledge-base/atlas-ai-pipeline.md

owner: architecture

gear_flow:
  - graph_id: atlas-ai-pipeline:input
    target_graph_id: atlas-input
    name: Input
    kind: input
    summary: preserva origem, surface, workspace e anexos
  - graph_id: atlas-ai-pipeline:intent
    target_graph_id: intent-routing
    name: Intent
    kind: context
    summary: entende o pedido, risco e confianca antes de executar
  - graph_id: atlas-ai-pipeline:domain
    target_graph_id: domain-plane
    name: Domain
    kind: context
    summary: escolhe a vertical operacional responsavel
  - graph_id: atlas-ai-pipeline:domain-profile
    target_graph_id: domain-profile-flow
    name: Domain Profile
    kind: context
    summary: escolhe o sistema operacional vertical correto
  - graph_id: atlas-ai-pipeline:flow-profile
    target_graph_id: domain-profile-flow
    name: Flow Profile
    kind: context
    summary: escolhe o fluxo operacional dentro do dominio
  - graph_id: atlas-ai-pipeline:context
    target_graph_id: context-builder
    name: Context
    kind: context
    summary: monta memoria, docs, arquivos e historico com budget
  - graph_id: atlas-ai-pipeline:policy
    target_graph_id: policy-profile
    name: Policy
    kind: policy
    summary: aplica regras, permissao, custo, tools, gates e autonomia
  - graph_id: atlas-ai-pipeline:decide
    target_graph_id: atlas-decide
    name: Decide
    kind: decision
    summary: Atlas Decide compila provider, modelo, fallback e contrato de evidencia
  - graph_id: atlas-ai-pipeline:executor
    target_graph_id: runtime-executor
    name: Executor
    kind: output
    summary: executa dentro do Decision Receipt
  - graph_id: atlas-ai-pipeline:gate
    target_graph_id: quality-gates
    name: Gate
    kind: gate
    summary: verifica sucesso com evidence, SLO, seguranca e qualidade
  - graph_id: atlas-ai-pipeline:repair
    target_graph_id: repair-escalation
    name: Repair / Escalation
    kind: failure
    summary: corrige, reexecuta, escala ou bloqueia sem improviso
  - graph_id: atlas-ai-pipeline:evidence
    target_graph_id: evidence-ledger
    name: Evidence
    kind: gate
    summary: registra o que foi feito, medido e persistido
  - graph_id: atlas-ai-pipeline:learning
    target_graph_id: learning-proposals
    name: Learning
    kind: context
    summary: promove memoria, benchmark ou proposta sem violar policy
  - graph_id: atlas-ai-pipeline:output
    target_graph_id: output-renderer
    name: Output
    kind: output
    summary: surface apresenta a resposta ou artefato ao operador

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - architecture

evidence:
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
evidence_refs:
  - symbol: AtlasAiPipelineRuntimeService
  - command: atlas:aaeos:atlas-ai-pipeline-runtime
  - test: AtlasAiPipelineRuntimeTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - flow
  - flow
  - architecture

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas AI Pipeline

O pipeline unico do Atlas AI e:

```text
Input
-> Intent
-> Domain
-> Domain Profile
-> Flow Profile
-> Context
-> Policy
-> Decide
-> Executor
-> Gate
-> Repair/Escalation
-> Evidence
-> Learning
-> Output
```

## Etapas

| Etapa | Pergunta | Owner |
|---|---|---|
| Input | O que entrou e em qual formato? | Atlas AI Core |
| Intent | O operador quer o que? | Atlas AI Core + Domain |
| Domain | Qual vertical deve responder? | Atlas AI Core |
| Domain Profile | Qual sistema operacional vertical deve ser usado? | Atlas Profile Resolver |
| Flow Profile | Qual fluxo operacional dentro do dominio? | Atlas Profile Resolver + Domain |
| Context | Que memoria, docs, arquivos e historico importam? | Context Core + Domain |
| Policy | Quais regras, permissoes, budget, tools, gates e autonomia valem? | AtlasAiPolicyService |
| Decide | Qual grafo, provider/modelo, fallback e evidence contract? | Atlas Decide |
| Executor | Quem executa e com qual intensidade? | Core Executor + Domain |
| Gate | O que prova sucesso? | Core Gate + Domain |
| Repair/Escalation | Se falhou, corrige, escala ou bloqueia? | Domain |
| Evidence | O que foi feito, medido e persistido? | Core Evidence + Domain |
| Learning | O que vira memoria, benchmark ou proposta? | Atlas Learning / Curation |
| Output | O que o operador precisa saber agora? | Surface |

## Invariantes

1. `Input` sempre deve preservar origem, surface, workspace e anexos.
2. `Intent` sempre deve registrar confianca ou motivo da classificacao.
3. `Domain` deve ser explicito quando a tarefa altera estado real.
4. `Domain Profile` e `Flow Profile` devem ser explicitos em runs operacionais.
5. `Context` deve ter hash, budget e refs quando um provider for chamado.
6. `Policy` deve registrar provider/modelo/permissao/budget/tools/gates.
7. `Decide` deve emitir Decision Receipt antes de execucao relevante.
8. `Executor` nao escolhe sua propria politica.
9. `Gate` avalia evidencia; ferramenta roda antes e persiste resultado.
10. `Repair` usa capsule estruturada, nao prompt improvisado.
11. `Evidence` e obrigatoria para declarar sucesso operacional.
12. `Learning` nunca promove memoria sensivel sem politica de privacidade.

## Policy, Profile E Decision Receipt

`Profile` define que fluxo operacional esta em jogo:

```text
programming.dev
programming.forge
programming.qa
finance.market_research
personal_development.weekly_review
```

`Policy` define as regras concretas daquele fluxo nesta chamada:

- provider/modelo permitidos;
- modo de selecao de modelo: auto-best permitido, auto-best disponivel ou override manual;
- autonomia;
- budget;
- tools permitidas;
- sandbox;
- contexto maximo;
- gates obrigatorios;
- fallback;
- evidence minima;
- privacidade;
- aprovacoes.

`Atlas Decide` compila profile + policy + contexto + risco em um `Decision
Receipt`. Esse receipt e o contrato que autoriza o executor a agir.

Por padrao, a selecao de modelo e automatica. Quando o operador passa um modelo
especifico em `atlas dev` ou `atlas forge`, isso vira `manual_override` no
receipt, nao um novo fluxo. O override pode ser aceito, ajustado ou bloqueado
pela policy.

Regra:

```text
Decide decide.
Domain Orchestrator executa.
Runtime produz evidencia.
Gate declara se passou.
```

## Intensidade

O pipeline suporta intensidade:

| Intensidade | Uso |
|---|---|
| `light` | resposta direta, baixo risco, pouca evidencia |
| `medium` | contexto + executor + gates relevantes |
| `heavy` | harness, isolamento, artifacts, multi-gate, repair e score |

Exemplo em programacao:

```text
atlas dev     -> Programming, intensity auto
atlas forge   -> Programming, intensity heavy
atlas fix     -> Programming, intent repair
atlas continue-> mesmo domain/intent com state resume
```

Em todos os casos, a surface entra no mesmo pipeline. A diferenca e profile,
policy, intensidade e estado, nao um produto interno diferente.

Surfaces canonicas que entram no pipeline:

- `cli` (atlas dev/forge/ask/chat/voice/...)
- `app` (Mac/desktop)
- `mobile` (mobile gateway + inbox + push)
- `api` (HTTP REST/WS)
- `mcp` (Atlas Open Brain MCP server)
- `voice_realtime` (Voice Realtime Surface — mobile-first + LiveKit Agents SDK + Swift Mac edge futuro; ver `atlas-ai-voice-realtime-surface.md`)
- `worker` (jobs/scheduler/background)

## Contrato De Implementacao

Todo domain precisa declarar:

- intents suportados;
- context pack proprio;
- executores;
- gates;
- repair/escalation;
- evidence packet;
- learning hooks;
- surfaces permitidas.

Se algum domain nao implementa uma etapa, deve declarar `not_applicable` com
motivo.

## Anti-Padroes

- Surface chamando provider direto.
- Comando montando contexto proprio.
- Gate parseando stdout bruto sem normalizer quando existe Tool Runtime.
- Repair em comando separado quando o fluxo atual consegue reparar.
- Evidence opcional para tarefa que altera codigo, dinheiro, saude ou decisao.

## Resumo

Documento fundador que define o pipeline unico de qualquer requisicao Atlas AI, com etapas, responsabilidades e invariantes.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
