---
id: atlas-code-premium-workbench-visual-comfort-v1
type: engineering_knowledge
title: Atlas Code Premium Workbench Visual Comfort v1
status: active
category: programming-forge
priority: 95
summary: Tema dark warm enterprise (warm graphite/charcoal low-glare) escopo `.atlas-shell.surface-code` + workbench primitives reusaveis (StatusBadge/MetricRow/WorkbenchPanel/EmptyState/SafetyStrip/ProgressMilestones/ObraListItem/ObraSummaryHero/LiveActivityCard) + centro vivo com lifecycle horizontal/atividade ao vivo/decisions/diagnostics/safety strip. Foco em 12h workstation premium feel, mirando 9/10 visual.
tags:
  - atlas
  - atlas-code
  - ux
  - visual-comfort
  - workbench
  - premium
  - dark-warm
capabilities:
  - atlas_code_premium_workbench_visual_comfort
decisions:
  - Tema dark warm aplicado APENAS no escopo `.atlas-shell.surface-code`; Cartografia preserva warm cream legacy (tokens --cream-*).
  - Background base `#23211c` (warm graphite deep) + superficies escalonadas `#2b2823/#34302a/#1c1a16`; texto warm cream high-contrast.
  - Branco puro `#ffffff` proibido como bg dominante; off-white `#fcfaf4` apenas para popovers/dialogos legacy.
  - Accent gold `#c79a4e` (atlas burnished) usado com parcimonia em CTA/active/focus.
  - Status colors saturados o suficiente para distinguir, sem agressao: success moss/`#7ba26a`, warning amber/`#d4a05a`, danger oxblood/`#c75148`, info steel/`#7a9bb4`, neutral graphite/`#a89e87`.
  - Workbench primitives reusaveis em `apps/desktop/src/surfaces/code/workbench/` (StatusBadge/StatusDot/MetricRow/WorkbenchPanel/EmptyState/SafetyStrip/ProgressMilestones/ObraListItem/ObraSummaryHero/LiveActivityCard) substituem inline styles ad-hoc.
  - Lifecycle horizontal premium (ProgressMilestones) substitui grid de 8 cards.
  - Live activity card sempre presente quando ha Obra, com dot pulsante em running/queued/waiting_worker.
  - Decision inbox cards com tom semantico por risco (low=info, medium=warning, high=danger).
  - Safety strip 5 sinais sempre visiveis (provider externo, tokens, completion, review gate, external rivals).
  - Status dot suporta data-pulse animation com respect a prefers-reduced-motion.
maintenance:
  - Atualize quando workbench primitives ou tokens `.atlas-shell.surface-code` mudarem.
  - Mantenha Cartografia intocada (warm cream legacy preservado).
related_paths:
  - apps/desktop/src/index.css
  - apps/desktop/src/surfaces/code/workbench/
  - apps/desktop/src/surfaces/code/stage/ObraCommandCenterPanel.tsx
  - apps/desktop/src/surfaces/code/leftRail/ObrasSection.tsx
  - docs/engineering-knowledge-base/atlas-code-visual-ergonomics-enterprise-polish-v1.md
  - docs/engineering-knowledge-base/atlas-code-obra-command-center-v1.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-code-premium-workbench-visual-comfort-v1
graph_title: Atlas Code Premium Workbench Visual Comfort v1
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-code-visual-ergonomics-enterprise-polish-v1
graph_status: active
graph_source: repo
human_name: Atlas Code Premium Workbench Visual Comfort v1
canonical_name: Atlas Code Premium Workbench Visual Comfort v1
technical_name: atlas-code-premium-workbench-visual-comfort-v1
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/atlas-code-premium-workbench-visual-comfort-v1.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-code-premium-workbench-visual-comfort-v1.md
allowed_changes:
  - Adicionar workbench primitives novos quando o produto crescer.
  - Refinar tokens dentro do escopo `.atlas-shell.surface-code`.
  - Estender status dot states para novos estados canonicos quando o Forge crescer.
forbidden_changes:
  - Adicionar `background: #ffffff` extenso como tema default.
  - Mover paleta dark warm para `:root` global (quebraria Cartografia).
  - Promover completion claim ou chamar provider externo via UI.
  - Reintroduzir letter-spacing exagerado em body/botoes/dados.
  - Remover safety strip ou esconder blockers honestos.
  - Inflar progresso sem evidencia real do read-model.
depends_on:
  - atlas-code-visual-ergonomics-enterprise-polish-v1
  - atlas-code-obra-command-center-v1
  - atlas-code-human-interface-upgrade-v2
flows_to:
  - atlas-code
unlocks:
  - premium_workbench_12h_visual_comfort
governs:
  - atlas_code_premium_workbench_visual_comfort
evidence:
  - docs/engineering-knowledge-base/atlas-code-premium-workbench-visual-comfort-v1.md
  - apps/desktop/src/surfaces/code/workbench/
  - apps/desktop/src/index.css
required_tests:
  - "npm run build --workspace=@atlas/desktop"
  - "npm run lint --workspace=@atlas/desktop"
  - "php artisan atlas:programming:completion-audit --json"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: low
