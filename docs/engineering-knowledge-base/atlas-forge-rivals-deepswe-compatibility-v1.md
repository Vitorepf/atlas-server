---
id: atlas-forge-rivals-deepswe-compatibility-v1
type: engineering_knowledge
title: Atlas Forge Rivals DeepSWE Compatibility v1
status: active
category: programming-forge
priority: 97
summary: Compatibilidade DeepSWE/Harbor/Pier para transformar Rivals em orquestrador de benchmarks externos reproduziveis com task manifests, verifiers programaticos, trajectory packs e claim gates fail-closed.
tags:
  - atlas
  - forge
  - rivals
  - deepswe
  - harbor
  - pier
  - reproducible-benchmark
capabilities:
  - deepswe_task_manifest_readiness
  - harbor_task_parser
  - pier_plan_without_provider_call
  - pier_result_ingest_without_provider_call
  - pier_batch_result_ingest_without_provider_call
  - deepswe_artifacts_to_rivals_evidence
  - external_battery_matrix_from_deepswe_runs
  - external_batch_to_provider_performance_ledger
  - external_batch_decide_signal_projection
  - external_batch_statistical_repeat_readiness
  - benchmark_data_contamination_guard
  - programmatic_verifier_claim_gate
decisions:
  - Rivals deve ser compativel com DeepSWE/Harbor/Pier em vez de tentar substituir esse padrao.
  - `solution/` e referencia para revisao humana, nunca input do agente e nunca conteudo em JSON do Rivals.
  - Readiness, dry-run e ingestao de resultado externo nao spawnam Pier, Docker, agentes CLI ou providers.
  - Resultado externo so entra no Rivals como evidence importado: patch, trajectory, logs, verifier result e task manifest hash.
  - Batch externo deve reutilizar battery-evidence, battery-verify-evidence e matrix-report existentes; nao criar relatorio paralelo.
  - Batch externo verificado deve alimentar Provider Performance Ledger, readiness estatistica segmentada e Decide Signal como advisory-only.
  - Score forte exige verifier verde, trajectory, patch, replay e matrix lock.
  - Rivals emits measured evidence; Atlas Decide decides model routing.
maintenance:
  - Atualizar quando DeepSWE, Harbor ou Pier mudarem formato de task, trajectory ou comando.
  - Manter o parser read-only e fail-closed em readiness/dry-run.
  - Nao expor conteudo de benchmark ou solution em JSON amplo.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - app/Services/Ai/Programming/ForgeRivals/DeepSwe/AtlasForgeRivalsDeepSweTaskParserService.php
  - app/Services/Ai/Programming/ForgeRivals/DeepSwe/AtlasForgeRivalsDeepSweCompatibilityService.php
  - app/Services/Ai/Programming/ForgeRivals/DeepSwe/AtlasForgeRivalsDeepSweResultIngestService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsActionDispatcher.php
  - app/Console/Commands/AtlasForgeRivalsCommand.php
  - tests/Feature/Ai/Programming/AtlasForgeRivalsDeepSweCompatibilityTest.php
doc_schema: atlas_canonical_module_doc.v1
owner: programming_rivals
graph_id: atlas-forge-rivals-deepswe-compatibility-v1
graph_title: Atlas Forge Rivals DeepSWE Compatibility v1
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-forge-rivals-provider-arena-v2
graph_status: active
graph_source: repo
canonical_source: docs/engineering-knowledge-base/atlas-forge-rivals-deepswe-compatibility-v1.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-forge-rivals-deepswe-compatibility-v1.md
  - app/Services/Ai/Programming/ForgeRivals/DeepSwe/AtlasForgeRivalsDeepSweTaskParserService.php
  - app/Services/Ai/Programming/ForgeRivals/DeepSwe/AtlasForgeRivalsDeepSweCompatibilityService.php
  - app/Services/Ai/Programming/ForgeRivals/DeepSwe/AtlasForgeRivalsDeepSweResultIngestService.php
  - app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsCorpusPlannerService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsArenaRunService.php
  - tests/Feature/Ai/Programming/AtlasForgeRivalsDeepSweCompatibilityTest.php
allowed_changes:
  - Expandir parser Harbor e ingestao Pier preservando data contamination guards.
  - Integrar results reais somente com evidence/replay/matrix completos.
