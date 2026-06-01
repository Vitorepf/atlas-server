---
id: atlas-documentation-reality-generative-self-healing
type: engineering_knowledge
title: Atlas Documentation Reality Generative Self-Healing
status: active
category: documentation-governance
priority: 98
summary: Primeiro incremento do pilar P2 (Gerativo/Auto-curativo) do salto gerativo do ADRS. A partir de um drift doc<->codigo ja DETECTADO pela maturity ledger, GERA uma proposta de reparo conservadora e read-only — apenas do lado do doc, nunca aplicando nada e nunca tocando codigo vivo.
human_name: Auto-cura Documental (Propositor de Reparo)
canonical_name: Atlas Documentation Reality Generative Self-Healing
technical_name: AtlasDocumentationRealityRepairProposerService
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-documentation-reality-generative-self-healing.md
tags:
  - atlas-ai
  - documentation-governance
  - adrs
  - generative
  - self-healing
  - drift-repair
capabilities:
  - documentation_reality_repair_proposal
  - doc_side_reconciliation_proposer
  - drift_repair_option_generation
  - read_only_self_healing_proposer
decisions:
  - Nome canonico/produto obrigatorio: Atlas Documentation Reality Generative Self-Healing.
  - Tecnico obrigatorio: AtlasDocumentationRealityRepairProposerService.
  - Este doc e o primeiro incremento do pilar P2 do atlas-documentation-reality-generative-leap; nao e uma segunda fonte canonica.
  - O propositor NAO detecta drift; ele REUSA AtlasAaeosImplementationTruthService::ledger() como veredito unico de divergencia.
  - Reparo e somente do lado do doc (baixar a claim OU suprir evidence_refs); nunca apaga nem edita codigo vivo.
  - O propositor e read-only e nunca aplica nada; a mudanca real passa pelos gates existentes + Evidence Ledger.
  - Geracao bidirecional codigo<->doc fica para um incremento P2 posterior, fora deste doc.
maintenance:
  - Manter abaixo de 520 linhas.
  - Revisar quando a maturity ledger, o ADRS ou o doc-mae do salto gerativo mudarem.
  - Rodar docs-health, docs-authority-audit e architecture-validate apos alteracoes.
related_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-generative-leap.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-system.md
  - docs/engineering-knowledge-base/atlas-aaeos-documentation-as-law-proposal.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-block-upgrade-map.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-documentation-reality-generative-self-healing
graph_title: Atlas Documentation Reality Generative Self-Healing
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-documentation-reality-generative-leap
graph_status: active
graph_source: repo
owner: documentation-governance
implementation_state: partial
repo_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-generative-self-healing.md
  - app/Services/Engineering/AtlasDocumentationRealityRepairProposerService.php
  - app/Console/Commands/AtlasDocumentationRealityRepairProposalsCommand.php
  - tests/Feature/Engineering/AtlasDocumentationRealityRepairProposerTest.php
allowed_changes:
  - Refinar as opcoes de reparo doc-side, a recomendacao e o envelope read-only.
  - Promover incrementos P2 posteriores (geracao bidirecional) em docs filhos proprios com gate e drift zero.
forbidden_changes:
  - Criar nova fonte de verdade de drift; o veredito vem da maturity ledger.
  - Adicionar opcao de reparo que apague ou edite codigo/arquivo, ou que aplique algo automaticamente.
  - Declarar runtime sem evidencia que resolve no indice.
depends_on:
  - atlas-documentation-reality-generative-leap
  - atlas-aaeos-documentation-as-law-proposal
  - atlas-documentation-reality-system
flows_to:
  - atlas-documentation-reality-generative-leap
  - atlas-documentation-reality-system
unlocks:
  - generative_self_healing_documentation
  - doc_side_drift_reconciliation
governs:
  - documentation-governance-self-healing
evidence:
  - docs/engineering-knowledge-base/atlas-documentation-reality-generative-self-healing.md
evidence_refs:
  - symbol: AtlasDocumentationRealityRepairProposerService
  - command: atlas:documentation-reality-repair-proposals
  - test: AtlasDocumentationRealityRepairProposerTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:ai:architecture-validate --json"
requires_evidence: true
risk_level: critical
visual_tags:
  - system
  - self-healing
  - drift-repair
ai_entrypoints:
  - Leia este doc quando precisar transformar um drift doc<->codigo detectado em uma proposta de reparo conservadora.
