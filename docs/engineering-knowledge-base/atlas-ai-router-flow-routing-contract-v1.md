---
id: atlas-ai-router-flow-routing-contract-v1
type: engineering_knowledge
title: Atlas AI Router · Flow Routing Contract v1
status: draft
category: programming
priority: 110
summary: Contrato canonico do Atlas AI Router. Atlas AI e o produto/superficie unica do programador; o Router e a camada acima dos fluxos especializados (Atlas Dev, Atlas Research, Atlas Explain, Atlas Debug, Atlas Review, Atlas Conversation, Atlas Forge, futuros QA/Security/DB/Design) que decide qual fluxo atende cada pedido. Este doc define inputs, outputs, flow_ids canonicos, tabela de roteamento e invariantes de nao-acoplamento. Nao define runtime nem implementa codigo.
tags:
  - atlas-ai
  - atlas-ai-router
  - flow-routing
  - routing-contract
  - flow-id
  - command-intent
capabilities:
  - atlas_ai_router_flow_decision
  - flow_id_taxonomy
  - command_intent_taxonomy
  - routing_reason_telemetry
  - delegate_to_other_flow
decisions:
  - Atlas AI Router e a unica camada autorizada a decidir flow_id; fluxos individuais nunca decidem fluxo global.
  - O Router roteia para fluxos especializados; ele nao executa trabalho de fluxo.
  - Atlas Dev nao deve absorver Research, Explain, Debug, Conversation; cada fluxo tem responsabilidade unica.
  - Quando um fluxo recebe payload errado, deve devolver `delegate_to_other_flow` em vez de tentar atender.
  - Router opera surface-agnostico; Desktop-first nao implica Desktop-coupled.
  - flow_origin diferencia decisao automatica (router) de override explicito do operador (slash command, dropdown).
maintenance:
  - Atualize este doc quando entrar um flow_id novo, quando a tabela de roteamento mudar, ou quando uma invariante de nao-acoplamento mudar.
  - Nao adicione schema runtime, services, controllers ou endpoints aqui; pertence ao runbook do Router ou aos contracts dos fluxos individuais.
  - Nao adicione tabela de R-levels, prompt projection ou rendered_prompt_text aqui; pertence aos contracts do fluxo alvo.
  - Nao adicione benchmark, Rivals, Opus challenge ou medicao competitiva.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-conversation-surface-and-atlas-dev-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-ai-router-flow-routing-contract-v1
graph_title: Atlas AI Router · Flow Routing Contract v1
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-ai-canonical-architecture-index
graph_status: active
graph_source: repo
owner: programming
allowed_changes:
  - Adicionar um flow_id novo com responsabilidade unica, payload de handoff e linha na tabela de roteamento.
  - Refinar command_intent e routing_reason quando aparecer telemetria real.
  - Atualizar a tabela de roteamento quando um sinal de input ficar mais discriminativo.
repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-router-flow-routing-contract-v1.md
depends_on:
  - atlas-ai-canonical-architecture-index
  - atlas-ai-spec-operating-system
  - atlas-ai-conversation-surface-and-atlas-dev-v1
  - atlas-dev-efficient-programming-flow-v1
  - atlas-dev-flow-map-and-product-options-v1
  - atlas-forge-operating-system
flows_to:
  - atlas_dev
  - atlas_research
  - atlas_explain
  - atlas_debug
  - atlas_review
  - atlas_conversation
  - atlas_forge
unlocks:
  - atlas_ai_router_runtime
  - flow_handoff_payload_schema
  - routing_telemetry
governs:
  - atlas_ai.router.flow_decision
  - atlas_ai.router.handoff_payload
  - atlas_ai.router.delegate_to_other_flow
evidence:
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-conversation-surface-and-atlas-dev-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
next_actions:
  - Quando o Router virar implementacao, criar `atlas-ai-router-runbook-v1.md` com services, endpoints e testes.
  - Quando novos fluxos (QA/Security/DB/Design) saírem do papel, registrar `flow_id`, payload de handoff e linha na tabela.
  - Cobrir telemetria de routing_reason em painel Desktop apos o runtime do Router existir.
