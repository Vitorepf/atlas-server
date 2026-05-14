---
id: atlas-code-visual-ergonomics-enterprise-polish-v1
type: engineering_knowledge
title: Atlas Code Visual Ergonomics & Enterprise Polish v1
status: active
category: programming-forge
priority: 95
summary: Refinamento visual enterprise do Atlas Code · paleta menos monocromatica, tipografia sans operacional, design tokens canonicos, left rail polido, status colors distintos, focus states, empty/loading/error padronizados, scrollbar warm. Foco em 12h workstation com fadiga visual minima.
tags:
  - atlas
  - atlas-code
  - ux
  - visual-polish
  - enterprise
  - design-tokens
capabilities:
  - atlas_code_visual_ergonomics
decisions:
  - Body family default e sans (Inter) com line-height generoso para parágrafos operacionais; serif Cormorant fica reservado a display/display titles especiais.
  - Tokens enterprise vivem em @layer enterprise com prefixo --cc-* para coexistir com tokens editoriais legacy sem reescrita.
  - Status colors sao distintos do warm dominante (success moss, warning amber, danger oxblood, info steel, neutral graphite).
  - Status nunca depende so de cor: dots + label + tom + position em UI.
  - Focus-visible global com ring calmo (var(--cc-focus-ring)) — keyboard-only, mouse nao mostra.
  - Scrollbar warm-tinted (8-10px) e custom thumb pra 12h sem ofuscar.
  - Density default e comfortable; data-cc-density='compact' troca tokens de altura para densidade maior quando o operador escolher.
  - Letter-spacing 0 no body, botoes e dados; uppercase apenas em eyebrows com tracking moderado (0.08em).
maintenance:
  - Atualize quando index.css ou os panels canônicos (ObraCommandCenterPanel, LeftRail, ObrasSection, SessionsSection, ForgeHumanPanel) mudarem visualmente.
  - Mantenha consistencia com [[atlas-code-obra-command-center-v1]] e [[atlas-code-human-interface-upgrade-v2]].
related_paths:
  - apps/desktop/src/index.css
  - apps/desktop/src/surfaces/code/leftRail/ObrasSection.tsx
  - apps/desktop/src/surfaces/code/leftRail/SessionsSection.tsx
  - apps/desktop/src/surfaces/code/leftRail/LeftRail.tsx
  - apps/desktop/src/surfaces/code/leftRail/LeftRailPrimitives.tsx
  - apps/desktop/src/surfaces/code/stage/ObraCommandCenterPanel.tsx
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-code-visual-ergonomics-enterprise-polish-v1
graph_title: Atlas Code Visual Ergonomics & Enterprise Polish v1
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-code-obra-command-center-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-code-visual-ergonomics-enterprise-polish-v1.md
allowed_changes:
  - Adicionar variantes de tokens enterprise (--cc-*) sem remover existentes.
  - Adicionar status dots/badges novos para estados canônicos novos do Forge.
  - Estender escala tipografica se houver caso operacional honesto.
forbidden_changes:
  - Reintroduzir letter-spacing exagerado em body/botoes/dados.
  - Voltar serif decorativo italic em paragrafos operacionais longos.
  - Adicionar gradientes/blobs/orbs decorativos genericos.
  - Misturar provas desta Obra com Certificacoes do sistema visualmente.
  - Esconder status real do backend com cor verde fake.
  - Mover logica de governanca para CSS/Frontend.
  - Promover completion claim ou chamar provider externo do front.
depends_on:
  - atlas-code-obra-command-center-v1
  - atlas-code-human-interface-upgrade-v2
flows_to:
  - atlas-code
unlocks:
  - twelve_hour_workstation_visual_polish
governs:
  - atlas_code_visual_ergonomics
evidence:
  - docs/engineering-knowledge-base/atlas-code-visual-ergonomics-enterprise-polish-v1.md
  - apps/desktop/src/index.css
required_tests:
  - "npm run build --workspace=@atlas/desktop"
  - "npm run lint --workspace=@atlas/desktop"
  - "php artisan atlas:programming:completion-audit --json"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: low
next_actions:
  - Migrar tabelas/listas tecnicas restantes (ProvenanceTopologyPanel, EvidencePanel completos) para cc-* tokens.
  - Adicionar toggle de densidade na ObraBar quando houver demanda real.
  - Validar contraste AA em todos os pares texto/fundo dos tokens enterprise.
---

# Atlas Code Visual Ergonomics & Enterprise Polish v1

