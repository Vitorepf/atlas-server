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
Domain. `AtlasProgrammingOrchestrator` emite
`atlas.programming.frontend_design_harness.v1` em planos, dispatch e completion
quando a tarefa envolve frontend/design. O contrato exige context pack frontend,
gates de visual/a11y/perf/state, asset provenance, 5D critique e receipts antes
de qualquer claim de done. O craft visual final pode ser delegado a Claude ou
outro provider, mas a autoridade de fluxo, gates e evidencia fica no Atlas.

Runtime atual: `AtlasFrontendDesignRuntimeService` emite
`atlas.frontend.design_runtime_contract.v1` e fica embutido no bloco
`frontend_design_harness_contract.atlas_frontend_runtime`. Ele seleciona
capabilities, output types, gates, evidencia, anti-AI-slop rules, variant
strategy, live iteration contract e competitive scorecard de forma
deterministica e provider-neutral. Comandos operacionais:

```text
php artisan atlas:frontend:plan --task="<intent>" --json
php artisan atlas:frontend:spec --task="<brief>" --json
php artisan atlas:frontend:benchmark --json
php artisan atlas:frontend:rubric --json
php artisan atlas:frontend:gate --task="<intent>" --json --strict
php artisan atlas:frontend:repair-plan --blocker=<id> --json
php artisan atlas:frontend:company-profile template|inspect --json
php artisan atlas:frontend:directions --task="<brief>" --json
php artisan atlas:frontend:inventory inspect --json
php artisan atlas:frontend:assets template|inspect --json
php artisan atlas:frontend:review template|inspect --json
php artisan atlas:frontend:visual-quality template|inspect --json
php artisan atlas:frontend:design-system-drift template|inspect --json
php artisan atlas:frontend:evidence template|verify --json
php artisan atlas:frontend:outcomes summary|record|template --json
php artisan atlas:frontend:handoff compile|template --json
php artisan atlas:frontend:replay inspect|template --json
php artisan atlas:frontend:proof [build] --json
php artisan atlas:frontend:publish verify|receipt-template --json
php artisan atlas:frontend:detect --path=<frontend-file-or-workspace> --strict --json
php artisan atlas:frontend:adapters inspect --json
php artisan atlas:frontend:bridge inject|remove --json
php artisan atlas:frontend:relay inject|remove --json
php artisan atlas:frontend:live prepare|accept|recover --json
php artisan atlas:frontend:run-certify|certify --json --strict
```

