---
id: atlas-documentation-reality-code-contract-proposals
type: engineering_knowledge
title: Atlas Documentation Reality Code Contract Proposals
status: active
category: documentation-governance
priority: 96
summary: Terceiro incremento do pilar P2 (Gerativo/Auto-curativo) do salto gerativo do ADRS — a direcao doc-AHEAD-of-code, a forma SEGURA do incremento "code-from-spec". Quando um doc DECLARA evidence_refs que nomeiam codigo (symbol/command/test/route/receipt) que NAO resolve no indice, o doc nomeou codigo que ainda nao existe. PROPOE o contrato para construi-lo (assinatura-esqueleto + outline de teste) para que um humano faca o CODIGO alcancar o doc. Read-only e propositor: NUNCA gera nem escreve codigo, nunca escreve arquivo, nunca auto-aplica.
human_name: Contrato de Codigo (Propositor doc-ahead-of-code)
canonical_name: Atlas Documentation Reality Code Contract Proposals
technical_name: AtlasDocumentationRealityCodeContractProposerService
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-documentation-reality-code-contract-proposals.md
tags:
  - atlas-ai
  - documentation-governance
  - adrs
  - generative
  - code-from-spec
  - doc-ahead-of-code
capabilities:
  - documentation_reality_code_contract_proposal
  - doc_ahead_of_code_gap_detection
  - code_skeleton_signature_description
  - verifying_test_outline_synthesis
  - read_only_contract_proposer
decisions:
  - Nome canonico/produto obrigatorio: Atlas Documentation Reality Code Contract Proposals.
  - Tecnico obrigatorio: AtlasDocumentationRealityCodeContractProposerService.
  - Este doc e o terceiro incremento do pilar P2 do atlas-documentation-reality-generative-leap; nao e uma segunda fonte canonica.
  - Completa o triangulo de reconciliacao P2 — over-claim (rebaixa o doc) + under-claim (eleva o doc) + esta direcao (constroi o codigo).
  - Regra absoluta: cada item de contrato e uma DESCRICAO (is_code=false, must_be_implemented_by_human=true); o teste verificador e um OUTLINE, nunca codigo de teste real.
  - O propositor NUNCA gera nem escreve codigo executavel — a geracao code-from-spec em disco e a forma arriscada deliberadamente NAO construida.
  - O propositor so propoe refs que o DOC declarou; nunca inventa uma ref.
  - O propositor respeita um doc que rotulou A SI MESMO como nao-runtime (status template/source_material/future/source); descrever um futuro nao e over-claim de um gap presente.
  - Degrade-SAFE: indice de Code Intelligence presente-mas-vazio faz toda ref parecer nao-resolvida; o propositor retem (degraded) em vez de fabricar um plano "construa todo este codigo".
maintenance:
  - Manter abaixo de 520 linhas.
  - Revisar quando o AtlasAaeosImplementationTruthService, o resolver de evidencia, o ADRS ou o doc-mae do salto gerativo mudarem.
  - Rodar docs-health, docs-authority-audit e architecture-validate apos alteracoes.
related_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-generative-leap.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-generative-self-healing.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-bidirectional-reconciliation.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-self-immunizing-antibody.md
  - docs/engineering-knowledge-base/atlas-aaeos-documentation-as-law-proposal.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-system.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-documentation-reality-code-contract-proposals
graph_title: Atlas Documentation Reality Code Contract Proposals
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-documentation-reality-generative-leap
graph_status: active
graph_source: repo
owner: documentation-governance
implementation_state: partial_runtime_with_future_scope
repo_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-code-contract-proposals.md
  - app/Services/Engineering/AtlasDocumentationRealityCodeContractProposerService.php
  - app/Console/Commands/AtlasDocumentationRealityCodeContractProposalsCommand.php
  - tests/Feature/Engineering/AtlasDocumentationRealityCodeContractProposalsTest.php
allowed_changes:
  - Refinar a descricao de assinatura, o outline de teste verificador e o envelope read-only.
  - Promover a auto-geracao real de codigo a partir do spec em doc filho proprio com gate e drift zero — mas SEMPRE como forma separada e arriscada, nunca aqui.
forbidden_changes:
  - Criar nova fonte de verdade de gap; o gap vem do indice de Code Intelligence via evidence_refs declaradas.
  - Emitir um item de contrato com is_code diferente de false, ou com codigo executavel, arquivo ou caminho-para-escrever.
  - Inventar uma ref que o doc nao declarou.
  - Gerar, escrever ou auto-aplicar qualquer codigo.
  - Declarar runtime sem evidencia que resolve no indice.
depends_on:
  - atlas-documentation-reality-generative-leap
  - atlas-aaeos-documentation-as-law-proposal
  - atlas-documentation-reality-system
