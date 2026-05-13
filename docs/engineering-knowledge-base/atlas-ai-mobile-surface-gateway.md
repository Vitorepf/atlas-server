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
  - Durante a fase backend-first, mobile visual fica pending, mas todo backend com impacto mobile deve publicar contrato consumivel.
maintenance:
  - Atualizar quando mobile auth, inbox, push, discussion bootstrap, domain catalog ou surface adapter mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md
  - docs/engineering-knowledge-base/atlas-constelacao-surface.md
  - docs/engineering-knowledge-base/surface-domain-catalog-integration-plan.md
  - docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
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

## Backend-First / Mobile Pending

Decisao operacional atual: priorizar backend, CLI, API, Kernel, runtimes,
gates e evidence. O Atlas App/mobile UI fica pendente ate o backend estar
estavel, mas nao pode virar esquecimento.

Qualquer capability backend com impacto mobile deve declarar um Mobile Pending
Contract no doc dono ou no AP:

- `mobile_surface_status`: `not_needed`, `pending_contract` ou `implemented`;
- endpoint/API ou comando CLI que o app consumira;
- schema version do payload;
- estados de UI esperados, mesmo que ainda nao implementados;
- criterio de aceite mobile futuro;
- doc dono e anti-duplicacoes conhecidas.

Nenhuma capability deve ser marcada como produto completo se precisar de mobile
e estiver sem contrato. A implementacao mobile futura deve consumir esses
contratos, nao reinventar fluxo, domain, policy ou runtime local.

## Componentes Canonicos

| Componente | Papel |
|---|---|
| Mobile pairing | Pareia device e emite bearer mobile revogavel. |
| Mobile gateway | API surface-safe para app mobile. |
| Inbox | Fila auditavel de insight, proposal, job_result, approval e diagnostic. |
| Push delivery | Canal de entrega, com delivery receipts e retry/defer. |
| Discussion bootstrap | Promove um item para conversa contextual governada. |
| Domain catalog read model | Fonte de picker/status de domain/flow. |
| Constelacao | Surface contemplativa; consome `/v1/mobile/atlas/celestial/positions` e nunca substitui Inbox. |
| Core reliability monitor | Watchdog para push, inbox, scheduler, jobs e degraded core. |

## Fluxos Principais

| Fluxo | Regra |
|---|---|
| Insight contextual | Backend cria inbox item; push so leva resumo seguro e deep link. |
| Self-improvement proposal | Proposal entra como item revisavel; aplicar mudanca exige action handler/gate. |
| Job result importante | Falha ou resultado critico cria item e pode disparar push. |
| Self-diagnostic | Monitor gera item quando scheduler, push, jobs ou health degradam. |
| Discuss item | App cria thread/context bundle; runtime ainda passa por policy/receipt. |
| Constelacao positions | App consome posicoes redigidas; backend registra evidence minima e usa fallback sem Graph RAG final. |

## Domain Catalog Nas Surfaces

Mobile deve consumir o mesmo catalogo que CLI/API/app:

- `GET /ai/domains` para listar domains/flows/maturity;
- flow `operations.diagnostic` para discussao operacional default;
- `programming.*` apenas quando a surface suportar safety/autonomy exigidos;
- `self_improvement.*` como ready quando houver controles de review/proposal;
- scaffolds aparecem como catalog-ready, nao implemented/ready.

## Push E Inbox Safety

- Push payload contem `inbox_id`, `deep_link`, tipo, prioridade e resumo curto.
- Inbox API/CLI resources expose `safety` as `atlas.inbox_item.safety.v1`:
  authenticated API required, push pointer-only, context bundle API-only,
  no raw context/payload/body in push and no automatic action execution.
- Every item created by `AtlasInboxService` persists
  `payload.proactive_delivery_contract` as
  `atlas.proactive.delivery_contract.v1`: a hash-only boundary receipt for the
  proactive layer with push mode, pointer-only flags, API-auth requirement,
  context-bundle API-only status, action-registry requirement, dedupe/deep-link
  hashes and the allowlisted push data fields. Dedupe refreshes the same
  contract instead of reusing stale delivery metadata.
- Conteudo sensivel fica atras de API autenticada.
- Dedupe key evita spam.
- Quiet hours, deferred delivery e invalid token devem ser auditaveis.
- Push e canal de interrupcao, nao fonte de verdade. Se `atlas_mobile_devices`
  ou `mobile_push_deliveries` estiver indisponivel, a criacao do Inbox deve
  sobreviver, o push falha fechado e um audit event `push.unavailable` registra
  as tabelas ausentes.
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

## Cross-Surface: Voice Realtime

Mobile e a primeira surface canonica do Voice Realtime Surface
(`voice_realtime`). A primeira entrega deve ser push-to-talk mobile; depois o
mesmo fluxo evolui para realtime com LiveKit Agents SDK. Quando ativa, mobile
mostra:

- estado da sessao de voz (clear, eclipsed, recovered);
- session id e source de origem (mobile_app, cli, mac_edge);
- indicador visivel de captura ativa;
- push-to-talk/mute;
- transcript parcial/final quando permitido por policy;
- botao de encerrar sessao com efeito imediato.

Estado atual: o app mobile ja possui cliente API para `session/start`,
`session/end` e `readiness`, e o Voice Mode tenta abrir sessao governada no
Kernel antes de cair para fallback visual local. O audio realtime, LiveKit media
streaming, STT/TTS e UX completa ainda nao estao implementados.

Mobile pode capturar e transmitir audio via LiveKit SDK, mas nao decide modelo,
nao chama provider direto e nao persiste audio raw. STT/TTS/turn detection vivem
no LiveKit Agents SDK, sempre subordinado ao Kernel. Swift/macOS entra depois
como edge ambiental local, nao como primeiro produto de voz. Detalhes em
`atlas-ai-voice-realtime-surface.md`.

## Source Material

- `resolver-o-que-vale-a-pena/docs/atlas-ai-mobile-operating-model.md`
- `resolver-o-que-vale-a-pena/docs/mobile-gateway-push-inbox-implementation.md`
- `docs/engineering-knowledge-base/surface-domain-catalog-integration-plan.md`
