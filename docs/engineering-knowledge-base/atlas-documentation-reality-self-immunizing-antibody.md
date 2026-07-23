---
id: atlas-documentation-reality-self-immunizing-antibody
type: engineering_knowledge
title: Atlas Documentation Reality Self-Immunizing Antibody
status: active
category: documentation-governance
priority: 97
summary: Primeiro incremento do pilar P3 (Auto-imunizante, L7) do salto gerativo do ADRS. A partir de uma falha que ESCAPOU (capturada como AtlasDevFailureCapsule), PROPOE um anticorpo — o detector que a torna impossivel de repetir — sempre acompanhado de um outline de teste que reproduz a falha original. Read-only e propositor: nunca cria gate, nunca escreve, nunca executa, nunca instala enforcement.
human_name: Anticorpo Auto-imunizante (Propositor)
canonical_name: Atlas Documentation Reality Self-Immunizing Antibody
technical_name: AtlasDocumentationRealityAntibodyProposerService
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-documentation-reality-self-immunizing-antibody.md
tags:
  - atlas-ai
  - documentation-governance
  - adrs
  - self-immunizing
  - antibody
  - escaped-failure
capabilities:
  - documentation_reality_antibody_proposal
  - escaped_failure_to_antibody
  - reproducing_test_outline_synthesis
  - read_only_detector_proposer
decisions:
  - Nome canonico/produto obrigatorio: Atlas Documentation Reality Self-Immunizing Antibody.
  - Tecnico obrigatorio: AtlasDocumentationRealityAntibodyProposerService.
  - Este doc e o primeiro incremento do pilar P3 do atlas-documentation-reality-generative-leap; nao e uma segunda fonte canonica.
  - Regra absoluta: cada anticorpo PRECISA de um reproducing_test_outline; anticorpo sem ele e invalido e nunca e emitido.
  - O propositor NAO detecta falhas; ele CONSOME registros de falha que escaparam (AtlasDevFailureCapsule ou um plain {kind, summary, location, detail}).
  - O propositor e read-only; nunca cria gate, nunca escreve arquivo, nunca executa, nunca instala enforcement, nunca gera codigo em disco.
  - A auto-sintese do codigo runtime do proprio gate e um incremento P3 posterior, fora deste doc.
maintenance:
  - Manter abaixo de 520 linhas.
  - Revisar quando o AtlasDevFailureCapsule, o ADRS ou o doc-mae do salto gerativo mudarem.
  - Rodar docs-health, docs-authority-audit e architecture-validate apos alteracoes.
related_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-generative-leap.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-generative-self-healing.md
  - docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-system.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-documentation-reality-self-immunizing-antibody
graph_title: Atlas Documentation Reality Self-Immunizing Antibody
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-documentation-reality-generative-leap
graph_status: active
graph_source: repo
owner: documentation-governance
implementation_state: partial_runtime_with_future_scope
repo_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-self-immunizing-antibody.md
  - app/Services/Engineering/AtlasDocumentationRealityAntibodyProposerService.php
  - app/Console/Commands/AtlasDocumentationRealityAntibodyProposalsCommand.php
  - tests/Feature/Engineering/AtlasDocumentationRealityAntibodyProposerTest.php
allowed_changes:
  - Refinar o outline de teste reprodutor, o spec do detector e o envelope read-only.
  - Promover incrementos P3 posteriores (auto-sintese do gate) em docs filhos proprios com gate e drift zero.
forbidden_changes:
  - Criar nova fonte de verdade de falha; o anticorpo vem de registros de falha que escaparam.
  - Emitir um anticorpo sem reproducing_test_outline.
  - Adicionar campo/opcao que crie gate, escreva arquivo, execute, instale enforcement ou gere codigo.
  - Declarar runtime sem evidencia que resolve no indice.
depends_on:
  - atlas-documentation-reality-generative-leap
  - atlas-self-improvement-governance-ladder
  - atlas-documentation-reality-system
