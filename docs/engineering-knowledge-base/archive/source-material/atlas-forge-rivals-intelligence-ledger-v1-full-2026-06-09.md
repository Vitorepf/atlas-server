---
id: atlas-forge-rivals-intelligence-ledger-v1-full-2026-06-09
type: engineering_knowledge
title: Atlas Forge Rivals Intelligence Ledger v1 Full Design Snapshot
status: source_material
authority_class: design_reference
implementation_state: source_material_full_snapshot_no_runtime_authority
category: programming-forge
summary: Full pre-split design snapshot for the canonical Intelligence Ledger v1 contract; not current runtime authority.
canonical_owner: docs/engineering-knowledge-base/atlas-forge-rivals-intelligence-ledger-v1.md
---

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

Estado implementado no Provider Performance Ledger:

- entradas preservam `case_id`, `task_id`, `case_source`,
  `difficulty_level`, `difficulty_weight`, `run_family` e `prompt_mode`;
- snapshot aceita filtros `difficulty`/`difficulty_level`,
  `run_family` e `prompt_mode`;
- agregado `by_task_category_difficulty_role_model` separa modelo por
  categoria, dificuldade, role, provider e modelo, com mediana, desvio,
  intervalo 95%, estabilidade, custo, tokens, tempo e custo por ponto;
- agregado segmentado por `run_family` + `prompt_mode` impede misturar
  spec-perfect, messy-real, enterprise-change ou batches externos distintos;
- `statistical_repeat_readiness` bloqueia claim forte ate cada segmento ter
  repeticoes validas suficientes e variancia estavel;
- `external_claim_readiness` explicita se a evidencia esta pronta para revisao
  humana externa, mas mantem `external_claim_allowed=false` e
  `score_or_claim_allowed=false` mesmo quando as repeticoes estatisticas estao
  completas;
- `statistical-repeat-plan --json` lista os proximos segmentos que precisam de
  repeticao, contadores top-level (`repeat_target_count` e
  `unstable_target_count`) e comandos sugeridos, sem chamar provider e sem
  gastar tokens;
- `statistical-repeat-plan --json` inclui `execution_plan`, agrupado por
  provider/modelo, com comandos `--dry-run --json`, inputs estruturados para
  `ArenaRunService`, resolucao de arm quando possivel e
  `confirmation_required_before_real_provider=true`. O plano tambem separa
  `planned_target_count` de `preview_target_count`, para garantir que o plano
  cobre todos os buckets conhecidos mesmo quando a visualizacao humana mostra
  apenas um preview;
- `statistical-repeat-dry-run --json` executa esses inputs estruturados pelo
  caminho dry-run do Provider Arena, sem shell, sem provider real, sem tokens e
  sem liberar score/claim. A acao prova que os proximos buckets estatisticos
  resolvem contratos de arm/modelo antes de qualquer confirmacao paga;
- quando todos os dry-runs passam, a acao emite `plan_fingerprint` e
  `real_execution_runbook`: uma lista agrupada de comandos reais com
  `--confirm-runbook-reviewed`, `--confirm-provider-cost` e
  `--confirm-real-provider-call`. Esse bloco e apenas runbook de operador:
  `planning_only=true`, `external_provider_call=false` e
  `provider_tokens_spent=false` continuam no payload porque Rivals nao executou
  provider;
- cada entrada do runbook recebe `run_id` deterministico no formato
  `statrep-<fingerprint12>-NNN`. Isso torna replay, adjudication,
  `ledger-record` e auditoria reproduziveis sem depender de ids gerados em
  runtime;
- quando o bucket usa dominios industriais como `security`, `product` ou
  `performance`, o planner pode resolver o caso por `industrial_domains` se a
  categoria primaria canonica for diferente. Isso evita falso blocker em
  repeticoes externas importadas, sem mudar o `case.category` original;
- o dry-run estatistico tambem aplica `difficulty=L1..L5` no corpus planner.
  Um bucket `security/L4`, por exemplo, precisa resolver casos do dominio
  `security` com dificuldade `L4`; validar apenas o case-set inteiro nao e
  evidencia suficiente para repeticao reproduzivel;
