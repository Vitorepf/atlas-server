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
php artisan atlas:frontend:portfolio --root=<folder-with-company-repos> --task="<intent>" --json --strict
php artisan atlas:frontend:gauntlet --task="<intent>" --workspace=<local-company-repo> --json --strict
php artisan atlas:frontend:selected-workspace --task="<intent>" --workspace=<local-company-repo> --json --strict
php artisan atlas:frontend:selected-workspace --task="<intent>" --workspace=<local-company-repo> --frontend-app=apps/web --json --strict
php artisan atlas:frontend:onboard --task="<intent>" --workspace=<local-company-repo> --write-docs --json --strict
php artisan atlas:frontend:work-order --task="<intent>" --workspace=<local-company-repo> --json --strict
php artisan atlas:frontend:runbook --task="<intent>" --workspace=<local-company-repo> --frontend-app=apps/web --json --strict
php artisan atlas:frontend:provider-packet --task="<intent>" --workspace=<local-company-repo> --frontend-app=apps/web --provider=<provider> --json --strict
php artisan atlas:frontend:skill-pack export --output=<skill-dir> --json --strict
php artisan atlas:frontend:skill-pack install --workspace=<local-company-repo> --json --strict
php artisan atlas:frontend:enterprise-bootstrap inspect|write --task="<intent>" --workspace=<local-company-repo> --json
php artisan atlas:frontend:intake --workspace=<local-company-repo> --json --strict
php artisan atlas:frontend:blueprint generate|write --task="<intent>" --workspace=<local-company-repo> --json
php artisan atlas:frontend:spec --task="<brief>" --json
php artisan atlas:frontend:scenarios --task="<brief>" --json --strict
php artisan atlas:frontend:benchmark --rival-evidence=<dir> --json
php artisan atlas:frontend:rubric --json
php artisan atlas:frontend:control-plane --json --strict
php artisan atlas:frontend:gate --task="<intent>" --json --strict
php artisan atlas:frontend:repair-plan --blocker=<id> --dimension-gap=product_intent_fit:2:-2:12:live_mode_repair_loop:pbakaus_impeccable --json
php artisan atlas:frontend:design-dossier template|inspect --workspace=<local-company-repo> --json
php artisan atlas:frontend:company-profile template|inspect --json
php artisan atlas:frontend:directions --task="<brief>" --json
php artisan atlas:frontend:inventory inspect --json
php artisan atlas:frontend:assets template|inspect --json
php artisan atlas:frontend:review template|inspect --json
php artisan atlas:frontend:visual-quality template|inspect --json
php artisan atlas:frontend:quality-budget template|inspect --json
php artisan atlas:frontend:design-system-drift template|inspect --json
php artisan atlas:frontend:evidence-kit prepare --task="<intent>" --workspace=<local-company-repo> --frontend-app=apps/web --output=<evidence-dir> --json
php artisan atlas:frontend:evidence template|verify --json
php artisan atlas:frontend:outcomes summary|record|template --json
php artisan atlas:frontend:handoff compile|template --json
php artisan atlas:frontend:replay inspect|template|runner-kit|evidence-worklist|score-template|external-receipt-template --json
php artisan atlas:frontend:proof catalog|build|pilot --json
php artisan atlas:frontend:publish verify|attest|receipt-template --json
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
operador enxergar varios repos locais. `AtlasFrontendSelectedWorkspaceService`
emite `atlas.frontend.selected_workspace.v1` para provar esse contrato:
`primary_entrypoint=selected_repository`, `portfolio_scan_required=false` e
`parallel_project_runtime_required=false`. O Atlas Frontend nao introduz uma
entidade "Space" entre o operador e o repo: o workspace runtime e sempre o
repositorio selecionado, e `frontend_app_scope` e apenas subescopo auditavel
dentro dele. Em planos/rotas automaticas, o
onboarding anexado ao envelope e read-only: ele projeta readiness do workspace
selecionado, mas nao instala skill, nao escreve docs e nao grava receipt sem
acao explicita do operador. Escrita no repo so ocorre em comandos intencionais
como `atlas:frontend:onboard --write-docs` ou
`atlas:frontend:skill-pack install`. O contrato de workspace selecionado tambem
retorna `recommended_command_sequence` e `attachable_runtime_components` para
Atlas AI/Code exibirem a trilha curta: selected-workspace, onboard,
provider-packet, runbook, evidence-kit prepare e run-certify. Ele tambem anexa
`frontend_runtime_projection` (`atlas.frontend.selected_workspace.runtime_projection.v1`),
um bundle read-only com comandos/hashes para gauntlet, onboarding, provider
packet, runbook, evidence kit e run certification; esse bundle so fica `ready`
quando ha repo selecionado, task amarrada e subescopo valido, nunca autoriza
provider dispatch, nunca conta como evidencia de execucao e carrega
`runtime_projection_hash` proprio para receipt/auditoria. Quando o repo
selecionado tem mapa frontend legivel, o mesmo contrato anexa
`repo_operating_summary` provider-safe com package manager, framework, URL
padrao, contagem de comandos de teste/build/qualidade e status de contexto de
design, sem retornar fonte bruta nem paths absolutos. Para monorepos, o contrato
tambem emite `frontend_app_candidates`, ranqueando apps internos como
`apps/web` por sinais de framework e scripts. O repo selecionado continua sendo
o workspace primario; app candidate e apenas subescopo de frontend, nunca um
projeto paralelo nem autorizacao de dispatch. Se um app interno for candidato
melhor que a raiz, `next_best_action` vira
`confirm_frontend_app_candidate_inside_selected_repo` e o comando sugerido usa
`--workspace=<selected-repo> --frontend-app=apps/web`, nunca
`--workspace=<selected-repo>/apps/web`, antes da projecao runtime.
Se o operador passar um subescopo inexistente, o contrato marca
`requested_frontend_app_subscope_invalid`, exige
`choose_valid_frontend_app_subscope_inside_selected_repo` e o CLI em `--strict`
falha com `requested_frontend_app_subscope_not_found`. Nesse caso
`dispatch_readiness.runtime_projection_allowed=false`, readiness expõe
`frontend_app_candidate_invalid=true` e o Atlas não transforma o app invalido em
workspace.
Atlas Dev e Forge propagam esse status no `enterprise_operating_contract` com
`frontend_app_candidate_status`, contagem, ref primaria e flag de confirmacao,
para que work packets e planos nao ignorem monorepos. Quando o operador passa
`frontend_app` no Atlas Dev, ou quando Forge recebe `expected_files` em
`apps/<app>/...`, o subescopo segue para runbook/provider packet como
`frontend_app_scope`. Quando o operador confirma `--frontend-app=apps/web`, o
selected-workspace payload expõe `confirmed_frontend_app_scope=subscope_selected`
e a `recommended_command_sequence` passa a incluir `--frontend-app=apps/web` nos
comandos `atlas:frontend:provider-packet`, `atlas:frontend:runbook` e
`atlas:frontend:evidence-kit prepare`, preservando o subescopo confirmado até a
instrução do provider e a coleta de evidência.
Ele tambem emite
`dispatch_readiness`: selecao de repo pode permitir projecao read-only do
runtime, mas nunca libera provider dispatch sozinha; dispatch exige onboarding,
gate, provider packet e runbook prontos. O mesmo payload traz
`capability_readiness`, um checklist deterministico de preview local, testes,
build, qualidade, contexto de design, candidato a live mode e evidencia medida.
`measured_evidence` fica missing ate execucao real de evidence-kit/run-certify,
impedindo claim visual por simples selecao de repo. Para a UI do Atlas AI/Code,
`operator_start_panel` resume essa decisao em badges, acao primaria e acoes
bloqueadas, permitindo mostrar o estado inicial do repo sem inventar readiness.
`task_binding` amarra a intencao ao repo selecionado por hash seguro; provider
dispatch exige task binding e nunca retorna texto bruto da tarefa nesse contrato.
`next_best_action` transforma repo, task binding, capabilities e dispatch
readiness em uma acao priorizada para a UI: escolher repo, vincular tarefa,
completar contexto, abrir projecao runtime ou reparar o mapa operacional. Essa
acao e navegacao/comando recomendado, nao evidencia de execucao nem autorizacao
de dispatch.
Inventario multi-repo: `AtlasFrontendCompanyPortfolioService` /
`atlas:frontend:portfolio` emite `atlas.frontend.company_portfolio.v1` apenas
para descoberta opcional. O contrato marca
`inventory_role=optional_repository_discovery_only`,
`primary_runtime_entrypoint=operator_selected_repository_workspace`,
`portfolio_dispatch_allowed=false` e `selected_workspace_handoff_required=true`.
O scanner usa `discovery_model=local_folder_with_multiple_repositories` e
descobre raizes de repos por marcadores como `.git`, `package.json`,
`pnpm-workspace.yaml`, `composer.json`, `artisan`, `pyproject.toml`, `go.mod` e
`Package.swift`. A pasta raiz passada para portfolio e sempre tratada como
container de inventario (`portfolio_root_is_not_selected_workspace=true`), mesmo
que tenha marcadores de projeto; se o operador quer executar nesse proprio repo,
deve chamar `atlas:frontend:selected-workspace --workspace=<repo>`. Quando
encontra uma raiz de repo filha, ele nao desce para promover
`apps/web` ou outro package interno a candidato separado; apps internos so
entram depois, no contrato `selected_workspace`, como `frontend_app`/subescopo.
Cada candidato fica `candidate_not_selected`, recebe score/ranking,
`project_markers` e um `selection_handoff` para
`atlas:frontend:selected-workspace`; nenhum candidate autoriza provider dispatch
ate o operador escolher exatamente um repo.
O mesmo payload inclui `selection_brief`, uma matriz curta para Atlas AI/Code
comparar candidatos por score, status, framework, package manager, ajuste com a
tarefa, motivos de decisao e pendencias antes da execucao. Quando `--task` e
informado, `task_binding` e `task_fit` usam hash/sinais derivados para ranquear
repos sem retornar o texto bruto da tarefa. Esse brief ajuda o operador a
escolher o repo correto, mas continua sem criar escopo runtime nem evidencia de
entrega.
Politica honesta: Atlas pode declarar contrato frontend mais completo que Impeccable/Claude Design somente quando `atlas:frontend:certify` estiver `ready`; claim "melhor do mundo" exige benchmark real, visual smoke, evidencia e replay contra rivais.
Control plane: `AtlasFrontendControlPlaneService` / `atlas:frontend:control-plane` emite `atlas.frontend.control_plane.v1`, agregando certificacao, gauntlet de repo local, benchmark, rival replay, product proof e publicacao. Em monorepo, `--frontend-app=apps/web` entra no `input_scope` como hash e no sinal de gauntlet como `frontend_app_scope`, sem retornar path absoluto nem transformar o subapp em workspace. O sinal `publication_attestation` vem de `AtlasFrontendPublicationAttestationService`, diferencia publicacao nao solicitada, bundle local pendente e publicacao verificada usando apenas hashes/estado, e declara `local_publication_report_is_not_public_distribution=true`. Pode retornar `warning` com runtime certificado quando a prova de mercado ainda falta; claims de runtime governado ficam permitidos, mas `world_best_claim_allowed=false` ate existir replay externo completo e receipt publico verificado.
World-best proof plan: `AtlasFrontendWorldBestProofPlanService` / `atlas:frontend:world-best-plan` emite `atlas.frontend.world_best_proof_plan.v1` e transforma a lacuna de mercado em workstreams executaveis: replay externo, publicacao publica e claim audit. O plano consome `evidence_pack_readiness` do replay, projeta status/resumo no workstream competitivo e tambem anexa `evidence_worklist` provider-safe em memoria, sem escrever arquivo, para mostrar quantos packs precisam de artefatos reais, hashes e espelhamento no manifest. A escrita dessa fila continua sendo comando explicito: `atlas:frontend:replay evidence-worklist --evidence=<dir> --output=<worklist.json> --json`. Manifests pendentes ja declaram `score_attestation` como receipt obrigatorio para todo run e `external_execution_receipt` como receipt obrigatorio para rivais externos, evitando que um replay ausente pareca completo so com hashes de artifact. O workstream de publicacao tambem carrega `publication_attestation` usando `AtlasFrontendPublicationAttestationService` / `atlas.frontend.delivery_handoff_publication_attestation.v1`, para diferenciar bundle local, receipt pendente e publicacao verificada sem expor URL bruta. O proof plan cria a acao `fill_and_verify_rival_replay_evidence_packs` quando algum pack ainda nao tem artefatos/hash refs verificaveis. So retorna `ready` quando o Atlas vence todos os casos completos contra rivais e o product proof tem receipt publico verificado; ate la, `--strict` falha e lista exatamente quais manifests, packs, hashes e receipts faltam.
Gauntlet: `AtlasFrontendGauntletService` / `atlas:frontend:gauntlet` e o entrypoint recomendado para repo local de empresa. Ele compoe runtime contract, task spec, execution gate, design dossier, product blueprint, repo intake, inventory e certificacao, retornando bloqueios, next actions e sequencia de comandos antes de dispatch provider. Em monorepo, `--frontend-app=apps/web` tambem entra no gauntlet como `frontend_app_scope`; comandos de blueprint, task spec, gate, scenario matrix e evidence kit gerados pelo gauntlet preservam esse subescopo para impedir modelagem, gate ou evidencia do pacote errado.
Company repo onboarding: `AtlasFrontendCompanyRepoOnboardingService` / `atlas:frontend:onboard` emite `atlas.frontend.company_repo_onboarding.v1` e prepara um repo local de empresa em uma chamada explicita: instala o skill pack em `.atlas/skills/atlas-frontend`, roda enterprise bootstrap, gera proof pilot, grava `.atlas/frontend/onboarding-receipt.json` e classifica `ready_for_operator_execution`, `prepared_needs_context` ou `blocked`. Para monorepo, `--frontend-app=apps/web` tambem entra no onboarding e no proof pilot gerado por ele, mantendo o repo escolhido como workspace primario e o app como subescopo auditavel. Para repos BlackInk/Refinar/SaaS ja documentados, status pronto libera dispatch com provider packet/runbook; para repo cru, `--write-docs` cria templates e retorna `prepared_needs_context`, sem autorizar provider dispatch ate o operador preencher contexto real. Quando Atlas Dev/Forge apenas avaliam um workspace ja selecionado, usam o mesmo service em modo read-only para nao fazer escrita surpresa, preservando o `frontend_app_scope` projetado. Onboarding nunca conta como evidencia de entrega, mantem `measured_evidence_present=false` e `world_best_claim_allowed=false`.
Enterprise bootstrap: `AtlasFrontendEnterpriseBootstrapService` / `atlas:frontend:enterprise-bootstrap` e o botao operacional para BlackInk, Refinar, repos locais de empresa e SaaS novo. Em `write`, cria templates de dossier e blueprint sem declarar contexto pronto; em `inspect`, agrega dossier, intake, gauntlet e work-order para decidir se provider dispatch e design premium podem comecar. Em monorepo, o bootstrap tambem carrega `frontend_app_scope` e propaga `--frontend-app=apps/web` para o work-order recomendado.
Work order: `AtlasFrontendWorkOrderService` / `atlas:frontend:work-order` transforma task + repo local em pacotes executaveis: context lock, patch/prototype, verificacao visual e handoff certificado. Em monorepo, o work-order carrega o `frontend_app_scope` vindo do gauntlet e injeta `--frontend-app=apps/web` nos pacotes de evidence-kit e scenarios, preservando repo selecionado como workspace primario e app como subescopo. Runbook: `AtlasFrontendExecutionRunbookService` / `atlas:frontend:runbook` converte repo intake, work-order e evidence-kit em sequencia repo-native de install/dev/test/build/evidence/certify/handoff e tambem projeta `public_distribution_proof` com proof bundle, receipt template, `publish attest`, verify e world-best-plan quando houver claim publico/competitivo. Essa etapa publica nao e requisito para handoff comum de cliente; ela e requisito para declarar distribuicao publica ou comparacao world-best, e exige attestation + receipt publico verificado. Em envelopes automaticos de Atlas Dev/Forge, o runbook roda com `write_evidence_kit=false`: projeta comandos, gates e hashes, mas nao cria manifest, templates ou evidence pack no workspace selecionado. Escrita do evidence kit so ocorre quando o operador chama explicitamente `atlas:frontend:evidence-kit prepare` ou um comando que peça escrita. Provider packet: `AtlasFrontendProviderInstructionPacketService` / `atlas:frontend:provider-packet` transforma gate, work-order e runbook em mandatos provider-safe antes de Codex/Claude/Gemini editar e declara quando esta em modo read-only para nao confundir readiness com evidencia real.
Quando um monorepo exige subapp, `--frontend-app=apps/web` define apenas um
subescopo relativo: o workspace primario continua sendo o repo escolhido, mas os
comandos repo-native do runbook usam `cd apps/web && ...`. O provider packet
carrega `frontend_app_scope` para evitar patch no pacote errado sem vazar path
absoluto. Subescopos invalidos, inexistentes ou sem `package.json` bloqueiam o
runbook antes de provider dispatch.
Skill pack: `AtlasFrontendSkillPackService` / `atlas:frontend:skill-pack export` emite `atlas.frontend.skill_pack.v1` e escreve um pacote portavel com `SKILL.md`, `reference/commands.md`, `reference/evidence.md`, `reference/claim-policy.md`, `reference/provider-handoff.md` e `manifest.json`. `atlas:frontend:skill-pack install --workspace=<local-company-repo>` emite `atlas.frontend.skill_pack_install.v1`, instala o pacote em `.atlas/skills/atlas-frontend` no repo da empresa e grava `install-receipt.json`. Ele existe para dar aos providers uma superficie operacional superior ao modelo `skill/SKILL.md` do Impeccable, mas sem transformar skill em fonte de verdade: o runtime Atlas, o provider packet, o proof pilot e os gates continuam autoritativos. O skill pack nunca conta como evidencia de execucao, nao retorna fonte bruta e mantem `world_best_claim_allowed=false`.
Scenario matrix: `AtlasFrontendScenarioMatrixService` / `atlas:frontend:scenarios` transforma task spec em matriz rota x viewport x estado, com checks obrigatorios por cenario antes de visual-quality.
Evidence kit: `AtlasFrontendEvidenceKitService` / `atlas:frontend:evidence-kit` prepara scenario matrix, reports, evidence pack, outcome template e comando run-certify sem declarar evidência real. Em monorepo, `--frontend-app=apps/web` também entra no `frontend_app_scope`, no manifest, nos templates de visual-quality, quality-budget, design-review, evidence-pack e nos comandos de coleta para que screenshots, reports e receipts provem o app certo, não apenas o repo raiz.
Repo intake: `AtlasFrontendRepoIntakeService` / `atlas:frontend:intake` emite `atlas.frontend.repo_intake.v1` como mapa operacional do repositorio local: package manager, framework adapter, entrypoints, rotas candidatas, comandos de teste/build/qualidade, status do dossier e inventario. Ele nao retorna fonte bruta nem paths absolutos e bloqueia dispatch quando o repo nao tem mapa minimo de execucao.
Blueprint: `AtlasFrontendProductBlueprintService` / `atlas:frontend:blueprint` transforma tarefa em modelo de produto, UX success, telas, estados, direcao visual, aceite e mapa de evidencias. Em monorepo, recebe `--frontend-app=apps/web`, compila task spec com esse subescopo e carrega `frontend_app_scope` no blueprint sem trocar o workspace primario. Run certification: `AtlasFrontendRunCertificationService` / `atlas:frontend:run-certify` decide se uma entrega pode declarar frontend done a partir de visual-quality, 5D review, quality budget medido, evidence pack, publicacao e outcome. O check `task_spec_hash_consistent` exige que visual-quality, 5D review, quality budget e evidence pack provem o mesmo `task_spec_hash`; se a cadeia divergir, a certificacao bloqueia a conclusao. O check `frontend_app_scope_consistent` exige que esses mesmos artefatos provem o mesmo subapp de monorepo: uma entrega em `apps/web` nao pode ser certificada com evidence pack de `apps/admin` nem com reports sem scope quando um subescopo foi selecionado. `outcome_memory_available` e `quality_budget_passed` sao criticos: o Atlas nao declara entrega frontend concluida sem outcome memory provider-safe e budgets objetivos para auditoria e aprendizado AEMOR. Task spec compiler: `AtlasFrontendTaskSpecCompilerService` / `atlas:frontend:spec` emite `atlas.frontend.task_spec.v1` antes do gate, sem texto bruto do cliente, com task types, rotas, viewports, estados, jornadas, aceite, gates, testes, evidencias e hash canonico. Em monorepo, `--frontend-app=apps/web` e registrado como `frontend_app_scope`, mantendo `repo_workspace_remains_primary=true` e bloqueando subescopos absolutos, inexistentes ou fora do repo.
Benchmark competitivo: `AtlasFrontendBenchmarkRuntimeService` /
`atlas:frontend:benchmark` emite `atlas.frontend.benchmark_runtime.v1`, matriz
provider-safe contra Atlas Frontend, Impeccable e Claude Design Plugin. Mede
governanca, detector, live mode, evidencia, multiempresa, portabilidade,
distribuicao/proof e outcome memory, mas mantem
`atlas_world_best_frontend_system=false` sem replay externo e demos publicas.
Quando `--rival-evidence=<dir>` e fornecido, o benchmark passa esse diretorio
ao `AtlasFrontendRivalReplayHarnessService`, registra
`rival_evidence_directory_hash` e reflete `external_rival_replay_completed` a
partir do replay real; sem isso, a matriz continua sendo apenas contrato
estatico documentado, nao prova de execucao rival.
Company design profile: `AtlasFrontendCompanyDesignProfileService` e `atlas:frontend:company-profile` emitem `atlas.frontend.company_design_profile.v1`, impedindo o Atlas Frontend de tratar empresas diferentes como uma mesma landing genérica. O perfil exige contexto da empresa, audiência/jobs, brand system, refs de design system com hashes, constraints frontend e quality policy. Para SaaS, ecommerce, enterprise ou multiempresa, `AtlasFrontendDesignRuntimeService` marca esse contrato como `required`. Template nao conta como contexto real, raw prompt/source/customer data sao bloqueados, e claim de brand adaptation depende de perfil `ready`.
Design dossier: `AtlasFrontendDesignDossierService` / `atlas:frontend:design-dossier` trata repo local de empresa do operador como modo padrao. Ele audita ou cria `docs/design/product-experience-brief.md`, `brand-system.md`, `ux-journeys.md`, `design-system.md` e `frontend-quality-policy.md`. Para refinamento premium, BlackInk-like local repo ou novo SaaS, missing docs viram blocker/context action antes de claim visual; template nao conta como contexto preenchido.
Design direction advisor: `AtlasFrontendDesignDirectionAdvisorService` / `atlas:frontend:directions` gera tres direcoes governadas com gates e riscos. Design 5D review: `AtlasFrontendDesignReviewService` / `atlas:frontend:review` verifica filosofia, hierarquia, craft, clareza funcional e originalidade com score minimo 8, evidence refs e bloqueio contra raw prompt/source.
Design system inventory: `AtlasFrontendDesignSystemInventoryService` / `atlas:frontend:inventory` escaneia workspace e emite `atlas.frontend.design_system_inventory.v1` com tokens, componentes, bibliotecas e config refs hashados, sem retornar fonte bruta ou paths absolutos.
Execution gate: `AtlasFrontendExecutionGateService` / `atlas:frontend:gate` compila/resume task spec, verifica `--task-spec-hash` quando declarado e bloqueia dispatch sem tarefa, aceite, plano de teste, plano visual, plano de evidencia, contexto de design system/perfil quando amplo e senior review quando necessario. Em monorepo, o gate aceita `--frontend-app=apps/web`, inclui o `frontend_app_scope` no resumo do task spec e no payload top-level, e portanto bloqueios/warnings se referem ao subapp escolhido dentro do repo selecionado. Atlas Dev projeta isso em `frontend_design_harness_contract.pre_execution_gate`; para repo local selecionado pelo operador tambem anexa `enterprise_operating_contract`, `selected_workspace`, `company_repo_onboarding`, bootstrap, runbook e provider packet. O resumo `enterprise_operating_contract.hash_refs` carrega `selected_workspace_hash` e `runtime_projection_hash` para auditar qual bundle read-only foi apresentado antes de qualquer dispatch. Forge projeta `atlas_frontend_pre_execution_gate` por work packet `surface_ui` e, quando o workspace local e conhecido, anexa `atlas_frontend_enterprise_operating_contract`, `atlas_frontend_selected_workspace`, `atlas_frontend_company_repo_onboarding`, bootstrap, runbook e provider packet, tambem propagando `runtime_projection_hash` no resumo operacional.
Repair planner: `AtlasFrontendRepairPlannerService` / `atlas:frontend:repair-plan` transforma blockers, warnings e failed gates em plano deterministico de reparo, rerun gates e evidencias. Outcome memory: `AtlasFrontendOutcomeMemoryService` / `atlas:frontend:outcomes` registra outcomes provider-safe por drivers, gates, failed gates e evidence refs, alimentando AEMOR sem texto bruto de cliente.
Delivery handoff: `AtlasFrontendDeliveryHandoffService` / `atlas:frontend:handoff` compila um pacote empresarial provider-safe para cliente/equipe a partir de run certification e evidence manifest. O handoff exige `run_certification_hash`, `task_spec_hash`, evidence manifest com hash compatível, `frontend_app_scope` compatível, `publication_attestation`, claim policy e known limitations. Em monorepo, um handoff de `apps/web` bloqueia se o evidence manifest ou publication report entregue pertencer a outro subapp ou ao repo raiz sem o mesmo scope. A attestation vem de `AtlasFrontendPublicationAttestationService` / `atlas.frontend.delivery_handoff_publication_attestation.v1`, diferencia explicitamente `missing_report`, `local_bundle_ready_publication_pending` e `public_verified`, carrega apenas hashes/estado do receipt publico e declara `local_publication_report_is_not_public_distribution=true`. Ele permite declarar handoff de entrega frontend, mas nao autoriza distribuicao publica sem publication report verificado e nunca autoriza claim "world best".
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
verificacoes, score breakdown, score por run e `score_attestation`
(`atlas.frontend.rival_replay.score_attestation.v1`). Templates pendentes nao contam
como replay real, manifest com prompt/source bruto e invalido, inclusive quando
o dado proibido aparece aninhado em `metadata`, `debug` ou outro objeto, e o claim
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
verificado. Para runs de rivais externos, o manifest tambem precisa carregar
`external_execution_receipt`
(`atlas.frontend.rival_replay.external_execution_receipt.v1`) com status
`verified`, case/system iguais, aprovacao do operador, superficie externa e os
mesmos hashes de output, screenshots, anti-slop e verificacao declarados no
manifest, alem do `evidence_pack_verification_hash` do pack verificado. Sem esse
receipt, ou se esse hash divergir, um manifest de Impeccable/Claude Design nao
pode ficar complete nem sustentar claim externo/world-best. O verifier tambem varre
os artefatos JSON do pack de forma recursiva
e bloqueia `artifact_forbidden_raw_prompt_or_source_field_present` quando prompt,
source, dados de cliente, tokens ou segredos aparecem escondidos dentro da
evidencia. A `score_attestation` impede placar auto-declarado: cada manifest
complete precisa de status `verified`, case/system, `rubric_hash`,
`score_breakdown_hash`, `score_total`, `score_max`, `reviewer_ref_hash`,
aprovacao do operador, superficie de scoring permitida e
`evidence_pack_verification_hash` batendo com o evidence pack verificado. Se a
attestation nao bater com o manifest ou com a evidencia, o run fica invalid e o
world-best claim continua bloqueado. Quando todos os replays estao completos mas o Atlas perde algum caso,
o harness nao deixa `remaining_gaps` vazio: emite
`atlas_does_not_win_every_complete_case` e anexa
`competitive_diagnostics` com caso, melhor rival, delta de pontos e proxima acao
`improve_atlas_frontend_case_and_rerun_replay`. Esse diagnostico compara tambem
o `score_breakdown` do Atlas contra o melhor rival e emite `dimension_gaps` por
dimensao da rubrica (`product_intent_fit`, hierarquia, responsividade,
performance, evidencia etc.), com pontos para empatar e acao `improve_<dimension>`.
Assim o replay perdido vira plano de melhoria por dimensao, nao apenas placar
agregado. O repair planner tambem aceita esses `dimension_gaps` e compila uma
estrategia `competitive_replay_repair`, com passos como
`repair_competitive_dimension_product_intent_fit`, gates de rerun
(`rival_replay_inspect`, `competitive_rubric`, `visual_quality_gate`) e
evidencias esperadas (`rival_replay_manifest`, `score_breakdown`,
`dimension_improvement_receipt`). O comando tambem aceita gaps competitivos
direto via `--dimension-gap=<dimension:points_to_match:delta_vs_best_rival:best_rival_score[:case_id[:best_rival_system]]>`,
permitindo que Atlas AI/Code ou um operador transforme uma derrota no replay em
repair plan sem transportar texto bruto de prompt, fonte ou cliente. A dimensao
precisa ser um id canonico da rubrica competitiva; se o operador ou o replay
emitir `not_a_rubric_dimension`, o planner bloqueia em modo strict com
`invalid_competitive_dimension_gap` / `dimension_not_in_competitive_rubric`, nao
gera `repair_competitive_dimension_*` falso e registra apenas slug/hash
provider-safe do valor invalido. Para reduzir
friccao operacional, cada `dimension_gap` do replay ja inclui
`case_id`, `best_rival_system` e `repair_plan_command`, e cada caso perdido
agrega `recommended_repair_plan_commands`; a UI do Atlas AI/Code pode transformar essas
strings em acoes sem reconstituir prompt, fonte ou path sensivel. O world-best proof plan consome
esse diagnostico e adiciona
`competitive_repair_plan` ao workstream de replay, alem da acao
`improve_atlas_frontend_until_replay_wins_every_case`, transformando benchmark
perdido em ciclo de reparo mensuravel, nao em claim silenciosamente negado. O comando
`runner-kit` emite
`atlas.frontend.rival_replay_runner_kit.v1` e escreve `replay-runner-kit.json`
com 15 run packets, passos de execucao, checklist de evidencias, politica de
score, exigencia de attestation de scoring e comandos de inspeção/world-best plan. Ele tambem prepara um
`evidence/evidence-pack.json` por run com `case_id`, `system` e `task_spec_hash`
preenchidos, mais os artifacts obrigatorios do evidence-pack verifier. O `inspect`
emite `evidence_pack_readiness` com total/present/passed/blocked/missing e blockers
por run, para mostrar exatamente quais arquivos/hash refs ainda faltam antes de
qualquer claim competitivo. O comando `evidence-worklist` gera
`atlas.frontend.rival_replay_evidence_worklist.v1` e escreve
`replay-evidence-worklist.json`: uma fila provider-safe por caso/sistema com
`pack_manifest_ref`, `run_manifest_ref`, slots de artefato, comando `shasum` por
arquivo e `manifest_hash_mapping` para espelhar `output_artifact`,
`screenshot_set`, `anti_slop_report` e `verification_report` no manifest. Quando
o evidence pack ja passou mas um run de rival externo ainda falha por
`external_execution_receipt_*`, a mesma fila cria
`fill_external_execution_receipt_<case>_<system>` com schema, campos obrigatorios,
hash mapping, superficies permitidas e comando `external-receipt-template`.
Quando o run falha por `score_attestation_*`, cria
`fill_score_attestation_<case>_<system>` com schema, campos obrigatorios,
`rubric_hash`, `canonical_sha256(manifest.score_breakdown)`,
`evidence_pack_verification_hash`, superficies de scoring permitidas e passos
para rodar o `score-template`, preencher a attestation sem prompt bruto, fonte de
cliente ou identidade real do reviewer e voltar ao `inspect`. Essa worklist
tambem nao e evidencia; ela reduz ambiguidade operacional para coletar artefatos
reais, provar execution receipt/scoring e voltar ao `inspect`.
Para reduzir erro manual em rivais externos,
`atlas:frontend:replay external-receipt-template --evidence=<dir> --case=<case>
--system=<external-rival> --json` le o manifest e o evidence pack verificado,
preenche `manifest_hashes` com output, screenshots, anti-slop, verificacao e
`evidence_pack_verification_hash`, e escreve
`external-execution-receipt-template.json` com
`manifest_patch.external_execution_receipt`. Esse template fica
`pending_operator_approval`, nao e evidencia, nao autoriza claim e so vira receipt
valido quando o operador marcar `status=verified`, `operator_approved=true` e
`captured_at` depois de executar o rival externo contra o task spec imutavel. O
comando bloqueia `external_rival_system_required` para impedir receipt externo no
run interno do Atlas.
Para reduzir erro manual, `atlas:frontend:replay score-template --evidence=<dir>
--case=<case> --system=<system> --reviewer-ref-hash=<sha256> --json` le o
manifest e o evidence pack verificado, preenche `rubric_hash`,
`score_breakdown_hash`, `score_total`, `score_max` e
`evidence_pack_verification_hash`, e escreve
`score-attestation-template.json` com `manifest_patch.score_attestation`. Esse
template fica `pending_operator_approval`, nao e evidencia, nao autoriza claim e
so vira attestation valida quando o operador marcar `status=verified`,
`operator_approved=true` e `reviewed_at` apos revisar os artefatos contra a
rubrica. O comando bloqueia `unknown_case_id` e `unknown_system_id` para impedir
attestation solta fora da matriz canonica de replay. Runner kit
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
`manifest.json` em um bundle estatico local publicavel. O bundle tambem inclui
`product_site_assets` (`atlas.frontend.product_proof_site_assets.v1`) com
`getting-started.html`, `downloads.json`, hashes e contagem de downloads para
site/docs/downloads locais; esses assets continuam sendo prova local e nao
autorizam distribuicao publica sem receipt verificado. Em monorepo,
`--frontend-app=apps/web` grava `frontend_app_scope` no manifest do bundle para
que publication verifier e handoff provem que o site publicado corresponde ao
mesmo subapp certificado. O manifest tambem inclui
`publication_workflow` (`atlas.frontend.product_proof_publication_workflow.v1`)
com comandos provider-safe para criar receipt template a partir do bundle,
emitir attestation canonica, verificar publicacao e rerodar o proof plan world-best, alem das evidencias
externas exigidas (`public_https_url`, HTTP 200, hash match, approval e scope
compativel). O bundle melhora a
prontidao de produto, mas nao autoriza claim de distribuicao publica enquanto
nao houver hospedagem externa verificada. `atlas:frontend:proof pilot --task
"<intent>" --workspace=<local-company-repo> --provider=<provider> --output=<dir>
--acceptance --test-plan --visual-quality-plan --evidence-plan
--senior-design-review --json --strict` emite
`atlas.frontend.company_repo_proof_dossier.v1`, o dossie pre-execucao para
BlackInk, Refinar ou qualquer repo local de empresa. Ele agrega enterprise
bootstrap, provider instruction packet, runbook, evidence kit, certificacao do
runtime e product proof em um unico artefato auditavel (`pilot-dossier.json`).
Em monorepo, `--frontend-app=apps/web` tambem entra no proof pilot: o dossie
carrega `frontend_app_scope`, o runbook opera no subapp, o provider packet recebe
o mesmo escopo e os comandos de collection/evidence kit mantem `--frontend-app`
ate a certificacao e handoff.
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
continua integro (`local_ready`) validando `manifest.json`, `bundle_hash`,
`index.html`, assets, `product_site_assets`, `getting-started.html`,
`downloads.json`, entradas do manifest de downloads e hashes. Se alguem editar
o tutorial, o manifest de downloads ou o proprio manifest depois da geracao, o
verificador bloqueia com hash mismatch antes de qualquer claim publico. O claim
publico so muda para `public_verified` quando existe receipt
`atlas.frontend.publication_receipt.v1` com URL HTTPS, status HTTP 200, hash do
bundle correspondente, `index_content_hash` igual ao hash real do `index.html`
local, `frontend_app_scope` compatível quando o bundle veio de subapp de
monorepo, e aprovacao do operador. `atlas:frontend:publish verify --strict` falha
sem receipt publico verificado. `atlas:frontend:publish attest --bundle=<bundle>
--receipt=<receipt> --json` emite a attestation canonica
`atlas.frontend.delivery_handoff_publication_attestation.v1`, usando o mesmo
service consumido por handoff, control-plane e world-best-plan, para inspecionar
bundle local/publicacao publica sem abrir outro fluxo. Para reduzir erro manual no ultimo passo,
`atlas:frontend:publish receipt-template --bundle=<bundle> --output=<bundle>
--json` preenche `bundle_hash`, `index_content_hash`, `local_index_hash` e
`frontend_app_scope` diretamente do manifest local, mas o payload mantem
`template_is_not_public_verification=true` e
`prefilled_bundle_hash_is_not_public_verification=true`: template preenchido
nao e prova publica, apenas prepara o receipt que ainda precisa de URL HTTPS,
HTTP 200 e aprovacao do operador.
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
exato unico, hash de precondicao do arquivo e `private_integrity_hash` da sessao
completa, bloqueando source drift e adulteracao local de
`.atlas/frontend-live/sessions/*.json` antes de `accept`/`recover`. O payload
publico continua sem retornar source original ou variantes brutas; a integridade
privada prova que o patch aceito e o mesmo preparado. Ainda faltam replay rival
real e product proof publico para claim de live mode superior ao mercado inteiro.

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