flows_to:
  - atlas-documentation-reality-generative-leap
  - atlas-documentation-reality-system
unlocks:
  - antifragile_immune_system
  - escaped_failure_immunization
governs:
  - documentation-governance-self-immunizing
evidence:
  - docs/engineering-knowledge-base/atlas-documentation-reality-self-immunizing-antibody.md
evidence_refs:
  - symbol: AtlasDocumentationRealityAntibodyProposerService
  - command: atlas:documentation-reality-antibody-proposals
  - test: AtlasDocumentationRealityAntibodyProposerTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:ai:architecture-validate --json"
requires_evidence: true
risk_level: critical
visual_tags:
  - system
  - self-immunizing
  - antibody
ai_entrypoints:
  - Leia este doc quando precisar transformar uma falha que escapou em uma proposta de anticorpo (detector + teste reprodutor).
ai_usage_notes:
  - Este e o primeiro incremento do P3; o propositor e read-only e nunca cria gate, nunca escreve, nunca executa.
  - Todo anticorpo carrega um reproducing_test_outline; sem ele a proposta e invalida e nunca e emitida.
  - A auto-sintese do codigo runtime do gate e um incremento P3 posterior, nao implementado aqui.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:ai:architecture-validate --json"
failure_modes:
  - Propositor criar/escrever um gate, teste ou arquivo (deveria so propor).
  - Propositor emitir um anticorpo sem teste reprodutor (viola a regra absoluta).
  - Propositor fabricar uma falha quando nao ha registro de escape (deveria degradar).
observability_signals:
  - failure_count
  - antibody_count
  - degraded
next_actions:
  - Usar este propositor como base do incremento P3 seguinte: auto-sintese do codigo do gate, em doc filho proprio com gate e drift zero.
---
# Atlas Documentation Reality Self-Immunizing Antibody

## Resumo

Este doc define o **primeiro incremento do pilar P3** (Auto-imunizante, L7) do
salto gerativo do ADRS. P1 (simulador preditivo) e P2 (propositor de auto-cura)
ja estao DONE; P3 e o ultimo pilar L1 da escada. Quando um rot **escapa** — uma
falha que passou PELOS gates existentes e foi capturada como
`AtlasDevFailureCapsule` — este incremento sintetiza o **anticorpo** que a torna
impossivel de repetir: o outline do detector que, se existisse, teria pego o
escape.

```text
falha que escapou (AtlasDevFailureCapsule)  ->  ESTE propositor PROPOE um anticorpo
```

Ele responde a uma pergunta unica:

```text
Dada uma falha que ja escapou, qual e o detector que a torna impossivel de
repetir — e qual teste reproduz a falha original ANTES de o detector virar gate?
```

## Papel no Atlas

O salto gerativo do ADRS tem tres pilares (P1 Preditivo, P2 Gerativo, P3
Auto-imunizante). Este doc abre o **P3** pelo incremento mais conservador: um
**propositor de anticorpos**. Ele nao re-descreve a captura de falhas; ele
consome o registro de escape (`AtlasDevFailureCapsule`) e adiciona a camada que
so existe acima dela — propor o detector e o teste reprodutor.

A diferenca de categoria do pilar: o ciclo de hoje termina em **relatorio de
falha**; aqui ele comeca a terminar em **imunizacao**. Mas, neste incremento, a
imunizacao e estritamente uma **proposta** e nunca e instalada.

Heranca do Self-Improvement Governance Ladder (a semente de P3): um anticorpo e
uma **proposta** que aguarda humano/gate, exatamente como um Proposal Packet
nunca vira Obra automatica. O propositor nunca pula o gate.

## Onde Se Encaixa

```text
atlas-documentation-reality-system (mae, categoria imune)
  -> atlas-documentation-reality-generative-leap (salto gerativo, north-star dos 3 pilares)
     P1 Preditivo  -> simulador pre-write (DONE)
     P2 Gerativo   -> propositor de reparo doc-side (DONE)
     P3 Imunizante -> ESTE doc: primeiro incremento (propositor de anticorpos)
        - consome AtlasDevFailureCapsule (a falha que escapou)
        - propoe anticorpo {teste reprodutor + detector}; nunca cria gate; nunca escreve
```