forbidden_changes:
  - Expor conteudo de `solution/` ao agente.
  - Expor texto de benchmark em JSON amplo sem necessidade operacional.
  - Chamar provider, Pier ou Docker em readiness.
  - Promover claim externo sem reproducibilidade e aprovacao humana.
  - Alterar provider topology do Atlas Decide.
depends_on:
  - atlas-forge-rivals-provider-arena-v2
  - atlas-forge-rivals-next-runner-architecture-v1
flows_to:
  - atlas-forge-rivals-intelligence-ledger-v1
  - atlas-decide
unlocks:
  - rivals_deepswe_readiness
  - rivals_harbor_task_manifest_parser
  - rivals_pier_plan_without_provider_call
  - rivals_deepswe_result_ingest
  - rivals_deepswe_batch_ingest
  - rivals_deepswe_batch_ledger_feed
  - rivals_deepswe_batch_decide_signal
governs:
  - rivals_deepswe_case_source
  - rivals_external_benchmark_evidence_contract
  - rivals_benchmark_data_contamination_guard
evidence:
  - "php artisan test --filter='AtlasForgeRivalsDeepSweCompatibilityTest'"
  - "php artisan atlas:forge:rivals deepswe --deepswe-path=<path> --json"
  - "php artisan atlas:forge:rivals deepswe-ingest --input=<pier-result-root> --deepswe-path=<task-dir> --json"
  - "php artisan atlas:forge:rivals deepswe-batch-ingest --input=<pier-results-root> --deepswe-path=<tasks-root> --json"
  - "php artisan atlas:forge:rivals run-arena --case-set=deepswe --deepswe-path=<path> --dry-run --json"
required_tests:
  - "php artisan test --filter='AtlasForgeRivalsDeepSweCompatibilityTest'"
  - "php artisan atlas:forge:rivals deepswe --deepswe-path=<path> --json"
  - "php artisan atlas:forge:rivals deepswe-ingest --input=<pier-result-root> --deepswe-path=<task-dir> --json"
  - "php artisan atlas:forge:rivals deepswe-batch-ingest --input=<pier-results-root> --deepswe-path=<tasks-root> --json"
requires_evidence: true
risk_level: critical
next_actions:
  - Expandir ingestao de resultados Pier para multi-repeat estatistico por task.
  - Alimentar Provider Performance Ledger e Decide Signal a partir de baterias externas verificadas.
  - Criar adaptador Atlas Forge Pier-compatible antes de qualquer comparacao externa forte.
---
# Atlas Forge Rivals DeepSWE Compatibility v1

## Resumo

Compatibilidade DeepSWE/Harbor/Pier para que Rivals deixe de ser apenas uma
bateria interna e passe a entender tarefas externas reproduziveis, com verifier
programatico e evidence pack forte.

## Papel no Atlas

Este canon move Rivals na direcao de benchmark externo confiavel e
reproduzivel. DeepSWE/Harbor fornece o formato de tarefas; Pier fornece o trilho
de execucao sandboxed; Rivals fornece governanca, evidence, replay, matrix,
report, ledger e sinal consultivo para Atlas Decide.

Rivals emits measured evidence; Atlas Decide decides model routing.

## Onde Se Encaixa

Fica entre o Provider Arena e os executores externos. O parser cria manifests
read-only; o planner permite `case_set=deepswe`; a ingestao transforma artifacts
ja produzidos por runner externo em run Rivals replayable. O executor real ainda
permanece bloqueado ate haver runbook Pier/runner aprovado.

## Contratos

Contratos obrigatorios:

- Readiness nao chama Pier, Docker, agente CLI ou provider.
- Ingestao `deepswe-ingest` tambem nao chama Pier, Docker, agente CLI ou
  provider; ela apenas copia/hash artifacts ja existentes.
- `solution/` nunca entra no prompt do agente.
- Conteudo de `instruction.md` nao e emitido em JSON amplo; o output usa path,
  hash e bytes.
- Verifier programatico e obrigatorio.
- Resultado externo importado exige patch, trajectory, verifier verde e task
  manifest; sem isso o run fica blocked.
- Score forte exige trajectory, patch, verifier verde, replay e matrix lock.
- Toda saida e advisory-only para Atlas Decide.

## Fluxo

1. Operador passa `--deepswe-path`.
2. Parser descobre task root ou lista de tasks.
3. Cada task valida `task.toml`, `instruction.md`, `tests/test.sh` e
   `tests/test.patch`.
