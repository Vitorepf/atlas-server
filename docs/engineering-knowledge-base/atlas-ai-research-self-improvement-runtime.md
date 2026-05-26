---
id: atlas-ai-research-self-improvement-runtime
type: engineering_knowledge
title: Atlas AI Research Intelligence And Self-Improvement Runtime
status: active
category: strategic-governance
priority: 100
summary: Contrato canonico para pesquisa de maximo nivel, promocao para documentacao, planejamento, implementacao validada e autoaprimoramento governado do Atlas.
tags:
  - atlas-ai
  - research-intelligence
  - self-improvement
  - source-quality
  - evolution-runtime
capabilities:
  - research_self_improvement_runtime
  - research_source_quality_consumption
  - research_to_docs_runtime_consumption
  - governed_self_improvement_runtime
  - evolution_velocity
decisions:
  - Pesquisa de alto nivel e frente P0 do Atlas, nao atividade auxiliar.
  - Fonte e evidencia vencem opiniao, hype e resposta de provider.
  - Documentacao canonica vem antes de implementacao estrutural.
  - Implementacao vem depois de pesquisa, sintese, AP/plano e validacao de risco.
  - Self-Improvement pode propor, priorizar e auditar; nao autoaplica mudanca estrutural sem gate.
  - Claims de melhoria precisam passar pela Self-Improvement Governance Ladder antes de promocao: Proposal Power Gate, before/after delta, invariant lock e regression sentinel.
maintenance:
  - Atualize quando Atlas criar crawler, source registry, research scheduler, evaluator, planner automatico ou Curator mais autonomo.
  - Leia antes de qualquer pesquisa longa, provider-release review, self-improvement proposal ou plano para acelerar evolucao do Atlas.
related_paths:
  - docs/engineering-knowledge-base/research-self-improvement/research-operating-system.md
  - docs/engineering-knowledge-base/research-self-improvement/evidence-lake-and-citation-health.md
  - docs/engineering-knowledge-base/research-self-improvement/source-connectors-and-capture.md
  - docs/engineering-knowledge-base/research-self-improvement/multi-agent-research-roles.md
  - docs/engineering-knowledge-base/research-self-improvement/scheduled-research-and-triggers.md
  - docs/engineering-knowledge-base/research-self-improvement/source-quality-and-trust-ladder.md
  - docs/engineering-knowledge-base/research-self-improvement/research-pipeline.md
  - docs/engineering-knowledge-base/research-self-improvement/research-to-docs-promotion.md
  - docs/engineering-knowledge-base/research-self-improvement/implementation-planning-and-rollout.md
  - docs/engineering-knowledge-base/research-self-improvement/continuous-self-improvement-loop.md
  - docs/engineering-knowledge-base/research-self-improvement/schemas-and-packets.md
  - docs/engineering-knowledge-base/research-self-improvement/metrics-and-evals.md
  - docs/engineering-knowledge-base/research-self-improvement/automation-runbook.md
  - docs/engineering-knowledge-base/research-self-improvement/failure-modes.md
  - docs/engineering-knowledge-base/research-self-improvement/enterprise-excellence-checklist.md
  - docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md
  - docs/engineering-knowledge-base/atlas-ai-cognitive-runtime.md
  - docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md
  - docs/engineering-knowledge-base/atlas-ai-content-intelligence-curation.md
  - docs/engineering-knowledge-base/domains/self-improvement.md
  - docs/ap/AP-689-research-self-improvement-runtime-contract.md
  - docs/engineering-knowledge-base/atlas-domain-company-runtimes.md
  - docs/engineering-knowledge-base/atlas-ai-multi-domain-implementation-sequence.md
  - database/migrations/2026_05_18_040000_create_ai_research_domain_tables.php
  - app/Models/AiResearchRun.php
  - app/Models/AiResearchSource.php
  - app/Models/AiResearchClaim.php
  - app/Models/AiResearchSynthesis.php
  - app/Services/Ai/ResearchDomain/ResearchDomainCanon.php
  - app/Services/Ai/ResearchDomain/ResearchDomainManifestSeeder.php
  - app/Services/Ai/ResearchDomain/ResearchRuntimeService.php
  - app/Services/Ai/ResearchDomain/ResearchSourcePlanService.php
  - app/Services/Ai/ResearchDomain/ResearchSourceQualityService.php
  - app/Services/Ai/ResearchDomain/ResearchClaimService.php
  - app/Services/Ai/ResearchDomain/ResearchSynthesisService.php
  - app/Services/Ai/ResearchDomain/ResearchEvidenceBridge.php
  - app/Services/Ai/ResearchDomain/ResearchControlPlaneProjection.php
  - app/Services/Ai/ResearchDomain/ResearchReadinessService.php
  - app/Console/Commands/AtlasAiResearchDomainCommand.php
  - tests/Concerns/CreatesResearchDomainTables.php
  - tests/Feature/Ai/ResearchDomain/