Politica honesta: Atlas pode declarar contrato frontend mais completo que
Impeccable/Claude Design somente quando `atlas:frontend:certify` estiver
`ready`. O claim "melhor do mundo" continua proibido sem benchmark real,
visual smoke, evidencia de execucao e replay contra rivais.
Run certification: `AtlasFrontendRunCertificationService` / `atlas:frontend:run-certify` decide se uma entrega pode declarar frontend done a partir de visual-quality, 5D review, evidence pack, publicacao e outcome. O check `task_spec_hash_consistent` exige que visual-quality, 5D review e evidence pack provem o mesmo `task_spec_hash`; se a cadeia divergir, a certificacao bloqueia a conclusao. `outcome_memory_available` tambem e critico: o Atlas nao declara entrega frontend concluida sem outcome memory provider-safe para auditoria e aprendizado AEMOR. Task spec compiler: `AtlasFrontendTaskSpecCompilerService` / `atlas:frontend:spec` emite `atlas.frontend.task_spec.v1` antes do gate, sem texto bruto do cliente, com task types, rotas, viewports, estados, jornadas, aceite, gates, testes, evidencias e hash canonico.
Benchmark competitivo: `AtlasFrontendBenchmarkRuntimeService` /
`atlas:frontend:benchmark` emite `atlas.frontend.benchmark_runtime.v1`, matriz
provider-safe contra Atlas Frontend, Impeccable e Claude Design Plugin. Mede
governanca, detector, live mode, evidencia, multiempresa, portabilidade,
distribuicao/proof e outcome memory, mas mantem
`atlas_world_best_frontend_system=false` sem replay externo e demos publicas.
Company design profile: `AtlasFrontendCompanyDesignProfileService` e `atlas:frontend:company-profile` emitem `atlas.frontend.company_design_profile.v1`, impedindo o Atlas Frontend de tratar empresas diferentes como uma mesma landing genérica. O perfil exige contexto da empresa, audiência/jobs, brand system, refs de design system com hashes, constraints frontend e quality policy. Para SaaS, ecommerce, enterprise ou multiempresa, `AtlasFrontendDesignRuntimeService` marca esse contrato como `required`. Template nao conta como contexto real, raw prompt/source/customer data sao bloqueados, e claim de brand adaptation depende de perfil `ready`.
Design direction advisor: `AtlasFrontendDesignDirectionAdvisorService` / `atlas:frontend:directions` gera tres direcoes governadas com gates e riscos. Design 5D review: `AtlasFrontendDesignReviewService` / `atlas:frontend:review` verifica filosofia, hierarquia, craft, clareza funcional e originalidade com score minimo 8, evidence refs e bloqueio contra raw prompt/source.
Design system inventory: `AtlasFrontendDesignSystemInventoryService` / `atlas:frontend:inventory` escaneia workspace e emite `atlas.frontend.design_system_inventory.v1` com tokens, componentes, bibliotecas e config refs hashados, sem retornar fonte bruta ou paths absolutos.
Execution gate: `AtlasFrontendExecutionGateService` / `atlas:frontend:gate` compila/resume task spec, verifica `--task-spec-hash` quando declarado e bloqueia dispatch sem tarefa, aceite, plano de teste, plano visual, plano de evidencia, contexto de design system/perfil quando amplo e senior review quando necessario. Atlas Dev projeta isso em `frontend_design_harness_contract.pre_execution_gate`; Forge projeta em `atlas_frontend_pre_execution_gate` por work packet `surface_ui`.
Repair planner: `AtlasFrontendRepairPlannerService` / `atlas:frontend:repair-plan` transforma blockers, warnings e failed gates em plano deterministico de reparo, rerun gates e evidencias. Outcome memory: `AtlasFrontendOutcomeMemoryService` / `atlas:frontend:outcomes` registra outcomes provider-safe por drivers, gates, failed gates e evidence refs, alimentando AEMOR sem texto bruto de cliente.
Delivery handoff: `AtlasFrontendDeliveryHandoffService` / `atlas:frontend:handoff` compila um pacote empresarial provider-safe para cliente/equipe a partir de run certification e evidence manifest. O handoff exige `run_certification_hash`, `task_spec_hash`, evidence manifest com hash compatível, claim policy e known limitations. Ele permite declarar handoff de entrega frontend, mas nao autoriza distribuicao publica sem publication report verificado e nunca autoriza claim "world best".
Asset pack: `AtlasFrontendAssetPackService` / `atlas:frontend:assets` emitem
`atlas.frontend.asset_pack_verifier.v1`; placeholder, asset sem proveniencia,
licenca invalida ou dimensoes criticas ausentes bloqueiam claim visual final.

Rival replay: `AtlasFrontendRivalReplayHarnessService` e
`atlas:frontend:replay` transformam a pergunta "Atlas superou Impeccable,
Claude Design e similares?" em matriz de evidencia. O comando `template` cria
15 manifests pendentes para cinco casos de produto contra tres sistemas
(`atlas_frontend`, `pbakaus_impeccable`, `claude_design_plugin`). O comando
`inspect` exige hashes/ref de artefatos, screenshots, anti-slop report,
verificacoes, score breakdown e score por run. Templates pendentes nao contam
como replay real, manifest com prompt/source bruto e invalido, e o claim
`world_best` so pode ser verdadeiro quando todos os rivais tiverem runs
completos e o Atlas vencer cada caso completo. Isso fecha a lacuna de processo:
o Atlas agora sabe exatamente qual prova falta antes de alegar superioridade
mundial.

Competitive rubric: `AtlasFrontendCompetitiveRubricService` e
`atlas:frontend:rubric` emitem `atlas.frontend.competitive_rubric.v1`, a regua
canonica de 100 pontos usada por replay. Ela pesa produto, hierarquia visual,
layout/spacing, workflow, responsividade, acessibilidade, integridade de
implementacao, performance, originalidade anti-slop/brand fit e completude de
evidencia. Nenhum sistema pode vencer por numero solto: `score_total` precisa
bater com o `score_breakdown`, todos os sistemas usam a mesma rubrica e o score
maximo precisa ser o mesmo.

Visual gates: `AtlasFrontendVisualQualityGateService` /
`atlas:frontend:visual-quality` emitem `atlas.frontend.visual_quality_gate.v1`
para viewports, console, a11y/perf, overlap, state, anti-slop e hashes.
`AtlasFrontendDesignSystemDriftGateService` /
`atlas:frontend:design-system-drift` emitem
`atlas.frontend.design_system_drift_gate.v1` para tokens, componentes, refs e
excecoes aprovadas. Screenshot, token novo ou componente novo sem evidencia nao
autoriza claim de frontend pronto nem de adaptacao ao design system da empresa.

