---
id: atlas-code-obra-command-center-v1
type: engineering_knowledge
title: Atlas Code Obra Command Center v1
status: active
category: programming-forge
priority: 100
summary: Command Center humano-first do Atlas Code · lifecycle canonico de 8 fases, progresso duplo (preparacao vs entrega comprovada), decision inbox, blocker translation, operational health honesto, trust summary, evidence digest, chat com classificacao ampliada (7 papeis) e safety strip — substitui o centro vazio por uma cabine que responde em 30s as 7 perguntas do diretor da Obra.
tags:
  - atlas
  - atlas-code
  - forge
  - ux
  - command-center
  - obra
capabilities:
  - atlas_code_obra_command_center
  - obra_lifecycle_progress
  - obra_decision_inbox
  - obra_operational_health
  - obra_trust_summary
decisions:
  - O humano e diretor da Obra; Atlas e executor governado. UI principal nunca expoe JSON, IDs ou logs como experiencia primaria.
  - Lifecycle canonico de uma Obra tem 8 fases (intake, architecture, forge_prep, build, review, proofs, decision, learning), cada uma com status humano, evidence_count, blocker_count e next_action.
  - Progresso e declarado em duas dimensoes separadas (readiness_progress vs proven_delivery_progress); progresso global enganoso e proibido.
  - Decision inbox lista decisoes humanas pendentes com risk e allowed_actions; sem decisao pendente, decision_inbox e uma lista vazia honesta.
  - Operational health expoe queue/worker/heartbeat honestamente; campos nao instrumentados aparecem como `unknown`, jamais verde fake.
  - Blockers tecnicos vivem em Avancado; UI principal mostra blocker_translation com kind, human_title, human_detail, suggested_action_label, files_out_of_scope e technical_detail.
  - Chat composer classifica draft em 7 papeis (definicao, comando, pergunta, decisao, restricao, criterio de aceite, nota) com efeito esperado mostrado antes do envio.
maintenance:
  - Atualize quando AtlasCodeObraCommandCenterService, controller, CLI ou painel canonico mudarem.
  - Mantenha consistencia com atlas-code-human-interface-upgrade-v2 e atlas-code-forge-human-first-ux-orchestrator-v1.
related_paths:
  - docs/engineering-knowledge-base/atlas-code-forge-human-first-ux-orchestrator-v1.md
  - docs/engineering-knowledge-base/atlas-code-human-interface-upgrade-v2.md
  - docs/engineering-knowledge-base/atlas-forge-continuum-os.md
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/domains/programming-professional-completion-audit.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-code-obra-command-center-v1
graph_title: Atlas Code Obra Command Center v1
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-code-forge-human-first-ux-orchestrator-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-code-obra-command-center-v1.md
  - app/Services/Ai/Programming/AtlasCodeObraCommandCenterService.php
  - app/Http/Controllers/AtlasCodeObraCommandCenterController.php
  - app/Console/Commands/AtlasCodeObraCommandCenterCommand.php
allowed_changes:
  - Adicionar campos ao schema atlas.code.obra_command_center.v1 desde que sejam derivados de read-model existente.
  - Adicionar fases canonicas ao lifecycle apenas se o ciclo Forge crescer realmente.
  - Estender a classificacao de chat com novos papeis honestos quando o dominio crescer.
forbidden_changes:
  - Chamar provider externo a partir do Command Center.
  - Inventar progresso sem evidencia (sempre derivar de signals do read-model).
  - Promover completion claim a partir do Command Center.
  - Esconder blockers (todo blocker tecnico deve ser traduzido, nunca mascarado).
  - Misturar provas desta Obra com certificacoes do sistema Atlas.
  - Mostrar running enquanto live_execution esta blocked.
  - Reduzir gates de governanca (review, completion, capacity, driver).
  - Tocar Voice, Cartografia ou external_rivals_certification a partir do Command Center.
depends_on:
  - atlas-code-forge-human-first-ux-orchestrator-v1
  - atlas-code-human-interface-upgrade-v2
  - atlas-forge-continuum-os
flows_to:
  - atlas-code
  - atlas-forge-continuum-os
  - atlas-programming-forge-flow
unlocks:
  - obra_command_center_8_5_of_10
governs:
  - atlas_code_obra_command_center
