---
id: atlas-code-human-interface-upgrade-v2
type: engineering_knowledge
title: Atlas Code Human Interface Upgrade v2
status: active
category: programming-forge
priority: 100
summary: Upgrade UX humano do Atlas Code · estado humano único + blockers traduzidos + Forge cockpit humano + Definir como contrato + Revisar só com decisão real + Provas separadas + chat com papel + centro com resumo da Obra.
tags:
  - atlas
  - atlas-code
  - forge
  - ux
  - human-interface
capabilities:
  - atlas_code_human_interface_v2
  - blocker_translation_canonical
  - completion_gating_canonical
  - chat_message_role_classification
decisions:
  - O humano é diretor/validador, não operador de motor técnico. Atlas executa, verifica, coleta evidência, bloqueia, pede decisão.
  - Estado humano canônico tem prioridade fixa - blocked > review_required > running > prepared > ready_to_define > idle > completed.
  - Se fast_path está queued/running mas live_execution está blocked, a UI mostra blocked. Nunca running.
  - Blocker técnico nunca aparece cru ao humano; sempre traduzido (governed_execution_exception, files_outside_task_contract, queue stale, etc).
  - Approve/Reject/Rollback só renderizam quando completionGating.*_button_visible é true.
  - Provas separa Obra desta sessão vs Certificações do sistema Atlas vs Histórico técnico.
  - Chat classifica cada mensagem em Definição/Comando/Pergunta/Decisão/Nota e mostra o efeito esperado.
  - Centro da tela renderiza resumo humano da Obra quando não há mensagens; nunca fica vazio sem motivo.
maintenance:
  - Atualize quando AtlasCodeForgeUxOrchestratorService crescer estados/blockers ou quando completionGating mudar.
related_paths:
  - docs/engineering-knowledge-base/atlas-code-forge-human-first-ux-orchestrator-v1.md
  - docs/engineering-knowledge-base/atlas-forge-continuum-os.md
  - docs/engineering-knowledge-base/atlas-code-forge-operator-cockpit-v1.md
allowed_changes:
  - Adicionar novo estado humano canônico ao state machine (Service + tipo TS + audit).
  - Adicionar novo blocker_kind ao resolveBlockerTranslation.
  - Adicionar novo CHAT_KIND ao classificador.
  - Adicionar nova seção ao centro da tela ou ao Forge cockpit, desde que a UX continue legível em 6/10.
forbidden_changes:
  - Mostrar approve/reject/rollback antes de review_required.
  - Esconder blocker técnico do diagnóstico — só esconder o crú da camada humana.
  - Tornar primary CTA ambígua (sempre 1 botão claro por estado).
  - Mostrar IDs/run_ids/commands/queue-names na camada humana.
  - Permitir que primary CTA dispare provider real direto.
  - Misturar certificação do sistema com prova desta Obra.
depends_on:
  - atlas-code-forge-human-first-ux-orchestrator-v1
  - atlas-code-forge-operator-cockpit-v1
  - atlas-forge-continuum-os
flows_to:
  - atlas-code-forge-operator-cockpit-v1
unlocks:
  - human_first_atlas_code_runtime_6_of_10
governs:
  - atlas_code_desktop_right_rail
  - atlas_code_desktop_main_stage
  - atlas_code_desktop_chat_composer
  - atlas_code_forge_primary_action
next_actions:
  - Adicionar telemetria de tempo gasto em cada estado humano.
  - Adicionar A2 spec (captures + AA contrast) do ForgeHumanPanel.
  - Integrar `fix_scope` com fluxo Definir > Pode mexer (sugestão pré-preenchida).
  - Mensagens auto-enviar dispatchador quando chat detecta /forge run/prepare.
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-code-human-interface-upgrade-v2
graph_title: Atlas Code Human Interface Upgrade v2
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-code-forge-human-first-ux-orchestrator-v1
graph_status: active
graph_source: repo
human_name: Atlas Code Human Interface Upgrade v2
canonical_name: Atlas Code Human Interface Upgrade v2
technical_name: atlas-code-human-interface-upgrade-v2
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/atlas-code-human-interface-upgrade-v2.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-code-human-interface-upgrade-v2.md
evidence:
  - docs/engineering-knowledge-base/atlas-code-human-interface-upgrade-v2.md
