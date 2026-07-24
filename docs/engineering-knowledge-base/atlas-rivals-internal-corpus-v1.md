---
id: atlas-rivals-internal-corpus-v1
type: engineering_knowledge
title: Atlas Rivals — Internal Corpus v1 (AtlasBench / Elite)
status: active
category: programming
priority: 98
summary: Corpus interno do Rivals 2.0 — AtlasBench e Elite Reality Suite. Mineracao a partir de git real, anti-contaminacao, calibracao de dificuldade. Caminho CursorBench-like do Atlas.
tags:
  - atlas
  - rivals
  - atlasbench
  - elite
  - contamination
capabilities:
  - rivals_internal_corpus
  - rivals_atlasbench
  - rivals_elite_reality_suite
decisions:
  - Corpus interno e o coracao da Fase B; externos sao referencia.
  - Cases frescos minerados de commits reais; corpus auto-autorado do 1.0 permanece morto.
  - ContaminationGuard e DifficultyCalibrator sao obrigatorios para claim interno.
maintenance:
  - Atualizar quando pisos de mine/diff ou bandas de dificuldade mudarem no config.
related_paths:
  - docs/engineering-knowledge-base/atlas-rivals-curriculum-ladder-and-anti-ceiling-fallacy.md
  - config/atlas_rivals.php
  - app/Services/Ai/Rivals/Adapters/AtlasBenchSuiteAdapter.php
  - app/Services/Ai/Rivals/Adapters/EliteRealitySuiteAdapter.php
  - app/Services/Ai/Rivals/Core/ContaminationGuard.php
  - app/Services/Ai/Rivals/Core/DifficultyCalibrator.php
  - app/Services/Ai/Rivals/Core/RealityScoreCard.php
  - docs/engineering-knowledge-base/atlas-rivals-structure-v1.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-rivals-internal-corpus-v1
graph_title: Atlas Rivals Internal Corpus v1
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-rivals-structure-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - app/Services/Ai/Rivals/Adapters/AtlasBenchSuiteAdapter.php
  - app/Services/Ai/Rivals/Adapters/EliteRealitySuiteAdapter.php
  - app/Services/Ai/Rivals/Core/ContaminationGuard.php
allowed_changes:
  - Ajustar mineracao e guards com testes e evidencia.
forbidden_changes:
  - Reintroduzir corpus industrial auto-autorado do ForgeRivals 1.0.
depends_on:
  - atlas-rivals-structure-v1
flows_to:
  - atlas-rivals-claims-and-reporting-v1
unlocks:
  - rivals_phase_b
governs:
  - rivals_internal_corpus
evidence:
  - app/Services/Ai/Rivals/Core/ContaminationGuard.php
  - tests/Unit/Ai/Rivals
required_tests:
  - php artisan test --filter=ContaminationGuard
requires_evidence: true
risk_level: high
next_actions:
  - Ampliar mine window com cases elite_valid; refresh periodico.
---

# Atlas Rivals — Internal Corpus v1 (AtlasBench / Elite)

## PÉTRO — Curriculum ladder / anti-ceiling fallacy

**Canon:** `docs/engineering-knowledge-base/atlas-rivals-curriculum-ladder-and-anti-ceiling-fallacy.md`

- **80–100% num teste = aprovação de NÍVEL escolar**, nunca teto do modelo, do Atlas, nem “inteligência suprema”.
- Suite saturada → **S_sanity** (regressão/paridade). Multiplicador e claim forte → só em **S_frontier** não saturado.
- Raw ou Atlas dominou o frontier → **promover L_{k+1}** (próximo livro). **Proibido** dizer “50× / N×M impossível para sempre porque já faz 80%”.
- **2026 ≠ 2030 ≠ 2040 ≠ 2050**; o currículo **sobe**. Atlas 80% em teste medíocre = **inútil como meta final** — elevar o nível.
- Essência Rivals: medir com/sem Atlas e **sempre melhorar o Atlas no frontier**, não congelar tabuada.



## Resumo

O corpus **interno** e o equivalente Atlas do CursorBench: tarefas derivadas de trabalho real (commits), prompts subespecificados o bastante para engenharia real, juizo local, refresh anti-contaminacao.

## Papel no Atlas

Fase B de `atlas-rivals-structure-v1`. Diferencia modelos onde benches publicos saturam; ancora uplift Atlas em cases do proprio ecossistema.

## Onde Se Encaixa

- AtlasBench: `Adapters/AtlasBenchSuiteAdapter` + config `atlasbench`
- Elite: `Adapters/EliteRealitySuiteAdapter` + config `elite` (pisos mais duros)
- Guards: ContaminationGuard, DifficultyCalibrator, RealityScoreCard

## Contratos

### Mineracao (estilo SWE-smith / Blame)

1. Olhar historico git do repo Atlas (ou path configurado).
2. Selecionar commits com diff acima do piso (`min_diff_lines`, `min_code_files`).
3. Reverter o commit na worktree isolada e pedir reimplementacao.
4. Check = testes reais tocados pelo commit (provider-free na geracao).

### Anti-Goodhart / anti-contaminacao

- **ContaminationGuard:** case long-lived expira (`contamination.max_case_age_days`).
- **DifficultyCalibrator:** se bare frontier passa demais, a **suite** e acusada (too_easy), nao celebrada.
- **RealityScoreCard:** patch inchado vs golden deixa de contar como minimal.
- Proibido corpus industrial auto-autorado (morto no rebuild map grupo D).

### Conceitos herdados do 1.0 (grupo B — so conceito)

- Provisionamento de fixture por case (worktree isolada).
- Evidence hash + `present:false + reason_missing`.
- Arm = `model_id@runtime`.

## Fluxo

`atlas:rivals mine` → cases em storage → plan/run com arms → evidence/replay → adjudicate → report escopado.

## Regras para IA

Nao fabricar cases sinteticos para "fechar" claim. Nao baixar pisos de dificuldade para verde falso. Preferir Elite quando a pergunta for senior/long-horizon real.

## Escopo de Implementacao

Adapters AtlasBench/Elite + Core guards + chaves `atlasbench` / `elite` / `contamination` / `difficulty` / `reality` no config.

## Dependencias

structure-v1, product-v1.

## Evidencias

Unit tests dos guards; mine receipts; difficulty_flags no report quando suite easy demais.

## Riscos

Fixture sem `.git` quebra mine em CI; corpus pequeno demais; overfit ao repo Atlas.

## Exemplos

```bash
php artisan atlas:rivals mine --limit=5 --json
php artisan atlas:rivals run-bench --json
```

## Proximas Acoes

Crescer corpus elite_valid; amarrar refresh periodico ao ContaminationGuard.
