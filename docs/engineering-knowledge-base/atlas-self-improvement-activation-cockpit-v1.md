---
id: atlas-self-improvement-activation-cockpit-v1
type: engineering_knowledge
title: Atlas Self-Improvement Activation Cockpit v1
status: active
category: self-construction
priority: 100
summary: Cockpit humano que torna o fluxo Self-Improvement → Forge Activation visível no Atlas Code. Read-model canônico + 2 mutações governadas (accept/reject com reviewer + reason + checkbox). Nunca executa Fast Path, nunca chama provider, nunca promove completion claim, nunca desbloqueia external_rivals_certification.
tags:
  - atlas
  - self-improvement
  - self-construction
  - forge
  - activation
  - cockpit
  - human-first
  - ux
  - governance
capabilities:
  - self_improvement_activation_cockpit
  - activation_list_and_filters
  - human_translated_power_gate
  - before_snapshot_visualisation
  - approval_receipt_visibility
  - created_obra_open_action
  - origin_badge_in_forge_intake
  - trust_ledger_aggregation
  - strategy_portfolio_visibility
decisions:
  - Cockpit é pura projeção (read-model) — toda mutação reusa accept/reject da v1.
  - UI bloqueia accept sem reviewer + reason + checkbox "não executa Fast Path automaticamente".
  - UI bloqueia reject sem reviewer + reason.
  - Power Gate exibe traduções humanas curtas para cada outcome (approved/needs_revision/human_review_required/rejected).
  - Before Snapshot mostra maturidade, invariant lock, regression sentinel, strategy bucket, trust band e docs canônicas, sempre com hash e tom editorial (rec-red/bronze/moss).
  - Approval Receipt mostra reviewer + reason + receipt_hash + proposal_hash + power_gate_hash visíveis ao operador.
  - Obra criada mostra obra_id, título, intake status, objetivo, business rule, contagem de acceptance criteria e canonical docs, com botão "Abrir Obra no Forge" que apenas troca o contexto — NUNCA executa Fast Path.
  - State projection `self_improvement_activation` permanece a fonte da badge "Criada por Self-Improvement Activation" no ForgeWorkIntakePanel.
  - Activation cockpit nunca cria Obra silenciosa; o cockpit é leitura + mutação humana explícita.
  - Tauri commands nativos são fornecidos (bridge_list/get/create/accept/reject_self_improvement_forge_activation); HTTP serve como fallback transparente.
  - Strategy Portfolio bucket é sempre apresentado junto com balance_health e recommended_next_bucket, sem bloquear activation.
  - Trust Ledger band (high_trust / medium_trust / low_trust / low_trust_overreach / low_trust_too_conservative / insufficient_data) é exibido como diagnóstico, nunca como autorização.
maintenance:
  - Atualize antes de mexer em cockpit service, controller, CLI, certification, painel, bridge actions, Tauri commands, adaptadores ou domain types.
  - Mantenha como autoridade do contrato visual; service e tests vencem texto aspiracional.
  - Quando v1 do underlying activation service mudar, refletir aqui antes de tocar a UI.
related_paths:
  - docs/engineering-knowledge-base/atlas-self-improvement-forge-activation-v1.md
  - docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md
  - docs/engineering-knowledge-base/atlas-forge-continuum-os.md
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/atlas-code-forge-human-first-ux-orchestrator-v1.md
  - docs/engineering-knowledge-base/domains/programming-professional-completion-audit.md
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementActivationCockpitService.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementForgeActivationService.php
  - app/Http/Controllers/AtlasCodeSelfImprovementActivationCockpitController.php
  - app/Http/Controllers/AtlasCodeSelfImprovementForgeActivationController.php
  - app/Console/Commands/AtlasSelfImprovementActivationCockpitCommand.php
  - tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementActivationCockpitTest.php
  - ../atlas-desktop/apps/desktop/src/surfaces/code/panels/AtlasSelfImprovementActivationCockpitPanel.tsx
  - ../atlas-desktop/apps/desktop/src/lib/bridge.ts
  - ../atlas-desktop/packages/atlas-domain/src/index.ts
  - ../atlas-desktop/crates/atlas-bridge/src/client.rs
  - ../atlas-desktop/crates/atlas-tauri/src/commands_bridge.rs
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-self-improvement-activation-cockpit-v1
graph_title: Atlas Self-Improvement Activation Cockpit v1
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-self-improvement-forge-activation-v1
graph_status: active
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-self-improvement-activation-cockpit-v1.md
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementActivationCockpitService.php
  - app/Http/Controllers/AtlasCodeSelfImprovementActivationCockpitController.php
  - app/Console/Commands/AtlasSelfImprovementActivationCockpitCommand.php
  - tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementActivationCockpitTest.php