- o subset `statistical-repeat` preserva 60 casos, mas sua selecao base deve
  incluir segmentos exigidos pelo ledger real. O segmento `docs/L3` e coberto
  explicitamente para evitar plano estatistico verde sem fixture correspondente;
- `decide-signal` aceita filtros de dificuldade/familia/prompt, classifica
  empate tecnico/vantagem direcional/vantagem material, sinaliza alternativa
  mais barata e continua com `routing_effect=none`.
- `decide-map` expoe `external_claim_readiness` e
  `statistical_repeat_measurement_plan` para Atlas Decide como sinal
  consultivo; a projection continua incapaz de alterar topology.
- `decide-map` tambem gera `model_profiles`: cards por provider/modelo com
  segmentos fortes, segmentos de cautela, postura consultiva, custo, tempo,
  tokens, `atlas_decide_policy_hint` e categorias/dificuldades onde o sinal
  medido apareceu.
- `decide-map` gera `atlas_decide_segment_advisory`: cards compactos por
  segmento com candidate provider/model, runner-up, `policy_hint`,
  `next_action`, motivos e `supporting_evidence`, sempre `routing_effect=none`.
- `decide-map` gera `atlas_decide_learning_packet`: resumo estavel para Atlas
  Decide com segmentos aprendiveis, shadow-only, bloqueados, proximas
  repeticoes estatisticas e efeitos proibidos.
- `atlas_decide_learning_packet` inclui `evidence_collection_plan`, com
  comandos dry-run para repetir segmentos, sequencia operacional e garantia
  explicita de `external_provider_call=false` ate confirmacao humana. O plano
  tambem inclui `learning_gap_commands_preview` e
  `external_execution_plan_commands_preview` para exportar
  `external_execution_runbook_manifest.v1`, alem de
  `arena_repeat_commands_preview`, para que Atlas Decide ou operador saibam
  exatamente qual gap medir, qual runbook reproduzivel revisar e qual
  comparação Provider Arena planejar sem chamar provider real.
- O manifesto exportado por `external_execution_plan_commands_preview` deve ser
  validado com `external-runbook-validate --plan-manifest=<path>` antes de
  qualquer execução paga. A validação recalcula fingerprint, exige comandos
  dry-run/templates reais com confirmações, mantém claim gate bloqueado e
  preserva `routing_effect=none`.
- `decide-learning --json` expoe apenas o pacote de aprendizado consultivo,
  para Atlas Decide ou operadores consumirem sem parsear o `decide-map`
  completo.
- O pacote inclui `model_fit_matrix`, uma matriz por segmento que responde
  diretamente qual provider/modelo mediu melhor para cada categoria,
  dificuldade e role, com `fit_status`, `policy_hint`, `next_action` e resumo
  auditavel de evidencia.
- O pacote inclui `model_usage_playbook`, um guia por provider/modelo com
  `use_when`, `shadow_when`, `do_not_prefer_when`, gaps de evidencia e comandos
  dry-run para repetir segmentos antes de qualquer preferencia. Esse playbook e
  o formato mais direto para o Atlas Decide entender quando observar, quando
  abrir policy review e quando nao usar o sinal para preferencia. Quando houver
  repeticao faltante, cada preview deve carregar `learning_gap_command`,
  `arena_dry_run_command` e `statistical_repeat_command`, mantendo
  `external_provider_call=false` e `routing_effect=none`.
- O pacote inclui `dimensional_signal_quality`, que bloqueia policy review
  quando qualquer segmento perder dimensoes obrigatorias como categoria,
  dificuldade L1-L5, role, prompt mode, run family ou provider/model resolvido.
  Sinal sem dimensao completa pode entrar no ledger como diagnostico, mas nao
  responde corretamente "qual modelo e melhor para que".
- Segmento com provider/model nao resolvido fica como reparo de metadata:
  `candidate_resolution_status=blocked_unresolved_provider_model`,
  `next_action=repair_provider_model_metadata` e blockers como
  `provider_required_for_atlas_decide_learning`. Ele nao entra em
  `model_profiles`, `model_usage_playbook`, `shadow_policy_candidates` ou
  `preference_candidates`, para evitar que Atlas Decide trate alias solto como
  modelo real.
