---
id: atlas-ai-conversation-surface-and-atlas-dev-v1
type: engineering_knowledge
title: Atlas AI Conversation Surface And Atlas Dev v1
status: active
category: programming-forge
priority: 101
summary: Contrato canonico que conecta Atlas AI mobile, Atlas Desktop, Atlas Dev e Atlas Forge: uma unica inteligencia com modos, threads, workspaces, dev/debug/review e promocao para Obra quando o trabalho exige governanca pesada.
tags:
  - atlas-ai
  - atlas-app
  - atlas-mobile
  - atlas-dev
  - atlas-code
  - programming
  - workspace
capabilities:
  - atlas_ai_conversation_surface
  - atlas_dev_daily_programming
  - mobile_desktop_thread_continuity
  - dev_to_forge_promotion
  - programming_mode_contract
decisions:
  - Existe um unico Atlas AI; Geral, Operacional, Programacao, Projeto e Revisao sao modos/focos, nao IAs separadas.
  - Atlas Dev e o modo de programacao diaria do Atlas AI para bugs, features pequenas/medias, debug, review, pesquisa tecnica e conversa tecnica.
  - Atlas Forge/Obras e a promocao governada para tarefas ultra-hard, longas, arriscadas, multiagentes ou com contexto pesado.
  - Atlas Desktop deve reaproveitar threads, modos, workspace e metadata do Atlas AI mobile em vez de criar chat paralelo.
  - Uma conversa Atlas Dev pode nascer no mobile, continuar no Mac/Desktop/CLI e virar Candidato de Obra ou Obra.
maintenance:
  - Atualize este doc antes de alterar AtlasAiSheet, atlasAiModeContract, atlasAiThreadRouting, atlasAiDomainCatalog, mobileThreadBridge, Atlas Desktop consultas, Atlas Dev ou promocao Dev -> Forge.
related_paths:
  - ../atlas-app/components/sheets/AtlasAiSheet.tsx
  - ../atlas-app/components/sheets/atlas-ai/AtlasAiContextModel.ts
  - ../atlas-app/components/sheets/atlas-ai/ThreadHistorySheet.tsx
  - ../atlas-app/components/sheets/atlas-ai/threadHistoryModel.ts
  - ../atlas-app/lib/atlasAiModeContract.ts
  - ../atlas-app/lib/atlasAiThreadRouting.ts
  - ../atlas-app/lib/atlasAiDomainCatalog.ts
  - ../atlas-app/lib/mobileThreadBridge.ts
  - ../atlas-app/lib/api/client.ts
  - ../atlas-app/app/mobile-thread.tsx
  - ../atlas-server/app/Http/Controllers/AiInteractionController.php
  - ../atlas-server/app/Http/Controllers/AiThreadController.php
  - docs/engineering-knowledge-base/atlas-code-multi-project-workspace-os.md
  - docs/engineering-knowledge-base/atlas-code-programming-obras-operating-system.md
  - docs/engineering-knowledge-base/atlas-code-attention-control-plane-v1.md
  - docs/engineering-knowledge-base/atlas-programming-self-construction-forge-map-v1.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-ai-conversation-surface-and-atlas-dev-v1
graph_title: Atlas AI Conversation Surface And Atlas Dev v1
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-operating-system
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-conversation-surface-and-atlas-dev-v1.md
allowed_changes:
  - Refinar nomes de UI e contratos quando Atlas Desktop implementar a surface de conversa.
  - Adicionar schemas de API/read-model quando a continuidade mobile/desktop estiver implementada.
forbidden_changes:
  - Criar chat de programacao separado que nao use AiThread, metadata, workspace e Atlas AI routing.
  - Tratar Atlas Dev como Obra obrigatoria para toda correcao pequena.
  - Fixar Claude, Codex ou Gemini como papel permanente.
depends_on:
  - atlas-ai-operating-system
  - atlas-code-multi-project-workspace-os
  - atlas-code-programming-obras-operating-system
  - atlas-programming-self-construction-forge-map-v1
flows_to:
  - atlas-ai-mobile
  - atlas-desktop-conversation
  - atlas-dev
  - atlas-forge
  - atlas-code
