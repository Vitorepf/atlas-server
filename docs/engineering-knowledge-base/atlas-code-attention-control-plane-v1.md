---
id: atlas-code-attention-control-plane-v1
type: engineering_knowledge
title: Atlas Code Attention Control Plane v1
status: active
category: programming-forge
priority: 100
summary: Canonical UX, read-model and implementation contract for the Atlas Desktop Atencao surface that serializes human decisions across multiple Programming Obras while Atlas works in parallel.
tags:
  - atlas-code
  - attention-control-plane
  - programming-obras
  - human-governance
  - ux
capabilities:
  - attention_control_plane
  - programming_obras_human_queue
  - multi_obra_attention_routing
  - human_decision_serialization
decisions:
  - Atencao must route decisions across Project-scoped Programming Obras, not across a global unscoped Obra list.
  - Atencao is a top-level Atlas Desktop surface next to Cartografia and Atlas Code.
  - Atencao does not replace Atlas Code; it routes human decisions across Programming Obras.
  - Atlas may execute multiple Obras in parallel, but the human attention flow must be serialized.
  - Every visible attention item must point to one Obra, one reason, one risk and explicit allowed actions.
  - Atencao must reduce cognitive load; it must not become another live execution dashboard.
maintenance:
  - Update before implementing or changing the Atlas Desktop Atencao surface, attention queue schema, human decision receipts, multi-Obra routing or anti-multitask UX.
related_paths:
  - docs/engineering-knowledge-base/atlas-code-multi-project-workspace-os.md
  - docs/engineering-knowledge-base/atlas-code-programming-obras-operating-system.md
  - docs/engineering-knowledge-base/atlas-code-obra-command-center-v1.md
  - docs/engineering-knowledge-base/atlas-code-forge-human-first-ux-orchestrator-v1.md
  - docs/engineering-knowledge-base/atlas-code-forge-work-intake-spec-governance-v1.md
  - docs/engineering-knowledge-base/atlas-code-forge-review-completion-gate-v1.md
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/atlas-ai-obras-operating-system.md
  - ../atlas-desktop/apps/desktop/src/surfaces/code/
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
owner: programming
layer: 2.3-attention-control-plane
line_limit: 520
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-code-attention-control-plane-v1
graph_title: Atlas Code Attention Control Plane v1
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-code-programming-obras-operating-system
graph_status: active
graph_source: repo
human_name: Atlas Code Attention Control Plane v1
canonical_name: Atlas Code Attention Control Plane v1
technical_name: atlas-code-attention-control-plane-v1
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-code-attention-control-plane-v1.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-code-attention-control-plane-v1.md
allowed_changes:
  - Refine routing, schema and UX when Atlas Code gains real multi-Obra attention runtime.
  - Add implementation links once service, controller, desktop surface and tests exist.
forbidden_changes:
  - Turn Atencao into terminal monitoring, provider monitoring or a generic notification inbox.
  - Show all running work as the primary experience.
  - Allow actions not present in allowed_actions.
  - Hide risk, evidence or missing evidence from human decisions.
  - Let Atlas make final value, risk, scope or acceptance decisions without human receipt.
depends_on:
  - atlas-code-multi-project-workspace-os
  - atlas-code-programming-obras-operating-system
  - atlas-code-obra-command-center-v1
  - atlas-code-forge-human-first-ux-orchestrator-v1
flows_to:
  - atlas-code
  - programming-obras-portfolio
  - human-decision-receipts
unlocks:
  - multi-obra-human-governance
  - anti-multitask-programming-flow
  - attention-safe-ai-software-production
governs:
  - atlas_desktop.atencao_surface
  - atlas_code.attention_queue
  - programming_obras.human_decision_flow
evidence:
  - docs/engineering-knowledge-base/atlas-code-attention-control-plane-v1.md
evidence_refs:
  - symbol: AtlasCodeAttentionControlPlaneService
  - test: AtlasCodeAttentionControlPlaneTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - atlas-code
  - atencao
  - attention-control-plane
ai_entrypoints:
  - Leia este doc antes de implementar a surface Atencao, fila humana multi-Obra ou qualquer UX de decisoes entre Obras.
ai_usage_notes:
  - Este doc e contrato de implementacao. O doc-mae explica a doutrina de Obras de Programacao.
quality_gates:
  - docs-health
  - serial-human-attention
  - allowed-actions-only
  - evidence-backed-decisions
