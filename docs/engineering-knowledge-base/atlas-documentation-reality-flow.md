---
id: atlas-documentation-reality-flow
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Documentation Reality Flow
slug: atlas-documentation-reality-flow
status: active
category: documentation-governance
priority: 95
summary: O "Fluxo alvo para IA" do salto gerativo do ADRS, virado runtime como UMA composicao read-only. Agora que a escada esta COMPLETA, o fluxo a reflete inteira — o post_write e o TRIANGULO P2 completo (over-claim + under-claim + code-contract doc-ahead-of-code) e a reflective_note e a TRIADE L-inf completa (R1 causal + R2 humildade + ponteiro R3). Cada capability ja construida existia como comando SEPARADO; este orquestrador as compoe no fluxo unico documentado, chamando cada uma e expondo a saida REAL (resumida). Nao reimplementa nada, nao muda comportamento e NAO e o enforcement (o hook de pre-commit L0 e).
human_summary: O doc do salto gerativo descreve o fluxo de ponta a ponta — preve o erro antes de escrever, deixa o write passar pelo enforcement L0, reconcilia divergencia (nas tres direcoes) e cria um anticorpo se algo escapar. Cada peca ja existia como comando solto. Este servico junta os servicos compostos no fluxo unico, chamando cada um e mostrando o que ele REALMENTE devolve: o triangulo P2 inteiro (over + under + code-contract) e a triade L-inf inteira (R1 + R2 + ponteiro R3). Ele so compoe; nao escreve nada, nao muda nada e nao e quem bloqueia o write (quem bloqueia e o hook de pre-commit).
human_what: Orquestrador read-only que compoe as capabilities ja construidas do ADRS no "Fluxo alvo para IA" documentado — incluindo o triangulo P2 completo e a triade L-inf completa — expondo a saida real de cada uma sem alterar verdito.
human_purpose: Fazer o codigo refletir o fluxo unico documentado, ja com a escada completa, sem reimplementar nem mudar comportamento, para uma IA ver previsao, conselho, veredito de enforcement, as tres direcoes de reconciliacao, imunizacao e a triade reflexiva como um so envelope.
human_input: Recebe a mudanca proposta (mesma forma do P1 simulate / O2 adviseProposal), os paths tocados, e opcionalmente uma falha que escapou.
human_output: Entrega um envelope read-only com pre_write (previsao P1 + conselho O2), write_boundary (veredito L0 ou nota de que o hook e o enforcement), post_write (TRIANGULO P2: reconciliacao over-claim + under-claim + code-contract, mais imunizacao P3) e reflective_note (TRIADE L-inf: causal R1 + humildade R2 + ponteiro R3), com linf_complete=false.
human_change_when: Mexa quando uma das capabilities compostas mudar a forma da sua saida, ou quando o fluxo documentado no doc-mae do salto gerativo evoluir.
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
  - O orquestrador e estritamente READ-ONLY e COMPOSE-ONLY - injeta e chama as capabilities ja construidas e expoe a saida REAL de cada uma; nunca re-deriva nem altera um verdito.
  - O post_write compoe o TRIANGULO P2 completo - over-claim (repair proposer), under-claim (reconciliacao bidirecional, so a direcao under-claim e surfada para nao dobrar a contagem) e code-contract (doc-ahead-of-code, contrato seguro, nunca codigo).
  - A reflective_note compoe a TRIADE L-inf completa - causal R1 (explainCapability FOCADO no foco da mudanca, nunca explainAll), humildade R2 (headline) e um PONTEIRO estatico para R3 (self-improvement-modeling roda em nivel de sessao, nunca por escrita). linf_complete permanece HARD false.
  - As novas etapas P2 (under-claim/code-contract) e R1 sao resumos read-only ADITIVOS; nada gera codigo nem aplica proposta. R3 NAO e injetado (ponteiro estatico evita dependencia pesada nao usada).
  - Este fluxo NAO e o enforcement - o enforcement L0 ativo e o hook de pre-commit (scripts/hooks/pre-commit -> atlas:documentation-reality-write-gate). Sem paths tocados, o write_boundary diz isso explicitamente.
  - Degrade-safe - uma collaborator que lanca vira {available:false, reason} naquele estagio, nunca um resultado fabricado.
  - Runtime read-only - nenhuma escrita, execucao de comando mutativo, mutacao ou autorizacao; nao auto-age.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar quando qualquer capability composta (triangulo P2 ou triade L-inf) mudar a forma da sua saida.
  - Rodar docs-health, docs-authority-audit e architecture-validate apos alteracoes.
