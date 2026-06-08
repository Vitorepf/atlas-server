---
id: atlas-hermes-executive-runtime-product-spec
type: engineering_knowledge
title: Atlas Hermes Executive Runtime Product Spec
status: active
category: architecture
priority: 93
summary: Blueprint de produto final para o ATLS usar Hermes como runtime executivo completo, mantendo ATLS soberano sobre intencao, contexto, policy, memoria, evidencia e resposta.
tags:
  - atlas-ai
  - hermes
  - product-spec
  - executive-runtime
  - sovereignty
capabilities:
  - hermes_product_blueprint
  - runtime_router_product_contract
  - gateway_adapter_blueprint
  - memory_gate_blueprint
  - executive_mesh_product_contract
decisions:
  - O produto final e Humano -> ATLS -> Hermes, nunca Humano -> Hermes como caminho soberano.
  - Hermes e executor, transportador, skill source e operations substrate; ATLS e decisor, memoria, policy, verifier e narrador final.
  - O salto de qualidade vem do flywheel ATLS/Hermes governado, nao da mistura direta de memorias.
  - Todo canal externo entra como ingress; toda acao sai como ExecutiveMission; todo retorno volta como ResultPacket.
  - Memoria Hermes nunca promove fatos sozinha; ela produz candidatos revisaveis pelo Atlas Memory Gate.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar junto com `atlas-hermes-executive-runtime.md` quando Gateway, Skills, Cron/Webhooks ou Memory Adapter forem implementados.
related_paths:
  - docs/engineering-knowledge-base/atlas-hermes-executive-runtime.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
  - docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md
  - docs/engineering-knowledge-base/programming-power-tools-catalog.md
  - docs/engineering-knowledge-base/atlas-hermes-capability-registry.md
  - app/Services/Ai/HermesCliProvider.php
  - app/Services/Ai/Hermes/HermesExecutiveMissionFactory.php
  - app/Services/Ai/Hermes/HermesMemoryAdapter.php
  - app/Services/Ai/Hermes/HermesResultPacketFactory.php
  - app/Services/Ai/Hermes/HermesScheduleAdapter.php
  - app/Services/Ai/Kernel/Evidence/ProviderUsagePayload.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-hermes-executive-runtime-product-spec
human_name: Atlas Hermes Executive Runtime Product Spec
canonical_name: Atlas Hermes Executive Runtime Product Spec
technical_name: HermesCliProvider
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-hermes-executive-runtime-product-spec.md
graph_title: Atlas Hermes Executive Runtime Product Spec
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-hermes-executive-runtime
graph_status: active
graph_source: repo
owner: architecture
repo_paths:
  - docs/engineering-knowledge-base/atlas-hermes-executive-runtime-product-spec.md
  - docs/engineering-knowledge-base/atlas-hermes-executive-runtime.md
allowed_changes:
  - Refinar experiencia, adapters, gates e DoD do produto final mantendo ATLS soberano.
  - Atualizar status de fases somente com evidencia de codigo, teste e gates.
forbidden_changes:
  - Declarar Hermes completo sem Gateway, Skills, Cron/Webhooks e Memory Adapter governados.
  - Permitir resposta de gateway ou memoria promovida sem ATLS decidir.
  - Transformar dashboard Hermes em surface principal do Atlas.
depends_on:
  - atlas-hermes-executive-runtime
  - atlas-ai-knowledge-governance-system
  - atlas-ai-runtime-language-boundaries
flows_to:
  - atlas-runtime-router
  - atlas-executive-mesh
unlocks:
  - hermes-gateway-adapter
  - hermes-skill-adapter
  - hermes-cron-webhook-adapter
  - hermes-memory-adapter
governs:
  - hermes-product-final-shape
evidence:
  - docs/engineering-knowledge-base/atlas-hermes-executive-runtime-product-spec.md
  - docs/engineering-knowledge-base/atlas-hermes-executive-runtime.md
  - app/Services/Ai/HermesCliProvider.php
  - app/Services/Ai/Hermes/HermesExecutiveMissionFactory.php
  - app/Services/Ai/Hermes/HermesMemoryAdapter.php
  - app/Services/Ai/Hermes/HermesResultPacketFactory.php
  - app/Services/Ai/Hermes/HermesScheduleAdapter.php
  - app/Services/Ai/Kernel/Evidence/ProviderUsagePayload.php
  - tests/Unit/AiCliProviderRuntimeArgsTest.php
  - tests/Unit/Ai/HermesExecutiveRuntimePacketEvidenceTest.php
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
  - php artisan atlas:ai:architecture-validate --json
  - php artisan test tests/Unit/AiCliProviderRuntimeArgsTest.php tests/Unit/Ai/HermesExecutiveRuntimePacketEvidenceTest.php