observability_signals:
  - flow_id
  - flow_origin
  - command_intent
  - routing_reason
  - routing_confidence
forbidden_changes:
  - Mover qualquer responsabilidade de execucao (provider call, patch, retrieval, verification) para o Router. Router so decide.
  - Permitir que um fluxo (Atlas Dev, Forge, etc) decida o flow_id de um pedido novo. So o Router decide.
  - Listar prompt projection, rendered_prompt_text, R-levels ou DTOs detalhados aqui. Esses contratos vivem nos docs dos fluxos.
  - Introduzir benchmark, Rivals, Opus challenge, baterias de prompts ou score competitivo.
required_tests:
  - "tests/Unit/Ai/Router/AtlasAiRouterContractTest.php (quando o runtime existir)"
requires_evidence: true
risk_level: high
line_limit: 520
---
# Atlas AI Router · Flow Routing Contract v1

## Resumo

Atlas AI e o produto/superficie unica do programador. **Atlas AI Router** e a camada acima dos fluxos especializados que recebe cada pedido (surface payload + raw intent + estado de thread) e devolve **qual fluxo deve atender**. Router decide, fluxo executa.

Este doc define:

1. Papel do Router e o que ele **nao** faz.
2. Inputs canonicos (o que entra na decisao).
3. Outputs canonicos (`flow_id`, `flow_origin`, `command_intent`, `routing_reason`, `routing_confidence`, handoff payload).
4. Lista inicial de `flow_id`s e seu dono.
5. Tabela de roteamento com sinais discriminativos.
6. Invariantes de nao-acoplamento.

Nao define runtime, services, endpoints, DTOs detalhados, prompt projection, R-levels ou benchmark. Esses contratos vivem nos docs dos fluxos.

## Onde Se Encaixa

Este modulo fica entre a superficie unica Atlas AI e os fluxos especializados. E parte da camada de decisao do kernel Atlas AI, antes de qualquer fluxo workspace-bound, provider-bound ou Obra-driven executar trabalho. O Router recebe envelopes normalizados das surfaces, escolhe o `flow_id` canonico e entrega um handoff pequeno para o fluxo dono. Atlas Dev, Forge, Research, Explain, Debug, Review e Conversation continuam donos da execucao dentro de seus contratos.

- **Parent**: `atlas-ai-canonical-architecture-index` define o lugar do Router dentro do produto Atlas AI.
- **Vizinhos imediatos**:
  - `atlas-ai-spec-operating-system` define a fronteira spec-vs-runtime. O Router e runtime de decisao; nao mexe em spec.
  - `atlas-ai-conversation-surface-and-atlas-dev-v1` define a surface (Atlas AI Desktop Mac, CLI, App, API) que produz o payload que entra no Router.
  - `atlas-dev-flow-map-and-product-options-v1` enumera os fluxos do produto; este doc seleciona um deles por pedido.
- **Filhos / handoff targets**:
  - `atlas-dev-efficient-programming-flow-v1` (`atlas_dev`) — flow workspace-bound de desenvolvimento.
  - `atlas-forge-operating-system` (`atlas_forge`) — flow Obra-driven.
  - Fluxos ainda sem contracts doc proprio (`atlas_research`, `atlas_explain`, `atlas_debug`, `atlas_review`, `atlas_conversation`) entram aqui ate ganharem doc proprio.
- **Persistencia**: cada decisao do Router e auditavel via `trace_id` / `thread_id`; o storage canonico fica no kernel do Atlas AI (vide `atlas-ai-kernel-architecture`), nao neste doc.
- **Limite**: o Router nao toca em retrieval, provider, gates, scope guard, verification, patch. Esses pertencem aos fluxos.

## Contratos

Os contratos abaixo sao os unicos que o Router governa. Tudo o que e detalhe interno de um fluxo (R-levels, mini-spec, task contract, prompt projection, receipts) vive no contracts doc daquele fluxo.