4. Readiness emite hashes e plano Pier sem executar.
5. `run-arena --case-set=deepswe --dry-run` consome o mesmo manifest externo.
6. `deepswe-ingest --input=<result-root>` importa dois arms ja executados
   (`atlas`/`rival` ou `arm_a`/`arm_b`) e materializa `manifest.json`,
   receipts, patches, logs, evidence pack e replay.
7. `deepswe-batch-ingest --input=<results-root>` descobre diretorios de
   resultado multi-task, cria um run Rivals por task, agrega
   `battery-evidence`, roda `battery-verify-evidence` e produz `matrix-report`
   consultivo.
8. Cada run externo importado tambem alimenta o Provider Performance Ledger
   com `case_id`, `task_id`, `case_source`, `task_category`,
   `difficulty_level`, `difficulty_weight`, `role`, provider/modelo e
   scorecard. Em seguida o batch emite `decide_signals[]` por
   categoria+role+dificuldade e `category_difficulty_model_summary[]`.
9. O batch tambem emite `statistical_repeat_readiness`, que fica
   `insufficient_evidence` ate cada bucket categoria+dificuldade+role+modelo
   ter repeticoes validas suficientes.
10. Claim forte segue bloqueado ate matrix lock, repeticao estatistica e
   certificacao humana.

## Regras para IA

Nao copiar `solution/` para prompt, report ou JSON de planejamento. Nao chamar
provider em readiness. Nao transformar import local em claim. Nao alterar Atlas
Decide topology.

## Escopo de Implementacao

Implementado nesta versao:

- parser Harbor/DeepSWE read-only;
- action `deepswe` / `deepswe-import`;
- action `deepswe-ingest`;
- action `deepswe-batch-ingest`;
- plano Pier sem spawn;
- `case_set=deepswe` no `run-arena --dry-run`;
- import de resultado externo para evidence/replay/adjudicator sem provider;
- batch externo multi-task com battery evidence, replay verifier e matrix
  report existentes;
- feed automatico Provider Performance Ledger, readiness estatistica por
  categoria+dificuldade+role+modelo e Decide Signal advisory-only;
- guards contra vazamento de solution/instruction content;
- testes focados.

Fora desta versao:

- execucao Pier real;
- execucao multi-repeat real;
- intervalo de confianca estatistico completo;
- score externo forte com claim;
- leaderboard.

## Dependencias

- DeepSWE/Harbor task format.
- Pier para execucao futura.
- Provider Arena v2.
- Evidence/replay/matrix do Rivals.

## Evidencias

- `AtlasForgeRivalsDeepSweCompatibilityTest`.
- `php artisan atlas:forge:rivals deepswe --deepswe-path=<path> --json`.
- `php artisan atlas:forge:rivals deepswe-ingest --input=<pier-result-root> --deepswe-path=<task-dir> --json`.
- `php artisan atlas:forge:rivals deepswe-batch-ingest --input=<pier-results-root> --deepswe-path=<tasks-root> --json`.
- `php artisan atlas:forge:rivals run-arena --case-set=deepswe --deepswe-path=<path> --dry-run --json`.

## Riscos

- Contaminacao por expor solution/reference patch.
- Confundir readiness com benchmark real.
- Pontuar sem verifier/trajectory/replay/matrix.
- Tratar DeepSWE local como claim externo.

## Exemplos

```bash
php artisan atlas:forge:rivals deepswe --deepswe-path=/path/to/deep-swe/tasks --json
php artisan atlas:forge:rivals deepswe-ingest --input=/path/to/pier/results --deepswe-path=/path/to/deep-swe/tasks/task-001 --json
php artisan atlas:forge:rivals deepswe-batch-ingest --input=/path/to/pier/results --deepswe-path=/path/to/deep-swe/tasks --battery-id=deepswe-battery-001 --json
php artisan atlas:forge:rivals run-arena --arm-a=cursor_cli --arm-a-model=composer_2_5 --arm-b=codex_cli --arm-b-model=gpt-5.5 --mode=provider_arena --task-category=bugfix --case-set=deepswe --deepswe-path=/path/to/deep-swe/tasks --dry-run --json
```

## Proximas Acoes

Expandir a ingestao para multi-repeat estatistico e elevar o Decide Signal de
sinal por categoria+role para confidence por categoria+dificuldade+runner,
mantendo o Atlas Decide como consumidor consultivo sem jamais alterar topology.
