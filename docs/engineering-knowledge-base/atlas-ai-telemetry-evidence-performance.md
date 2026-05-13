---
id: atlas-ai-telemetry-evidence-performance
type: engineering_knowledge
title: Atlas AI Telemetry Evidence And Performance
status: active
category: observability
priority: 87
summary: Contrato canonico para telemetria, rollups, aggregator_version, health gates, performance reports, custo, qualidade e relacao com Evidence Ledger.
tags:
  - atlas-ai
  - telemetry
  - evidence
  - performance
  - cost
capabilities:
  - evidence_ledger
  - telemetry_rollup
  - performance_reporting
decisions:
  - Evidence Ledger e a fonte auditavel de eventos de kernel; telemetry rollups sao projecoes analiticas.
  - aggregator_version define comparabilidade de metricas e deve bloquear claims de tendencia entre schemas diferentes.
  - Push, inbox e reports nunca devem carregar secrets ou metricas sensiveis cruas.
maintenance:
  - Atualizar quando comandos de telemetry, reports, SLOs, ledger replay ou cost model mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
  - docs/atlas-ai-telemetry.md
  - docs/atlas-ai-aggregator-versions.md
  - docs/atlas-ai-performance-reports.md
  - docs/atlas-ai-performance-engine-ops.md
  - resolver-o-que-vale-a-pena/docs/atlas-ai-telemetry-quality-efficiency-implementation.md
---

# Atlas AI Telemetry Evidence And Performance

Este documento consolida a familia telemetry/evidence/performance. Ele nao
remove os runbooks operacionais existentes; ele define qual doc manda e como as
pecas se relacionam.

## Autoridade

| Assunto | Autoridade |
|---|---|
| Event sourcing, receipt, ledger, SLO e replay | `atlas-ai-kernel-architecture.md` |
| Contrato consolidado telemetry/evidence/performance | Este documento |
| Comandos e APIs operacionais atuais | `../atlas-ai-telemetry.md` |
| Historico de `aggregator_version` | `../atlas-ai-aggregator-versions.md` |
| Daily/multi-window performance report | `../atlas-ai-performance-reports.md` |
| Ordem diaria, smoke e rollout do engine | `../atlas-ai-performance-engine-ops.md` |

## Camadas

| Camada | Papel | Fonte primaria |
|---|---|---|
| Evidence Ledger | Eventos append-only de operacoes, providers, gates, tools, repairs e outcomes. | `atlas_ledger_events` / Kernel |
| Raw Telemetry | Eventos brutos vindos de app, CLI, server e worker. | `ai_telemetry_events` |
| Trace Rollup | Resumo por trace: qualidade, eficiencia, custo, contexto e outcome. | `ai_trace_metric_summaries` |
| Health Gate | Classificacao de janela operacional. | `atlas:ai:telemetry:health` |
| Performance Report | Projecao diaria/multi-window para inbox/mobile. | `atlas:ai:telemetry:performance-report` |
| SLO Probe | Avaliacao de SLOs do Kernel sobre ledger/replay/projecoes. | Kernel SLO docs/codigo |

## Invariantes

- Ledger e append-only; reports e summaries podem ser recomputados.
- Report diario usa `ai_traces.created_at` como base de atribuicao, nao
  `computed_at` do summary.
- Baixa amostra gera `watch`, nao falso `critical`.
- Custo estimado deve permanecer marcado como estimado; o Atlas nao converte
  estimativa operacional em cobranca real.
- Custo desconhecido e problema de observabilidade quando nao existe identidade
  provider/model ou rate suficiente.
- `ai_traces` projetados do Evidence Ledger (`schema_version=
  atlas.ledger_projection.metadata.v1`, `projection_id=ai_traces`) sem
  provider/model nao sao execucoes de provider. Eles devem aparecer como
  `cost_confidence=estimated`, `cost_source=provider_not_applicable` e
  `cost_mode=not_applicable`, e o report de missing cost rates deve exclui-los
  mesmo quando houver summary antigo ainda nao recomputado.
- Providers CLI reais sem rate ativo continuam acionaveis via
  `missing_active_cost_rate`; o operador deve preencher rates atuais e o Atlas
  nao deve inferir precos.
- `aggregator_version` muda sempre que schema, semantica ou comparabilidade de
  rollup mudar.
- Trend, anomaly e benchmark nao podem atravessar `aggregator_version` diferente
  sem reprocessamento ou nota explicita.
- Push notification e inbox carregam resumo seguro; dados sensiveis ficam no
  backend/auditoria.
- Health insights emitidos para Inbox carregam
  `atlas.telemetry_health.notification_receipt.v1`, com hash de janela, status,
  issue keys, politica de notificacao e bloqueios de provider/runtime/policy/
  memory write.

## Comandos Canonicos

