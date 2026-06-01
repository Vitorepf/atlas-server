---
id: atlas-documentation-reality-flow
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Documentation Reality Flow
slug: atlas-documentation-reality-flow
status: active
category: documentation-governance
priority: 95
summary: O "Fluxo alvo para IA" do salto gerativo do ADRS, virado runtime como UMA composicao read-only. As seis capabilities ja construidas (P1 prever, O2 aconselhar, L0 veredito do write-gate, P2 reconciliar, P3 imunizar, L-inf nota reflexiva) existiam como comandos SEPARADOS; este orquestrador as compoe no fluxo unico documentado, chamando cada uma e expondo a saida REAL (resumida). Nao reimplementa nada, nao muda comportamento e NAO e o enforcement (o hook de pre-commit L0 e).
human_summary: O doc do salto gerativo descreve o fluxo de ponta a ponta — preve o erro antes de escrever, deixa o write passar pelo enforcement L0, reconcilia divergencia e cria um anticorpo se algo escapar. Cada peca ja existia como comando solto. Este servico junta os seis servicos compostos (P1, O2, L0 write-gate, P2, P3, L-inf) no fluxo unico, chamando cada um e mostrando o que ele REALMENTE devolve. Ele so compoe; nao escreve nada, nao muda nada e nao e quem bloqueia o write (quem bloqueia e o hook de pre-commit).
human_what: Orquestrador read-only que compoe as seis capabilities ja construidas do ADRS no "Fluxo alvo para IA" documentado, expondo a saida real de cada uma sem alterar verdito.
human_purpose: Fazer o codigo refletir o fluxo unico documentado sem reimplementar nem mudar comportamento, para uma IA ver previsao, conselho, veredito de enforcement, reconciliacao e imunizacao como um so envelope.
human_input: Recebe a mudanca proposta (mesma forma do P1 simulate / O2 adviseProposal), os paths tocados, e opcionalmente uma falha que escapou.
human_output: Entrega um envelope read-only com pre_write (previsao P1 + conselho O2), write_boundary (veredito L0 ou nota de que o hook e o enforcement), post_write (reconciliacao P2 + imunizacao P3) e uma nota reflexiva L-inf curta.
human_change_when: Mexa quando uma das seis capabilities mudar a forma da sua saida, ou quando o fluxo documentado no doc-mae do salto gerativo evoluir.
human_block_when: Bloqueie se alguem tentar fazer este orquestrador escrever, autorizar mutacao, virar o enforcement (em vez do hook), auto-agir, ou re-derivar/alterar o verdito de qualquer capability composta.
canonical_name: Atlas Documentation Reality Flow
technical_name: AtlasDocumentationRealityFlowService
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-documentation-reality-flow.md
tags:
  - atlas-ai
  - documentation-governance
  - adrs
  - composition
  - read-only
capabilities:
  - documented_flow_composition
  - pre_write_foresight_surface
  - write_boundary_verdict_surface
  - post_write_reconciliation_and_immunization_surface
decisions:
  - Nome canonico obrigatorio - Atlas Documentation Reality Flow.
  - Acronimo tecnico - ADRS-FLOW.
  - Este doc filho compoe o "Fluxo alvo para IA" do doc-mae atlas-documentation-reality-generative-leap; NAO e uma segunda fonte canonica.
  - O orquestrador e estritamente READ-ONLY e COMPOSE-ONLY - injeta e chama as seis capabilities ja construidas e expoe a saida REAL de cada uma; nunca re-deriva nem altera um verdito.
  - Este fluxo NAO e o enforcement - o enforcement L0 ativo e o hook de pre-commit (scripts/hooks/pre-commit -> atlas:documentation-reality-write-gate). Sem paths tocados, o write_boundary diz isso explicitamente.
  - Degrade-safe - uma collaborator que lanca vira {available:false, reason} naquele estagio, nunca um resultado fabricado.
  - Runtime read-only - nenhuma escrita, execucao de comando mutativo, mutacao ou autorizacao; nao auto-age.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar quando qualquer uma das seis capabilities compostas mudar a forma da sua saida.
  - Rodar docs-health, docs-authority-audit e architecture-validate apos alteracoes.
graph_id: atlas-documentation-reality-flow
graph_title: Atlas Documentation Reality Flow
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-documentation-reality-generative-leap
graph_status: active
graph_source: repo
owner: documentation-governance
implementation_state: partial
depends_on:
  - atlas-documentation-reality-generative-leap
  - atlas-software-twin-verified-evolution-runtime
  - atlas-documentation-reality-intent-coformation
  - atlas-documentation-reality-generative-self-healing
  - atlas-documentation-reality-self-immunizing-antibody
  - atlas-documentation-reality-reflective-status-fragment
flows_to:
  - atlas-documentation-reality-generative-leap
