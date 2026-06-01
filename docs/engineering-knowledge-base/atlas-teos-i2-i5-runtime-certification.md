---
id: atlas-teos-i2-i5-runtime-certification
type: engineering_knowledge
title: Atlas TEOS I2-I5 Runtime Certification
status: active
category: programming
priority: 92
summary: Documenta os incrementos TEOS-I2 a TEOS-I5 implementados como runtime local auditável: replay/causal/continuity gate, strategic forgetting, Obra review, operator attention queue, time-aware world model e final certification. Não executa benchmark/rivals.
tags:
  - atlas-dev
  - atlas-forge
  - teos
  - long-horizon
  - certification
  - 2026-05-19
capabilities:
  - teos_increment_2_certification
  - strategic_forgetting
  - obra_review
  - operator_attention_queue
  - time_aware_world_model
  - runtime_smoke
  - final_local_release_gate
decisions:
  - TEOS-I2 é certificado por gate read-only que verifica replay manifest, causal graph lite e continuity certification.
  - TEOS-I3 adiciona strategic forgetting, weekly/monthly Obra review e operator attention queue como read models; nenhum deles deleta memória, fecha Obra ou escreve inbox.
  - TEOS-I4 expõe Time-Aware World Model sobre tabelas existentes; não cria tabela paralela de grafo temporal.
  - TEOS runtime smoke materializa dados locais mínimos para provar final-certify ready sem provider, rivals ou benchmark.
  - TEOS-I5 agrega os gates em certificação final local; status partial é correto quando faltam dados reais como Forge intake ou Codebase World Model.
  - benchmark_not_run permanece true. Rivals e claims externas exigem autorização explícita do operador.
maintenance:
  - Atualizar esta doc quando um comando TEOS mudar schema, status ou semântica de exit code.
  - Não transformar read models em mutações sem ADR e testes de governança.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - app/Services/Ai/LongHorizon/AtlasTeosIncrement2CertificationService.php
  - app/Services/Ai/LongHorizon/StrategicForgettingService.php
  - app/Services/Ai/LongHorizon/ObraReviewService.php
  - app/Services/Ai/LongHorizon/OperatorAttentionQueueService.php
  - app/Services/Ai/LongHorizon/TimeAwareWorldModelService.php
  - app/Services/Ai/LongHorizon/AtlasTeosRuntimeSmokeService.php
  - app/Services/Ai/LongHorizon/AtlasTeosFinalCertificationService.php
  - app/Services/Ai/LongHorizon/AtlasLongHorizonCanon.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-teos-i2-i5-runtime-certification
graph_title: Atlas TEOS I2-I5 Runtime Certification
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-temporal-engineering-operating-system
graph_status: active
graph_source: repo
human_name: Atlas TEOS I2-I5 Runtime Certification
canonical_name: Atlas TEOS I2-I5 Runtime Certification
technical_name: atlas-teos-i2-i5-runtime-certification
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/atlas-teos-i2-i5-runtime-certification.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-teos-i2-i5-runtime-certification.md
allowed_changes:
  - Adicionar novas surfaces TEOS quando houver código, comando e teste focado.
  - Atualizar blockers conhecidos quando final-certify mudar.
forbidden_changes:
  - Declarar superioridade externa sem benchmark autorizado.
  - Criar tabela paralela de attention queue ou world model temporal sem ADR.
  - Apagar regra benchmark_not_run.
depends_on:
  - atlas-teos-increment-1-plan
  - atlas-temporal-engineering-operating-system
  - atlas-long-horizon-intelligence-layer
flows_to:
  - atlas-pre-benchmark-readiness-audit
unlocks:
  - teos_pre_benchmark_hardening
  - long_horizon_runtime_operations
governs:
  - atlas_teos_i2_i5_runtime
evidence:
  - app/Console/Commands/AtlasTeosIncrement2CertifyCommand.php
  - app/Console/Commands/AtlasLongHorizonStrategicForgettingCommand.php
  - app/Console/Commands/AtlasLongHorizonObraReviewCommand.php
  - app/Console/Commands/AtlasLongHorizonAttentionQueueCommand.php
  - app/Console/Commands/AtlasLongHorizonWorldModelCommand.php
  - app/Console/Commands/AtlasTeosRuntimeSmokeCommand.php
  - app/Console/Commands/AtlasTeosFinalCertifyCommand.php
evidence_refs:
  - symbol: AtlasTeosIncrement2CertificationService
  - command: atlas:teos:i2-certify
