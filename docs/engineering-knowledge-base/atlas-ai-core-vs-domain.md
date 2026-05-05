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
  - Profiles sao contratos operacionais de dominio/fluxo, nao presets de modelo.
  - Super Tool Runtime pertence ao Core; dominios consomem suas evidencias e definem criterios.
maintenance:
  - Atualizar quando uma capability mudar de domain para Core ou de Core para domain.
  - Usar este documento antes de criar service, command ou module novo.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-vision.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-architecture-audit.md
  - docs/engineering-knowledge-base/atlas-ai-resolver-corpus-audit.md
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
| Domain Profile | `programming`, `finance`, `personal_development` | Core Profile Resolver |
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

| Domain | Responsabilidades |
|---|---|
| Programming | bug, feature, refactor, review, QA, testes, release, migrations, code intelligence e engineering harness. |
| Personal Development | habitos, foco, performance, saude, rotina, intervencoes, measurement harness e memoria pessoal. |
| Finance | mercado, risco, tese, operacao, compliance, data provenance e audit trail financeiro. |
| Curation | detectar lacunas, duplicacao, regressao de processo, proposta de melhoria e medicao. |

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
