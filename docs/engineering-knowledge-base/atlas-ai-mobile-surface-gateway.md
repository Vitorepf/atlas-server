---
id: atlas-ai-mobile-surface-gateway
type: engineering_knowledge
title: Atlas AI Mobile Surface Gateway
status: active
category: surface-architecture
priority: 84
summary: Contrato canonico para mobile como surface, mobile gateway, pairing, push, inbox, discussion handoff e consumo do domain catalog.
tags:
  - atlas-ai
  - mobile
  - surface
  - gateway
  - inbox
capabilities:
  - surface_adapter
  - mobile_gateway
  - domain_catalog
decisions:
  - Mobile e surface do Atlas AI, nao domain paralelo.
  - Push e inbox sao delivery/interaction layers auditaveis, nao runtime autônomo.
  - Elevacao de read/plan para danger/execution exige safety, receipt, evidence e aprovacao apropriada.
maintenance:
  - Atualizar quando mobile auth, inbox, push, discussion bootstrap, domain catalog ou surface adapter mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md
  - docs/engineering-knowledge-base/atlas-constelacao-surface.md
  - docs/engineering-knowledge-base/surface-domain-catalog-integration-plan.md
  - resolver-o-que-vale-a-pena/docs/atlas-ai-mobile-operating-model.md
  - resolver-o-que-vale-a-pena/docs/mobile-gateway-push-inbox-implementation.md
---

# Atlas AI Mobile Surface Gateway

Este documento promove o material mobile para a KB sem transformar mobile em
arquitetura-mae. Mobile e uma surface: recebe input, mostra estado, entrega
inbox/push e pode iniciar discussoes, mas Kernel, Domain Catalog, Policy e
Evidence continuam mandando.

## Autoridade

| Assunto | Autoridade |
|---|---|
| Mobile como surface e UX gateway | Este documento |
| Domain/flow/source of truth | `domains/README.md`, `atlas-ai-operating-system.md`, `surface-domain-catalog-integration-plan.md` |
| Runtime contracts | `atlas-ai-kernel-architecture.md` |
| Produto e planes | `atlas-ai-master-architecture.md` |
| Legacy source material | Mobile docs em `resolver-o-que-vale-a-pena/docs` |

## Fronteira

Mobile pode:

- listar e abrir inbox operacional;
- receber push seguro;
- iniciar discussion handoff;
- mostrar domain/flow readiness vindo do catalogo;
- pedir aprovacao humana para acoes;
- operar leitura, review, plan-only e diagnostic flows;
- exibir estado de pairing, sync, scheduler, provider e health.

Mobile nao pode:

- bypassar policy, receipt ou capability registry;
- elevar permissao para danger/execution sem gate;
- carregar secrets em push;
- criar domain/flow local divergente;
- tratar notification como fonte de verdade;
- enviar nota humana crua para provider.

## Componentes Canonicos

| Componente | Papel |
|---|---|
| Mobile pairing | Pareia device e emite bearer mobile revogavel. |
| Mobile gateway | API surface-safe para app mobile. |
| Inbox | Fila auditavel de insight, proposal, job_result, approval e diagnostic. |
| Push delivery | Canal de entrega, com delivery receipts e retry/defer. |
| Discussion bootstrap | Promove um item para conversa contextual governada. |
| Domain catalog read model | Fonte de picker/status de domain/flow. |
| Constelacao | Surface contemplativa de serendipidade; consome endpoint governado e nunca substitui Inbox. |
| Core reliability monitor | Watchdog para push, inbox, scheduler, jobs e degraded core. |

## Fluxos Principais

| Fluxo | Regra |
|---|---|
| Insight contextual | Backend cria inbox item; push so leva resumo seguro e deep link. |
| Self-improvement proposal | Proposal entra como item revisavel; aplicar mudanca exige action handler/gate. |
| Job result importante | Falha ou resultado critico cria item e pode disparar push. |
| Self-diagnostic | Monitor gera item quando scheduler, push, jobs ou health degradam. |
| Discuss item | App cria thread/context bundle; runtime ainda passa por policy/receipt. |

## Domain Catalog Nas Surfaces

Mobile deve consumir o mesmo catalogo que CLI/API/app:

- `GET /ai/domains` para listar domains/flows/maturity;
- flow `operations.diagnostic` para discussao operacional default;
- `programming.*` apenas quando a surface suportar safety/autonomy exigidos;
- `self_improvement.*` como ready quando houver controles de review/proposal;
- scaffolds aparecem como catalog-ready, nao implemented/ready.

## Push E Inbox Safety

- Push payload contem `inbox_id`, `deep_link`, tipo, prioridade e resumo curto.
- Conteudo sensivel fica atras de API autenticada.
- Dedupe key evita spam.
- Quiet hours, deferred delivery e invalid token devem ser auditaveis.
- Watchdog externo e recomendado para detectar scheduler/Laravel totalmente
  indisponivel.

## Readiness

Um mobile gateway pronto deve ter:

- pairing/revoke;
- inbox list/detail/action/discuss;
- push-test e receipts;
- unread count reconciliavel;
- dedupe e idempotencia;
- cleanup/expire stale;
- alert-check dry-run/apply;
- smoke de device real documentado quando push for declarado pronto.

## Source Material

- `resolver-o-que-vale-a-pena/docs/atlas-ai-mobile-operating-model.md`
- `resolver-o-que-vale-a-pena/docs/mobile-gateway-push-inbox-implementation.md`
- `docs/engineering-knowledge-base/surface-domain-catalog-integration-plan.md`
