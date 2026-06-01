---
id: atlas-documentation-reality-reflective-status-fragment
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Documentation Reality Reflective Status Fragment
slug: atlas-documentation-reality-reflective-status-fragment
status: active
category: documentation-governance
priority: 97
summary: Um fragmento mensuravel (R2 humildade epistemica) promovido da assintota L-inf do ADRS — NAO a assintota. Um self-status reflexivo, read-only, que responde perguntas sobre o proprio ADRS (incl. "esta 10/10 em doc<->runtime?") com incerteza calibrada e pontos cegos declarados em CADA afirmacao. Nunca um veredito nu; nunca declara L-inf concluido. A doc-mae reflexiva continua north-star.
human_summary: O topo da escada (L-inf) e uma direcao, nunca um sprint. Aqui promovemos so UM pedaco mensuravel dele — a humildade epistemica — virando codigo: um status que, quando perguntado "o Atlas esta 10/10?", nunca responde so um numero. Ele diz a medida E os proprios pontos cegos (sao primeiros incrementos; outcome ainda e 0; cobertura mede o que afirma, nao se o conjunto de afirmacoes esta completo). O resto do L-inf segue como norte, nao como entrega.
human_what: Self-status reflexivo R2 (humildade epistemica) - uma afirmacao calibrada por degrau (L0, L1-P1/P2/P3, L2-O1/O2/O3) + um headline com pontos cegos declarados, read-only.
human_purpose: Tornar o drift supremo (o sistema confiantemente errado sobre si mesmo) estruturalmente impossivel, exigindo incerteza calibrada + pontos cegos declarados em toda afirmacao sobre si.
human_input: Compoe os relatorios read-only existentes da escada - cobertura doc<->runtime (truth.coverage), contagem outcome_grounded (O1) e status de prontidao L0.
human_output: Entrega um envelope read-only com hash - claims[] (rung, claim, confidence, blind_spots, evidence_ref) e um headline que responde "10/10?" com pontos cegos declarados, nunca um numero nu.
human_change_when: Mexa quando um novo fragmento mensuravel da reflexao for promovido (sempre um por vez, com prova e incerteza), ou quando os relatorios compostos mudarem de forma.
human_block_when: Bloqueie qualquer afirmacao sobre si sem incerteza calibrada, qualquer headline 10/10 nu, ou qualquer tentativa de tratar este fragmento como se fosse a assintota L-inf inteira.
canonical_name: Atlas Documentation Reality Reflective Status Fragment
technical_name: AtlasDocumentationRealityReflectiveStatusService
cartography_type: module
tags:
  - atlas-ai
  - documentation-governance
  - adrs
  - reflective
  - epistemic-humility
capabilities:
  - reflective_self_status
  - calibrated_uncertainty_per_claim
  - declared_blind_spots
  - supreme_drift_guard
decisions:
  - Nome canonico obrigatorio - Atlas Documentation Reality Reflective Status Fragment.
  - Acronimo tecnico - ADRS-R2.
  - Este doc promove UM fragmento mensuravel (R2 humildade epistemica) da assintota L-inf; NAO e a assintota e nunca a declara concluida (linf_complete e hard false).
  - Invariante inviolavel - toda afirmacao sobre si carrega confidence calibrado; confidence nao-high ou claim completion-flavored exige pelo menos um blind_spot declarado.
  - O headline que responde "ADRS 10/10?" nunca e um veredito nu - sempre carrega declared_blind_spots nao-vazio.
  - Composto read-only dos relatorios da escada (truth.coverage, O1 gradeAll, L0 report); collaborator ausente baixa confianca e adiciona blind_spot, nunca fabrica certeza.
  - A doc-mae reflexiva (atlas-documentation-reality-reflective-self-model) continua north_star; este fragmento nao a altera.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar quando outro fragmento reflexivo for promovido (um por vez) ou quando truth.coverage / O1 / L0 mudarem de forma.
  - Rodar docs-health, docs-authority-audit e architecture-validate apos alteracoes.
graph_id: atlas-documentation-reality-reflective-status-fragment
graph_title: Atlas Documentation Reality Reflective Status Fragment
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
  - atlas-documentation-reality-outcome-grounded-truth
  - atlas-documentation-reality-system
flows_to:
  - atlas-documentation-reality-evolution-ladder
unlocks:
  - confidently_correct_or_explicitly_uncertain
  - self_status_that_declares_its_blind_spots
