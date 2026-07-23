---
id: atlas-documentation-reality-completeness
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Documentation Reality Completeness
slug: atlas-documentation-reality-completeness
status: active
category: documentation-governance
priority: 98
summary: O check honesto e DESAMBIGUADO de "a escada do ADRS esta completa?". Read-only. Reporta TRES eixos separados, nunca um "100%" unico - (a) runtime_completeness (os mecanismos BUILDABLE da escada presentes + ligados + drift-0, a completude de RUNTIME, hoje 15/15), (b) asymptote (linf_complete=false PARA SEMPRE, a bussola, fora do 10/10), (c) reality_dependent (O1 outcome_grounded, funcao do mundo, honestamente 0, NUNCA contado). CADA mecanismo e drift-checado contra seu owner doc real (sem exemcao); a honestidade repousa nesse check por mecanismo + no conjunto de mecanismos travado por teste. Um guard em codigo e defesa em profundidade que rejeita envelope desonesto.
human_summary: Responde "o ADRS esta completo?" da unica forma honesta - separando tres perguntas que antes viravam um "100%" so. (a) Os mecanismos que da pra construir estao construidos, endurecidos e ligados? (e o 10/10 de verdade, e hoje esta atingido). (b) A assintota (o topo ideal da escada) esta concluida? - nunca, por definicao, e ela nunca entra no 10/10. (c) Quantos resultados reais do mundo ja chegaram? - hoje 0, e isso e a leitura certa, nao um defeito; nunca conta como completude. Um guard em codigo impede fingir.
human_what: Check read-only que faz dogfood do criterio "ADRS runtime completeness" - verifica cada mecanismo buildable da escada (service+comando+teste resolvem / drift=0) e reporta os tres eixos honestamente, com guard anti-goalpost.
human_purpose: Tornar o objetivo "L0->L-inf 100%" satisfazivel de forma honesta, desambiguando o que e alcancavel (os mecanismos), o que e bussola (a assintota) e o que depende do mundo (outcome).
human_input: Compoe os relatorios read-only existentes - o ledger de verdade AAEOS (drift por owner doc), o self-status reflexivo R2 (linf_complete = o eixo assintota) e o scorer O1 (outcome_grounded = o eixo reality-dependent) - e reflete sobre a superficie real de classe/comando/teste.
human_output: Um envelope read-only com hash - runtime_completeness (met/not + evidencia por mecanismo), asymptote (false para sempre) e reality_dependent (contagem honesta), com um verdict que nunca afirma a assintota nem soma grounded na completude.
human_change_when: Mexa quando um novo mecanismo buildable for adicionado a escada (entra no registro), ou quando os relatorios compostos (AAEOS / R2 / O1) mudarem de forma.
human_block_when: Bloqueie qualquer tentativa de marcar runtime_complete com mecanismo nao-resolvido, de afirmar a assintota concluida, ou de contar outcome_grounded como parte da completude.
canonical_name: Atlas Documentation Reality Completeness
technical_name: AtlasDocumentationRealityCompletenessService
cartography_type: module
tags:
  - atlas-ai
  - documentation-governance
  - adrs
  - runtime-completeness
  - asymptote-humility
capabilities:
  - adrs_runtime_completeness_criterion
  - three_axis_completeness_disambiguation
  - per_mechanism_resolution_evidence
  - anti_goalpost_completeness_guard
decisions:
  - Nome canonico obrigatorio - Atlas Documentation Reality Completeness.
  - Acronimo tecnico - ADRS-RC.
  - "ADRS runtime completeness" e um criterio BOUNDED e MENSURAVEL - o runtime esta completo quando TODO mecanismo buildable da escada esta presente + ligado + drift-0 (built+hardened+integrado). E a completude de RUNTIME - distinta da assintota e das metricas dependentes-de-realidade - nao "a escada concluida". Hoje os mecanismos buildable estao presentes + ligados + drift-0 (15/15); a leitura completa e a frase de tres eixos (nomeia asymptote=false + grounded=0).
  - Tres eixos SEPARADOS, nunca um "100%" unico - (a) runtime_completeness (buildable, alcancavel), (b) asymptote (linf_complete=false para sempre, lido do R2, fora do 10/10), (c) reality_dependent (O1 outcome_grounded, funcao do mundo, honestamente 0, nunca contado).
  - O verdict NUNCA afirma a assintota e NUNCA dobra grounded na contagem de completude. A honestidade repousa em DUAS coisas concretas: o drift-check por mecanismo sobre o owner doc real de CADA rung (sem exemcao) e o conjunto de mecanismos travado por teste (count + key-set). Um guard em codigo (LogicException, espelha P3/O1/R2) e defesa em profundidade que rejeita envelope desonesto - nao uma impossibilidade estrutural.
  - Composto read-only do ledger AAEOS, do R2 (selfAssessment) e do O1 (gradeAll); collaborator ausente marca o mecanismo afetado nao-resolvido, nunca fabrica resolucao.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar quando um mecanismo buildable for adicionado/removido da escada ou quando AAEOS / R2 / O1 mudarem de forma.
  - Rodar docs-health, docs-authority-audit e architecture-validate apos alteracoes.
