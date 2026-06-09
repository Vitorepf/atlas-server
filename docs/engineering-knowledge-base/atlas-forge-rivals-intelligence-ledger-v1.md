---
id: atlas-forge-rivals-intelligence-ledger-v1
type: engineering_knowledge
title: Atlas Forge Rivals · Intelligence Ledger v1
status: planned
category: programming-forge
priority: 94
implementation_state: proposed_next_patamar_not_promoted
blocker: ledger_historical_tests_and_two_real_batteries_required
summary: Proximo patamar do Rivals: transformar baterias reais replayable em inteligencia historica segmentada por provider, modelo, modo de prompt, categoria, dificuldade, custo, tempo, estabilidade e confianca estatistica, sempre advisory-only para Atlas Decide.
tags:
  - atlas
  - forge
  - rivals
  - intelligence-ledger
  - provider-performance-ledger
  - atlas-decide
capabilities:
  - rivals_historical_intelligence
  - rivals_segmented_provider_ranking
  - rivals_cost_time_quality_frontier
  - rivals_statistical_reproducibility
  - rivals_advisory_decide_signal
decisions:
  - O proximo patamar do Rivals nao e mais um modo de bateria; e historico confiavel e segmentado.
  - Baterias validas viram evidencia longitudinal por modo, categoria, dificuldade, custo, tempo e estabilidade.
  - Ranking global e secundario; o produto principal e ranking contextual por tipo de tarefa.
  - Atlas Decide continua dono exclusivo de model routing.
  - Rivals emits measured evidence; Atlas Decide decides model routing.
maintenance:
  - Atualizar quando o Provider Performance Ledger ganhar novas dimensoes, retention, compaction ou signal projection.
  - Sincronizar com benchmark-strategy, battery-modes, report, matrix evidence lock e Atlas Decide.
  - Nunca promover esta doc para active sem testes de ledger historico e ao menos duas baterias reais adicionais alem de human-normal.
related_paths:
  - docs/engineering-knowledge-base/atlas-forge-rivals-benchmark-strategy-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-battery-modes-and-human-prompts-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-provider-performance-ledger-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-v2.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-industrial-benchmark-suite-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-matrix-report-v1.md
  - docs/engineering-knowledge-base/system-graph/atlas-decide.md
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderPerformanceLedgerService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsDecideSignalProjectionService.php
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
owner: programming_rivals
graph_id: atlas-forge-rivals-intelligence-ledger-v1
graph_title: Atlas Forge Rivals · Intelligence Ledger v1
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-forge-rivals-provider-performance-ledger-v1
graph_status: planned
graph_source: repo
repo_paths:
  - docs/engineering-knowledge-base/atlas-forge-rivals-intelligence-ledger-v1.md
allowed_changes:
  - Refinar dimensoes de ranking quando novas baterias reais validarem modos ou providers.
  - Adicionar metricas estatisticas quando houver amostras suficientes por segmento.
forbidden_changes:
  - Permitir que Rivals, ledger ou decide-signal alterem provider topology.
  - Usar local_fake, quick ou run sem replay como evidencia de ranking.
  - Misturar spec-perfect, human-normal, messy-real e enterprise-change sem filtro explicito.
  - Declarar superioridade global quando o sinal for apenas por categoria, dificuldade ou modo.
depends_on:
  - atlas-forge-rivals-benchmark-strategy-v1
  - atlas-forge-rivals-provider-performance-ledger-v1
  - atlas-forge-rivals-matrix-report-v1
flows_to:
  - atlas-decide
unlocks:
  - rivals_contextual_provider_intelligence
  - atlas_decide_advisory_signal_with_history
governs:
  - rivals_intelligence_ledger_next_level
  - rivals_segmented_rankings
  - rivals_statistical_confidence
