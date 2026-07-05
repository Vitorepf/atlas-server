---
id: atlas-spec-adversary-obra
type: obra_spec
title: "Obra #2 — Spec-Adversary + Deterministic Freeze Floor (fidelity-OF-spec)"
doc_schema: atlas_canonical_module_doc.v1
status: active
implementation_state: runtime_available
priority: 990
category: engineering-delivery-floor
owner: atlas-ai
canonical_source: docs/engineering-knowledge-base/atlas-spec-adversary-obra.md
tags:
  - atlas-ai
  - engineering-kernel
  - spec-adversary
  - freeze-floor
capabilities:
  - spec_adversary
  - deterministic_freeze_floor
  - frozen_hash_binding
decisions:
  - Spec freeze depende de piso deterministico provider-free.
  - Modelo pode levantar contestacao, mas nao sela freeze.
  - Frozen hash deve ser computado sobre criterios reais canonizados.
maintenance:
  - Atualizar quando SpecComposer, IntentActionExtractor, EngineeringKernel/Spec ou freeze flow mudarem.
  - Nao tratar esta spec como runtime entregue sem codigo, testes e receipts.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - app/Services/Ai/EngineeringKernel
  - app/Services/Ai/Programming/AtlasDev/Pipeline
  - app/Services/Ai/Programming/AtlasDev/Pipeline/IntentActionExtractor.php
  - docs/engineering-knowledge-base/atlas-sovereign-acceptance-gate-obra.md
depends_on:
  - atlas-sovereign-acceptance-gate-obra
summary: >
  A Obra #1 certifica fidelidade-À-spec (a implementação bate com os critérios
  congelados). Falta a outra metade: fidelidade-DA-spec — atacar os critérios de
  aceitação ANTES de congelar, pra uma spec confiantemente ERRADA não produzir um
  green perfeito sobre lixo. Esta obra instala um SpecAdversary onde a autoridade de
  FREEZE vem SÓ de pisos determinísticos provider-free; modelo só levanta flag.
human_summary: >
  Hoje o Atlas prova que o código bate com a spec, mas nunca que a spec está certa.
  Uma spec confiante-e-errada passa por tudo. Esta obra faz a spec ser adversarializada
  antes de virar contrato — e faz isso sem depender de um provider vivo (que cai).
requires_evidence: true
risk_level: high
grounded_by: design-obra2-spec-adversary workflow (4 ground + 2 adversarial-critique agents, file:line)
graph_id: atlas-spec-adversary-obra
graph_title: Spec Adversary Obra
graph_world: atlas
graph_layer: module
graph_kind: runbook
graph_parent: atlas-sovereign-acceptance-gate-obra
graph_status: active
graph_source: repo
repo_paths:
  - docs/engineering-knowledge-base/atlas-spec-adversary-obra.md
  - app/Services/Ai/EngineeringKernel
allowed_changes:
  - Refinar contrato, slices e evidencias da Obra #2.
  - Atualizar paths quando SpecAdversary, SpecComposer ou freeze flow mudarem.
forbidden_changes:
  - Declarar runtime entregue sem codigo, testes verdes e receipts.
  - Permitir que modelo sele freeze ou que provider ausente vire freeze.
flows_to:
  - engineering-kernel-spec-freeze
  - acceptance-gate-certification
unlocks:
  - deterministic-spec-freeze-floor
  - frozen-hash-binding
governs:
  - spec-adversary
  - spec-freeze
evidence:
  - docs/engineering-knowledge-base/atlas-spec-adversary-obra.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
  - php artisan atlas:documentation:enforce --task="manter doc canonica da Obra #2 spec adversary" --feature="spec adversary freeze floor" --strict --json
next_actions:
  - Runtime entregue em 2026-07-04 (S0-S7, 335ac4b9ed..74b739d96c); manter floor, oracle e advisor sob os testes de tests/Unit/Ai/EngineeringKernel/Spec.
  - Manter esta spec alinhada com AcceptanceGate, SpecComposer e EngineeringKernel.
---

# Obra #2 — Spec-Adversary + Deterministic Freeze Floor

