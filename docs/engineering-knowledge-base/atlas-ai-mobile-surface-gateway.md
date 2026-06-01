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
  - mobile_surface_adapter
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
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-mobile-surface-gateway

graph_title: Atlas AI Mobile Surface Gateway

graph_world: atlas

graph_layer: module

graph_kind: surface

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Mobile Surface Gateway
canonical_name: Atlas AI Mobile Surface Gateway
technical_name: atlas-ai-mobile-surface-gateway
cartography_type: surface
canonical_source: docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md

owner: surface-architecture

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - surface-architecture

evidence:
  - docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md

evidence_refs:
  - symbol: MobilePushService
  - command: atlas:cli:mobile
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - module
  - surface
  - surface-architecture

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
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
- The same contract embeds `presence_eclipse_governance` as
  `atlas.proactive.presence_eclipse.v1`: explicit proactive opt-out, manual
  eclipse, quiet-hours support, no-surveillance default and retention anchor.
  Device preferences `proactive_push_enabled=false` or
  `manual_eclipse_enabled=true` block non-critical proactive push while the
  Inbox item remains available through the authenticated API.
- Every `mobile_push_delivery` audit event produced by `MobilePushService`
  embeds `delivery_attempt_contract` as
  `atlas.proactive.push_delivery_attempt.v1`: a hash-only attempt receipt with
  provider/status, request payload hash, linked proactive-contract hash, hashed
  device id, raw-device-id persistence closed and provider data keys. It never
  grants actions and keeps context/payload/body outside push. Surrounding push
  audit evidence also uses `device_id_hash` and `raw_device_id_persisted=false`.
- `php artisan atlas:ai:proactive-layer-report --json` is the read-only health
  report for insight watchers, Atlas-initiated insights and push deliveries. It
  never emits Inbox items or push. It exposes
  `atlas.proactive_layer.report_safety.v1`: read-model only, writes closed,
  push pointer-only, authenticated fetch required, raw context/payload/body and
  raw device-id audit persistence closed, no agent auto-resolve/auto-dismiss.
  When critical insights are active, it exposes
  `critical_review_contract` as
  `atlas.proactive.critical_review_contract.v1`: operator review is required,
  agent auto-resolve/auto-dismiss is forbidden and only active critical
  insights (`unread`, `read`, `actioned` or `snoozed`) block completion; already
  resolved, dismissed or expired critical history remains counted as history but
  does not keep the review gate open. Review paths remain pointer-only Inbox
  links. The same contract exposes
  `operator_review_plan` as `atlas.proactive.operator_review_plan.v1` with
  CLI commands for `show`, `discuss`, `mark_read`, `snooze` and
  review-backed `dismiss`; these commands are operator actions, not autonomous
  completion authority, and the structure-mother audit must be rerun afterward.
- Inbox CLI `respond` forwards provider cost-rate fields
  (`--provider`, `--model`, `--input-microusd`, `--output-microusd`,
  `--currency`, `--effective-from`, `--effective-until`) to the governed
  `configure_provider_cost_rates` action. Operators must supply current rates;
  Atlas does not infer or fabricate provider pricing.
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

## Resumo

Contrato canonico para mobile como surface, mobile gateway, pairing, push, inbox, discussion handoff e consumo do domain catalog.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
