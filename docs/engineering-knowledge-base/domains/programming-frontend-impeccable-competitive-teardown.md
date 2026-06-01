---
id: atlas-ai-programming-frontend-impeccable-competitive-teardown
type: engineering_knowledge
title: Atlas Frontend Impeccable Competitive Teardown
status: active
category: architecture
priority: 97
summary: Dossie tecnico do Impeccable como benchmark competitivo para `programming.frontend`, com capacidades, arquitetura, gaps e blueprint para Atlas Frontend superar o estado da arte.
tags:
  - atlas-ai
  - programming
  - frontend
  - design-harness
  - competitive-teardown
  - impeccable
capabilities:
  - frontend_competitive_teardown
  - frontend_design_runtime_blueprint
  - frontend_visual_quality_gates
  - frontend_live_iteration_runtime
decisions:
  - Impeccable e benchmark forte para design/frontend multiempresa, nao apenas prompt library.
  - Atlas deve absorver principios e padroes operacionais, nao copiar codigo, assets, textos ou produto.
  - A vantagem Atlas deve ser runtime governado: AEDPDS, Dev, Forge, Evidence, AEMOR, ACRUI e certificacao.
  - `programming.frontend` deve virar runtime multiempresa com onboarding, design context, craft, live iteration, detector, debt memory e pre-ship gauntlet.
maintenance:
  - Atualizar quando Impeccable mudar arquitetura publica, quando Atlas implementar Frontend Design Runtime, ou quando novo benchmark superar esse nivel.
related_paths:
  - docs/engineering-knowledge-base/domains/programming-frontend-superpower.md
  - docs/engineering-knowledge-base/domains/programming-frontend-impeccable-code-inventory.md
  - docs/engineering-knowledge-base/domains/programming-frontend-impeccable-skill-command-flow.md
  - docs/engineering-knowledge-base/domains/programming-frontend-impeccable-detector-extension.md
  - docs/engineering-knowledge-base/domains/programming-frontend-impeccable-live-mode.md
  - docs/engineering-knowledge-base/domains/programming-frontend-impeccable-build-test-release.md
  - docs/engineering-knowledge-base/domains/programming-frontend-impeccable-product-site-assets.md
  - docs/engineering-knowledge-base/domains/programming-frontend-impeccable-coverage-audit.md
  - docs/engineering-knowledge-base/domains/programming.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php
  - tests/Unit/Ai/AtlasProgrammingOrchestratorTest.php
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-programming-frontend-impeccable-competitive-teardown
graph_title: Atlas Frontend Impeccable Competitive Teardown
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-programming-frontend-superpower
graph_status: active
graph_source: repo
human_name: Atlas Frontend Impeccable Competitive Teardown
canonical_name: Atlas Frontend Impeccable Competitive Teardown
technical_name: atlas-ai-programming-frontend-impeccable-competitive-teardown
cartography_type: module
canonical_source: docs/engineering-knowledge-base/domains/programming-frontend-impeccable-competitive-teardown.md

owner: domains
repo_paths:
  - docs/engineering-knowledge-base/domains/programming-frontend-impeccable-competitive-teardown.md
allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.
forbidden_changes:
  - Declarar Atlas superior ao benchmark sem runtime, testes, visual evidence e certificacao granulares.
depends_on:
  - atlas-ai-programming-frontend-superpower
flows_to:
  - programming.frontend
  - programming.visual
unlocks:
  - atlas-frontend-design-runtime
governs:
  - domains
evidence:
  - docs/engineering-knowledge-base/domains/programming-frontend-impeccable-competitive-teardown.md
evidence_refs:
  - symbol: AtlasProgrammingOrchestrator
  - test: AtlasProgrammingOrchestratorTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: medium
visual_tags:
  - system
  - module
  - frontend
ai_entrypoints:
  - Leia Resumo, Estado Do Benchmark, Padroes A Absorver e Blueprint Atlas antes de implementar runtime frontend multiempresa.
