---
id: atlas-ai-core-vs-domain
type: engineering_knowledge
title: Atlas AI Core Vs Domain
status: active
category: architecture
priority: 100
summary: Documento fundador que define a regra de decisao entre capacidades horizontais do Atlas AI Core e logica especializada de dominios.
tags:
  - atlas-ai
  - core
  - domains
  - anti-duplication
capabilities:
  - core_domain_boundary
  - anti_duplication_governance
  - capability_ownership
decisions:
  - Core contem capacidades horizontais compartilhadas.
  - Domains contem semantica, criterios e harnesses especificos.
  - Surfaces nao sao lugar de capacidade reutilizavel.
  - Empresas/produtos como Blackink sao Business Contexts/Product Domains, nao Atlas AI Domains por padrao.
  - Profiles sao contratos operacionais de dominio/fluxo, nao presets de modelo.
  - Super Tool Runtime pertence ao Core; dominios consomem suas evidencias e definem criterios.
maintenance:
  - Atualizar quando uma capability mudar de domain para Core ou de Core para domain.
  - Usar este documento antes de criar service, command ou module novo.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-vision.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-business-contexts.md
  - docs/engineering-knowledge-base/atlas-ai-architecture-audit.md
  - docs/engineering-knowledge-base/atlas-ai-resolver-corpus-audit.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-core-vs-domain

graph_title: Atlas AI Core Vs Domain

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: architecture

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md

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
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
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
# Atlas AI Core Vs Domain

Este documento responde: uma capacidade nova deve viver no Core, em um Domain
ou em uma Surface?

## Regra Rapida

```text
Serve para mais de um domain ou surface? Core.
Depende de criterios especializados de uma vertical? Domain.
So coleta input ou mostra output? Surface.
```

## Core

Core e a camada compartilhada.

| Core Module | Responsabilidade |
|---|---|
| `Atlas.Input` | Texto, imagem, audio, arquivo, paste, drag-drop e anexos. |
| `Atlas.Intent` | Classificacao inicial, dominio, risco, complexidade e hints. |
| `Atlas.Profile` | Resolve Domain Profile e Flow Profile para a chamada. |
| `Atlas.Context` | Builder universal de context pack e contratos comuns. |
| `Atlas.Memory` | Memoria unica, recall, provider-safety, quality e learning. |
| `Atlas.Policy` | AtlasAiPolicyService: permissoes, budget, provider/model, risco, autonomia, tools, gates e compliance. |
| `Atlas.Provider` | Health, escolha, fallback permitido e runtime de modelo. |
| `Atlas.Executor` | Interface de executores: simple, repair, harness, scheduled. |
| `Atlas.Gate` | Gate framework, status e composicao de evidencias. |
| `Atlas.Tools` | Registry, policy, executor, normalizer e evidence store. |
| `Atlas.Evidence` | Run, attempt, artifact, finding, score e final packet base. |
| `Atlas.Learning` | Delta, promocao, feedback, benchmark e curadoria. |

Core nao conhece profundamente programacao, saude, comportamento ou mercado.
Core fornece infraestrutura e contratos.

## Profile

Profile e contrato operacional, nao preset de modelo.

| Conceito | Exemplo | Dono |
|---|---|---|
| Domain Profile | `programming`, `finance`, `personal_development`, `self_improvement` | Core Profile Resolver |
| Flow Profile | `programming.dev`, `programming.forge`, `programming.qa` | Domain + Policy |
| Model Profile | `opus`, `codex-high`, `gemini-scout` | Provider/Policy |

Regra:

```text
Um flow profile pode escolher modelos, tools e gates.
Um modelo nunca deve definir o flow profile.
```

`atlas dev` deve apontar para `programming.dev`.
`atlas forge` deve apontar para `programming.forge`.
`atlas fix` deve apontar para `programming.dev` ou `programming.forge` com
intent `repair`, dependendo de risco.

## Domain

Domain adiciona semantica especializada.

Empresas, produtos e fontes de renda do Vitor nao sao domains cognitivos por
padrao. `blackink` deve ser tratado como Business Context/Product Domain, enquanto
`programming`, `marketing`, `finance`, `operations` e `strategic_decision`
descrevem a capacidade usada para trabalhar sobre esse contexto. A regra completa
vive em `atlas-ai-business-contexts.md`.