governs:
  - documentation-governance-self-status
related_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-reflective-self-model.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-evolution-ladder.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-outcome-grounded-truth.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-system.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-reflective-status-fragment.md
  - app/Services/Engineering/AtlasDocumentationRealityReflectiveStatusService.php
  - app/Console/Commands/AtlasDocumentationRealityReflectiveStatusCommand.php
  - tests/Feature/Engineering/AtlasDocumentationRealityReflectiveStatusTest.php
allowed_changes:
  - Promover OUTRO fragmento mensuravel da reflexao (um por vez, com prova e incerteza declarada).
  - Refinar os claims, o headline, a calibracao e os blind_spots mantendo a invariante.
forbidden_changes:
  - Emitir qualquer afirmacao sobre si sem incerteza calibrada (drift supremo).
  - Responder "10/10" como veredito nu, sem pontos cegos declarados.
  - Tratar este fragmento como a assintota L-inf inteira, ou declarar L-inf concluido.
  - Tornar o status mutativo (escrever, executar, alterar).
  - Alterar a doc-mae reflexiva para implemented; ela continua north_star.
evidence:
  - docs/engineering-knowledge-base/atlas-documentation-reality-reflective-status-fragment.md
  - app/Services/Engineering/AtlasDocumentationRealityReflectiveStatusService.php
evidence_refs:
  - symbol: AtlasDocumentationRealityReflectiveStatusService
  - command: atlas:documentation-reality-reflective-status
  - test: AtlasDocumentationRealityReflectiveStatusTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:ai:architecture-validate --json"
requires_evidence: true
risk_level: critical
line_limit: 520
ai_entrypoints:
  - Leia este doc para entender como o ADRS responde sobre SI mesmo com incerteza calibrada e pontos cegos declarados.
ai_usage_notes:
  - Este e UM fragmento (R2) de L-inf, nao a assintota; linf_complete e sempre false e is_one_fragment_not_asymptote sempre true.
  - Toda afirmacao sobre si carrega confidence; nao-high ou completion-flavored exige blind_spot; o headline nunca e um 10/10 nu.
next_actions:
  - Manter L-inf como bussola permanente; promover o proximo fragmento reflexivo so com prova e incerteza declarada.
  - So depois de sinal de outcome real fluir, recalibrar os claims L2 com confianca maior.
---

# Atlas Documentation Reality Reflective Status Fragment

## Resumo