evidence:
  - docs/engineering-knowledge-base/atlas-self-improvement-activation-cockpit-v1.md
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementActivationCockpitService.php
  - tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementActivationCockpitTest.php
allowed_changes:
  - Estender filtros adicionais (data range, reviewer) sob compatibilidade backward.
  - Adicionar novos campos `human_summary` / `next_safe_action` keepers em translations.
  - Reorganizar visualmente sub-seções desde que preserve os 10 blocos canônicos.
  - Adicionar Tauri commands nativos para mutações futuras.
forbidden_changes:
  - Criar Obra silenciosa a partir do cockpit.
  - Aceitar activation sem reviewer + reason + checkbox de no-fast-path.
  - Rejeitar activation sem reviewer + reason.
  - Executar Fast Path automaticamente ao abrir Obra.
  - Disparar provider externo a partir do cockpit.
  - Promover completion claim por causa de aceitação no cockpit.
  - Liberar `external_rivals_certification` a partir do cockpit.
  - Mascarar blockers do power gate ou do invariant lock.
  - Inventar trust band / portfolio deviation.
  - Substituir a fonte canônica da decisão (continuam sendo accept/reject do service v1).
depends_on:
  - atlas-self-improvement-forge-activation-v1
  - atlas-self-improvement-governance-ladder
  - atlas-forge-continuum-os
  - atlas-programming-forge-flow
  - atlas-code-forge-human-first-ux-orchestrator-v1
flows_to:
  - atlas-code
  - atlas-self-improvement-forge-activation-v1
unlocks:
  - human_visibility_of_governed_activation_lifecycle
governs:
  - self_improvement_activation_cockpit
required_tests:
  - "php artisan test --filter='AtlasSelfImprovementForgeActivation|AtlasSelfImprovementActivationCockpit'"
  - "php artisan atlas:self-improvement:activation-cockpit --json --strict"
  - "npm run lint --workspace=@atlas/desktop"
  - "npm run build --workspace=@atlas/desktop"
  - "cargo check -p atlas-tauri"
requires_evidence: true
risk_level: critical
visual_tags:
  - self-improvement
  - cockpit
  - activation
  - editorial
ai_entrypoints:
  - Leia este doc antes de tocar cockpit service, controller, painel React, bridge actions, Tauri commands, adaptadores ou audit certification.
ai_usage_notes:
  - O cockpit é projeção — quando inventar UX nova, mexa antes na doc; só então no código.
  - Mutações continuam servidas por accept/reject do service v1, NUNCA por endpoints novos.
  - `open_obra_action` apenas troca contexto no Forge; jamais executa Fast Path.
next_actions:
  - Adicionar filtro temporal (data range) e por reviewer no cockpit list/filter bar.
  - Integrar trust ledger trends (gráfico mini histórico) quando histórico ultrapassar 25 entries.
  - Adicionar ação "Replan" para reabrir uma proposta rejeitada em modo edit, sem perder o histórico.
  - Suportar drill-down do evidence_refs[] para abrir doc/ledger event correspondente no Cartografia.
  - Quando dispatcher executor estiver pronto, expor "Iniciar Fast Path" como ação separada (com confirmação dupla).
---

# Atlas Self-Improvement Activation Cockpit v1

## Resumo

O backend Atlas Self-Improvement → Forge Activation v1 já entrega o fluxo completo `proposta → power gate → before snapshot → approval → Obra criada com Intake`. Antes deste documento, o fluxo só era operável por CLI ou requisição HTTP — invisível dentro do Atlas Code Desktop. O cockpit v1 fecha esse gap com:

1. Read-model dedicado (`atlas.self_improvement.activation_cockpit.v1`) agrupando registry + detalhe + counters + summary + traduções humanas.
2. 2 rotas read-only (`/atlas-code/self-improvement/activation-cockpit` e `/{activation}`).
3. CLI dedicado (`atlas:self-improvement:activation-cockpit --json --strict`) para auditoria operacional.
4. Painel React primário dentro do Atlas Code (10 subseções editoriais linear · NUNCA grid 2D para conteúdo temporal).
5. 5 Tauri commands nativos + bridge HTTP fallback.
6. Audit certification `atlas_self_improvement_activation_cockpit_certification` com 25+ invariantes.
7. Badge "Criada por Self-Improvement Activation" no ForgeWorkIntakePanel quando state projection contém o bloco.