failure_modes:
  - Criar uma dashboard bonita que exige que o humano acompanhe tudo.
  - Misturar decisao humana com log tecnico bruto.
  - Mostrar progresso sem distinguir prova, risco e pendencia.
  - Permitir que o humano aprove algo sem saber o risco e a evidencia.
observability_signals:
  - attention_queue_item_id
  - obra_id
  - attention_kind
  - severity
  - allowed_actions
  - chosen_action
  - human_decision_receipt_id
  - evidence_refs
  - dismissed_at
  - resolved_at
next_actions:
  - Implementar read-model de fila de atencao a partir dos estados atuais do Forge UX Orchestrator.
  - Criar surface Atencao no Atlas Desktop.
  - Adicionar testes de roteamento, actions permitidas e receipt humano.
---
# Atlas Code Attention Control Plane v1

## Resumo

Atencao e a surface do Atlas Desktop que protege o humano da multitarefa enquanto varias Obras de Programacao rodam. Ela nao mostra tudo. Ela mostra o que precisa de decisao humana agora.

Regra central:

```text
Atlas trabalha em paralelo.
O humano decide em serie.
Atencao e o roteador dessa serie.
```

## Papel no Atlas

Atencao fica no topo do Atlas Desktop ao lado de Cartografia e Atlas Code:

```text
Cartografia | Atlas Code | Atencao
```

Papel de cada surface:

- Cartografia: contexto, relacoes, mapa cognitivo e conhecimento estrutural.
- Atlas Code: cockpit profundo de uma Obra de Programacao.
- Atencao: fila humana serializada para decisoes entre varias Obras.

Atencao existe porque humanos nao devem operar como scheduler de agentes. O Atlas pode manter varias execucoes, provas e reparos em andamento; o humano deve receber apenas decisoes formuladas em linguagem de governo.

## Onde Se Encaixa

Atencao consome estados de:

- Obra Command Center;
- Forge UX Orchestrator;
- Work Intake;
- Review Completion Gate;
- Evidence Panel;
- provider, budget e runtime approval gates.

Atencao nao cria trabalho de programacao diretamente. Ela encaminha o humano para a Obra certa ou registra uma decisao que desbloqueia a Obra.

## Contratos

Item minimo de fila:

```text
attention_queue_item {
  id
  obra_id
  obra_title
  obra_phase
  obra_status
  kind
  severity
  human_question
  why_now
  recommended_action
  allowed_actions
  risk_if_ignored
  evidence_refs
  target_surface
  target_panel
  receipt_required
  expires_at
  created_at
}
```

Tipos canonicos:

- intake_needed: falta objetivo, regra, escopo ou aceite.
- scope_decision: escopo mudou ou esta ambivalente.
- risk_approval: ha risco tecnico, produto, custo, seguranca ou contrato.
- provider_approval: provider, modelo ou custo exige autorizacao.
- runtime_approval: ferramenta, terminal, filesystem, rede ou runtime exige autorizacao.
- review_needed: pacote esta pronto para revisao humana.
- repair_decision: gate falhou e precisa decidir reparar, rejeitar, pausar ou rollback.
- final_acceptance: provas existem e falta aceite final.
- blocked_attention: Atlas nao consegue traduzir proximo passo sem humano.

Acoes canonicas:

- open_obra;
- approve;
- reject;
- request_repair;
- pause;
- rollback;
- refine_intake;
- approve_scope_change;
- deny_scope_change;
- approve_provider;
- approve_runtime;
- dismiss_with_reason.

Toda acao que muda estado deve gerar `human_decision_receipt`.

## Fluxo

Fluxo principal:

1. Atlas atualiza estado de uma ou mais Obras.
2. Runtime deriva `attention_queue_item` somente quando ha decisao humana real.
3. Atencao ordena itens por severidade, prazo, bloqueio e impacto.
4. Surface mostra um foco principal e uma lista curta secundaria.
5. Humano decide usando apenas acoes permitidas.
6. Sistema grava receipt.
7. Obra e desbloqueada, pausada, rejeitada, reparada ou enviada para revisao.
8. Atencao escolhe a proxima decisao humana relevante.

Estados que normalmente geram atencao:

```text
intake_required
waiting_provider_confirmation
waiting_budget_confirmation
waiting_runtime_dispatch_confirmation
waiting_review
repair_required
blocked_needs_human
final_acceptance
```

Estados que nao devem gerar atencao por si so:

```text
running
waiting_worker
prepared
completed
idle
```

## Regras para IA