required_tests:
  - "php artisan test --filter=AtlasCodeForgeUxOrchestrator"
  - "php artisan atlas:programming:completion-audit --workspace=. --json"
requires_evidence: true
risk_level: medium
---
# Atlas Code Human Interface Upgrade v2

> Schema canônico: `atlas.code.forge_ux_orchestrator.v1`
> Audit cert: `atlas.code.forge_human_first_ux_certification.v1` (extensão v2)
> Status: implementado · 2026-05-14

## Resumo

Upgrade UX humano em cima do runtime governado existente. O humano deixa de
ser operador de motor técnico (intake/fast path/dispatch/invocation/review/
completion/capacity/trust ledger) e passa a ser diretor/validador.

A interface responde sempre:
1. **O que está acontecendo?** estado humano canônico + label/detail humanos.
2. **Por que parou?** blocker traduzido (`blocker_translation.kind/human_title/human_detail`).
3. **O que decidir?** `completion_gating.*_button_visible` e Revisar tab.
4. **Qual o próximo botão seguro?** `primary_action_label/_kind/_enabled`.

## Papel no Atlas

Camada UX sobre [[atlas-code-forge-human-first-ux-orchestrator-v1]]. Não
substitui runtime, governance, review gate, provider invocation, drivers
reais. Encarna a regra: humano define, autoriza, revisa. Atlas executa,
verifica, registra prova, bloqueia honestamente.

## Onde Se Encaixa

- Acima de [[atlas-code-forge-operator-cockpit-v1]] (cockpit técnico vira `Avançado`).
- Acima de [[atlas-forge-continuum-os]] (continuum emite os metadados, UX traduz).
- Espelha [[atlas-programming-governance]] (review gate, completion claim, provider invocation).
- Convive com [[project-atlas-spec-operating-system]] (Spec OS gera Spec/Plan governados).

## Contratos

Idênticos aos v1, reforçados:

- Provider externo nunca chamado em testes.
- Tokens nunca gastos sem 3 confirmações + capacity + driver + dispatch plan.
- Completion claim nunca auto-promovido. Review gate sempre presente.
- External rivals certification continua `blocked_requires_operator_approval`.
- Allowlist + Symfony Process array nos drivers reais.
- `no_obra` é fail-closed. Obra criada apenas via Left Rail `CreateObraControl`.
- Voice / Cartografia / Self-construction não foram tocados.

## Fluxo

```
Usuário cria Obra (Left Rail)
        │
        ▼
Forge Orchestrator resolve estado canônico
        │
   ┌────┼─────┬──────────────┬──────────────┐
   ▼    ▼     ▼              ▼              ▼
no_obra ready_to_define running    blocked_*    waiting_review
        │                  │              │
        │                  │              │
        ▼                  ▼              ▼
   primary CTA       suggested_action   approve/reject/rollback
   "Completar         "Corrigir escopo"  (gated por completionGating)
   Definição"
```

## Regras para IA

- Antes de inventar estado: passe pelo `resolveState` do Orchestrator (16+ estados canônicos + prioridade).
- Antes de criar tab nova: confirme que ela pertence à camada humana ou vai para `Avançado`.
- Antes de chamar provider real: 3 confirmações explícitas em `Avançado > Provider Invocation`.
- Antes de mostrar approve/reject/rollback: confirme `completionGating.*_button_visible === true`.
- Quando blocker técnico aparecer: traduza via `resolveBlockerTranslation` ou adicione caso novo.
- Quando centro estiver vazio: renderize `ObraSummaryCenter` lendo `forgeUxOrchestrator`.

## Escopo de Implementacao

Backend (`atlas-server`):

- `AtlasCodeForgeUxOrchestratorService` — state machine v2 + prioridade canônica
- `resolveState()` — blocked > review_required > running > prepared > ready_to_define > idle > completed
- `classifyExecutionBlocked()` — out_of_scope → `blocked_scope`, governance → `blocked_governance`, etc.
- `resolveBlockerTranslation()` — emite kind + human_title + human_detail + suggested_action_label/kind + technical_detail + files_out_of_scope
- `definitionStatus()` — pronto / incompleto / blocking_execution
- `queueStaleSeconds()` + state `waiting_worker` quando fila trava ≥ 90s
- Snapshot expõe `completion_gating`, `evidence_separation`, `chat_message_kinds`

