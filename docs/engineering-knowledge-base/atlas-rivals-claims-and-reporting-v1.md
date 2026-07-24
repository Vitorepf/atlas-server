---
id: atlas-rivals-claims-and-reporting-v1
type: engineering_knowledge
title: Atlas Rivals — Claims and Reporting v1
status: active
category: programming
priority: 99
summary: Hard gates de claim_allowed, escopo obrigatorio e eixos do relatorio Rivals 2.0. Proibe best overall e media dos N como veredito.
tags:
  - atlas
  - rivals
  - claims
  - reporting
  - evidence
capabilities:
  - rivals_claim_gates
  - rivals_scoped_reporting
decisions:
  - claim_allowed=false e o default petreo.
  - Pipeline valido nao implica claim; readiness e separada em pipeline, internal e public.
  - Harness, fixture, mockllm e arms harness_* nunca geram claim interno ou publico.
  - Todo claim e escopado; nunca best overall nem score unico colapsado.
  - Media global dos adapters e non-claim dashboard only.
  - Relatorio deve expor resolucao, custo, custo/tarefa, tokens, tempo, estabilidade e uplift quando suportado.
  - Relatorio empresarial consolidado Fase A vive em atlas-rivals-phase-a-enterprise-report-v1 (agregado nunca claim).
  - Fato Atlas = pipeline + measurement + events_complete + native execute + (uplift) bridge 100%; fora disso = nao-fato.
  - intelligence_rate exclui environment_failure; ITT inclui env e permanece separado/rotulado.
  - events_incomplete e blocker de claim interno (Adjudicator).
maintenance:
  - Sincronizar gates com Adjudicator e config atlas_rivals.claim.
related_paths:
  - docs/engineering-knowledge-base/atlas-rivals-curriculum-ladder-and-anti-ceiling-fallacy.md
  - app/Services/Ai/Rivals/Core/Adjudicator.php
  - app/Services/Ai/Rivals/Core/ReportBuilder.php
  - app/Services/Ai/Rivals/Core/EnterpriseReportBuilder.php
  - app/Services/Ai/Rivals/Core/EvidencePackBuilder.php
  - app/Services/Ai/Rivals/Core/ReplayVerifier.php
  - app/Services/Ai/Rivals/Core/ResultLedger.php
  - app/Services/Ai/Rivals/Core/AtlasUpliftRunner.php
  - docs/engineering-knowledge-base/atlas-rivals-phase-a-enterprise-report-v1.md
  - docs/engineering-knowledge-base/thesis/rivals-validation.md
  - config/atlas_rivals.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-rivals-claims-and-reporting-v1
graph_title: Atlas Rivals Claims and Reporting v1
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-rivals-product-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - app/Services/Ai/Rivals/Core/Adjudicator.php
  - app/Services/Ai/Rivals/Core/ReportBuilder.php
allowed_changes:
  - Apertar gates e eixos de report com testes.
forbidden_changes:
  - Colapsar dimensoes em score unico de claim.
  - Aceitar claim sem custo/tempo/replay/evidence.
depends_on:
  - atlas-rivals-product-v1
  - atlas-rivals-structure-v1
flows_to:
  - atlas-rivals-operator-runbook-v1
unlocks:
  - rivals_honest_claims
governs:
  - rivals_claims
evidence:
  - app/Services/Ai/Rivals/Core/Adjudicator.php
  - tests/Feature/Ai/Rivals/RivalsFakePipelineTest.php
required_tests:
  - php artisan test --filter=RivalsFakePipeline
requires_evidence: true
risk_level: high
next_actions:
  - Manter report-all alinhado aos eixos; nunca emitir claim sem escopo.
---

# Atlas Rivals — Claims and Reporting v1

## PÉTRO — Curriculum ladder / anti-ceiling fallacy

**Canon:** `docs/engineering-knowledge-base/atlas-rivals-curriculum-ladder-and-anti-ceiling-fallacy.md`

