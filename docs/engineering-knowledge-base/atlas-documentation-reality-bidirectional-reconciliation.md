---
id: atlas-documentation-reality-bidirectional-reconciliation
type: engineering_knowledge
title: Atlas Documentation Reality Bidirectional Reconciliation
status: active
category: documentation-governance
priority: 98
summary: Segundo incremento do pilar P2 (Gerativo/Auto-curativo) do salto gerativo do ADRS. Compoe a reconciliacao BIDIRECIONAL doc<->codigo que o doc-mae nomeia: reusa o propositor de over-claim (doc afirma mais do que o codigo prova) E adiciona a direcao que faltava, under-claim (o codigo prova mais do que o doc afirma -> propor SUBIR o implementation_state do doc). Ambas as direcoes sao propostas SOMENTE do lado do doc, read-only, nunca geram codigo e nunca aplicam nada.
human_name: Reconciliacao Bidirecional Doc<->Codigo (Doc-side)
canonical_name: Atlas Documentation Reality Bidirectional Reconciliation
technical_name: AtlasDocumentationRealityBidirectionalReconciliationService
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-documentation-reality-bidirectional-reconciliation.md
tags:
  - atlas-ai
  - documentation-governance
  - adrs
  - generative
  - self-healing
  - bidirectional-reconciliation
capabilities:
  - bidirectional_doc_code_reconciliation
  - under_claim_doc_upgrade_proposal
  - over_claim_repair_delegation
  - read_only_doc_side_reconciliation
decisions:
  - Nome canonico/produto obrigatorio: Atlas Documentation Reality Bidirectional Reconciliation.
  - Tecnico obrigatorio: AtlasDocumentationRealityBidirectionalReconciliationService.
  - Este doc e o segundo incremento do pilar P2 do atlas-documentation-reality-generative-leap; nao e uma segunda fonte canonica.
  - A direcao over-claim e DELEGADA ao AtlasDocumentationRealityRepairProposerService; este service nunca a re-deriva.
  - A direcao under-claim vem do flag under_claim da maturity ledger (rank(computed) > rank(claimed)); nunca recomputada aqui.
  - As DUAS direcoes sao somente do lado do doc; a unica opcao under-claim e subir implementation_state na frontmatter do owner doc.
  - Read-only: nunca aplica, nunca escreve/apaga arquivo, nunca gera nem toca codigo em nenhuma direcao.
  - A direcao codigo-a-partir-do-spec (regenerar codigo de um spec) e o incremento P2 ARRISCADO posterior, explicitamente fora de escopo aqui.
maintenance:
  - Manter abaixo de 520 linhas.
  - Revisar quando a maturity ledger, o propositor de over-claim, o ADRS ou o doc-mae do salto gerativo mudarem.
  - Rodar docs-health, docs-authority-audit e architecture-validate apos alteracoes.
related_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-generative-leap.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-generative-self-healing.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-system.md
  - docs/engineering-knowledge-base/atlas-aaeos-documentation-as-law-proposal.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-documentation-reality-bidirectional-reconciliation
graph_title: Atlas Documentation Reality Bidirectional Reconciliation
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-documentation-reality-generative-leap
graph_status: active
graph_source: repo
owner: documentation-governance
implementation_state: partial
repo_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-bidirectional-reconciliation.md
  - app/Services/Engineering/AtlasDocumentationRealityBidirectionalReconciliationService.php
  - app/Console/Commands/AtlasDocumentationRealityBidirectionalReconcileCommand.php
  - tests/Feature/Engineering/AtlasDocumentationRealityBidirectionalReconciliationTest.php
allowed_changes:
  - Refinar a opcao de upgrade under-claim, o envelope read-only e a delegacao do over-claim.
  - Promover incrementos P2 posteriores (geracao de codigo a partir do spec) em docs filhos proprios com gate e drift zero.
forbidden_changes:
  - Re-derivar a direcao over-claim aqui; ela e delegada ao propositor existente.
  - Recomputar drift ou under-claim; o veredito vem da maturity ledger.
  - Adicionar opcao que gere, edite ou apague codigo/arquivo, ou que aplique algo automaticamente.
  - Declarar runtime sem evidencia que resolve no indice.