> Schema canonico: `atlas.code.visual_ergonomics_certification.v1`
> Audit cert: `atlas.code.visual_ergonomics_certification.v1`
> Status: implementado · 2026-05-14

## Resumo
Refinamento visual enterprise do Atlas Code para uso prolongado (12h workstation). Camada `@layer enterprise` em `index.css` adiciona tokens `--cc-*` para paleta, tipografia, spacing, focus, density, status colors e shadows. Componentes core (LeftRail, ObrasSection, SessionsSection, ObraCommandCenterPanel, Composer, TopBar) adotam os tokens sem quebrar o DNA editorial existente. Tipografia sans operacional, paleta menos monocromatica, status colors distintos, scrollbar warm-tinted, focus-visible acessivel. Diagnostico tecnico permanece em camada secundaria (`<details>Ver detalhes tecnicos</details>`).

## Papel no Atlas
Eleva a experiencia visual do Atlas Code de prototipo cru (~5.5/10) para enterprise polish 8.5/10+, mantendo a identidade Atlas calma/sofisticada/warm. Reduz fadiga visual em 12h de trabalho continuo: contraste confortavel, tipografia legivel, hierarquia clara, status sem cores agressivas. Nao redesigna radicalmente — refina o que ja existe.

## Onde Se Encaixa
Filho de [[atlas-code-obra-command-center-v1]] (centro humano) e [[atlas-code-human-interface-upgrade-v2]] (estado humano priorizado). Camada exclusivamente visual: nao altera contratos do Forge/Review/Provider/Self-Improvement. Mantem [[atlas-code-forge-human-first-ux-orchestrator-v1]] e [[atlas-forge-continuum-os]] intocados em logica.

## Contratos
- Tokens enterprise: `@layer enterprise` em `apps/desktop/src/index.css`.
- Paleta: `--cc-bg`, `--cc-surface`, `--cc-surface-raised`, `--cc-surface-sunken`, `--cc-text`, `--cc-text-strong`, `--cc-text-muted`, `--cc-text-faint`, `--cc-text-disabled`, `--cc-border-soft`, `--cc-border`, `--cc-border-strong`, `--cc-accent` (deep umber), `--cc-success` (moss), `--cc-warning` (amber/ochre), `--cc-danger` (oxblood), `--cc-info` (steel), `--cc-neutral`.
- Tipografia: `--cc-font-sans` (Inter), `--cc-font-serif` (Cormorant), `--cc-font-mono` (JetBrains Mono); escala `--cc-text-display/title/section/body/body-sm/caption/label/data`; leadings `--cc-leading-tight/snug/normal/relaxed`; tracking `--cc-tracking-tight/normal/label/data`.
- Spacing: scale 4px base (`--cc-space-0..12`).
- Density: `--cc-density: comfortable | compact` via `data-cc-density`.
- Focus: `--cc-focus-ring` em `:focus-visible` global.
- Classes utilitarias: `cc-btn` (primary/secondary/ghost/danger/sm), `cc-input`, `cc-textarea`, `cc-label`, `cc-eyebrow`, `cc-badge[data-tone]`, `cc-status-dot[data-status]`, `cc-card`, `cc-empty`, `cc-loading`, `cc-error`, `cc-section-title`, `cc-obra-row`, `cc-rail-section`.
- Audit cert: `atlas.code.visual_ergonomics_certification.v1` em `ProgrammingProfessionalCompletionAuditService::atlasCodeVisualErgonomicsCertification()` (21 invariantes).

## Fluxo
1. `index.css` carrega `@layer reset, tokens, enterprise, base, layout, components, motion`. 2. `:root` define paleta editorial legacy (cream/ink/bronze) e tokens enterprise (`--cc-*`). 3. Body herda `--cc-font-sans`/`--cc-text` por padrao. 4. Componentes legacy preservam tokens originais (sem quebra); componentes refatorados (LeftRail, ObrasSection, SessionsSection, LeftRail nova-obra, ObraCommandCenterPanel, ops-tabs, brand, surface-switcher, obra-bar, conv-msg, composer) usam tokens enterprise. 5. `data-status` no `.cc-status-dot` mapeia estados canonicos (running/queued/blocked/review/passed/proven/unknown/ready) para cores semanticas distinguiveis sem depender so de cor. 6. `:focus-visible` global aplica ring keyboard-only.

