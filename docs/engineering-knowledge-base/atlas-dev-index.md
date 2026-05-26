---
id: atlas-dev-index
type: engineering_knowledge
title: Atlas Dev Index
status: active
category: programming
priority: 100
summary: Entrypoint canonico para IA externa descobrir Atlas Dev. Define em uma pagina o que e Atlas Dev, onde fica no produto Atlas AI, qual seu escopo, quais documentos compoem seu conjunto canonico e em que ordem devem ser lidos. NAO contem schema detalhado, runbook, decisoes locked extensas ou framing competitivo; aponta para os docs donos.
tags:
  - atlas-dev
  - index
  - entrypoint
  - documentation-navigation
capabilities:
  - atlas_dev_navigation_entrypoint
  - canonical_document_set_index
  - reading_order_for_ai
decisions:
  - Atlas Dev e o fluxo especializado de desenvolvimento em workspace dentro do Atlas AI. Atlas AI e o produto; Atlas Dev e UM fluxo entre varios.
  - Toda IA que vai mexer em Atlas Dev (entender, implementar, corrigir, evoluir) deve comecar por este index antes de qualquer outro doc do conjunto Atlas Dev.
  - Este index nunca duplica conteudo dos docs donos; ele aponta. Mudanca de conteudo acontece no doc dono, nao aqui.
maintenance:
  - Atualize este index quando entrar novo doc no conjunto Atlas Dev, quando a hierarquia de leitura mudar, ou quando Atlas Dev mudar de patamar.
  - Manter abaixo de 180 linhas (limite canonico para index).
  - Rodar `php artisan atlas:engineering:knowledge docs-health --json` apos alterar.
related_paths:
  - docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-documentation-inventory.md
  - docs/engineering-knowledge-base/atlas-dev-glossary.md
  - docs/engineering-knowledge-base/atlas-dev-policy.md
  - docs/engineering-knowledge-base/atlas-dev-patamares.md
  - docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-router-flow-routing-contract-v1.md
  - docs/engineering-knowledge-base/atlas-ai-conversation-surface-and-atlas-dev-v1.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-index
graph_title: Atlas Dev Index
graph_world: atlas
graph_layer: system
graph_kind: index
graph_parent: atlas-ai-canonical-architecture-index
graph_status: active
graph_source: repo
human_name: Atlas Dev Index
canonical_name: Atlas Dev Index
technical_name: atlas-dev-index
cartography_type: index
canonical_source: docs/engineering-knowledge-base/atlas-dev-index.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-index.md
allowed_changes:
  - Adicionar entry de doc novo quando integrar ao conjunto Atlas Dev.
  - Atualizar ordem de leitura quando relacao entre docs mudar.
  - Refinar perguntas-chave conforme aparecem confusoes recorrentes em IAs.
forbidden_changes:
  - Duplicar conteudo dos docs donos.
  - Inserir schema detalhado, decisoes locked extensas ou runbook aqui.
  - Inserir benchmark, Rivals, Opus challenge ou medicao competitiva.
  - Tratar Atlas Dev como produto unico ou substituto de Claude Code/Cursor/Codex; Atlas AI e o produto.
depends_on:
  - atlas-ai-canonical-architecture-index
  - atlas-ai-documentation-operating-system
flows_to:
  - atlas-dev-glossary
  - atlas-dev-policy
  - atlas-dev-patamares
  - atlas-dev-efficient-programming-flow-v1
unlocks:
  - atlas_dev_navigation_for_ai
  - canonical_reading_order
governs:
  - atlas_dev.navigation
  - atlas_dev.canonical_document_set
evidence:
  - docs/engineering-knowledge-base/atlas-dev-index.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: low
visual_tags:
  - index
  - navigation
  - atlas-dev
ai_entrypoints:
  - Comece SEMPRE por este index quando for mexer em Atlas Dev. Depois leia glossary, policy, patamares, e so entao o trio efficient-flow.
ai_usage_notes:
  - Este doc e mapa, nao territorio. Para cada assunto, va ao doc dono indicado na tabela.
  - Se uma pergunta nao tem doc dono listado aqui, e gap. Reporte como gap, nao invente.
  - Para docs Atlas Dev `part-*`, audits ou docs fora do conjunto canonico, consulte o Documentation Inventory antes de usar como autoridade.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - IA pula este index e tenta entender Atlas Dev lendo o efficient-flow direto. Resultado: perde contexto de patamar, escopo e governance.
  - IA confunde Atlas Dev com Atlas AI (produto) ou com Atlas Forge (Obra-driven).