owner: atlas-ai
layer: 0.5-and-2
line_limit: 260
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-research-self-improvement-runtime

graph_title: Atlas AI Research Intelligence And Self-Improvement Runtime

graph_world: atlas

graph_layer: system

graph_kind: policy

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active
implementation_status: active_meta_8a_research_company_runtime
implementation_boundary: implemented_local_research_domain_runtime_no_external_fetch_or_auto_promotion

graph_source: repo
human_name: Atlas AI Research Intelligence And Self-Improvement Runtime
canonical_name: Atlas AI Research Intelligence And Self-Improvement Runtime
technical_name: atlas-ai-research-self-improvement-runtime
cartography_type: policy
canonical_source: docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md

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
  - strategic-governance

evidence:
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
  - database/migrations/2026_05_18_040000_create_ai_research_domain_tables.php
  - app/Services/Ai/ResearchDomain/ResearchRuntimeService.php
  - app/Console/Commands/AtlasAiResearchDomainCommand.php
  - tests/Feature/Ai/ResearchDomain/

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan test tests/Feature/Ai/ResearchDomain"
  - "php artisan atlas:ai:research-domain --action=readiness --json"

requires_evidence: true

risk_level: high

visual_tags:
  - system
  - policy
  - strategic-governance

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
# Atlas AI Research Intelligence And Self-Improvement Runtime

## Resumo

Esta e a lei canonica para o Atlas evoluir em velocidade exponencial sem
perder qualidade, seguranca, memoria, governanca ou capacidade de auditoria.

O Atlas nao deve apenas implementar rapido. Ele deve pesquisar melhor, escolher
fontes melhores, sintetizar melhor, transformar descoberta em documentacao
canonica, planejar com precisao, implementar com testes e corrigir com base em
evidencia.

## Papel no Atlas

Governa como pesquisa vira source-quality, sintese, documentacao canonica,
plano/AP, implementacao validada e proposta de self-improvement.

## Onde Se Encaixa

Filho do Atlas AI canonical architecture index e da sequencia multi-domain.
Meta 8A implementa o Research Company Runtime local; Tool Runtime, promocao
automatica e fetch externo continuam fora deste boundary.

## Contratos

Contratos principais: `atlas.ai.research_run.v1`,
`atlas.ai.research_source.v1`, `atlas.ai.research_claim.v1`,
`atlas.ai.research_synthesis.v1` e readiness
`atlas.ai.research_domain.readiness.v1`.

## Fluxo

```text
Research Intelligence -> Source Quality -> Evidence Synthesis
-> Documentation Law -> Planning / AP -> Implementation -> Validation
-> Evidence -> Self-Improvement Proposal -> Promotion / Rollback
```

## Regras para IA

Fonte primaria, evidencia, doc canonica, AP/plano, teste/replay e proposta
revisavel vencem opiniao, conversa e autoaplicacao invisivel.

## Non-Negotiable Laws