graph_id: atlas-documentation-reality-flow
human_name: Atlas Documentation Reality Flow
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
  - atlas-documentation-reality-bidirectional-reconciliation
  - atlas-documentation-reality-code-contract-proposals
  - atlas-documentation-reality-self-immunizing-antibody
  - atlas-documentation-reality-causal-self-model-fragment
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
  - docs/engineering-knowledge-base/atlas-documentation-reality-bidirectional-reconciliation.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-code-contract-proposals.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-self-immunizing-antibody.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-causal-self-model-fragment.md
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
  - Leia este doc para ver o "Fluxo alvo para IA" do ADRS como UMA composicao read-only das capabilities ja construidas, incluindo o triangulo P2 completo e a triade L-inf completa.
ai_usage_notes:
  - Este fluxo compoe e expoe; nao decide, nao escreve, nao e o enforcement (o hook de pre-commit L0 e).
  - Cada estagio surfaca a saida REAL da capability composta; um verdito (ex. would_duplicate) nunca e suavizado nem re-derivado aqui.
next_actions:
  - Manter os resumos fieis se qualquer capability composta (triangulo P2 ou triade L-inf) mudar a forma da saida.
  - Nao promover este fluxo a enforcement nem a auto-acao; ele e e permanece composicao read-only.
---

# Atlas Documentation Reality Flow

## Resumo

O doc-mae do salto gerativo (`atlas-documentation-reality-generative-leap.md`,
secao **Fluxo alvo para IA**) descreve as capabilities ja construidas como
UM fluxo de ponta a ponta:

```text
tarefa
-> P1 preve ("se eu escrever X: duplica Y, drift Z, dono W, quebra Q")
-> a IA recebe a previsao ANTES de escrever
-> a escrita acontece via enforcement write-bound (L0)
-> P2 reconcilia (propoe reparo para a divergencia, nas TRES direcoes)
-> P3 sintetiza um anticorpo se algo escapou
```

Cada rung ja existia como um **service read-only separado**, com comando, doc e
teste proprios. O que faltava era o **fluxo unico** virar runtime. Este
orquestrador faz exatamente isso: **injeta cada capability e a chama**, expondo a
saida **REAL** (resumida) de cada uma. Ele **nao reimplementa** nada, **nao muda
comportamento** e **NAO e o enforcement** — o enforcement L0 ativo e o hook de
pre-commit.

Agora que a escada esta **completa**, a composicao a reflete inteira:

- O **post_write** e o **TRIANGULO P2 completo** — over-claim (o repair proposer
  original), under-claim (a reconciliacao bidirecional que sobe o doc) e
  doc-ahead-of-code (o **code-contract** proposer: o contrato seguro de
  code-from-spec, **nunca codigo**).
- A **reflective_note** e a **TRIADE L-inf completa** — **R1** modelo causal
  (especifico da mudanca), **R2** humildade epistemica (o headline) e um
  **PONTEIRO** estatico para **R3** (self-improvement-modeling roda em nivel de
  sessao, nunca por escrita). A nota permanece UMA composicao de fragmentos,
  **nunca a assintota** (`linf_complete` segue HARD false).

## Papel no Atlas

O ADRS ja tinha as pecas; o doc do salto gerativo as descreve como um so fluxo
que ainda nao era codigo. Este doc filho faz o **codigo refletir o fluxo
documentado**: uma IA (ou o operador) ve, num so envelope, a previsao P1, o
conselho O2, o veredito de enforcement L0, o **triangulo P2 completo** (over-claim
+ under-claim + code-contract) e a imunizacao P3, mais uma **triade reflexiva
L-inf** curta (causal R1 + humildade R2 + ponteiro R3). E uma **composicao
fiel**: surfaca o verdito de cada capability como ela o produziu, sem nunca
re-derivar nem suavizar.

## Onde Se Encaixa

