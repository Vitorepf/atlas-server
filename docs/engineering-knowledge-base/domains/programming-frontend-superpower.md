---
id: atlas-ai-programming-frontend-superpower
type: engineering_knowledge
title: Atlas AI Programming Frontend Superpower
status: active
category: architecture
priority: 98
summary: Contrato alvo para transformar `programming.frontend` em um harness frontend/design superior a skills isoladas e ao fluxo de Claude Designer.
tags:
  - atlas-ai
  - programming
  - frontend
  - design-harness
  - huashu-design
capabilities:
  - frontend_design_harness
  - design_asset_protocol
  - visual_quality_gates
  - frontend_ap99_learning
decisions:
  - `programming.frontend` e specialist profile dentro do Programming Domain, nao novo Atlas AI Domain.
  - Atlas pode estudar Huashu Design como source material, mas nao deve copiar assets/scripts/licenca comercial restrita para produto sem revisao juridica.
  - O ganho do Atlas vem do loop completo: contexto real, assets, skill, harness, gates, AP-99, memoria e self-improvement.
  - Frontend pronto exige evidence visual, a11y, responsividade, estado, console/network e criterios esteticos; screenshot sozinho nao basta.
maintenance:
  - Manter abaixo de 260 linhas.
  - Atualizar antes de implementar `programming.frontend`, design harness, skill import, visual QA ou provider benchmark frontend.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/domains/programming-specialist-profiles.md
  - docs/engineering-knowledge-base/domains/programming.md
  - docs/engineering-knowledge-base/domains/programming-frontend-impeccable-competitive-teardown.md
  - docs/engineering-knowledge-base/domains/programming-frontend-impeccable-code-inventory.md
  - docs/engineering-knowledge-base/domains/programming-frontend-impeccable-skill-command-flow.md
  - docs/engineering-knowledge-base/domains/programming-frontend-impeccable-detector-extension.md
  - docs/engineering-knowledge-base/domains/programming-frontend-impeccable-live-mode.md
  - docs/engineering-knowledge-base/domains/programming-frontend-impeccable-build-test-release.md
  - docs/engineering-knowledge-base/domains/programming-frontend-impeccable-product-site-assets.md
  - docs/engineering-knowledge-base/domains/programming-frontend-impeccable-coverage-audit.md
  - docs/engineering-knowledge-base/atlas-ai-skill-system.md
  - docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md
  - docs/engineering-knowledge-base/programming-power-tools-catalog.md
  - app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-ai-programming-frontend-superpower
graph_title: Atlas AI Programming Frontend Superpower
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-canonical-architecture-index
graph_status: active
graph_source: repo
human_name: Atlas AI Programming Frontend Superpower
canonical_name: Atlas AI Programming Frontend Superpower
technical_name: atlas-ai-programming-frontend-superpower
cartography_type: module
canonical_source: docs/engineering-knowledge-base/domains/programming-frontend-superpower.md

owner: domains

repo_paths:
  - docs/engineering-knowledge-base/domains/programming-frontend-superpower.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - domains

evidence:
  - docs/engineering-knowledge-base/domains/programming-frontend-superpower.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - domains

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas AI Programming Frontend Superpower

Este documento define o caminho para o Atlas Frontend ter capacidade muito acima
de uma skill isolada de design. A tese: Claude Designer/Huashu vencem por bons
prompts e componentes; Atlas deve vencer por **sistema fechado de qualidade**.

## Fonte Avaliada

Huashu Design (`github.com/alchaincyf/huashu-design`) e valioso como source
material porque combina:

1. design context first;
2. fact verification before assumptions;
3. Core Asset Protocol;
4. 5-10-2-8 asset quality rule;
5. design direction advisor;
6. junior designer workflow;
7. variations and tweaks;
8. starter components;
9. Playwright verification;
10. video/PPTX/PDF export;
11. 5-dimension critique.

Restricao: o README declara uso pessoal livre e uso enterprise/comercial
restrito por autorizacao. Atlas pode absorver principios e criar implementacao
propria, mas nao deve vender/distribuir copia direta do skill/assets/scripts sem
revisao e permissao.

## O Que Vale Absorver

