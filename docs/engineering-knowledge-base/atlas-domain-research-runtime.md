---
id: atlas-domain-research-runtime
type: engineering_knowledge
title: Atlas Research Domain Runtime
status: building
risk_level: medium
authority_class: domain_runtime
graph_parent: atlas-ai-multi-domain-implementation-sequence
depends_on:
  - atlas-ai-research-self-improvement-runtime
  - atlas-ai-knowledge-governance-system
  - atlas-constitutional-kernel
  - atlas-ai-autonomy-admission
  - atlas-ai-finance-domain
forbidden_changes:
  - relax review-only invariant (Research never executes; always analysis-only)
  - bypass source_grounded=true requirement
  - lower MIN_SOURCE_DIVERSITY or MIN_ACCEPTED_SOURCES thresholds without canon review
  - duplicate ResearchDomainCanon enums anywhere
  - touch external_rivals_certification
category: architecture
priority: 92
summary: Doc-mãe do runtime PHP do ResearchDomain. Aponta para os services existentes, profile no Registry, CLI canon e entrada no ACOS Scorecard. Declara não-duplicação explícita e registra que o gate standalone antigo foi aposentado.
tags:
  - atlas-ai
  - domains
  - research
  - source-grounded
  - review-only
capabilities:
  - research_domain_runtime
  - source_grounded_compliance
  - claim_attribution
  - research_claim_contradiction_review
  - evidence_pack_bridge
decisions:
  - Research é domínio review-only (analysis_review_only); nunca executa.
  - Research permanece review-only e source-grounded por política declarada. O enforcement comprovável em código é a certificação de thresholds em ResearchSynthesisService (MIN_ACCEPTED_SOURCES/MIN_SOURCE_DIVERSITY/MIN_CLAIMS) mais a política declarativa do registry (gate_policy autonomy_ceiling=source_grounded_review). Não existe gate de execução — o gate standalone foi deletado em 2026-07-05 (commit 26333fec23) e nunca teve wiring.
  - ResearchDomain e Research Company Runtime sao adapter/executor do Research OS universal, nao uma arquitetura paralela de pesquisa.
  - Storage de runs/sources/claims/synthesis vive em DB (decisão 2026-05-18).
  - Flows canônicos: research.quick e research.super (registry).
  - Research é subsystem do ACOS Scorecard como ARDR (Research Domain Runtime); entrou como linha 52 em 2026-05-26, hoje (2026-07-05) é a entrada 70 de 73 — posição de lista, não ID estável.
maintenance:
  - Quando flows, gates, thresholds, ou runtime mudar, atualize este documento.
  - Não duplique enums já em ResearchDomainCanon — sempre referencie a fonte.
  - Não migre DB → JSONL para runs/sources/claims/synthesis — a decisão é canon.
related_paths:
  - app/Services/Ai/ResearchDomain/ResearchDomainCanon.php
  - app/Services/Ai/ResearchDomain/ResearchRuntimeService.php
  - app/Services/Ai/ResearchDomain/ResearchSourcePlanService.php
  - app/Services/Ai/ResearchDomain/ResearchSourceQualityService.php
  - app/Services/Ai/ResearchDomain/ResearchClaimService.php
  - app/Services/Ai/ResearchDomain/ResearchSynthesisService.php
  - app/Services/Ai/ResearchDomain/ResearchEvidenceBridge.php
  - app/Services/Ai/ResearchDomain/ResearchReadinessService.php
  - app/Services/Ai/ResearchDomain/ResearchControlPlaneProjection.php
  - app/Services/Ai/ResearchDomain/ResearchDomainManifestSeeder.php
  - app/Services/Ai/Domain/AtlasResearchOrchestrator.php
  - app/Console/Commands/AtlasAiResearchDomainCommand.php
  - app/Services/Ai/AtlasDomainProfileRegistry.php
  - app/Services/Ai/Cognition/AtlasCognitionScoreCardService.php
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
  - docs/engineering-knowledge-base/research-self-improvement/research-operating-system.md
  - docs/engineering-knowledge-base/research-self-improvement/
  - docs/engineering-knowledge-base/domains/finance.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-domain-research-runtime