required_tests:
  - "php artisan test tests/Feature/Ai/LongHorizon/AtlasTeosIncrement2CertificationServiceTest.php tests/Feature/Ai/LongHorizon/StrategicForgettingServiceTest.php tests/Feature/Ai/LongHorizon/ObraReviewServiceTest.php tests/Feature/Ai/LongHorizon/OperatorAttentionQueueServiceTest.php tests/Feature/Ai/LongHorizon/TimeAwareWorldModelServiceTest.php tests/Feature/Ai/LongHorizon/AtlasTeosFinalCertificationServiceTest.php tests/Feature/Ai/LongHorizon/AtlasTeosRuntimeSmokeServiceTest.php"
  - "vendor/bin/pint app/Services/Ai/LongHorizon/AtlasLongHorizonCanon.php app/Services/Ai/LongHorizon/AtlasTeosFinalCertificationService.php"
  - "php artisan atlas:teos:runtime-smoke --json --strict"
  - "php artisan atlas:teos:final-certify --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Rodar runtime smoke local quando precisar materializar evidência mínima de World Model + Forge intake para certificação.
  - Selecionar Forge intake real quando certificar Obra viva fora do smoke.
  - Só depois preparar pre-benchmark readiness; não rodar rivals ainda.
---
# Atlas TEOS I2-I5 Runtime Certification

## Resumo

Esta doc registra a camada runtime TEOS-I2 a TEOS-I5. Ela transforma a visão
TEOS em comandos auditáveis para continuidade de programação de longo prazo,
sem fundir Atlas Dev e Atlas Forge e sem executar benchmark/rivals.

## Papel no Atlas

TEOS fica dentro do domínio de programação. Ele sustenta Atlas Dev em contexto
longo e Atlas Forge em Obras longas, preservando verdade temporal, recuperação,
atenção humana e certificação honesta.

## Onde Se Encaixa

Hierarquia operacional: Atlas AI/Router decide o domínio; programação entra em
Atlas Dev ou Atlas Forge; TEOS atua abaixo deles quando o trabalho precisa
continuar por dias, semanas ou meses.

## Contratos

- `atlas.teos.increment_2_certification.v1`
- `atlas.teos.strategic_forgetting_receipt.v1`
- `atlas.teos.obra_review_receipt.v1`
- `atlas.teos.operator_attention_queue.v1`
- `atlas.teos.time_aware_world_model.v1`
- `atlas.teos.runtime_smoke.v1`
- `atlas.teos.final_certification.v1`

## Fluxo

1. `atlas:teos:i2-certify --json` verifica replay manifest, causal graph lite e
   continuity certification.
2. `atlas:long-horizon:strategic-forgetting --json` calcula política read-only
   para memórias duráveis.
3. `atlas:long-horizon:obra-review --json` emite revisão semanal/mensal de
   Obra sem mutar Forge.
4. `atlas:long-horizon:attention-queue --json` agrega atenção humana exigida.
5. `atlas:long-horizon:world-model --json` lê relações atuais/stale/expired do
   Codebase World Model.
6. `atlas:teos:runtime-smoke --json --strict` cria goal, World Model, Forge
   intake e memória local mínimos, depois roda final certification com esses
   IDs.
7. `atlas:teos:final-certify --json` agrega tudo e declara `ready`, `partial`
   ou `blocked`.

## Regras para IA

- Não rodar benchmark/rivals durante estes comandos.
- Não declarar TEOS completo se `final-certify` retornar `partial` ou
  `blocked`.
- Não apagar memória em Strategic Forgetting; `forget` é apenas recomendação
  que exige revisão humana.
- Não fechar, pausar ou aprovar Obra via Obra Review; é advisory-only.
- Não criar runtime paralelo se existir serviço/tabela canônica.
- `runtime-smoke` pode escrever dados locais, mas continua proibido de chamar
  provider, benchmark ou rivals.

## Escopo de Implementacao

Inclui certificação I2, strategic forgetting, Obra review, attention queue,
time-aware world model, runtime smoke local e final certification. Exclui
benchmark real, UI pesada, CRDT de drafts, execução de provider externo e
mutações destrutivas.

## Dependencias

Depende de Long-Horizon persistence, Atlas Memory, Forge intake/milestones/work
packets, Codebase World Model e comandos TEOS-I2 já existentes.

## Evidencias

Testes focados cobrem serviços e comandos. Em execução local real,
`atlas:teos:final-certify --json` pode retornar `partial` se não houver Forge
intake ou World Model no banco. Para provar o runtime de ponta a ponta sem
usar dados de produção, rode `atlas:teos:runtime-smoke --json --strict`; ele
materializa evidência local mínima e deve terminar com final certification
`ready`.

## Riscos

- Confundir read model com mutação operacional.
- Promover claim externa antes de benchmark autorizado.
- Construir tabela paralela de fila ou grafo temporal.
- Ignorar `partial` e declarar pronto.

## Exemplos

```bash
php artisan atlas:long-horizon:attention-queue --json
php artisan atlas:long-horizon:world-model --json
php artisan atlas:teos:runtime-smoke --json --strict
php artisan atlas:teos:final-certify --json
```

## Proximas Acoes

1. Rodar `atlas:teos:runtime-smoke --json --strict` para prova local completa.
2. Certificar uma Forge Obra real usando `--intake`.
3. Só depois preparar Pre-Benchmark Hardening, ainda sem rodar benchmark.