- **Entrada**: envelope provider-safe com `surface_id`, `surface_context`, `workspace`, `raw_intent`, `attachments`, `thread_context` e `operator_decision_mode` (detalhe em **Inputs Canonicos**).
- **Saida**: `RouterDecision` com `flow_id`, `flow_origin`, `command_intent`, `routing_reason`, `routing_confidence`, `handoff_payload` e `alternative_flow_ids` (detalhe em **Outputs Canonicos**).
- **Tabela de roteamento**: ver **Tabela De Roteamento** abaixo — primeira-match decide.
- **Delegate**: fluxo que recebe payload errado devolve `FlowDelegation` (detalhe em **Delegate To Other Flow**).
- **Invariante**: Router decide fluxo; fluxo executa trabalho. Nenhum fluxo decide globalmente o `flow_id` de um pedido novo.
- **Evidencia futura**: quando o runtime existir, testes `tests/Unit/Ai/Router/*` devem provar a tabela de roteamento, os fallbacks e o `delegate_to_other_flow`.

## Papel No Atlas

```text
Atlas AI  (produto / superficie unica do programador)
  └─ Atlas AI Router   ← este doc
        ├─ Atlas Dev          (desenvolvimento em workspace)
        ├─ Atlas Research     (pesquisa conceitual / aprendizado)
        ├─ Atlas Explain      (explicacao de codigo/arquitetura sem patch)
        ├─ Atlas Debug        (logs/traces/erros sem patch obrigatorio)
        ├─ Atlas Review       (review profunda de diff/PR)
        ├─ Atlas Conversation (chat exploratorio)
        ├─ Atlas Forge        (Obra-driven multiagente longa)
        └─ futuros: QA / Security / DB / Design
```

O Router e **a camada de decisao** que pluga `Atlas AI` em qualquer numero de fluxos sem que a surface (Desktop/CLI/App/API) precise saber quais existem.

## Papel Do Router

Faz:

- Recebe o pedido completo da surface.
- Lê sinais de input (mode/task dropdown, slash command, workspace presence, raw intent, attachments, thread context).
- Decide um unico `flow_id` (com `flow_origin`, `command_intent`, `routing_reason`, `routing_confidence`).
- Monta o handoff payload canonico do fluxo escolhido.
- Persiste a decisao para auditoria/telemetria.

Nao faz:

- Nao chama provider.
- Nao faz retrieval.
- Nao roda gates, scope guard, verification.
- Nao aplica patch.
- Nao decide R-level, prompt projection, prompt artesanal.
- Nao acopla a superficies especificas (Desktop, CLI, App, API).

## Inputs Canonicos

Todo pedido chega ao Router com este envelope minimo:

| Campo | Origem | Uso na decisao |
| --- | --- | --- |
| `surface_id` | adapter da surface | telemetria + override de defaults por surface |
| `surface_context.composer_mode` | dropdown da surface | sinal forte (programming/operational/general) |
| `surface_context.composer_task` | dropdown da surface | sinal forte (dev/debug/review/plan/direct) |
| `surface_context.slash_command` | input do operador | override explicito do flow_id |
| `workspace` | adapter | binding para fluxos workspace-bound (Dev/Debug/Review/Explain) |
| `workspace_present` | derivado | gate para Atlas Dev e qualquer fluxo que exija worktree |
| `raw_intent` | texto bruto do operador | classificacao linguistica |
| `normalized_intent` | normalizador | matching estavel |
| `attachments` | adapter | sinais de payload (diff/PR -> review, logs -> debug, doc/URL -> research) |
| `thread_context` | conversation surface | continuidade de fluxo, ancora de delegate_to_other_flow |
| `operator_decision_mode` | dropdown | atlas_decide vs manual_override |

Sinais derivados (computados pelo Router antes da decisao):

- `has_diff_or_pr_attachment`
- `has_log_or_stacktrace_attachment`
- `has_url_or_document_attachment`
- `intent_class` (patch_like, explain_like, debug_like, research_like, review_like, conversation_like)
- `workspace_bound` (intent menciona arquivo, simbolo, comando, teste)

