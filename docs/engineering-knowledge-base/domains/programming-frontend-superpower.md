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
php artisan atlas:frontend:gauntlet --task="<intent>" --workspace=<local-company-repo> --json --strict
php artisan atlas:frontend:onboard --task="<intent>" --workspace=<local-company-repo> --write-docs --json --strict
php artisan atlas:frontend:work-order --task="<intent>" --workspace=<local-company-repo> --json --strict
php artisan atlas:frontend:runbook --task="<intent>" --workspace=<local-company-repo> --json --strict
php artisan atlas:frontend:provider-packet --task="<intent>" --workspace=<local-company-repo> --provider=<provider> --json --strict
php artisan atlas:frontend:skill-pack export --output=<skill-dir> --json --strict
php artisan atlas:frontend:skill-pack install --workspace=<local-company-repo> --json --strict
php artisan atlas:frontend:enterprise-bootstrap inspect|write --task="<intent>" --workspace=<local-company-repo> --json
php artisan atlas:frontend:intake --workspace=<local-company-repo> --json --strict
php artisan atlas:frontend:blueprint generate|write --task="<intent>" --workspace=<local-company-repo> --json
php artisan atlas:frontend:spec --task="<brief>" --json
php artisan atlas:frontend:scenarios --task="<brief>" --json --strict
php artisan atlas:frontend:benchmark --json
php artisan atlas:frontend:rubric --json
php artisan atlas:frontend:control-plane --json --strict
php artisan atlas:frontend:gate --task="<intent>" --json --strict
php artisan atlas:frontend:repair-plan --blocker=<id> --json
php artisan atlas:frontend:design-dossier template|inspect --workspace=<local-company-repo> --json
php artisan atlas:frontend:company-profile template|inspect --json
php artisan atlas:frontend:directions --task="<brief>" --json
php artisan atlas:frontend:inventory inspect --json
php artisan atlas:frontend:assets template|inspect --json
php artisan atlas:frontend:review template|inspect --json
php artisan atlas:frontend:visual-quality template|inspect --json
php artisan atlas:frontend:quality-budget template|inspect --json
php artisan atlas:frontend:design-system-drift template|inspect --json
php artisan atlas:frontend:evidence-kit prepare --task="<intent>" --workspace=<local-company-repo> --output=<evidence-dir> --json
php artisan atlas:frontend:evidence template|verify --json
php artisan atlas:frontend:outcomes summary|record|template --json
php artisan atlas:frontend:handoff compile|template --json
php artisan atlas:frontend:replay inspect|template|runner-kit --json
php artisan atlas:frontend:proof catalog|build|pilot --json
php artisan atlas:frontend:publish verify|receipt-template --json
php artisan atlas:frontend:world-best-plan --json --strict
php artisan atlas:frontend:detect --path=<frontend-file-or-workspace> --strict --json
php artisan atlas:frontend:adapters inspect --json
php artisan atlas:frontend:bridge inject|remove --json
php artisan atlas:frontend:relay inject|remove --json
php artisan atlas:frontend:live prepare|accept|recover --json
php artisan atlas:frontend:run-certify|certify --json --strict
```
Fluxo primario de uso: Atlas AI / Atlas Code recebem um repositorio escolhido
pelo operador e passam esse `workspace` explicitamente para o Atlas Frontend. A
partir desse repo selecionado, Atlas Frontend roda onboarding, gauntlet,
provider packet, proof pilot, runbook, evidence kit e certificacao. O Atlas nao
depende de varrer uma pasta de repos para iniciar trabalho; qualquer varredura
de portfolio e apenas uma ferramenta opcional de inventario/triagem para o
operador enxergar varios repos locais.
Politica honesta: Atlas pode declarar contrato frontend mais completo que Impeccable/Claude Design somente quando `atlas:frontend:certify` estiver `ready`; claim "melhor do mundo" exige benchmark real, visual smoke, evidencia e replay contra rivais.
Control plane: `AtlasFrontendControlPlaneService` / `atlas:frontend:control-plane` emite `atlas.frontend.control_plane.v1`, agregando certificacao, gauntlet de repo local, benchmark, rival replay, product proof e publicacao. Pode retornar `warning` com runtime certificado quando a prova de mercado ainda falta; claims de runtime governado ficam permitidos, mas `world_best_claim_allowed=false` ate existir replay externo completo e receipt publico verificado.
World-best proof plan: `AtlasFrontendWorldBestProofPlanService` / `atlas:frontend:world-best-plan` emite `atlas.frontend.world_best_proof_plan.v1` e transforma a lacuna de mercado em workstreams executaveis: replay externo, publicacao publica e claim audit. O plano consome `evidence_pack_readiness` do replay, projeta status/resumo no workstream competitivo e cria a acao `fill_and_verify_rival_replay_evidence_packs` quando algum pack ainda nao tem artefatos/hash refs verificaveis. So retorna `ready` quando o Atlas vence todos os casos completos contra rivais e o product proof tem receipt publico verificado; ate la, `--strict` falha e lista exatamente quais manifests, packs, hashes e receipts faltam.
Gauntlet: `AtlasFrontendGauntletService` / `atlas:frontend:gauntlet` e o entrypoint recomendado para repo local de empresa. Ele compoe runtime contract, task spec, execution gate, design dossier, product blueprint, repo intake, inventory e certificacao, retornando bloqueios, next actions e sequencia de comandos antes de dispatch provider.
Company repo onboarding: `AtlasFrontendCompanyRepoOnboardingService` / `atlas:frontend:onboard` emite `atlas.frontend.company_repo_onboarding.v1` e prepara um repo local de empresa em uma chamada: instala o skill pack em `.atlas/skills/atlas-frontend`, roda enterprise bootstrap, gera proof pilot, grava `.atlas/frontend/onboarding-receipt.json` e classifica `ready_for_operator_execution`, `prepared_needs_context` ou `blocked`. Para repos BlackInk/Refinar/SaaS ja documentados, status pronto libera dispatch com provider packet/runbook; para repo cru, `--write-docs` cria templates e retorna `prepared_needs_context`, sem autorizar provider dispatch ate o operador preencher contexto real. Onboarding nunca conta como evidencia de entrega, mantem `measured_evidence_present=false` e `world_best_claim_allowed=false`.
Enterprise bootstrap: `AtlasFrontendEnterpriseBootstrapService` / `atlas:frontend:enterprise-bootstrap` e o botao operacional para BlackInk, Refinar, repos locais de empresa e SaaS novo. Em `write`, cria templates de dossier e blueprint sem declarar contexto pronto; em `inspect`, agrega dossier, intake, gauntlet e work-order para decidir se provider dispatch e design premium podem comecar.
Work order: `AtlasFrontendWorkOrderService` / `atlas:frontend:work-order` transforma task + repo local em pacotes executaveis: context lock, patch/prototype, verificacao visual e handoff certificado. Runbook: `AtlasFrontendExecutionRunbookService` / `atlas:frontend:runbook` converte repo intake, work-order e evidence-kit em sequencia repo-native de install/dev/test/build/evidence/certify/handoff. Provider packet: `AtlasFrontendProviderInstructionPacketService` / `atlas:frontend:provider-packet` transforma gate, work-order e runbook em mandatos provider-safe antes de Codex/Claude/Gemini editar.
Skill pack: `AtlasFrontendSkillPackService` / `atlas:frontend:skill-pack export` emite `atlas.frontend.skill_pack.v1` e escreve um pacote portavel com `SKILL.md`, `reference/commands.md`, `reference/evidence.md`, `reference/claim-policy.md`, `reference/provider-handoff.md` e `manifest.json`. `atlas:frontend:skill-pack install --workspace=<local-company-repo>` emite `atlas.frontend.skill_pack_install.v1`, instala o pacote em `.atlas/skills/atlas-frontend` no repo da empresa e grava `install-receipt.json`. Ele existe para dar aos providers uma superficie operacional superior ao modelo `skill/SKILL.md` do Impeccable, mas sem transformar skill em fonte de verdade: o runtime Atlas, o provider packet, o proof pilot e os gates continuam autoritativos. O skill pack nunca conta como evidencia de execucao, nao retorna fonte bruta e mantem `world_best_claim_allowed=false`.
Scenario matrix: `AtlasFrontendScenarioMatrixService` / `atlas:frontend:scenarios` transforma task spec em matriz rota x viewport x estado, com checks obrigatorios por cenario antes de visual-quality.
Evidence kit: `AtlasFrontendEvidenceKitService` / `atlas:frontend:evidence-kit` prepara scenario matrix, reports, evidence pack, outcome template e comando run-certify sem declarar evidência real.
Repo intake: `AtlasFrontendRepoIntakeService` / `atlas:frontend:intake` emite `atlas.frontend.repo_intake.v1` como mapa operacional do repositorio local: package manager, framework adapter, entrypoints, rotas candidatas, comandos de teste/build/qualidade, status do dossier e inventario. Ele nao retorna fonte bruta nem paths absolutos e bloqueia dispatch quando o repo nao tem mapa minimo de execucao.
Blueprint: `AtlasFrontendProductBlueprintService` / `atlas:frontend:blueprint` transforma tarefa em modelo de produto, UX success, telas, estados, direcao visual, aceite e mapa de evidencias. Run certification: `AtlasFrontendRunCertificationService` / `atlas:frontend:run-certify` decide se uma entrega pode declarar frontend done a partir de visual-quality, 5D review, quality budget medido, evidence pack, publicacao e outcome. O check `task_spec_hash_consistent` exige que visual-quality, 5D review, quality budget e evidence pack provem o mesmo `task_spec_hash`; se a cadeia divergir, a certificacao bloqueia a conclusao. `outcome_memory_available` e `quality_budget_passed` sao criticos: o Atlas nao declara entrega frontend concluida sem outcome memory provider-safe e budgets objetivos para auditoria e aprendizado AEMOR. Task spec compiler: `AtlasFrontendTaskSpecCompilerService` / `atlas:frontend:spec` emite `atlas.frontend.task_spec.v1` antes do gate, sem texto bruto do cliente, com task types, rotas, viewports, estados, jornadas, aceite, gates, testes, evidencias e hash canonico.
Benchmark competitivo: `AtlasFrontendBenchmarkRuntimeService` /
`atlas:frontend:benchmark` emite `atlas.frontend.benchmark_runtime.v1`, matriz
provider-safe contra Atlas Frontend, Impeccable e Claude Design Plugin. Mede
governanca, detector, live mode, evidencia, multiempresa, portabilidade,
distribuicao/proof e outcome memory, mas mantem
`atlas_world_best_frontend_system=false` sem replay externo e demos publicas.
Company design profile: `AtlasFrontendCompanyDesignProfileService` e `atlas:frontend:company-profile` emitem `atlas.frontend.company_design_profile.v1`, impedindo o Atlas Frontend de tratar empresas diferentes como uma mesma landing genérica. O perfil exige contexto da empresa, audiência/jobs, brand system, refs de design system com hashes, constraints frontend e quality policy. Para SaaS, ecommerce, enterprise ou multiempresa, `AtlasFrontendDesignRuntimeService` marca esse contrato como `required`. Template nao conta como contexto real, raw prompt/source/customer data sao bloqueados, e claim de brand adaptation depende de perfil `ready`.
Design dossier: `AtlasFrontendDesignDossierService` / `atlas:frontend:design-dossier` trata repo local de empresa do operador como modo padrao. Ele audita ou cria `docs/design/product-experience-brief.md`, `brand-system.md`, `ux-journeys.md`, `design-system.md` e `frontend-quality-policy.md`. Para refinamento premium, BlackInk-like local repo ou novo SaaS, missing docs viram blocker/context action antes de claim visual; template nao conta como contexto preenchido.
Design direction advisor: `AtlasFrontendDesignDirectionAdvisorService` / `atlas:frontend:directions` gera tres direcoes governadas com gates e riscos. Design 5D review: `AtlasFrontendDesignReviewService` / `atlas:frontend:review` verifica filosofia, hierarquia, craft, clareza funcional e originalidade com score minimo 8, evidence refs e bloqueio contra raw prompt/source.
Design system inventory: `AtlasFrontendDesignSystemInventoryService` / `atlas:frontend:inventory` escaneia workspace e emite `atlas.frontend.design_system_inventory.v1` com tokens, componentes, bibliotecas e config refs hashados, sem retornar fonte bruta ou paths absolutos.
Execution gate: `AtlasFrontendExecutionGateService` / `atlas:frontend:gate` compila/resume task spec, verifica `--task-spec-hash` quando declarado e bloqueia dispatch sem tarefa, aceite, plano de teste, plano visual, plano de evidencia, contexto de design system/perfil quando amplo e senior review quando necessario. Atlas Dev projeta isso em `frontend_design_harness_contract.pre_execution_gate`; para repo local selecionado pelo operador tambem anexa `enterprise_operating_contract`, `company_repo_onboarding`, bootstrap, runbook e provider packet. Forge projeta `atlas_frontend_pre_execution_gate` por work packet `surface_ui` e, quando o workspace local e conhecido, anexa `atlas_frontend_enterprise_operating_contract`, `atlas_frontend_company_repo_onboarding`, bootstrap, runbook e provider packet.
Repair planner: `AtlasFrontendRepairPlannerService` / `atlas:frontend:repair-plan` transforma blockers, warnings e failed gates em plano deterministico de reparo, rerun gates e evidencias. Outcome memory: `AtlasFrontendOutcomeMemoryService` / `atlas:frontend:outcomes` registra outcomes provider-safe por drivers, gates, failed gates e evidence refs, alimentando AEMOR sem texto bruto de cliente.
Delivery handoff: `AtlasFrontendDeliveryHandoffService` / `atlas:frontend:handoff` compila um pacote empresarial provider-safe para cliente/equipe a partir de run certification e evidence manifest. O handoff exige `run_certification_hash`, `task_spec_hash`, evidence manifest com hash compatível, claim policy e known limitations. Ele permite declarar handoff de entrega frontend, mas nao autoriza distribuicao publica sem publication report verificado e nunca autoriza claim "world best".
Asset pack: `AtlasFrontendAssetPackService` / `atlas:frontend:assets` emitem
`atlas.frontend.asset_pack_verifier.v1`; placeholder, asset sem proveniencia,
licenca invalida ou dimensoes criticas ausentes bloqueiam claim visual final.
Rival replay: `AtlasFrontendRivalReplayHarnessService` e
`atlas:frontend:replay` transformam a pergunta "Atlas superou Impeccable,
Claude Design e similares?" em matriz de evidencia. O comando `template` cria
cinco task specs canonicos `atlas.frontend.rival_replay_task_spec.v1` e
15 manifests pendentes para cinco casos de produto contra tres sistemas
(`atlas_frontend`, `pbakaus_impeccable`, `claude_design_plugin`). O comando
`inspect` exige hashes/ref de artefatos, screenshots, anti-slop report,
verificacoes, score breakdown e score por run. Templates pendentes nao contam
como replay real, manifest com prompt/source bruto e invalido, e o claim
`world_best` so pode ser verdadeiro quando todos os rivais tiverem runs
completos, o Atlas vencer cada caso completo e todos os sistemas comparados
usarem o mesmo `task_spec_hash` por caso. Cada manifest gerado ja aponta para
`../task-spec.json`, reduzindo risco de o operador executar Atlas, Impeccable e
Claude Design contra escopos diferentes. O `inspect` valida essa referencia
canonica: o manifest precisa declarar `task_spec_ref=../task-spec.json`, o arquivo
precisa existir, ter schema/case corretos e o `task_spec_hash` do manifest precisa
bater com o hash do task spec referenciado. Replay completo tambem exige
`evidence_pack_ref=evidence/evidence-pack.json`; esse pack e verificado por
`AtlasFrontendEvidencePackVerifierService`, precisa conter arquivos reais com
hashes correspondentes e precisa bater case/system/task spec com o manifest. O
manifest so fica complete quando `output_artifact_hash`, `screenshot_hashes`,
`anti_slop_report_hash` e `verification_hashes` tambem aparecem no pack
verificado. O comando `runner-kit` emite
`atlas.frontend.rival_replay_runner_kit.v1` e escreve `replay-runner-kit.json`
com 15 run packets, passos de execucao, checklist de evidencias, politica de
score e comandos de inspeção/world-best plan. Ele tambem prepara um
`evidence/evidence-pack.json` por run com `case_id`, `system` e `task_spec_hash`
preenchidos, mais os artifacts obrigatorios do evidence-pack verifier. O `inspect`
emite `evidence_pack_readiness` com total/present/passed/blocked/missing e blockers
por run, para mostrar exatamente quais arquivos/hash refs ainda faltam antes de
qualquer claim competitivo. O comando `evidence-worklist` gera
`atlas.frontend.rival_replay_evidence_worklist.v1` e escreve
`replay-evidence-worklist.json`: uma fila provider-safe por caso/sistema com
`pack_manifest_ref`, `run_manifest_ref`, slots de artefato, comando `shasum` por
arquivo e `manifest_hash_mapping` para espelhar `output_artifact`,
`screenshot_set`, `anti_slop_report` e `verification_report` no manifest. Essa
worklist tambem nao e evidencia; ela reduz ambiguidade operacional para coletar
artefatos reais e voltar ao `inspect`. Runner kit
nao e evidencia de replay; ele e a fila operacional provider-neutral para coletar
a evidencia correta. Se os sistemas forem avaliados contra tarefas diferentes, o harness marca
`task_spec_hash_mismatch_across_systems` e invalida o replay. Isso fecha a
lacuna de processo: o Atlas agora sabe exatamente qual prova falta antes de
alegar superioridade mundial e impede benchmark injusto por escopo divergente.
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
`AtlasFrontendQualityBudgetGateService` / `atlas:frontend:quality-budget`
emitem `atlas.frontend.quality_budget_gate.v1` com budgets medidos para LCP,
INP, CLS, JS KB, a11y critica, contraste, overflow, text overlap e console.
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
nao houver hospedagem externa verificada. `atlas:frontend:proof pilot --task
"<intent>" --workspace=<local-company-repo> --provider=<provider> --output=<dir>
--acceptance --test-plan --visual-quality-plan --evidence-plan
--senior-design-review --json --strict` emite
`atlas.frontend.company_repo_proof_dossier.v1`, o dossie pre-execucao para
BlackInk, Refinar ou qualquer repo local de empresa. Ele agrega enterprise
bootstrap, provider instruction packet, runbook, evidence kit, certificacao do
runtime e product proof em um unico artefato auditavel (`pilot-dossier.json`).
Status `ready_for_operator_execution` significa que o provider pode ser
despachado com mandatos e comandos corretos; nao significa entrega concluida.
O dossie sempre marca `measured_evidence_present=false`,
`rival_replay_present=false` e `world_best_claim_allowed=false` ate que a
execucao real substitua templates por evidencias medidas, rode
`atlas:frontend:run-certify`, gere handoff e complete replay externo contra
rivais.

Publication verifier: `AtlasFrontendPublicationVerifierService` e
`atlas:frontend:publish` emitem `atlas.frontend.publication_verifier.v1`. O
verificador checa se o bundle local gerado por `atlas:frontend:proof build`
continua integro (`local_ready`) validando `manifest.json`, `index.html`, assets
e hashes. O claim publico so muda para `public_verified` quando existe receipt
`atlas.frontend.publication_receipt.v1` com URL HTTPS, status HTTP 200, hash do
bundle correspondente, `index_content_hash` igual ao hash real do `index.html`
local e aprovacao do operador. `atlas:frontend:publish verify --strict` falha
enquanto a distribuicao publica nao estiver verificada. Recibo template nao
autoriza claim publico; ele apenas diz exatamente que prova externa precisa ser
anexada.

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