evidence:
  - "php artisan atlas:forge:rivals report --run-id=<trusted_run> --json"
  - "php artisan atlas:forge:rivals replay --run-id=<trusted_run> --json --strict"
  - "php artisan atlas:forge:rivals ledger-record --run-id=<trusted_run> --json --strict"
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan test --filter='ProviderPerformanceLedger|AtlasForgeRivalsReportV3|AtlasForgeRivalsMatrix'"
requires_evidence: true
risk_level: high
next_actions:
  - Rodar release real `messy-real` 40 casos e registrar como segmento separado.
  - Rodar release real `enterprise-change` 40 casos e registrar como segmento separado.
  - Implementar snapshot historico por segmento: provider, model, prompt_mode, category, difficulty e run_family.
  - Adicionar variancia, intervalos de confianca e regressao por segmento quando houver repeticoes suficientes.
---

# Atlas Forge Rivals · Intelligence Ledger v1

## Resumo

O Rivals ja consegue responder: **nesta bateria validada, quem performou
melhor e com qual evidencia?**

O proximo patamar responde uma pergunta mais operacional:
**dado historico real, categoria, dificuldade, modo de prompt, custo, tempo,
estabilidade e risco, qual runner tende a ser melhor para este tipo de
trabalho, com qual confianca, e quais evidencias sustentam isso?**

Este documento define o salto de produto de benchmark runner para sistema de
inteligencia comparativa continua. Ele nao substitui o Provider Performance
Ledger v1; ele especifica a camada seguinte em cima dele.

## Papel no Atlas

O Intelligence Ledger e a memoria empirica do Rivals. Ele transforma runs
validadas em sinal historico para:

- comparar providers por contexto;
- detectar regressao de modelo, prompt ou harness;
- expor custo, tempo e estabilidade por segmento;
- alimentar Atlas Decide como sinal consultivo.

Ele nao roteia modelo, nao altera provider topology e nao desbloqueia claim
externo.

## Onde Se Encaixa

Fluxo de autoridade:

1. Rivals executa bateria real.
2. Evidence pack, replay e matrix lock validam o harness.
3. Report v3 declara resultado da bateria.
4. Provider Performance Ledger registra entradas append-only.
5. Intelligence Ledger agrega historico segmentado.
6. Atlas Decide consome advisory signal e decide routing com receipt proprio.

## Estado Atual

Comprovado:

- `release` 40 casos, 8 categorias x 5 dificuldades.
- `human-normal` rodado com provider real e token real.
- Report v3, replay strict e matrix evidence lock validos.
- Resultado consultivo, inclusive quando Atlas perde.
- `external_rivals_certification` bloqueado.

Preparado, mas ainda sem claim real dedicado:

- `messy-real` como prompt mode para ruido, ambiguidade e informacao faltando.
- `enterprise-change` como prompt mode para mudanca longa, risco e rollback.
- Provider Arena multi-provider alem do par ja executado.
- Deep ranking com repeticoes e intervalo de confianca.

## Contrato De Autoridade

Rivals mede; Atlas Decide decide.

Todo payload para Atlas Decide deve preservar:

- `advisory_only=true`
- `should_update_provider_topology=false`
- `never_changes_atlas_decide_topology=true`
- `owner_of_model_routing=atlas_decide`
- `routing_effect=none`

Frase canonica: **Rivals emits measured evidence; Atlas Decide decides model routing.**

## Contratos

Contrato minimo para uma entrada ranqueavel:

- run real ou bateria local explicitamente rotulada;
- `replay_passes=true`;
- `matrix_evidence_lock_ok=true`;
- scorecard hashado;
- modo de prompt declarado;
- categoria e dificuldade declaradas;
- custo/tempo preservados quando disponiveis;
- `routing_effect=none`.

## Produto Alvo

The target product is a segmented historical intelligence layer over validated Rivals
runs. It answers contextual questions by provider, model, prompt mode, category,
difficulty, cost, time, stability and confidence. Global winner tables are secondary;
Atlas Decide consumes only advisory evidence.