- Implementar em Atlas Desktop, nao em outro app.
- Nao remover Atlas Code nem Cartografia.
- Nao criar tela de logs como Atencao.
- Nao mostrar todos os terminais.
- Nao usar cards gigantes de marketing.
- Nao exigir que o humano entenda erro tecnico bruto.
- Traduzir bloqueio tecnico em pergunta humana clara.
- Mostrar uma recomendacao, mas preservar escolha humana.
- Bloquear qualquer action fora de `allowed_actions`.
- Exigir `evidence_refs` para review, repair decision e final acceptance.
- Registrar receipt para toda decisao mutante.
- Permitir abrir a Obra no Atlas Code quando detalhe profundo for necessario.

## Escopo de Implementacao

Surface minima:

- top-level tab `Atencao`;
- foco principal com uma decisao por vez;
- lista lateral curta de proximas decisoes;
- filtro por severidade e Obra;
- botao para abrir Obra no Atlas Code;
- actions renderizadas a partir de `allowed_actions`;
- painel de evidencias vinculadas;
- resumo do risco de ignorar;
- estado vazio saudavel quando nenhuma decisao humana e necessaria.

Layout minimo:

```text
Header: Atencao / quantidade / modo foco
Main: pergunta humana, contexto da Obra, risco, recomendacao, actions
Side: proximas decisoes agrupadas por prioridade
Bottom/Panel: evidencias e receipts recentes
```

Read-model minimo:

```text
attention_control_plane_read_model {
  generated_at
  active_focus_item
  queue_items
  resolved_recently
  obra_summary
  health
}
```

Health minimo:

- total_items;
- blocked_obras;
- waiting_human_count;
- stale_items_count;
- missing_evidence_count;
- unknown_state_count.

## Dependencias

Depende de:

- `atlas-code-programming-obras-operating-system`;
- `atlas-code-obra-command-center-v1`;
- `atlas-code-forge-human-first-ux-orchestrator-v1`;
- `atlas-code-forge-work-intake-spec-governance-v1`;
- `atlas-code-forge-review-completion-gate-v1`;
- `atlas-programming-forge-flow`.

## Evidencias

Base atual:

- Obra Command Center ja possui decision inbox, blockers traduzidos, fases e progresso separado.
- Forge UX Orchestrator ja possui estados de espera humana, bloqueio, review, reparo e finalizacao.
- Atlas Code desktop ja possui surface propria e pode ganhar top-level navigation sem confundir terminal com centro.
- O doc de Programming Obras ja define Atencao como surface complementar.

Evidencia exigida apos implementacao:

- teste de docs-health verde;
- teste de read-model com pelo menos tres Obras e uma decisao humana;
- teste impedindo action fora de `allowed_actions`;
- teste exigindo receipt em action mutante;
- screenshot ou verificacao visual mostrando que Atencao nao exibe terminal como elemento principal.

## Riscos

Riscos:

- Atencao virar dashboard de ansiedade.
- O humano voltar a monitorar varios fluxos.
- Itens sem evidencia parecerem prontos para aceite.
- Recomendacao do Atlas parecer decisao obrigatoria.
- Actions genericas permitirem estados invalidos.

Mitigacoes:

- um foco principal por vez;
- lista secundaria curta;
- `allowed_actions` obrigatorio;
- receipts obrigatorios;
- evidence refs obrigatorias para decisoes finais;
- botao "abrir Obra no Atlas Code" para detalhe profundo, sem encher a tela de Atencao.

## Exemplos

Item de review:

```text
kind: review_needed
human_question: "A Obra Completion Audit pode ir para aceite?"
why_now: "Build, testes e docs-health passaram; falta revisao humana."
recommended_action: approve
allowed_actions: [open_obra, approve, request_repair, reject]
evidence_refs: [test_run_id, docs_health_run_id, review_packet_id]
```

Item de risco:

```text
kind: risk_approval
human_question: "Autorizar mudanca em contrato publico?"
why_now: "A solucao reduz duplicacao, mas altera schema consumido por outra surface."
recommended_action: open_obra
allowed_actions: [open_obra, approve_scope_change, deny_scope_change, pause]
```

## Proximas Acoes

1. Criar service de read-model `AtlasCodeAttentionControlPlaneService`.
2. Criar endpoint para a surface Atencao.
3. Criar top-level tab `Atencao` no Atlas Desktop.
4. Implementar focus item, queue curta, evidencias e actions permitidas.
5. Adicionar testes de schema, roteamento, actions e receipts.