ai_usage_notes:
  - Este e o primeiro incremento do P2; o propositor e read-only e nunca aplica reparo nem toca codigo.
  - A geracao bidirecional codigo<->doc e um incremento P2 posterior, nao implementado aqui.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:ai:architecture-validate --json"
failure_modes:
  - Propositor sugerir apagar ou editar codigo vivo (auto-reparo cego).
  - Propositor aplicar reparo automaticamente em vez de so propor.
  - Propositor re-detectar drift por conta propria em vez de reusar a ledger.
observability_signals:
  - drift_count
  - proposal_count
  - recommended_option_distribution
next_actions:
  - Usar este propositor como base do incremento P2 seguinte (reconciliacao bidirecional doc<->codigo), em doc filho proprio.
---
# Atlas Documentation Reality Generative Self-Healing

## Resumo

Este doc define o **primeiro incremento do pilar P2** (Gerativo/Auto-curativo) do
salto gerativo do ADRS. O sistema de maturidade ja **detecta** divergencia
doc<->codigo: um doc que declara um `implementation_state` mais alto do que o
indice de Code Intelligence consegue provar (um over-claim). Este incremento
comeca a **curar**: a partir de um drift ja detectado, ele **gera uma proposta de
reparo** concreta e conservadora.

```text
maturity ledger DETECTA drift  ->  ESTE propositor GERA proposta de reparo doc-side
```

Ele responde a uma pergunta unica:

```text
Dado um drift ja provado, qual e o reparo minimo e seguro, somente do lado do doc?
```

## Papel no Atlas

O salto gerativo do ADRS tem tres pilares (P1 Preditivo, P2 Gerativo, P3
Auto-imunizante). P1 (simulador preditivo) ja esta DONE. Este doc abre o **P2**
pelo incremento mais conservador possivel: um **propositor de reparo de
reconciliacao**. Ele nao re-descreve a deteccao de drift; ele consome o veredito
da maturity ledger e adiciona a camada que so existe acima dela — gerar a
sugestao de correcao.

A diferenca de categoria do pilar: o ciclo de hoje termina em **relatorio de
drift**; aqui ele comeca a terminar em **proposta de reparo**. Mas, neste
incremento, a proposta e estritamente doc-side e nunca aplicada.

## Onde Se Encaixa

```text
atlas-documentation-reality-system (mae, categoria imune)
  -> atlas-documentation-reality-generative-leap (salto gerativo, north-star dos 3 pilares)
     P1 Preditivo  -> simulador pre-write (DONE)
     P2 Gerativo   -> ESTE doc: primeiro incremento (propositor de reparo doc-side)
        - reusa a maturity ledger (AtlasAaeosImplementationTruthService::ledger)
        - gera proposta conservadora; nunca aplica; nunca toca codigo
     P3 Imunizante -> incremento posterior
```

Fronteira anti-duplicacao: a **deteccao** de drift continua sendo da maturity
ledger (doc-as-law / R4). Este doc nao reimplementa deteccao; ele define o
**propositor** que transforma um drift detectado em uma proposta de reparo.

## Contratos

| Campo | Valor |
|---|---|
| Nome canonico | Atlas Documentation Reality Generative Self-Healing |
| Tecnico | AtlasDocumentationRealityRepairProposerService |
| Comando | atlas:documentation-reality-repair-proposals |
| Doc-mae | atlas-documentation-reality-generative-leap (pilar P2) |
| Fonte do drift | AtlasAaeosImplementationTruthService::ledger() (reuso, nao reimplementacao) |
| Estado | partial (service + comando + teste resolvem no indice) |

Schema do envelope: `atlas.documentation_reality.repair_proposal.v1`.

Por linha de over-claim, a proposta declara:

- `owner_doc`, `claimed_state`, `computed_state`, `unmet_evidence`;
- `repair_options` (sempre duas, doc-side):
  - `downgrade_state` — baixar `implementation_state` para o `computed_state`
    que o codigo prova (reversivel);
  - `supply_evidence` — adicionar `evidence_refs` que resolvem para justificar o
    `claimed_state`, nomeando exatamente os refs que faltam (`missing`);
- `recommended` — `supply_evidence` quando ha evidencia faltante concreta e
  resolvivel, caso contrario `downgrade_state`;
- `safety` — `{read_only:true, auto_apply:false, proposes_code_change:false,
  proposes_deletion:false}`.

