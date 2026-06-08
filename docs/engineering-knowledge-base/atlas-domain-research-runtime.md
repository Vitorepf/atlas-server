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
summary: Doc-mãe do runtime PHP do ResearchDomain. Aponta para os 10 services existentes + 17 docs de research-self-improvement + Profile no Registry + CLI canon + ComplianceGate novo + entry no ACOS Scorecard. Declara não-duplicação explícita.
tags:
  - atlas-ai
  - domains
  - research
  - source-grounded
  - review-only
  - compliance-gate
capabilities:
  - research_domain_runtime
  - source_grounded_compliance
  - claim_attribution
  - research_claim_contradiction_review
  - evidence_pack_bridge
decisions:
  - Research é domínio review-only (analysis_review_only); nunca executa.
  - Toda publicação de claim passa por ResearchDomainComplianceGate.
  - Storage de runs/sources/claims/synthesis vive em DB (decisão 2026-05-18).
  - Gate decisions vivem em JSONL append-only (storage/atlas/research_domain/gates.jsonl).
  - Flows canônicos: research.quick e research.super (registry).
  - Research é o subsystem 52 do ACOS (ARDR — Research Domain Runtime).
maintenance:
  - Quando flows, gates, thresholds, ou runtime mudar, atualize este documento.
  - Não duplique enums já em ResearchDomainCanon — sempre referencie a fonte.
  - Não migre DB → JSONL para runs/sources/claims/synthesis — a decisão é canon.
related_paths:
  - app/Services/Ai/ResearchDomain/ResearchDomainCanon.php
  - app/Services/Ai/ResearchDomain/ResearchDomainComplianceGate.php
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
  - tests/Unit/Ai/ResearchDomain/ResearchDomainComplianceGateTest.php
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
  - research-compliance-gate
  - research-claim-publication
evidence:
  - app/Services/Ai/ResearchDomain/ResearchDomainCanon.php
  - app/Services/Ai/ResearchDomain/ResearchDomainComplianceGate.php
  - app/Services/Ai/ResearchDomain/ResearchRuntimeService.php
  - app/Console/Commands/AtlasAiResearchDomainCommand.php
  - tests/Feature/Ai/ResearchDomain
  - tests/Unit/Ai/ResearchDomain/ResearchDomainComplianceGateTest.php
evidence_refs:
  - symbol: ResearchRuntimeService
  - command: atlas:ai:research-domain
  - test: ResearchDomainComplianceGateTest
required_tests:
  - "php artisan test tests/Feature/Ai/ResearchDomain tests/Unit/Ai/ResearchDomain/ResearchDomainComplianceGateTest.php"
  - "php artisan atlas:ai:research-domain --action=readiness --json"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
next_actions:
  - Rodar bateria completa ResearchDomain depois de mudanças em gates ou storage.
  - Registrar qualquer novo flow no registry canônico, sem criar registry paralelo.
  - Manter Research review-only e source-grounded antes de claims externos.
schema: atlas.research_domain.compliance_gate.v1
---

# Atlas Research Domain Runtime

## Resumo

Doc-mãe do runtime PHP do ResearchDomain. Documenta o que **já existe** desde
2026-05-18, declara explicitamente o que **NÃO** é duplicado, e registra a
**adição limpa** de 2026-05-26 (`ResearchDomainComplianceGate` + entry no
ACOS Scorecard).

## Papel no Atlas

Research é o domínio review-only para análise source-grounded. Ele produz
evidência, claims e síntese, mas não executa ações externas. O papel deste doc
é apontar o runtime PHP atual e impedir que uma IA recrie services, flows,
registry ou storage paralelos por não saber onde a autoridade vive.

## Onde Se Encaixa

Este documento NÃO duplica conteúdo de `research-self-improvement/` ou de
`atlas-ai-research-self-improvement-runtime.md`. Aqueles canon docs são a
**rubrica autoral** da função Research no Atlas. Este doc-mãe é a
**fotografia de runtime PHP**: que arquivo existe, onde está, qual contrato
expõe.

Research depende da governança de conhecimento, Constitutional Kernel,
Autonomy Admission e do registry de domínios. Finance é referência estrutural
para compliance gate, mas não vira owner de regras Research.

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
| Gate decisions | `storage/atlas/research_domain/gates.jsonl` | Append-only ledger novo (2026-05-26), pétreo evidence pattern. |
| Mission evidence refs | `ai_mission_evidence_refs` (tolerante) | Bridge existente em `ResearchEvidenceBridge`. |

## Fluxo

```text
research.quick|research.super
-> ResearchRuntimeService
-> source plan
-> source quality
-> claim registration
-> contradiction check
-> synthesis/certification
-> evidence bridge/control-plane projection
-> ResearchDomainComplianceGate before claim publication
```

