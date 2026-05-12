---
id: atlas-structure-mother-handoff
type: engineering_knowledge
title: Atlas Structure Mother Handoff
status: active
category: architecture-handoff
priority: 99
summary: Handoff compacto para novas sessoes continuarem a estrutura mae enterprise do Atlas AI sem depender do historico da conversa.
tags:
  - atlas-ai
  - structure-mother
  - handoff
  - governance
  - backend
capabilities:
  - architecture_handoff
  - documentation_governance
  - session_bootstrap
decisions:
  - Voice/LiveKit permanece scaffold governado e estacionado fora do caminho critico.
  - Proximo foco estrutural e Memory/Context Engine + Knowledge Base/Open Brain.
  - Mobile/frontend continuam pendentes governados ate o backend core estabilizar.
maintenance:
  - Atualizar ao fechar blocos estruturais grandes.
  - Manter objetivo e abaixo de 220 linhas.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md
---

# Atlas Structure Mother Handoff

## Missao

A estrutura mae do Atlas AI e o backend governado que transforma o Atlas em uma camada superior aos providers, nao um wrapper. O Kernel decide dominio, fluxo, provider/modelo, politica, memoria, gates, evidencias e receipts. Runtimes, surfaces e tools executam, mas nao decidem.

## Ordem Correta Dos Modulos

1. Memory/Context Engine.
2. Knowledge Base/Open Brain.
3. Inbox/Capture Pipeline.
4. Task/Agent Orchestration.
5. Tool/Action Runtime.
6. Autonomy/Long-Running Work.
7. Evaluation/Rivals Framework.
8. Notification/Proactive Layer.
9. Voice/LiveKit Runtime.

## Voice/LiveKit

Voice esta em scaffold governado e validado, mas estacionado fora do caminho critico. Foi longe o suficiente para provar LiveKit local, token issuer, SDK, probes, runtime certification e gates. Nao foi promovido a runtime real.

Estado atual:

- LiveKit Server local foi testado e parado.
- Worker LiveKit real nao foi iniciado.
- Runtime real nao foi promovido.
- Gate final permanece `review_required`.

Motivo: voz e surface de experiencia. Memory, Open Brain e Orchestration sao nucleo funcional do Atlas.

## Contratos E Padroes

- Kernel e autoridade de provider, domain, flow, policy e memory.
- Surface nao decide.
- Provider nao decide.
- Tool nao decide.
- Domain nao burla policy.
- Runtime nao executa sem Decision Receipt.
- Promotion exige review/receipt auditavel.
- Tudo relevante vira Evidence Ledger.
- Tudo repetido vira Core.
- Fail-closed por padrao.
- Secrets, tokens e audio cru nunca entram em logs, docs ou ledger.
- Mobile/frontend ficam pendentes governados; nao sao foco agora.

## Validacoes Obrigatorias

Comandos recorrentes:

```bash
php artisan test <tests focados>
php artisan atlas:ai:architecture-validate --json
atlas engineering knowledge docs-health
atlas engineering knowledge sync --prune
atlas engineering knowledge index-code --prune
git diff --check
```

Quando tocar em voice:

```bash
php artisan atlas:ai:voice runtime-certify --require-sdk --callback-loop-wired --production-sdk-loop-wired --json
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=runtimes/python/voice_realtime:runtimes/python/voice_realtime/tests runtimes/python/voice_realtime/.venv/bin/python -m unittest discover -s runtimes/python/voice_realtime/tests
```

## Docs Canonicos Para Ler Primeiro

- `docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md`
- `docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md`
- `docs/engineering-knowledge-base/atlas-ai-pipeline.md`
- `docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md`
- `docs/engineering-knowledge-base/atlas-ai-master-architecture.md`
- `docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md`
- `docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md`
- `runtimes/python/voice_realtime/README.md`

Para Memory/Open Brain, localizar e ler docs/classes existentes antes de criar qualquer contrato novo.

## Restricoes Criticas

- Nao reverter mudancas existentes sem pedido explicito.
- Preservar trabalho de outras frentes/agentes.
- Nao mexer em mobile/frontend agora.
- Nao criar fluxo paralelo se ja houver contrato/doc.
- Nao promover scaffold como implemented.
- Nao iniciar daemon/processo persistente sem start, stop, status, rollback e logs seguros.
- Nao usar pip global.
- Nao expor secrets.
- Atualizar doc dono e KB quando mudar contrato.
- Respeitar limites de linhas da documentacao.

## Estado Do Repo

O worktree esta sujo. Ha mudancas de voice desta frente e mudancas paralelas de self-construction/autonomy.

Voice relevante:

- `app/Services/Ai/Voice/AtlasVoiceLiveKitServerProbe.php`
- `docker-compose.livekit.yml`
- `app/Console/Commands/AtlasAiVoiceRealtimeCommand.php`
- `app/Http/Controllers/AtlasAiVoiceRealtimeController.php`
- `app/Services/Ai/Voice/AtlasVoiceRuntimeCertificationService.php`
- `app/Services/Ai/Voice/AtlasVoiceRealtimeService.php`
- `routes/api.php`
- `tests/Unit/Ai/Voice/AtlasVoiceLiveKitServerProbeTest.php`
- docs de voice/runtime

Provavel outra frente:

- `app/Models/AtlasSelfConstruction*`
- migration `create_atlas_self_construction_agent_control_plane_tables`
- docs `self-construction/*`
- `atlas-system-graph*`
- alteracoes em `AtlasAiSelfConstructionCommand*`

Preservar tudo.

## Proximo Modulo

Memory/Context Engine + Open Brain foundation.

Objetivo: memoria real governada e recuperavel, conectada a docs, codigo, vault, decisoes, entidades, relacoes, freshness, lineage e evidencia. Esse modulo reduz confusao de IA, duplicacao de fluxo e perda de contexto.

## Primeiro Bloco Tecnico Recomendado

1. Ler estado real:

```bash
pwd
git status --short
rg -n "Open Brain|memory|context|Knowledge|retrieval|vault|ledger projection" docs app config routes tests
```

2. Mapear classes, comandos, tabelas e docs existentes de Memory/Open Brain.
3. Produzir matriz `implemented | partial | scaffold | missing` apenas para Memory/Open Brain.
4. Escolher o primeiro elo backend: contrato canonico de memory item/context pack/retrieval result, validator e CLI/API read-only.
5. Implementar com testes focados e rodar `architecture-validate`, `docs-health`, `sync --prune`, `index-code --prune` e `git diff --check`.

Nao comecar por UI. Nao comecar por mobile. Nao criar novo sistema de memoria antes de reconciliar o que ja existe.