O envelope carrega um `proposal_hash`, um `summary` (`drift_count`,
`proposal_count`) e um `claim_policy` (`read_only:true`, `auto_applies:false`,
`proposes_code_mutation:false`).

Regra de autoridade (herdada do ADRS, inalterada): repo docs canonicos
> codigo/testes > Evidence Ledger > read models > Cartografia > chat.

## Fluxo

1. Chamar `proposeAll()` (corpus inteiro) ou `proposeForDoc(ownerDoc)` (um doc).
2. O propositor pede o veredito a maturity ledger.
3. Para cada linha de **over-claim** (`drift === true`), gera uma proposta com as
   duas opcoes doc-side.
4. Linhas limpas e under-claim sao ignoradas (under-claim nunca e "curado": subir
   uma claim seria um over-claim em potencial).
5. Retorna o envelope read-only com hash.
6. O comando imprime as propostas; nada e aplicado.
7. Para aplicar de fato, a mudanca segue os gates existentes + Evidence Ledger.

## Regras para IA

- NUNCA aplicar o reparo a partir deste propositor; ele so propoe.
- NUNCA propor opcao que apague ou edite codigo ou qualquer arquivo.
- NUNCA re-detectar drift aqui; o veredito vem da maturity ledger.
- NUNCA promover este incremento a geracao bidirecional sem doc filho proprio.
- O reparo, quando aplicado, passa pelos mesmos gates + Evidence Ledger.

## Escopo de Implementacao

Implementado neste incremento (resolve no indice):

- `AtlasDocumentationRealityRepairProposerService` — decider read-only com
  `proposeAll()` e `proposeForDoc()`.
- `atlas:documentation-reality-repair-proposals` — comando read-only que imprime
  as propostas (`--capability=`, `--json`).
- `AtlasDocumentationRealityRepairProposerTest` — gates de determinismo.

Fora de escopo (incremento P2 posterior, doc filho proprio):

- Reconciliacao **bidirecional** codigo<->doc (regenerar codigo a partir do spec
  do doc canonico).
- Qualquer aplicacao automatica de reparo.

## Dependencias

- atlas-documentation-reality-generative-leap (pilar P2, doc-mae).
- atlas-aaeos-documentation-as-law-proposal (maturity ledger = fonte do drift).
- atlas-documentation-reality-system (doc-mae do ADRS, categoria imune).

## Evidencias

Este incremento e `partial`: o service, o comando e o teste resolvem no indice de
Code Intelligence via `evidence_refs`. Promocao a `verified` exige tambem teste e
receipt resolvendo, exatamente como qualquer bloco do Atlas.

## Riscos

- **Auto-reparo cego (o risco nomeado no doc-mae):** uma proposta que apague ou
  edite codigo vivo por falta de prova. Mitigacao: este propositor e o **lado
  conservador** — somente do lado do doc; nenhuma opcao de reparo toca, apaga ou
  edita codigo ou qualquer arquivo, e nada e aplicado automaticamente. A correcao
  real passa pelos gates existentes + Evidence Ledger.
- **Aplicacao automatica:** o propositor aplicar em vez de so propor. Mitigacao:
  `claim_policy.auto_applies` e sempre `false`; o decider e read-only e nao tem
  caminho de escrita nem de exec.
- **Segunda fonte de drift:** re-detectar divergencia aqui. Mitigacao: o veredito
  e sempre reusado de `AtlasAaeosImplementationTruthService::ledger()`.
- **Sprawl/over-reach P2:** confundir este incremento conservador com a geracao
  bidirecional. Mitigacao: a geracao codigo<->doc e explicitamente um incremento
  posterior, em doc filho proprio com gate.

## Exemplos

```text
- doc declara verified, indice prova spec, evidence_refs nao resolvem
    -> proposta: downgrade_state (para spec) OU supply_evidence (nomeando os refs)
    -> recommended: supply_evidence (ha ref nomeado e resolvivel a suprir)
- ledger sem drift
    -> zero propostas; nada a reconciliar
- nenhuma proposta jamais sugere apagar/editar codigo; safety.proposes_deletion = false
```

## Proximas Acoes

- Usar este propositor como base do incremento P2 seguinte: reconciliacao
  bidirecional doc<->codigo, em doc filho proprio com gate e drift zero.
- Manter o propositor read-only; qualquer aplicacao continua passando pelos gates
  existentes + Evidence Ledger.