Evidence pack verifier: `AtlasFrontendEvidencePackVerifierService` e
`atlas:frontend:evidence` emitem `atlas.frontend.evidence_pack_verifier.v1` e
fecham uma lacuna importante do replay: hashes precisam corresponder a arquivos
reais. O pack exige artefato de output, screenshot set, anti-slop report,
verification report, console report, a11y/reason, performance/reason e receipt.
O verificador bloqueia caminho absoluto, path traversal, hash invalido,
hash mismatch, arquivo ausente e campos como raw prompt/source/customer data.
Um pack `passed` pode sustentar um replay manifest, mas ainda nao prova
hospedagem publica.

Product proof: `AtlasFrontendProductProofRuntimeService` e
`atlas:frontend:proof` emitem `atlas.frontend.product_proof_runtime.v1`, um
catalogo local das demos obrigatorias para provar Atlas Frontend multiempresa:
SaaS dashboard repair, ecommerce product page, mobile onboarding, design system
migration e live mode repair loop. Cada demo declara viewports, evidencias e
manifest esperado. `atlas:frontend:proof build` emite
`atlas.frontend.product_proof_bundle.v1` com `index.html`, paginas por demo e
`manifest.json` em um bundle estatico local publicavel. O bundle melhora a
prontidao de produto, mas nao autoriza claim de distribuicao publica enquanto
nao houver hospedagem externa verificada.

Publication verifier: `AtlasFrontendPublicationVerifierService` e
`atlas:frontend:publish` emitem `atlas.frontend.publication_verifier.v1`. O
verificador checa se o bundle local gerado por `atlas:frontend:proof build`
continua integro (`local_ready`) validando `manifest.json`, `index.html`, assets
e hashes. O claim publico so muda para `public_verified` quando existe receipt
`atlas.frontend.publication_receipt.v1` com URL HTTPS, status HTTP 200, hash do
bundle correspondente e aprovacao do operador. Recibo template nao autoriza
claim publico; ele apenas diz exatamente que prova externa precisa ser anexada.

Integração Forge: `ForgeSpecialistWorkcellRouterService` anexa o mesmo contrato
`atlas_frontend_runtime` a work packets roteados para `surface_ui`, garantindo
que Obras frontend recebam gates/evidencia equivalentes aos fluxos Dev.

Detector executavel: `AtlasFrontendAntiSlopDetectorService` e
`atlas:frontend:detect` materializam `anti_ai_slop_detector` sem LLM no core.
Ele varre HTML/CSS/JS/JSX/TSX/Vue/Svelte/Astro/Blade e emite
`atlas.frontend.anti_slop_detector.v1` com findings provider-safe para padrões
como gradient text generico, paleta roxa/pink one-note, nested cards,
placeholder assets, hero copy generico, glow blobs, tiny text, absolute overlap
risk e icon buttons sem accessible name.

Live source patch: `AtlasFrontendLiveSourcePatchRuntimeService` e
`atlas:frontend:live` implementam o nucleo que o Live Mode do Impeccable prova
ser valioso: preparar variantes, aceitar uma variante no source real, descartar
sem mutacao e recuperar o original por session journal. A mutacao exige target
exato unico e hash de precondicao do arquivo, bloqueando source drift. Ainda
faltam replay rival real e product proof publico para claim de live mode
superior ao mercado inteiro.

Framework adapters: `AtlasFrontendFrameworkAdapterRuntimeService` e
`atlas:frontend:adapters inspect` detectam Vite, Next, Nuxt, Astro, SvelteKit,
Remix, Angular, Expo, React, Vue e Svelte a partir de `package.json` e arquivos
de config. O adapter retorna dev commands, URL local default, candidatos de root
document e estrategia de acoplamento bridge/relay/source-patch sem expor
`package.json` bruto nem caminhos absolutos. Quando o framework nao e
identificado, o status fica `partial`, nao falso-ready.

Browser bridge: `AtlasFrontendBrowserBridgeService` e `atlas:frontend:bridge`
geram/injetam/removem uma ponte local em HTML para selecionar elementos no
browser com Alt+click. A ponte emite `atlas.frontend.browser_pick_event.v1`,
inclui fingerprint provider-safe do elemento selecionado, hash de texto em vez
de texto bruto, retangulo, path CSS curto e journal em `window` sem acessar
cookies, storage ou rede. Esse dado alimenta o Live Mode sem transformar a tela
em screenshot solto nem depender de extensao proprietaria.

Live preview relay: `AtlasFrontendLivePreviewRelayService` e
`atlas:frontend:relay` geram/injetam/removem uma camada de preview CSS via
eventos locais. O browser aceita `atlas:frontend:preview-css`, aplica a variante
em uma tag `<style>` temporaria, emite `atlas.frontend.live_preview_event.v1` e
limpa via `atlas:frontend:clear-preview`. O relay nao acessa cookies, storage
ou rede, nao persiste o CSS da variante e separa preview visual de patch aceito
no source.

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

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