flows_to:
  - atlas-documentation-reality-generative-leap
  - atlas-documentation-reality-bidirectional-reconciliation
  - atlas-documentation-reality-system
unlocks:
  - doc_ahead_of_code_contract
  - p2_reconciliation_triangle_complete
governs:
  - documentation-governance-code-from-spec-safe
evidence:
  - docs/engineering-knowledge-base/atlas-documentation-reality-code-contract-proposals.md
evidence_refs:
  - symbol: AtlasDocumentationRealityCodeContractProposerService
  - command: atlas:documentation-reality-code-contract-proposals
  - test: AtlasDocumentationRealityCodeContractProposalsTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:ai:architecture-validate --json"
requires_evidence: true
risk_level: critical
visual_tags:
  - system
  - generative
  - code-from-spec
ai_entrypoints:
  - Leia este doc quando um doc nomear codigo (evidence_refs) que o indice nao resolve e voce precisar propor o contrato para construi-lo.
ai_usage_notes:
  - Este e o terceiro incremento do P2 (doc-ahead-of-code); o propositor e read-only e NUNCA gera nem escreve codigo.
  - Todo item de contrato e uma DESCRICAO (is_code=false, must_be_implemented_by_human=true); o teste e um OUTLINE, nunca codigo de teste real.
  - So refs que o doc declarou viram item; nada e inventado. Docs nao-runtime (status template/source_material/future/source) sao pulados.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:ai:architecture-validate --json"
failure_modes:
  - Propositor gerar ou escrever codigo executavel (deveria so descrever).
  - Propositor emitir um item com is_code=true ou com campo de codigo/arquivo/caminho (viola o guard estrutural).
  - Propositor inventar uma ref que o doc nao declarou.
  - Propositor mirar um doc verified (sem gap) ou um doc que se rotulou nao-runtime.
observability_signals:
  - docs_with_gaps
  - total_unresolved_refs
  - degraded
next_actions:
  - Manter o propositor read-only; a auto-geracao real de codigo a partir do spec continua sendo forma separada e arriscada, nunca implementada aqui.
  - Usar o triangulo P2 completo (over-claim + under-claim + doc-ahead-of-code) como base do auto-reparo governado, sempre via gates + Evidence Ledger.
---
# Atlas Documentation Reality Code Contract Proposals

## Resumo

Este doc define o **terceiro incremento do pilar P2** (Gerativo/Auto-curativo) do
salto gerativo do ADRS: a direcao **doc-AHEAD-of-code**, a **forma SEGURA** do
incremento documentado "code-from-spec". As duas primeiras direcoes do P2 ja estao
DONE e sao ambas DOC-side:

```text
over-claim  : o doc afirma MAIS do que o codigo prova   -> rebaixa o doc / supre evidencia
under-claim : o codigo resolve MAIS do que o doc afirma -> eleva o doc para a realidade
doc-ahead   : ESTE doc — o doc NOMEIA codigo que nao existe -> propoe construir o CODIGO
```

Um doc e **LAW**. Quando um doc DECLARA `evidence_refs` que nomeiam codigo
especifico — um `symbol`/`command`/`test`/`route`/`receipt` — que **nao resolve no
indice**, o doc nomeou codigo que **ainda nao existe**. O inverso da direcao
under-claim (que eleva o DOC) e propor construir o CODIGO que falta, para que um
humano faca o **codigo alcancar o doc**. Este servico emite o **CONTRATO** para
construi-lo: uma DESCRICAO de assinatura-esqueleto + um OUTLINE de teste.

Com este incremento, o **triangulo de reconciliacao do P2 fica completo**:
over-claim + under-claim + doc-ahead-of-code.

## Papel no Atlas

O salto gerativo do ADRS tem tres pilares (P1 Preditivo, P2 Gerativo, P3
Auto-imunizante). Dentro do **P2**, ha tres direcoes de reconciliacao doc<->codigo;
este doc abre a ultima — doc-ahead-of-code — pela forma mais conservadora possivel:
um **propositor de contrato**, nunca um gerador.

A diferenca de categoria: a geracao code-from-spec **cega** (escrever codigo
executavel a partir do spec, em disco) e justamente o **risco nomeado** do salto
gerativo (`atlas-documentation-reality-generative-leap.md`: "auto-reparo cego"). Por
isso a forma arriscada e **deliberadamente NAO construida**; este incremento entrega
apenas a **PROPOSTA** — uma descricao do que construir — e um guard estrutural em
codigo torna uma emissao insegura impossivel.

## Onde Se Encaixa