evidence:
  - docs/engineering-knowledge-base/atlas-code-obra-command-center-v1.md
  - app/Services/Ai/Programming/AtlasCodeObraCommandCenterService.php
required_tests:
  - "php artisan test --filter=AtlasCodeObraCommandCenterTest"
  - "php artisan test --filter=AtlasCodeForgeUxOrchestratorTest"
  - "php artisan atlas:code:obra-command-center --json --strict"
  - "php artisan atlas:programming:completion-audit --json"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: medium
next_actions:
  - Telemetria real de queue/worker (preencher operational_health.queue_status/worker_status/heartbeat_status sem cair em unknown).
  - A11y do ObraCommandCenterPanel (AA contrast + captures em viewport 393).
  - Decision inbox interativo (clicar em decisao abre tab correspondente com payload preparado).
  - fix_scope flow integrado com Definir > Pode mexer (one-click add).
---

# Atlas Code Obra Command Center v1

> Schema canonico: `atlas.code.obra_command_center.v1`
> Audit cert: `atlas.code.obra_command_center_certification.v1`
> Status: implementado · 2026-05-14

## Resumo
Cabine humana central da Obra. Substitui o "centro vazio" do Atlas Code por uma tela viva com lifecycle de 8 fases, progresso duplo (preparacao vs entrega comprovada), decision inbox, operational health honesto, trust summary, evidence digest e safety strip. Objetivo declarado: subir a UX humana de ~6.3/10 para pelo menos 8.5/10, preservando seguranca (provider externo, tokens, completion claim, review gate, external rivals e governance continuam intocaveis).

## Papel no Atlas
Promove o centro da tela a fonte primaria de verdade humana. O usuario e diretor, validador e dono do risco da Obra; Atlas e executor governado. A primeira viewport deve responder em 30 segundos: o que estamos construindo, onde estamos, esta rodando ou bloqueado, por que, qual o proximo passo seguro, o que ja foi provado e se precisa de decisao humana.

## Onde Se Encaixa
Filho de [[atlas-code-forge-human-first-ux-orchestrator-v1]] (v1) e [[atlas-code-human-interface-upgrade-v2]] (v2). Consome read-models de [[atlas-forge-continuum-os]] (intake, fast path, live execution, runtime dispatch, provider topology, capacity, review packet, completion claim). Convive com [[atlas-programming-forge-flow]] como fluxo canonico. Auditado em `atlas_code_obra_command_center_certification` dentro do [[atlas-ai-programming-professional-completion-audit]].

## Contratos
Schema: `atlas.code.obra_command_center.v1`. Campos canonicos emitidos pelo `AtlasCodeObraCommandCenterService`: `schema_version`, `status` (`ok`|`blocked`|`no_obra`), `generated_at`, `obra_id`, `obra_title`, `objective_summary`, `human_status_label`, `human_status_detail`, `current_phase`, `next_phase`, `next_safe_action`, `primary_action_kind`, `primary_action_label`, `primary_action_enabled`, `primary_action_disabled_reason`, `lifecycle_phases[]`, `milestones[]`, `readiness_progress`, `proven_delivery_progress`, `decision_inbox[]`, `blocker_summary`, `blocker_translation`, `operational_health`, `trust_summary`, `evidence_digest`, `provider_summary`, `safety_summary`, `advanced_refs`, `chat_message_kinds`, `external_provider_call=false`, `provider_tokens_spent=false`, `completion_claim_promoted=false`, `review_gate_preserved=true`, `separated_from='external_rivals_certification'`. Endpoint: `GET /api/atlas-code/works/{project}/obra-command-center`. State agregada: campo `obra_command_center` em `GET /api/atlas-code/works/{project}/state`. CLI: `php artisan atlas:code:obra-command-center --obra=<uuid> --json --strict`.

## Fluxo
1. Usuario seleciona ou cria Obra no rail esquerdo. 2. Backend consolida intake + fast_path + live_execution + runtime_dispatch + provider_topology + capacity + provider_invocation + review_packet + completion_claim em um snapshot do Forge UX Orchestrator. 3. Command Center service envolve o snapshot e deriva lifecycle (8 fases), milestones, readiness vs delivery, decision inbox, blocker translation, operational health, trust summary, evidence digest. 4. Desktop renderiza ObraCommandCenterPanel no centro quando `messages.length === 0`. 5. Operador age via primary CTA unica; provider externo continua exigindo confirmacoes explicitas. 6. Cada fase canonica progride apenas com evidencia real (review_status, completion_status, evidence_ref_count).