graph_id: atlas-documentation-reality-completeness
human_name: Atlas Documentation Reality Completeness
canonical_source: docs/engineering-knowledge-base/atlas-documentation-reality-completeness.md
graph_title: Atlas Documentation Reality Completeness
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-documentation-reality-system
graph_status: active
graph_source: repo
owner: documentation-governance
implementation_state: partial
depends_on:
  - atlas-documentation-reality-system
  - atlas-documentation-reality-evolution-ladder
  - atlas-documentation-reality-reflective-self-model
  - atlas-documentation-reality-reflective-status-fragment
  - atlas-documentation-reality-outcome-grounded-truth
flows_to:
  - atlas-documentation-reality-evolution-ladder
unlocks:
  - honest_bounded_completeness
  - asymptote_stays_compass_never_done
governs:
  - documentation-governance-completeness
related_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-system.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-evolution-ladder.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-reflective-self-model.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-reflective-status-fragment.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-outcome-grounded-truth.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-completeness.md
  - app/Services/Engineering/AtlasDocumentationRealityCompletenessService.php
  - app/Console/Commands/AtlasDocumentationRealityCompletenessCommand.php
  - tests/Feature/Engineering/AtlasDocumentationRealityCompletenessTest.php
allowed_changes:
  - Adicionar/remover um mecanismo buildable do registro quando a escada ganhar/perder um mecanismo real (com service+comando+teste).
  - Refinar a evidencia por mecanismo, o verdict e a prosa mantendo os tres eixos separados e o guard.
forbidden_changes:
  - Marcar runtime_complete=true com qualquer mecanismo nao-resolvido (over-claim).
  - Afirmar a assintota (asymptote_complete) ou tratar linf_complete como diferente de false.
  - Dobrar outcome_grounded na contagem de completude (eixo reality-dependent e reportado, nunca contado).
  - Redefinir "10/10" como "o que estiver construido" - goalpost-moving.
  - Tornar o check mutativo (escrever, executar, alterar).
evidence:
  - docs/engineering-knowledge-base/atlas-documentation-reality-completeness.md
  - app/Services/Engineering/AtlasDocumentationRealityCompletenessService.php
evidence_refs:
  - symbol: AtlasDocumentationRealityCompletenessService
  - command: atlas:documentation-reality-completeness
  - test: AtlasDocumentationRealityCompletenessTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:ai:architecture-validate --json"
requires_evidence: true
risk_level: critical
line_limit: 520
ai_entrypoints:
  - Leia este doc para entender como o ADRS prova a propria completude de forma honesta e desambiguada (tres eixos, nunca um 100% unico).
ai_usage_notes:
  - runtime_completeness e o unico 10/10 alcancavel (mecanismos buildable); asymptote e bussola (linf_complete=false para sempre); reality_dependent (O1) e funcao do mundo e nunca conta.
  - CADA mecanismo e drift-checado contra seu owner doc real (sem exemcao); a honestidade repousa nesse check por mecanismo + no conjunto de mecanismos travado por teste. O guard em codigo e defesa em profundidade que rejeita um envelope desonesto.
next_actions:
  - Manter o registro de mecanismos sincronizado com a escada; cada novo mecanismo buildable entra com service+comando+teste.
  - Quando sinal de outcome real fluir (O1 > 0), o eixo reality_dependent sobe sozinho - sem nunca virar parte da completude.
---

# Atlas Documentation Reality Completeness

## Resumo

ADRS-RC e o check **honesto e DESAMBIGUADO** de "a escada do ADRS esta completa?".
O objetivo `L0->L-inf 100% implementado / 10/10 funcionando` era **estruturalmente
insatisfazivel** porque **conflava** tres coisas diferentes num unico "100%":