Fronteira anti-duplicacao: a **captura** da falha continua sendo do runtime de
Dev (`DevFailureCapsuleRuntimeService` -> `AtlasDevFailureCapsule`; nomes do
cluster Dev conforme `docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md`).
Este doc nao reimplementa captura; ele define o **propositor** que transforma uma
falha capturada em uma proposta de anticorpo.

## Contratos

| Campo | Valor |
|---|---|
| Nome canonico | Atlas Documentation Reality Self-Immunizing Antibody |
| Tecnico | AtlasDocumentationRealityAntibodyProposerService |
| Comando | atlas:documentation-reality-antibody-proposals |
| Doc-mae | atlas-documentation-reality-generative-leap (pilar P3) |
| Fonte da falha | AtlasDevFailureCapsule (reuso, nao reimplementacao) |
| Estado | partial (service + comando + teste resolvem no indice) |

Schema do envelope: `atlas.documentation_reality.antibody.v1`.

Metodos:

- `proposeFromCapsule(array $failure)` — uma falha (shape do `AtlasDevFailureCapsule`
  OU plain `{kind, summary, location, detail}`) -> UM anticorpo;
- `proposeRecent(int $limit = 20)` — le as capsulas de falha mais recentes
  escaladas (`escalate_to_forge=true`) e propoe um anticorpo por falha; se a
  tabela esta ausente/vazia,
  **degrada** (`degraded:true`, `antibodies:[]`, `reason: no_failure_capsules`),
  nunca fabrica falhas.

Cada anticorpo declara:

- `failure_ref`, `failure_kind`;
- `reproducing_test_outline` (SEMPRE presente, nunca vazio):
  `{description, given_when_then, arrange_act_assert, steps,
  target_test_path_suggestion, must_fail_before_fix:true}`;
- `proposed_detector` (o SPEC do gate, nunca o gate instalado):
  `{description, kind (gate_check|frontmatter_rule|drift_rule|static_scan), where
  (a camada/comando/serviço onde plugaria), example_assertion}`;
- `plug_in_point`;
- `status: proposed_requires_human_review`.

O envelope carrega um `antibody_hash`, um `summary` (`failure_count`,
`antibody_count`) e um `claim_policy`:
`{read_only:true, auto_creates_gate:false, writes:false, executes:false,
installs_enforcement:false, generates_code:false, requires_reproducing_test:true,
goes_through_gates:true}`.

Regra de autoridade (herdada do ADRS, inalterada): repo docs canonicos
> codigo/testes > Evidence Ledger > read models > Cartografia > chat.

## Fluxo

1. Chamar `proposeFromCapsule(failure)` (uma falha) ou `proposeRecent(limit)`
   (corpus recente de escapes).
2. O propositor normaliza a falha (capsule -> kind/summary/location/detail).
3. Para cada falha, sintetiza UM anticorpo:
   - primeiro o `reproducing_test_outline` (incondicional — a regra absoluta);
   - depois o `proposed_detector` (spec), mapeando o `failure_kind` para o tipo
     de detector e o ponto de plug-in.
4. Se `proposeRecent` nao encontra capsulas, degrada (`no_failure_capsules`);
   nunca inventa falha.
5. Retorna o envelope read-only com hash.
6. O comando imprime os anticorpos; nada e criado, escrito ou instalado.
7. Para imunizar de fato, um humano/gate escreve o **teste reprodutor PRIMEIRO**,
   confirma que ele falha no sistema sem patch, e so entao adiciona o detector —
   tudo pelos gates existentes + Evidence Ledger.

## Regras para IA