## Outputs Canonicos

A decisao do Router e um objeto pequeno e estavel:

```yaml
RouterDecision:
  flow_id: atlas_dev | atlas_research | atlas_explain | atlas_debug | atlas_review | atlas_conversation | atlas_forge
  flow_origin: router_auto | operator_override | slash_command | thread_continuity
  command_intent: patch | repair | refactor | codegen | explain | research | debug | review | promote_to_forge | converse
  routing_reason: string                # frase curta auditavel ("workspace presente + intent patch_like")
  routing_confidence: confirmed | strong | inferred | low
  handoff_payload: object               # payload canonico do fluxo escolhido (definido pelo doc daquele fluxo)
  alternative_flow_ids: list            # fluxos candidatos descartados (telemetria)
```

Notas:

- `flow_origin = operator_override` exige sinal explicito (dropdown, slash command) e suprime classificacao linguistica.
- `flow_origin = thread_continuity` so se aplica quando o pedido entra dentro de uma thread cujo `flow_id` ja foi definido e o operador nao mudou de mode/task.
- `routing_confidence = low` deve disparar UX de confirmacao na surface (ex: oferecer 2 fluxos lado a lado), nao decisao silenciosa.
- `handoff_payload` e definido pelo contracts doc do fluxo alvo; o Router nao inventa schema.

## Flow IDs Iniciais

| flow_id | Fluxo | Workspace-bound | Patch | Provider call | Dono / contracts doc |
| --- | --- | --- | --- | --- | --- |
| `atlas_dev` | desenvolvimento em workspace (patch/repair/refactor/codegen) | sim | sim | sim | `atlas-dev-efficient-programming-flow-v1.md` (+ contracts/runbook) |
| `atlas_research` | pesquisa conceitual / aprendizado, possivelmente sem workspace | nao | nao | sim | a definir (sucessor de Research no `atlas-dev-flow-map`) |
| `atlas_explain` | explicacao de codigo/arquitetura sem patch | parcial (lê workspace, nao escreve) | nao | sim | a definir |
| `atlas_debug` | logs/traces/erros sem patch obrigatorio | parcial | nao por default | sim | a definir |
| `atlas_review` | review profunda de diff/PR | depende (PR remoto pode nao ter workspace) | nao | sim | a definir; reaproveita parte do Atlas Dev review |
| `atlas_conversation` | chat exploratorio, baseline Atlas AI conversation surface | nao | nao | sim | `atlas-ai-conversation-surface-and-atlas-dev-v1.md` |
| `atlas_forge` | Obra-driven multiagente longa, governado | sim | sim | sim | `atlas-forge-operating-system.md` |
| futuros: `atlas_qa`, `atlas_security`, `atlas_db`, `atlas_design` | dominio especializado | a definir | a definir | a definir | docs novos quando o fluxo virar prioridade |

Cada `flow_id` e estavel — versionamento de schema acontece dentro do contracts doc daquele fluxo.

## Tabela De Roteamento

Sinais ordenados por forca (de cima para baixo). O primeiro match define a decisao:

| Sinal | Decisao | command_intent default | routing_confidence |
| --- | --- | --- | --- |
| `slash_command in (/dev, /research, /explain, /debug, /review, /forge, /chat)` | flow_id correspondente | mapeado do slash | `confirmed`, `flow_origin = slash_command` |
| `composer_mode = programming` + `composer_task in (dev, debug, review, plan)` + `workspace_present = true` | mapeado direto: dev→`atlas_dev`, debug→`atlas_debug`, review→`atlas_review`, plan→`atlas_dev` plan-only | conforme task | `confirmed`, `flow_origin = operator_override` |
| `has_diff_or_pr_attachment = true` | `atlas_review` | `review` | `strong` |
| `has_log_or_stacktrace_attachment = true` | `atlas_debug` | `debug` | `strong` |
| `intent_class = patch_like` + `workspace_present = true` | `atlas_dev` | `patch` ou `repair` ou `refactor` ou `codegen` conforme verbo | `strong` |
| `intent_class = explain_like` + `workspace_bound = true` | `atlas_explain` | `explain` | `strong` |
| `intent_class = explain_like` + `workspace_bound = false` | `atlas_research` | `research` | `inferred` |
| `intent_class = research_like` (conceitual, doc, framework, padrao) | `atlas_research` | `research` | `strong` |
| `intent_class = debug_like` sem workspace | `atlas_research` (com `handoff_payload.debug_hint = true`) | `research` | `inferred` |
| `intent_class = review_like` sem diff/PR | `atlas_explain` ou `atlas_conversation` conforme `workspace_bound` | conforme | `inferred` |
| `intent_class = conversation_like` (saudacao, brainstorm, livre) | `atlas_conversation` | `converse` | `strong` |
| `thread_context.flow_id` ja definido + pedido sem override | mesmo flow_id (`flow_origin = thread_continuity`) | herdado | herdado |
| Operador declara Obra (`/forge`, "vou criar uma Obra", `composer_task = plan` + escopo R4+) | `atlas_forge` (ou `atlas_dev` com `forge_promotion_preview`) | `promote_to_forge` | `confirmed` ou `strong` |
| Nenhum sinal forte | `atlas_conversation` (fallback honesto) com `routing_confidence = low` | `converse` | `low` |

Regras complementares:

- `workspace_present = false` **bloqueia** `atlas_dev` por invariante 6 do Atlas Dev (`programming` exige workspace). O Router deve degradar para `atlas_explain` ou `atlas_research` conforme intent_class e marcar `routing_reason` com a razao do bloqueio.
- `intent_class = patch_like` sem workspace **nunca** vai para `atlas_dev`. Vai para `atlas_research` ou `atlas_conversation` com `routing_reason = "patch_like sem workspace"`.
- Promocao explicita para Forge (operador pede Obra) **nao** e decidida pelo Router sozinho — o Router roteia para `atlas_dev` com `forge_promotion_preview` ou diretamente para `atlas_forge` somente se `flow_origin = operator_override`.

## Invariantes

1. **Router decide, fluxo executa.** Nenhum fluxo (Atlas Dev, Forge, etc) escolhe `flow_id` para um pedido novo. So o Router escolhe.
2. **Fluxos sao especializados.** Atlas Dev nao deve absorver Research, Explain, Debug ou Conversation. Cada fluxo tem responsabilidade unica e contracts doc proprio.
3. **Delegate explicito.** Se um fluxo recebe um handoff cujo conteudo nao bate com sua responsabilidade, deve devolver `delegate_to_other_flow = <flow_id_correto>` com motivo. Nao deve tentar atender silenciosamente.
4. **Workspace gate.** `atlas_dev` exige `workspace_present = true`. Sem workspace, Router roteia para `atlas_explain` ou `atlas_research`.
5. **Provider-safe boundary.** Router opera sobre payload provider-safe (sem prompt artesanal, sem trace interno, sem secret). Handoff payload e tambem provider-safe ou marcado para projecao.
6. **Surface-agnostico.** Router nunca depende de tipos Desktop/CLI/App/API. Inputs entram normalizados via adapter; output e estavel para qualquer surface.
7. **Desktop-first nao implica Desktop-coupled.** A primeira surface a consumir o Router e Atlas AI Desktop Mac, mas CLI/App/API consomem o mesmo contrato.
8. **flow_origin auditavel.** Toda decisao registra `flow_origin` (`router_auto`, `operator_override`, `slash_command`, `thread_continuity`). Override silencioso (mudar `flow_id` apos decisao sem novo evento) e proibido.
9. **Telemetria minima.** Cada decisao emite `flow_id`, `flow_origin`, `command_intent`, `routing_reason`, `routing_confidence`. Sem isso nao tem como medir "estou roteando bem?".
10. **Sem benchmark interno.** Router nao roda Rivals, Opus challenge ou score competitivo. Avaliacao competitiva e trabalho de outro fluxo/agente.