Desktop (`atlas-desktop`):

- `ForgeHumanPanel` consome `blockerTranslation` (CTA dinâmica + arquivos out_of_scope + technical details collapsed)
- `ForgeWorkIntakePanel` renomeia labels enterprise para forma humana (O que você quer? / Regra que não pode quebrar / Como saberemos / Pode mexer / Não pode mexer)
- `VerifyPanel` gateia approve/reject/rollback pelo `completionGating`
- `EvidencePanel` separa "Provas desta Obra" / "Certificações do sistema" / "Histórico técnico"
- `ConversationPanel.ObraSummaryCenter` renderiza resumo humano quando centro está vazio
- `ComposerPanel` classifica mensagem em Definição/Comando/Pergunta/Decisão/Nota e mostra efeito esperado

## Dependencias

- [[atlas-code-forge-human-first-ux-orchestrator-v1]] (base v1)
- [[atlas-code-forge-operator-cockpit-v1]] (cockpit técnico preservado em Avançado)
- [[atlas-forge-continuum-os]] (consumo dos metadados)
- [[atlas-programming-governance]] (review gate / completion claim contratos)

## Evidencias

```bash
# CLI estrito (sem obra → exit 1)
php artisan atlas:code:forge-ux --json --strict

# CLI com obra
php artisan atlas:code:forge-ux --obra=<uuid> --json

# HTTP
curl -s "$ATLAS_SERVER/atlas-code/works/<project>/forge/ux-orchestrator" | jq

# Audit cert v2
php artisan atlas:programming:completion-audit --workspace=. --json \
  | jq '.atlas_code_forge_human_first_ux_certification.invariants'

# Testes
php artisan test --filter='AtlasCodeForgeUx|AtlasCodeForgeFastPath|AtlasCodeForgeReviewCompletion|AtlasCodeContract'

# Desktop
npm run lint --workspace=@atlas/desktop
npm run build --workspace=@atlas/desktop
cargo check -p atlas-tauri
```

## Riscos

- **Médio**: classificador de chat erra papel — operador pode enviar pensando que vira Comando e ficar como Nota. Mitigação: chip é visível antes do envio e o efeito esperado também.
- **Médio**: `waiting_worker` falso-positivo quando relógio do worker está dessincronizado. Mitigação: limiar de 90s.
- **Alto (evitado)**: UX simplificar segurança — approve sem revisão, completion sem gate, provider direto pela CTA. Mitigação: invariantes da audit cert (`completion_gating_visible`, `no_external_provider_auto_call`, `no_completion_claim_auto_promotion`, `review_gate_preserved`).

## Exemplos

### Forge cockpit humano resolve "Bloqueado por escopo"

```bash
$ curl -s "$ATLAS/atlas-code/works/<id>/forge/ux-orchestrator" | jq '.state, .blocker_translation'
"blocked_scope"
{
  "kind": "blocked_scope",
  "human_title": "Bloqueado por escopo",
  "human_detail": "A execucao tentou alterar src/forbidden.ts.",
  "suggested_action_label": "Corrigir escopo",
  "suggested_action_kind": "fix_scope",
  "technical_detail": "out_of_scope_write, governed_execution_exception",
  "files_out_of_scope": ["src/forbidden.ts"],
  "is_blocking": true
}
```

### Stale queue vira waiting_worker

```bash
$ curl -s "$ATLAS/atlas-code/works/<id>/forge/ux-orchestrator" | jq '.state, .next_safe_step'
"waiting_worker"
"A fila atlas-code-forge ainda nao processou (queued ha 180s). Aguarde ou inicie o worker em Avancado."
```

### Approve/Reject permanecem ocultos antes de review

```bash
$ curl -s "$ATLAS/atlas-code/works/<id>/forge/ux-orchestrator" | jq '.completion_gating'
{
  "review_required": false,
  "final_completion_allowed": false,
  "approve_button_visible": false,
  "reject_button_visible": false,
  "rollback_button_visible": false
}
```

## Proximas Acoes

Veja `next_actions` no frontmatter.