unlocks:
  - documented_flow_as_one_composition
  - end_to_end_foresight_to_immunization_surface
governs:
  - documentation-governance-composed-flow
related_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-generative-leap.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-intent-coformation.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-generative-self-healing.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-self-immunizing-antibody.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-reflective-status-fragment.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-write-gate.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-flow.md
  - app/Services/Engineering/AtlasDocumentationRealityFlowService.php
  - app/Console/Commands/AtlasDocumentationRealityFlowCommand.php
  - tests/Feature/Engineering/AtlasDocumentationRealityFlowTest.php
allowed_changes:
  - Refinar os resumos de cada estagio mantendo a fidelidade a saida real da capability composta.
  - Ligar uma capability composta a uma nova forma de saida se ela mudar, sem re-derivar verdito.
forbidden_changes:
  - Fazer o orquestrador escrever, executar comando mutativo, autorizar mutacao ou auto-agir.
  - Tornar este fluxo o enforcement (o hook de pre-commit L0 e o enforcement).
  - Re-derivar, suavizar ou alterar o verdito de qualquer capability composta.
  - Criar segunda fonte canonica de verdade documental.
evidence:
  - docs/engineering-knowledge-base/atlas-documentation-reality-flow.md
  - app/Services/Engineering/AtlasDocumentationRealityFlowService.php
evidence_refs:
  - symbol: AtlasDocumentationRealityFlowService
  - command: atlas:documentation-reality-flow
  - test: AtlasDocumentationRealityFlowTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:ai:architecture-validate --json"
requires_evidence: true
risk_level: critical
line_limit: 520
ai_entrypoints:
  - Leia este doc para ver o "Fluxo alvo para IA" do ADRS como UMA composicao read-only das seis capabilities ja construidas.
ai_usage_notes:
  - Este fluxo compoe e expoe; nao decide, nao escreve, nao e o enforcement (o hook de pre-commit L0 e).
  - Cada estagio surfaca a saida REAL da capability composta; um verdito (ex. would_duplicate) nunca e suavizado nem re-derivado aqui.
next_actions:
  - Manter os resumos fieis se qualquer das seis capabilities mudar a forma da saida.
  - Nao promover este fluxo a enforcement nem a auto-acao; ele e e permanece composicao read-only.
---

# Atlas Documentation Reality Flow

## Resumo

O doc-mae do salto gerativo (`atlas-documentation-reality-generative-leap.md`,
secao **Fluxo alvo para IA**) descreve as seis capabilities ja construidas como
UM fluxo de ponta a ponta:

```text
tarefa
-> P1 preve ("se eu escrever X: duplica Y, drift Z, dono W, quebra Q")
-> a IA recebe a previsao ANTES de escrever
-> a escrita acontece via enforcement write-bound (L0)
-> P2 reconcilia (propoe reparo para a divergencia)
-> P3 sintetiza um anticorpo se algo escapou
```

Cada rung ja existia como um **service read-only separado**, com comando, doc e
teste proprios. O que faltava era o **fluxo unico** virar runtime. Este
orquestrador faz exatamente isso: **injeta cada capability e a chama**, expondo a
saida **REAL** (resumida) de cada uma. Ele **nao reimplementa** nada, **nao muda
comportamento** e **NAO e o enforcement** — o enforcement L0 ativo e o hook de
pre-commit.

## Papel no Atlas

O ADRS ja tinha as pecas; o doc do salto gerativo as descreve como um so fluxo
que ainda nao era codigo. Este doc filho faz o **codigo refletir o fluxo
documentado**: uma IA (ou o operador) ve, num so envelope, a previsao P1, o
conselho O2, o veredito de enforcement L0, a reconciliacao P2 e a imunizacao P3,
mais uma nota reflexiva L-inf curta. E uma **composicao fiel**: surfaca o verdito
de cada capability como ela o produziu, sem nunca re-derivar nem suavizar.

## Onde Se Encaixa

```text
atlas-documentation-reality-generative-leap (doc-mae do salto, "Fluxo alvo para IA")
  -> ESTE doc filho (a composicao read-only do fluxo documentado)
       pre_write     -> P1 AtlasSoftwareTwinRuntimeService::simulate  (prever)
                     -> O2 AtlasDocumentationRealityIntentAdvisoryService::adviseProposal (aconselhar)
       write_boundary-> L0 AtlasDocumentationRealityWriteGateService::decide (veredito)
                        [o ENFORCEMENT ativo e o hook de pre-commit, nao este fluxo]
       post_write    -> P2 AtlasDocumentationRealityRepairProposerService::proposeAll|proposeForDoc (reconciliar)
                     -> P3 AtlasDocumentationRealityAntibodyProposerService::proposeFromCapsule (imunizar, so com falha)
       reflective    -> L-inf AtlasDocumentationRealityReflectiveStatusService::selfAssessment (nota curta)
```