## Delegate To Other Flow

Quando um fluxo recebe um pedido fora do escopo dele, devolve:

```yaml
FlowDelegation:
  reason: string                       # motivo curto auditavel
  delegate_to_other_flow: atlas_dev | atlas_research | ...
  handoff_payload_override: object|null
  preserve_thread: boolean             # default true
```

Exemplos:

- Atlas Dev recebe pedido de explicacao pura ("o que faz esse arquivo?") sem patch -> `delegate_to_other_flow = atlas_explain`.
- Atlas Conversation recebe diff colado -> `delegate_to_other_flow = atlas_review`.
- Atlas Research recebe pedido de patch concreto em arquivo workspace-bound -> `delegate_to_other_flow = atlas_dev` (so se workspace_present).

O Router re-recebe o pedido com o novo `flow_id` e registra `routing_reason = "delegated_by:<flow_origem>"` para fechar o ciclo de auditoria.

## Fluxo

```text
surface payload
   │
   ▼
Atlas AI Router
   │  collect_inputs() · normalize_intent() · classify_signals()
   │  decide_flow_id() · build_handoff_payload() · persist_decision()
   ▼
flow alvo (atlas_dev | atlas_research | atlas_explain | atlas_debug | atlas_review | atlas_conversation | atlas_forge)
   │  may return: FlowDelegation -> back to Router
   ▼
artefatos canonicos do fluxo (definidos pelo contracts doc daquele fluxo)
```

## Regras Para IA

- Antes de implementar feature nova de roteamento, IA leu este doc inteiro.
- IA nao adiciona logica de decisao em um fluxo individual: leva para o Router.
- IA nao expande Atlas Dev para cobrir Research/Explain/Debug/Conversation: cria fluxo novo com contracts doc proprio.
- IA registra `routing_reason` em frase curta auditavel, nao em codigo opaco.
- IA preserva surface-agnosticismo: se o Router precisar conhecer detalhe de Desktop/CLI/App/API, o detalhe deve passar pelo adapter, nao entrar no nucleo.

## Escopo De Implementacao

Este doc e **contratual**. Implementacao do runtime do Router (services, endpoints, persistencia, telemetria) e trabalho de um runbook futuro (`atlas-ai-router-runbook-v1.md`).

O que ja existe hoje no codebase, fora do escopo deste doc:

- Adapters de surface que constroem o `OperationEnvelope` (vide `AtlasDesktopAiSurfaceAdapter.php` e similares).
- `AtlasDevRuntimeService` no fluxo Atlas Dev.
- `surface_id`, `composer_mode`, `composer_task`, `flow_id` ja circulam em payloads e metadata.

O que falta para o Router virar runtime:

- Servico canonico `AtlasAiRouterService` que recebe envelope normalizado e devolve `RouterDecision`.
- Persistencia auditavel de decisao por trace_id/thread_id.
- Painel Desktop opcional de telemetria de roteamento (`routing_reason`, `routing_confidence`, `alternative_flow_ids`).

## Dependencias

- `atlas-ai-canonical-architecture-index` define onde o Router se encaixa no kernel.
- `atlas-ai-spec-operating-system` define a fronteira spec-vs-runtime.
- `atlas-dev-efficient-programming-flow-v1` define o handoff payload e invariants do flow `atlas_dev`.
- `atlas-forge-operating-system` define handoff e contratos do `atlas_forge`.
- `atlas-ai-conversation-surface-and-atlas-dev-v1` define como o flow `atlas_conversation` se comporta.

## Evidencias

Evidencia primaria: este doc + os contracts docs dos fluxos listados na tabela de flow_ids. Quando o runtime existir, evidencia secundaria sera `tests/Unit/Ai/Router/*` e logs de `flow_id`/`routing_reason` persistidos.

## Riscos