requires_evidence: true
risk_level: high
visual_tags:
  - product
  - runtime
  - hermes
  - atls-sovereignty
ai_entrypoints:
  - Leia Objetivo, Produto Final, Contratos de Poder, Modos Operacionais e Definition of Done.
ai_usage_notes:
  - Este doc descreve o alvo de produto final; `implementation_state` separa o que ja existe do que ainda falta.
quality_gates:
  - docs-health status ok
  - architecture-validate status ok
failure_modes:
  - Operador usar Hermes direto e criar uma segunda verdade.
  - Hermes Gateway responder sem passar por ATLS.
  - Memoria Hermes capturar preferencias e fatos canonicos sem review.
  - Skills/Cron/Webhooks virarem automacao invisivel sem stop gate.
observability_signals:
  - ExecutiveMission emitida por chamada Hermes.
  - HermesResultPacket emitido por retorno Hermes.
  - MemoryDeltaCandidate e ScheduleCandidate persistidos como candidatos revisaveis quando policy permite.
  - ProcedureCandidate em quarentena para futuro Skill Gate.
  - Provider usage ledger com hashes mission/result e adapter receipts.
implementation_state: product_phase_4_capability_registry_and_adapters_current
next_actions:
  - Expor surfaces de operador (comando/controller) sobre os gates ja implementados.
  - Conectar transporte real de canal ao Gateway adapter governado.
  - Promover ProcedureCandidate via fluxo guiado sobre o SkillPackPromotionGate.
  - Habilitar Runtime Router auto-routing por policy explicita, mantendo default-safe.
---
# Atlas Hermes Executive Runtime Product Spec

## Resumo

Este documento define o produto final: ATLS usando Hermes como runtime executivo
completo, mas sem entregar a soberania do Atlas para Hermes.

O operador deve sentir uma unica inteligencia operacional. Por baixo, ATLS
escolhe quando usar Hermes, como empacotar contexto, quais ferramentas liberar,
como validar resultado, quais aprendizados aceitar e quando responder.

## Papel no Atlas

Este spec e o blueprint de produto final do runtime Hermes. O doc pai
`atlas-hermes-executive-runtime.md` governa o boundary canonico; este doc
detalha experiencia, adapters, contracts, DoD e sequencia de implementacao.

Hermes pertence ao Executive Runtime Layer: amplia alcance operacional do ATLS,
mas nao assume Identity, Memory, Policy, Context Compiler ou Evidence Ledger.

## Onde Se Encaixa

| Camada Atlas | Papel | Relacao com Hermes |
|---|---|---|
| Surface | Desktop, Mobile, Voice, Gateway | Entrada humana e resposta final |
| Intent Kernel | entende pedido | cria objetivo normalizado |
| Context Compiler | monta verdade canonica | injeta contexto minimo |
| Policy Engine | decide permissao | limita tools, paths, network |
| Runtime Router | escolhe executor | seleciona Hermes quando vantajoso |
| Executive Runtime | executa | Hermes opera tools/gateway/skills |
| Verifier | valida | confere output e evidencias |
| Memory Gate | aprende | aprova/rejeita candidatos |

## Produto Final

O caminho soberano e:

```text
Humano -> ATLS -> Runtime Router -> Hermes -> ATLS -> Humano
```

Quando houver canal externo:

```text
Telegram/Discord/API -> Hermes Gateway -> ATLS -> Hermes Worker -> ATLS -> Hermes Gateway
```

Hermes pode receber mensagens, executar tools, operar skills, rodar cron,
chamar MCP, manter sessoes e expor dashboard. ATLS decide o significado, a
permissao, a memoria, o destino e a resposta final.

## Experiencia Do Operador