- Fonte primaria vence resumo.
- Evidencia vence confianca.
- Documentacao canonica vence conversa.
- AP/plano vence improviso estrutural.
- Teste e replay vencem intuicao.
- Proposta revisavel vence autoaplicacao invisivel.
- Rollback planejado vence autonomia sem freio.
- Pesquisa sem fonte rastreavel nao vira memoria, policy, runtime nem codigo.

## Authority Map

| Area | Canonical doc |
|---|---|
| Research Operating System | `research-self-improvement/research-operating-system.md` |
| Evidence Lake and Citation Health | `research-self-improvement/evidence-lake-and-citation-health.md` |
| Source connectors and capture | `research-self-improvement/source-connectors-and-capture.md` |
| Multi-agent research roles | `research-self-improvement/multi-agent-research-roles.md` |
| Scheduled research and triggers | `research-self-improvement/scheduled-research-and-triggers.md` |
| Source trust ladder | `research-self-improvement/source-quality-and-trust-ladder.md` |
| Research pipeline | `research-self-improvement/research-pipeline.md` |
| Research to documentation law | `research-self-improvement/research-to-docs-promotion.md` |
| Planning and rollout | `research-self-improvement/implementation-planning-and-rollout.md` |
| Continuous self-improvement | `research-self-improvement/continuous-self-improvement-loop.md` |
| Schemas and packets | `research-self-improvement/schemas-and-packets.md` |
| Metrics and evals | `research-self-improvement/metrics-and-evals.md` |
| Automation runbook | `research-self-improvement/automation-runbook.md` |
| Failure modes | `research-self-improvement/failure-modes.md` |
| Enterprise checklist | `research-self-improvement/enterprise-excellence-checklist.md` |
| Long-session cognition | `atlas-ai-cognitive-runtime.md` |
| External provider releases | `atlas-ai-provider-evolution-intelligence.md` |
| Content ingestion and curation | `atlas-ai-content-intelligence-curation.md` |
| Operational domain | `domains/self-improvement.md` |
| Self-improvement governance ladder | `atlas-self-improvement-governance-ladder.md` |

## Required Runtime Shape

The runtime must be layered:

1. **Research Scout** gathers candidates from approved source classes.
2. **Source Judge** scores source quality, freshness, provenance and conflict.
3. **Evidence Synthesizer** extracts claims, proofs, limits and uncertainty.
4. **Documentation Promoter** updates canonical docs or creates APs.
5. **Implementation Planner** creates small, reversible execution blocks.
6. **Builder** implements only scoped blocks.
7. **Validator** runs focused tests, docs-health, architecture validation and
   diff checks.
8. **Self-Improvement Reviewer** emits proposals, findings and metrics.
9. **Promotion Gate** decides promote, hold, archive, rollback or research more.

## Research Quality Contract

Every research packet must preserve:

- objective;
- source list;
- source tier;
- retrieval timestamp;
- claim list;
- direct evidence or citation pointer;
- uncertainty and conflicting evidence;
- why the source matters for Atlas;
- what must not be implemented yet;
- recommended doc/AP/code impact.

LLM-generated text can summarize and compare. It cannot be the authority for a
factual claim unless backed by a source or by local repo evidence.

## Documentation-First Rule

For structural changes, Atlas must update or create the governing document
before writing runtime code. The doc must say:

- what problem is being solved;
- what authority owns the behavior;
- what is allowed;
- what is forbidden;
- what metrics prove success;
- what validation is required;
- what rollback or fail-closed behavior exists.

Code without this contract is experimental and cannot become enterprise
authority.

## Research-To-Implementation Ratio

For major evolution fronts, default effort should bias toward research,
documentation and planning before implementation; shrink only for tiny fixes
or mechanical follow-through from an approved AP.

## Automation Boundary

Automation is allowed to:

- find source candidates;
- create research packets;
- score source trust;
- draft documentation updates;
- draft APs;
- generate implementation plans;
- run read-only audits;
- run tests and validators;
- emit Self-Improvement proposals.

Automation is not allowed to:

- change routing policy directly;
- promote provider releases directly;
- write memory as truth without source gate;
- auto-apply migrations;
- mutate Kernel/Policy/Receipt/Ledger/Curator without review;
- delete canonical docs;
- bypass architecture validation;
- claim improvement without metric or replay.