> Contrato inviolável que esta obra instala: **ninguém CONGELA uma spec antes de `contest()` passar, e `contest()` só sela FREEZE por prova determinística que um modelo não consegue conversar pra passar.** A Obra #1 matou o fake-green da execução; se a spec estiver confiantemente errada, a Obra #1 sela um green perfeito sobre lixo. Esta obra fecha essa porta.

## Resumo

Spec canônica da Obra #2: instalar `SpecAdversary` e freeze floor determinístico
para provar fidelidade da spec antes que ela vire contrato congelado.

## Papel no Atlas

Fecha a metade epistêmica que a Obra #1 não cobre: não basta a implementação
bater com a spec; a spec precisa resistir a contestação determinística.

## Onde Se Encaixa

Filha da Obra #1 e parte do `EngineeringKernel/Spec`. O resultado alimenta
`AcceptanceGate` com critérios congelados e hash realmente ligado ao conteúdo.

## Contratos

- `contest()` é o único caminho de freeze.
- Authority de freeze vem de pisos determinísticos provider-free.
- Modelo só levanta contestação; concordância não sela freeze.

## Fluxo

Draft + intent + lane entram no `SpecAdversary`; o floor puro valida hash,
discriminação, oracle adequacy, verb fidelity e ambiguity; o advisor assíncrono
só pode elevar contestação; saída é `SpecVerdict`.

## Regras para IA

- Runtime entregue em 2026-07-04 (S0-S7 na main, 335ac4b9ed..74b739d96c); mudanças no piso/oracle só via obra com freeze.
- Nao dar crédito positivo de freeze a modelo.
- Nao aceitar spec verde contra no-op.

## Escopo de Implementacao

Criar contratos, canonicalize/hash-binding, oracle no-op, adapter de spec,
produtor de ambiguidade, advisor contest-only, wiring no freeze e corpus dourado.

**Entregue em 2026-07-04, na main, por slice:** S0+S1 contrato + hash-binding
(`335ac4b9ed`), S2 `SovereignSpecFloor` + oracle no-op (`9fd7fc108d`), S3 oracle
real via `WorkcellExecutor` + `AtlasSpecGateAdapter` (`7f2c722292`), S4 produtor
determinístico de ambiguidade + roteamento de clarificação (`cc7465ff5e`), S5
shadow advisor cross-family três-estados (`d1f5b73cb2`), S6/dogfood oracle
default fail-closed + adapter resolvível (`1396748c99`), S7 recibo selado +
corpus dourado known-bad/known-good (`74b739d96c`). Runtime: 22 classes em
`app/Services/Ai/EngineeringKernel/Spec/`; wiring vivo em
`AtlasDevFastPathOrchestrator.php` (construtor l.76, `contest()` l.175) e
`ForgeObraCertificationService.php` (l.46-56, `contestSddSpec()`).

## Dependencias

- Obra #1 `AcceptanceGate`.
- `SpecComposer`, `IntentActionExtractor` e `WorkcellExecutor`.
- Governance wiper-safe para testes.

## Evidencias

- Este doc.
- Corpus dourado known-bad/known-good em `tests/Unit/Ai/EngineeringKernel/Spec/SpecAdversaryGoldenCorpusTest.php`.
- Testes de contrato e dogfood em `tests/Unit/Ai/EngineeringKernel/Spec/` (`SpecAdversaryContractTest.php`, `Obra2DogfoodTest.php`, `SovereignSpecFloorTest.php`, `WorkcellSpecOracleTest.php`, `SpecAmbiguityRoutingTest.php`, `SpecShadowAdvisorTest.php`).
- Receipts de freeze com provenance e frozen_hash.

## Riscos

- Recriar fake-green na camada de spec.
- Provider ausente virar fail-open.
- Usar contagem de asserts como proxy de discriminação real.

## Exemplos

Uma spec tautológica que fica verde contra no-op deve retornar `refuse` ou
`revise`, nunca `freeze`.

## Proximas Acoes

- Runtime entregue em 2026-07-04 (S0-S7 na main, `335ac4b9ed`..`74b739d96c`); nada a implementar para ativar.
- Manter floor, oracle, advisor e corpus dourado sob os testes de `tests/Unit/Ai/EngineeringKernel/Spec/`; mudanças no piso/oracle só via obra com freeze.

## 0. Por que esta é a Obra #2 (a metade que falta)