human_name: Atlas Research Domain Runtime
canonical_name: Atlas Research Domain Runtime
technical_name: ResearchRuntimeService
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-domain-research-runtime.md
graph_title: Atlas Research Domain Runtime
graph_world: atlas
graph_layer: system
graph_kind: module
graph_status: building
graph_source: repo
owner: domains
repo_paths:
  - docs/engineering-knowledge-base/atlas-domain-research-runtime.md
  - app/Services/Ai/ResearchDomain
  - app/Console/Commands/AtlasAiResearchDomainCommand.php
  - tests/Feature/Ai/ResearchDomain
allowed_changes:
  - Atualizar runtime, thresholds, gates e evidências quando ResearchDomain mudar.
  - Adicionar novos flows apenas via AtlasDomainProfileRegistry e AtlasResearchOrchestrator.
flows_to:
  - research.quick
  - research.super
  - holding.enterprise-analysis
unlocks:
  - source-grounded-research-domain
  - review-only-research-claims
  - research-domain-control-plane-projection
governs:
  - research-domain
  - research-claim-publication
evidence:
  - app/Services/Ai/ResearchDomain/ResearchDomainCanon.php
  - app/Services/Ai/ResearchDomain/ResearchRuntimeService.php
  - app/Console/Commands/AtlasAiResearchDomainCommand.php
  - tests/Feature/Ai/ResearchDomain
evidence_refs:
  - symbol: ResearchRuntimeService
  - command: atlas:ai:research-domain
required_tests:
  - "php artisan test tests/Feature/Ai/ResearchDomain"
  - "php artisan atlas:ai:research-domain --action=readiness --json"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
next_actions:
  - Rodar bateria completa ResearchDomain depois de mudanças em gates ou storage.
  - Registrar qualquer novo flow no registry canônico, sem criar registry paralelo.
  - Manter Research review-only e source-grounded antes de claims externos.
schema: atlas.research_domain.runtime.v1
---

# Atlas Research Domain Runtime

## Resumo

Doc-mãe do runtime PHP do ResearchDomain. Documenta o que **já existe** desde
2026-05-18, declara explicitamente o que **NÃO** é duplicado, e registra a
entrada no ACOS Scorecard. Atualização 2026-07-05: o gate standalone de compliance
(`ResearchDomainComplianceGate`) citado em versões antigas não existe no código
atual — foi deletado no commit 26333fec23 e NUNCA teve wiring em
app/config/routes/bootstrap (o único consumidor era o próprio teste unitário,
deletado junto); o enforcement de execução descrito em versões antigas deste doc
nunca foi real. Não o recrie sem AP/owner-flow explícito.

## Papel no Atlas

Research é o domínio review-only para análise source-grounded. Ele produz
evidência, claims e síntese, mas não executa ações externas. O papel deste doc
é apontar o runtime PHP atual e impedir que uma IA recrie services, flows,
registry ou storage paralelos por não saber onde a autoridade vive.

Este runtime **não é o Research OS universal inteiro**. Ele é o adapter/executor
atual do domínio `research` dentro do Atlas Research OS. A autoridade sobre
pipeline universal, evidencia, claims, citation health, contradiction,
synthesis, promotion e destinos de conhecimento vive em
`research-self-improvement/research-operating-system.md`.

## Onde Se Encaixa

Este documento NÃO duplica conteúdo de `research-self-improvement/` ou de
`atlas-ai-research-self-improvement-runtime.md`. Aqueles canon docs são a
**rubrica autoral** da função Research no Atlas. Este doc-mãe é a
**fotografia de runtime PHP**: que arquivo existe, onde está, qual contrato
expõe.