Tudo respeitando os hard limits do service v1: nunca chama provider, nunca executa Fast Path, nunca promove completion, nunca toca external_rivals_certification.

## Papel no Atlas

O cockpit é a única superfície humana governada do fluxo Self-Improvement → Forge Activation dentro do Atlas Code. Não substitui o service v1; ele projeta + traduz + gateia o accept/reject canônico. Quando o operador toca uma activation no Atlas Code, ele só passa pelo cockpit — tudo que envolva criar Obra, abrir Forge, ou registrar trust ledger relacionado a Self-Improvement passa por aqui.

Papel do humano:

1. Revisa lista de propostas e seleciona uma activation.
2. Lê proposal summary (problema, regra de negócio, capacidade alvo, ganho esperado, risco, docs canônicas).
3. Confere Power Gate (outcome + hard fails + soft findings + traduções humanas).
4. Confere Before Snapshot (maturidade, invariant lock, regression sentinel, strategy bucket, trust band, docs hash).
5. Decide aceitar ou rejeitar — sempre com reviewer + reason + checkbox "não executa Fast Path" no caso de accept.
6. Abre a Obra resultante no Forge para decidir manualmente se quer executar Fast Path.
7. Audita trust ledger e strategy portfolio em background.

O cockpit nunca interpreta JSON cru, nunca exige hash decoding, nunca pede ao humano que entenda internals para decidir. Sempre há linguagem humana primária + JSON disponível em `<details>` para auditoria.

## Onde Se Encaixa

| Camada | Componente | Papel |
|---|---|---|
| Doc-mãe | `atlas-self-improvement-forge-activation-v1.md` | Define o service v1 (plan/accept/reject) e o registry. Cockpit é projeção. |
| Doc-mãe Forge | `atlas-forge-continuum-os.md` | Diz como Obra → Fast Path acontece. Cockpit só abre Obra, não roda Fast Path. |
| Doc-mãe Governance | `atlas-self-improvement-governance-ladder.md` | 7 níveis de governance ladder (proposal packet → trust ledger). Cockpit visualiza os outputs. |
| Doc-mãe UX | `atlas-code-forge-human-first-ux-orchestrator-v1.md` | Padrão de painel humano (1 ação primária + safety strip + checklist). Cockpit espelha esse pattern. |
| Backend service | `AtlasSelfImprovementActivationCockpitService` | Read-model projection layer (pure, sem mutação). |
| Backend controller | `AtlasCodeSelfImprovementActivationCockpitController` | 2 rotas read-only. Mutações continuam em `AtlasCodeSelfImprovementForgeActivationController`. |
| Backend CLI | `atlas:self-improvement:activation-cockpit` | Emit canônico JSON para auditoria operacional/CI. |
| Desktop panel | `AtlasSelfImprovementActivationCockpitPanel.tsx` | Tab primário `self_improvement` no rightRailRegistry, antes de Forge. |
| Desktop bridge | `bridge.ts` + 5 Tauri commands | Transport Tauri+HTTP; HTTP fallback explícito. |
| Audit certification | `atlas_self_improvement_activation_cockpit_certification` | 31 invariantes; status `available` após desktop + tests + doc presentes. |

## Contratos

Schema canônico do cockpit: `atlas.self_improvement.activation_cockpit.v1` (read-model). Estrutura:

```json
{
  "schema_version": "atlas.self_improvement.activation_cockpit.v1",
  "generated_at": "ISO-8601",
  "filters": { "status": "string|null", "bucket": "string|null", "has_obra": "bool|null", "activation_id": "string|null" },
  "activations": [<ActivationListItem>...],
  "counters": { "total": 0, "blocked": 0, "needs_revision": 0, "pending_human_review": 0, "rejected": 0, "accepted": 0, "obra_created": 0, "dry_run_planned": 0, "with_obra": 0, "with_blockers": 0 },
  "selected_activation": <ActivationDetail|null>,
  "strategy_portfolio": <atlas.self_improvement.strategy_portfolio.v1>,
  "trust_ledger": <atlas.self_improvement.human_trust_ledger.v1>,
  "human_summary": "string",
  "next_safe_action": "string",
  "commands": {"cli": "...", "plan": "...", "accept": "...", "reject": "..."},
  "external_provider_call": false,
  "provider_tokens_spent": false,
  "auto_fast_path_executed": false,
  "completion_claim_promoted": false,
  "separated_from": "external_rivals_certification",
  "is_read_model": true
}
```

