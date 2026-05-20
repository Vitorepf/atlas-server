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

O Intelligence Ledger transforma cada bateria real validada em uma linha
historica segmentavel. O produto final deve permitir perguntas como:

- Quem e melhor em `backend_logic` L5 sob `human-normal`?
- Quem e mais barato para `frontend_ui` L2-L3 sem perda de qualidade?
- Qual runner falha mais em `test_design` com ambiguidade alta?
- O resultado de hoje e regressao real ou variancia normal?
- Qual provider merece recomendacao consultiva para Atlas Decide nesta classe
  de tarefa?

Ranking global existe, mas nao e a interface primaria. O ranking principal e
contextual.

## Dimensoes Canonicas

Cada entrada historica deve indexar:

- `run_id`
- `run_family` ou experimento logico
- `provider`
- `model`
- `arm_id`
- `mode`: `fair`, `full_power`, `provider_pure`, `atlas-power`
- `prompt_mode`: `spec-perfect`, `human-normal`, `messy-real`,
  `enterprise-change`
- `preset`: `quick`, `release`, `deep`, category battery
- `task_category`
- `difficulty_level`: `L1` a `L5`
- `ambiguity_level`
- `risk_level`
- `atlas_score`, `rival_score`, `arm_score`
- `winner`, `margin`, `tie_threshold`
- `cost`, `wall_seconds`, token totals quando disponiveis
- `replay_passes`
- `matrix_evidence_lock_ok`
- `confidence`
- `suspicious_flags`
- `hard_failures`
- `evidence_hash`
- `scorecard_hash`
- `report_path`

Sem `replay_passes=true` e `matrix_evidence_lock_ok=true`, a entrada pode ser
armazenada apenas como incidente diagnostico, nunca como ranking.

## Fluxo

1. Operador roda uma bateria `release` ou `deep`.
2. Rivals gera evidence pack, replay, matrix report e report v3.
3. Ledger-record ingere somente scorecards com hashes e paths verificaveis.
4. Snapshot historico agrupa por modo, categoria, dificuldade, provider e
   modelo.
5. Projection gera decide-signal advisory-only.
6. Atlas Decide decide routing sem aceitar mutacao de topology pelo Rivals.

## Regras para IA

- Nao declarar ranking historico com uma unica bateria.
- Nao misturar prompt modes no mesmo agregado sem filtro explicito.
- Nao transformar resultado suspeito em verdade ate triage passar.
- Nao esconder custo, tempo, replay, case failures ou hard failures.
- Nao converter advisory signal em provider topology update.
- Nao invalidar derrota legitima de um provider quando o harness esta limpo.
- Nao ranquear local_fake como claim real.

## Escopo de Implementacao

Escopo v1:

- schema de snapshot historico;
- filtros por provider/model/mode/prompt_mode/category/difficulty;
- ranking por score medio, custo, tempo, win rate e hard failure rate;
- freshness e sample count;
- decide-signal advisory-only.

Fora do v1:

- roteamento automatico;
- claim externo;
- judge qualitativo como fonte primaria;
- UI obrigatoria;
- mutation de Atlas Decide topology.

## Metaranking Segmentado

O snapshot historico deve gerar rankings por:

- provider/modelo;
- categoria;
- dificuldade;
- prompt mode;
- ambiguity/risk;
- custo por ponto;
- tempo por ponto;
- estabilidade entre runs;
- taxa de hard failure;
- delta Atlas Forge vs provider puro;
- delta fair vs full_power.

Exemplo de saida desejada:

```json
{
  "schema_version": "atlas.forge.rivals.intelligence_ledger.snapshot.v1",
  "segment": {
    "prompt_mode": "human-normal",
    "task_category": "backend_logic",
    "difficulty_level": "L5"
  },
  "ranking": [
    {
      "provider": "claude",
      "model": "claude_sonnet",
      "mean_score": 62.5,
      "runs": 3,
      "confidence": "directional_signal",
      "routing_effect": "none"
    }
  ],
  "advisory_only": true,
  "owner_of_model_routing": "atlas_decide"
}
```

## Reprodutibilidade Estatistica

Uma bateria `release` unica pode declarar vencedor daquela bateria. Ela nao
deve virar verdade permanente. Para `provider_ranking` e `decide_signal`, o
Intelligence Ledger deve exigir repeticao.

Regras iniciais:

- 1 bateria `release`: `trusted_battery` para a bateria, nao ranking historico.
- 2-3 baterias no mesmo segmento: `directional_history`.
- 4+ baterias no mesmo segmento: calcular variancia e intervalo de confianca.
- Resultado inconsistente entre runs: `unstable_segment`, nao recomendado.
- Mudanca de modelo/provider: inicia nova serie ou marca quebra de serie.

Campos estatisticos esperados:

- `sample_count`
- `mean_score`
- `median_score`
- `score_stddev`
- `mean_cost`
- `mean_wall_seconds`
- `win_rate`
- `hard_failure_rate`
- `confidence_interval_95` quando houver amostra suficiente
- `freshness_status`

## Quality Judge Auditavel

O gate deterministico continua sendo o piso. O proximo patamar pode adicionar
quality judge, mas apenas como camada separada:

- rubrica fixa;
- judge receipts;
- reasoning compacto;
- divergencia entre judges;
- calibracao contra hidden oracle;
- incapaz de sobrescrever replay, scope ou evidence lock.

Se replay, scope ou evidence falhar, quality judge nao pontua.

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
4. Implementar snapshot historico segmentado.
5. Adicionar custo/tempo por ponto e estabilidade entre runs.
6. Adicionar repeticao e intervalo de confianca por segmento.
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