| Momento | O que o operador ve | O que acontece por baixo |
|---|---|---|
| Conversa normal | ATLS responde em Desktop, Mobile, Voice ou Telegram | ATLS decide runtime e contexto |
| Tarefa executiva | "Resolvido", com evidencia e proximos passos | Hermes executa uma ExecutiveMission |
| Trabalho longo | status, checkpoint, pause/resume | ATLS mantem trace; Hermes roda worker |
| Aprendizado | ATLS lembra depois de aprovar | Hermes retorna MemoryDeltaCandidate |
| Automacao | rotinas visiveis e pausaveis | Cron/Webhook Hermes vira Schedule ATLS |
| Debug/ops | logs, receipt, replay | ResultPacket vira Evidence Ledger |

O operador nao deve configurar profile, model, toolset, skill, gateway, memoria
ou cron manualmente durante uso normal. Essas escolhas sao politica do ATLS.

## Contratos

| Poder | Dono | Hermes pode | Hermes nao pode |
|---|---|---|---|
| Intencao | ATLS | receber objetivo compilado | reinterpretar prioridade soberana |
| Contexto | ATLS | consumir Context Pack | buscar verdade canonica sozinho |
| Policy | ATLS | executar dentro do scope | ampliar permissao |
| Tools | ATLS + Hermes | rodar tool liberada | escolher tool proibida |
| Memoria | ATLS | sugerir candidatos | promover fatos/preferencias |
| Gateway | ATLS | receber/entregar mensagem | responder sem ATLS |
| Skills | ATLS | fornecer procedimento candidato | instalar/prometer qualidade sozinho |
| Cron/Webhook | ATLS | disparar execucao | manter rotina invisivel |
| Evidencia | ATLS | retornar ResultPacket | declarar sucesso sem prova |

## Fluxo

Fluxo manual:

```text
Operador -> ATLS Surface -> Intent -> Context -> Policy -> Runtime Router
-> ExecutiveMission -> Hermes -> HermesResultPacket -> Verifier
-> Evidence Ledger -> Memory Gate -> Resposta ATLS
```

Fluxo gateway:

```text
Canal externo -> Hermes Gateway -> ATLS ingress envelope -> ATLS decide
-> Hermes Worker opcional -> ATLS Verifier -> Hermes Gateway delivery
```

Fluxo de aprendizado:

```text
Hermes output -> MemoryDeltaCandidate -> Atlas Memory Gate
-> dedupe/review -> promote|quarantine|discard -> proxima missao
```

## Regras para IA

- Use este doc como alvo de produto, nao como prova de implementacao completa.
- Nao chamar Hermes completo ate Gateway, Skills, Cron/Webhooks e Memory Adapter
  terem adapters, testes e evidence dedicados.
- Nao propor memoria compartilhada sem Atlas Memory Gate.
- Nao deixar Gateway responder sem ATLS decidir.
- Nao criar runtime paralelo; estenda `hermes_cli` e o Hermes Adapter Suite.
- Nao promover ProcedureCandidate, ScheduleCandidate ou MemoryDeltaCandidate
  sem review, dedupe e owner doc.

## Arquitetura Alvo

```text
ATLS Sovereign Core
├── Intent Kernel
├── Context Compiler
├── Policy Engine
├── Runtime Router
├── Executive Mission Factory
├── Evidence Ledger
├── Memory Gate
├── Verifier
└── Hermes Adapter Suite
    ├── CLI Provider Adapter
    ├── Gateway Adapter
    ├── Skills Adapter
    ├── Cron/Webhooks Adapter
    ├── MCP/Tools Adapter
    └── Memory Adapter
```

## Modos Operacionais

### 1. Provider Executor

Uso: ATLS escolhe `hermes_cli` para uma tarefa manual governada.

Estado atual: implementado.

Entrada: prompt ATLS + Context Pack + permission scope.  
Saida: output Hermes + HermesResultPacket + ledger refs.

### 2. Gateway Ingress

Uso: Telegram, Discord, WhatsApp, API server ou outro canal entram pelo Hermes.

Contrato:

```text
Hermes recebe mensagem -> normaliza envelope -> ATLS decide -> Hermes entrega resposta
```

Regra: gateway nao chama modelo nem responde sozinho quando a conversa pertence
ao ATLS.

### 3. Worker Especializado

Uso: coding, research, ops, review, browser, files, MCP.

Contrato: Runtime Router escolhe profile Hermes e scope minimo. Worker retorna
evidencia, diff, teste, resumo ou erro estruturado.

### 4. Skill Mining

Uso: Hermes descobre procedimentos uteis, mas nao instala como verdade.