Regra de arquitetura: qualquer nova pesquisa especifica deve preferir
`Research OS Core + Domain Research Profile` antes de criar outro serviço de
pesquisa. Exemplos:

| Necessidade | Caminho correto |
| --- | --- |
| Pesquisa de empresas | Research OS + company/business adapter |
| Pesquisa financeira | Research OS + finance adapter |
| Pesquisa cientifica | Research OS + science adapter |
| Pesquisa de musica/gaita | Research OS + learning/music adapter |
| Pesquisa de ingles/frances | Research OS + language-learning adapter |
| Pesquisa de tecnologia | Research OS + technology adapter |

Se o mecanismo parece generico (source quality, claim extraction, contradiction,
synthesis, citation health, promotion), ele pertence ao Research OS Core. Se o
criterio e especifico do assunto, ele pertence ao adapter.

Research depende da governança de conhecimento, Constitutional Kernel,
Autonomy Admission e do registry de domínios. Finance pode inspirar padrões de
compliance, mas não vira owner de regras Research nem prova que há gate
standalone neste domínio.

## Contratos

### Services existentes

Os 10 services abaixo foram criados em 2026-05-18 e são canon. Qualquer
novo trabalho COMPÕE sobre eles, NÃO os substitui:

| Arquivo | Função canônica |
| --- | --- |
| `ResearchDomainCanon.php` | Enums (RUN_STATUSES, SOURCE_TYPES, CLAIM_STATUSES, CONTRADICTION_STATUSES, MIN_SOURCE_DIVERSITY=2, MIN_ACCEPTED_SOURCES=2, MIN_CLAIMS=1). **Fonte única**. |
| `ResearchRuntimeService.php` | Orquestra ciclo de run (plan → collect → synthesize → certify). |
| `ResearchSourcePlanService.php` | Planeja fontes propostas para um run. |
| `ResearchSourceQualityService.php` | Score determinístico de qualidade de fonte; promove a accepted/rejected. |
| `ResearchClaimService.php` | Registra claim COM source_refs obrigatório; roda contradiction check. |
| `ResearchSynthesisService.php` | Síntese final do run e certificação interna. |
| `ResearchEvidenceBridge.php` | Bridge para Mission Evidence Ledger (tolerante a tabela ausente). |
| `ResearchReadinessService.php` | Health check (tables, models, services, canon enums). |
| `ResearchControlPlaneProjection.php` | Read-model para superfícies (CLI, futura UI). |
| `ResearchDomainManifestSeeder.php` | Seed idempotente do manifest no registry. |

### Papel como adapter do Research OS

| Camada do Research OS | Implementacao atual neste runtime | Observacao |
| --- | --- | --- |
| Source Plan | `ResearchSourcePlanService` | Local/deterministico; nao faz fetch externo. |
| Source Quality | `ResearchSourceQualityService` | Usa factors fornecidos por operador/teste/runtime futuro. |
| Claim Store | `ResearchClaimService` + `ai_research_claims` | `source_refs` obrigatorio. |
| Contradiction Check | `ResearchClaimService::runContradictionCheck` | Heuristica deterministica v1. |
| Synthesis | `ResearchSynthesisService` | Certifica contra thresholds do domain canon. |
| Evidence Pack | `ResearchEvidenceBridge` | Projeta para mission evidence quando disponivel. |
| Control Plane | `ResearchControlPlaneProjection` | Read model do adapter. |

O que ainda deve subir para o Research OS Core quando generalizado:

- contrato de `Domain Research Profile`;
- promotion gate para Memory/Vault/Semantic Notes/Docs/Constelacao;
- connector/fetch runtime;
- citation health reutilizavel;
- eval harness comum;
- cross-domain semantic recall.

### Profile + Registry

`AtlasDomainProfileRegistry` já registra o domínio `research` com:

- `orchestrator` = `AtlasResearchOrchestrator` (em `app/Services/Ai/Domain/`)
- `default_flow` = `research.quick`
- flows canônicos = `research.quick`, `research.super`

