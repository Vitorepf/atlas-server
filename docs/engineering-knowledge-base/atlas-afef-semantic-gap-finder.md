---
id: atlas-afef-semantic-gap-finder
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas AFEF Semantic Gap-Finder + Frontier Generator Wiring (FASE 4 / Pilar 2)
slug: atlas-afef-semantic-gap-finder
status: future
implementation_state: future_spec_no_runtime_yet
category: agentic-engineering
priority: 94
summary: >
  Especificacao precisa do gap-finder SEMANTICO do AFEF: compara a CAPACIDADE
  DOCUMENTADA (claims canonicos `capabilities:` / `implementation_state:` dos
  docs do repo) contra a REALIDADE RUNTIME (dossie de evidencia real do
  Harvester AP-A: ciclos, ledger, receipts, plan-completion) e emite gaps
  CONCRETOS ancorados em evidencia (capability_gap.v1) em vez do doc-miner
  boilerplate que so sabia dizer "mantenha o doc em sincronia". Cada gap traz a
  evidencia que prova a divergencia (anchor_id verificavel + drift_kind) e e
  consumivel diretamente pelo gerador da fronteira (Opus 4.8 propoe -> armadura
  I1..I9 -> backlog estruturado proposal-only -> FASE 1 decompoe no proximo
  ciclo). Proposal-only; nao gera codigo, nao escreve canon, nao chama provider
  fora do gerador gated. Sequenciado DEPOIS de AP-A (Harvester) e AP-C
  (orquestrador da fronteira + armadura) ja entregues.
tags: [atlas-ai, software-company, frontier-evolution-foundry, semantic-gap-finder, capability-drift, proposal-only]
capabilities: [semantic_capability_gap_detection, documented_vs_runtime_drift_mapping, afef_frontier_generator_wiring, evidence_anchored_gap_emission]
decisions:
  - O gap-finder e SEMANTICO, nao lexical - compara claim de capacidade documentada vs evidencia runtime, nunca emite "mantenha o doc em sincronia" como gap.
  - Todo gap DEVE carregar pelo menos um anchor_id que existe no dossie AP-A e que o FoundryEvidenceVerifierService confirma - gap sem ancora verificavel e dropado na origem (herdando I1 Evidence-Bound).
  - O gap-finder e READ-ONLY - consome dossie AP-A + claims canonicos (injetados), emite capability_gap.v1, nao escreve canon/codigo, nao chama provider.
  - O gap NAO e proposta - ele ALIMENTA o dossie que o FrontierGeneratorPort (Opus 4.8 real-or-blocked) consome; a geracao continua double-gated (gate AP-B + flag persistente) e proposal-only.
  - drift_kind e um conjunto FECHADO de divergencias semanticas reais; um doc cujo claim casa com o runtime NAO produz gap (zero ruido).
maintenance:
  - Atualizar antes de mudar o conjunto drift_kind, o schema capability_gap.v1, ou o contrato de wiring gap-finder -> gerador -> FASE 1.
  - Bloquear quando IA tentar emitir gap sem ancora verificavel, emitir "keep doc in sync"/"update documentation" como gap, ou fazer o gap-finder escrever canon/chamar provider.
risk_level: high
owner: agentic_engineering_os/dev_forge
graph_id: atlas-afef-semantic-gap-finder
graph_title: Atlas AFEF Semantic Gap-Finder
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-frontier-evolution-foundry
graph_status: future
graph_source: repo
depends_on: [atlas-frontier-evolution-foundry, atlas-afef-build-plan, atlas-self-directed-evolution-layer]
flows_to: [atlas-frontier-evolution-foundry]
unlocks: [evidence_anchored_evolution_backlog, semantic_drift_driven_self_improvement]
governs: [afef_gap_source_contract, capability_gap_acceptance]
authority_class: planner
related_paths:
  - app/Services/Ai/Foundry/FoundryEvidenceHarvesterService.php
  - app/Services/Ai/Foundry/FoundryEvidenceVerifierService.php
  - app/Services/Ai/Foundry/Frontier/FrontierGenerationOrchestratorService.php
  - app/Services/Ai/Foundry/Frontier/Ports/FrontierGeneratorPort.php
  - app/Services/Ai/Foundry/Frontier/Ports/AtlasDecideFrontierGeneratorService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/PlanExecution/BuildPlanDecomposerService.php
repo_paths:
  - docs/engineering-knowledge-base/atlas-afef-semantic-gap-finder.md
evidence:
  - docs/engineering-knowledge-base/atlas-frontier-evolution-foundry.md
  - docs/engineering-knowledge-base/atlas-afef-build-plan.md
  - app/Services/Ai/Foundry/FoundryEvidenceHarvesterService.php