ADRS-R2 promove **um unico fragmento mensuravel** da assintota L-inf
(`atlas-documentation-reality-reflective-self-model`): **R2, humildade
epistemica**. Os niveis abaixo perguntam a verdade sobre o **codigo** ("o codigo
bate com o doc? vai bater? funcionou no mundo?"). Este fragmento vira a lente para
o **proprio ADRS** e responde a pergunta do topo da escada — mas so esse pedaco
dela:

```text
O que eu NAO sei sobre mim mesmo?
Onde minha modelagem pode estar errada?
Qual e minha incerteza, calibrada e declarada?
```

O entregavel e um **self-status read-only**: uma afirmacao calibrada por degrau
(L0, L1-P1/P2/P3, L2-O1/O2/O3) e um **headline** que responde "o ADRS esta 10/10
em doc<->runtime?" **nunca** como veredito nu — sempre com a medida **e** os
proprios pontos cegos.

> Este doc promove R2 e **nada alem**. **NAO** e a assintota L-inf. L-inf
> permanece **bussola permanente**, nunca um sprint, nunca "concluido"
> (`linf_complete` e hard `false`). A doc-mae reflexiva continua `north_star`.

## Papel no Atlas

A doenca-mae que o ADRS combate ("dizer que implementou sem ter implementado") tem
uma versao final, a pior de todas: **o sistema confiantemente errado sobre si
proprio**. Um Atlas que acredita conhecer a propria realidade quando nao conhece
corrompe a propria fonte de correcao.

R2 existe para tornar isso **estruturalmente impossivel**: toda afirmacao que o
status faz sobre si carrega **incerteza calibrada**, e qualquer afirmacao
nao-`high` ou com sabor de "concluido" carrega pelo menos um **ponto cego
declarado**. Um self-claim sem incerteza calibrada nao pode ser produzido — o
metodo que o monta lanca `LogicException` antes de emiti-lo.

## Onde Se Encaixa

```text
L2 (ancorado em realidade)
  -> L-inf (assintota, north-star permanente)
       R1 Auto-modelo causal        (fragmento posterior, NAO construido)
       R2 Humildade epistemica      (ESTE fragmento — promovido, read-only)
       R3 Auto-melhoria da modelagem (fragmento posterior, NAO construido)
  -> territorio aberto (fora do escopo canonico)
```

## A Invariante Inviolavel

Herdada da doc-mae (`reflective-self-model.md`, absoluta):

```text
toda afirmacao do sistema sobre si mesmo carrega incerteza calibrada.
auto-conhecimento sem incerteza declarada e o drift SUPREMO.
```

Gravada em codigo (`assertClaimCarriesCalibratedUncertainty`):

- todo claim carrega `confidence` calibrado em {`high`, `medium`, `low`} — nao
  existe "certain";
- `confidence` != `high` **OU** claim completion-flavored (contem
  complete/done/10/10/concluido/...) => **exige** >= 1 `blind_spot` declarado;
- o `headline` **sempre** carrega `declared_blind_spots` nao-vazio e
  `is_bare_verdict=false`;
- violar qualquer item lanca `LogicException` (espelha os guards de P3/O1) — o
  drift supremo e inalcancavel por construcao.

## Contratos

`AtlasDocumentationRealityReflectiveStatusService` (read-only):

| Metodo | O que faz |
|---|---|
| `selfAssessment(): array` | compoe o self-status reflexivo: claims[] por degrau + headline, com hash |

Cada claim:

```text
{ rung, claim (prosa), confidence: high|medium|low (CALIBRADO),
  blind_spots: [limites conhecidos, explicitos], evidence_ref }
```

O headline (resposta a "ADRS 10/10?"):

```text
{ question, assessment (prosa, NUNCA so um numero), confidence,
  is_bare_verdict:false, declared_blind_spots:[...] (nao-vazio) }
```

Envelope `atlas.documentation_reality.reflective_status.v1` (com hash):

```text
schema_version,
level="L-inf (one promoted fragment: R2 epistemic humility)",
fragment="R2_epistemic_humility",
is_one_fragment_not_asymptote:true,
linf_complete:false  (HARD false — nunca declara L-inf concluido),
claims[], headline,
claim_policy { read_only:true, writes:false,
               every_claim_carries_calibrated_uncertainty:true,
               never_confidently_wrong_about_itself:true,
               is_one_linf_fragment_not_the_asymptote:true },
writes:false, reflective_status_hash
```

Comando: `atlas:documentation-reality-reflective-status {--json}`
(auto-descoberto, nao muta nada).

## Composicao — Read-Only

O status **nao re-deriva** verdade; **compoe** os relatorios read-only ja
existentes da escada:

1. `AtlasAaeosImplementationTruthService::coverage()` — a medida doc<->runtime
   (`coverage_pct` / `score_out_of_10`) e, crucial, **seu ponto cego conhecido**:
   ela mede o que **afirma** runtime, nao se o **conjunto** de afirmacoes esta
   completo.
2. `AtlasDocumentationRealityOutcomeGroundingService::gradeAll()` — a contagem
   `outcome_grounded` (O1), que **prova por dado** (nao por asserção) o ponto cego
   "0 = sem validacao no mundo ainda".
3. `AtlasDocumentationRealitySystemService::report()` — o status de prontidao L0
   que ancora o claim L0.

Cada collaborator e **degrade-safe**: se um read model nao puder ser lido,
`readable=false` **baixa a confianca** e **adiciona um blind_spot** — nunca
fabrica um numero limpo.

## Fluxo

```text
qualquer pergunta sobre o proprio ADRS
-> compoe coverage (medida) + O1 (outcome_grounded) + L0 (prontidao)
-> emite um claim calibrado por degrau (cada um validado na hora)
-> anexa: confidence + pontos cegos conhecidos para cada afirmacao
-> headline responde "10/10?" com a medida E os pontos cegos declarados
```

## Regras para IA

- NUNCA emita afirmacao sobre si sem incerteza calibrada — e o drift supremo.
- NUNCA responda "10/10" como veredito nu; o headline sempre carrega pontos cegos.
- NUNCA trate este fragmento como a assintota L-inf inteira; e UM pedaco dela.
- NUNCA declare L-inf concluido (`linf_complete` e sempre `false`).
- NUNCA torne o status mutativo (escrever, executar, alterar).
- NAO altere a doc-mae reflexiva para implemented — ela continua north_star.

## Escopo de Implementacao

Runtime read-only, **um fragmento**. Entrega so o **self-status R2** (service +
comando + teste), composto dos relatorios existentes. O auto-modelo causal (R1) e
a auto-melhoria da modelagem (R3) sao deliberadamente **fora de escopo** — sao
fragmentos posteriores da mesma assintota, e a ausencia deles e ela mesma um ponto
cego declarado no headline.

## Dependencias

- `atlas-documentation-reality-reflective-self-model` (L-inf, doc-mae north-star).
- `AtlasAaeosImplementationTruthService.coverage()` (medida doc<->runtime).
- `AtlasDocumentationRealityOutcomeGroundingService.gradeAll()` (contagem O1).
- `AtlasDocumentationRealitySystemService.report()` (prontidao L0). Todos
  degrade-safe.

## Evidencias

- symbol: `AtlasDocumentationRealityReflectiveStatusService`
- command: `atlas:documentation-reality-reflective-status`
- test: `AtlasDocumentationRealityReflectiveStatusTest`

Drift do doc filho deve ser `false` em
`atlas:aaeos:maturity --capability=atlas-documentation-reality-reflective-status-fragment --json`.

## Riscos

- **ESTE FRAGMENTO NAO E L-inf:** o risco numero um e confundir R2 com a assintota.
  R2 (humildade epistemica) e **um fragmento mensuravel** promovido de L-inf, **NAO
  L-inf**. L-inf permanece a **assintota / bussola permanente** e **nunca** e um
  sprint; alem dele e territorio aberto. Mitigacao em codigo: `linf_complete` e hard
  `false` e `is_one_fragment_not_asymptote` e `true`, sempre.
- **Confiantemente errado sobre si (drift SUPREMO):** o pior rot — corrompe a fonte
  de correcao. Mitigacao: a invariante-mae em codigo — **incerteza calibrada +
  pontos cegos declarados em CADA self-claim**; um claim sem isso lanca
  `LogicException` e nao pode ser emitido. O headline nunca e um "10/10" nu.
- **Reflexao teatral:** self-status bonito sem prova nem incerteza. Mitigacao: os
  numeros sao **compostos** dos relatorios reais (coverage, O1, L0), nao inventados;
  collaborator ausente baixa confianca e declara o ponto cego.
- **Pontos cegos escondidos:** maquiar a resposta omitindo os limites reais.
  Mitigacao: os pontos cegos reais sao **obrigatorios** no headline — sao
  **primeiros incrementos** (incrementos posteriores nao construidos); `outcome_grounded`
  hoje e **0** (sem validacao no mundo ainda); a cobertura mede o que **afirma**
  runtime, **nao** se o conjunto de afirmacoes esta completo; e este status modela o
  proprio **STATUS**, nao toda a realidade.
- **Antropomorfismo:** confundir modelo verificavel com consciencia. Mitigacao:
  aqui reflexao e engenharia (composicao + invariante em codigo), nao metafora.
- **Mexer na doc-mae:** marcar a reflective-self-model como implemented. Mitigacao:
  proibido — ela continua `north_star`; so este fragmento filho e `partial`.

## Exemplos

```text
Pergunta: "o Atlas esta 10/10 em doc<->runtime?"
Resposta L0/L1/L2 (nu): "X/10, drift zero."
Resposta L-inf/R2 (este fragmento):
  "Na medida que TENHO — cobertura doc<->runtime — estou em X/10 (N% backed).
   Mas '10/10' nao e algo que eu possa afirmar sobre mim. Ponto cego conhecido:
   minha cobertura mede o que AFIRMA runtime, nao se o CONJUNTO de afirmacoes esta
   completo. E outcome_grounded hoje e 0: a escada e internamente verdadeira
   (drift-checked), mas ainda NAO validada no mundo. Os degraus L1/L2 sao apenas
   PRIMEIROS INCREMENTOS, e esta propria resposta e UM fragmento (R2) de L-inf —
   nao a assintota."
```

(Repare: a resposta sobre o 10/10 vem com o ponto cego declarado — e o que torna o
sistema impossivel de estar confiantemente errado sobre si.)

## Proximas Acoes

- Manter L-inf como **bussola permanente**, revisada mas nunca concluida.
- Promover o **proximo** fragmento reflexivo apenas com prova e incerteza declarada
  (um por vez).
- Quando sinal de outcome real fluir (O1 > 0), recalibrar os claims L2 com
  confianca maior — sempre com os pontos cegos restantes declarados.