- NUNCA criar/escrever um gate, teste ou arquivo a partir deste propositor; ele so propoe.
- NUNCA emitir um anticorpo sem `reproducing_test_outline`; sem ele a proposta e invalida.
- NUNCA executar nada nem instalar enforcement; o decider e read-only.
- NUNCA fabricar uma falha quando nao ha registro de escape; degradar.
- NUNCA re-detectar/capturar falha aqui; o registro vem do runtime de Dev.
- O anticorpo, quando aplicado, passa pelos mesmos gates + Evidence Ledger, e o
  teste reprodutor e escrito ANTES do gate.

## Escopo de Implementacao

Implementado neste incremento (resolve no indice):

- `AtlasDocumentationRealityAntibodyProposerService` — decider read-only com
  `proposeFromCapsule()` e `proposeRecent()`.
- `atlas:documentation-reality-antibody-proposals` — comando read-only que imprime
  os anticorpos (`--limit=`, `--json`).
- `AtlasDocumentationRealityAntibodyProposerTest` — gates de determinismo.

Fora de escopo (incremento P3 posterior, doc filho proprio):

- **Auto-sintese do codigo runtime do gate** (gerar o detector/teste reais em
  disco). Este incremento entrega apenas a PROPOSTA; a escrita real continua sendo
  trabalho de um humano/gate pelos gates existentes.
- Qualquer instalacao automatica de enforcement.

## Dependencias

- atlas-documentation-reality-generative-leap (pilar P3, doc-mae).
- atlas-self-improvement-governance-ladder (semente de P3; anticorpo = proposta governada).
- atlas-documentation-reality-system (doc-mae do ADRS, categoria imune).

## Evidencias

Este incremento e `partial`: o service, o comando e o teste resolvem no indice de
Code Intelligence via `evidence_refs`. Promocao a `verified` exige tambem teste e
receipt resolvendo, exatamente como qualquer bloco do Atlas.

## Riscos

- **Auto-instalacao de gate (o risco central deste pilar):** o propositor criar ou
  escrever o gate em vez de so propor. Mitigacao: este e o **lado propositor** —
  `claim_policy.auto_creates_gate` e sempre `false`, e o decider nao tem caminho de
  escrita, exec ou geracao de codigo. **Nunca cria gate automaticamente.** A
  auto-sintese do proprio codigo do gate e explicitamente um incremento posterior.
- **Anticorpo sem teste reprodutor:** emitir um anticorpo invalido. Mitigacao: a
  **regra absoluta** — todo anticorpo carrega um `reproducing_test_outline`
  nao-vazio; o service o constroi incondicionalmente e tem um invariante em codigo
  que falha alto se o outline vier vazio. **Um anticorpo e invalido sem um teste
  que reproduz a falha original.**
- **Fabricacao de falha:** inventar um escape para ter o que imunizar. Mitigacao:
  `proposeRecent` degrada (`no_failure_capsules`) quando nao ha capsula; nunca
  fabrica falhas.
- **Sprawl/over-reach P3:** confundir este incremento propositor com a
  auto-sintese do gate. Mitigacao: **este e o incremento propositor**; a
  auto-sintese do codigo real do gate e explicitamente um incremento posterior, em
  doc filho proprio com gate.

## Exemplos

```text
- falha que escapou: "predictive simulator returned clean on a degraded index"
    -> anticorpo: reproducing_test_outline (RED antes do fix) + proposed_detector
       (drift_rule no atlas:aeos:maturity) + status proposed_requires_human_review
    -> nada criado/escrito/instalado; humano escreve o teste reprodutor primeiro
- proposeRecent sem tabela de capsulas
    -> degraded:true, antibodies:[], reason no_failure_capsules; nada fabricado
- todo anticorpo sempre carrega um reproducing_test_outline; sem ele nunca e emitido
```

## Proximas Acoes

- Usar este propositor como base do incremento P3 seguinte: auto-sintese do codigo
  do gate (o detector/teste reais), em doc filho proprio com gate e drift zero.
- Manter o propositor read-only; qualquer instalacao de gate continua passando
  pelos gates existentes + Evidence Ledger, com o teste reprodutor escrito ANTES.