Status operacional atual:

- `programming`, `finance`, `personal_development` e `self_improvement` sao os
  dominios implemented/ready.
- `marketing`, `research`, `health`, `learning`, `writing`, `qa`, `security`,
  `operations`, `background` e `general` sao scaffold/catalog-ready ate terem
  runtime/orchestrator proprio.
- Marketing pode aparecer como exemplo de dominio vertical ou catalogo alvo,
  mas nao deve ser tratado como implemented/ready.

| Domain | Responsabilidades |
|---|---|
| Programming | bug, feature, refactor, review, QA, testes, release, migrations, code intelligence e engineering harness. |
| Personal Development | habitos, foco, performance, saude, rotina, intervencoes, measurement harness e memoria pessoal. |
| Finance | mercado, risco, portfolio, tese, compliance, data provenance e audit trail financeiro, sempre review-only. |
| Self-Improvement | melhoria do proprio Atlas: docs drift, capability gaps, benchmark review, memory quality, provider performance e propostas de evolucao. |
| Marketing | catalogo alvo/scaffold para estrategia, pesquisa, campanhas, copy, analytics, brand review e forge; ainda nao implemented/ready. |

Domain pode ter:

- intent classifiers especificos;
- context pack especializado;
- harness proprio;
- tools preferidas;
- gates especificos;
- evidence schema adicional;
- memory projection propria.

Domain nao deve reimplementar input multimodal, provider routing, memory core,
tool runtime ou telemetry.

Domain deve declarar:

- flow profiles suportados;
- orchestrator canonico;
- runtime leve/medio/pesado;
- gates por profile;
- evidence schema adicional;
- repair/escalation;
- memory projection;
- privacy rules.

## Surface

Surface e porta de entrada/saida:

- CLI;
- app;
- API;
- worker;
- scheduler;
- IDE/MCP.

Surface pode:

- coletar input;
- passar hints;
- renderizar output;
- mostrar status;
- solicitar confirmacao do operador.

Surface nao deve:

- escolher provider fora da policy;
- montar context pack proprio;
- executar tool diretamente sem runtime quando existe registry;
- declarar sucesso sem evidence;
- implementar repair proprio;
- guardar memoria canonica.

## Exemplos

| Caso | Onde deve viver | Motivo |
|---|---|---|
| Paste de imagem | Core `Atlas.Input` | Deve funcionar em ask, dev, chat, app e futuras surfaces. |
| Bugfix com teste falhando | Domain Programming | Semantica de codigo e gates de programacao. |
| Escolher Claude/Codex/Gemini | Core Policy/Provider | Provider e motor, nao dominio. |
| Review de migration | Domain Programming | Gate especializado de banco. |
| Secret scan | Core Tools + Programming Gate | Tool e horizontal; criterio entra no domain. |
| Rotina de sono | Domain Personal Development | Semantica pessoal, privacy e measurement proprios. |
| Operacao financeira | Domain Finance | Risco, compliance e data provenance especificos. |
| Detectar duplicacao de services | Domain Curation | Tarefa meta sobre o proprio Atlas. |

## Teste De Decisao

Antes de criar uma feature, responda:

1. Mais de uma surface deveria usar isso?
2. Mais de um domain poderia se beneficiar?
3. A feature cria estado, evidencia ou memoria?
4. Existe tool/runtime/gate parecido?
5. Existe doc canonico relacionado?
6. Se isso ficar no comando atual, outro comando vai precisar copiar depois?

Se a resposta da pergunta 6 for "sim", a feature nao pertence ao comando.

## Regra Final

Duplicacao arquitetural nao e apenas codigo repetido. Tambem e:

- metadata diferente para o mesmo conceito;
- gate diferente para o mesmo risco;
- repair diferente para a mesma falha;
- contexto diferente para a mesma tarefa;
- evidencia diferente para o mesmo resultado.

O Atlas so fica forte quando esses conceitos tem um dono unico.

## Resumo

Documento fundador que define a regra de decisao entre capacidades horizontais do Atlas AI Core e logica especializada de dominios.

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

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