Fronteira anti-duplicacao: este doc **nao re-descreve** as seis capabilities;
cada uma tem o seu proprio doc canonico. Este doc define **so** a camada de
composicao que existe **acima** delas.

## Contratos

`AtlasDocumentationRealityFlowService` (read-only):

| Metodo | O que faz |
|---|---|
| `forProposedChange(array $proposal, array $touchedPaths = [], ?array $failure = null): array` | compoe o fluxo documentado para UMA mudanca proposta, chamando cada capability e expondo a saida real resumida |

A forma do `$proposal` e a mesma que o P1 `simulate` / O2 `adviseProposal`
aceitam: `{kind, slug, graph_id, owner, capabilities, governs,
implementation_state, symbol, objective?}`.

Envelope `atlas.documentation_reality.flow.v1` (com hash):

```text
schema_version, mode=read_only_composed_documented_flow,
documents, composes_capabilities[ { rung, role, source } ],
proposal { ..., touched_paths[] },
pre_write {
  predictive      : P1 simulate() resumo { verdict, would_duplicate, would_drift, owner },
  intent_advisory : O2 adviseProposal() resumo { recommendation, sovereignty{...} }
},
write_boundary {
  is_active_via = "scripts/hooks/pre-commit (atlas:documentation-reality-write-gate)",
  enforcement_is_the_hook_not_this = true,
  enforcement : L0 decide() veredito (com paths) | nota de que o hook e o enforcement (sem paths)
},
post_write {
  reconciliation : P2 resumo { drift_count, proposal_count, degraded },
  immunization   : P3 proposeFromCapsule() resumo (com falha) | nota on-demand (sem falha)
},
reflective_note : L-inf headline confidence + nota de composicao read-only,
writes:false,
claim_policy { read_only:true, writes:false, composes_only:true,
               changes_no_behavior:true, enforcement_is_the_hook_not_this:true, ... },
flow_hash
```

Comando: `atlas:documentation-reality-flow {--kind=doc} {--slug=} {--graph-id=}
{--capability=*} {--symbol=} {--objective=} {--paths=} {--json}`
(auto-descoberto, nao muta nada, **sempre sai 0**).

## Composicao — Fidelidade as Sete Capabilities

O orquestrador **so compoe**. Para cada estagio:

1. **pre_write / P1** — chama `AtlasSoftwareTwinRuntimeService::simulate($proposal)`
   e surfaca `verdict`, `would_duplicate`, `would_drift`, `owner` exatamente como o
   P1 os produziu. Um `would_duplicate` aparece como `would_duplicate` — nunca
   suavizado.
2. **pre_write / O2** — chama
   `AtlasDocumentationRealityIntentAdvisoryService::adviseProposal($proposal, $objective)`
   e surfaca a `recommendation` advisory + o bloco de soberania obrigatorio.
3. **write_boundary / L0** — com paths tocados, chama
   `AtlasDocumentationRealityWriteGateService::decide(...)` (postura mutativa, pois
   o fluxo demonstra um write proposto) e surfaca o veredito real; sem paths, diz que
   o **hook de pre-commit** e o enforcement ativo.
4. **post_write / P2** — chama `proposeForDoc(...)` focado no doc tocado/owner
   quando resolvivel, senao `proposeAll()`, e surfaca `drift_count` /
   `proposal_count`.
5. **post_write / P3** — **so** com uma falha fornecida, chama
   `proposeFromCapsule($failure)`; sem falha, carrega a nota on-demand — nunca um
   anticorpo fabricado.
6. **reflective_note / L-inf** — chama `selfAssessment()` e surfaca a `confidence`
   da headline (calibrada, nunca um veredito nu) + a nota de composicao read-only.

Cada estagio e **degrade-safe**: se a collaborator lanca, o estagio vira
`{available:false, reason}` — nunca um resultado inventado.

## Fluxo

1. Receber a mudanca proposta, os paths tocados e (opcional) a falha que escapou.
2. **pre_write**: rodar P1 `simulate` e O2 `adviseProposal`; resumir cada saida real.
3. **write_boundary**: com paths, rodar L0 `decide` e resumir o veredito; sem paths,
   anotar que o hook de pre-commit e o enforcement.
4. **post_write**: rodar P2 (`proposeForDoc`/`proposeAll`) e, so com falha, P3
   `proposeFromCapsule`; resumir.
5. **reflective_note**: rodar L-inf `selfAssessment` e resumir a confidence + nota.
6. Anexar `writes:false`, `claim_policy` (composes_only), hash. Devolver.

## Regras para IA

- Este fluxo **compoe e expoe**; **nunca** decide, escreve, executa comando
  mutativo ou autoriza mutacao.
- Este fluxo **NAO e o enforcement**. O enforcement L0 ativo e o **hook de
  pre-commit** (`scripts/hooks/pre-commit -> atlas:documentation-reality-write-gate`).
