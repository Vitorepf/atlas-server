---
id: atlas-local-agent-surface
type: engineering_knowledge
title: Atlas Local Agent Surface
status: active
category: surface-architecture
priority: 80
summary: Contrato canonico para agentes locais do Mac como surface/automation de energia, wake, background jobs e readiness, sem autoridade sobre a arquitetura Atlas AI.
tags:
  - atlas
  - mac-agent
  - local-surface
  - automation
capabilities:
  - local_agent_surface
  - background_job_readiness
  - mobile_gateway
decisions:
  - Mac Agent e surface/automation local, nao arquitetura-mae.
  - Readiness local pode bloquear claim de jobs, mas nao bypassa Kernel policy, receipt ou evidence.
  - Wake/caffeinate/power helper sao detalhes operacionais documentados no runbook externo.
maintenance:
  - Atualizar quando host agent, wake scheduling, mobile mac endpoints ou background job readiness mudarem.
related_paths:
  - docs/atlas-mac-agent.md
  - docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md
---

# Atlas Local Agent Surface

O Atlas Local Agent Surface cobre automacoes locais do Mac usadas para manter o
servidor disponivel, acordar janelas de manutencao e proteger background jobs.

## Autoridade

| Assunto | Autoridade |
|---|---|
| Arquitetura local surface/automation | Este documento |
| Comandos de instalacao, validacao e uninstall | `../atlas-mac-agent.md` |
| Mobile gateway e endpoints mobile | `atlas-ai-mobile-surface-gateway.md` |
| Runtime AI, jobs e policy | Kernel/Operating System |

## Fronteira

O Mac Agent pode:

- manter heartbeat local;
- segurar o Mac acordado por sessao;
- reconciliar `caffeinate`;
- programar wake por helper root quando instalado;
- bloquear background jobs quando readiness local falha;
- expor estado para app/mobile.

O Mac Agent nao pode:

- executar provider ou tool sem receipt/policy;
- elevar permissao por estar local;
- esconder falha de readiness;
- depender de push/inbox como unico watchdog.

## Source Material

- `docs/atlas-mac-agent.md`