Não crie `ResearchDomainFlowRegistry` paralelo — a fonte de flows é o
Registry + `AtlasResearchOrchestrator::supportedFlows()`.

### CLI

`atlas:ai:research-domain` já existe em `AtlasAiResearchDomainCommand.php`
com as actions: `readiness`, `seed-manifest`, `smoke`, `control-plane`,
`enterprise-analysis`. Suporta `--json`. Não criar comando paralelo.

### Storage

| Conteúdo | Onde vive | Por quê |
| --- | --- | --- |
| Runs, sources, claims, synthesis | DB tables (`ai_research_*`) | Decisão de 2026-05-18, suporta queries relacionais. |
| Mission evidence refs | `ai_mission_evidence_refs` (tolerante) | Bridge existente em `ResearchEvidenceBridge`. |

## Fluxo

```text
research.quick|research.super
-> Research OS Core contract
-> Research Domain Adapter
-> ResearchRuntimeService
-> source plan
-> source quality
-> claim registration
-> contradiction check
-> synthesis/certification
-> evidence bridge/control-plane projection
-> certificação source-grounded contra thresholds do canon em ResearchSynthesisService
```

O fluxo é review-only por política declarada (registry:
`autonomy_ceiling=source_grounded_review`). O único enforcement comprovável em
código é a certificação de thresholds em `ResearchSynthesisService`; não há
gate de execução standalone ativo.

## Regras para IA

- Não criar registry paralelo para flows Research.
- Não criar outro sistema de pesquisa paralelo ao Research OS.
- Não duplicar enums de `ResearchDomainCanon`.
- Não mover regra generica de pesquisa para adapter especifico se ela pertence ao core.
- Não migrar runs/sources/claims/synthesis de DB para JSONL.
- Não tratar `requires_evidence` como falha pétrea; é retomada com mais fonte.
- Não declarar benchmark, Rivals ou superioridade por ResearchDomain.
- Não permitir ação externa: Research analisa, não executa.
- Não promover synthesis para Memory/Vault/Constelacao sem promotion gate.

## Escopo de Implementacao

### Enforcement atual

Não existe `ResearchDomainComplianceGate` standalone nem gate de execução. O
que existe, comprovável em código:

- `ResearchDomainCanon` concentra enums, thresholds e constantes canônicas.
- `ResearchSourceQualityService` pontua fontes (accepted/rejected) e grava a
  métrica `source_diversity` do run; ele NÃO aplica o threshold de diversidade.
- `ResearchSynthesisService` é onde a certificação acontece: aplica
  `MIN_ACCEPTED_SOURCES`, `MIN_SOURCE_DIVERSITY`
  (`ResearchSynthesisService.php:105`) e `MIN_CLAIMS`, e marca como missing
  claims sem `source_refs` antes de certificar.
- `ResearchReadinessService` é health-check (tables, models, services, enums);
  não bloqueia nada.
- `AtlasDomainProfileRegistry::researchDomainGatePolicy()` declara
  `autonomy_ceiling=source_grounded_review` — política declarativa do registry,
  não gate de runtime.

Políticas declaradas (decisão de doc/registry, sem enforcement dedicado em código):

- Research opera em modo analysis/review-only. Nenhum código do ResearchDomain
  declara ou verifica `output_mode` — essa constante existe apenas no domínio
  Finance; para Research é política declarada, não invariante enforced.
- Research não executa ações externas.
- Claims precisam ser source-grounded e atribuíveis a fontes aceitas (isto sim
  é verificado na certificação da synthesis).
- Claims comparativos externos e de medição competitiva não são autorizados
  por este runtime; ver claim policy do ACOS.

Se um gate standalone voltar a ser necessário, ele deve nascer por AP/owner-flow
explícito, com wiring real, teste vivo e atualização deste doc no mesmo commit.

