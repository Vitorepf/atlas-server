---
id: atlas-code-codex-slate-premium-v1
type: engineering_knowledge
title: Atlas Code Codex Slate Premium v1
status: active
category: programming-forge
priority: 95
summary: Estética canon definitiva do Atlas Code Desktop · slate teal Codex-inspired bg + atlas gold burnished accent + cool cream text. Tipografia sans operacional, status colors saturados sem agressao, status dots estaticos calmos (sem pulse cafona), letter-spacing zero em corpo/botoes/dados. Cartografia preservada warm cream legacy.
tags:
  - atlas
  - atlas-code
  - ux
  - canon
  - codex-inspired
  - slate-teal
  - premium
capabilities:
  - atlas_code_codex_slate_premium
decisions:
  - Background base e slate teal Codex-inspired (`#1d2b34`) com superficies escalonadas (`#243743`/`#2d4351`/`#15212a`); proibido warm graphite ou preto puro como bg.
  - Terminal usa `#11202a` (slate teal mais escuro) em vez de preto.
  - Accent unico e atlas gold burnished (`#d4a85a`/`#e6b966`) com parcimonia em CTA/active/focus; texto sobre gold e slate dark `#15212a`.
  - Status colors recalibrados para slate: success `#82b577` moss saturado, warning `#e0ad5e` amber, danger `#d05a52` warm red, info `#7fa7c4` steel blue, neutral `#95a3ac` graphite cool.
  - Texto cool cream sobre slate: `#f0f4f7` strong, `#d6dde2` body, `#95a3ac` muted, `#677482` faint.
  - Status dots SEMPRE estaticos. Animacao pulse halo e cafona — proibida. Sinalizacao via cor + label, nunca movimento.
  - Tipografia: Inter sans para body/data/buttons; Cormorant serif APENAS para display titles especiais; mono pra IDs/hashes/comandos. Letter-spacing 0 em corpo; max 0.04em em eyebrows/labels.
  - Bordas alpha cool (`rgba(233, 238, 242, 0.05-0.20)`); shadows deep slate (`rgba(0,0,0,0.20-0.38)`).
  - Tema aplicado APENAS no escopo `.atlas-shell.surface-code`. Cartografia preserva warm cream legacy intacto.
  - Legacy token bridge: dentro do shell Code, tokens `--cream-*/--ink-*/--bronze-*/--hair*` redefinidos para slate equivalents, propagando o tema para painels que ainda nao migraram para `--cc-*`.
  - Mass polish via sed em painels legacy: eliminado letter-spacing 1.1-1.8px, textTransform uppercase exagerado, fontFamily serif italic e fontStyle italic em corpo operacional.
maintenance:
  - Atualize quando o canon visual mudar (paleta, tipografia, accent).
  - Mantenha Cartografia intocada (warm cream legacy preservado).
  - Antes de adicionar nova cor de bg/text/border, sempre via tokens `--cc-*` (proibido hardcode).
related_paths:
  - apps/desktop/src/index.css
  - apps/desktop/src/surfaces/code/workbench/
  - apps/desktop/src/surfaces/code/panels/RightRailPrimitives.tsx
  - apps/desktop/src/surfaces/code/panels/ForgeHumanPanel.tsx
  - apps/desktop/src/surfaces/code/stage/ObraCommandCenterPanel.tsx
  - apps/desktop/src/surfaces/code/leftRail/ObrasSection.tsx
  - docs/engineering-knowledge-base/atlas-code-premium-workbench-visual-comfort-v1.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-code-codex-slate-premium-v1
graph_title: Atlas Code Codex Slate Premium v1
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-code-premium-workbench-visual-comfort-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-code-codex-slate-premium-v1.md
allowed_changes:
  - Adicionar variantes de tokens slate escalonados dentro do escopo `.atlas-shell.surface-code`.
  - Estender status colors saturados quando o Forge crescer (mantendo paleta calma).
  - Adicionar workbench primitives novos.
forbidden_changes:
  - Reintroduzir warm graphite ou preto puro como bg (`#23211c`, `#1c1a16`, `#16140f`, `#000`).
  - Voltar pulse animation com halo nos status dots (cafona).
  - Reintroduzir letter-spacing 1.0-1.8px em corpo/botoes/dados.
  - Voltar serif italic em paragrafos operacionais longos.
  - Mover tokens slate teal para `:root` global (quebra Cartografia).
  - Promover completion claim ou chamar provider externo via UI.
depends_on:
  - atlas-code-premium-workbench-visual-comfort-v1
  - atlas-code-visual-ergonomics-enterprise-polish-v1
flows_to:
  - atlas-code
unlocks:
  - codex_slate_premium_canon_definitivo
governs:
  - atlas_code_codex_slate_premium
evidence:
  - docs/engineering-knowledge-base/atlas-code-codex-slate-premium-v1.md
  - apps/desktop/src/index.css
required_tests:
  - "npm run build --workspace=@atlas/desktop"
  - "npm run tauri:build"