unlocks:
  - mobile_to_desktop_dev_continuity
  - project_scoped_consultations
  - quick_programming_without_obra
  - dev_to_forge_promotion
governs:
  - atlas_ai.thread_modes
  - atlas_dev.daily_programming
  - atlas_desktop.conversation_surface
  - atlas_code.dev_to_forge_promotion
evidence:
  - atlas-app/lib/atlasAiModeContract.ts
  - atlas-app/lib/atlasAiThreadRouting.ts
  - atlas-app/lib/atlasAiDomainCatalog.ts
  - atlas-app/components/sheets/AtlasAiSheet.tsx
  - atlas-app/components/sheets/atlas-ai/AtlasAiContextModel.ts
  - atlas-app/components/sheets/atlas-ai/ThreadHistorySheet.tsx
  - atlas-app/lib/mobileThreadBridge.ts
  - atlas-server/app/Http/Controllers/AiInteractionController.php
  - atlas-server/app/Http/Controllers/AiThreadController.php
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia este doc antes de implementar Consultas, Atlas AI no Desktop, Atlas Dev, fluxo mobile->Mac, bugs rapidos, chat tecnico de programacao ou promocao para Obra.
ai_usage_notes:
  - Este doc governa a camada antes da Obra. Para execucao pesada, leia tambem Atlas Code Programming Obras Operating System e Forge docs.
quality_gates:
  - single_atlas_ai_identity
  - project_workspace_bound
  - thread_continuity_preserved
  - dev_not_forced_into_obra
  - forge_promotion_available
failure_modes:
  - Duplicar historico entre app mobile e desktop.
  - Criar chat generico sem workspace/projeto.
  - Obrigar uma Obra para corrigir cor de botao, texto, bug simples ou pequena feature.
  - Deixar Atlas Dev interativo mais fraco que `atlas dev "prompt"`.
  - Perder o contexto quando a conversa muda de mobile para Mac.
observability_signals:
  - thread_id
  - current_mode
  - routing_task
  - routing_domain
  - workspace
  - surface_id
  - flow_id
  - provider
  - atlas_workflow_mode
  - promotion_target
next_actions:
  - Implementar no Atlas Desktop uma surface Atlas AI/Atlas Dev que usa as mesmas AiThreads do mobile.
  - Adicionar seletor de Projeto/Workspace na conversa.
  - Criar acoes Promover para Atlas Dev, Intervencao Rapida, Candidato de Obra e Obra Forge.
  - Sincronizar status de sessoes CLI/observed provider com historico mobile e desktop.
line_limit: 520
---
# Atlas AI Conversation Surface And Atlas Dev v1

## Resumo

Atlas ja tem, no mobile, a base certa para a camada antes da Obra:

```text
Atlas AI = inteligencia unica do produto.
Atlas Dev = modo Programacao do Atlas AI para trabalho diario de software.
Atlas Forge / Obra = promocao para trabalho dificil, longo, arriscado ou multiagente.
```

Nao se cria Obra para mudar cor de botao, corrigir bug pequeno, pesquisar uma
duvida tecnica ou conversar sobre arquitetura. Isso entra em Atlas AI/Atlas Dev.
Se crescer em risco, tamanho, dependencia, evidencia ou duracao, Atlas promove
para Intervencao Rapida, Candidato de Obra ou Obra Forge.

## Papel no Atlas

Atlas Code continua tendo prioridade maxima: ser a ferramenta mais poderosa do
mundo para programacao pesada, problemas ultra-hard e sessoes longas. Atlas Dev
e a camada diaria que compete com Claude Code, Codex e Gemini para bugs,
features simples/medias, debug, review, pesquisa tecnica e entendimento de
codigo. Forge/Obras e o patamar acima, com governanca, contexto profundo,
multi-provider, gates e aceite humano.

```text
Atlas AI / Atlas Dev
  -> conversa tecnica, pesquisa, debug, bug, feature, review
  -> prepara candidato quando o trabalho cresce

Atlas Forge / Obras
  -> problema ultra-hard, sessao longa, evidencia, varios providers, gates
```

## Onde Se Encaixa