- **80–100% num teste = aprovação de NÍVEL escolar**, nunca teto do modelo, do Atlas, nem “inteligência suprema”.
- Suite saturada → **S_sanity** (regressão/paridade). Multiplicador e claim forte → só em **S_frontier** não saturado.
- Raw ou Atlas dominou o frontier → **promover L_{k+1}** (próximo livro). **Proibido** dizer “50× / N×M impossível para sempre porque já faz 80%”.
- **2026 ≠ 2030 ≠ 2040 ≠ 2050**; o currículo **sobe**. Atlas 80% em teste medíocre = **inútil como meta final** — elevar o nível.
- Essência Rivals: medir com/sem Atlas e **sempre melhorar o Atlas no frontier**, não congelar tabuada.



## Resumo

Quando um resultado pode virar **claim** e o que o **relatorio** deve mostrar. Fail-closed.

## Papel no Atlas

Contrato de honestidade do benchmark. Absorve do 1.0 (conceito B): evidence pack hash, replay fail-closed, claim honesty / repetitions, enterprise claim gate da thesis.

## Onde Se Encaixa

Adjudicator, ReportBuilder, EvidencePackBuilder, ReplayVerifier, ResultLedger, AtlasUpliftRunner.

## Contratos

### Readiness e hard gates

O payload separa:

- `pipeline_valid`: schema, receipt set, evidence e integrity replay validos;
- `internal_claim_allowed`: pipeline valido + tier production/public + hard gates + amostra adequada;
- `public_claim_allowed`: internal permitido + Enterprise Claim Gate completo.

`claim_allowed` permanece apenas como alias de compatibilidade para
`internal_claim_allowed`. Default de ambos os claims e **false**.

Tiers: `harness | diagnostic | production | public`. Um run harness/diagnostic
pode ter `pipeline_valid=true`, mas nunca claim interno/publico.

Claim interno true so com todos:

1. Schema valido (plan/receipts)
2. Receipts completos para cada case×arm×rep planejado
3. Artifacts verificados (evidence pack; nenhum `present:false` critico)
4. `repetitions >= claim.min_repetitions` (config, default 3)
5. Custo, tempo e tokens com presenca provada em todo receipt remoto
6. Replay verificado (quando exigido)
7. Claim **escopado**
8. `judge_config` pinned quando o plan declara juiz
9. Zero mistura de configs no mesmo claim
10. Tier `production|public`; zero fixture/mock/harness model/receipt
11. Presenca real (nao zero inventado) de custo/tempo
12. Sample policy pre-registrada e adequada
13. Native execution receipt `status=success`, `runner.mode=execute` e
    cardinalidade igual ao manifest; fixture/normalize-only nunca promove
14. Zero `environment_failure`; erro de provider/verifier nao vira model failure
15. `events.jsonl` vivo completo (`events_incomplete` bloqueia claim)
16. Rebuild de report/adjudication/evidence exige re-append ledger semantico
    (epoch atual limpo; historico corrompido vai para quarantine)
17. Cada unidade tem stdout e stderr legíveis, hash-pinados pelo native receipt;
    todo receipt não-success tem `failure_reason` não vazio e o report o expõe

### Contrato “Fato Atlas” vs diagnostico

- **Fato Atlas**: passa os gates internos acima + (enterprise) eixos
  `pipeline|measurement|intelligence|claim` com `claim.internal_ok` e
  `events_complete`.
- **Diagnostico / nao-fato**: pipeline_ok sem claim, measurement parcial,
  uplift `diagnostic_only` / `excluded_pair_keys` por proof, harness_omit.
- **intelligence_rate**: sucessos / (success + model_failure) — **exclui**
  `environment_failure`. ITT continua incluindo env e deve ser rotulado.
- Battery claim-grade **aborta** se `environment_failure_rate` exceder o
  teto configurado — nao empilha suites “ok + 0%” por env.

### Preregistration e inferencia

Antes de spend, `preregistration.json` congela primary/secondary endpoints,
cases, arms, repetitions, seed, budget, pairing, alpha/power, CI-width,
missing/outlier/stopping rules. Mudanca cria nova revision.

