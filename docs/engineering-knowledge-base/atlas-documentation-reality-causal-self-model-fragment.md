---
id: atlas-documentation-reality-causal-self-model-fragment
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Documentation Reality Causal Self Model Fragment
slug: atlas-documentation-reality-causal-self-model-fragment
status: active
category: documentation-governance
priority: 97
summary: Um fragmento mensuravel (R1 auto-modelo causal) promovido da assintota L-inf do ADRS — NAO a assintota. Um auto-modelo causal read-only e consultavel que, por capability, monta a cadeia intencao->verdade->resultado->porque compondo os sinais reais da escada (ledger de verdade, grade O1, reconciliacao). CADA elo causal carrega incerteza calibrada e distingue prova de inferencia; nunca fabrica uma causa; nunca declara L-inf concluido. A doc-mae reflexiva continua north-star.
human_summary: O topo da escada (L-inf) e direcao, nunca sprint. Aqui promovemos so MAIS UM pedaco mensuravel dele — o auto-modelo causal — virando codigo. Para uma capability ele responde quatro coisas a partir de sinais reais: o que o doc DIZ que e (intencao), o que o codigo RESOLVE (verdade), o que RESULTOU no mundo (hoje honestamente sem sinal) e POR QUE o estado e esse. Cada "porque" vem com a incerteza declarada e diz se e prova ou inferencia. Uma correlacao inferida nunca e apresentada como causa provada. O resto do L-inf segue como norte.
human_what: Auto-modelo causal R1 read-only - por capability, a cadeia intencao->verdade->resultado->porque composta dos sinais reais, com incerteza calibrada e prova-vs-inferencia em cada elo.
human_purpose: Tornar o Atlas consultavel sobre SI mesmo de forma causal (o que e/foi-intencao/resultou e por que) sem nunca cair no drift supremo - toda afirmacao causal carrega incerteza calibrada e nenhuma causa e fabricada.
human_input: Compoe os relatorios read-only existentes da escada - o ledger de verdade da capability (claimed/computed/drift/under_claim + resolucao por ref), a grade L2-O1 da capability, a reconciliacao bidirecional doc<->codigo e a calibracao R2.
human_output: Entrega um envelope read-only com hash - causal_chains[] (intent, truth, result, why[], calibration) onde cada why-link carrega basis (sinal real), inference (proven|inferred) e uncertainty (confidence + blind_spot).
human_change_when: Mexa quando um novo fragmento mensuravel da reflexao for promovido (sempre um por vez, com prova e incerteza), ou quando os relatorios compostos mudarem de forma.
human_block_when: Bloqueie qualquer elo causal sem incerteza calibrada ou sem basis, qualquer inferencia apresentada como causa provada, qualquer causa fabricada, ou qualquer tentativa de tratar este fragmento como se fosse a assintota L-inf inteira.
canonical_name: Atlas Documentation Reality Causal Self Model Fragment
technical_name: AtlasDocumentationRealityCausalSelfModelService
cartography_type: module
tags:
  - atlas-ai
  - documentation-governance
  - adrs
  - reflective
  - causal-self-model
capabilities:
  - causal_self_model
  - intent_truth_result_chain
  - calibrated_uncertainty_per_causal_link
  - inference_vs_proof_distinction
decisions:
  - Nome canonico obrigatorio - Atlas Documentation Reality Causal Self Model Fragment.
  - Acronimo tecnico - ADRS-R1.
  - Este doc promove UM fragmento mensuravel (R1 auto-modelo causal) da assintota L-inf; NAO e a assintota e nunca a declara concluida (linf_complete e hard false).
  - R2 (humildade epistemica) ja foi promovido como o primeiro fragmento; R1 e o proximo, um por vez.
  - Invariante inviolavel - toda afirmacao causal carrega basis (sinal real), inference (proven|inferred) e uncertainty.blind_spot nao-vazio; um elo sem isso lanca LogicException.
  - Nunca fabricar uma causa - cada elo e ancorado num sinal real composto; uma correlacao inferida e rotulada inferred, nunca asserida como causa provada.
  - Composto read-only dos relatorios da escada (ledger truth, O1 grade, reconciliacao bidirecional, R2 reflective status); collaborator ausente degrada com nota calibrada, nunca fabrica causa.
  - R2 e REUSADO para anexar calibracao; este fragmento nao duplica a avaliacao de R2.
  - A doc-mae reflexiva (atlas-documentation-reality-reflective-self-model) continua north_star; este fragmento nao a altera.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar quando outro fragmento reflexivo for promovido (um por vez) ou quando ledger truth / O1 / reconciliacao / R2 mudarem de forma.
  - Rodar docs-health, docs-authority-audit e architecture-validate apos alteracoes.