```text
atlas-documentation-reality-generative-leap (doc-mae do salto, "Fluxo alvo para IA")
  -> ESTE doc filho (a composicao read-only do fluxo documentado)
       pre_write     -> P1 AtlasSoftwareTwinRuntimeService::simulate  (prever)
                     -> O2 AtlasDocumentationRealityIntentAdvisoryService::adviseProposal (aconselhar)
       write_boundary-> L0 AtlasDocumentationRealityWriteGateService::decide (veredito)
                        [o ENFORCEMENT ativo e o hook de pre-commit, nao este fluxo]
       post_write    -> P2 (TRIANGULO completo):
                          over-claim   -> AtlasDocumentationRealityRepairProposerService::proposeAll|proposeForDoc
                          under-claim  -> AtlasDocumentationRealityBidirectionalReconciliationService::reconcileAll|reconcileForDoc (so a direcao under-claim e surfada)
                          code-contract-> AtlasDocumentationRealityCodeContractProposerService::proposeAll|proposeForDoc (doc-ahead-of-code, nunca codigo)
                     -> P3 AtlasDocumentationRealityAntibodyProposerService::proposeFromCapsule (imunizar, so com falha)
       reflective    -> L-inf (TRIADE completa):
                          causal R1    -> AtlasDocumentationRealityCausalSelfModelService::explainCapability (FOCADO no foco da mudanca)
                          humildade R2 -> AtlasDocumentationRealityReflectiveStatusService::selfAssessment (headline)
                          R3 ponteiro  -> atlas:documentation-reality-self-improvement-modeling (estatico, nivel de sessao, nao injetado)
```

Fronteira anti-duplicacao: este doc **nao re-descreve** as capabilities; cada uma
tem o seu proprio doc canonico (inclusive os tres servicos novos do triangulo P2
e da triade L-inf). Este doc define **so** a camada de composicao que existe
**acima** delas.

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
  verdict_is_indicative_not_authoritative,
  enforcement : L0 decide() veredito (com paths) | nota de que o hook e o enforcement (sem paths)
},
post_write {                 # TRIANGULO P2 + P3
  stage = "P2_reconcile_over_under_and_code_contract_then_P3_immunize",
  reconciliation             : P2 over-claim resumo { drift_count, proposal_count, degraded },
  under_claim_reconciliation : P2 under-claim resumo { direction_surfaced:"under_claim_only",
                                 under_claim_count, under_claim_unconfirmed_count, degraded },
  code_contract              : P2 doc-ahead-of-code resumo { docs_with_gaps, total_unresolved_refs, degraded },
  immunization               : P3 proposeFromCapsule() resumo (com falha) | nota on-demand (sem falha)
},
reflective_note {            # TRIADE L-inf, linf_complete=false
  stage = "L-inf_triad_causal_R1_humility_R2_self_improvement_pointer_R3",
  linf_complete = false, is_one_composition_not_the_asymptote = true,
  causal           : R1 explainCapability(foco) resumo da cadeia headline
                       { intent_state, truth_state, result_grade, why_link_count, chain_confidence, linf_complete:false },
  humility         : R2 selfAssessment headline { headline_confidence, headline_is_bare_verdict, linf_complete },
  self_improvement : R3 ponteiro ESTATICO { available_via, scope:"session/global, not per-change", note }
},
writes:false,
claim_policy { read_only:true, writes:false, composes_only:true,
               changes_no_behavior:true, enforcement_is_the_hook_not_this:true, auto_acts:false, ... },