## Regras para IA
Nunca chamar provider externo a partir do Command Center. Nunca promover completion claim. Nunca bypassar review humano. Nunca mascarar blockers — traduzir tecnicamente para humano via blocker_translation. Nunca criar Obra silenciosa. Nunca tocar Voice, Cartografia ou external_rivals_certification. Nunca misturar provas desta Obra com Certificacoes do sistema Atlas. Nunca mostrar running enquanto live_execution esta blocked. Nunca mover logica de governanca para frontend; UI so renderiza estado governado.

## Escopo de Implementacao
- Service: `app/Services/Ai/Programming/AtlasCodeObraCommandCenterService.php` (read-model puro, consome Forge UX Orchestrator).
- Controller: `app/Http/Controllers/AtlasCodeObraCommandCenterController.php` (GET endpoint).
- CLI: `app/Console/Commands/AtlasCodeObraCommandCenterCommand.php` (fail-closed em --strict sem Obra).
- Audit hook: `ProgrammingProfessionalCompletionAuditService::atlasCodeObraCommandCenterCertification()` (25 invariantes).
- Painel Desktop: `atlas-desktop/apps/desktop/src/surfaces/code/stage/ObraCommandCenterPanel.tsx`.
- Conversa/Centro: `atlas-desktop/apps/desktop/src/surfaces/code/stage/ConversationPanel.tsx` (renderiza o panel quando Obra ativa).
- Composer chat 7 kinds: `atlas-desktop/apps/desktop/src/surfaces/code/stage/ComposerPanel.tsx`.
- Types canonicos: `atlas-desktop/packages/atlas-domain/src/index.ts` (interface `AtlasCodeObraCommandCenter`).

## Dependencias
[[atlas-code-forge-human-first-ux-orchestrator-v1]] (v1 base), [[atlas-code-human-interface-upgrade-v2]] (v2 base), [[atlas-forge-continuum-os]] (read-models), [[atlas-programming-forge-flow]] (fluxo canonico). Audit final via [[atlas-ai-programming-professional-completion-audit]].

## Evidencias
- `php artisan test --filter=AtlasCodeObraCommandCenterTest` — 100% verde.
- `php artisan atlas:code:obra-command-center --json --strict` — exit 1 sem Obra, exit 0 com Obra `ok`.
- `php artisan atlas:programming:completion-audit --json` — `atlas_code_obra_command_center_certification.status = available`.
- `php artisan atlas:engineering:knowledge docs-health --json` — sem violacoes.
- Build desktop verde, lint 0 erros, cargo check tauri verde.

## Riscos
1. Esconder estado real do backend (UI mostrar verde enquanto runtime esta bloqueado); mitigado por blocker_translation obrigatorio e by `operational_health.queue_status='unknown'` honesto. 2. Inflar trust_summary com evidencia fake; mitigado por derivacao direta de signals do read-model + missing_evidence honesto. 3. Mover decisoes humanas (approve/reject/rollback) para autonomia; mitigado por `completion_gating` herdado do v2 + decision_inbox como recomendacao, nao automacao. 4. Misturar provas desta Obra com certificacoes do sistema; mitigado por `evidence_digest.system_certifications_separated=true` e header explicito no EvidencePanel.

## Exemplos
```
# Diagnostico humano da Obra ativa
php artisan atlas:code:obra-command-center --obra=$OBRA_UUID

# CI / strict (fail-closed sem Obra ou bloqueada)
php artisan atlas:code:obra-command-center --json --strict

# API
curl -sH "X-Atlas-Token: $ATLAS_TOKEN" \
  http://localhost:8080/api/atlas-code/works/$OBRA_UUID/obra-command-center | jq
```
ObraCommandCenterPanel renderiza automaticamente no centro do Atlas Code Desktop quando ha Obra ativa e o chat esta vazio.

## Proximas Acoes
Telemetria real de queue/worker (sair de `unknown` honesto so quando houver instrumentacao). A11y do ObraCommandCenterPanel (AA contrast + captures em viewport 393). Decision inbox interativo. fix_scope flow integrado com Definir > Pode mexer.