requires_evidence: true
risk_level: low
next_actions:
  - Mass polish nos painels tecnicos restantes (DiffScopeGuard, ForgeOperatorCockpitPanel, ForgeStageTimelinePanel, ForgeAsyncExecutionPanel, AtlasSelfImprovementGovernancePanel, ForgeRunReplayInspectorPanel, ForgeGovernedExecutionPanel, ForgeTaskQueuePanel, WorkItemInspector, ForgeWorkspaceBanner) — eles ja recebem slate via legacy token bridge mas podem ter inline styles ainda com letter-spacing/uppercase pendentes.
  - Validar contraste AA em todos pares texto/fundo do tema slate teal.
  - Toggle de densidade compact se houver demanda real.
---

# Atlas Code Codex Slate Premium v1

> Schema canonico: `atlas.code.codex_slate_premium.v1`
> Status: implementado · 2026-05-14
> Validacao visual: aprovada pelo operador ("essa combinaçao ficou maravilhosa")

## Resumo
Estetica canon definitiva do Atlas Code Desktop. Sai do tema warm graphite/preto (que ficou cafona com pulse halos e parecia laboratorio cru) para slate teal Codex-inspired premium: bg slate teal escuro, accent atlas gold burnished, cool cream text, status colors saturados e calmos. Tipografia sans operacional sem letter-spacing brutal. Status dots estaticos sem animacao halo. Tema escopo `.atlas-shell.surface-code` (Cartografia preservada warm cream legacy intacto).

## Papel no Atlas
Encerra a saga de polish visual do Atlas Code. Substitui tanto o tema warm cream chapado (v1) quanto o tema warm graphite cafona (workbench v1) por uma estetica que mira superior ao Cursor/Codex: slate teal + atlas gold + cool cream, sem decoracoes baratas (sem pulses, sem orbs, sem gradientes), com tipografia legivel em 12h.

## Onde Se Encaixa
Filho de [[atlas-code-premium-workbench-visual-comfort-v1]] e [[atlas-code-visual-ergonomics-enterprise-polish-v1]]. Reescreve tokens dentro do escopo `.atlas-shell.surface-code`. Cartografia preserva warm cream legacy (zero alteracao). Convive com [[atlas-code-obra-command-center-v1]] e [[atlas-code-human-interface-upgrade-v2]] sem alterar contratos.

## Contratos
**Paleta canon** (em `.atlas-shell.surface-code` em `apps/desktop/src/index.css`):
- bg base: `--cc-bg: #1d2b34` (slate teal Codex-inspired)
- surface: `--cc-surface: #243743`
- surface-raised: `--cc-surface-raised: #2d4351`
- surface-sunken: `--cc-surface-sunken: #15212a`
- terminal-dock: `#11202a` (slate teal escuro)

**Texto cool cream**:
- text-strong: `#f0f4f7`
- text: `#d6dde2`
- text-muted: `#95a3ac`
- text-faint: `#677482`
- text-disabled: `#3d4b54`

**Accent atlas gold burnished**:
- accent: `--cc-accent: #d4a85a`
- accent-strong: `--cc-accent-strong: #e6b966`
- accent-veil: `rgba(212, 168, 90, 0.12)`
- accent-border: `rgba(212, 168, 90, 0.34)`
- texto sobre gold: `#15212a` (slate dark legivel)

**Status colors tuned para slate**:
- success: `#82b577` (moss saturado) + fg `#b9e4ac`
- warning: `#e0ad5e` (amber) + fg `#f0cf94`
- danger: `#d05a52` (warm red) + fg `#e89a93`
- info: `#7fa7c4` (steel blue) + fg `#b9d0e0`
- neutral: `#95a3ac` (graphite cool) + fg `#c0c9d0`

**Bordas alpha cool**:
- border-soft: `rgba(233, 238, 242, 0.05)`
- border: `rgba(233, 238, 242, 0.10)`
- border-strong: `rgba(233, 238, 242, 0.20)`

**Shadows deep slate**:
- xs: `0 1px 0 rgba(0, 0, 0, 0.20)`
- sm: `0 2px 4px rgba(0, 0, 0, 0.26)`
- md: `0 6px 16px rgba(0, 0, 0, 0.32)`
- lg: `0 14px 32px rgba(0, 0, 0, 0.38)`

**Focus ring**:
- `0 0 0 2px var(--cc-bg), 0 0 0 4px rgba(230, 185, 102, 0.55)` (burnished gold sobre slate)

**Legacy token bridge** (dentro do shell Code):
- `--cream: #1d2b34`, `--cream-deep: #15212a`, `--cream-paper: #243743`, `--cream-canvas: #1d2b34`
- `--ink: #e9eef2`, `--ink2: #cdd6dc`, `--ink3: #8d9aa3`, `--ink4: #5d6c75`
- `--bronze: #d4a85a`, `--bronze-deep: #e6b966`, `--bronze-soft: rgba(212, 168, 90, 0.32)`, `--bronze-veil: rgba(212, 168, 90, 0.10)`
- `--hair: rgba(233, 238, 242, 0.10)`, `--hair-soft: rgba(233, 238, 242, 0.05)`
- `--prussian: #7fa7c4`, `--moss: #82b577`, `--rec-red: #d05a52`