```text
Projeto/Workspace
-> Atlas AI Conversation Surface
-> Atlas Dev Runtime
-> Intervencao Rapida / Candidato de Obra
-> Atlas Forge / Obra de Programacao
-> Attention Control Plane quando varias Obras rodam
```

Atlas App mobile e Atlas Desktop devem usar a mesma fonte de verdade:
`ai_threads`, `ai_traces`, metadata, workspace, session state, compaction,
handoff e Domain Catalog. Desktop nao deve criar outro historico de conversa.

## Contratos

Contrato de identidade:

- existe um unico Atlas AI;
- Geral, Operacional, Programacao, Projeto e Revisao sao modos/focos;
- Atlas Dev e Programacao dentro do Atlas AI;
- providers sao executores substituiveis, nao papeis fixos.

Contrato mobile ja existente em `atlasAiModeContract.ts`:

```text
mode: general | operational | programming
task: direct | plan | review | dev | debug
domain: auto | atlas | vault-curador | saude | blackink | financas
executor: auto | claude_cli | codex_cli | gemini_cli | claude_codex
```

No modo `programming`, o contrato exige plano, testes ou motivo, diff ou motivo
e resumo de risco. O runtime declara `capability_profile=atlas_programming`,
`permission_mode=danger`, workspace obrigatorio e artefatos esperados:
`plan`, `diff_or_reason`, `tests_or_reason`, `risks`.

Contrato de flows em `atlasAiDomainCatalog.ts`:

```text
general/direct      -> general.answer
operational/review  -> operations.diagnostic
programming/direct  -> programming.dev
programming/plan    -> programming.dev
programming/dev     -> programming.dev
programming/review  -> programming.review
programming/debug   -> programming.repair
```

Contrato de surface:

```text
surface_id=atlas_app ou atlas_desktop_ai -> conversa/Atlas Dev pode existir sem Obra.
surface_id=atlas_code -> Forge/Atlas Code exige Obra.
```

## Fluxo

Fluxo diario:

```text
Selecionar Projeto/Workspace
-> abrir Atlas AI / Atlas Dev
-> enviar pergunta, bug, feature, review ou debug
-> Atlas usa programming.dev/review/repair quando o modo for Programacao
-> registra thread, trace, provider, workspace, diff/testes ou motivo
-> continua no mobile, Desktop ou CLI
```

Fluxo de promocao:

```text
Atlas Dev thread
-> Intervencao Rapida quando e pequeno mas executavel
-> Candidato de Obra quando precisa de estruturacao
-> Obra Forge quando exige governanca pesada
```

O mobile ja tem ponte por `mobileThreadBridge.ts`: `threadId` abre uma thread e
`inboxId + action=discuss` cria/abre conversa operacional. O Desktop deve
expandir isso para Programacao: uma thread iniciada no celular pode abrir no Mac
como Atlas Dev no workspace correto e, se necessario, promover para Obra.

## Regras para IA

1. Nao crie "outro chat" se `ai_threads` resolve.
2. Nao force Obra para bug pequeno, ajuste visual simples ou conversa tecnica.
3. Nao deixe Atlas Dev sem Projeto/Workspace quando a tarefa for de codigo.
4. Nao copie o fluxo mobile para desktop criando storage paralelo.
5. Nao fixe Claude, Codex ou Gemini como papel permanente.
6. Preserve thread, metadata, workspace, flow, provider history e traces.
7. Promova para Forge quando a conversa deixou de ser simples.

## Escopo de Implementacao

Meta 6: Atlas AI Conversation Surface.

- listar/criar/abrir `ai_threads`;
- filtrar por projeto/workspace/modo;
- composer com anexos;
- modos `general/operational/programming`;
- tarefas `direct/plan/review/dev/debug`;
- provider auto/manual;
- stream por trace;
- compaction e provider handoff;
- continuidade mobile <-> desktop;
- acoes de promocao.

Meta 7: Atlas Dev Runtime.

- workspace obrigatorio para programacao;
- flows `programming.dev`, `programming.review`, `programming.repair`;
- Open Brain/context injection;
- plano, diff ou motivo, testes ou motivo, riscos;
- terminal no workspace correto;
- execucao curta/media sem exigir Obra;
- evidence suficiente para retomada;
- integracao com CLI/observed provider quando necessario.