ai_usage_notes:
  - Use este doc como benchmark e blueprint, nao como autorizacao para copiar codigo externo.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Confundir skill prompt com runtime real.
  - Copiar Impeccable sem integrar AEDPDS, Dev, Forge, Evidence e Outcome Memory.
observability_signals:
  - docs-health status ok
next_actions:
  - Implementar Atlas Frontend Design Runtime e certificar com testes/evidence.
---
# Atlas Frontend Impeccable Competitive Teardown

Este documento disseca `pbakaus/impeccable` como benchmark competitivo para o
Atlas Frontend multiempresa. A leitura correta: Impeccable nao e so uma skill
bonita; e um produto operacional de design assistido por IA com skill, comandos,
detector deterministico, browser live mode, extensao, testes e build
multi-provider.

Fonte auditada: `github.com/pbakaus/impeccable`, commit
`84135db0e6bdd58d22828f7bc8331cae7bde3e7f`, datado de 2026-05-22.

## Resumo

Impeccable e o benchmark mais relevante para `programming.frontend` porque une
vocabulario de design, contexto por projeto, comandos orientados por disciplina,
detector de "AI slop", modo visual no browser e manutencao de divida de design.

Atlas so supera isso se transformar frontend em runtime governado:

```text
company/project onboarding
-> PRODUCT/DESIGN context
-> AEDPDS driver selection
-> frontend craft runtime
-> live variants and source patch
-> deterministic visual detector
-> pre-ship gauntlet
-> Dev/Forge evidence
-> AEMOR outcome learning
-> certification
```

Sem esse runtime, Atlas fica bom em governanca e mais fraco em craft visual.
Com esse runtime, Atlas pode vencer por cobertura enterprise e prova ponta a
ponta.

## Papel no Atlas

Este doc e benchmark e blueprint para transformar Atlas Frontend em capacidade
multiempresa. Ele nao cria dominio novo: `programming.frontend` continua dentro
do Programming Domain. A funcao do doc e orientar implementacao futura de
runtime, comandos, detector, live iteration, design debt e certificacao.

## Onde Se Encaixa

O dossie fica abaixo de `programming-frontend-superpower.md` e acima de futuras
APs ou services concretos. Ele deve ser lido quando a tarefa envolver frontend
multiempresa, design harness, anti-slop detector, browser live iteration,
design-system onboarding ou comparacao com Impeccable/Claude Design.

## Contratos

Contratos alvo que este doc recomenda:

- `atlas.frontend.project_context.v1`;
- `atlas.frontend.design_context.v1`;
- `atlas.frontend.doctrine_selection.v1`;
- `atlas.frontend.visual_direction.v1`;
- `atlas.frontend.live_iteration_event.v1`;
- `atlas.frontend.variant_packet.v1`;
- `atlas.frontend.pre_ship_gauntlet.v1`;
- `atlas.frontend.detector_findings.v1`;
- `atlas.frontend.design_debt.v1`;
- `atlas.frontend.certification.v1`.

## Fluxo

Fluxo Atlas recomendado:

```text
Frontend Project Onboarding
-> Frontend Design Context
-> AEDPDS frontend doctrine
-> Shape
-> Visual Direction
-> Craft in real framework
-> Live iteration when useful
-> Deterministic detector
-> Pre-ship gauntlet
-> Dev/Forge evidence packet
-> AEMOR outcome learning
-> Certification
```

## Regras para IA

1. Nao declarar Atlas superior ao Impeccable sem runtime e evidence.
2. Nao copiar codigo, assets, textos ou produto externo; absorver padroes.
3. Nao criar frontend como dominio paralelo fora de Programming.
4. Nao aceitar screenshot isolado como prova.
5. Nao codar UI multiempresa sem contexto de produto, marca e design system.
6. Nao hardcodar provider para frontend; medir via AP-99/Rivals.
7. Nao pular Dev/Forge/evidence/outcome quando a entrega vira produto real.