| Objetivo | Comando |
|---|---|
| Rollup recente | `php artisan atlas:ai:telemetry:rollup --hours=72 --json` |
| Rollup por trace | `php artisan atlas:ai:telemetry:rollup --trace=<trace-id> --json` |
| Health gate | `php artisan atlas:ai:telemetry:health --hours=72 --json` |
| Health gate CI | `php artisan atlas:ai:telemetry:health --hours=24 --fail-on-critical --json` |
| Cost rates | `php artisan atlas:ai:telemetry:cost-rates --json` |
| Missing cost rates | `php artisan atlas:ai:telemetry:cost-rates --missing --hours=168 --json` |
| Performance report | `php artisan atlas:ai:telemetry:performance-report --emit --recompute --json` |
| Metrics snapshot | `php artisan atlas:ai:metrics:snapshot-refresh --days=2 --json` |
| Performance smoke | `php artisan atlas:ai:performance:smoke --strict --json` |

## `aggregator_version`

`aggregator_version` e o discriminador de schema e semantica do rollup. Uma nova
versao deve ser emitida quando:

- novos campos entram em `quality`, `efficiency`, `cost`, `context` ou
  `outcome`;
- o calculo de score muda;
- provider/model attribution muda;
- cost confidence muda de semantica;
- um downstream passaria a interpretar tendencia de forma errada.

Consumidores devem:

- mostrar a versao em diagnostics;
- bloquear comparacao direta entre versoes diferentes;
- rodar rollup/backfill antes de declarar melhoria ou regressao historica.

## Reports E Inbox

Performance reports sao projecoes para acao humana. Eles devem conter:

- qualidade final, auto quality, continuity, human feedback, outcome e
  remediation;
- eficiencia, latencia, contexto e token volume;
- custo estimado/medido/desconhecido;
- provider health e degradacoes;
- data quality e missing cost rates;
- traces notaveis e recomendacoes;
- dedupe key por data/tipo para evitar spam.

`atlas:ai:telemetry:health --emit --json` cria um insight operacional apenas
quando a janela esta em `warning` ou `critical`. A payload deve explicar por que
o operador recebeu o item (`why_received`), qual politica de notificacao foi
usada (`push_policy`) e o receipt hashavel
`atlas.telemetry_health.notification_receipt.v1`. O receipt nunca autoriza
provider call, runtime execution, policy patch ou escrita de memoria; ele existe
para replay/auditoria do alerta proativo.

## Safety E Privacy

- Nao enviar secrets, prompts brutos, memory notes privadas ou dados pessoais
  detalhados em push/inbox/report compacto.
- Personal Development, Finance e Health-like signals exigem resumo
  conservador e link para detalhe autenticado.
- Evidence usado por self-improvement deve preservar origem e confidence.

## Eventos Voice Realtime

A familia `VOICE_*` no Evidence Ledger e governada por
`atlas-ai-voice-realtime-surface.md`. Eventos canonicos:

- `VOICE_SESSION_STARTED`
- `VOICE_WAKE_WORD_DETECTED`
- `VOICE_TURN_AUDIO_RECEIVED`
- `VOICE_TURN_TRANSCRIBED`
- `VOICE_TURN_DECIDED`
- `VOICE_TURN_SYNTHESIZED`
- `VOICE_TURN_PLAYED`
- `VOICE_TURN_INTERRUPTED`
- `VOICE_RUNTIME_FAILED`
- `VOICE_ECLIPSE_TRIGGERED`
- `VOICE_ECLIPSE_LIFTED`
- `VOICE_SESSION_ENDED`
- `VOICE_PROVIDER_HEALTH_DEGRADED`

SLO de voz (`turn_to_first_audio_p95`, `wake_word_detection_p95`) e medido pelo
Self-Improvement em `voice_latency_review`. Audio raw nunca persiste em
nenhuma tabela; somente `audio_hash` (sha256) sob privacy class do domain.
Eclipse window ativa bloqueia turno e emite `VOICE_ECLIPSE_TRIGGERED`.

## Eventos Local RAG

A familia `LOCAL_RAG_*` governa readiness, benchmark e promocao futura de
Graph RAG/Python. Eventos canonicos:

- `LOCAL_RAG_PLAN_CREATED`
- `LOCAL_RAG_QUALITY_CORPUS_EVALUATED`
- `LOCAL_RAG_GRAPH_PROMOTION_BLOCKED`

Esses eventos usam `AtlasEvidenceLedger::recordLocalRagEvent`. Query, prompt,
input bruto, contexto bruto, documentos e excerpts nunca persistem; o payload
mantem hashes, ids de fonte, versao do corpus, scores, latencia e motivo de
bloqueio/promocao. Nenhum evento `LOCAL_RAG_*` autoriza policy patch sozinho:
promocao exige review humano ou proposta de Curator.

## Source Material

- `docs/atlas-ai-telemetry.md`
- `docs/atlas-ai-aggregator-versions.md`
- `docs/atlas-ai-performance-reports.md`
- `docs/atlas-ai-performance-engine-ops.md`
- `resolver-o-que-vale-a-pena/docs/atlas-ai-telemetry-quality-efficiency-implementation.md`