## Core Metrics

Research quality:

- primary-source ratio;
- citation coverage;
- stale-source rate;
- contradiction detection rate;
- hallucinated-source rate, target zero;
- unresolved uncertainty count.

Evolution velocity:

- research-to-AP latency;
- AP-to-validated-block latency;
- validated-block-to-promotion latency;
- percent of implementation preceded by doc/AP;
- rework caused by weak research.

Self-improvement quality:

- proposal acceptance rate;
- false-positive proposal rate;
- rollback rate;
- bypass prevention count;
- post-promotion regression count.

## Enterprise Target

Atlas should exceed normal agent products by combining:

- long research runs;
- source-quality gates;
- state-of-art synthesis;
- canonical documentation law;
- small implementation blocks;
- evidence ledger;
- replay and benchmarks;
- proposal-only self-improvement;
- rollback and auditability.

The target is not "more autonomy". The target is more correct autonomy.

## Status De Implementacao (Meta 8A — Research Company Runtime)

Meta 8A do Multi-Domain Implementation Sequence entrega o **Research Company
Runtime** como primeiro Domain Company Runtime focado em pesquisa: source
plan, source quality, claims com attribution obrigatoria, contradiction
check, synthesis e evidence pack auditavel.

Boundary atual: runtime local implementado e testado para planejamento,
qualidade de fontes, attribution, contradiction check, synthesis, readiness,
smoke e control-plane projection. Nao faz fetch externo, scraping, promocao
automatica para docs canonicos nem autoaplica mudanca estrutural.

Esta camada NUNCA realiza scraping ou fetch externo direto — o futuro Tool
Runtime (Meta 5) executara web.search/web.fetch e devolvera factors
estruturados para `ResearchSourceQualityService`. Aqui ficam apenas os
contratos canonicos e a maquina de estado.

Persistencia (4 tabelas):

- `ai_research_runs` (`atlas.ai.research_run.v1`) — pergunta, hipotese,
  source_plan, status, certification_hash, evidence_pack_hash, missing_requirements.
- `ai_research_sources` (`atlas.ai.research_source.v1`) — todas as fontes,
  status em `{planned, accepted, rejected}`, source_quality, quality_factors,
  reason_rejected e `citation_hash` deterministico (sha256 canonical).
- `ai_research_claims` (`atlas.ai.research_claim.v1`) — afirmacoes com
  `source_refs` obrigatorio, `claim_status`, `contradiction_status` e
  `claim_hash` deterministico.
- `ai_research_syntheses` (`atlas.ai.research_synthesis.v1`) — sintese final
  com `brief`, `claim_refs`, `source_refs`, `contradictions`,
  `open_questions`, `overall_confidence`, `evidence_refs` (pack completo) e
  `synthesis_hash`.

Services (`App\Services\Ai\ResearchDomain`):

- `ResearchDomainCanon` — enums canonicos: source types, source statuses,
  claim statuses, contradiction statuses, primary/low-triangulation types,
  minimum diversity e thresholds.
- `ResearchDomainManifestSeeder::seed()` — idempotente; reusa o payload
  canonical do Meta 2 (`DomainSeedManifests::research()`).
- `ResearchRuntimeService::run()` orquestra pipeline plan -> score ->
  claim -> contradiction-check -> synthesize -> certify.
- `ResearchRuntimeService::smokeRun()` end-to-end deterministico para CLI/tests.
- `ResearchSourcePlanService::plan(question, sources, hypothesis, context)`
  abre o run e persiste sources com status `planned`.
- `ResearchSourceQualityService::score(source, factors)` aplica regras
  deterministicas (peer_reviewed, primary, recency_days, domain_authority,
  vendor_bias, low_triangulation). `scoreRun(run, factorsByHash)` aplica para
  todo o run e calcula `source_diversity`.