## Fluxo
1. `index.css` carrega `@layer reset, tokens, enterprise, base, layout, components, motion`.
2. `:root` em `@layer enterprise` define tokens parchment-warm para Cartografia (preservados).
3. `.atlas-shell.surface-code` redefine TODOS os tokens `--cc-*` + `--cream*/--ink*/--bronze*/--hair*` para slate teal.
4. `.atlas-shell.surface-cartografia .topbar` mantem warm cream legacy explicito.
5. Componentes do Atlas Code consomem tokens via classes `cc-*` ou inline styles `var(--cc-*)`; legacy via redefinicao bridge.
6. Status dot e estatico (sem pulse animation); cor semantica indica estado.

## Regras para IA
Nunca reintroduzir warm graphite, preto puro ou bege chapado como bg. Nunca voltar pulse halo animation em status dots. Nunca letter-spacing > 0.04em em corpo. Nunca serif italic em paragrafo operacional. Nunca mover tokens slate para `:root` global. Toda nova cor via tokens `--cc-*`. Toda nova classe utilitaria via `cc-*` ou workbench primitive.

## Escopo de Implementacao
**Files modificados nessa entrega**:
- `apps/desktop/src/index.css`: paleta slate teal completa, legacy token bridge, polish overrides global Atlas Code, animacao pulse halo REMOVIDA.
- `apps/desktop/src/surfaces/code/workbench/StatusDot.tsx`: sem animacao halo.
- `apps/desktop/src/surfaces/code/panels/RightRailPrimitives.tsx`: btnPrimary em accent gold texto slate dark, Row em sans operacional, EmptyText em sans normal, sectionHeading sem uppercase.
- `apps/desktop/src/surfaces/code/panels/ForgeHumanPanel.tsx`: reescrito consumindo workbench primitives + tokens slate teal.
- `apps/desktop/src/surfaces/code/stage/ObraCommandCenterPanel.tsx`: tokens slate teal + Self-Improvement origin card.
- `apps/desktop/src/surfaces/code/leftRail/ObrasSection.tsx`: ObraListItem workbench primitive + busca local.
- Mass polish via sed em todos panels/stage/leftRail/terminal/obra/workbench: eliminado letter-spacing 1.1-1.8px, textTransform uppercase, serif italic.

**Files preservados (Cartografia)**:
- `apps/desktop/src/surfaces/cartografia/*`: warm cream legacy intacto via tokens `--cream-*/--ink-*/--bronze-*` no `:root` global.

## Dependencias
[[atlas-code-premium-workbench-visual-comfort-v1]] (workbench primitives base), [[atlas-code-visual-ergonomics-enterprise-polish-v1]] (tokens enterprise base), [[atlas-code-obra-command-center-v1]] (centro vivo), [[atlas-code-forge-human-first-ux-orchestrator-v1]] (forge cabin).

## Evidencias
- `npm run build --workspace=@atlas/desktop` — verde.
- `npm run tauri:build` — `.app/.dmg` aarch64 gerados em `target/release/bundle/`.
- Validacao visual operador (2026-05-14): "essa combinaçao ficou maravilhosa".
- Imagem aprovacao: top bar slate, left rail com Obras + status dots calmos, center stage com SDD mini bar, right rail Self-Improvement tab com Closed Loop Level 7 v1, terminal slate teal.

## Riscos
1. Componente novo usando hardcode de cor (`#fff`, `#000`, etc) que escapa tokens: mitigado por code review e busca regex periodica.
2. Linter trazendo de volta inline styles legacy com letter-spacing/uppercase em files novos: mitigado por mass polish via sed scriptado e doc canon.
3. Pessoas adicionando animation halo achando que e premium: explicitamente proibido nesta doc.
4. Tema slate sobrescrevendo Cartografia: mitigado por escopo `.atlas-shell.surface-code` e overrides explicitos em `.atlas-shell.surface-cartografia`.

## Exemplos
```css
/* Tokens slate teal corretos */
.atlas-shell.surface-code {
  --cc-bg: #1d2b34;
  --cc-accent: #d4a85a;
  --cc-text-strong: #f0f4f7;
}

/* Status dot canon · estatico, sem pulse */
.cc-status-dot[data-status='running'] { background: var(--cc-info); }
.cc-status-dot[data-pulse='true'] { box-shadow: none; animation: none; }
```

```tsx
// Botao primary canon · accent gold + slate dark text
<button style={btnPrimary}>Preparar Forge</button>

// Row canon · sans operacional sem uppercase
<Row k="Provider externo" v="não" ok={true} />
```

## Proximas Acoes
Mass polish nos painels tecnicos restantes (DiffScopeGuard, ForgeOperatorCockpitPanel, ForgeStageTimelinePanel, ForgeAsyncExecutionPanel, etc) — esses ja recebem slate via legacy token bridge mas podem ter inline styles ainda com letter-spacing/uppercase pendentes. Validar contraste AA em todos pares texto/fundo do tema slate teal. Toggle de densidade compact quando houver demanda real.