`ActivationDetail` carrega `proposal_summary`, `power_gate`, `before_snapshot`, `approval_state`, `approval_receipt`, `rejection`, `created_obra`, `open_obra_action`, todas com tom (rec-red/bronze/moss/cream/ink) e label humano.

Contratos enforced:
1. Cockpit é projeção pura. Mutações continuam servidas por `AtlasCodeSelfImprovementForgeActivationController::store/accept/reject`.
2. Accept e Reject endpoint exigem `reviewer` + `reason` (service v1). UI exige adicionalmente checkbox `acknowledgesNoFastPath` antes de habilitar Accept.
3. Status enumerados são herdados do service v1 — nunca renomeados na projeção.
4. `next_safe_action` é frase humana curta em PT-BR adequada para botão/instrução.
5. `is_read_model: true` é constante; cockpit nunca usa `false`.

## Fluxo

```
[1] PLAN (CLI ou bridge.createSelfImprovementForgeActivation)
        ↓
[2] Cockpit lista as activations recentes (registry, cap 25)
        ↓
[3] Operador seleciona uma → cockpit.getSelfImprovementForgeActivation(id)
        ↓
[4] Cockpit renderiza:
      • Proposal summary humano
      • Power Gate traduzido (outcome + tone + label)
      • Before Snapshot 5 baselines + docs canônicas hash
        ↓
[5] Form Aprovação (apenas se status permite)
      • reviewer obrigatório
      • reason obrigatório
      • checkbox no-fast-path obrigatório (accept)
        ↓
[6a] ACCEPT → bridge.acceptSelfImprovementForgeActivation → service v1.accept
       • Materializa Obra
       • Preenche Intake
       • Grava approval receipt + receipt_hash
       • Grava `proposal_accepted_for_forge` no trust ledger
       • Status: obra_created · next_safe_action: "Abrir Obra no Forge"
        ↓
[7a] Cockpit exibe Obra criada + botão Abrir Obra
       (clique apenas troca contexto · NUNCA roda Fast Path)
        ↓
[8a] Forge cockpit/Definir mostra badge "Criada por Self-Improvement Activation"

[6b] REJECT → bridge.rejectSelfImprovementForgeActivation → service v1.reject
       • Registra rejection (reviewer + reason)
       • NÃO cria Obra
       • Grava `proposal_rejected_for_forge` no trust ledger
       • next_safe_action: "Reescrever proposta ou encerrar"
```

## Estados

Schema canônico: `atlas.self_improvement.activation_cockpit.v1`.

`AtlasSelfImprovementActivationStatus` (transparente do service v1):
- `blocked` — power gate rejected ou docs canônicos ausentes. Tone: rec-red.
- `needs_revision` — power gate identificou hard fails recuperáveis. Tone: bronze.
- `pending_human_review` — gate ok, exige reviewer + reason. Tone: bronze.
- `accepted` — aceito mas Obra ainda não materializada. Tone: moss.
- `obra_created` — Obra real existe + Intake preenchido. Tone: moss.
- `dry_run_planned` — diagnóstico sem persistência. Tone: bronze.
- `rejected` — reviewer rejeitou. Tone: rec-red.

`approval_state` derivado:
- `accepted_obra_created`
- `accepted_pending_materialise`
- `awaiting_human_review`
- `needs_revision`
- `dry_run_planned`
- `rejected_by_human`
- `rejected_by_gate`
- `blocked`

## Power Gate

Power Gate retorna outcome canônico (`approved`, `needs_revision`, `rejected`, `human_review_required`). O cockpit traduz:

| Outcome | Label humano | Tone |
|---|---|---|
| `approved` | "Pode virar Obra, mas ainda não executa Forge." | moss |
| `human_review_required` | "Humano precisa aprovar antes de criar Obra." | bronze |
| `needs_revision` | "Precisa melhorar antes de virar Obra." | bronze |
| `rejected` | "Proposta fraca ou perigosa. Não pode virar Obra." | rec-red |

Hard fails e soft findings aparecem como lista bullet, mantendo identificadores canônicos (`missing_business_rule`, `missing_canonical_docs`, etc.) para auditoria. Próxima ação canônica do gate é exibida como instrução em itálico.

## Before Snapshot

Schema: `atlas.self_improvement.forge_activation_baseline.v1`. Renderizado como 6 linhas editoriais com hash + tom:

1. **maturidade** — `<achieved> / <target>` (target_level vs achieved_level).
2. **invariant lock** — `passed` (moss) ou `blocked: N violações` (rec-red).
3. **regression sentinel** — `clear` (moss) / `findings (N)` (bronze) / `blocked` (rec-red).
4. **strategy portfolio** — balance_health + recommended_next_bucket.
5. **trust band** — high_trust (moss) / medium / low / low_trust_overreach / insufficient_data.
6. **docs canônicas** — `X/Y presentes`. Bronze se incompleto, moss se completo. Lista de missing_required ao final.