| Ideia | Valor Para Atlas | Como Incorporar |
|---|---|---|
| Fact verification first | evita produto/versao falsa e retrabalho | preflight de frontend/design quando houver produto, marca, versao ou evento recente |
| Core Asset Protocol | design cresce de assets reais | `FrontendAssetPack`: logo, produto, UI screenshots, tokens, fontes, guidelines, fontes citadas |
| 5-10-2-8 assets | impede visual mediocre | gate de asset quality antes de hero/media decisivo |
| Design context first | reduz generic AI slop | context pack le design system, screenshots, rotas, componentes, tokens, concorrentes |
| Direction advisor | resolve briefs vagos | gerar 3 direcoes distintas antes de implementar quando escopo e nebuloso |
| Junior designer workflow | reduz erro cedo | assumptions -> placeholder -> variation -> polish -> verify |
| Tweaks/variations | explora espaco visual | gerar variantes controladas e persistir escolhas no evidence |
| Starter components | acelera craft | Atlas-owned components para device frames, decks, browser windows, animation stage |
| Playwright verification | transforma gosto em evidence | screenshots multi-viewport, console, click/state, visual diff |
| 5D critique | revisão estetica estruturada | score: filosofia, hierarquia, craft, funcionalidade, originalidade |

## O Que Nao Copiar Cegamente

1. HTML-only como resposta universal: Atlas precisa produzir app real quando o
   repo e React/Expo/Vue/Laravel Blade, nao prototipo solto.
2. Assets/scripts licenciados para uso comercial restrito sem permissao.
3. Brand-from-zero como qualidade final; sem contexto, deve virar exploration
   ou pedir material, nao declarar final.
4. PPTX/video workflow dentro do frontend app sem separar output type.
5. Score estetico sem gates tecnicos: typecheck, lint, a11y, performance e
   estado continuam obrigatorios.

## Frontend Harness Alvo

```text
Input
-> Frontend Intent
-> Design/Product Context Pack
-> FrontendAssetPack
-> Atlas Decide model/profile
-> Builder/Designer pass
-> Variant pass when useful
-> Visual/A11y/Perf/State Gates
-> 5D Critique
-> Repair Loop
-> Evidence Ledger + AP-99
-> Output patch/prototype/report
```

Estado backend atual: `programming.frontend` e flow governado do Programming
Domain. Esta doc e a doc-mae: ela declara fronteira, invariantes e
responsabilidades. Detalhes de gauntlet, selected workspace, onboarding,
runbook, provider packet, skill pack, scenario matrix, evidence kit, replay,
proof, publish, live mode, browser bridge, adapters e benchmarking ficam nos
services e docs filhas listados em `related_paths`.

Contrato operacional resumido:

1. `AtlasProgrammingOrchestrator` projeta o harness em planos, dispatch e
   completion.
2. `AtlasFrontendDesignRuntimeService` seleciona capabilities, output types,
   gates, evidencia, anti-slop, variantes e competitive scorecard.
3. Repo selecionado pelo operador e o workspace primario; `frontend_app` e
   subescopo auditavel, nao workspace paralelo.
   Quando Atlas AI/Code varre uma pasta com muitos repos, o portfolio scan
   apenas ranqueia candidatos e projeta `frontend_app_candidate_summary`
   provider-safe por repo. Esse resumo pode mostrar contagem, hashes e se ha
   candidato `apps/web`/subapp exigindo confirmacao, mas nao devolve path bruto
   nem autoriza dispatch. A execucao continua exigindo o contrato
   `selected_workspace` do repo selecionado; so depois disso o Atlas pode
   projetar `frontend_runtime_projection` com `runtime_projection_hash`.
4. Dispatch provider exige task spec, gate, provider packet, runbook e plano de
   evidencia; selected workspace/onboarding read-only nao contam como evidence
   de entrega.