## Escopo de Implementacao

Permitido: services e commands sob Programming, detectors, contracts, tests,
docs e integration points com Dev/Forge. Fora de escopo: copiar Impeccable,
vender assets externos, mover frontend para dominio proprio ou criar UX pesada
sem runtime.

## Dependencias

- AEDPDS para selecionar UX, ATDD, TDD, Risk e Evidence drivers.
- Programming Domain para flows `programming.frontend` e `programming.visual`.
- Dev/Forge para executar patch curto ou obra pesada.
- ACRUI e Software Twin para evitar source mutation insegura.
- Evidence Ledger e AEMOR para provar e aprender outcomes.
- Glossario canonico para nomenclatura Dev/Forge/Atlas Code.

## Evidencias

Evidencia desta analise:

- clone local atualizado do repo Impeccable no commit citado;
- leitura de `README.md`, `skill/SKILL.md`, `skill/reference/*`,
  `cli/engine/*`, `skill/scripts/live*`, `scripts/build.js`, `PRODUCT.md`,
  `DESIGN.md`, `AGENTS.md` e `DEVELOP.md`;
- contagem auditada: 36 referencias, 24 scripts de skill, 17 arquivos em
  `cli/engine`, 29 regras deterministicas, 320 arquivos de testes.

## Riscos

| Risco | Mitigacao |
|---|---|
| Copia cega do benchmark | Implementacao Atlas-owned e revisao juridica quando houver codigo externo |
| Runtime visual sem prova | Certificacao strict com visual/a11y/perf/state evidence |
| Detector opinativo demais | Aplicar por brand/product lane e policy do projeto |
| Live mode mexer no source errado | ACRUI, Software Twin, boundary contract e source mapping |
| Atlas vender doc-only como pronto | Certification command bloqueia sem services/tests/evidence |

## Exemplos

Exemplos de intents Atlas:

- "crie uma landing para uma fintech": `frontend.teach`, `shape`,
  `visual_direction`, `craft`, `pre_ship_gauntlet`;
- "essa tabela esta feia": `frontend.audit`, `critique`, `layout`, `harden`;
- "mude esse hero no browser": `frontend.live` com variantes e accept;
- "padronize componentes repetidos": `frontend.extract` e design debt report.

## Proximas Acoes

1. Criar AP ou spec de `Atlas Frontend Design Runtime`.
2. Implementar detector minimo anti-slop/quality.
3. Criar command surface `atlas:frontend:*`.
4. Integrar `programming.frontend` ao Dev/Forge evidence packet.
5. Adicionar certification service e testes.
6. Rodar benchmark contra Impeccable usando casos frontend reais.

## Anatomia Do Impeccable

| Camada | Evidencia no repo auditado | Valor |
|---|---|---|
| Skill principal | `skill/SKILL.md` | Doutrina, setup, leis de design, roteador de comandos |
| Referencias | 36 arquivos em `skill/reference` | Especialistas por disciplina: type, color, motion, UX, live, craft |
| Scripts skill | 24 arquivos em `skill/scripts` | Context loader, live mode, wrap, accept, poll, storage, pin |
| CLI detector | `cli/engine` com 17 arquivos | Anti-patterns e quality checks sem depender do LLM |
| Extensao/browser | `extension` | Detector e painel visual no navegador |
| Site/docs | `site` | Produto/documentacao/casos/publicacao |
| Tests | 320 arquivos em `tests` | Detector, live mode, provider output, fixtures, regressao |
| Build multi-provider | `scripts/build.js` | Claude, Codex, Cursor, Gemini, OpenCode, Copilot e outros |

O core de produto e: **1 skill, 23 comandos, 29 regras deterministicas, Live
Mode e contexto por projeto**.

## Loop Operacional

Impeccable organiza design como ciclo:

| Fase | Comandos | Funcao |
|---|---|---|
| Start | `teach`, `shape`, `craft` | Descobrir contexto, planejar UX/UI, construir |
| Iterate | `live`, `typeset`, `layout`, `colorize`, `animate` | Variar e corrigir uma superficie existente |
| Polish | `audit`, `critique`, `clarify`, `harden`, `polish` | Medir, revisar, copy, edge cases e acabamento |
| Maintain | `extract`, `document` | Consolidar tokens/componentes e recapturar design system |

Essa sequencia e mais valiosa que qualquer prompt isolado. Ela transforma
frontend em uma rotina repetivel.

## Contexto Por Projeto

Impeccable usa dois documentos de raiz:

| Arquivo | Papel |
|---|---|
| `PRODUCT.md` | Registro brand/product, usuarios, proposito, personalidade, anti-referencias, principios e acessibilidade |
| `DESIGN.md` | Tokens e linguagem visual: cores, tipografia, elevacao, componentes, do/don't |

O loader procura esses arquivos antes de qualquer comando. Se `PRODUCT.md`
falta, `teach` vira blocker. Se `DESIGN.md` falta, o sistema recomenda
`document`.

Licao para Atlas: `programming.frontend` precisa de **Frontend Company Context**,
nao so prompt. Para cada empresa/projeto, Atlas deve saber publico, marca,
anti-referencias, design tokens, stack, componentes, acessibilidade,
performance budget, screenshots e outcomes anteriores.

## Brand Vs Product

Impeccable separa duas lanes:

| Lane | Quando usar | Consequencia |
|---|---|---|
| Brand | landing, campanha, editorial, portfolio | design e o produto; mais expressivo |
| Product | app, dashboard, admin, ferramenta | design serve a tarefa; mais denso e funcional |

Essa decisao ajusta vocabulário, estetica, densidade e criteria de qualidade.
Atlas deve fazer isso no AEDPDS/selector: `frontend_brand_surface` e
`frontend_product_surface` precisam de gates e scoring diferentes.

## Comandos Como Vocabulario

Os 23 comandos sao uma linguagem operacional compartilhada:

| Familia | Comandos | Valor para Atlas |
|---|---|---|
| Build | `craft`, `shape`, `teach`, `document`, `extract` | Cria contexto, brief, design system e componentes |
| Evaluate | `critique`, `audit` | Separa julgamento estetico de scan tecnico |
| Refine | `polish`, `bolder`, `quieter`, `distill`, `harden`, `onboard` | Corrige direcao visual e prontidao de producao |
| Enhance | `animate`, `colorize`, `typeset`, `layout`, `delight`, `overdrive` | Intervencoes nomeadas por disciplina |
| Fix | `clarify`, `adapt`, `optimize`, `live` | Copy, responsivo, performance e variantes no browser |

Atlas deve implementar isso como intents/runtimes, nao slash commands soltos:
`frontend.shape`, `frontend.craft`, `frontend.live`, `frontend.audit`,
`frontend.harden`, `frontend.extract`, `frontend.document`.

## Detector Deterministico

O detector tem 29 regras: 16 de `slop` e 13 de `quality`.

Regras de slop relevantes:

- `side-tab`, `border-accent-on-rounded`;
- `overused-font`, `single-font`, `flat-type-hierarchy`;
- `gradient-text`, `ai-color-palette`, `dark-glow`;
- `nested-cards`, `monotonous-spacing`, `everything-centered`;
- `icon-tile-stack`, `hero-eyebrow-chip`, `repeated-section-kickers`;
- `bounce-easing`, `italic-serif-display`.

Regras de qualidade relevantes:

- `low-contrast`, `gray-on-color`, `pure-black-white`;
- `layout-transition`, `line-length`, `cramped-padding`;
- `body-text-viewport-edge`, `tight-leading`, `skipped-heading`;
- `justified-text`, `tiny-text`, `all-caps-body`, `wide-tracking`.

Motores:

| Engine | Uso |
|---|---|
| regex/text | CSS, JSX, TSX e arquivos nao HTML |
| static HTML/CSS | parse HTML, cascade CSS e checks por elemento/pagina |
| browser/Puppeteer | layout real, computed styles, viewport |
| visual screenshot | contraste por pixel quando fundo visual engana o analitico |

Licao para Atlas: gosto precisa virar detector. O Atlas Frontend Detector deve
ser tool runtime com manifest JSON, findings P0-P3, false-positive policy,
evidence refs e linkage com Dev/Forge.

## Live Mode

Live Mode e a maior diferenca competitiva. Fluxo real:

1. `live.mjs` inicia helper server e injeta script.
2. Browser envia eventos por SSE/HTTP.
3. Usuario seleciona elemento e escolhe acao.
4. Agente recebe outerHTML, computed styles, comentarios, strokes e screenshot
   anotado quando existe.
5. `live-wrap.mjs` localiza source e insere wrapper seguro.
6. Agente escreve 2-4 variantes em bloco unico.
7. Browser mostra variantes via HMR.
8. Usuario aceita ou descarta.
9. `live-accept.mjs` preserva a variante aceita e remove scaffolding.
10. Journal/session store permite recovery.

Pontos tecnicos fortes:

- token local e validacao de evento;
- long poll com timeout alto;
- journal duravel em `.impeccable/live/sessions`;
- protecao contra arquivo gerado;
- suporte JSX/HTML/Astro style mode;
- annotations com comentarios e strokes;
- parametros tunaveis por variante;
- accept/discard e cleanup.

Atlas deve superar com `AtlasFrontendLiveIterationRuntime`: usar source mapping,
Software Twin, ACRUI reachability, AVEOR boundary, visual evidence, outcome
learning e rollback plan. O operador deve conseguir apontar, pedir variantes,
aceitar uma, e receber evidence ledger, nao apenas patch.

## Critique E Audit

Impeccable separa:

- `audit`: score tecnico 0-4 em a11y, performance, theming, responsive e
  anti-patterns;
- `critique`: design director review, heuristicas de Nielsen, cognitive load,
  emotional journey, personas e detector isolado.

Ponto excelente: `critique` exige duas avaliacoes independentes antes da
sintese. Isso reduz anchoring do detector sobre o julgamento estetico.

Atlas deve absorver como `FrontendReviewBoard`:

```text
Assessment A: designer/UX review
Assessment B: deterministic/browser scan
Assessment C: product/domain fit
Assessment D: AEDPDS gate/evidence check
Synthesis: findings, severity, repair plan, outcome hypothesis
```

## Craft E Image Direction

No Codex, Impeccable usa fluxo visual antes do codigo:

1. shape brief confirmado;
2. perguntas de direcao;
3. paleta confirmada;
4. mocks high-fidelity;
5. direcao aprovada;
6. inventario de ingredientes do mock;
7. asset production;
8. build e verificacao.

Isso resolve o problema classico: o agente codar CSS abstrato sem referencia
visual concreta.

Atlas deve implementar `FrontendVisualDirectionRuntime`: gerar direcoes,
registrar decisoes, comparar implementacao contra ingredientes aprovados e
bloquear se hero, composicao, assets ou estados principais sumirem.

## Manutencao E Divida De Design

`document` captura DESIGN.md a partir de tokens/componentes/rendered output.
`extract` encontra padroes usados 3+ vezes e consolida componentes/tokens.

Licao: design system e memoria viva, nao doc manual. Atlas deve ter:

- `FrontendDesignSystemScanner`;
- `FrontendTokenDriftDetector`;
- `FrontendComponentConsolidationPlanner`;
- `DesignDebtOutcomeMemory`.

## Onde Impeccable E Fraco