- O pacote inclui `dimensional_signal_repair_plan`, sempre read-only, para
  explicar como corrigir gaps dimensionais sem inventar metadados: apenas
  reingestao quando manifest/scorecard/case metadata confiavel provar a
  dimensao, provider receipt/manifest/model registry provar provider/model, ou
  nova repeticao com categoria, dificuldade, prompt mode e run family
  explicitos. O plano bloqueia mutacao automatica, rewrite de ledger, provider
  call e qualquer score/claim enquanto o reparo nao estiver auditado.
- O pacote inclui `atlas_decide_learning_eligibility`, derivado do ledger, para
  separar score replayavel de sinal aprendivel. Uma entrada pode continuar
  `valid_for_ranking=true` e ainda carregar
  `atlas_decide_learning_eligible=false` quando faltar provider, model,
  categoria, dificuldade L1-L5 ou role; esses blockers impedem uso consultivo
  como politica ate nova evidencia completa existir.
- O pacote inclui `operator_summary` e `category_fit_summary`, para humanos e
  Atlas Decide entenderem rapidamente onde cada modelo apareceu e por que o
  sinal ainda e shadow/repeat quando a evidencia e fraca.
- O pacote inclui `shadow_policy_candidates` e `do_not_prefer_until`, para
  separar candidatos observaveis em shadow de qualquer preferencia real de
  roteamento.
- O pacote inclui `preference_candidates`, `preference_candidate_count` e
  `blocked_preference_reasons`, para separar candidatos que poderiam entrar em
  revisao de politica de qualquer preferencia efetiva. Mesmo quando houver
  candidatos, a saida continua `score_or_claim_allowed=false` e
  `routing_effect=none`.
- Quando nao ha repeticao estatistica suficiente, `allowed_learning_effect`
  permanece `advisory_shadow_signal_only`. Quando ha segmento direcional
  reproduzivel, o efeito permitido sobe apenas para
  `advisory_policy_review_candidate_only`: Atlas Decide pode revisar a politica,
  mas Rivals ainda nao altera topology nem emite claim.
- O pacote inclui `atlas_decide_consumption_summary`, um semaforo compacto para
  consumidores machine-readable: `no_preference_available` enquanto o sinal e
  shadow/repeat, ou `policy_review_candidates_available` quando ha candidatos
  reproduziveis para revisao de politica. Ambos preservam `routing_effect=none`.
- O pacote inclui `statistical_repeat_gap_summary`, que separa quantidade de
  segmentos visiveis no mapa (`segment_repeat_target_count`) da prontidao
  estatistica global (`not_ready_bucket_count`, `unstable_bucket_count`). Isso
  evita que Atlas Decide confunda alguns segmentos repetiveis com readiness
  global.
- O pacote inclui `evidence_provenance` e `learning_packet_hash`, para comparar
  snapshots de aprendizado sem depender de horario de geracao e auditar qual
  ledger/filtro produziu o sinal.
- O `supporting_evidence` por segmento carrega contagem valida, scores
  candidato/runner-up, gap, estabilidade, custo/tempo/tokens, run_ids recentes,
  resolucao provider/model, repeticoes faltantes e os requisitos antes de
  preferencia. Isso permite ao Atlas Decide explicar "por que este modelo aqui"
  sem inferir a partir de score bruto e sem mutar topology.
- `next` bloqueia run historico/externo ausente como
  `external_evidence_missing`, sem score/claim, ate restaurar ou ingerir
  evidence pack verificavel.
- `trusted-signal --run-id=<id> --json` inclui
  `atlas_decide_ingestion_contract`: um contrato que separa run confiavel para
  ingestao no ledger de qualquer preferencia de modelo. Mesmo quando
  `trusted_signal_ready=true`, o efeito permitido e apenas
  `append_measured_evidence_to_provider_performance_ledger`; preferencia de
  modelo, claim externo e topology update continuam bloqueados ate repeticao
  estatistica, replay/matrix e revisao do Atlas Decide.

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

O `decide-map` tambem deve expor perfis por modelo:

```json
{
  "model_profiles": [
    {
      "provider": "claude",
      "model": "opus",
      "decision_posture": "directional_fit_explore_before_routing_change",
      "atlas_decide_policy_hint": "atlas_decide_should_explore_or_shadow_before_preference",
      "categories": ["backend"],
      "difficulties": ["L5"],
      "best_segments": [
        {
          "segment_key": "backend|L5|builder",
          "advantage_band": "material_advantage"
        }
      ],
      "advisory_only": true,
      "routing_effect": "none"
    }
  ]
}
```