flow_hash
```

Comando: `atlas:documentation-reality-flow {--kind=doc} {--slug=} {--graph-id=}
{--capability=*} {--symbol=} {--objective=} {--paths=} {--json}`
(auto-descoberto, nao muta nada, **sempre sai 0**).

## Composicao — Fidelidade as Capabilities

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
   o fluxo demonstra um write proposto) e surfaca o veredito real, **indicativo, nao
   autoritativo** (`verdict_is_indicative_not_authoritative`); sem paths, diz que o
   **hook de pre-commit** e o enforcement ativo.
4. **post_write / P2 (TRIANGULO completo)** — tudo focado no mesmo owner doc:
   - **over-claim** — `AtlasDocumentationRealityRepairProposerService::proposeForDoc`
     (ou `proposeAll`) e surfaca `drift_count` / `proposal_count` (inalterado).
   - **under-claim** —
     `AtlasDocumentationRealityBidirectionalReconciliationService::reconcileForDoc`
     (ou `reconcileAll`). Surfaca **so a direcao under-claim**
     (`under_claim_count`, `under_claim_unconfirmed_count`) — o servico bidirecional
     tambem delega o over-claim por dentro, mas essa direcao ja esta no estagio acima,
     entao **nao** e surfada aqui (sem dupla contagem).
   - **code-contract** —
     `AtlasDocumentationRealityCodeContractProposerService::proposeForDoc` (ou
     `proposeAll`) e surfaca `docs_with_gaps` / `total_unresolved_refs`. E o
     code-from-spec **seguro**: contrato, **nunca codigo**.
5. **post_write / P3** — **so** com uma falha fornecida, chama
   `proposeFromCapsule($failure)`; sem falha, carrega a nota on-demand — nunca um
   anticorpo fabricado.
6. **reflective_note / L-inf (TRIADE completa)** — `linf_complete=false`:
   - **causal R1** — `AtlasDocumentationRealityCausalSelfModelService::explainCapability($foco)`
     **FOCADO** no foco da mudanca (graph_id/slug ou path), **nunca** `explainAll`
     (varredura do indice inteiro e pesada demais por mudanca). Surfaca um resumo
     CURTO da cadeia causal headline: `intent_state`, `truth_state`, `result_grade`,
     `why_link_count`, `chain_confidence`.
   - **humildade R2** — `selfAssessment()`: a `confidence` da headline (calibrada,
     nunca um veredito nu), inalterada.
   - **self_improvement R3** — um **PONTEIRO estatico** para
     `atlas:documentation-reality-self-improvement-modeling`. R3 e GLOBAL + pesado e
     propoe o proprio proximo degrau em **nivel de sessao, nunca por escrita**; por
     isso **nao** roda inline e **nao** e injetado aqui.

Cada estagio e **degrade-safe**: se a collaborator lanca, o estagio vira
`{available:false, reason}` — nunca um resultado inventado. As tres direcoes P2 e os
tres fragmentos L-inf degradam **independentes**: uma falha num nao derruba os
outros nem o envelope.

## Fluxo

1. Receber a mudanca proposta, os paths tocados e (opcional) a falha que escapou.
2. **pre_write**: rodar P1 `simulate` e O2 `adviseProposal`; resumir cada saida real.
3. **write_boundary**: com paths, rodar L0 `decide` e resumir o veredito (indicativo);
   sem paths, anotar que o hook de pre-commit e o enforcement.
4. **post_write (triangulo P2)**: rodar as tres direcoes — over-claim
   (`proposeForDoc`/`proposeAll`), under-claim (`reconcileForDoc`/`reconcileAll`, so
   a direcao under-claim) e code-contract (`proposeForDoc`/`proposeAll`) — e, so com
   falha, P3 `proposeFromCapsule`; resumir cada uma.
5. **reflective_note (triade L-inf)**: rodar R1 `explainCapability(foco)` FOCADO, R2
   `selfAssessment`, e anexar o ponteiro estatico R3; resumir, mantendo
   `linf_complete=false`.
6. Anexar `writes:false`, `claim_policy` (composes_only, auto_acts:false), hash.
   Devolver.

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
- `AtlasDocumentationRealityRepairProposerService` (P2, reconciliacao over-claim).
- `AtlasDocumentationRealityBidirectionalReconciliationService` (P2, reconciliacao
  under-claim — so a direcao under-claim e surfada).
- `AtlasDocumentationRealityCodeContractProposerService` (P2, code-contract
  doc-ahead-of-code — contrato, nunca codigo).
- `AtlasDocumentationRealityAntibodyProposerService` (P3, imunizacao).
- `AtlasDocumentationRealityCausalSelfModelService::explainCapability` (L-inf R1,
  modelo causal focado).
- `AtlasDocumentationRealityReflectiveStatusService` (L-inf R2, humildade /
  nota reflexiva).
- `atlas:documentation-reality-self-improvement-modeling` (L-inf R3, ponteiro
  estatico em nivel de sessao — nao injetado neste servico).

## Evidencias

- symbol: `AtlasDocumentationRealityFlowService`
- command: `atlas:documentation-reality-flow`
- test: `AtlasDocumentationRealityFlowTest`

Drift do doc filho deve ser `false` em
`atlas:aaeos:maturity --capability=atlas-documentation-reality-flow --json`.

## Riscos

- **Confundir composicao com enforcement (o risco central):** alguem ler este
  fluxo como o que **bloqueia** o write. **Mitigacao:** isto e uma composicao
  **READ-ONLY** das capabilities ja construidas no "Fluxo alvo para IA"
  documentado; ele **muda comportamento NENHUM** e **NAO e o enforcement** — o
  enforcement L0 ativo e o **hook de pre-commit**. Sem paths tocados, o
  `write_boundary` diz isso explicitamente; com paths, o veredito e
  **indicativo, nao autoritativo** (`verdict_is_indicative_not_authoritative`).
  `claim_policy.enforcement_is_the_hook_not_this` e `composes_only` gravam isso no
  envelope.
- **Codigo gerado as cegas ("auto-reparo cego") pela direcao code-contract:** a
  nova direcao P2 doc-ahead-of-code sugerir gerar codigo sozinha. **Mitigacao:** o
  code-contract proposer e estritamente **contrato** (descricao de assinatura +
  outline de teste), **nunca codigo**, com guarda estrutural no proprio servico; o
  fluxo so surfaca `docs_with_gaps` / `total_unresolved_refs`, nunca aplica nada.
- **Auto-conhecimento sem incerteza calibrada (o drift supremo) na triade L-inf:**
  a nota reflexiva afirmar uma causa sem incerteza. **Mitigacao:** R1 so emite
  links causais calibrados (cada link carrega `basis`, `inference` proven/inferred e
  `blind_spot`), e R3 e so um **ponteiro estatico** rodado em nivel de sessao;
  `reflective_note.linf_complete` permanece HARD `false` — a nota e UMA composicao de
  fragmentos, nunca a assintota.
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
  pre_write.predictive                    : verdict=would_duplicate (P1 surfaca o collision como esta)
  pre_write.intent_advisory               : recommendation=reconsider_advisory + bloco de soberania (O2)
  write_boundary                          : is_active_via = hook de pre-commit; enforcement = veredito real (indicativo) do L0 decide()
  post_write.reconciliation               : P2 over-claim { drift_count, proposal_count } pro doc tocado
  post_write.under_claim_reconciliation   : P2 under-claim { under_claim_count, under_claim_unconfirmed_count } (so under-claim)
  post_write.code_contract                : P2 doc-ahead-of-code { docs_with_gaps, total_unresolved_refs } (contrato, nunca codigo)
  post_write.immunization                 : nota on-demand (sem falha fornecida — nenhum anticorpo fabricado)
  reflective_note.causal                  : R1 cadeia headline { intent_state, truth_state, result_grade, why_link_count, chain_confidence }
  reflective_note.humility                : R2 headline confidence calibrada, is_bare_verdict=false
  reflective_note.self_improvement        : R3 ponteiro estatico (session/global, not per-change)
  reflective_note.linf_complete           : false (UMA composicao de fragmentos, nunca a assintota)

Mesma proposta, agora SEM paths tocados:
  write_boundary.enforcement : nota — "o hook de pre-commit L0 e o enforcement ativo" (nao adjudica)

Mesma proposta, agora COM uma falha que escapou:
  post_write.immunization    : P3 proposeFromCapsule() — anticorpo com reproducing_test_outline obrigatorio

Degrade-safe (ex.: o servico bidirecional lanca):
  post_write.under_claim_reconciliation : { available:false, reason } — os outros estagios seguem intactos, o envelope ainda volta
```

Em todos os casos o envelope e read-only, compoe as capabilities (o triangulo P2
inteiro e a triade L-inf inteira), expoe a saida real de cada uma, e nao e o
enforcement.

## Proximas Acoes

- Manter os resumos fieis se qualquer capability composta (triangulo P2 ou triade
  L-inf) mudar a forma da saida.
- Nao promover este fluxo a enforcement nem a auto-acao; ele e e permanece
  composicao read-only. R3 permanece ponteiro de sessao, nunca inline por escrita.