graph_id: atlas-documentation-reality-causal-self-model-fragment
human_name: Atlas Documentation Reality Causal Self Model Fragment
canonical_source: docs/engineering-knowledge-base/atlas-documentation-reality-causal-self-model-fragment.md
graph_title: Atlas Documentation Reality Causal Self Model Fragment
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-documentation-reality-reflective-self-model
graph_status: active
graph_source: repo
owner: documentation-governance
implementation_state: partial
depends_on:
  - atlas-documentation-reality-reflective-self-model
  - atlas-documentation-reality-reflective-status-fragment
  - atlas-documentation-reality-outcome-grounded-truth
  - atlas-documentation-reality-bidirectional-reconciliation
flows_to:
  - atlas-documentation-reality-evolution-ladder
unlocks:
  - system_that_can_explain_itself_causally
  - confidently_correct_or_explicitly_uncertain
governs:
  - documentation-governance-causal-self-knowledge
related_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-reflective-self-model.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-reflective-status-fragment.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-outcome-grounded-truth.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-bidirectional-reconciliation.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-causal-self-model-fragment.md
  - app/Services/Engineering/AtlasDocumentationRealityCausalSelfModelService.php
  - app/Console/Commands/AtlasDocumentationRealityCausalSelfModelCommand.php
  - tests/Feature/Engineering/AtlasDocumentationRealityCausalSelfModelTest.php
allowed_changes:
  - Promover OUTRO fragmento mensuravel da reflexao (um por vez, com prova e incerteza declarada).
  - Refinar os elos causais, a calibracao e os blind_spots mantendo a invariante e a distincao prova-vs-inferencia.
forbidden_changes:
  - Emitir qualquer elo causal sem incerteza calibrada ou sem basis (drift supremo).
  - Apresentar uma correlacao inferida como causa provada.
  - Fabricar uma causa quando um sinal composto falha (degrade-safe e obrigatorio).
  - Tratar este fragmento como a assintota L-inf inteira, ou declarar L-inf concluido.
  - Tornar o modelo mutativo (escrever, executar, alterar).
  - Alterar a doc-mae reflexiva para implemented; ela continua north_star.
evidence:
  - docs/engineering-knowledge-base/atlas-documentation-reality-causal-self-model-fragment.md
  - app/Services/Engineering/AtlasDocumentationRealityCausalSelfModelService.php
evidence_refs:
  - symbol: AtlasDocumentationRealityCausalSelfModelService
  - command: atlas:documentation-reality-causal-self-model
  - test: AtlasDocumentationRealityCausalSelfModelTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:ai:architecture-validate --json"
requires_evidence: true
risk_level: critical
line_limit: 520
ai_entrypoints:
  - Leia este doc para entender como o ADRS explica uma capability de forma CAUSAL (intencao->verdade->resultado->porque) com incerteza calibrada e prova-vs-inferencia em cada elo.
ai_usage_notes:
  - Este e UM fragmento (R1) de L-inf, nao a assintota; linf_complete e sempre false e is_one_fragment_not_asymptote sempre true.
  - Toda afirmacao causal carrega basis + inference + uncertainty.blind_spot; uma correlacao inferida nunca e causa provada; nenhuma causa e fabricada.
next_actions:
  - Manter L-inf como bussola permanente; promover o proximo fragmento reflexivo (R3) so com prova e incerteza declarada.
  - So depois de sinal de outcome real fluir, recalibrar os elos de resultado com confianca maior.
---

# Atlas Documentation Reality Causal Self Model Fragment

## Resumo

ADRS-R1 promove **um unico fragmento mensuravel** da assintota L-inf
(`atlas-documentation-reality-reflective-self-model`): **R1, auto-modelo causal**.
R2 (humildade epistemica) ja foi promovido como o **primeiro** fragmento; R1 e o
**proximo**, um por vez. Para uma capability, R1 responde a pergunta que a doc-mae
nomeia no topo da escada (`reflective-self-model.md:134/146/156-159`):