Rationale fixa: "Este snapshot serve para comparar se o Atlas melhorou depois."

## Approval Receipt

Schema: `atlas.self_improvement.forge_activation_approval.v1`.

Cockpit exibe após accept:
- reviewer (obrigatório)
- reason (obrigatório)
- approved_at (ISO 8601 UTC)
- receipt_hash (sha256 — primeiros 24 chars na UI, full no `<details>`)
- proposal_hash + power_gate_hash + invariant_lock_hash + regression_sentinel_hash
- silent = false (sempre)
- auto_promotes_completion_claim = false (sempre)
- auto_executes_fast_path = false (sempre)
- external_provider_call = false (sempre)

## Obra criada

Quando `status === obra_created`:
- obra_id (UUID, primeiros 12 chars + …)
- title (string)
- intake_status (`filled` / `pending`)
- objective + business_rule
- acceptance_criteria_count + canonical_docs_count
- scope_in / scope_out (arrays)
- risk_level
- fast_path_started = false

Botão "Abrir Obra no Forge" troca o contexto (refresh do ForgeUxOrchestrator + Forge Work Intake) — NUNCA inicia Fast Path. Tooltip explica.

## Trust Ledger

Schema: `atlas.self_improvement.human_trust_ledger.v1`. Cockpit mostra:
- trust_band (alta visibilidade)
- entry_count
- últimas entradas via `<details>` se necessário

Outcomes canônicos relacionados:
- `proposal_accepted_for_forge` — grava em accept (com obra criada → registra no metadata da Obra também).
- `proposal_rejected_for_forge` — grava em reject (global, sem Obra).

Dedupe window 30s, cap 100 entries por Obra.

## Strategy Portfolio

Schema: `atlas.self_improvement.strategy_portfolio.v1`.

Cockpit mostra para a activation selecionada:
- strategy_bucket (1 dos 8: quick_wins / core_runtime / enterprise_reliability / provider_intelligence / operator_experience / rivals_evaluation / self_construction / security_governance).
- portfolio_deviation (bool) + portfolio_reason (string).
- recommended_next_bucket (do snapshot global).
- balance_health (balanced / mild_imbalance / severe_imbalance / empty_portfolio).

Portfolio deviation NUNCA bloqueia activation — é diagnóstico.

## Regras para IA

1. **Cockpit é projeção.** Quando precisar adicionar feature nova, sempre verifique se o ajuste pertence ao service v1 (`AtlasSelfImprovementForgeActivationService`) primeiro. Cockpit só projeta.
2. **Nunca crie novo endpoint mutador.** Accept/Reject continuam servidos pelo controller v1; cockpit nunca adiciona POST/PUT/DELETE de mutação.
3. **Nunca aceite sem reviewer + reason + ack no-fast-path.** UI desabilita botão; bridge layer também valida antes do round-trip.
4. **Power Gate traduções são canônicas.** Não invente label novo para outcomes existentes (approved/needs_revision/human_review_required/rejected). Sempre use a tabela de Power Gate desta doc.
5. **Before Snapshot é diagnóstico.** Nunca o invente; sempre projete do service. Quando vazio (`null`), exibir EmptyText, nunca placeholder fake.
6. **Trust band e portfolio deviation são diagnóstico.** Nunca bloqueie activation por causa deles; só exibir.
7. **`open_obra_action` apenas troca contexto Forge.** Nunca dispara Fast Path automaticamente — Fast Path continua decisão manual no Forge Human Panel.
8. **Origin badge é leitura de state projection.** Nunca duplique a fonte; sempre leia de `selfImprovementActivation` (canon `atlas.self_improvement.forge_activation_state.v1`).
9. **Nunca toque external_rivals_certification.** Cockpit é separated_from external_rivals_certification (invariante).
10. **Documente antes de tocar o painel.** Mudanças visuais devem refletir na seção UI desta doc antes de virem código.

## Escopo de Implementacao