E deve expor cards compactos por segmento:

```json
{
  "atlas_decide_segment_advisory": [
    {
      "segment_key": "backend|L5|builder",
      "candidate_provider": "claude",
      "candidate_model": "opus",
      "policy_hint": "atlas_decide_should_explore_or_shadow_before_preference",
      "next_action": "run_statistical_repeat_for_segment",
      "score_or_claim_allowed": false,
      "routing_effect": "none"
    }
  ]
}
```

E deve expor um pacote de aprendizado consultivo:

```json
{
  "atlas_decide_learning_packet": {
    "schema_version": "atlas.forge.rivals.atlas_decide_learning_packet.v1",
    "status": "exploration_only",
    "learning_packet_hash": "sha256-do-pacote-normalizado",
    "evidence_provenance": {
      "source_schema_version": "atlas.forge.rivals.provider_performance_ledger.v1",
      "filtered_ledger_entries": 124,
      "routing_effect": "none"
    },
    "operator_summary": "Rivals has measured shadow-only segments; no model should be preferred from this signal until reproducible evidence improves.",
    "allowed_learning_effect": "advisory_shadow_signal_only",
    "evidence_collection_plan": {
      "status": "needs_more_reproducible_evidence",
      "dry_run_first": true,
      "external_provider_call": false,
      "learning_gap_commands_preview": [
        {
          "command": "php artisan atlas:forge:rivals external-learning-gap --provider=claude --task-category=backend --difficulty=L5 --role=builder --json",
          "external_provider_call": false,
          "provider_tokens_spent": false
        }
      ],
      "external_learning_gap_rows": "external-learning-gap emits dry_run_command plus real_execution_command_template per missing bucket; next_measurement_command stays dry-run.",
      "external_execution_plan_commands_preview": [
        {
          "command": "php artisan atlas:forge:rivals external-execution-plan --provider=claude --model=opus --task-category=backend --difficulty=L5 --role=builder --case-set=industrial-50 --output-path=storage/app/atlas-rivals/external-runbooks/backend-L5-builder-claude-opus.json --json",
          "runbook_manifest_path": "storage/app/atlas-rivals/external-runbooks/backend-L5-builder-claude-opus.json",
          "manifest_schema_version": "atlas.forge.rivals.external_execution_runbook_manifest.v1",
          "writes_plan_manifest_only": true,
          "external_provider_call": false,
          "provider_tokens_spent": false,
          "routing_effect": "none"
        }
      ],
      "arena_repeat_commands_preview": [
        {
          "candidate_provider": "claude",
          "candidate_model": "opus",
          "baseline_provider": "codex",
          "baseline_model": "gpt-5.5",
          "command": "php artisan atlas:forge:rivals run-arena --case-set=industrial-50 --mode=provider_arena --arm-a=claude_code --arm-a-model=opus --arm-b=codex_cli --arm-b-model=gpt-5.5 --task-category=backend --difficulty=L5 --role=builder --dry-run --json",
          "external_provider_call": false,
          "provider_tokens_spent": false
        }
      ],
      "commands_preview": [
        {
          "command": "php artisan atlas:forge:rivals run-battery --preset=statistical-repeat --mode=provider_arena --task-category=backend --difficulty=L5 --dry-run --json"
        }
      ]
    },
    "model_fit_matrix": [
      {
        "segment_key": "backend|L5|builder",
        "candidate_provider": "claude",
        "candidate_model": "opus",
        "fit_status": "shadow_or_repeat_before_preference",
        "next_action": "run_statistical_repeat_for_segment",
        "routing_effect": "none"
      }
    ],
    "category_fit_summary": [
      {
        "task_category": "backend",
        "dominant_candidate": {
          "provider": "claude",
          "model": "opus",
          "fit_statuses": ["shadow_or_repeat_before_preference"]
        },
        "routing_effect": "none"
      }
    ],
    "preference_candidates": [],
    "preference_candidate_count": 0,
    "blocked_preference_reasons": [
      "atlas_decide_policy_review_required",
      "statistical_repeat_not_ready",
      "external_claim_readiness_blocked",
      "only_shadow_candidates_available"
    ],
    "statistical_repeat_gap_summary": {
      "status": "needs_repetition",
      "bucket_count": 100,
      "ready_bucket_count": 8,
      "not_ready_bucket_count": 92,
      "unstable_bucket_count": 0,
      "segment_repeat_target_count": 3,
      "global_repeat_gap_exists": true,
      "routing_effect": "none"
    },
    "atlas_decide_consumption_summary": {
      "decision": "no_preference_available",
      "allowed_learning_effect": "advisory_shadow_signal_only",
      "review_candidate_count": 0,
      "statistical_repeat_not_ready_bucket_count": 92,
      "global_repeat_gap_exists": true,
      "next_action": "run_statistical_repeat_for_shadow_segments",
      "routing_effect": "none"
    },
    "shadow_policy_candidates": [
      {
        "provider": "claude",
        "model": "opus",
        "allowed_learning_effect": "shadow_observation_only",
        "routing_effect": "none"
      }
    ],
    "do_not_prefer_until": {
      "statistical_repeat_ready": false,
      "required_before_preference": [
        "replay_green",
        "matrix_green",
        "scorecard_present",
        "statistical_repeat_complete",
        "atlas_decide_policy_review"
      ]
    },
    "forbidden_learning_effects": [
      "provider_topology_update",
      "automatic_model_routing_change",
      "external_claim"
    ],
    "score_or_claim_allowed": false,
    "routing_effect": "none"
  }
}
```