Meta 8: Dev-to-Forge Promotion.

- detectar sinais de promocao;
- gerar resumo forte da thread;
- criar Intervencao Rapida ou Candidato de Obra;
- promover para Obra com workspace, contexto, criterios e evidence;
- manter link reverso para thread original;
- explicar ao humano o motivo da promocao.

## Dependencias

Dependencias de codigo atuais:

- `AtlasAiSheet.tsx`: composer, anexos, envio, routing, provider, streaming.
- `atlasAiModeContract.ts`: contrato de modos, tarefas e runtime programming.
- `atlasAiThreadRouting.ts`: inferencia e persistencia de modo/foco/provider.
- `atlasAiDomainCatalog.ts`: mapa UX -> flow.
- `ThreadHistorySheet.tsx` e `threadHistoryModel.ts`: historico, filtros e CLI em curso.
- `AtlasAiContextModel.ts`: promocao operacional -> Programacao.
- `mobileThreadBridge.ts` e `app/mobile-thread.tsx`: deep link para thread.
- `AiThreadController.php`: threads, compaction, handoff, snapshots.
- `AiInteractionController.php`: interacoes, anexos, Domain Catalog e binding de Obra.

Docs dependentes: `atlas-code-multi-project-workspace-os.md`,
`atlas-code-programming-obras-operating-system.md`,
`atlas-code-attention-control-plane-v1.md`,
`atlas-programming-self-construction-forge-map-v1.md` e
`open-brain-context-injection.md`.

## Evidencias

Evidencia da varredura no mobile/backend:

- `programming` permite `plan/review/dev/debug` e defaulta para `dev`.
- `atlas_workflow_mode=dev` e `routing_task=dev/debug` viram Programacao.
- `programming/debug` mapeia para `programming.repair`.
- `AtlasAiSheet.tsx` envia `atlas_focus`, `atlas_workflow_mode`,
  `routing_task`, `routing_domain`, `flow_id`, `surface_id`, provider e runtime.
- Historico mobile filtra `Geral`, `Operacional` e `Programacao`.
- Historico mobile detecta sessoes CLI recentes como "Em curso".
- Backend lista threads por `workspace`, compacta thread e troca provider.
- Backend exige Obra apenas quando a surface e `atlas_code`.

## Riscos

- Duplicar historico entre mobile e Desktop.
- Criar chat generico sem workspace/projeto.
- Obrigar Obra para pequenas correcoes na Blackink.
- Deixar Atlas Dev interativo mais fraco que `atlas dev "prompt"`.
- Perder contexto ao trocar de mobile para Mac.
- Fazer Desktop ignorar Domain Catalog e inventar roteamento proprio.

## Exemplos

| Situacao | Surface correta |
|---|---|
| "Por que esse componente esta estranho?" | Atlas Dev |
| "Corrige a cor do botao na Blackink" | Atlas Dev ou Intervencao Rapida |
| "Implementa uma feature pequena/media" | Atlas Dev |
| "Preciso entender arquitetura do modulo" | Atlas Dev |
| "Vamos pesquisar solucoes antes de desenhar arquitetura" | Atlas AI / Atlas Dev |
| "Bug critico envolvendo backend, app, pagamento e dados" | Candidato de Obra -> Obra |
| "Reestruturar auth inteira" | Obra Forge |
| "Rodar varios providers em paralelo com evidence/gates" | Obra Forge |

Fluxo Blackink:

```text
Selecionar Projeto: Blackink
-> abrir Atlas AI / Atlas Dev
-> pedir correcao pequena
-> Atlas usa workspace Blackink e flow programming.dev
-> registra trace, diff/testes ou motivo
-> promove apenas se o trabalho crescer
```

## Proximas Acoes

1. Implementar no Atlas Desktop a surface Atlas AI/Atlas Dev usando `ai_threads`.
2. Adicionar seletor de Projeto/Workspace na conversa.
3. Criar acoes de promocao para Intervencao Rapida, Candidato de Obra e Obra.
4. Sincronizar sessoes CLI/observed provider com historico mobile e desktop.
5. Garantir que cada mensagem de programacao passe por policy, Decide, contexto e gates adequados.