```text
(1) os mecanismos BUILDABLE da escada (alcancavel — e ja construido)
(2) a assintota L-inf infinita (ideal; nunca "done" por design)
(3) metricas reality-dependent (O1 outcome_grounded, funcao do mundo, hoje 0)
```

A unica leitura honesta os **separa** em tres eixos e nunca os funde. Read-only;
nunca finge.

## Papel no Atlas

A doenca-mae do ADRS ("dizer que implementou sem ter implementado") tem uma forma
sutil: **mover a trave** — redefinir "10/10" como "o que estiver construido",
declarar a assintota alcancada, ou inflar `outcome_grounded`. ADRS-RC se defende
disso com coisas **concretas e verificaveis**: afirma `runtime_complete` **so**
quando **todo** mecanismo buildable resolve **e** seu owner doc real esta drift-0
(check por mecanismo, sem exemcao); trava o conjunto de mecanismos por teste (count
+ key-set, para que encolher o denominador seja mudanca revisada); mantem
`asymptote_complete` **false para sempre**; e **nunca** soma `outcome_grounded` a
completude. Um guard em codigo reforca isso como **defesa em profundidade** —
rejeita um envelope desonesto antes de emitir.

## Onde Se Encaixa

```text
escada L0->L-inf (evolution-ladder, o indice)
  -> ADRS-RC (este doc) — faz dogfood do criterio de completude
       (a) runtime_completeness: os mecanismos buildable built+hardened
       (b) asymptote: linf_complete=false (lido do R2)
       (c) reality_dependent: outcome_grounded (lido do O1)
```

## Os Tres Eixos (nunca um "100%" unico)

| Eixo | O que mede | Natureza | Conta no 10/10? |
|---|---|---|---|
| (a) `runtime_completeness` | mecanismos BUILDABLE da escada | bounded, alcancavel | **SIM** — e o 10/10 |
| (b) `asymptote` | `linf_complete` | ideal, nunca "done" | **NAO** — bussola |
| (c) `reality_dependent` | `outcome_grounded` (O1) | funcao do mundo | **NAO** — reportado, nunca contado |

### (a) runtime_completeness — a unica completude alcancavel

O runtime do ADRS esta **COMPLETO** quando **todo mecanismo buildable** esta
**presente + ligado + drift-0** (built + hardened + integrado). `built` = a classe
do service existe, o comando artisan esta registrado e o arquivo de teste existe.
`hardened` = built **e** o **owner doc real** do mecanismo esta `drift=false` no
ledger AAEOS. **Cada** mecanismo nomeia o doc canonico cujo `evidence_refs`/symbol
**E** o seu service, entao o check de over-claim (`drift = rank(claimed) >
rank(computed)`) e aplicado a **todos** — **sem exemcao**; um doc que over-claim
derruba aquele mecanismo para nao-hardened e o verdict para NOT MET. E o sentido
honesto de **"L0->L-inf implementado em codigo"** — a completude de RUNTIME, distinta
da assintota. Hoje os mecanismos buildable estao **presentes + ligados + drift-0
(15/15)**.

Os mecanismos buildable (o conjunto fechado que define a completude):

```text
L0   : write-gate (built+available) + block readiness
L1   : P1 predict, P2 triangulo (over/under/code-contract), P3 antibody
L2   : O1 (scorer) + O2 (advisory) + O3 (multi-estate)
L-inf: TODOS os fragmentos promotaveis R1 (causal) + R2 (reflective) + R3 (self-improve)
flow : o flow composer
integ: a integracao viva (o ADRS ligado no entrypoint de session-bootstrap —
       artefato DISTINTO do R2, com classe+comando+teste+owner doc proprios)
```

### (b) asymptote — a bussola permanente, fora do 10/10

`asymptote_complete` e lido **direto** do `linf_complete` do fragmento R2 e e
**hard `false` PARA SEMPRE**. A assintota L-inf (`reflective-self-model`) e
**direcao/limite teorico**, nunca um sprint; alem dela e territorio aberto. O
criterio de runtime-completeness e **DISTINTO** dela e **NAO** a afirma. A
humildade/`forbidden-as-sprint` da doc-mae reflexiva fica **intacta**: assintota =
bussola (nunca done); objetivo dos fragmentos promotaveis = bounded + met.