```text
atlas-documentation-reality-system (mae, categoria imune)
  -> atlas-documentation-reality-generative-leap (salto gerativo, north-star dos 3 pilares)
     P2 Gerativo/Auto-curativo:
        - over-claim  -> AtlasDocumentationRealityRepairProposerService (DONE)
        - under-claim -> AtlasDocumentationRealityBidirectionalReconciliationService (DONE)
        - doc-ahead   -> ESTE doc: AtlasDocumentationRealityCodeContractProposerService
             - le o ledger de verdade (AtlasAaeosImplementationTruthService::ledger)
             - acha refs DECLARADAS-mas-nao-resolvidas
             - propoe o contrato {assinatura + outline de teste}; nunca gera codigo
```

Fronteira anti-duplicacao: a **deteccao** de resolucao de ref continua sendo do
`AtlasAaeosImplementationTruthService` + `AtlasAaeosImplementationEvidenceResolver`
(o ledger ja resolve cada ref contra o indice). Este doc nao reimplementa resolucao;
ele define o **propositor** que transforma uma ref declarada-mas-nao-resolvida em uma
proposta de contrato de codigo.

## Contratos

| Campo | Valor |
|---|---|
| Nome canonico | Atlas Documentation Reality Code Contract Proposals |
| Tecnico | AtlasDocumentationRealityCodeContractProposerService |
| Comando | atlas:documentation-reality-code-contract-proposals |
| Doc-mae | atlas-documentation-reality-generative-leap (pilar P2) |
| Fonte do gap | AtlasAaeosImplementationTruthService::ledger (reuso, nao reimplementacao) |
| Estado | partial (service + comando + teste resolvem no indice) |

Schema do envelope: `atlas.documentation_reality.code_contract.v1`.

Metodos:

- `proposeAll()` — varre o ledger e propoe um contrato por doc que qualifica;
- `proposeForDoc(string $ownerDoc)` — restrito a um owner doc (id/slug ou substring
  de path), igual ao filtro do ledger de maturidade.

### Populacao-alvo (precisa, para impedir over-reach)

Um doc qualifica **SOMENTE** quando TODAS valem:

1. e **runtime-CLAIMING**: `implementation_state` normalizado em {spec, partial}
   (NUNCA verified — verified nao tem gap) **E** o `status` NAO e um auto-rotulo
   nao-runtime (template/source_material/future/source);
2. DECLAROU **>=1** `evidence_ref`;
3. **>=1** ref DECLARADA **nao resolve** no indice.

Para cada ref declarada-mas-nao-resolvida `{kind, ref}`, um item de contrato:

- `symbol`  -> DESCRICAO de assinatura de classe/metodo proposta;
- `command` -> DESCRICAO de assinatura artisan proposta;
- `route`   -> DESCRICAO de rota;
- `test`    -> OUTLINE de teste given/when/then (`must_fail_before_implemented:true`);
- `receipt` -> nota de que um receipt genuino deve ser **PRODUZIDO por um run real**
  (nunca escrito a mao).

Cada item carrega: `{kind, ref, contract (descricao string), is_code:false,
must_be_implemented_by_human:true}`. `recommended_order`: symbol -> command/route ->
test -> receipt. **NUNCA** inventa uma ref que o doc nao declarou. **NUNCA** emite
codigo executavel.

O envelope carrega um `contract_hash`, um `summary`
(`docs_with_gaps`, `total_unresolved_refs`, `by_kind`) e um `claim_policy`:
`{read_only:true, generates_code:false, writes:false, auto_applies:false,
proposes_contract_only:true, only_doc_declared_refs:true}`.

Regra de autoridade (herdada do ADRS, inalterada): repo docs canonicos
> codigo/testes > Evidence Ledger > read models > Cartografia > chat.

## Fluxo

1. Chamar `proposeAll()` (corpus inteiro) ou `proposeForDoc(ownerDoc)`.
2. Guard de indice cego: se o indice de Code Intelligence esta presente-mas-vazio,
   **degrada** (`code_intelligence_index_empty_or_absent_contracts_withheld`) — toda
   ref pareceria nao-resolvida.
3. Para cada linha do ledger, aplica a populacao-alvo (claiming state + status
   runtime + >=1 ref declarada-mas-nao-resolvida).
4. Para cada ref declarada-mas-nao-resolvida, sintetiza UM item de contrato (descricao
   por kind); o teste e sempre um OUTLINE.
5. Cada item passa pelo **guard estrutural** antes de ser emitido: `is_code` deve ser
   estritamente `false`, `must_be_implemented_by_human` estritamente `true`, e nenhum
   campo de codigo/arquivo/caminho-para-escrever pode estar presente — senao lanca
   `LogicException` e a emissao insegura e estruturalmente impossivel.
6. Retorna o envelope read-only com hash.
7. O comando imprime os contratos; nada e gerado, escrito ou aplicado.
8. Para fechar o gap de fato, um humano implementa cada item (symbol -> command/route
   -> test -> receipt) pelos gates existentes + Evidence Ledger; o receipt e
   PRODUZIDO por um run real.

## Regras para IA