| Gap | Impacto | Como Atlas supera |
|---|---|---|
| Gate e instrucao, nao enforcement central | Agente pode ignorar passos | AEDPDS gate bloqueia/escala |
| Sem Evidence Ledger enterprise | Prova fica dispersa | Atlas registra receipts e outcomes |
| Sem Forge/Obra | Trabalho grande vira sessao de agente | Forge packets/milestones/FSORB |
| Sem ACRUI/Software Twin | Risco maior em source matching/deletes | Reachability, boundary e patch simulation |
| Sem certificacao global | Maturidade fica implicita | `atlas:frontend-design:certify --strict` |
| Detector com opinioes universais | Pode conflitar com marcas | Atlas aplica por lane/contexto/brand policy |
| Live Mode complexo por framework | Fragil em HMR/source mapping | Atlas usa Code Intelligence e adapters por stack |
| Multi-provider build, nao multi-provider eval | Distribui skill; nao mede vencedor | AP-99/Rivals mede provider por tarefa |

## Blueprint Atlas Para Superar

### Runtimes

| Runtime | Responsabilidade |
|---|---|
| `AtlasFrontendProjectOnboardingRuntime` | contexto por empresa: produto, usuarios, brand, anti refs, acessibilidade, stack e design system |
| `AtlasFrontendDesignContextRuntime` | resolve PRODUCT/DESIGN, tokens, componentes, screenshots, rotas, constraints e prior outcomes |
| `AtlasFrontendDoctrineSelector` | decide brand/product lane, AEDPDS drivers e gates |
| `AtlasFrontendShapeRuntime` | gera brief com criterios de aceite e perguntas minimas |
| `AtlasFrontendVisualDirectionRuntime` | gera/avalia direcoes, paletas, mock ingredients e asset plan |
| `AtlasFrontendCraftRuntime` | implementa no framework real, sem criar app paralelo |
| `AtlasFrontendLiveIterationRuntime` | browser pick, variants, accept/discard, source patch e recovery |
| `AtlasFrontendQualityDetector` | anti-slop, a11y, responsive, perf, state, copy e visual contrast |
| `AtlasFrontendPreShipGauntlet` | multi-viewport, console/network, states, edge data, i18n e visual diff |
| `AtlasFrontendDesignDebtRuntime` | document/extract/drift/consolidation |
| `AtlasFrontendOutcomeMemory` | aprende direcoes, providers, tokens e repair loops que funcionaram |
| `AtlasFrontendDesignCertificationService` | prova readiness e bloqueia doc-only ready |

Comandos alvo: `atlas:frontend:teach`, `document`, `shape`, `craft`, `live`,
`audit`, `critique`, `harden`, `extract` e `certify --strict --json`.

## Certificacao 10/10

Atlas so pode declarar superior ao Impeccable quando:

1. onboarding multiempresa existe;
2. contexto PRODUCT/DESIGN equivalente existe;
3. brand/product lane e AEDPDS estao integrados;
4. craft exige visual direction quando necessario;
5. live iteration escreve source real com recovery;
6. detector deterministico cobre AI slop e quality;
7. pre-ship gauntlet emite evidence;
8. Dev e Forge consomem esses gates;
9. AEMOR registra outcome por driver/design/provider;
10. certification command retorna ready com checks granulares;
11. testes provam selector, blocking, detector, live event, Dev/Forge e outcome;
12. docs nao bastam para ready.

## Ranking Honesto Atual

| Area | Impeccable | Atlas atual | Atlas alvo |
|---|---:|---:|---:|
| Design craft multiempresa | 9.5 | 8.0 | 10 |
| Live visual iteration | 9.5 | 6.5 | 10 |
| Detector anti-slop | 9.0 | 6.5 | 10 |
| Governance/runtime | 7.5 | 10 | 10 |
| Evidence/outcome | 6.5 | 10 | 10 |
| Dev/Forge integration | 6.0 | 10 | 10 |
| Product certification | 6.5 | 10 | 10 |
| Overall frontend assistant | 9.0 | 8.5 | 10 |

Conclusao: Impeccable vence hoje em design craft e live iteration. Atlas vence
em governanca. O produto vencedor e a combinacao: craft operacional do
Impeccable com enforcement, evidence, Dev/Forge e learning do Atlas.