```text
o que o Atlas e, por que, o que e verdade, o que foi intencao e o que resultou
```

O entregavel e um **auto-modelo causal read-only e consultavel**: por capability,
a cadeia **intencao -> verdade -> resultado -> porque**, composta dos sinais reais
da escada. **Cada** elo do "porque" carrega **incerteza calibrada** e **distingue
prova de inferencia**.

> Este doc promove R1 e **nada alem**. **NAO** e a assintota L-inf. L-inf
> permanece **bussola permanente**, nunca um sprint, nunca "concluido"
> (`linf_complete` e hard `false`). A doc-mae reflexiva continua `north_star`.

## Papel no Atlas

Os niveis abaixo perguntam a verdade sobre o **codigo** ("o codigo bate com o doc?
vai bater? funcionou no mundo?"). R1 vira a lente para o **proprio ADRS** e monta o
**modelo causal** de por que o estado de uma capability e o que e — a partir dos
**sinais reais** de gap, nao de prosa inventada.

A doenca-mae final (o **sistema confiantemente errado sobre si proprio**) tem aqui
uma forma especifica: **fabricar uma causa** ou **apresentar uma inferencia como
causa provada**. R1 existe para tornar isso **estruturalmente impossivel**: toda
afirmacao causal carrega `basis` (o sinal real que a ancora), `inference`
(`proven` | `inferred`) e `uncertainty.blind_spot` nao-vazio. Um elo sem isso nao
pode ser emitido — `assertCausalClaimCalibrated()` lanca `LogicException` antes.

## Onde Se Encaixa

```text
L2 (ancorado em realidade)
  -> L-inf (assintota, north-star permanente)
       R1 Auto-modelo causal        (ESTE fragmento — promovido, read-only)
       R2 Humildade epistemica      (fragmento anterior — promovido, read-only)
       R3 Auto-melhoria da modelagem (fragmento posterior, NAO construido)
  -> territorio aberto (fora do escopo canonico)
```

## A Invariante Inviolavel

Herdada da doc-mae (`reflective-self-model.md:169`, absoluta):

```text
Auto-conhecimento sem incerteza calibrada e o DRIFT SUPREMO.
```

Gravada em codigo (`assertCausalClaimCalibrated`), CADA elo causal exige:

- `basis` nao-vazio — o **sinal real composto** que ancora a causa (nunca fabricada);
- `inference` em {`proven`, `inferred`} — **prova vs inferencia explicita**; uma
  correlacao inferida e rotulada `inferred`, **nunca** asserida como causa provada;
- `uncertainty.confidence` calibrado em {`high`, `medium`, `low`} — nao existe
  "certain";
- `uncertainty.blind_spot` nao-vazio — **todo** elo nomeia um limite conhecido;
- violar qualquer item lanca `LogicException` (espelha os guards de R2/P3/O1) — o
  drift supremo e inalcancavel por construcao.

## Contratos

`AtlasDocumentationRealityCausalSelfModelService` (read-only):

| Metodo | O que faz |
|---|---|
| `explainCapability(string $capability): array` | monta a cadeia causal de UMA capability (id/slug ou substring do owner-doc) |
| `explainAll(int $limit = 25): array` | monta a cadeia causal de todas as capabilities do ledger, ate `$limit` |

Cada `causal_chain`:

```text
{ capability_id, owner_doc,
  intent  { claimed_state, as_declared, source },        # o que o doc DIZ
  truth   { computed_state, resolved_kinds, resolved_refs, unmet_evidence, source },  # o que o codigo RESOLVE
  result  { grade, outcome_grounded, as_resulted, source },  # o que RESULTOU (hoje no-signal)
  why     [ { statement, basis, inference, uncertainty { confidence, blind_spot } } ],
  calibration { confidence, attached_by, blind_spots, ... },  # R2 REUSADO
  linf_fragment:true, linf_complete:false, fragment="R1_causal_self_model" }
```

Cada `why-link`:

```text
{ statement (a causa: "X BECAUSE Y, do sinal real Z"),
  basis (qual sinal real ancora: ledger / O1 / reconciliacao),
  inference: proven | inferred  (prova vs inferencia, explicito),
  uncertainty { confidence: high|medium|low (CALIBRADO),
                blind_spot (limite conhecido, nao-vazio) } }
```

Envelope `atlas.documentation_reality.causal_self_model.v1` (com hash):

```text
schema_version,
level="L-inf (one promoted fragment: R1 causal self-model)",
fragment="R1_causal_self_model",
is_one_fragment_not_asymptote:true,
linf_fragment:true,
linf_complete:false  (HARD false — nunca declara L-inf concluido),
composes { intent, truth, result, why, calibration },
causal_chains[],
claim_policy { read_only:true, linf_fragment:true, linf_complete:false,
               every_causal_claim_calibrated:true,
               distinguishes_inference_from_proof:true,
               never_fabricates_a_cause:true,
               is_one_linf_fragment_not_the_asymptote:true },
writes:false, causal_self_model_hash
```

Comando: `atlas:documentation-reality-causal-self-model {--capability=} {--json}`
(auto-descoberto, nao muta nada).

## Composicao — Read-Only

O modelo **nao re-deriva** verdade; **compoe** os relatorios read-only ja
existentes da escada e os monta numa cadeia causal:

1. **intent + truth** — `AtlasAaeosImplementationTruthService::ledger()`:
   `claimed_state` (a **intencao** declarada do doc) e `computed_state` +
   resolucao por ref (a **verdade** da maquina), alem de `drift` / `under_claim` —
   o sinal do gap.
2. **result** — `AtlasDocumentationRealityOutcomeGroundingService` (L2-O1): a grade
   de outcome da capability — hoje honestamente **no-signal / 0**, nunca fabricada.
3. **why** — o gap do ledger (`drift` / `under_claim` / refs nao-resolvidos) +
   `AtlasDocumentationRealityBidirectionalReconciliationService`: **qual** ref nao
   resolve, em **qual** direcao.
4. **calibration** — `AtlasDocumentationRealityReflectiveStatusService` (**R2**,
   humildade epistemica) e **REUSADO** para anexar confianca calibrada +
   blind_spots a cadeia. R1 **nao duplica** a avaliacao de R2.

Cada collaborator e **degrade-safe**: se um sinal nao puder ser lido, o modelo
**degrada** com `available:false` + uma nota calibrada (que ela mesma carrega
`basis` + `inference` + `blind_spot`) — **nunca** uma causa fabricada.

## Fluxo

```text
pergunta causal sobre uma capability do ADRS
-> compoe ledger (intent=claimed, truth=computed+refs) + O1 (result) + reconciliacao (why) + R2 (calibracao)
-> monta a cadeia intent -> truth -> result -> why
-> cada why-link: a causa, o sinal real (basis), proven|inferred, e a incerteza (confidence + blind_spot)
-> valida CADA elo na hora (assertCausalClaimCalibrated) antes de emitir
```

## Regras para IA

- NUNCA emita um elo causal sem `basis`, sem `inference` ou sem `blind_spot` — e o
  drift supremo.
- NUNCA apresente uma correlacao **inferida** como causa **provada**; rotule
  `inferred`.
- NUNCA fabrique uma causa quando um sinal falha — degrade com nota calibrada.
- NUNCA trate este fragmento como a assintota L-inf inteira; e UM pedaco dela.
- NUNCA declare L-inf concluido (`linf_complete` e sempre `false`).
- NUNCA torne o modelo mutativo (escrever, executar, alterar).
- NAO altere a doc-mae reflexiva para implemented — ela continua north_star.

## Escopo de Implementacao

Runtime read-only, **um fragmento**. Entrega so o **auto-modelo causal R1**
(service + comando + teste), composto dos relatorios existentes. A auto-melhoria da
modelagem (R3 — registrar limites de modelagem) e deliberadamente **fora de
escopo** — e um fragmento posterior da mesma assintota.

## Dependencias

- `atlas-documentation-reality-reflective-self-model` (L-inf, doc-mae north-star).
- `atlas-documentation-reality-reflective-status-fragment` (R2, reusado para
  calibracao).
- `AtlasAaeosImplementationTruthService.ledger()` (intent + truth + gap).
- `AtlasDocumentationRealityOutcomeGroundingService` (result / L2-O1).
- `AtlasDocumentationRealityBidirectionalReconciliationService` (why direcional).
  Todos degrade-safe.

## Evidencias

- symbol: `AtlasDocumentationRealityCausalSelfModelService`
- command: `atlas:documentation-reality-causal-self-model`
- test: `AtlasDocumentationRealityCausalSelfModelTest`

Drift do doc filho deve ser `false` em
`atlas:aaeos:maturity --capability=atlas-documentation-reality-causal-self-model-fragment --json`.

## Riscos

- **ESTE FRAGMENTO NAO E L-inf:** o risco numero um e confundir R1 com a assintota.
  R1 (auto-modelo causal) e **um fragmento mensuravel** promovido de L-inf, **NAO
  L-inf**. L-inf permanece a **assintota / bussola permanente** e **nunca** e um
  sprint; alem dele e territorio aberto. Mitigacao em codigo: `linf_complete` e hard
  `false` e `is_one_fragment_not_asymptote` e `true`, sempre.
- **Causa fabricada (drift SUPREMO):** inventar um porque sem sinal real. Mitigacao:
  a invariante-mae em codigo — `basis` obrigatorio em cada elo; um elo sem `basis`
  lanca `LogicException`. Falha de sinal composto **degrada** com nota calibrada,
  nunca fabrica.
- **Inferencia como prova:** apresentar uma correlacao como causa provada — a forma
  mais sutil do confiantemente-errado. Mitigacao: `inference` em {`proven`,
  `inferred`} e obrigatorio; um elo nao-rotulado nao pode ser emitido; o caso
  no-outcome e explicitamente `inferred`, nunca `proven`.
- **Auto-conhecimento sem incerteza:** um elo causal sem limite declarado.
  Mitigacao: `uncertainty.blind_spot` nao-vazio obrigatorio em CADA elo; o `headline`
  da cadeia (calibracao) sempre carrega blind_spots — sao **primeiros incrementos**;
  `outcome_grounded` hoje e **0** (sem validacao no mundo ainda); a resolucao e
  existence-only (prova que refs resolvem, nao que a capability se comporta correta).
- **Reflexao teatral:** cadeia causal bonita sem prova nem incerteza. Mitigacao: os
  sinais sao **compostos** dos relatorios reais (ledger, O1, reconciliacao, R2), nao
  inventados; collaborator ausente degrada e declara o ponto cego.
- **Antropomorfismo:** confundir modelo verificavel com consciencia. Mitigacao:
  aqui reflexao e engenharia (composicao + invariante em codigo), nao metafora.
- **Mexer na doc-mae:** marcar a reflective-self-model como implemented. Mitigacao:
  proibido — ela continua `north_star`; so este fragmento filho e `partial`.

## Exemplos

```text
Pergunta: "por que o ADRS-R2 (reflective-status-fragment) esta no estado que esta?"
Resposta L-inf/R1 (este fragmento):
  intent : o doc DECLARA implementation_state 'partial' (a intencao).
  truth  : o indice RESOLVE 'partial' (symbol + command resolvem; test existe).
  result : no_outcome_signal — built, mas nao validado no mundo (honesto, 0).
  why:
   - "doc e codigo AGREE (drift=false): computed='partial' = claimed='partial'
      BECAUSE all declared evidence_refs resolve."
        basis=capability_truth_ledger.drift=false + resolucao por ref; inference=proven;
        uncertainty={confidence: high, blind_spot: "resolucao e existence-only; nao
        prova comportamento, nem que o conjunto de claims esta completo"}.
   - "intent=implemented BUT result=no_outcome_signal BECAUSE no real outcome signal
      is currently linked — i.e. built but NOT YET validated in the world. NOT a failure."
        basis=L2-O1 grade (implemented_no_outcome_signal); inference=INFERRED;
        uncertainty={confidence: low, blind_spot: "ausencia de sinal nao prova
        que nunca foi usado; leitura inferida de um estado no-signal, nunca causa provada"}.
  calibration: confidence=low (o elo mais fraco manda), R2 anexa blind_spots —
  e UM fragmento (R1) de L-inf, nao a assintota.
```

(Repare: o "porque" do resultado vem **rotulado `inferred`** e com o ponto cego
declarado — e o que impede a inferencia de virar causa provada e torna o sistema
impossivel de estar confiantemente errado sobre si.)

## Proximas Acoes

- Manter L-inf como **bussola permanente**, revisada mas nunca concluida.
- Promover o **proximo** fragmento reflexivo (R3, auto-melhoria) apenas com prova e
  incerteza declarada (um por vez).
- Quando sinal de outcome real fluir (O1 > 0), recalibrar os elos de **resultado**
  com confianca maior — sempre com os pontos cegos restantes declarados.