- NUNCA gerar nem escrever codigo executavel a partir deste propositor; ele so propoe.
- NUNCA emitir um item com `is_code` diferente de `false` ou com codigo/arquivo/caminho.
- NUNCA inventar uma ref que o doc nao declarou.
- NUNCA mirar um doc verified (sem gap) nem um doc que se rotulou nao-runtime.
- NUNCA auto-aplicar; o codigo real e trabalho de um humano pelos gates + Evidence Ledger.
- O receipt genuino e PRODUZIDO por um run real; nunca escrito a mao para enganar o indice.

## Escopo de Implementacao

Implementado neste incremento (resolve no indice):

- `AtlasDocumentationRealityCodeContractProposerService` — decider read-only com
  `proposeAll()` e `proposeForDoc()`, guard estrutural anti-codigo e degrade de indice cego.
- `atlas:documentation-reality-code-contract-proposals` — comando read-only que imprime
  os contratos (`--capability=`, `--json`).
- `AtlasDocumentationRealityCodeContractProposalsTest` — gates de determinismo.

Fora de escopo (forma separada e arriscada, nunca aqui):

- **Auto-geracao real de codigo a partir do spec** (escrever o symbol/command/test
  reais em disco). Esta e a forma **deliberadamente NAO construida**; este incremento
  entrega apenas a PROPOSTA. A escrita real continua sendo trabalho de um humano/gate.
- Qualquer auto-aplicacao do contrato.

## Dependencias

- atlas-documentation-reality-generative-leap (pilar P2, doc-mae).
- atlas-aaeos-documentation-as-law-proposal (ledger de verdade + resolver de evidencia).
- atlas-documentation-reality-system (doc-mae do ADRS, categoria imune).

## Evidencias

Este incremento e `partial`: o service, o comando e o teste resolvem no indice de
Code Intelligence via `evidence_refs`. Promocao a `verified` exige tambem teste e
receipt resolvendo, exatamente como qualquer bloco do Atlas. Honestidade: rodado ao
vivo, o corpus atual reporta `docs_with_gaps: 0` (a missao anterior doc->runtime
levou o corpus a drift=0 com rotulagem honesta), entao a populacao presente e
legitimamente pequena; o valor e a **completude estrutural do triangulo** e capturar
qualquer gap doc-ahead-of-code futuro no instante em que surgir.

## Riscos

- **Geracao de codigo cega (o risco central desta direcao):** o propositor gerar ou
  escrever codigo executavel em vez de so descrever. Mitigacao: este e o **lado
  propositor** — `claim_policy.generates_code` e sempre `false`, todo item e
  `is_code=false` + `must_be_implemented_by_human=true`, e um **guard estrutural** em
  codigo lanca `LogicException` se qualquer item tivesse `is_code!==false` ou um campo
  de codigo/arquivo/caminho-para-escrever. **A geracao code-from-spec real e a forma
  arriscada deliberadamente NAO construida.**
- **Auto-aplicacao:** aplicar o contrato sem humano. Mitigacao:
  `claim_policy.auto_applies=false` e nao ha caminho de escrita/exec.
- **Ref inventada:** propor codigo que o doc nao nomeou. Mitigacao:
  `only_doc_declared_refs=true` — cada item e chaveado a uma ref que o DOC declarou e
  que o indice nao resolveu; nada e inventado.
- **Over-reach na populacao:** mirar um doc sem gap. Mitigacao: docs verified sao
  excluidos (sem gap), e docs que se rotularam nao-runtime (status
  template/source_material/future/source) sao **respeitados e pulados** — descrever um
  futuro nao e over-claim de um presente.
- **Gap em massa por indice cego:** indice presente-mas-vazio faz toda ref parecer
  nao-resolvida. Mitigacao: degrade-SAFE — retem tudo (`degraded=true`,
  `proposals=[]`) em vez de fabricar um plano "construa todo este codigo".

## Exemplos

```text
- doc partial declara test: AtlasFooTest, que NAO resolve no indice
    -> item de contrato kind=test com OUTLINE given/when/then (RED antes de existir),
       is_code=false, must_be_implemented_by_human=true; nada gerado/escrito
- doc cujas refs declaradas TODAS resolvem
    -> nenhum item; sem gap (sem falso-gap)
- doc com status source_material e refs nao-resolvidas
    -> pulado (auto-rotulo nao-runtime respeitado)
- doc verified com ref nao-resolvida solta
    -> nunca mirado (verified nao tem gap)
- indice presente-mas-vazio
    -> degraded:true, proposals:[]; nada fabricado
```

## Proximas Acoes

- Manter o propositor read-only; a auto-geracao real de codigo a partir do spec
  continua sendo forma separada e arriscada, nunca implementada aqui.
- Usar o triangulo P2 completo (over-claim + under-claim + doc-ahead-of-code) como
  base do auto-reparo governado, sempre pelos gates existentes + Evidence Ledger.