| Arquivo | Tipo | Responsabilidade |
|---|---|---|
| `app/Services/Ai/SelfImprovement/AtlasSelfImprovementActivationCockpitService.php` | Service | Projection pura — combina registry + detail + counters + traduções humanas. |
| `app/Http/Controllers/AtlasCodeSelfImprovementActivationCockpitController.php` | Controller | 2 rotas read-only (`GET /atlas-code/self-improvement/activation-cockpit[/{activation}]`). |
| `app/Http/Controllers/AtlasCodeSelfImprovementForgeActivationController.php` | Controller (hardened) | Adiciona `human_summary`+`next_safe_action` em store/show/accept/reject sem quebrar compat. |
| `app/Console/Commands/AtlasSelfImprovementActivationCockpitCommand.php` | CLI | `atlas:self-improvement:activation-cockpit --json [--strict] [--activation=]`. |
| `app/Services/Ai/Programming/ProgrammingProfessionalCompletionAuditService.php` (extended) | Audit | Registra `atlas_self_improvement_activation_cockpit_certification` com 31 invariantes. |
| `tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementActivationCockpitTest.php` | Tests | 16 tests cobrindo schema/list/detail/api/CLI/safety invariants. |
| `apps/desktop/src/surfaces/code/panels/AtlasSelfImprovementActivationCockpitPanel.tsx` | UI | 10 subseções editoriais + safety strip + advanced details. |
| `apps/desktop/src/surfaces/code/panels/ForgeWorkIntakePanel.tsx` (extended) | UI | Origin badge "Criada por Self-Improvement Activation". |
| `packages/atlas-domain/src/index.ts` (extended) | Types | 7+ tipos novos (Cockpit, ListItem, Detail, PowerGate, BeforeSnapshot, Approval, CreatedObra). |
| `apps/desktop/src/lib/bridge.ts` (extended) | Bridge | 5 actions + 8 adapters + helper `buildCockpitQuery`. |
| `crates/atlas-bridge/src/client.rs` (extended) | Rust client | 5 HTTP methods. |
| `crates/atlas-tauri/src/commands_bridge.rs` + `lib.rs` (extended) | Tauri | 5 commands registrados em `tauri::generate_handler!`. |

## Dependencias

| Dependência | Tipo | Por quê |
|---|---|---|
| `atlas-self-improvement-forge-activation-v1` | doc-mãe direta | service + accept/reject + before_snapshot + receipt vivem aqui. Cockpit só projeta. |
| `atlas-self-improvement-governance-ladder` | doc-mãe governance | proposal packet + power gate + invariant lock + regression sentinel + maturity + trust ledger + portfolio. |
| `atlas-forge-continuum-os` | doc-mãe forge | Obra criada continua sob governance Forge; cockpit nunca dispara Fast Path. |
| `atlas-programming-forge-flow` | flow | Atlas Code SCOR-1 surface continua sendo a casa; cockpit adiciona um tab. |
| `atlas-code-forge-human-first-ux-orchestrator-v1` | UX canon | Pattern de painel humano (status strip + ação primária + safety strip + checklist) que o cockpit espelha. |
| `AtlasSelfImprovementForgeActivationService` | runtime | Service v1; cockpit reusa registry/get/accept/reject. |
| `AtlasSelfImprovementProposalPowerGateService` | runtime | Power gate outcomes/hard fails/soft findings projetados. |
| `AtlasSelfImprovementHumanTrustLedgerService` | runtime | Trust band + entries usados na visualização. |
| `AtlasSelfImprovementStrategyPortfolioService` | runtime | Bucket + balance_health + recommended_next_bucket. |
| `AtlasCodeForgeWorkIntakeService` | runtime | Intake preenchido em accept; cockpit lê contagem de acceptance_criteria e canonical_docs. |
| `AtlasCodeWorkController` | state projection | Bloco `self_improvement_activation` na projeção state-per-Obra; alimenta origin badge no ForgeWorkIntakePanel. |

## Evidencias

`evidence_refs[]` no payload de cada activation referencia:
- `doc:<path>@<sha256>` — cada doc canônica presente (REQUIRED_DOCS + OPTIONAL_DOCS).
- `proposal:<proposal_id>` — id da proposta normalizada via Proposal Packet.
- `power_gate:<gate_id>` — id do gate evaluation.
- `baseline:<kind>@<hash>` — maturity / invariant_lock / regression_sentinel / strategy_portfolio / trust_ledger.
- `approval:<receipt_hash>` — quando aceito (sha256 do receipt completo).
- `obra:<obra_id>` — quando Obra materializada (UUID do AtlasProject).

Verificáveis via:
1. Filesystem (docs).
2. Tabela `atlas_ledger_events` (event_type prefixado `SELF_IMPROVEMENT_FORGE_ACTIVATION_` + status, schema_version `atlas.self_improvement.forge_activation.v1`).
3. AtlasProject metadata (`self_improvement_activation`, `latest_atlas_code_forge_work_intake`, `atlas_self_improvement_human_trust_ledger`).
4. Local storage `atlas/self-improvement/forge-activations/<id>.json` + `_registry.json`.