depends_on:
  - atlas-documentation-reality-generative-leap
  - atlas-documentation-reality-generative-self-healing
  - atlas-aaeos-documentation-as-law-proposal
flows_to:
  - atlas-documentation-reality-generative-leap
  - atlas-documentation-reality-generative-self-healing
  - atlas-documentation-reality-system
unlocks:
  - bidirectional_doc_code_reconciliation
  - under_claim_doc_upgrade_proposal
governs:
  - documentation-governance-bidirectional-reconciliation
evidence:
  - docs/engineering-knowledge-base/atlas-documentation-reality-bidirectional-reconciliation.md
evidence_refs:
  - symbol: AtlasDocumentationRealityBidirectionalReconciliationService
  - command: atlas:documentation-reality-bidirectional-reconcile
  - test: AtlasDocumentationRealityBidirectionalReconciliationTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:ai:architecture-validate --json"
requires_evidence: true
risk_level: critical
visual_tags:
  - system
  - self-healing
  - bidirectional-reconciliation
ai_entrypoints:
  - Leia este doc quando precisar reconciliar doc<->codigo nas DUAS direcoes (over-claim e under-claim) como propostas doc-side.
ai_usage_notes:
  - Este e o segundo incremento do P2; o reconciliador e read-only e nunca aplica, nunca gera codigo, em nenhuma direcao.
  - A direcao over-claim e delegada ao propositor existente; a direcao under-claim e o novo upgrade do lado do doc.
  - A geracao de codigo a partir do spec e um incremento P2 posterior arriscado, NAO implementado aqui.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:ai:architecture-validate --json"
failure_modes:
  - Reconciliador gerar, editar ou apagar codigo vivo (auto-reparo cego).
  - Reconciliador aplicar uma proposta automaticamente em vez de so propor.
  - Reconciliador re-derivar a direcao over-claim ou recomputar under-claim em vez de reusar.
observability_signals:
  - over_claim_count
  - under_claim_count
  - total_reconciliations
next_actions:
  - Usar este reconciliador como base do incremento P2 posterior (codigo a partir do spec), em doc filho proprio com gate.
---
# Atlas Documentation Reality Bidirectional Reconciliation

## Resumo

Este doc define o **segundo incremento do pilar P2** (Gerativo/Auto-curativo) do
salto gerativo do ADRS. O doc-mae nomeia o P2 como
**"reconciliacao bidirecional doc<->codigo"**. O primeiro incremento cobriu uma
direcao — **over-claim** (o doc afirma um `implementation_state` mais alto do que
o indice de Code Intelligence consegue provar). Este incremento adiciona a
direcao que faltava — **under-claim** (o codigo resolve **mais** do que o doc
afirma).

```text
over-claim : doc afirma MAIS do que o codigo prova  -> baixar a claim OU suprir evidencia
under-claim: codigo prova MAIS do que o doc afirma  -> SUBIR o implementation_state do doc
```

Ele responde a uma pergunta unica:

```text
Onde doc e codigo divergem nas DUAS direcoes, e qual e o reparo minimo e seguro,
sempre do lado do doc?
```

## Papel no Atlas

O salto gerativo do ADRS tem tres pilares (P1 Preditivo, P2 Gerativo, P3
Auto-imunizante). P1 ja esta DONE; o **primeiro incremento do P2** (propositor de
reparo de over-claim) tambem. Este doc fecha o P2 **bidirecional** pelo lado
seguro: ele **compoe** os dois sentidos da reconciliacao como propostas doc-side.

A direcao over-claim ja tem dono — o `AtlasDocumentationRealityRepairProposerService`.
Este service **nao re-descreve** essa direcao; ele a **delega** verbatim e adiciona
a camada que faltava: quando o codigo prova mais do que o doc afirma, o doc
**sub-documenta a realidade**, e a correcao e **subir** a claim do doc para o que
o codigo ja prova.

A diferenca de categoria: hoje o under-claim e so um warning na ledger; aqui ele
vira uma **proposta de upgrade** concreta — mas estritamente do lado do doc e
nunca aplicada.

## Onde Se Encaixa