## Dimensoes Canonicas

Each trusted row preserves: run family, provider/model/arm, mode, prompt mode, preset,
task category, difficulty, ambiguity, risk, scores, winner/margin, cost, wall time,
tokens, replay status, matrix lock status, confidence, suspicious flags, failures,
evidence hash, scorecard hash and report path. Rows without replay and matrix lock may
be diagnostic incidents, not ranking evidence.

## Fluxo

Operator runs a release/deep battery; Rivals generates evidence pack, replay, matrix
report and report v3; ledger-record ingests verified scorecards; historical snapshots
group by segment; decide-signal emits advisory-only evidence; Atlas Decide owns routing.

## Regras para IA

Do not claim historical ranking from one battery, mix prompt modes without filters,
hide cost/time/failures, rank `local_fake`, or turn advisory signal into provider
topology mutation. A clean defeat is valid evidence; a suspicious run is not truth until
triaged.

## Escopo de Implementacao

The canonical scope is the concise contract here. Detailed candidate formulas,
metaranking, repeat plans, quality-judge ideas and adversarial hardening notes were
moved to `archive/source-material/atlas-forge-rivals-intelligence-ledger-v1-full-2026-06-09.md`.
They are reference material, not active runtime authority.

Implementation remains planned until historical ledger tests and at least two real
additional batteries prove segmented repeatability beyond the current trusted run.

## Metaranking Segmentado

Rankings must be segment-first: prompt mode, run family, provider/model, category,
difficulty and role decide comparability before any aggregate score is shown. Confidence
comes from sample count, variance, replay validity, matrix lock, stability, cost/time
and hard-failure rate.

## Reprodutibilidade Estatistica

Strong claims require repeated trusted runs per segment, stable variance and no replay
or matrix-lock drift. Until then the ledger can emit readiness gaps and next-run plans,
not superiority claims.

## Quality Judge Auditavel

A future quality judge may be a secondary audited layer with fixed rubric and receipts,
but it cannot overwrite replay, scope, evidence lock or trusted-signal gates.

## Dependencias

Depende de:

- battery modes e corpus release;
- evidence pack e replay strict;
- matrix evidence lock;
- report v3;
- Provider Performance Ledger;
- Decide Signal Projection;
- Atlas Decide como consumidor consultivo.

## Evidencias

Evidence minima para ativar segmento:

- `evidence/manifest.json`;
- `events.jsonl`;
- `evidence/scorecard.json`;
- `evidence/report.md`;
- replay strict sem mismatch obrigatorio;
- matrix lock OK;
- provider receipts por arm;
- hashes determin code/evidence.

## Inventario Local De Runs

`atlas:forge:rivals runs --json` lista o `runs_root` local sem executar
provider, replay ou adjudicator. O objetivo e separar evidência ausente de
falha real de benchmark:

- runs presentes mostram `manifest_present`, `evidence_pack_present`,
  `scorecard_present`, `report_present` e o proximo comando seguro;
- ids historicos/externos ausentes, como `battery-*`, `deepswe-*` e
  `arena-*`, retornam `phase=external_evidence_missing`;
- o inventario nunca libera claim, nunca pontua e preserva
  `advisory_only=true` com `routing_effect=none`.

## Manifesto Portatil De Evidencia

`atlas:forge:rivals evidence-bundle --run-id=<id> --json` emite um manifesto
hashado do diretório do run para backup/restauracao:

- inclui `manifest.json`, `events.jsonl`, `evidence_pack.json`,
  `artifact_index.json` e artifacts declarados no pack;
- bloqueia quando arquivo essencial do bundle esta ausente;
- sugere comando `tar` para transportar a evidencia, mas nao executa export,
  provider, replay ou adjudicator;
- `bundle_ready=true` significa apenas que a evidencia pode ser transportada;
  nao significa score confiavel nem claim externo.