- **NUNCA** re-derive, suavize ou altere o verdito de uma capability composta —
  surface-o como ela o produziu.
- **NAO** crie segunda fonte canonica; cada capability ja tem o seu doc.
- P2/P3 surfacam **propostas**; o operador + os gates existentes + o Evidence
  Ledger fazem o trabalho real. Este fluxo nao auto-age.

## Escopo de Implementacao

Runtime read-only. Entrega a composicao do fluxo documentado (service + comando +
teste). O **enforcement** (o hook L0), a **decisao** do operador, o **build**
(write-bound) e a **aplicacao** de qualquer proposta (P2/P3, via gates + Evidence
Ledger) sao deliberadamente fora de escopo: este fluxo so os **compoe e mostra**.

## Dependencias

- `atlas-documentation-reality-generative-leap` (doc-mae do salto, "Fluxo alvo
  para IA").
- `AtlasSoftwareTwinRuntimeService::simulate` (P1, previsao).
- `AtlasDocumentationRealityIntentAdvisoryService::adviseProposal` (O2, conselho).
- `AtlasDocumentationRealityWriteGateService::decide` (L0, veredito do write-gate).
- `AtlasDocumentationRealityRepairProposerService` (P2, reconciliacao).
- `AtlasDocumentationRealityAntibodyProposerService` (P3, imunizacao).
- `AtlasDocumentationRealityReflectiveStatusService` (L-inf, nota reflexiva).

## Evidencias

- symbol: `AtlasDocumentationRealityFlowService`
- command: `atlas:documentation-reality-flow`
- test: `AtlasDocumentationRealityFlowTest`

Drift do doc filho deve ser `false` em
`atlas:aaeos:maturity --capability=atlas-documentation-reality-flow --json`.

## Riscos

- **Confundir composicao com enforcement (o risco central):** alguem ler este
  fluxo como o que **bloqueia** o write. **Mitigacao:** isto e uma composicao
  **READ-ONLY** das seis capabilities ja construidas no "Fluxo alvo para IA"
  documentado; ele **muda comportamento NENHUM** e **NAO e o enforcement** — o
  enforcement L0 ativo e o **hook de pre-commit**. Sem paths tocados, o
  `write_boundary` diz isso explicitamente; `claim_policy.enforcement_is_the_hook_not_this`
  e `composes_only` gravam isso no envelope.
- **Re-derivar ou suavizar um verdito:** o orquestrador alterar o que uma
  capability produziu (ex. esconder um `would_duplicate`). **Mitigacao:** cada
  estagio surfaca a saida **REAL** da capability; um `would_duplicate` aparece como
  `would_duplicate`. `claim_policy.re_derives_verdicts:false`.
- **Auto-acao:** o fluxo aplicar uma proposta P2/P3 sozinho. **Mitigacao:** P2/P3
  surfacam **propostas**; nada e aplicado; o operador + os gates + o Evidence
  Ledger fazem o trabalho real. `claim_policy.auto_acts:false`.
- **Resultado fabricado num estagio que falhou:** inventar um verdito quando uma
  collaborator lanca. **Mitigacao:** degrade-safe — o estagio vira
  `{available:false, reason}`, nunca um resultado inventado.
- **Segunda fonte canonica:** este fluxo virar verdade paralela das capabilities.
  **Mitigacao:** ele nao re-descreve nenhuma; cada capability permanece dona do seu
  proprio doc e verdito.

## Exemplos

```text
Proposta: doc novo com graph_id que JA existe + paths tocados.
  pre_write.predictive       : verdict=would_duplicate (P1 surfaca o collision como esta)
  pre_write.intent_advisory  : recommendation=reconsider_advisory + bloco de soberania (O2)
  write_boundary             : is_active_via = hook de pre-commit; enforcement = veredito real do L0 decide()
  post_write.reconciliation  : P2 resumo { drift_count, proposal_count } pro doc tocado
  post_write.immunization    : nota on-demand (sem falha fornecida — nenhum anticorpo fabricado)
  reflective_note            : headline confidence calibrada, is_bare_verdict=false

Mesma proposta, agora SEM paths tocados:
  write_boundary.enforcement : nota — "o hook de pre-commit L0 e o enforcement ativo" (nao adjudica)

Mesma proposta, agora COM uma falha que escapou:
  post_write.immunization    : P3 proposeFromCapsule() — anticorpo com reproducing_test_outline obrigatorio
```

Em todos os casos o envelope e read-only, compoe as seis capabilities, expoe a
saida real de cada uma, e nao e o enforcement.

## Proximas Acoes

- Manter os resumos fieis se qualquer das seis capabilities mudar a forma da saida.
- Nao promover este fluxo a enforcement nem a auto-acao; ele e e permanece
  composicao read-only.