- primary population: todos os attempts planejados (ITT);
- sem imputation; environment failures aparecem no ITT e em taxa separada;
- success rate: Wilson 95%;
- custo/tempo: mediana, p95 e bootstrap CI;
- uplift: pares exatos `case_id×repetition`, mesma matriz nos dois arms;
- amostra abaixo de `min_distinct_cases` ou CI largo ⇒ `not_ready`;
- secondary/post-hoc nunca inverte primary endpoint.

### Escopo obrigatorio do claim

`(task_type, suite, cases, model, runtime, budget, environment, repetitions, judge_config?)`

Proibido: "best overall", "media dos 10 = vencedor", score unico colapsado.

### Eixos do relatorio (multi-eixo)

| Eixo | Nota |
|---|---|
| Resolucao / status | success rate por escopo |
| Qualidade / dimensoes | quando a suite nativa expoe (ex. senior_swe); sem colapso |
| Custo | USD real do receipt |
| Custo por tarefa | agregado honesto no escopo |
| Tokens | in/out quando presentes |
| Tempo | wall_ms |
| Estabilidade | variancia entre repetitions |
| Uplift | bare vs atlas_*; `uplift_supported=false` se Atlas nao rodou |
| Difficulty flags | suite too_easy / borderline (DifficultyCalibrator) |
| Readiness | pipeline/internal/public separados e blockers explicitos |

### Media global

Permitida apenas como **dashboard non-claim** (exploratorio). Nunca alimenta `claim_allowed=true`.

### Uplift

Mesmo `model_id` nos dois bracos. Sem wrapper em `runtime_commands` ⇒ nao simular.
Para provider remoto, bare exige `metadata.direct_provider.real_provider=true`;
Atlas exige `metadata.runtime_bridge.real_provider=true`, provider/model
observados iguais ao lock, fair-mode verdadeiro e usage presente. O proof e
derivado do caminho executado; nunca e inferido do nome do arm.

### Closure

Fase A nao conta apenas `pipeline_valid`. Cada suite precisa de
`internal_claim_allowed=true`, `statistical_analysis.adequate=true`, report hash,
bundle e replay validos, comandos independentes e workspace evidence clean.
`closure --verify` recomputa gates vivos; um receipt historicamente valido nao
continua autorizado depois de smoke/ledger/workspace/runtime degradarem.

### Enterprise claim gate (thesis)

Antes de claim publico/enterprise: baseline real, isolation, model lock, sample minimo, replay hash-verified, falhas de ambiente separadas de qualidade de modelo. Falhou qualquer item ⇒ `not_ready`.

### Conceitos B absorvidos do 1.0

- Evidence pack com hash por artefato e `present:false + reason_missing`
- Replay sha256 fail-closed
- Arm = model×runtime
- Repetitions para variancia
- Gate de claim honesto (sem scoring theater AdjudicatorV2)

## Fluxo

`verify` → `adjudicate` (grava ledger) → `report` / `report-all` → opcional `uplift`.

## Regras para IA

Nao inventar claim_allowed. Nao colapsar dimensoes. Separar environment failure de model failure.

## Escopo de Implementacao

Core Adjudicator/Report/Evidence/Replay/Ledger/Uplift + `config.atlas_rivals.claim`.

## Dependencias

product-v1, structure-v1; thesis para enterprise gate.

## Evidencias

Pipeline fake com `pipeline_valid=true`, mas
`internal_claim_allowed=false`/`public_claim_allowed=false`; ledger hash chain
verified.

## Riscos

Report bonito com claim falso; media usada como KPI interno (Goodhart).

## Exemplos

```bash
php artisan atlas:rivals verify --run=<id> --json
php artisan atlas:rivals adjudicate --run=<id> --json
php artisan atlas:rivals report --run=<id> --json
php artisan atlas:rivals uplift --run=<id> --model=claude_opus_4_8 --json
```

## Proximas Acoes

Manter eixos no ReportBuilder; documentar qualquer novo blocker no Adjudicator aqui.