`atlas:forge:rivals evidence-bundle-verify --input=<manifest.json> --json`
re-hasheia a evidencia restaurada contra o manifesto portatil. Se qualquer
arquivo exigido estiver ausente ou com hash divergente, o resultado e
`status=blocked`; se passar, o proximo passo continua sendo replay strict.
Quando restaurado em outro caminho, use
`--bundle-run-dir=<restored_run_dir>`; depois de apontar `runs_root` para o
restore, `replay`, `trusted-signal` e `ledger-record` remapeiam artifact paths
do `base` antigo para o atual, preservando hashes, receipts, custo, tempo e
tokens. Fluxo minimo: `evidence-bundle` -> restore -> `evidence-bundle-verify`
-> `replay --strict` -> `trusted-signal` -> `ledger-record` -> `decide-signal`.

## Trusted Signal Gate

`atlas:forge:rivals trusted-signal --run-id=<id> --task-category=<cat>
--role=<role> --json` e o gate read-only antes do Provider Performance
Ledger. Ele agrega:

- run materializado;
- evidence pack e scorecard presentes;
- replay final verde;
- hash de evidencia derivavel para o ledger;
- task_category e role explicitos;
- bloqueio de `local_fake`/diagnostico como sinal confiavel.

Quando `trusted_signal_ready=true`, o proximo comando sugerido e
`ledger-record`; mesmo assim o payload continua `claim_ready=false`,
`advisory_only=true` e `routing_effect=none`.

## Hardening Adversarial

O Intelligence Ledger deve rastrear comportamento de risco dos runners:

- edicao de teste para passar falsamente;
- remocao de validacao;
- alteracao de fixture;
- escrita fora do escopo;
- diff excessivo;
- solucao fragil;
- timeout ou stall;
- saida vazia;
- pedido de desculpa sem patch;
- patch que passa localmente mas viola contrato.

Esses sinais alimentam `hard_failure_rate`, `suspicion_rate` e `trust_penalty`,
mas nunca viram roteamento automatico.

## Riscos

Riscos principais:

- confundir falha de harness com derrota real;
- supervalorizar uma bateria unica;
- misturar modos de prompt;
- esquecer custo/tempo e otimizar so score;
- deixar Atlas Decide parecer subordinado ao Rivals.

Mitigacao: replay obrigatorio, matrix lock, segmentacao explicita, sample count,
confidence ladder e advisory-only em todo payload.

## Exemplos

Consulta desejada:

```bash
php artisan atlas:forge:rivals intelligence-ledger \
  --prompt-mode=human-normal \
  --task-category=backend_logic \
  --difficulty=L5 \
  --json --strict
```

Saida esperada: ranking segmentado com `advisory_only=true`,
`owner_of_model_routing=atlas_decide` e `routing_effect=none`.

## Proximas Acoes

1. Validar `messy-real` com release real 40 casos.
2. Validar `enterprise-change` com release real 40 casos.
3. Registrar runs trusted no Provider Performance Ledger com dimensoes
   completas.
4. Validar snapshot segmentado por prompt_mode/run_family em baterias reais.
5. Adicionar custo/tempo por ponto e estabilidade entre runs.
6. Adicionar intervalo de confianca por segmento sobre as repeticoes ja
   rastreadas.
7. Adicionar quality judge auditavel como camada secundaria.
8. Expor decide-signal historico advisory-only para Atlas Decide.

## Criterio De Aceite

O patamar Intelligence Ledger v1 so esta pronto quando:

- houver pelo menos uma bateria real validada por `spec-perfect`,
  `human-normal`, `messy-real` e `enterprise-change`;
- cada bateria tiver report, replay e matrix lock verdes;
- entradas historicas forem append-only e hashadas;
- ranking segmentado separar prompt mode, categoria e dificuldade;
- resultados instaveis forem bloqueados como ranking;
- Atlas Decide receber apenas advisory signal;
- nenhum payload alterar provider topology.