## Regras para IA
Nunca reintroduzir letter-spacing exagerado em body/botoes/dados. Nunca voltar serif italic decorativo em paragrafos operacionais longos. Nunca adicionar gradientes/blobs/orbs decorativos. Nunca esconder status real do backend com cor verde fake. Nunca depender so de cor para sinalizar estado — sempre dot + label + tom. Toda mudanca visual deve passar pelos tokens `--cc-*` (sem hardcode de cor). Toda nova classe utilitaria entra em `@layer components` com prefixo `cc-`. Diagnostico tecnico (JSON, IDs, logs) fica em `<details>` ou aba Avancado, nunca como experiencia primaria.

## Escopo de Implementacao
- `apps/desktop/src/index.css`: nova camada `@layer enterprise` com tokens, base body em sans, focus-visible, scrollbar, classes utilitarias `cc-*`. Tokens legacy preservados.
- `LeftRail.tsx`: CreateObraControl com `cc-btn cc-btn-primary` + `cc-input` + `cc-textarea` + `cc-error`.
- `ObrasSection.tsx`: ObraRow com `cc-obra-row`, status dot, short_id, title, meta limpa, busca local quando ≥4 obras, empty/loading honestos.
- `SessionsSection.tsx`: row compacta com status dot + origin + turnos.
- `LeftRailPrimitives.tsx`: `cc-rail-section` head (sans, count em mono).
- `ObraCommandCenterPanel.tsx`: header com status dot, lifecycle tone mapping (success/warning/danger/info/accent/neutral), badges semanticos, safety strip explicito, advanced collapsed.
- TopBar/SurfaceSwitcher/ObraBar/Composer: classes enterprise.
- Audit: `atlasCodeVisualErgonomicsCertification()` com 21 invariantes (design_tokens_available, left_rail_polished, active_obra_state_visible, long_session_typography_available, color_palette_not_monochrome, status_colors_available, focus_states_available, empty_states_available, command_center_visual_polished, intake_form_polished, evidence_tables_polished, advanced_details_deemphasized, no_external_provider_call, no_token_spend, no_completion_claim_promotion, sessions_polished, enterprise_buttons_available, enterprise_inputs_available, scrollbar_polished, density_token_available, visual_ergonomics_doc_present).

## Dependencias
[[atlas-code-obra-command-center-v1]], [[atlas-code-human-interface-upgrade-v2]], [[atlas-code-forge-human-first-ux-orchestrator-v1]]. Audit final via [[atlas-ai-programming-professional-completion-audit]].

## Evidencias
- `npm run build --workspace=@atlas/desktop` — verde.
- `npm run lint --workspace=@atlas/desktop` — 0 errors.
- `php artisan atlas:programming:completion-audit --json` — `atlas_code_visual_ergonomics_certification.status = available`.
- `php artisan atlas:engineering:knowledge docs-health --json` — sem violacoes na nova doc.
- Screenshots Playwright em viewports 1440/1280/1024: `/tmp/atlas-vqa/screenshots/atlas-code-vqa-*.png`.

## Riscos
1. Tokens enterprise quebrando temas legacy: mitigado por coexistencia (camada `@layer enterprise` adicional, tokens `--cc-*` distintos de `--cream/--ink/--bronze`). 2. Componentes nao migrados ficando inconsistentes: mitigado por adocao incremental (LeftRail + Command Center primeiro; tabelas tecnicas continuam podendo usar tokens legacy ate migracao futura). 3. Contraste insuficiente em algum par texto/fundo: mitigado por escala curada e testes visuais; A11y AA validacao listada em next_actions. 4. Cor sozinha como sinal: mitigado por status dot + label + tom semantico.

## Exemplos
```html
<button class="cc-btn cc-btn-primary">Preparar Forge</button>
<div class="cc-obra-row" data-active="true">
  <div class="cc-obra-row-head">
    <span class="cc-status-dot" data-status="running"></span>
    <span class="cc-obra-row-id">7f3a9c</span>
    <span class="cc-obra-row-title">Atlas Code Visual Polish</span>
  </div>
</div>
<span class="cc-badge" data-tone="warning">aguardando review</span>
<div class="cc-empty">
  <div class="cc-empty-title">Nenhuma Obra ativa</div>
  <div class="cc-empty-hint">Use o botao acima para criar a primeira.</div>
</div>
```
Visual QA: `node /tmp/atlas-vqa/take-screenshots.mjs` gera screenshots em viewports 1440/1280/1024.

## Proximas Acoes
Migrar tabelas/listas tecnicas restantes para `cc-*` quando voltarem a ser tocadas. Toggle de densidade na ObraBar (data-cc-density='compact') quando houver demanda real do operador. Validar contraste AA com ferramenta acessibilidade nos pares texto/fundo.