A Obra #1 (`AcceptanceGate`) certifica **fidelidade-À-spec**: a implementação satisfaz os critérios congelados, sem fake-green. Mas ela mede fidelidade-À-spec, **nunca fidelidade-DA-spec**. Risco #1 epistêmico: `criteria` confiantemente errada → testes congelados perfeitos → green → entrega lixo com cara de elite. Hoje `author ≠ implementer` (quem escreve o código não é o juiz), mas **NÃO** `author ≠ spec-source` (ninguém ataca se a spec é a spec CERTA). Esta obra instala esse adversário — e o instala do jeito honesto, não do jeito que reintroduz fake-green.

## 1. Estado verificado (a doença) — fundamentado em file:line

| Fato | Evidência |
|---|---|
| **Spec composta por heurística fixa** | `IntentActionExtractor.php:59` = tabela `RECOGNIZED_VERBS` (30+ formas → 8 rótulos); `extract()` (l.125) faz **match por substring** case-insensitive. Colisão real: "add validation" pode extrair o verbo "remov" por substring. `SpecComposer::buildAcceptanceCriteria()` (l.974) depende disso. |
| **Nenhum passo valida a spec, só a forma** | `PromptQualityChecker` (l.127) só checa que `acceptanceCriteria` é **não-vazio** — nunca que é **não-tautológico**. E1/E6 (`IntentFalsificationProbe`, `SpecDrivenConstitutionGate`) rodam **pós-execução contra o DIFF**, nunca contra a validade da spec. |
| **`frozen_hash` liga NADA (buraco herdado da Obra #1)** | `AtlasDevGateAdapter::bundleFromDevEvidence` (l.70-71) lê `criteria_hash`/`frozen_hash` da evidência com `?? ''`; `SovereignHonestyFloor::criteriaHashFrozen` (l.249-257) só checa `hash === hash`, **nunca recomputa** do critério real. `SpecComposer` nunca gera hash. **Dois hashes iguais quaisquer → PROMOTE.** Binding sem prova de binding. |
| **Piso de força de spec desligado E fora do caminho quente** | `spec_amplification` (config/atlas.php:2672) default 0 (OFF, byte-idêntico). O `AtlasLoopSpecAmplificationGate` conta assertions por **regex** (`$this->assert*` — l.50) — proxy de test-count que a própria memória do operador proíbe (`loop-not-proxy-cleanup-feedback`). E o planner de obra (`AtlasLoopObraExecutionAdapter` l.684+) **nunca chama** o gate. |
| **Escape silencioso e2.mode=off** | `SpecComposer` só emite ACs comportamentais com `e2.mode` ligado (l.974). Write task com verbo claro + `e2.mode=off` → spec só com backstops ("command exits 0"), semanticamente vazia — e o `SpecDrivenConstitutionGate` **NO-OPa** (l.125) quando a spec tem zero ACs. As specs mais fracas são exatamente as que todo gate ignora. |
| **Sem produtor de ambiguidade** | AAEOS P1 (`AtlasAaeosGateSignalEvaluator.php:68`) **consome** `ambiguity_tokens` mas nada os **produz**. `AtlasLoopIntentAmbiguityClarifierProposer` (l.17) converte findings→packets mas "no production source of findings today". `AtlasLoopClarificationRequest` (fila durável) existe, flags default OFF. Consumidor ligado a canal vazio → sempre passa. |
| **A máquina de "mutation" existente é de brinquedo** | `AtlasExternalBrainTaskSpecMutationTester` é puro MAS decide "caught" por regex hardcoded na mutação que ele mesmo injetou (`str_starts_with 'must not'`, `mb_strlen<20`), e vários casos retornam `caught=true` incondicionalmente. Prova que um gate interno de brinquedo é auto-consistente; **não prova nada** sobre a spec real. |

**Máquina REAL a promover (não reescrever):** `IntentActionExtractor::RECOGNIZED_VERBS` (fonte única do vocabulário), `SpecComposer::buildAcceptanceCriteria`, `WorkcellExecutor` (interface do EngineeringKernel — roda os testes congelados), `AtlasLoopVaguenessPreScreener` (detector de zero-anchor, regex puro), `AtlasLoopIntentAmbiguityClarifierProposer` + `AtlasLoopClarificationRequest` (fila de clarificação já plumbada), `BudgetMeter` (interface do EngineeringKernel — teto de gasto).

## 2. Objetivo e não-objetivos

**Objetivo (uma frase):** um `SpecAdversary.contest(draft, intent, lane)` onde o FREEZE só é selado por **pisos determinísticos provider-free** (que um modelo não passa conversando), com `frozen_hash` computado sobre o critério real, e onde qualquer sinal baseado em modelo **só levanta CONTEST**, nunca sela FREEZE.

**Não-objetivos (cada um é obra futura):**
- ❌ Refazer a Obra #1 (certificação da implementação) — mas esta obra **conserta** o buraco de hash-binding dela.
- ❌ Detectores não-funcionais (perf/dados/arquitetura) — Obra #3.
- ❌ OBSERVE / COMPOUND / Rivals-bridge — obras posteriores.
- ❌ Reescrever a tabela de verbos — esta obra a **promove** e adiciona uma guarda de word-boundary por cima.

## 3. Arquitetura alvo — o princípio soberano

> **PRINCÍPIO (a espinha da obra, derivado de 2 críticas adversariais convergentes):** a autoridade de FREEZE vem **SÓ de pisos determinísticos, provider-free, puros**. Saídas de modelo (shadow-spec, cross-family) **só levantam flag de CONTEST** — unidirecional (divergência ⇒ contest; concordância ⇒ **zero crédito de freeze**), assíncronas, só quando uma heurística barata cheira ambiguidade, **nunca no caminho quente do freeze, nunca fail-open**. Isso dissolve de uma vez: (a) o problema do provider caído, (b) quem-vigia-o-vigia (a shadow-spec não tem poder de freeze, logo não precisa de vigia), e (c) custo/latência 24/7.

Nova casa: `app/Services/Ai/EngineeringKernel/Spec/` (7ª família de interface do kernel).

```
  draft+intent+lane
        │
        ▼
  ┌─────────────────────────────────────────────────────────────┐
  │  TIER 1 — SovereignSpecFloor  (PURO, provider-free, ÚNICA    │
  │  autoridade de FREEZE; cada piso max(piso, config))          │
  │   • hash_binding   → contest() é o ÚNICO mintador do          │
  │                      frozen_hash = sha256(canonicalize(crit)) │
  │   • discrimination → cada case-class exigida tem ≥1 AC que    │
  │                      fica VERMELHO no no-op stub              │
  │   • oracle_adequacy→ roda os verification_ref congelados      │
  │                      contra um stub no-op via WorkcellExecutor│
  │                      → exige RED genuíno (green-on-noop=REFUSE)│
  │   • verb_fidelity  → word-boundary vs substring; e2.mode=off  │
  │                      com verbo reconhecido e zero AC = REFUSE │
  │   • ambiguity_resolved → todo finding do PRODUTOR determinís- │
  │                      tico foi respondido ou flaggeado         │
  └─────────────────────────────────────────────────────────────┘
        │ passou os pisos?  (senão: REFUSE/HOLD, nunca chama modelo)
        ▼
  ┌─────────────────────────────────────────────────────────────┐
  │  TIER 2 — SpecContestAdvisor  (modelo, ASSÍNCRONO, com teto   │
  │  BudgetMeter; SÓ levanta CONTEST; roda só se Tier-1 passou E  │
  │  heurística barata cheira ambiguidade)                        │
  │   • shadow-spec cross-family → DIVERGÊNCIA ⇒ REVISE (mostra   │
  │     o gap ao operador). CONCORDÂNCIA ⇒ zero crédito.          │
  │   • provider ausente ⇒ estado {unavailable} → provenance,     │
  │     nunca fail-open, nunca trava-pra-sempre                   │
  └─────────────────────────────────────────────────────────────┘
        │
        ▼
  SpecVerdict { freeze | revise | refuse | hold, gaps[], provenance }
```

### 3.1 O contrato do adversário

```php
// app/Services/Ai/EngineeringKernel/Spec/SpecAdversary.php
interface SpecAdversary
{
    /** Sela FREEZE SOMENTE se os pisos determinísticos passarem. Fail-closed. */
    public function contest(SpecDraft $draft, IntentEnvelope $intent, TrustLevel $lane): SpecVerdict;
}
```
- `SpecDraft`: a lista de `acceptanceCriteria` composta (id/description/verification/verification_ref) + os `expected_files`/`forbidden_files`/`non_goals` + o `intent_text` canônico.
- `IntentEnvelope`: o goal cru + as respostas de desambiguação já elicitadas (cache de clarificação).
- `SpecVerdict`: `{freeze|revise|refuse|hold}` + `gaps[]` + `provenance{frozen_hash, divergence_status, spec_source_independence, oracle_mode, ambiguity_findings}`. Fail-closed.
- `TrustLevel` (reusa o enum da Obra #1) — só troca a **testemunha de proveniência**, nunca os pisos.

### 3.2 O `frozen_hash` que REALMENTE liga (conserta os dois obras)

`contest()` é o **único mintador** do `frozen_hash`:
```
frozen_hash = sha256(canonicalize(acceptanceCriteria))
```
computado DENTRO do kernel sobre o critério exato que o adversário inspecionou, retornado em `SpecVerdict.provenance`, escrito na evidência pelo passo de FREEZE — **nunca aceito de upstream**. E a Obra #1 ganha um invariante novo: no `certify()`, **recomputa** `sha256(canonicalize(criteria))` a partir do critério cru carregado no `AcceptanceBundle` (promovido pra carregar a lista) e **REFUSE** se `≠ frozen_hash`. Uma função `canonicalize()` (ordenar chaves, normalizar whitespace, dropar ids voláteis) pinada por golden test fecha o buraco dos DOIS obras de uma vez.

### 3.3 Os pisos determinísticos (Tier 1) — regra de ouro: DISCRIMINAÇÃO, não contagem

Cada piso é `max(piso_soberano, config)` (config só aperta, como na Obra #1). O que mata Goodhart: **um AC só CONTA se DISCRIMINA** — se fica VERMELHO num stub no-op.

| Invariante | Regra determinística (sem provider) |
|---|---|
| `hash_binding` | `frozen_hash` computado sobre o critério real; recomputa-e-compara. |
| `discrimination` | cada case-class exigida (happy/boundary/error/idempotência/segurança/não-funcional-se-tagueada) tem ≥1 AC que **fica RED no no-op stub**. AC tautológico ("exits 0", "implements <verbo>") discrimina nada → conta ZERO. Backstops **excluídos** da contagem. |
| `oracle_adequacy` | sintetiza o impl ERRADO trivial (diff vazio / stub no-op) e roda os `verification_ref` congelados via `WorkcellExecutor` → exige **RED genuíno**. Suíte que fica VERDE contra o no-op = REFUSE. (É execução, não provider.) |
| `verb_fidelity` | match por **word-boundary** (`\b`) vs o substring do compositor; DISCORDÂNCIA = sinal de ambiguidade (não confia cego). E write task com ≥1 verbo reconhecido mas zero AC comportamental (e2.mode=off) = **REFUSE**, nunca escape silencioso. |
| `ambiguity_resolved` | todo `finding` do **produtor determinístico** (null-mutant + colisão + zero-anchor) foi operator-answered (cache) ou explicitamente flaggeado na provenance — nunca ausente-em-silêncio. |

Slots reservados (Obra #3+), default `pass`: `nonfunctional_case_class_for_tagged`.

### 3.4 A witness de proveniência (o único que varia por lane — forma honesta de "author≠spec-source")

`author≠spec-source` é inexequível como correção (hoje o compositor É a spec-source, e a lane autônoma não tem humano nem, muitas vezes, 2º provider). Então vira **binding de proveniência de 3 estados**:

| `spec_source_independence` | Significado | Pode FREEZE? |
|---|---|---|
| `human_witnessed` | humano no loop é a fonte independente (Dev/Forge interativo) | ✅ (interativo) |
| `cross_family_witnessed` | 2º provider family re-derivou e não divergiu | ✅ |
| `self_composed_unwitnessed` | só o compositor viu; sem testemunha independente | ✅ **só em lane interativa**; **HOLD na lane autônoma** |

O estado é **selado no `frozen_hash`** → a Obra #1 RECUSA lavagem cross-lane (uma spec congelada `self_composed_unwitnessed` não pode depois ser certificada como se fosse testemunhada). Isso é a verdade `loop-cannot-deliver-unwitnessed` já na memória — honesto, não bug.

### 3.5 O advisor (Tier 2) — modelo só levanta flag

`SpecContestAdvisor` roda **assíncrono**, com teto de `BudgetMeter`, **só quando** os pisos puros passaram **E** uma heurística barata (VaguenessPreScreener zero-anchor, ou token de ambiguidade não-resolvido) dispara. Nunca no caminho quente. Regras:
- shadow-spec cross-family: **DIVERGÊNCIA ⇒ REVISE** (mostra o gap específico ao operador); **CONCORDÂNCIA ⇒ zero crédito** de freeze (guarda contra alucinação correlacionada — GLM+MiniMax compartilham modo de falha; concordância confiante-e-errada NÃO pode selar).
- provider ausente ⇒ estado `{unavailable}` → provenance `divergence_status=unwitnessed`, **nunca fail-open** (que fabricaria testemunho falso = fake-green uma camada acima), **nunca trava-pra-sempre** (vira HOLD/clarification, não bloqueio eterno).
- cap de loops REVISE (1–2) → depois HOLD-for-human, anti-livelock.

## 4. Slices (ordenados, cada um verde + prova própria)

> Padrão strangler (adapter→cutover→delete), como a Obra #1. Do Slice 2 em diante a obra se **dogfooda** — mas o dogfood é só smoke; a prova real é o corpus dourado (S7).

**Slice 0 — Contrato puro.** `SpecAdversary` (interface), `SpecDraft`/`IntentEnvelope`/`SpecVerdict` (DTOs), estado de proveniência de 3 valores. *Prova:* teste de DTO/enum; `php -l`; zero consumer.

**Slice 1 — `canonicalize()` + hash-binding (conserta os DOIS obras).** `contest()` minta `frozen_hash`; a `SovereignHonestyFloor` da Obra #1 ganha recomputa-e-compara a partir do critério cru no bundle. *Prova:* golden test — duas listas de critério equivalentes hasheiam IGUAL; uma driftada hasheia DIFERENTE; e um bundle com `frozen_hash` que não corresponde ao critério cru é **REFUSED** pela Obra #1.

**Slice 2 — O oráculo executável no-op (o núcleo anti-tautologia).** `discrimination` + `oracle_adequacy` via `WorkcellExecutor` contra stub no-op. *Prova:* uma spec de ACs tautológicos (verde no no-op) é **REFUSED** com blocker `oracle_adequacy`; uma spec discriminante passa. Este é o dente da obra.

**Slice 3 — `AtlasSpecGateAdapter` (promove a máquina real).** Compõe `IntentActionExtractor` (com guarda word-boundary), `SpecComposer` criteria, `WorkcellExecutor`, `VaguenessPreScreener` atrás da interface. *Prova:* regressão — o caminho green atual da spec do Dev continua congelando, agora via o adversário.

**Slice 4 — O PRODUTOR de ambiguidade (fecha o consumidor-vazio) + unificação.** Null-mutant + colisão word-boundary + zero-anchor viram a fonte única de `ambiguity_findings`, ligada ao `AtlasLoopIntentAmbiguityClarifierProposer`→`AtlasLoopClarificationRequest`; unifica os 3 gates OFF-by-default atrás desse produtor. *Prova:* uma spec com ambiguidade suprimida produz findings → **HOLD** com clarification enfileirada (no disco de teste dedicado, nunca pgsql vivo).

**Slice 5 — `SpecContestAdvisor` (Tier 2, async, budget-capped, CONTEST-only) + proveniência de 3 estados + split por lane.** *Prova:* família ausente ⇒ **HOLD** (não freeze, não bloqueio eterno); lane autônoma `self_composed_unwitnessed` ⇒ **HOLD**; DIVERGÊNCIA ⇒ REVISE; CONCORDÂNCIA ⇒ zero crédito (não sela sozinha).

**Slice 6 — Wire no FREEZE + matar o escape.** Ninguém congela antes de `contest()==freeze`; o `e2.mode=off`-com-verbo deixa de ser escape silencioso. *Prova:* `rg` mostra o FREEZE roteando pelo adversário; teste assere que o caminho antigo não congela spec vazia.

**Slice 7 — Recibo selado + CORPUS DOURADO (o oráculo-do-oráculo).** Recibo de proveniência de spec; e um corpus fixo de specs **KNOWN-BAD** (colisão-de-verbo, AC-tautológico, passável-no-no-op, ambiguidade-suprimida) que o adversário DEVE `revise`/`refuse`, + **KNOWN-GOOD** que DEVE `freeze` — golden, determinístico, wiper-safe, pinado em CI. *Prova:* o corpus inteiro verde; falha ruidosamente se um piso futuro virar rubber stamp.

## 5. Critérios de aceitação da OBRA (meta)

Só é `done` quando TODOS provados por teste:
1. **Hash liga de verdade:** um `frozen_hash` que não corresponde ao critério cru é REFUSED — nos DOIS obras (Slice 1).
2. **Anti-tautologia executável:** spec verde-no-no-op é REFUSED por `oracle_adequacy` (Slice 2).
3. **Nunca fail-open:** provider ausente ⇒ HOLD, nunca freeze silencioso, nunca bloqueio eterno (Slice 5).
4. **Modelo não sela:** concordância de shadow-spec dá zero crédito de freeze; só divergência é acionável (Slice 5).
5. **Produtor de ambiguidade existe:** o canal deixa de ser sempre-vazio; ambiguidade suprimida ⇒ HOLD (Slice 4).
6. **Sem escape silencioso:** write task com verbo + zero AC = REFUSE (Slice 2/6).
7. **Corpus dourado discrimina:** todo known-bad é recusado, todo known-good congela (Slice 7).
8. **Dogfood + wiper-safe:** o floor é 100% puro (zero Eloquent, zero DB); a persistência vive em adapter no disco de teste; a própria obra passa pelo adversário como smoke.

## 6. Riscos + mitigações

- **Provider caído (Hermes/Claude 3rd-party block, GLM 429, MiniMax futuro):** resolvido pelo design — a autoridade de freeze é o piso determinístico; o modelo é advisor async. Ausência ⇒ HOLD de 1ª classe, selado na proveniência.
- **Cicatriz do wiper:** o `SovereignSpecFloor` é PURO (valor-entra/veredito-sai, sem Eloquent, sem `config()` com efeito). Proveniência e hold voltam no struct; persistência só no adapter, no disco `atlas_serving`/teste, nunca no container vivo. Testes do floor = structs puros, zero RefreshDatabase.
- **Goodhart/proxy de test-count:** proibido promover o contador regex de assertions. A régua é DISCRIMINAÇÃO (RED no no-op), não contagem. Backstops excluídos.
- **Quem-vigia-o-vigia / alucinação correlacionada:** a shadow-spec não tem poder de freeze (só flag), então não precisa de vigia; concordância = zero crédito.
- **Custo 24/7:** pisos puros off-provider (sub-ms); cross-family async, só quando o barato passa + ambiguidade cheira, com teto `BudgetMeter` por hora.
- **Colisão com worker codex:** branch; aditivo-primeiro; anunciar no blackboard antes de tocar `EngineeringKernel/`, `Programming/AtlasDev/Pipeline/`.
- **Dogfood circular:** dogfood é só smoke; a prova de adequação é o corpus dourado known-bad/known-good, não a auto-passagem.

## 7. Como saber que ficou perfeita

O adversário é perfeito quando: (a) uma spec confiantemente-errada (tautológica / verde-no-no-op / verbo-colidido / ambiguidade-suprimida) **não congela** — provado pelo corpus dourado; (b) com o 2º provider **caído**, o sistema **segura** em vez de fabricar testemunho falso ou travar pra sempre; (c) `frozen_hash` prova ligar ao critério que foi atacado — fechando o buraco que a própria Obra #1 tinha; (d) o floor é puro (zero DB) e nenhum sinal de crédito-positivo vem de um modelo. Aí — e só aí — o Atlas passa a certificar não só *"a implementação bate com a spec"* (Obra #1) mas *"a spec é a spec certa"* (Obra #2), e a fidelidade-DA-spec deixa de ser um buraco aberto.