### (c) reality_dependent — honesto, nunca contado

`outcome_grounded` (O1) e funcao de **resultados reais do mundo acumulando**.
Honestamente **0 hoje** e a leitura **CORRETA**, **nao** um deficit de build —
forcar para cima seria **fabricacao** (o pecado cardinal do O1). E **reportado**,
**nunca** somado a completude.

## Contratos

`AtlasDocumentationRealityCompletenessService` (read-only):

| Metodo | O que faz |
|---|---|
| `assess(): array` | computa o criterio e os tres eixos, com hash; o guard valida antes de emitir |

Envelope `atlas.documentation_reality.runtime_completeness.v1` (com hash):

```text
schema_version,
runtime_completeness { runtime_complete, total_mechanisms, built_count,
                       hardened_count, unresolved[], mechanisms[] },
asymptote { asymptote_complete:false, is_permanent_compass:true,
            counts_toward_runtime_completeness:false, r2_headline_* },
reality_dependent { outcome_grounded_count, counts_toward_runtime_completeness:false,
                    honestly_zero_is_correct:true },
verdict { runtime_complete, asymptote_complete:false, claims_asymptote:false,
          outcome_grounded_count, folds_grounded_into_completeness:false, statement },
claim_policy { read_only:true, three_axes_kept_separate:true,
               asymptote_complete_is_false_forever:true,
               outcome_grounded_never_counts_toward_completeness:true, ... },
writes:false, completeness_hash
```

Comando: `atlas:documentation-reality-completeness {--json}` (auto-descoberto, nao
muta nada).

## Onde a Honestidade Repousa (e o Guard como Defesa em Profundidade)

A honestidade **nao** repousa numa "impossibilidade estrutural"; repousa em **duas
coisas concretas**:

1. **Drift-check por mecanismo, sem exemcao.** Cada um dos 15 mecanismos e checado
   contra seu owner doc real (`drift = rank(claimed) > rank(computed)`). Um doc que
   over-claim flipa aquele mecanismo para nao-hardened e derruba
   `runtime_complete` para false.
2. **Conjunto de mecanismos travado por teste.** Um teste de regressao fixa o
   **count** e o **key-set** exatos lidos do registro real (via reflection). Tirar
   um rung para encolher o denominador — o goalpost-move mais sutil — vira mudanca
   **visivel e revisada**, nao um edit silencioso.

Em cima disso, um guard em codigo (`assertHonestVerdict`, espelha os guards
P3/O1/R2) e **defesa em profundidade** — rejeita um envelope desonesto antes de
deixar o service:

```text
1. runtime_complete=true exige que TODO mecanismo resolva (built E hardened);
   completude com buraco => LogicException. (braco LOAD-BEARING: le as contagens reais)
2. asymptote_complete e false e NUNCA e dobrado em runtime_complete => LogicException.
3. outcome_grounded NUNCA entra na conta de completude => LogicException.
```

Os bracos 2-3 re-afirmam constantes que o proprio codigo escreve, entao em operacao
normal so disparam contra um envelope adulterado (injetado via reflection, como faz
o teste do guard) — um **tripwire**, nao a prova primaria. A prova primaria sao as
duas coisas acima.

## Composicao — Read-Only

ADRS-RC **nao re-deriva** verdade; **compoe** os relatorios existentes:

1. `AtlasAaeosImplementationTruthService::ledger()` — drift por owner doc (a
   metade "hardened" de **cada** mecanismo; **todos** os 15 sao drift-checados,
   nenhum e exempt).
2. `AtlasDocumentationRealityReflectiveStatusService::selfAssessment()` — o R2; seu
   `linf_complete` **e** o eixo assintota e seu headline calibrado viaja junto.
3. `AtlasDocumentationRealityOutcomeGroundingService::gradeAll()` — a contagem O1
   (o eixo reality-dependent), lida honestamente.
4. o registro vivo de comandos artisan — prova que cada comando esta **ligado**.

Cada collaborator e **degrade-safe**: um read model ausente marca o mecanismo
afetado **nao-resolvido** (baixando a completude) — nunca fabrica resolucao.

## Fluxo