Contrato: Hermes retorna `ProcedureCandidate`; ATLS avalia baseline, risco,
duplicacao, owner doc e teste antes de promover para skill Atlas.

### 5. Cron/Webhooks

Uso: rotinas recorrentes, monitores, webhooks e background jobs.

Contrato: Hermes sugere ou executa dentro de `ScheduleCandidate` governado por
ATLS, com stop condition, evidence, idempotencia e visibilidade humana.

Estado atual: `HermesScheduleAdapter` persiste candidatos em `ai_scheduled_tasks`
(`kind=candidate`, `enabled=false`) apenas com `schedule_policy=atlas_adapter`. A
conversao para schedule ativo agora passa pelo `HermesScheduleActivationGate`, que
exige aprovacao, stop conditions, idempotencia/evidence e cadence valida.

### 6. Memory Adapter

Uso: capturar aprendizados operacionais sem criar memoria paralela.

Contrato: Hermes retorna candidatos; ATLS classifica, deduplica, versiona,
aprova ou descarta.

Estado atual: `HermesMemoryAdapter` persiste candidatos `pending` em
`ai_memory_deltas`; `HermesMemoryReviewGate` revisa, deduplica, rejeita ou promove
(via AtlasMemoryDeltaPromotionService) so com operator_confirmed e guards de qualidade.

## Schemas De Produto

### ExecutiveMission

```yaml
schema_version: atlas.hermes.executive_mission.v1
issued_by: atls
runtime: hermes
objective: string
scope:
  permission: read|write|danger
  network: none|limited|open
  allowed_paths: []
  forbidden_paths: []
context_pack:
  prompt_hash: sha256
  canonical_docs: []
runtime_profile:
  profile: atlas-hermes-coder|atlas-hermes-researcher|atlas-hermes-gateway
  provider: optional
  model: optional
memory_policy:
  hermes_memory: off|operational_only|atlas_adapter
  promotion_allowed_now: false
schedule_policy:
  hermes_schedule: off|atlas_adapter
  activation_allowed_now: false
success_criteria: []
```

### HermesResultPacket

```yaml
schema_version: atlas.hermes.result_packet.v1
mission_id: string
mission_hash: sha256
status: completed|failed|timeout
output:
  text_hash: sha256
  size_bytes: int
evidence_packet:
  commands: []
  files_touched: []
  validation: []
gateway:
  delivery_authority: atlas
memory_gate:
  candidate_count: int
  promotion_allowed_now: false
```

### MemoryDeltaCandidate

```yaml
schema_version: atlas.hermes.memory_delta_candidates.v1
candidates:
  - claim: string
    evidence: []
    confidence: 0.0
    class: fact|preference|procedure|workaround|noise
    suggested_action: promote|quarantine|discard
```

### ProcedureCandidate

```yaml
schema_version: atlas.hermes.procedure_candidates.v1
candidates:
  - name: string
    purpose: string
    steps: []
    required_tools: []
    risk_level: low|medium|high
    duplicate_check_required: true
    promotion_allowed_now: false
```

### ScheduleCandidate

```yaml
schema_version: atlas.hermes.schedule_candidates.v1
candidates:
  - name: string
    trigger: cron|webhook|manual
    cadence: string
    objective: string
    stop_conditions: []
    evidence_required: true
    activation_allowed_now: false
    activation_requires_atlas_schedule_gate: true
```

## Memoria E Flywheel

O ganho real e cumulativo:

```text
Uso do ATLS
-> contexto canonico melhora
-> ExecutiveMission fica mais precisa
-> Hermes executa melhor
-> ResultPacket gera evidencia
-> Memory Gate promove so o que presta
-> skills/profiles/routes melhoram
-> proxima missao fica melhor
```

Hermes fica "mais inteligente para o ATLS" porque recebe missoes melhores,
profiles mais adequados, skills curadas e contexto mais limpo. ATLS fica melhor
porque recebe evidencia e candidatos de aprendizado. A inteligencia composta
vem da governanca entre os dois.

## Escopo de Implementacao

Hermes Runtime so pode ser chamado de completo quando todos forem verdadeiros:

| Area | DoD |
|---|---|
| CLI Provider | ExecutiveMission, ResultPacket, policy, tests e ledger refs verdes |
| Gateway | mensagens externas sempre passam por ATLS antes de resposta |
| Skills | ProcedureCandidate avaliado por baseline, duplicacao e owner doc |
| Cron/Webhooks | ScheduleCandidate com stop gates, idempotencia e evidence |
| Memory Adapter | nenhum aprendizado promove sem Atlas Memory Gate |
| Runtime Router | auto-routing permitido apenas com policy e DecisionReceipt |
| Observability | cada execucao tem trace, hashes, status e validacao |
| Safety | configs inseguras removidas, secrets redigidos, yolo so com danger |
| Reversibilidade | cada adapter pode ser desligado sem quebrar ATLS |

Sequencia esperada:

| Fase | Resultado | Estado |
|---|---|---|
| 1 | `hermes_cli` provider manual governado | implementado |
| 2a | ExecutiveMission + HermesResultPacket | implementado |
| 2b | Gateway adapter | adapter + delivery gate implementados; transporte de canal pendente |
| 3 | ProcedureCandidate/Skills adapter | adapter + promocao via SkillPackPromotionGate implementados |
| 4 | ScheduleCandidate/Cron-Webhooks adapter | persistencia + Schedule activation gate implementados |
| 5 | Memory Adapter persistente | persistencia + Memory review/promotion gate implementados |
| 6 | Runtime Router auto-routing governado | implementado, default-safe (allow_auto off) |

## Estado Atual

Implementado e testado: provider `hermes_cli` (manual + auto-routing governado),
`atlas.hermes.executive_mission.v1`, `atlas.hermes.result_packet.v1`, Memory e
Schedule adapters persistentes, Gateway adapter governado (ingress + delivery
gate), Procedure adapter revisavel, Schedule activation gate, Memory review/
promotion gate, e Runtime Router default-safe com DecisionReceipt executive_runtime.
ProviderUsage carrega mission/result + recibos de todos os adapters/gates; cada
recibo `atlas.hermes.*_receipt.v1` e selado com receipt_hash. Health check por
`hermes chat --help`. Suite Hermes verde (provider, adapters, gates, router, evidence).

Ainda falta para produto final: surfaces de operador (comandos/controllers) sobre
os gates, transporte real de canal (ingress controller + outbound send) acoplado ao
Gateway adapter, e ramp de auto-routing por dominio mantendo o default-safe atual.

## Dependencias

Depende de `atlas-hermes-executive-runtime.md`, arquitetura canonica,
knowledge governance, runtime language boundaries, performance/memory strategy e
`programming-power-tools-catalog.md`.

## Evidencias

- Este documento de produto.
- Doc pai `atlas-hermes-executive-runtime.md`.
- `app/Services/Ai/HermesCliProvider.php`.
- `app/Services/Ai/Hermes/HermesExecutiveMissionFactory.php`.
- `app/Services/Ai/Hermes/HermesMemoryAdapter.php`.
- `app/Services/Ai/Hermes/HermesResultPacketFactory.php`.
- `app/Services/Ai/Hermes/HermesScheduleAdapter.php`.
- `app/Services/Ai/Kernel/Evidence/ProviderUsagePayload.php`.
- `tests/Unit/AiCliProviderRuntimeArgsTest.php`.
- `tests/Unit/Ai/HermesExecutiveRuntimePacketEvidenceTest.php`.

## Riscos

- Hermes virar uma segunda interface soberana ao lado do ATLS.
- Gateway externo responder antes de ATLS decidir.
- Memoria Hermes criar drift contra memoria canonica.
- Skills e cron virarem automacao invisivel sem review.
- O produto parecer completo porque o CLI funciona, mesmo sem adapters finais.

## Exemplos

Gateway: Telegram entra pelo Hermes Gateway e ATLS decide a resposta. Skill:
Hermes retorna ProcedureCandidate; ATLS deduplica, avalia owner doc e promove.

## Proximas Acoes

1. Implementar Gateway adapter Hermes -> ATLS -> Hermes Gateway.
2. Promover `ProcedureCandidate` para Skills somente via review ATLS.
3. Converter `ScheduleCandidate` persistido em Cron/Webhooks ativo somente via stop gates.
4. Revisar/promover MemoryDeltaCandidate persistido via Atlas Memory Gate.
5. Liberar auto-routing Hermes somente com DecisionReceipt e policy forte.

Regra final: Hermes deve ser capacidade multiplicadora, nao substituto do ATLS.
O produto mais poderoso da a Hermes o maximo de capacidade executiva e zero
autoridade soberana.