required_tests:
  - "php artisan test --filter Foundry"
next_actions:
  - Implementar FoundrySemanticGapFinderService (read-only) e o harness deterministico que prova emissao de gaps reais a partir de fixture.
allowed_changes:
  - Refinar drift_kind, schema capability_gap.v1, wiring gap-finder -> gerador -> FASE 1 e harness deterministico.
forbidden_changes:
  - Emitir gap sem anchor confirmado.
  - Emitir "keep doc in sync" ou "update documentation" como gap.
  - Fazer o gap-finder escrever canon, escrever codigo ou chamar provider.
requires_evidence: false
---

# Atlas AFEF Semantic Gap-Finder + Frontier Generator Wiring (FASE 4 / Pilar 2)

## Resumo

O AFEF, antes desta fatia, depende de uma fonte de "gap" boilerplate: um
doc-miner lexical que percorre os docs canonicos e, na pratica, so sabe emitir
o pseudo-gap **"keep doc in sync" / "update documentation"**. Isso e inerte:

- Nao multiplica (filtro #1): repetir "sincronize o doc" nao destrava capacidade.
- Nao e ancorado em evidencia: nao prova que algo REALMENTE diverge do runtime.
- Gera ruido: todo doc vira candidato, esgotando budget de geracao com nada.

A fronteira (Opus 4.8) precisa de um **sinal semantico real** para propor algo
que multiplica: a divergencia entre a **capacidade que o sistema DIZ que tem**
(claim canonico) e a **realidade que o runtime PROVA** (dossie AP-A).

## Papel no Atlas

Este doc define a fonte semantica de gaps do AFEF. Ele substitui gap lexical por
gap ancorado em evidencia, mas permanece proposal-only: nao escreve canon, nao
gera codigo e nao chama provider fora do gerador gated.

## Onde Se Encaixa

Ele fica depois do Harvester AP-A e antes do Frontier Generator AP-C: recebe o
dossie verificavel, emite `capability_gap.v1` e injeta esses gaps no dossie que
o gerador ja consome.

## Contratos

`FoundrySemanticGapFinderService` (novo, `app/Services/Ai/Foundry/`,
`declare(strict_types=1)`, `final`) e um servico **READ-ONLY** que recebe:

- `dossier`: o dossie real do Harvester AP-A (`atlas.foundry.dossier.v1`), com
  `anchors[]` ja verificaveis pelo `FoundryEvidenceVerifierService`.
- `capability_claims`: lista de claims de capacidade documentada extraida dos
  docs canonicos (campos `capabilities:`, `implementation_state:`, `status:`,
  `required_tests:` do front-matter `atlas_canonical_module_doc.v1`). Injetada
  por seam (`array_key_exists`) exatamente como o Harvester injeta `cycles` /
  `ledger_events`, de modo que o teste forneca tudo e toque ZERO I/O.

Emite uma lista de `capability_gap.v1` — divergencias CONCRETAS, cada uma
ancorada em evidencia.

### 2.1 Schema `atlas.foundry.capability_gap.v1` (novo em `FoundrySchemas`)

Chaves obrigatorias (validacao key-presence, mesmo contrato de `validateShape`):

```
gap_id                  string  sha256-derivado de {capability, drift_kind, anchor_id}
capability              string  o claim de capacidade documentada (ex.: "merge_provider_proof")
documented_state        string  o que o doc afirma (implementation_state/status/claim)
runtime_state           string  o que a evidencia runtime prova (derivado do dossie)
drift_kind              string  membro do conjunto fechado DRIFT_KINDS (secao 2.2)
anchor_id               string  anchor_id existente em dossier.anchors[] que PROVA o drift
anchor_verdict          string  'confirmed' (obrigatorio) - verdict do FoundryEvidenceVerifierService
evidence_excerpt        string  trecho minimo do anchor_claim/integrity que evidencia
source_doc              string  slug do doc canonico de origem do claim
severity                string  low|medium|high (derivado de drift_kind, deterministico)
gap_hash                string  'sha256:'+canonical hash do gap (identidade estavel)
```

### 2.2 Conjunto FECHADO `DRIFT_KINDS` (zero ruido, zero "keep doc in sync")

Um gap so existe se cair em UM destes drifts semanticos reais. Qualquer outra
coisa (doc casa com runtime) NAO produz gap:

| drift_kind                       | semantica                                                                                          | severity |
|----------------------------------|----------------------------------------------------------------------------------------------------|----------|
| `claimed_available_runtime_blocked` | doc diz `implementation_state: available`/`status: building` mas o dossie prova BLOCKER recorrente (ledger_event blocker confirmado) na capacidade | high     |
| `claimed_capability_no_runtime_evidence` | doc lista `capabilities: [X]` mas NAO existe NENHUM anchor confirmado que exercite X (capacidade declarada nunca rodou) | high     |
| `runtime_proven_capability_undocumented` | dossie prova um ciclo/merge confirmado para uma capacidade que NAO aparece em `capabilities:` de nenhum doc (runtime adiante do doc) | medium   |
| `plan_incomplete_doc_claims_done` | doc `implementation_state: available` mas `plan_completion` do dossie prova slices entregues < total (entrega parcial mascarada) | high     |
| `required_test_unproven`         | doc declara `required_tests:` mas nenhum anchor confirmado liga a execucao verde desse teste        | medium   |

**Proibido**: emitir gap cujo `drift_kind` nao esteja nesta tabela; emitir gap
de "documentacao desatualizada" generico; emitir gap sem `anchor_id` que exista
em `dossier.anchors[]` e cujo `FoundryEvidenceVerifierService::verifyAnchor`
retorne `verdict=confirmed`. Gap que falhe qualquer um e DROPADO na origem com
`drop_reason` machine-readable (herdando o contrato I1 Evidence-Bound).

### 2.3 Contrato de saida `project(array $input): array`

```
schema_version  atlas.foundry.capability_gap_report.v1
status          ready|partial|blocked
area_id         string
gaps            list<capability_gap.v1>     (apenas gaps com ancora confirmada)
drops           list<{capability,drift_kind,drop_reason,detail}>  (gaps rejeitados)
gap_count       int
claim_policy    {read_only:true, generates_code:false, provider_invoked:false, canonical_doc_write_allowed:false}
report_hash     sha256
```

`status`:
- `blocked` quando o dossie nao tem `status=ready`/anchors (sem realidade para comparar).
- `partial` quando ha gaps mas tambem drops (algum claim sem ancora).
- `ready` quando todo gap emitido tem ancora confirmada.

### 2.4 Determinismo e armadura herdada

- Determinismo total: mesma `(dossier, capability_claims)` -> mesmo `report_hash`
  (ordenacao estavel de `gaps` por `gap_id`, sem timestamp na identidade).
- Reusa (nunca duplica) `FoundryEvidenceVerifierService::verifyAnchor` para o
  veredito da ancora — mesma fonte de verdade do gate I1.
- READ-ONLY: nenhuma escrita de canon/codigo, nenhum `provider`, nenhum
  `ledger->record`. Espelha o `claim_policy` do Harvester AP-A.

## Fluxo

O gap-finder NAO gera proposta. Ele produz o sinal que enriquece o dossie que o
gerador consome. Pipeline (cada seta e um servico ja existente, exceto o
gap-finder novo):

```
[AP-A Harvester]  dossier (anchors verificaveis)
       |
       v
[FoundrySemanticGapFinderService]  capability_gap_report.v1   (NOVO, read-only)
       |
       |  enriquece o dossie: dossier['capability_gaps'] = report['gaps']
       v
[FrontierGenerationOrchestratorService::run]   (AP-C, double-gated)
       |   GUARD 1: FoundryExhaustionRarityGateService (config-backed, sem override)
       |   GUARD 2: frontier_mode flag persistente
       v
[FrontierGeneratorPort::generate(dossier, count)]
       |   REAL: AtlasDecideFrontierGeneratorService -> Opus 4.8 via AtlasDecide + ProviderDriver
       |   real-or-blocked: sem bridge real => blocked('premium_provider_real_execution_bridge_missing')
       |   o gerador recebe dossier.capability_gaps como SEED de proposta
       v
[armadura I1->I6->I3->I7->I9->I2]  first-match-wins, cada drop machine-readable
       |
       v
[FrontierProposalToGapCandidateAdapter]  survivor -> gap_candidate
       |
       v
[SelfDirectedEvolutionCurationInboxService::project]  backlog estruturado, pending_operator_review
       |
       v  (proximo ciclo, com decisao do operador)
[FASE 1: BuildPlanDecomposerService]  candidate -> slices bounded -> PlanExecutionOrchestrator
```

## Regras para IA

- Nao implementar provider, writer canonico ou runtime paralelo dentro do gap-finder.
- Nao emitir gap sem `anchor_id` confirmado pelo verifier.
- Nao reintroduzir "keep doc in sync" / "update documentation" como gap.
- Reusar Harvester, Evidence Verifier, Frontier Generator e BuildPlanDecomposer existentes.

## Escopo de Implementacao

### 3.1 Pontos de wiring concretos (cirurgicos, aditivos)

1. **Seed no gerador**: `AtlasDecideFrontierGeneratorService::generate` ja
   recebe `$dossier` como unica fonte. O gap-finder grava `capability_gaps` no
   dossie ANTES de chamar `run()`. Nenhuma mudanca de assinatura: o gerador ja
   encaminha o dossie inteiro como `prompt.payload.harvester_dossier`. Os gaps
   viajam dentro do dossie — o prompt do Opus 4.8 instrui "proponha evolucao que
   FECHE estes capability_gaps, citando o anchor_id de cada gap".
2. **I1 reforcado de graca**: como cada gap ja carrega `anchor_id` confirmado, a
   proposta que cita esse anchor passa I1 (Evidence-Bound) por construcao; uma
   proposta que invente ancora fora do dossie e dropada como hoje.
3. **FASE 1 (decompose)**: o `gap_candidate` admitido no inbox e, apos decisao do
   operador, entregue ao `BuildPlanDecomposerService` (FASE 1) que ja sabe
   quebrar uma proposta em slices bounded. Nenhum novo decomposer — reuso.

### 3.2 Invariantes preservadas (nenhum gate enfraquecido)

- Proposal-only: gap-finder + gerador nunca escrevem canon/codigo, nunca mergeiam.
- Double-gate da geracao intocado (config-backed gate AP-B + flag persistente).
- Real-or-blocked: sem provider real, gerador BLOQUEIA honesto; gap-finder roda
  e produz o relatorio mesmo assim (read-only), so nao ha proposta.
- Honest-stop: sem gaps reais (doc casa com runtime), `gap_count=0` e a geracao
  nao tem seed — esgota honesto, nao fabrica.

## Dependencias

- AP-A Harvester e `FoundryEvidenceVerifierService`.
- FrontierGenerationOrchestrator + `FrontierGeneratorPort`.
- AtlasDecideFrontierGeneratorService real-or-blocked.
- BuildPlanDecomposerService para FASE 1.
- Docs canonicos com `capabilities`, `implementation_state`, `status` e `required_tests`.

## Evidencias

`tests/Feature/Foundry/FoundrySemanticGapFinderServiceTest.php` (a entregar junto
com o servico) DEVE provar, sem provider, a partir de uma FIXTURE:

1. **Emite gap real**: dossie com claim `implementation_state: available` +
   ledger_event blocker confirmado -> 1 gap `claimed_available_runtime_blocked`
   com `anchor_id` presente no dossie e `anchor_verdict=confirmed`.
2. **NAO emite "keep doc in sync"**: nenhum gap cujo `drift_kind` esteja fora de
   `DRIFT_KINDS`; assert explicito de que nenhum gap contem "keep doc in sync"
   nem "update documentation".
3. **Doc que casa com runtime nao gera gap**: claim `available` + anchor
   confirmado da MESMA capacidade -> `gap_count=0`, `status` honesto.
4. **Gap sem ancora verificavel e dropado**: claim cujo unico anchor referenciado
   nao existe em `dossier.anchors[]` -> aparece em `drops`, nunca em `gaps`.
5. **Determinismo**: duas execucoes da mesma fixture -> `report_hash` identico.
6. **Read-only**: dobras de owner explodem se chamadas; run verde = zero I/O.

Ate o servico existir, o harness committed nesta fatia prova o CONTRATO via uma
referencia deterministica local (oracle no proprio teste) que demonstra os
drift_kinds e a regra "ancora confirmada obrigatoria", falhando se a regra
"keep doc in sync" reaparecer.

## Riscos

- Gap sem evidencia virar nova fonte de backlog inerte.
- Provider real-or-blocked ser contornado por geracao direta.
- Gap-finder virar writer de canon/codigo.
- Drift_kind aberto reintroduzir ruido lexical.

## Exemplos

- Claim `implementation_state: available` + blocker confirmado vira
  `claimed_available_runtime_blocked`.
- Claim de capability sem nenhum anchor confirmado vira
  `claimed_capability_no_runtime_evidence`.
- Doc cujo claim casa com runtime emite `gap_count=0`, nao gap generico.

## Proximas Acoes

Esta fatia vem DEPOIS de AP-A (Harvester, entregue) e AP-C (orquestrador +
armadura, entregue). Ela substitui a FONTE de gap (de boilerplate para
semantica) sem tocar nos gates ja vigentes. Proxima fatia: FASE 1 consumir o
backlog estruturado e decompor 1 candidate -> slices num ciclo real.