O fluxo é review-only. Qualquer tentativa de transformar Research em execução
autônoma deve bloquear no compliance gate.

## Regras para IA

- Não criar registry paralelo para flows Research.
- Não duplicar enums de `ResearchDomainCanon`.
- Não migrar runs/sources/claims/synthesis de DB para JSONL.
- Não tratar `requires_evidence` como falha pétrea; é retomada com mais fonte.
- Não declarar benchmark, Rivals ou superioridade por ResearchDomain.
- Não permitir ação externa: Research analisa, não executa.

## Escopo de Implementacao

### ResearchDomainComplianceGate

Espelho **estrutural** (não conteúdo igual) de `AtlasFinanceComplianceGate`.

### Contrato

```php
$gate = app(ResearchDomainComplianceGate::class);
$envelope = $gate->evaluate($plan, $requestedExecutionActions);
// $envelope['decision'] ∈ {allow, block, requires_evidence}
```

### Invariantes enforced

- `output_mode` deve ser `analysis_review_only`.
- `execution_intent_allowed` deve ser `false` (review-only sempre).
- `source_grounded` deve ser `true`.
- `accepted_sources_count ≥ ResearchDomainCanon::MIN_ACCEPTED_SOURCES`.
- `source_diversity ≥ ResearchDomainCanon::MIN_SOURCE_DIVERSITY`.
- `claims_with_source_refs ≥ ResearchDomainCanon::MIN_CLAIMS`.
- Constitutional Kernel atravessado a cada evaluate (pétreos).
- Autonomy Admission consultado com `requested_autonomy=suggest`.
- Qualquer `requested_execution_actions != []` → **hard block**.

### Decision composition

| Condição | Decisão |
| --- | --- |
| Kernel decide `block` | `block` |
| `$requestedExecutionActions != []` | `block` |
| Sem reasons | `allow` |
| Reasons só de threshold/source-grounding | `requires_evidence` |

`requires_evidence` é o caminho de retomada: operador fornece mais fonte
ou evidência e re-roda. Não é falha pétreo.

### Receipt JSONL

`storage/atlas/research_domain/gates.jsonl` — append-only, schema
`atlas.research_domain.compliance_gate.v1`, hash determinístico sobre
campos canônicos (recorded_at fora do hash).

### ACOS Scorecard registration

Research entrou em `AtlasCognitionScoreCardService::SUBSYSTEMS` como linha
**52**:

```
['ARDR', 'Research Domain Runtime', 'research_domain', ResearchRuntimeService::class, 'ready', 'ready', 'building']
```

- `code_status` = derivado por `probeCodeStatus()` (ready — classe existe).
- `doc_status` = `ready` (este doc + 17 docs research-self-improvement +
  doc-mãe self-improvement-runtime).
- `pipeline_status` = `ready` (11+ feature tests verdes + Profile registry +
  Holding enterprise hook + ResearchReadinessService probe — pipeline real
  prova-se hoje; evidence volume orgânico cresce com uso do operador,
  consistent com a definição v3 de "ready").

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
php artisan test tests/Feature/Ai/ResearchDomain tests/Unit/Ai/ResearchDomain/ResearchDomainComplianceGateTest.php
php artisan atlas:ai:research-domain --action=readiness --json
php artisan atlas:engineering:knowledge docs-health --json
```

O doc só prova que o runtime ResearchDomain existe e está governado. Ele não
prova benchmark externo, Rivals real ou que todo fluxo Research está final.

## Riscos

| Risco | Mitigação |
| --- | --- |
| IA recriar `ResearchDomainFlowRegistry` | Registry canônico declarado neste doc. |
| Research virar executor | Compliance gate bloqueia execution intent. |
| Claims sem fonte | Thresholds e `source_grounded=true` exigidos. |
| Storage paralelo | DB e JSONL têm papéis separados no contrato. |
| Scorecard virar claim externo | claim_policy bloqueia benchmark/Rivals/superioridade. |

## Exemplos

### claim_policy

Hardcoded em `ResearchDomainComplianceGate::claimPolicy()`:

```
benchmark_claim_allowed                = false
rivals_claim_allowed                   = false
superiority_claim_allowed              = false
external_rivals_certification_touched  = false
concurrent_claim_allowed               = false
cognitive_immune_law_enforced          = true
provider_safe_only_enforced            = true
review_only_enforced                   = true
```

### Comando readiness

```bash
php artisan atlas:ai:research-domain --action=readiness --json
```

## Proximas Acoes

- Self-improvement runtime: `atlas-ai-research-self-improvement-runtime.md`
- 17 docs de função Research: `docs/engineering-knowledge-base/research-self-improvement/`
- Finance pattern espelhado: `domains/finance.md`
- Multi-domain sequence: `atlas-ai-multi-domain-implementation-sequence.md`
- Knowledge governance: `atlas-ai-knowledge-governance-system.md`