Quando a repeticao estatistica fica completa para um segmento, o mesmo pacote
pode declarar candidato revisavel:

```json
{
  "atlas_decide_learning_packet": {
    "status": "has_directional_learning_candidates",
    "allowed_learning_effect": "advisory_policy_review_candidate_only",
    "preference_candidate_count": 1,
    "preference_candidates": [
      {
        "provider": "claude",
        "model": "opus",
        "allowed_learning_effect": "advisory_policy_review_candidate_only",
        "policy_gate": "atlas_decide_policy_review_required_before_any_preference",
        "routing_effect": "none"
      }
    ],
    "blocked_preference_reasons": [
      "atlas_decide_policy_review_required"
    ],
    "statistical_repeat_gap_summary": {
      "status": "complete",
      "not_ready_bucket_count": 0,
      "unstable_bucket_count": 0,
      "global_repeat_gap_exists": false,
      "routing_effect": "none"
    },
    "atlas_decide_consumption_summary": {
      "decision": "policy_review_candidates_available",
      "allowed_learning_effect": "advisory_policy_review_candidate_only",
      "review_candidate_count": 1,
      "statistical_repeat_not_ready_bucket_count": 0,
      "global_repeat_gap_exists": false,
      "next_action": "atlas_decide_policy_review_can_evaluate_candidates_without_topology_mutation",
      "routing_effect": "none"
    },
    "score_or_claim_allowed": false,
    "should_update_provider_topology": false,
    "routing_effect": "none"
  }
}
```

Atalho operacional:

```bash
php artisan atlas:forge:rivals decide-learning --json
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

Comando operacional:

```bash
php artisan atlas:forge:rivals statistical-repeat-plan --json
php artisan atlas:forge:rivals statistical-repeat-dry-run --n-tasks=10 --json
```

Contrato do comando:

- `external_provider_call=false`;
- `provider_tokens_spent=false`;
- `claim_ready=false`;
- `external_claim_allowed=false`;
- `score_or_claim_allowed=false`;
- `routing_effect=none`;
- frase canonica preservada: “Rivals emits measured evidence; Atlas Decide decides model routing.”

`statistical-repeat-dry-run` preserva o mesmo contrato e adiciona:

- `schema_version=atlas.forge.rivals.statistical_repeat_dry_run_validation.v1`;
- `planned_dry_run_count`;
- `validated_dry_run_count`;
- `passed_count` e `failed_count`;
- `ready_for_operator_real_repeat_review=true` somente quando todos os dry-runs
  selecionados passam;
- `real_provider_execution_still_requires_confirmations=true`.
- `real_execution_runbook.status=ready_for_human_cost_review` somente quando o
  plano inteiro foi validado, sem `--n-tasks` limitando a amostra.
- `plan_fingerprint` precisa ser preservado no handoff humano antes de rodar
  comandos pagos; se mudar, rode o dry-run novamente.
- `real_execution_runbook.batches[].entries[].post_run_commands` inclui
  `replay --strict`, `adjudicate`, `ledger-record` e `decide-learning` para cada
  `run_id` deterministico.
- `real_execution_runbook.batches[].batch_post_run_commands` e
  `real_execution_runbook.final_post_run_commands` fecham a evidencia agregada
  com `battery-evidence`, `battery-verify-evidence`, `matrix-report` e
  `decide-learning`, usando os mesmos `run_ids` deterministicos. Isso impede
  que o operador rode repeticoes pagas sem um caminho explicito de replay,
  matrix lock e ingestao no ledger.
- Cada item de `results_preview` inclui
  `case_filter_integrity.schema_version=atlas.forge.rivals.statistical_repeat_case_filter_integrity.v1`;
  o dry-run deve bloquear quando qualquer case retornado nao casar com a
  `task_category`/`difficulty` solicitada. Quando a selecao industrial casar por
  dominio especializado, por exemplo `security`, o JSON explicita
  `matched_by.industrial_domain` e cada `cases_preview[]` carrega
  `requested_task_category_match`, evitando confundir categoria primaria
  (`planning`, `docs`) com o eixo de medicao pedido.

O `execution_plan` e o bloco operacional seguro por default:

```json
{
  "execution_plan": {
    "schema_version": "atlas.forge.rivals.statistical_repeat_execution_plan.v1",
    "status": "needs_repetition",
    "known_not_ready_bucket_count": 92,
    "planned_target_count": 92,
    "preview_target_count": 20,
    "preview_limited": false,
    "groups_by_provider_model": [
      {
        "provider": "claude",
        "model": "sonnet",
        "suggested_minimum_additional_runs": 10,
        "provider_driver_resolution": {
          "status": "resolved_for_dry_run_plan",
          "arm": "claude_code"
        },
        "dry_run_inputs_preview": [
          {
            "case_set": "statistical-repeat",
            "mode": "provider_arena",
            "prompt_mode": "human-normal",
            "task_category": "bugfix",
            "difficulty": "L2",
            "run_family": "deepswe-batch-test-...",
            "arm_a": "claude_code",
            "arm_a_model": "sonnet",
            "arm_b": "codex_cli",
            "arm_b_model": "gpt-5.5",
            "dry_run": true
          }
        ],
        "dry_run_commands_preview": [
          "php artisan atlas:forge:rivals run-arena --case-set=statistical-repeat --mode=provider_arena --prompt-mode=human-normal --task-category=bugfix --difficulty=L2 --run-family=deepswe-batch-test-... --arm-a=claude_code --arm-a-model=sonnet --arm-b=codex_cli --arm-b-model=gpt-5.5 --dry-run --json"
        ]
      }
    ],
    "execution_batches": [
      {
        "batch": 1,
        "command_count": 10,
        "input_count": 10,
        "run_dry_first": true,
        "external_provider_call": false,
        "provider_tokens_spent": false,
        "routing_effect": "none"
      }
    ],
    "operator_cost_risk_summary": {
      "schema_version": "atlas.forge.rivals.statistical_repeat_operator_cost_risk_summary.v1",
      "status": "ready_for_dry_run_review",
      "minimum_additional_runs_estimate": 180,
      "provider_model_group_count": 5,
      "cost_estimate_available": false,
      "cost_estimate_reason": "provider_real_calls_not_executed_and_pricing_receipts_not_available_in_plan",
      "risk_level": "high_volume_repeat_plan",
      "dry_run_only_until_confirmed": true,
      "required_confirmations_before_real_provider": [
        "confirm_runbook_reviewed",
        "confirm_provider_cost",
        "confirm_real_provider_call"
      ],
      "external_provider_call": false,
      "provider_tokens_spent": false,
      "routing_effect": "none"
    },
    "confirmation_required_before_real_provider": true,
    "external_provider_call": false,
    "provider_tokens_spent": false,
    "score_or_claim_allowed": false,
    "routing_effect": "none"
  }
}
```

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