```text
atlas-documentation-reality-system (mae, categoria imune)
  -> atlas-documentation-reality-generative-leap (salto gerativo, north-star dos 3 pilares)
     P1 Preditivo  -> simulador pre-write (DONE)
     P2 Gerativo
        - 1o incremento: atlas-documentation-reality-generative-self-healing (over-claim)
        - 2o incremento: ESTE doc (reconciliacao BIDIRECIONAL doc-side)
             over-claim  -> DELEGA ao propositor de reparo existente
             under-claim -> NOVO: upgrade do implementation_state do doc
     P3 Imunizante -> incremento posterior
```

Fronteira anti-duplicacao: a **deteccao** de drift e de under-claim continua sendo
da maturity ledger (`AtlasAaeosImplementationTruthService::ledger()`); a **direcao
over-claim** continua sendo do propositor de reparo. Este doc nao reimplementa
nenhum dos dois; ele **compoe** os dois e adiciona somente o upgrade under-claim.

## Contratos

| Campo | Valor |
|---|---|
| Nome canonico | Atlas Documentation Reality Bidirectional Reconciliation |
| Tecnico | AtlasDocumentationRealityBidirectionalReconciliationService |
| Comando | atlas:documentation-reality-bidirectional-reconcile |
| Doc-mae | atlas-documentation-reality-generative-leap (pilar P2) |
| Direcao over-claim | DELEGADA a AtlasDocumentationRealityRepairProposerService (reuso) |
| Direcao under-claim | derivada do flag under_claim da ledger (rank(computed) > rank(claimed)) |
| Estado | partial (service + comando + teste resolvem no indice) |

Schema do envelope: `atlas.documentation_reality.bidirectional_reconcile.v1`.

O envelope bidirecional declara:

- `over_claim_repairs` — a saida do propositor de reparo, embutida verbatim
  (mesmas opcoes `downgrade_state` / `supply_evidence`, sempre doc-side);
- `under_claim_upgrades` — por linha com `under_claim === true`, uma proposta:
  - `owner_doc`, `claimed_state`, `computed_state`, `direction: under_claim_doc_upgrade`;
  - `repair_options` (uma): `upgrade_state` — subir `implementation_state` para o
    `computed_state` que o codigo ja prova (`from`, `to`, `reversible:true`,
    `touches_code:false`, `generates_code:false`);
  - `recommended: upgrade_state`;
  - `proof_refs_resolved` — os refs que resolveram e sustentam o tier (read-only);
  - `safety` — `{read_only:true, auto_apply:false, proposes_code_change:false,
    generates_code:false}`;
- `summary` — `{over_claim_count, under_claim_count, total_reconciliations}`;
- `claim_policy` — `{read_only:true, auto_applies:false, generates_code:false,
  proposes_code_mutation:false, both_directions_doc_side_only:true}`;
- `reconcile_hash` — hash determinstico do envelope read-only.

Regra de autoridade (herdada do ADRS, inalterada): repo docs canonicos
> codigo/testes > Evidence Ledger > read models > Cartografia > chat.

## Fluxo

1. Chamar `reconcileAll()` (corpus inteiro) ou `reconcileForDoc(ownerDoc)` (um doc).
2. Direcao over-claim: **delegar** ao propositor de reparo (`proposeAll()` /
   `proposeForDoc()`) e embutir a saida.
3. Se o propositor **degradou** (indice cego), degradar o pacote inteiro e nao
   emitir upgrades under-claim (ver Riscos).
4. Direcao under-claim: ler a **mesma** maturity ledger; para cada linha com
   `under_claim === true`, gerar uma proposta de `upgrade_state` doc-side.
5. Linhas limpas (sem drift e sem under-claim) nao geram proposta em nenhuma direcao.
6. Retornar o envelope read-only com hash; nada e aplicado.
7. O comando imprime as duas direcoes; nada e aplicado.
8. Para aplicar de fato, a mudanca segue os gates existentes + Evidence Ledger.

## Regras para IA

- NUNCA aplicar uma proposta a partir deste reconciliador; ele so propoe.
- NUNCA gerar, editar ou apagar codigo ou qualquer arquivo em nenhuma direcao.
- NUNCA re-derivar a direcao over-claim aqui; ela e delegada ao propositor.
- NUNCA recomputar drift ou under-claim; o veredito vem da maturity ledger.
- NUNCA promover este incremento para geracao de codigo a partir do spec sem doc
  filho proprio com gate.