### ACOS Scorecard registration

ARDR está registrado em `AtlasCognitionScoreCardService::SUBSYSTEMS`
(`app/Services/Ai/Cognition/AtlasCognitionScoreCardService.php:245`) como tupla
de **4 elementos**:

```
['ARDR',  'Research Domain Runtime',               'research_domain', ResearchRuntimeService::class],
```

- Hoje (2026-07-05) ARDR é a entrada **70 de 73** em `SUBSYSTEMS`. Fato
  histórico: versões anteriores deste doc (2026-05-26) registravam a entrada
  como linha 52, com tupla de 7 elementos carregando literais de status; esses
  literais foram deletados do scorecard.
- `code_status` = derivado em runtime por `probeCodeStatus()`.
- `doc_status` e `pipeline_status` NUNCA são lidos da tupla: são resolvidos em
  runtime por `AtlasCognitionEvidenceResolver`
  (`resolveDocStatus()`/`resolvePipelineStatus()` chamados em `build()`).
- Testes: **10** arquivos de feature test em `tests/Feature/Ai/ResearchDomain`
  (versões antigas diziam "11+").

### Holding integration

O CLI `atlas:ai:research-domain --action=enterprise-analysis` dispatch via
`AutonomousHoldingEnterpriseBuildoutService` + `EnterpriseFlowFixtureActionRuntimeService`.
Esse hook permite a Holding consultar Research em supervised_execution
observada, sem que Research execute nada além de análise.

## Dependencias

- `atlas-ai-research-self-improvement-runtime.md`
- `docs/engineering-knowledge-base/research-self-improvement/`
- `atlas-ai-knowledge-governance-system.md`
- Constitutional Kernel
- Autonomy Admission
- `AtlasDomainProfileRegistry`
- `AtlasResearchOrchestrator`

## Evidencias

Comandos de validação:

```bash
php artisan test tests/Feature/Ai/ResearchDomain
php artisan atlas:ai:research-domain --action=readiness --json
php artisan atlas:engineering:knowledge docs-health --json
```

O doc só prova que o runtime ResearchDomain existe e está governado. Ele não
prova benchmark externo, Rivals real, Research OS universal final, ou que todo
fluxo Research está final.

## Riscos

| Risco | Mitigação |
| --- | --- |
| IA recriar `ResearchDomainFlowRegistry` | Registry canônico declarado neste doc. |
| IA criar mini-Research por dominio | Research OS universal + adapters, conforme `research-operating-system.md`. |
| Research virar executor | Política declarada (registry `autonomy_ceiling=source_grounded_review`) + certificação de thresholds em `ResearchSynthesisService`. Não existe gate de execução em código desde a deleção do gate standalone (que nunca teve wiring) — vigiar em review. |
| Claims sem fonte | Thresholds e `source_grounded=true` exigidos. |
| Storage paralelo | DB é a fonte de runs/sources/claims/synthesis; evidence bridge é tolerante e não substitui o store canônico. |
| Scorecard virar claim externo | Claims comparativos externos e de medição competitiva não são autorizados; ver claim policy do ACOS. |
| Pesquisa certificada nao virar conhecimento reutilizavel | Futuro promotion gate deve levar synthesis para Memory/Vault/Semantic Notes/Open Brain/Constelacao. |

## Exemplos

### Comando readiness

```bash
php artisan atlas:ai:research-domain --action=readiness --json
```

## Proximas Acoes

- Self-improvement runtime: `atlas-ai-research-self-improvement-runtime.md`
- 18 docs de função Research: `docs/engineering-knowledge-base/research-self-improvement/` (17 na criação 2026-05-10; `agent-loop-patterns-catalog.md` adicionado 2026-06-21)
- Finance pattern espelhado: `domains/finance.md`
- Multi-domain sequence: `atlas-ai-multi-domain-implementation-sequence.md`
- Knowledge governance: `atlas-ai-knowledge-governance-system.md`