## Riscos

| Risco | Mitigação |
|---|---|
| Operador esquecer de marcar checkbox no-fast-path antes de Accept | Botão desabilitado até checkbox + reviewer + reason válidos; bridge layer também valida antes do round-trip. |
| Mostrar dados falsos (mock/synthetic) quando endpoint indisponível | Adaptador retorna `null` honestamente; painel renderiza EmptyText. |
| Open Obra disparar Fast Path silenciosamente | Botão chama `refreshForgeUxOrchestrator + refreshForgeWorkIntake` somente — não chama `runForgeFastPath`. Tooltip explica. |
| Trust band cair (low_trust_overreach) sem operador notar | Linha "trust band" da subseção Histórico/Trust mostra valor com tom; safety strip nunca esconde. |
| Audit certification quebrando ao remover painel desktop | Invariantes `desktop_*` ficam `false`, status volta para `backend_available_ui_pending`. |
| Hash collision dificultar auditoria | Receipt hash + proposal hash + power gate hash exibidos completos em `<details>`; primeiros 24 chars visíveis na UI. |
| External rivals desbloqueado por descuido | Invariante `external_rivals_separated` + teste `test_external_rivals_certification_remains_present_and_unaffected` previnem. |

## Exemplos

**Listar todas as activations via CLI:**
```bash
php artisan atlas:self-improvement:activation-cockpit --json
```

**Inspecionar uma activation específica com strict mode:**
```bash
php artisan atlas:self-improvement:activation-cockpit \
  --activation=act_01HXXXX \
  --json --strict
# exit 0 quando OK / 1 quando blockers ou rejected
```

**Endpoint HTTP — listar com filtros:**
```bash
curl -H "X-Atlas-Token: $TOKEN" \
  "http://localhost:8000/atlas-code/self-improvement/activation-cockpit?status=pending_human_review&has_obra=false"
```

**Endpoint HTTP — detalhe:**
```bash
curl -H "X-Atlas-Token: $TOKEN" \
  "http://localhost:8000/atlas-code/self-improvement/activation-cockpit/act_01HXXXX"
```

**Accept via cockpit no desktop:**
```typescript
await bridge.acceptSelfImprovementForgeActivation('act_01HXXXX', {
  reviewer: 'vitorepf',
  reason: 'maturity ok, baseline limpo, acceptance gates declarados',
  acknowledgesNoFastPath: true,
})
// → status: 'obra_created', receipt_hash visível, Open Obra habilitado
```

**Reject via cockpit:**
```typescript
await bridge.rejectSelfImprovementForgeActivation('act_01HXXXX', {
  reviewer: 'vitorepf',
  reason: 'rivals_evaluation_plan ausente, replanejar',
})
// → status: 'rejected', sem Obra, trust ledger registra
```

## UI

Painel: `atlas-desktop/apps/desktop/src/surfaces/code/panels/AtlasSelfImprovementActivationCockpitPanel.tsx`.

Estrutura editorial linear peso-decrescente (canon):

1. **PanelTitle** "Self-Improvement" + contador total.
2. **Status strip** — eyebrow + humanSummary + nextSafeAction.
3. **Propostas** — FilterBar (Todas/Pendentes/Aceitas/Rejeitadas/Com Obra) + lista de cards.
4. **Avaliação** — ProposalSummaryView + PowerGateView (visível apenas com selected).
5. **Before Snapshot** — 6 linhas Row + rationale (visível apenas com selected).
6. **Aprovação** — Form accept/reject ou histórico de decisão (visível apenas com selected).
7. **Obra criada** — CreatedObraView + botão Abrir (visível apenas após accept).
8. **Histórico / Trust** — trust_band + strategy_bucket + portfolio (visível com selected).
9. **Safety strip** — 5 invariantes sempre visíveis (provider externo · tokens · Fast Path auto · completion claim · separated_from).
10. **Avançado** — `<details>` com JSON raw + hashes + evidence refs para auditoria.

Registrado em `rightRailRegistry.tsx` com priority 3 (antes do Forge, depois de pré-Forge se houver). Tab id: `self_improvement`.

Origin badge no Forge: `ForgeWorkIntakePanel.tsx` renderiza "Criada por Self-Improvement Activation · {activationId}" quando `selfImprovementActivation?.activationId` está presente no state projection.

Tokens visuais (canon editorial cream + bronze + moss + rec-red, viewport 393, sem grade 2D).