- O reparo/upgrade, quando aplicado, passa pelos mesmos gates + Evidence Ledger.

## Escopo de Implementacao

Implementado neste incremento (resolve no indice):

- `AtlasDocumentationRealityBidirectionalReconciliationService` — reconciliador
  read-only com `reconcileAll()` e `reconcileForDoc()`; delega over-claim e deriva
  under-claim.
- `atlas:documentation-reality-bidirectional-reconcile` — comando read-only que
  imprime as duas direcoes (`--capability=`, `--json`).
- `AtlasDocumentationRealityBidirectionalReconciliationTest` — gates de determinismo.

Fora de escopo (incremento P2 posterior arriscado, doc filho proprio):

- **Geracao de codigo a partir do spec** do doc canonico (regenerar codigo
  faltante para um spec). Esta e a direcao com risco real e e DELIBERADAMENTE
  deixada de fora deste incremento.
- Qualquer aplicacao automatica de reparo ou upgrade.

## Dependencias

- atlas-documentation-reality-generative-leap (pilar P2, doc-mae).
- atlas-documentation-reality-generative-self-healing (1o incremento; dono do
  over-claim, delegado aqui).
- atlas-aaeos-documentation-as-law-proposal (maturity ledger = fonte do drift e do
  under-claim).

## Evidencias

Este incremento e `partial`: o service, o comando e o teste resolvem no indice de
Code Intelligence via `evidence_refs`. Promocao a `verified` exige tambem teste e
receipt resolvendo, exatamente como qualquer bloco do Atlas.

## Riscos

- **Auto-reparo cego (o risco nomeado no doc-mae):** uma proposta que gere, edite
  ou apague codigo vivo. Mitigacao: as DUAS direcoes sao propostas **somente do
  lado do doc**; nenhuma opcao toca, gera, edita ou apaga codigo ou arquivo. A
  direcao verdadeiramente arriscada — **gerar codigo a partir do spec** — e o
  incremento P2 posterior e esta FORA de escopo aqui. A correcao real passa pelos
  gates existentes + Evidence Ledger.
- **Aplicacao automatica:** o reconciliador aplicar em vez de so propor. Mitigacao:
  `claim_policy.auto_applies` e sempre `false`; o decider e read-only, sem caminho
  de escrita nem de exec.
- **Segunda fonte de verdade:** re-derivar over-claim ou recomputar under-claim
  aqui. Mitigacao: over-claim e delegado verbatim ao propositor; under-claim vem do
  flag `under_claim` da ledger.
- **Indice cego nas duas direcoes:** um indice vazio faz TODO doc parecer ao mesmo
  tempo over- E under-claiming. Mitigacao: o reconciliador reusa o guard do
  propositor — se o over-claim degrada, o pacote inteiro degrada e NENHUM upgrade
  under-claim e emitido.
- **Sprawl/over-reach P2:** confundir este incremento doc-side com a geracao de
  codigo. Mitigacao: a geracao codigo<-spec e explicitamente um incremento
  posterior, em doc filho proprio com gate.

## Exemplos

```text
- doc declara spec, indice prova verified (symbol + command + test + receipt resolvem)
    -> under_claim_upgrades: upgrade_state (spec -> verified), generates_code=false
    -> proof_refs_resolved nomeia os refs que sustentam o tier
- doc declara verified, indice prova spec, evidence_refs nao resolvem
    -> over_claim_repairs: DELEGADO ao propositor (downgrade_state OU supply_evidence)
- linha sem drift e sem under-claim
    -> zero propostas; doc e codigo ja concordam nas duas direcoes
- nenhuma proposta jamais gera/edita/apaga codigo; safety.generates_code = false
```

## Proximas Acoes

- Usar este reconciliador como base do incremento P2 posterior: **geracao de codigo
  a partir do spec**, em doc filho proprio com gate e drift zero — a direcao
  arriscada que fica fora deste incremento.
- Manter o reconciliador read-only; qualquer aplicacao continua passando pelos
  gates existentes + Evidence Ledger.