- `ResearchClaimService::record(run, statement, citationHashes, confidence)`
  exige `>=1` citation hash valido (rejeita citations de fontes rejeitadas);
  `runContradictionCheck(run)` flag par-a-par bidirecional;
  `declareContradiction(claim, reason)` registra contradicao aceita.
- `ResearchSynthesisService::synthesize(run, brief, context)` constroi
  synthesis + pack via bridge, calcula `missing_requirements`,
  `overall_confidence` e seta `certification_status` em
  `{passed, failed}`. Falha por padrao quando ha `<2` sources aceitas,
  diversidade `<2`, claims sem attribution ou contradiction detectada sem
  resolucao.
- `ResearchEvidenceBridge::projectToMissionEvidence(run, synthesis)` projeta
  source refs + synthesis no `ai_mission_evidence_refs` quando Mission
  Foundation esta presente; tolerante a ausencia.
- `ResearchEvidenceBridge::buildEvidencePack(run, synthesis)` retorna pack
  canonical (`sources_accepted`, `sources_rejected`, `claims`,
  `synthesis_hash`).
- `ResearchReadinessService::report()` — schema
  `atlas.ai.research_domain.readiness.v1`.
- `ResearchControlPlaneProjection::snapshot()` — schema
  `atlas.ai.research_domain.control_plane.v1` com 4 secoes
  (runs/sources/claims/syntheses) e agregados por status, source type e
  rejection reason.

Comando Artisan:

```bash
/opt/homebrew/bin/php artisan atlas:ai:research-domain --action=readiness --json
/opt/homebrew/bin/php artisan atlas:ai:research-domain --action=seed-manifest --json
/opt/homebrew/bin/php artisan atlas:ai:research-domain --action=smoke --json
/opt/homebrew/bin/php artisan atlas:ai:research-domain --action=control-plane --json
```

Invariantes canonicos (forbidden_actions herdados do manifest research):

- **claim sem source_ref** -> `ResearchClaimService::record` lanca
  `InvalidArgumentException`.
- **fonte unica para risco alto** -> falha de certification por
  `source_diversity_below_minimum`.
- **resposta superficial** -> sintese sem 2+ fontes aceitas + claim + brief
  vira `certification_status=failed`.
- **contradicao silenciosa** -> claims contraditorios sem resolucao bloqueiam
  certification.

Fora de escopo de Meta 8A (continua em Metas seguintes):

- Tool Runtime para web.search/web.fetch/pdf.parse (Meta 5) — factors hoje
  vem do operador/teste.
- Auto-classificacao de claims por LLM/embedding — heuristica determinista v1.
- Promotion automatica de brief para docs canonicos — `research-to-docs-promotion`
  doc continua governando.

## Escopo de Implementacao

Implementado localmente: migrations, models, services, command, readiness,
smoke, source quality, claims, contradiction check, synthesis, evidence pack
e control-plane projection. Fora do escopo atual: fetch externo, scraping,
LLM claim classification e promocao automatica para docs canonicos.

## Dependencias

Depende dos docs `research-self-improvement/*`,
`atlas-self-improvement-governance-ladder.md`, `domains/self-improvement.md`,
Tool Runtime futuro para fetch externo e Evidence/Mission bridges quando
presentes.

## Evidencias

Evidencias atuais: `php artisan atlas:ai:research-domain --action=readiness
--json` com 19/19 checks passed, `php artisan test
tests/Feature/Ai/ResearchDomain`, migration `create_ai_research_domain_tables`
e services em `app/Services/Ai/ResearchDomain`.

## Riscos

Risco principal: IA confundir runtime local de pesquisa com crawler externo,
auto-promocao de docs ou self-improvement autônomo. Esses caminhos continuam
bloqueados por source gate, docs promotion law e review.

## Exemplos

Correto: criar research packet com fontes, claims e synthesis antes de um AP.
Proibido: usar resumo de provider sem fonte como memoria, policy ou codigo.

## Proximas Acoes

Manter readiness e testes ResearchDomain verdes; quando Tool Runtime entregar
fetch externo, atualizar este doc com novo boundary e provas.