## Segurança

Invariantes enforced no cockpit:

| Invariante | Como | Camada |
|---|---|---|
| Accept exige reviewer | Botão desabilitado + service v1 rejeita | UI + backend |
| Accept exige reason | Botão desabilitado + service v1 rejeita | UI + backend |
| Accept exige checkbox no-fast-path | Botão desabilitado se `!acknowledgesNoFastPath` | UI |
| Reject exige reviewer | Botão desabilitado + service v1 rejeita | UI + backend |
| Reject exige reason | Botão desabilitado + service v1 rejeita | UI + backend |
| Nunca cria Obra silenciosa | service v1 garante | backend |
| Nunca executa Fast Path | cockpit nunca chama runForgeFastPath | UI |
| Nunca chama provider | service não tem driver | backend |
| Nunca promove completion claim | receipt explícito false | backend |
| Nunca toca external_rivals_certification | separated_from = `external_rivals_certification` | backend |

Bridge layer também valida reviewer + reason + ack antes de chamar accept/reject (lança erro local sem fazer round-trip se condições não atendidas).

## Evidence

`evidence_refs[]` no payload de cada activation referencia:
- `doc:<path>@<sha256>` para cada doc canônica presente.
- `proposal:<id>` para o proposal_id.
- `power_gate:<gate_id>` para o gate.
- `baseline:<kind>@<hash>` para cada componente do before_snapshot (maturity, invariant_lock, regression_sentinel, strategy_portfolio, trust_ledger).
- `approval:<receipt_hash>` quando aceito.
- `obra:<obra_id>` quando obra materializada.

Tudo verificável via filesystem (docs) ou ledger (`atlas_ledger_events`, event_type `SELF_IMPROVEMENT_*`).

## Testes

```bash
php artisan test --filter='AtlasSelfImprovementForgeActivation|AtlasSelfImprovementActivationCockpit'
php artisan atlas:self-improvement:activation-cockpit --json --strict
npm run lint --workspace=@atlas/desktop
npm run build --workspace=@atlas/desktop
cargo check -p atlas-tauri
php artisan atlas:programming:completion-audit --json
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:ai:architecture-validate --json
```

Os 16 tests do `AtlasSelfImprovementActivationCockpitTest` cobrem:

- Schema canônico vazio (counters zerados, ack do read-model).
- Lista com labels humanos (tone + statusLabel).
- Detail expondo proposal + power gate + before snapshot.
- Detail de activation inexistente devolvendo blocked.
- Filtros por status preservando counters totais.
- Accept materializando Obra + receipt visível + open_obra_action habilitado.
- Rejection visível, sem Obra, com tone rec-red.
- API list e detail (200) + 404 para id inexistente.
- Forge controller enrichment (human_summary + next_safe_action backwards-compatible).
- CLI strict success (sem activation selecionada).
- CLI strict fail (activation bloqueada).
- Invariantes de não-execução (provider call false, tokens false, fast path false, completion claim false, separated_from correto).
- Completion audit expondo certification nova com >=25 invariantes.
- External rivals certification permanecendo presente e inalterada.

## Limites

- Cockpit nunca substitui a fonte canônica: accept/reject continuam servidos por `AtlasSelfImprovementForgeActivationService::accept/reject`.
- Cockpit nunca toca Voice, Cartografia, Inbox, Embodiment.
- Cockpit nunca desbloqueia `external_rivals_certification` (continua governado externamente).
- Botão "Abrir Obra" apenas troca contexto; Fast Path continua sendo decisão manual no Forge Human Panel.
- Tauri commands nativos foram entregues mas o cockpit suporta operação somente-HTTP (offline-shim) sem quebrar.
- O cockpit não tenta inferir trust band ou portfolio deviation — apenas projeta o que o service retornou.
- Nenhuma persistência local de form data — cada navegação entre activations exige reentrada de reviewer + reason + checkbox (intencional · previne accept-after-navigation).

## Proximas Acoes

1. Adicionar filtro temporal (data range) e por reviewer no cockpit list/filter bar.
2. Integrar trust ledger trends (gráfico mini histórico) quando histórico ultrapassar 25 entries.
3. Adicionar ação "Replan" para reabrir uma proposta rejeitada em modo edit, sem perder o histórico.
4. Suportar drill-down do `evidence_refs[]` para abrir doc/ledger event correspondente no Cartografia (cross-surface).
5. Quando dispatcher executor estiver pronto, expor "Iniciar Fast Path" como ação separada (com confirmação dupla + budget approval).