- **Drift Router vs fluxo.** Adicionar campo no `RouterDecision` sem atualizar fluxos consumidores quebra handoff. Mitigacao: bump de schema do Router (`atlas.ai.router.flow_decision.v<n>`) com leitores migrados antes.
- **Fluxo absorvendo responsabilidade alheia.** Atlas Dev historicamente foi tentado como porta unica; este doc bloqueia. Mitigacao: invariant 2 e teste de regressao quando o runtime existir.
- **Telemetria opaca.** `routing_reason` sem texto auditavel torna analise impossivel. Mitigacao: required field, nao opcional.
- **Decisao silenciosa.** Router decidir um fluxo e o frontend assumir outro. Mitigacao: Desktop e demais surfaces leem `flow_id` do `RouterDecision`, nao deduzem.

## Exemplos

### Exemplo 1 · patch_like com workspace

```yaml
input:
  surface_id: atlas_desktop_ai
  composer_mode: programming
  composer_task: dev
  workspace: /Users/op/code/atlas-server
  raw_intent: "corrija o teste falhando em AtlasCliDevWorkflowServiceTest"
decision:
  flow_id: atlas_dev
  flow_origin: operator_override
  command_intent: repair
  routing_reason: "composer_mode=programming + composer_task=dev + workspace presente"
  routing_confidence: confirmed
```

### Exemplo 2 · pesquisa conceitual sem workspace

```yaml
input:
  surface_id: atlas_desktop_ai
  composer_mode: general
  composer_task: direct
  workspace: null
  raw_intent: "explique o algoritmo de Raft e quando usar vs Paxos"
decision:
  flow_id: atlas_research
  flow_origin: router_auto
  command_intent: research
  routing_reason: "intent_class=research_like + workspace_present=false"
  routing_confidence: strong
```

### Exemplo 3 · explicacao de arquivo (workspace presente, sem patch)

```yaml
input:
  surface_id: atlas_desktop_ai
  composer_mode: programming
  composer_task: direct
  workspace: /Users/op/code/atlas-server
  raw_intent: "explica como o AtlasDevRuntimeService monta o flow_id"
decision:
  flow_id: atlas_explain
  flow_origin: router_auto
  command_intent: explain
  routing_reason: "intent_class=explain_like + workspace_bound=true + sem verbo de patch"
  routing_confidence: strong
```

### Exemplo 4 · diff colado para review

```yaml
input:
  surface_id: atlas_desktop_ai
  raw_intent: "olha esse diff e vê se quebra alguma coisa"
  attachments:
    - kind: diff
      ref: "uploads/diff-1234.patch"
decision:
  flow_id: atlas_review
  flow_origin: router_auto
  command_intent: review
  routing_reason: "has_diff_or_pr_attachment=true"
  routing_confidence: strong
```

### Exemplo 5 · stacktrace colado, sem workspace

```yaml
input:
  surface_id: atlas_desktop_ai
  workspace: null
  raw_intent: "qual a causa provavel desse erro?"
  attachments:
    - kind: stacktrace
      content: "PHP Fatal error: Uncaught TypeError…"
decision:
  flow_id: atlas_debug
  flow_origin: router_auto
  command_intent: debug
  routing_reason: "has_log_or_stacktrace_attachment=true; workspace ausente, sem patch"
  routing_confidence: strong
```

### Exemplo 6 · chat livre

```yaml
input:
  surface_id: atlas_desktop_ai
  composer_mode: general
  raw_intent: "bom dia, como vai o atlas hoje"
decision:
  flow_id: atlas_conversation
  flow_origin: router_auto
  command_intent: converse
  routing_reason: "intent_class=conversation_like; sem sinais workspace/diff/log"
  routing_confidence: strong
```

## Proximas Acoes

- Quando o runtime do Router for priorizado, criar `atlas-ai-router-runbook-v1.md`.
- Quando `atlas_research`/`atlas_explain`/`atlas_debug`/`atlas_review` saírem do papel, cada um ganha um contracts doc proprio definindo handoff payload e invariants.
- Quando QA/Security/DB/Design entrarem, adicionar linha na tabela de flow_ids e linha na tabela de roteamento.