```text
pergunta "o ADRS esta completo?"
-> para cada mecanismo buildable: resolve (classe + comando registrado + teste) e drift do owner doc
   => built? hardened?
-> eixo (a) runtime_completeness = todos built+hardened? (met/not, com evidencia por mecanismo)
-> eixo (b) asymptote = linf_complete do R2 (false para sempre)
-> eixo (c) reality_dependent = outcome_grounded do O1 (honesto, nunca contado)
-> guard valida (sem over-claim, sem conflar assintota/grounded) -> verdict + hash
```

## Regras para IA

- NUNCA marque `runtime_complete=true` com mecanismo nao-resolvido — e over-claim.
- NUNCA afirme a assintota; `asymptote_complete` e `false` para sempre.
- NUNCA dobre `outcome_grounded` na completude; e reportado, nunca contado.
- NUNCA redefina "10/10" como "o que estiver construido" — e goalpost-moving.
- NUNCA torne o check mutativo (escrever, executar, alterar).

## Escopo de Implementacao

Runtime read-only. Entrega o **check de completude** (service + comando + teste),
composto dos relatorios existentes. O dogfood prova o proprio criterio, na frase
plena de tres eixos: hoje `runtime_completeness` esta **MET — 15/15 mecanismos
buildable presentes + ligados + drift-0** (cada um drift-checado contra seu owner
doc real, sem exemcao), `asymptote_complete=false` (a bussola, sempre), e
`outcome_grounded=0` (honesto, nao contado).

## Dependencias

- `atlas-documentation-reality-evolution-ladder` (a escada/criterio).
- `atlas-documentation-reality-reflective-self-model` (a assintota north-star).
- `AtlasAaeosImplementationTruthService::ledger()` (drift por doc).
- `AtlasDocumentationRealityReflectiveStatusService::selfAssessment()` (o eixo assintota).
- `AtlasDocumentationRealityOutcomeGroundingService::gradeAll()` (o eixo reality). Todos
  degrade-safe.

## Evidencias

- symbol: `AtlasDocumentationRealityCompletenessService`
- command: `atlas:documentation-reality-completeness`
- test: `AtlasDocumentationRealityCompletenessTest`

Drift do doc deve ser `false` em
`atlas:aeos:maturity --capability=atlas-documentation-reality-completeness --json`.

## Riscos

- **Goalpost-moving (a trave):** redefinir 10/10, afirmar a assintota, dobrar
  grounded, ou **encolher o denominador** tirando um rung. Mitigacao primaria: o
  drift-check por mecanismo (sem exemcao) + o conjunto de mecanismos travado por
  teste (count + key-set), que tornam tirar um rung uma mudanca revisada. Defesa em
  profundidade: o guard `assertHonestVerdict` lanca `LogicException` antes de emitir
  um envelope desonesto.
- **Assintota virar entregavel:** tratar `linf_complete` como algo que vira true.
  Mitigacao: `asymptote_complete` e lido do R2 e e `false` para sempre; eixo distinto
  da completude.
- **Grounded fabricado:** inflar `outcome_grounded`. Mitigacao: a contagem vem do O1
  (sinal real, nunca inferido) e e reportada, **nunca** contada na completude;
  honestamente 0 e a leitura correta.
- **Mecanismo fantasma:** marcar built sem o artefato existir. Mitigacao: `built`
  exige classe + comando registrado + arquivo de teste; um collaborator ausente
  marca nao-resolvido, nunca um pass falso.

## Exemplos

```text
Pergunta: "o ADRS esta 10/10 / L0->L-inf completo?"
Resposta ADRS-RC (desambiguada, frase plena de tres eixos):
  (a) runtime_completeness = MET: 15/15 mecanismos buildable presentes + ligados +
      drift-0 (cada um drift-checado contra seu owner doc real, sem exemcao; inclui a
      integracao viva no session-bootstrap, DISTINTA do R2). E a completude de RUNTIME
      ("L0->L-inf em codigo") — nao "a escada concluida".
  (b) asymptote = false (sempre): a assintota L-inf e bussola, nunca "done"; fora do 10/10.
  (c) reality_dependent: outcome_grounded = 0 hoje — funcao do mundo, leitura correta,
      nao deficit; reportado, nunca contado.
  Verdict: runtime_complete=true, claims_asymptote=false, folds_grounded=false.
```

## Proximas Acoes

- Manter o registro de mecanismos sincronizado com a escada (cada novo mecanismo
  buildable entra com service + comando + teste).
- Quando sinal de outcome real fluir (O1 > 0), o eixo reality_dependent sobe sozinho
  — sem nunca virar parte da completude.