observability_signals:
  - docs-health status ok
next_actions:
  - Manter este index sincronizado quando docs Atlas Dev evoluirem.
line_limit: 180
---
# Atlas Dev Index

## 1. Em Uma Frase

Atlas Dev e o **fluxo especializado de desenvolvimento em workspace dentro do Atlas AI**. Cobre patch, repair, refactor leve, code generation, review de diff, frontend pontual, multi-file edit em scope, e perguntas workspace-bound. NAO e produto-substituto de Claude Code/Cursor/Codex (esse papel e do Atlas AI inteiro).

## 2. Onde Atlas Dev Fica No Produto Atlas AI

```text
Atlas AI (produto / superficie unica do programador)
  -> Atlas AI Router  (decide qual fluxo serve cada pedido)
      -> Atlas Dev          (este conjunto canonico — desenvolvimento em workspace)
      -> Atlas Research     (pesquisa conceitual)
      -> Atlas Explain      (explicacao sem patch)
      -> Atlas Debug        (logs/traces)
      -> Atlas Review       (review profundo de PR/diff)
      -> Atlas Conversation (chat exploratorio)
      -> Atlas Forge        (Obra-driven, multiagente, semanas/mes)
      -> futuros            (QA, Security, DB, Design, ...)
```

## 3. Conjunto Canonico Atlas Dev

| Doc | Tipo | Quando ler |
| --- | --- | --- |
| [`atlas-dev-index.md`](atlas-dev-index.md) | index | **agora** (este doc) |
| [`atlas-dev-glossary.md`](atlas-dev-glossary.md) | module | depois deste, para entender termos |
| [`atlas-dev-policy.md`](atlas-dev-policy.md) | policy | regras invariaveis (o que NUNCA mudar) |
| [`atlas-dev-patamares.md`](atlas-dev-patamares.md) | module | qual patamar Atlas Dev esta hoje + futuros planejados |
| [`atlas-dev-flow-map-and-product-options-v1.md`](atlas-dev-flow-map-and-product-options-v1.md) | module | caderno mae: contexto historico, fluxos atuais, opcoes de produto |
| [`atlas-dev-efficient-programming-flow-v1.md`](atlas-dev-efficient-programming-flow-v1.md) | contract | contrato do patamar atual (alto nivel) |
| [`atlas-dev-efficient-programming-flow-contracts-v1.md`](atlas-dev-efficient-programming-flow-contracts-v1.md) | contracts | schemas detalhados (17 artefatos operacionais em 4 camadas) |
| [`atlas-dev-efficient-programming-flow-runbook-v1.md`](atlas-dev-efficient-programming-flow-runbook-v1.md) | runbook | implementacao fatia por fatia |

## 4. Ordem De Leitura Obrigatoria Para IA

```text
1. atlas-ai-session-bootstrap          ← entrypoint global Atlas
2. atlas-ai-canonical-architecture-index ← hierarquia de autoridade
3. atlas-dev-index                     ← AQUI VOCE ESTA
4. atlas-dev-glossary                  ← termos canonicos
5. atlas-dev-policy                    ← regras invariaveis
6. atlas-dev-patamares                 ← patamar atual + planejados
7. atlas-dev-flow-map-and-product-options-v1 ← caderno mae
8. atlas-dev-efficient-programming-flow-v1   ← contrato do patamar atual
   8a. atlas-dev-efficient-programming-flow-contracts-v1 ← schemas
   8b. atlas-dev-efficient-programming-flow-runbook-v1   ← implementacao
```

IA que pula este index e vai direto pro efficient-flow perde contexto de patamar e escopo. Resultado: faz merda.

## 5. Perguntas-Chave (Onde Achar Resposta)