next_actions:
  - Migrar painels do right rail (ForgeHumanPanel, ForgeAdvancedPanel, VerifyPanel, EvidencePanel) para `WorkbenchPanel/MetricRow/StatusBadge` consistentes.
  - Toggle de densidade `data-cc-density='compact'` exposto na ObraBar quando houver demanda.
  - Validar contraste AA em todos pares texto/fundo do tema dark warm.
  - Rebuild Tauri para .app/.dmg refletirem novo visual.
---
# Atlas Code Premium Workbench Visual Comfort v1

> Schema canonico: `atlas.code.premium_workbench_visual_comfort_certification.v1`
> Status: implementado · 2026-05-14

## Resumo
Camada visual premium para Atlas Code Desktop mirando 9/10 em conforto ocular, hierarquia, clareza operacional e percepcao de produto enterprise. Tema dark warm graphite/charcoal aplicado APENAS no shell Atlas Code (Cartografia mantida warm cream legacy). Workbench primitives reusaveis substituem inline styles ad-hoc. Centro vivo com hero rico, lifecycle horizontal, atividade ao vivo, decisions inbox, diagnostics e safety strip permanente. Nao toca runtime/governance.

## Papel no Atlas
Eleva o Atlas Code Desktop de UI clara e chapada (~6.5/10 apos Visual Ergonomics v1) para workbench premium 9/10 de 12h workstation, com paleta low-glare, hierarquia clara, status colors saturados e calmos, e tipografia operacional. Permite ao humano entender em 3s: Obra ativa, status, seguranca, proximo passo e necessidade de intervencao.

## Onde Se Encaixa
Filho de [[atlas-code-visual-ergonomics-enterprise-polish-v1]] (camada de tokens enterprise base) e [[atlas-code-obra-command-center-v1]] (centro humano canonico). Camada exclusivamente visual: nao altera contratos do Forge/Review/Provider/Self-Improvement. Cartografia preserva tokens legacy `--cream-*` sem mudanca.

## Contratos
- Escopo: `.atlas-shell.surface-code` redefine tokens `--cc-*` para dark warm; `:root` continua warm parchment para Cartografia/outros surfaces.
- Tokens primarios (escopo): `--cc-bg: #23211c`, `--cc-surface: #2b2823`, `--cc-surface-raised: #34302a`, `--cc-surface-sunken: #1c1a16`; `--cc-text-strong: #f4ecd9`, `--cc-text: #ddd3bd`, `--cc-text-muted: #a89e87`, `--cc-text-faint: #7a715d`; `--cc-accent: #c79a4e` (atlas gold burnished).
- Status colors warm-tuned: success `#7ba26a`, warning `#d4a05a`, danger `#c75148`, info `#7a9bb4`, neutral `#a89e87`.
- Workbench primitives em `apps/desktop/src/surfaces/code/workbench/`:
  - `StatusDot` (status + pulse animado quando running/queued/waiting_worker).
  - `StatusBadge` (pilula compacta dot + label tom semantico).
  - `MetricRow` (key-value row mono/sans alinhado).
  - `WorkbenchPanel` (card escalonado eyebrow + title + action).
  - `WorkbenchSection` (subsecao com eyebrow pequeno).
  - `EmptyState` (POR QUE vazio + O QUE FAZER).
  - `SafetyStrip` (5 sinais criticos sempre visiveis).
  - `ProgressMilestones` (trilha horizontal lifecycle passed/current/upcoming/blocked).
  - `ObraListItem` (row premium lista de Obras com active state inequivoco).
  - `ObraSummaryHero` (header rico centro com status badge + title 24px + proximo passo gold).
  - `LiveActivityCard` (feedback vivo com dot pulsante + last events).
- Animation: `@keyframes cc-status-pulse` respeita `prefers-reduced-motion`.
- Audit cert: `atlas.code.premium_workbench_visual_comfort_certification.v1` em `ProgrammingProfessionalCompletionAuditService::atlasCodePremiumWorkbenchVisualComfortCertification()` (24 invariantes).

## Fluxo
1. `index.css` carrega `@layer reset, tokens, enterprise, base, layout, components, motion`. 2. `@layer enterprise :root` define paleta legacy parchment para Cartografia. 3. `.atlas-shell.surface-code` redefine `--cc-*` para dark warm (warm graphite/charcoal). 4. Cartografia explicito override em `.atlas-shell.surface-cartografia .topbar` mantem warm cream visivel. 5. Componentes refatorados consomem tokens `--cc-*` (ObraCommandCenterPanel, ObrasSection, SessionsSection, LeftRail, TopBar, ObraBar, Composer, ConversationPanel, terminal). 6. Workbench primitives renderizam consistentemente em qualquer painel.

## Regras para IA
Nunca mover tokens dark warm para `:root` global. Nunca usar `background: #ffffff` extenso como tema default. Nunca remover safety strip. Nunca esconder blockers tecnicos sem traducao humana. Nunca inflar progresso sem evidencia real do read-model. Nunca tocar Voice/Cartografia logica. Toda nova classe utilitaria de UI deve entrar como workbench primitive ou `cc-*` em index.css.