5. Claims de superioridade/world-best exigem replay externo completo, evidence
   packs, score attestation, publication receipt verificado e margem decisiva
   minima por caso. Vencer por 1 ponto nao basta para claim world-best.
   O inspect do replay emite
   `atlas.frontend.rival_replay_competitive_proof_contract.v1`; o operador pode
   materializar o recibo compacto com
   `atlas:frontend:replay proof-contract --evidence=<dir> --json`. Esse arquivo
   indexa o estado da prova, mas nao substitui os artifacts/receipts reais.
   Para reduzir improviso operacional, `atlas:frontend:replay operator-packet
   --evidence=<dir> --json` materializa
   `atlas.frontend.rival_replay_operator_packet.v1` com ordem de runs externos,
   refs, hashes e comandos de template. O endpoint padrao
   `/atlas-code/frontend/prepare-rival-replay` tambem escreve esse packet junto
   com runner kit, worklist e proof-contract, mas a resposta HTTP continua
   provider-safe e nao retorna paths brutos. O packet usa
   `${ATLAS_FRONTEND_REPLAY_EVIDENCE}` nos comandos internos para nao embutir o
   path absoluto do repo. `atlas:frontend:replay operator-packet-verify
   --evidence=<dir> --json` emite
   `atlas.frontend.rival_replay_operator_packet_verification.v1` e valida se
   hashes, placeholders e policy ainda estao intactos; ele tambem nao executa
   providers nem autoriza claim. O `atlas:frontend:world-best-plan` consome
   essa verificacao como gate: se um diretorio de replay for informado e o
   operator packet estiver ausente, stale, adulterado ou com path bruto, o plano
   bloqueia claim com `operator_packet_verification_blocked`. Depois do operador
   preencher evidence packs, execution receipts e score attestations,
   `atlas:frontend:replay proof-bundle --evidence=<dir> --json` materializa
   `atlas.frontend.rival_replay_competitive_proof_bundle.v1`: um indice
   provider-safe de replay, proof-contract, operator-packet verification,
   scoreboard e hashes de manifest. Esse bundle tambem nao guarda artifacts
   brutos, nao executa providers e so libera claim quando o replay externo real
   passou; o claim de produto publico ainda exige publication receipt verificado.
   No cockpit do Atlas Code/Frontend, o endpoint
   `/atlas-code/frontend/proof-bundle` compila o mesmo indice para o repo
   selecionado e devolve `atlas.frontend.workspace_api.proof_bundle.v1`; a UI
   deve mostrar isso como degrau de prova competitivo, nao como evidence bruta
   nem como autorizacao de dispatch.
   O cockpit tambem chama `/atlas-code/frontend/control-plane` para consolidar,
   no repo selecionado, runtime projection, proof bundle, publication receipt,
   replay status e claim policy em `atlas.frontend.workspace_api.control_plane.v1`.
   Essa visao e somente control-plane/readiness: nao cria Space runtime, nao
   executa provider e nao converte pasta local em prova publica sem receipt e
   replay externo real.
   Para o ultimo gate publico, `/atlas-code/frontend/publication-receipt-template`
   gera o template `atlas.frontend.publication_receipt.v1` dentro da pasta de
   evidencia do repo selecionado, e `/atlas-code/frontend/publication-verify`
   devolve `atlas.frontend.workspace_api.publication_verify.v1` com status
   `local_ready`, `public_verified` ou `blocked`. Esse gate tambem nao executa
   provider, nao retorna path bruto e nao libera world-best sem replay externo
   real; ele apenas prova se a distribuicao publica bate com o bundle local.
6. Live mode `accept`/`discard`/`recover` emite
   `atlas.frontend.live_source_patch_decision_receipt.v1` com hashes de decisao,
   diff e variante, mas `live_patch_decision_is_not_delivery_evidence=true`;
   patch aceito ainda precisa de `visual_quality_gate`, evidence pack e
   run-certification antes de claim de conclusao.
7. Product proof local gera site estatico auditavel, mas cada demo precisa de
   `atlas.frontend.product_proof_demo_manifest.v1` ligando pagina, hash,
   viewports, evidencias e claim boundary. `downloads.json`, demo manifests e
   publication receipt sao verificados antes de qualquer claim publico.
8. Browser bridge nao e so picker: `AtlasFrontendBrowserBridgeService` emite
   `atlas.frontend.browser_detector_event.v1` via `atlas:frontend:browser-detect`
   para achados provider-safe no elemento selecionado, como alvo pequeno, botao
   icon-only sem nome, texto minusculo, copy generica e risco de overlap.
9. Escritas em repo so ocorrem por comandos explicitos como
   `atlas:frontend:onboard --write-docs`, `atlas:frontend:skill-pack install`
   e `atlas:frontend:evidence-kit prepare`.

Comandos de entrada ficam resumidos no owner runtime:
`atlas:frontend:gauntlet`, `atlas:frontend:selected-workspace`,
`atlas:frontend:provider-packet`, `atlas:frontend:runbook`,
`atlas:frontend:evidence-kit`, `atlas:frontend:run-certify`,
`atlas:frontend:replay`, `atlas:frontend:proof` e
`atlas:frontend:publish`.

Detector anti-slop e quality nao e checklist decorativo. O runtime atual
`AtlasFrontendAntiSlopDetectorService` emite:

- `atlas.frontend.detector_findings.v1` para findings provider-safe;
- `atlas.frontend.anti_slop_rule_registry.v1` para catalogo deterministico;
- `atlas.frontend.anti_slop_repair_projection.v1` para transformar achados em
  `failed_gates`, `blockers`, `rerun_gates`, evidencia requerida e comando
  `atlas:frontend:repair-plan`.

Todo finding deve mapear regra -> impacto -> gate -> alvo de reparo -> dimensao
da rubrica competitiva. Achado sem reparo/evidencia nao pode sustentar claim de
frontend pronto; report limpo tambem nao substitui browser/visual scan quando o
risco for layout real, overlap, responsivo ou contraste por pixel.

Para implementar, a IA deve abrir primeiro o service/doc filho especifico em
`related_paths`; esta doc governa fronteira e invariantes, nao carrega o
manual completo de cada runtime.

O harness deve entender quatro entregas diferentes:

| Output Type | Runtime | Gates |
|---|---|---|
| production UI patch | repo framework | type/lint/tests/visual/a11y/perf |
| clickable prototype | HTML/React sandbox | Playwright click/state + screenshot |
| motion/design asset | HTML animation | render/video verification + asset provenance |
| deck/infographic | HTML-first deck | slide QA + export QA + typography |

## Context Pack Frontend

O context pack deve incluir:

1. framework, routes, components, design tokens and CSS strategy;
2. existing UI screenshots and design system docs;
3. target viewport/device matrix;
4. user-provided screenshots/images;
5. brand/product assets with provenance;
6. accessibility baseline;
7. performance budget and bundle constraints;
8. console/network errors from current page when available;
9. prior visual regressions and repair outcomes;
10. relevant skills activated and versions.

## Gates Obrigatorios

```text
typescript_or_reason
eslint_or_biome_or_reason
console_error_check
visual_smoke_multi_viewport
no_text_overlap
responsive_check
a11y_check_or_reason
state_transition_check
asset_provenance_check
anti_ai_slop_detector
design_5d_review
performance_budget_or_reason
evidence_receipt_required
```

Para release/high-risk, adicionar Lighthouse, visual baseline strict e review
humano quando houver mudanca visual ampla.

## Model Selection

`programming.frontend` deve sinalizar para Atlas Decide:

```text
needs_multimodal=true when screenshots/assets exist
needs_visual_reasoning=true for layout/design/craft
needs_code_patch=true for production UI
needs_long_context=true for design system/codebase audit
specialist_profile=programming.frontend
```

Decide escolhe provider/modelo via AP-99. Nao hardcodar "frontend sempre Claude",
"sempre Codex" ou "sempre Gemini"; o vencedor precisa ser medido por tarefa,
framework, output type, repair rate, visual score, bugs e custo.

## Skill Strategy

Huashu-like skill deve entrar como `frontend-design-harness` Atlas-owned:

1. provider-neutral;
2. versionada e hashada;
3. com allowed tools declaradas;
4. com tests/evals de prompt;
5. com licenca propria segura;
6. integrada ao Evidence Ledger;
7. ativada por specialist profile, nao por surface manual.

External skills podem ser usadas como referencia ou skill pessoal local, mas o
produto Atlas precisa de skill propria para evitar dependencia/licenca/confusao.

## AP-99 Frontend Metrics

```text
provider/model, output_type, framework, specialist_profile,
asset_pack_quality, visual_5d_score, gate_pass_rate, repair_iterations,
console_errors, a11y_findings, responsive_failures, human_correction,
latency, cost, accepted_by_operator
```
Essas metricas alimentam Self-Improvement e Model Selection.

## Roadmap

| Fase | Entrega |
|---|---|
| AFD-0 | Doc canonica e Huashu evaluation |
| AFD-1 | `FrontendAssetPack` schema + provenance |
| AFD-2 | `programming.frontend` payload/profile routing |
| AFD-3 | Atlas-owned frontend design skill |
| AFD-4 | Frontend harness com Playwright multi-viewport |
| AFD-5 | 5D critique gate + no-text-overlap gate |
| AFD-6 | AP-99 frontend rollups |
| AFD-7 | Variant/council workflow para tarefas complexas |

## Definition Of Done

1. produz patch/prototype com evidence reproduzivel;
2. passa visual/a11y/perf/state gates;
3. usa assets reais ou declara ausencia;
4. mede provider/modelo por AP-99;
5. aprende com correcoes humanas;
6. evita duplicar design/product/marketing fora do Programming;
7. deixa replay no Evidence Ledger.

## Resumo
Contrato alvo para transformar `programming.frontend` em um harness frontend/design superior a skills isoladas e ao fluxo de Claude Designer.
## Papel no Atlas
Define a responsabilidade desta peca dentro da arquitetura Atlas.
## Onde Se Encaixa
Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.
## Contratos
Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.
## Fluxo
Descreve o caminho operacional ou a sequencia de uso quando aplicavel.
## Regras para IA
Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.
## Escopo de Implementacao
Mudancas ficam nos caminhos do frontmatter e no cluster `programming.frontend`.
## Dependencias
Depende de Programming, Forge, visual smoke, Evidence Ledger, AEMOR e docs canonicas.
## Evidencias
Aceita task spec, scenario matrix, screenshots/videos, testes, hashes, receipts e outcome memory.
## Riscos
Claims visuais falsos, design generico, ausencia de repo context e benchmark sem replay bloqueiam promocao.
## Exemplos
Use `atlas:frontend:work-order` para repos locais e `atlas:frontend:scenarios` antes da verificacao visual.
## Proximas Acoes
Executar replay externo real, receipts publicos e provas em repos de empresa antes de claim world-best.
