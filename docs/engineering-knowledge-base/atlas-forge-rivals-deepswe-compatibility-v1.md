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
  - external_batch_decide_model_intelligence_map
  - external_batch_statistical_repeat_readiness
  - benchmark_data_contamination_guard
  - programmatic_verifier_claim_gate
decisions:
  - Rivals deve ser compativel com DeepSWE/Harbor/Pier em vez de tentar substituir esse padrao.
  - `solution/` e referencia para revisao humana, nunca input do agente e nunca conteudo em JSON do Rivals.
  - Readiness, dry-run e ingestao de resultado externo nao spawnam Pier, Docker, agentes CLI ou providers.
  - Resultado externo so entra no Rivals como evidence importado: patch, trajectory, logs, verifier result e task manifest hash.
  - Batch externo deve reutilizar battery-evidence, battery-verify-evidence e matrix-report existentes; nao criar relatorio paralelo.
  - Batch externo verificado deve alimentar Provider Performance Ledger, readiness estatistica segmentada, Decide Signal e Decide Map como advisory-only.
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
human_name: Atlas Forge Rivals DeepSWE Compatibility v1
canonical_name: Atlas Forge Rivals DeepSWE Compatibility v1
technical_name: AtlasForgeRivalsDeepSweTaskParserService
cartography_type: contract
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
evidence_refs:
  - symbol: AtlasForgeRivalsDeepSweTaskParserService
  - command: atlas:forge:rivals
  - test: AtlasForgeRivalsDeepSweCompatibilityTest
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

DeepSWE/Harbor/Pier nao obriga o Atlas a abandonar runners CLI. No Rivals,
`external-execution-preflight` expõe o bridge CLI/runtime/API, e
`external-execution-plan` expõe a matriz paga que ainda falta medir e pode
escrever `external_execution_runbook_manifest.v1` via `--output-path`. Claude
Code, Codex CLI, Gemini CLI, Cursor CLI e Composer 2.5 continuam como runners
CLI; DeepSWE pode ser formato externo de tarefas/resultados. Sem receipt,
trajectory/patch, scorecard, replay verde e matrix lock, nao existe score forte
nem claim externo.

O manifesto exportado deve preservar o `case_set` solicitado e listar, por
bucket faltante, `dry_run_command` e `real_execution_command_template`. O
template real sempre inclui as três confirmações explícitas de custo/runbook/
provider; o dry-run continua sendo o próximo comando seguro e não gasta tokens.
O manifesto também deve carregar `model_gap_summary`, agregando runs faltantes
por provider/modelo para que revisão humana e Atlas Decide não confundam
lacunas de Sonnet, Opus, Codex, Gemini, Cursor ou Composer.

Quando o operador exporta `external_execution_runbook_manifest.v1`, o ingest
pode receber `--plan-manifest=<path>`. Nesse modo, cada arm result precisa
declarar o mesmo `plan_fingerprint`; fingerprint ausente ou divergente bloqueia
o ingest antes de ledger, score forte ou claim. No batch, o mesmo manifesto
produz `external_execution_plan_binding_summary`: todos os resultados precisam
estar ligados ao mesmo fingerprint para que o batch alimente matrix/ledger/Atlas
Decide sem blocker.

Antes de qualquer execução paga, o operador deve rodar
`external-runbook-validate --plan-manifest=<path> --json`. Essa validação não
chama DeepSWE, Pier, Docker, CLI runner nem provider; ela só prova que o
manifesto exportado continua plan-only, fingerprintado, com comandos dry-run,
templates reais confirmados, `model_gap_summary`, claim gate bloqueado e
saída advisory-only.

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
4. Readiness emite hashes, plano Pier sem executar e
   `external_benchmark_runbook`. O runbook fica `ready_for_operator_review`
   somente com pelo menos 50 tasks validas planejadas; caso contrario retorna
   `blocked_until_minimum_external_task_count`.
5. `run-arena --case-set=deepswe --dry-run` consome o mesmo manifest externo.
6. `deepswe-ingest --input=<result-root>` importa dois arms ja executados
   (`atlas`/`rival` ou `arm_a`/`arm_b`) e materializa `manifest.json`,
   receipts, patches, logs, evidence pack, replay, bundle portatil e
   `trusted-signal` para ledger/Atlas Decide.
7. `deepswe-batch-ingest --input=<results-root>` descobre diretorios de
   resultado multi-task, cria um run Rivals por task, agrega
   `battery-evidence`, roda `battery-verify-evidence` e produz `matrix-report`
   consultivo. Quando `--plan-manifest=<path>` e informado, o batch exige que
   cada run importado tenha `external_execution_plan_binding.status=ok` e emite
   `external_execution_plan_binding_summary` com contadores bound/blocked e o
   fingerprint comum.
8. Cada run externo importado tambem alimenta o Provider Performance Ledger
   com `case_id`, `task_id`, `case_source`, `task_category`,
   `difficulty_level`, `difficulty_weight`, `role`, provider/modelo e
   scorecard. Em seguida o batch emite `decide_signals[]` por
   categoria+role+dificuldade, `decide_model_intelligence_map` segmentado e
   `category_difficulty_model_summary[]`. O batch tambem promove o
   `atlas_decide_learning_packet` para o campo top-level
   `atlas_decide_external_learning_packet`, facilitando consumo pelo Atlas
   Decide sem parsear o mapa inteiro.
9. O batch tambem emite `statistical_repeat_readiness`, que fica
   `insufficient_evidence` ate cada bucket categoria+dificuldade+role+modelo
   ter repeticoes validas suficientes.
10. O batch emite `external_benchmark_claim_gate`, um gate compacto de claim
   externo forte. Ele exige pelo menos 50 runs externos bem-sucedidos, battery
   evidence verde, replay verde, matrix verde, ledger verde, repeticao
   estatistica pronta, dimensoes completas para Atlas Decide e certificacao
   humana. Mesmo quando tudo estiver verde, o status maximo e
   `ready_for_human_certification_external_claim_still_blocked`; `claim_ready`,
   `external_claim_allowed`, `score_or_claim_allowed` e topology update seguem
   falsos.
11. Claim forte segue bloqueado ate matrix lock, repeticao estatistica e
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
- `external_benchmark_runbook` plan-only com sequencia operacional 50+ tasks:
  readiness, runner externo, batch ingest, evidence/replay/matrix/ledger,
  learning packet e certificacao humana;
- `case_set=deepswe` no `run-arena --dry-run`;
- import de resultado externo para evidence/replay/adjudicator sem provider;
- lifecycle externo por run importado com bundle verificado,
  `trusted_signal_ready`, `can_feed_provider_performance_ledger` e
  `can_feed_atlas_decide_advisory_signal`;
- batch externo multi-task com battery evidence, replay verifier e matrix
  report existentes;
- feed automatico Provider Performance Ledger, readiness estatistica por
  categoria+dificuldade+role+modelo, Decide Signal e Decide Map advisory-only;
- `external_benchmark_claim_gate` fail-closed no batch, separando evidencia
  pronta para revisao humana de qualquer claim externo;
- `atlas_decide_external_learning_packet` top-level para consumo consultivo do
  Atlas Decide;
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
- `php artisan atlas:forge:rivals external-evidence-readiness --json`.
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