## Escopo de Implementacao
- `apps/desktop/src/index.css`: `.atlas-shell.surface-code` redefine `--cc-*` dark warm + animacao status pulse + repaint legacy session/terminal/topbar/obrabar/composer/conv-msg/ops-tabs.
- `apps/desktop/src/surfaces/code/workbench/`: 11 arquivos (10 primitives + tokens.ts + index.ts).
- `apps/desktop/src/surfaces/code/stage/ObraCommandCenterPanel.tsx`: refatorado para consumir ObraSummaryHero/LiveActivityCard/ProgressMilestones/WorkbenchPanel/MetricRow/StatusBadge/SafetyStrip; lifecycle horizontal + diagnostics grid 4-up + decision inbox cards.
- `apps/desktop/src/surfaces/code/leftRail/ObrasSection.tsx`: usa `ObraListItem` workbench primitive + busca local quando ≥4 obras + EmptyState honesto.
- `apps/desktop/src/surfaces/code/leftRail/SessionsSection.tsx`: usa ObraListItem para consistencia.
- Audit: `atlasCodePremiumWorkbenchVisualComfortCertification()` com 24 invariantes (dark_warm_theme_scoped, low_glare_no_pure_white, cartografia_preserved_legacy, workbench_primitives_complete, 10 primitives_available, command_center_consumes_primitives, left_rail_consumes_obra_list_item, status_dot_pulse_animation, terminal_dock_dark_warm, safety_strip_5_signals, no_external_provider_call, no_token_spend_visible, no_completion_claim_promotion, review_gate_preserved, advanced_collapsed, premium_workbench_doc_present).

## Dependencias
[[atlas-code-visual-ergonomics-enterprise-polish-v1]], [[atlas-code-obra-command-center-v1]], [[atlas-code-human-interface-upgrade-v2]], [[atlas-code-forge-human-first-ux-orchestrator-v1]]. Audit final via [[atlas-ai-programming-professional-completion-audit]].

## Evidencias
- `npm run build --workspace=@atlas/desktop` — verde (~150ms).
- `npm run lint --workspace=@atlas/desktop` — 0 erros novos (warnings pre-existentes em Cartografia).
- `php artisan atlas:programming:completion-audit --json` — `atlas_code_premium_workbench_visual_comfort_certification.status = available`.
- `php artisan atlas:engineering:knowledge docs-health --json` — sem violacoes na nova doc.
- Visual QA: `/tmp/atlas-vqa/screenshots/atlas-code-premium-desktop-{1440,1280,1024}.png`.
- Mockup canonico: `/tmp/atlas-vqa/atlas-code-premium-mockup.html`.

## Riscos
1. Tema dark warm dentro de `.atlas-shell.surface-code` quebrar componentes que consultam tokens fora desse escopo (popovers detachados): mitigado por uso de tokens `--cc-*` apenas em componentes children do shell. 2. Contraste em pares dark warm: mitigado por escala curada (text-strong `#f4ecd9` sobre bg `#23211c` da ~AA), validacao AA listada em next_actions. 3. Cartografia perder consistencia visual: mitigado por overrides explicitos em `.atlas-shell.surface-cartografia` + Cartografia ja tem CSS proprio. 4. Animacao pulse em `prefers-reduced-motion`: mitigado por media query desligando keyframes.

## Exemplos
```tsx
// Centro vivo
<ObraSummaryHero
  obraTitle="Atlas Code Premium Workbench"
  objectiveSummary="Transformar Atlas Code em workbench enterprise 12h"
  statusLabel="Em execucao"
  statusDetail="O Forge esta trabalhando dentro do contrato governado."
  statusKind="running"
  statusTone="info"
  primaryActionLabel="Atualizar Status"
  primaryActionHint="Aguarde o polling; refresh manual e seguro."
/>

<ProgressMilestones milestones={[
  { key: 'intake', label: 'Definicao', status: 'passed' },
  { key: 'build', label: 'Construcao', status: 'current', hint: 'Aguarde o polling.' },
  { key: 'review', label: 'Revisao', status: 'upcoming' },
]} />

<SafetyStrip
  externalProviderCall={false}
  providerTokensSpent={0}
  completionClaimPromoted={false}
  reviewGatePreserved={true}
  externalRivalsStatus="blocked_requires_operator_approval"
/>
```
Visual QA: `node /tmp/atlas-vqa/take-premium-screenshots.mjs` gera screenshots em viewports 1440/1280/1024.

## Proximas Acoes
Migrar painels do right rail (ForgeHumanPanel, ForgeAdvancedPanel, VerifyPanel, EvidencePanel) para WorkbenchPanel/MetricRow/StatusBadge consistentes. Toggle de densidade compact exposto na ObraBar quando houver demanda. Validar contraste AA com ferramenta acessibilidade. Rebuild Tauri (`npm run tauri:build`) para gerar .app/.dmg com novo visual.
