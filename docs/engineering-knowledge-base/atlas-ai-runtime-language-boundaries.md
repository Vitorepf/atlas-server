---
id: atlas-ai-runtime-language-boundaries
type: engineering_knowledge
title: Atlas AI Runtime Language Boundaries
status: active
category: architecture
priority: 100
summary: Contrato canonico que separa o papel de Laravel, Python, Go e Swift no Atlas AI para impedir microservicos paralelos, decisao fora do Kernel e duplicacao de runtime.
tags:
  - atlas-ai
  - runtime
  - language-boundaries
  - laravel
  - python
  - go
  - swift
capabilities:
  - runtime_language_boundaries
  - python_ai_data_runtime
  - go_edge_runtime
  - swift_native_mac_runtime
  - laravel_kernel
  - local_ai_performance_strategy
decisions:
  - Laravel/PHP e o Kernel/Maestro canonico do Atlas AI.
  - Python pode existir como runtime especializado de IA, dados, RAG, ML, multimodal e analytics.
  - Go pode existir como runtime especializado de edge, rede, ingestao, concorrencia, streaming e agentes leves.
  - Swift pode existir como Atlas Native Mac Agent para APIs Apple, seguranca local, contexto ambiental e automacao assistida.
  - Python, Go e Swift nunca decidem dominio, modelo, autonomia, policy, repair, gate ou learning sem Decision Receipt emitido pelo Kernel.
maintenance:
  - Manter abaixo de 260 linhas.
  - Atualizar antes de criar qualquer servico Python, Go, Swift, worker externo, bridge, daemon ou runtime multi-linguagem.
  - Rodar docs-health e architecture-validate depois de alterar.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-evolution-roadmap.md
  - docs/engineering-knowledge-base/atlas-ai-autonomy-power-backlog.md
  - docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
  - docs/engineering-knowledge-base/atlas-native-mac-agent.md
  - docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
  - docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md
---

# Atlas AI Runtime Language Boundaries

Este documento define o papel de Laravel, Python, Go e Swift no Atlas AI.

Ele existe para impedir que uma IA crie microservico paralelo, replique decisao
do Kernel ou transforme linguagem em novo cerebro.

## Regra Mae

Laravel decide e governa. Python, Go e Swift executam capacidades especializadas.

```text
Surface -> Laravel Kernel -> Decision Receipt -> Runtime especializado
                                      |
                                      v
                         Evidence Ledger / Gates / Learning
```

Nenhum runtime externo pode pular `Atlas Decide`, `Policy/Profile`,
`DecisionReceipt`, `Evidence Ledger`, `Quality Gates` ou limites de autonomia.

## Papel Do Laravel/PHP

Laravel e o Kernel/Maestro do Atlas.

Responsabilidades canonicas:

1. receber requests de CLI, API, app, mobile, MCP e surfaces;
2. autenticar, autorizar, aplicar tenancy e privacy;
3. criar `OperationEnvelope`;
4. classificar intent, domain e flow;
5. emitir `DecisionReceipt`;
6. compilar Policy/Profile, budget e autonomy;
7. orquestrar Provider Driver, Domain Runtime, Super Tool Runtime e Harness;
8. gravar Evidence Ledger e projections;
9. executar gates, repair policy, learning e proposal inbox;
10. expor UI, API, CLI, observability e governance.

Laravel e a fonte de verdade operacional. Ele pode chamar Python, Go ou Swift,
mas nao terceiriza soberania.

## Papel Do Python

Python e o AI/Data Runtime.

Use Python quando a tarefa exigir:

1. embeddings, Vector RAG, Graph RAG e reranking;
2. FAISS, Chroma, LlamaIndex, LangGraph, NetworkX;
3. Pandas, Polars, NumPy, scikit-learn e estatistica pesada;
4. analise de campanhas, CPA, ROI, logs e series temporais;
5. modelos locais leves, anomaly detection e scoring preditivo;
6. processamento multimodal pesado: OCR, audio, imagem, video;
7. experimentos agentic/swarms em modo governado;
8. notebooks ou pipelines de pesquisa que depois viram runtime.

Nao use Python para:

1. substituir Laravel API;
2. decidir provider/modelo por conta propria;
3. gravar memoria sem privacy/gate;
4. criar fila ou scheduler paralelo;
5. executar acao destrutiva sem receipt e approval;
6. virar source of truth de Evidence.

Status inicial recomendado: `scaffold/future` ate existir contrato de payload,
worker, health, replay e testes.

Uso de 48GB RAM, RAG local, rerank, cache, modelos locais e precomputacao vive
em `atlas-ai-local-performance-memory-strategy.md`.

## Papel Do Go

Go e o Edge/Concurrency Runtime.

Use Go quando a tarefa exigir:

1. ingestao de alto volume: cliques, postbacks, webhooks, tracking;
2. conexoes longas, watchers, local agents e bridges;
3. baixa latencia, backpressure e throughput previsivel;
4. streaming com Redis, NATS, Kafka ou canais equivalentes;
5. proxy/gateway interno, callback receiver, event forwarder;
6. binario unico leve para daemon sempre ligado;
7. collectors de telemetry, health probes e log shippers.

Nao use Go para:

1. implementar IA/RAG/ML pesado;
2. decidir domain, flow, provider, policy ou repair;
3. duplicar Super Tool Runtime;
4. gravar dados finais sem passar por event contract;
5. manter banco paralelo de estado canonico.

Status inicial recomendado: `future/scaffold` ate existir necessidade real de
alto volume, streaming, agent local ou rede persistente.

## Papel Do Swift

Swift e o Native Mac Runtime. Use para:

1. Keychain, Touch ID e XPC helper isolado;
2. notificacoes nativas, Menu Bar e LED virtual de status;
3. FSEvents e NSWorkspace focus events;
4. Accessibility opt-in (selected text only) e ScreenCaptureKit manual;
5. Core Spotlight, Shortcuts/App Intents e deep links;
6. Core ML leve para classificacao/embedding/STT local sem substituir Decide;
7. Voice Realtime Edge: wake word local, VAD, echo cancel, captura de mic e
   AirPods, LiveKit Swift SDK como cliente WebRTC.

Identidade canonica do runtime: `swift_native_mac`.

Nao use Swift para Kernel, API, provider routing, Graph RAG, analytics pesado,
decisao de dominio/modelo, automacao destrutiva sem approval ou streaming de
audio/video ambiente sem wake word/eclipse/policy do Kernel.

Contratos detalhados:
- `atlas-native-mac-agent.md` — contrato base de capabilities Apple.
- `atlas-ai-voice-realtime-surface.md` — voice edge (`atlas-voice-edge`).

## Matriz De Decisao

| Necessidade | Linguagem dona | Motivo |
|---|---|---|
| API, auth, policy, receipt, ledger, gates | Laravel | Kernel e governanca |
| Domain orchestration | Laravel | contrato central e auditavel |
| Provider routing | Laravel | Atlas Decide e Provider Driver |
| Graph RAG, Vector RAG, embeddings | Python | ecossistema de IA/dados |
| Pandas/Polars analytics | Python | data crunching eficiente |
| ML local leve | Python | scikit-learn e modelos locais |
| Swarm/agentic experiment | Python | frameworks de pesquisa |
| Webhook/click/postback em massa | Go | concorrencia e baixa latencia |
| Daemon local leve | Go | binario pequeno e estavel |
| Streaming/backpressure | Go | rede e I/O concorrente |
| Touch ID, Keychain e notificacoes nativas | Swift | APIs Apple nativas |
| FSEvents, Accessibility e ScreenCaptureKit | Swift | contexto local opt-in |
| Wake word local, VAD, echo cancel, captura mic/AirPods | Swift | latencia <50ms e zero stream antes de wake |
| LiveKit client SDK no edge do Mac | Swift | qualidade nativa de audio e WebRTC |

## Contrato De Comunicacao

Todo runtime externo deve receber um payload assinado pelo Kernel:

```json
{
  "schema_version": "atlas.runtime.invoke.v1",
  "envelope_id": "uuid",
  "decision_receipt_hash": "sha256",
  "runtime": "python_ai_data|go_edge|swift_native_mac",
  "domain_id": "programming|finance|marketing|...",
  "flow_id": "domain.flow",
  "input": {},
  "policy": {},
  "limits": {},
  "evidence_contract": {}
}
```

E deve responder:

```json
{
  "schema_version": "atlas.runtime.result.v1",
  "status": "succeeded|failed|blocked|needs_review",
  "artifacts": [],
  "metrics": {},
  "findings": [],
  "evidence": []
}
```

Laravel valida a resposta, grava evidence, roda gates e decide proximo passo.

## Modos De Implantacao

| Modo | Quando usar | Status |
|---|---|---|
| CLI JSON local | primeiro prototipo seguro | recomendado para inicio |
| Worker de fila | tarefas assincronas com retry | medio prazo |
| FastAPI local | serviço persistente de RAG/ML | quando houver hot path real |
| Go daemon | ingestao/edge sempre ligado | quando houver volume real |
| Swift app/helper | APIs Apple, approvals e contexto local | com AP e opt-in |
| Microservicos separados | escala ou isolamento forte | somente com AP proprio |

Comecar simples. Promover apenas quando evidence provar necessidade.

## Evidence Obrigatoria

Todo runtime Python, Go ou Swift deve registrar:

1. `RUNTIME_INVOKED`;
2. `RUNTIME_RETURNED` ou `RUNTIME_FAILED`;
3. duracao, custo estimado, memoria, input hash e output hash;
4. artifact refs sem vazar dados sensiveis;
5. finding normalizado quando houver erro, drift ou gap;
6. link para `DecisionReceipt`.

Sem evidence, o runtime nao existe operacionalmente.

## Anti-Duplicacao

Se Python, Go ou Swift precisar de capability que ja existe no Kernel, ele deve
chamar o Kernel ou receber capability token. Nao copiar:

1. policy engine;
2. provider selection;
3. memory privacy;
4. repair limits;
5. domain registry;
6. evidence schema;
7. approval flow;
8. scheduler canonico.

## Definition Of Done

Antes de implementar runtime Python, Go ou Swift:

1. criar ou atualizar spec curta;
2. definir runtime owner e status;
3. declarar payload/result schema;
4. passar por DecisionReceipt;
5. gravar Evidence Ledger;
6. ter health check;
7. ter teste de contrato;
8. atualizar docs e Code Intelligence;
9. rodar `docs-health` e `architecture-validate`.