| Pergunta | Onde |
|---|---|
| O que e Atlas Dev exatamente? | este doc + `atlas-dev-glossary` |
| Atlas Dev substitui Claude Code? | NAO. Atlas AI substitui. `atlas-dev-glossary` explica. |
| Quais fluxos existem no Atlas AI? | `atlas-ai-router-flow-routing-contract-v1` |
| Qual o escopo de Atlas Dev (dentro vs fora)? | `atlas-dev-policy` + `atlas-dev-efficient-programming-flow-v1` secao Escopo |
| Quando escalar para Forge? | `atlas-dev-policy` + `atlas-dev-efficient-programming-flow-v1` Forge Escalation |
| Qual o patamar atual de Atlas Dev? | `atlas-dev-patamares` |
| Quais sao os 17 artefatos canonicos? | `atlas-dev-efficient-programming-flow-contracts-v1` |
| Como implementar Fatia 0? | `atlas-dev-efficient-programming-flow-runbook-v1` PR 0.1+ |
| Quais decisoes ja estao locked? | `atlas-dev-policy` + frontmatter `atlas-dev-efficient-programming-flow-v1` |
| Quais sao as regras de governance? | `atlas-dev-policy` |
| Como Atlas Dev se relaciona com Programming Governance? | `atlas-dev-efficient-programming-flow-v1` secao 17.1 |
| Como Atlas Dev se relaciona com Kernel Pipeline? | `atlas-dev-efficient-programming-flow-v1` secao 1.1 |

## 6. Equipes Que Tocam Atlas Dev

| Equipe | Escopo |
| --- | --- |
| **Criacao** (dona deste conjunto canonico) | desenhar e construir Atlas Dev: pipeline, contratos, schemas, gates, scope guard, verification, repair, escalada, drivers, surfaces |
| **Medicao / Rivals** | benchmark, oraculos, baterias, scoring, criterios Rivals — vivem em `atlas-forge-rivals-*`, NAO neste conjunto |

Forbidden: misturar medicao competitiva em qualquer doc do conjunto Atlas Dev. Se aparecer, recusar.

## Resumo

Entrypoint canonico para IA externa descobrir Atlas Dev. Define em uma pagina o que e Atlas Dev, onde fica no Atlas AI, qual conjunto canonico de documentos compoe o Atlas Dev, em que ordem ler, e quais perguntas tem resposta em qual doc.

## Papel no Atlas

Garante que qualquer IA (interna ou externa) tem entrypoint claro para Atlas Dev sem perder contexto de patamar, escopo, governance ou hierarquia. Substitui leitura caotica por sequencia canonica.

## Onde Se Encaixa

Filho de `atlas-ai-canonical-architecture-index`. Irmao dos demais entrypoints de fluxos (futuros `atlas-research-index`, `atlas-explain-index`, etc.). Pai do conjunto canonico Atlas Dev.

## Contratos

Este doc nunca duplica conteudo dos docs donos. Cada pergunta tem **um** doc dono autoritativo. Quando duvida, este index aponta; nao decide.

## Fluxo

IA externa entra -> le este index -> identifica doc dono da pergunta -> le doc dono -> implementa/responde.

## Regras para IA

- Sempre comecar por este index ao mexer em Atlas Dev.
- Nunca duplicar conteudo dos docs donos aqui.
- Nunca tratar Atlas Dev como produto-substituto de Claude Code/Cursor/Codex (Atlas AI e o produto).
- Reportar gap quando uma pergunta nao tem doc dono listado.

## Escopo de Implementacao

Manutencao deste index. Conteudo de cada assunto vive no doc dono.

## Dependencias

- `atlas-ai-canonical-architecture-index` (hierarquia de autoridade)
- `atlas-ai-documentation-operating-system` (regras de doc)
- Os 7 docs do conjunto canonico Atlas Dev (listados na tabela acima)

## Evidencias

- Existencia deste doc e dos demais docs do conjunto Atlas Dev.
- Cross-refs verificados via `related_paths`.
- `docs-health` verde apos cada alteracao.

## Riscos

- IA pular este index e perder contexto de patamar.
- IA tentar absorver conteudo dos docs donos aqui (anti-padrao).
- Index ficar stale enquanto docs donos evoluem.

## Exemplos

IA recebe pedido "implementar Atlas Dev". Caminho correto: le index -> identifica que primeiro precisa entender patamar atual -> le `atlas-dev-patamares` -> identifica Fatia 0 do patamar -> le runbook -> implementa.

## Proximas Acoes

- Manter atualizado quando entrar doc novo.
- Validar ordem de leitura quando IA externa reportar confusao.
- Atualizar quando Atlas Dev mudar de patamar.
