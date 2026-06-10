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
  - runtime_local_performance_boundary
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
  - app/Services/Ai/RuntimeBoundary/PythonManifestRuntimeClient.php
  - runtimes/python/atlas_runtime_contract/entrypoint.py
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-runtime-language-boundaries

graph_title: Atlas AI Runtime Language Boundaries

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Runtime Language Boundaries
canonical_name: Atlas AI Runtime Language Boundaries
technical_name: atlas-ai-runtime-language-boundaries
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md

owner: architecture

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md

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
  - architecture

evidence:
  - docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
evidence_refs:
  - symbol: AtlasAiRuntimeLanguageBoundariesService
  - command: atlas:aaeos:runtime-language-boundaries
  - test: AtlasAiRuntimeLanguageBoundariesTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - system
  - module
  - architecture

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
# Atlas AI Runtime Language Boundaries

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

Adapters Laravel podem manter manifest, chunking, privacy gate, chamada governada de
embedding ou falha explicita quando o runtime real nao esta disponivel. Eles nao podem virar Vector RAG, Graph RAG, reranker, clustering, analytics pesada ou source of truth, nem fabricar vetores por hash.

Entrypoints Python que seguem o contrato `main.py <manifest.json>` e emitem uma
linha JSON devem reutilizar `runtimes/python/atlas_runtime_contract` para leitura
de manifest, envelope `ok/error` e codigo de saida. O `main.py` de cada runtime
deve apenas ligar o runner especializado e preservar qualquer formato historico
de erro quando houver compatibilidade a manter.

Adapters Laravel para esses runtimes devem reutilizar
`PythonManifestRuntimeClient` para disponibilidade, manifest temporario,
processo, parsing e limpeza. Cada client especifico continua responsavel pelo
anti-fake guard do seu boundary receipt.

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
5. manter banco paralelo de estado canonico ou ser invocado por atalho `go run` dentro do Laravel.

## Papel Do Swift

Swift e o Native Mac Runtime. Use para:

1. Keychain, Touch ID e XPC helper isolado;
2. notificacoes nativas, Menu Bar e LED virtual de status;
3. FSEvents e NSWorkspace focus events;
4. Accessibility opt-in (selected text only) e ScreenCaptureKit manual;
5. Core Spotlight, Shortcuts/App Intents e deep links;
6. Core ML leve para classificacao/embedding/STT local sem substituir Decide;
7. Voice Realtime Edge futuro no Mac: wake word local, VAD, echo cancel,
   captura de mic/AirPods e LiveKit Swift SDK como cliente WebRTC. A primeira
   surface de voz continua sendo mobile-first.

Identidade canonica do runtime: `swift_native_mac`.

Nao use Swift para Kernel, API, provider routing, Graph RAG, analytics pesado,
decisao de dominio/modelo, atalho `swift run` dentro do Laravel, automacao destrutiva sem approval ou streaming de
audio/video ambiente sem wake word/eclipse/policy do Kernel.

Contratos detalhados: `atlas-native-mac-agent.md` e
`atlas-ai-voice-realtime-surface.md`.

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

## Preflight Gate

`atlas.runtime_boundary_preflight_gate.v1` exige antes de qualquer Python, Go ou
Swift: `place-feature --strict`, `runtime-boundary --json`, doc dono atualizado,
`runtime_invocation_contract`, evidence contract, rollback plan, testes focados
e `architecture-validate`. Proibido: runtime sem doc dono, chamada direta da
surface, pular Decision Receipt, escrever Memory/Context/Policy pelo runtime ou
deixar runtime escolher provider, modelo, dominio ou flow.

## Runtime Promotion Policy

`atlas.runtime_promotion_policy.v1` proibe auto-promocao. Promover runtime exige
review humano, Decision Receipt, rollback, Evidence Ledger, policy patch
revisavel, testes focados, docs-health e architecture-validate verdes.

## Modos De Implantacao

Modos permitidos: CLI JSON local para prototipo, FastAPI local para hot path
RAG/ML, Go daemon para ingestao/edge e Swift app/helper para APIs Apple. Todos
exigem AP, opt-in quando houver sensor, receipt e evidence.

## Evidence Obrigatoria
Todo runtime Python, Go ou Swift deve registrar:

1. `RUNTIME_INVOKED`;
2. `RUNTIME_RETURNED` ou `RUNTIME_FAILED`;
3. duracao, custo estimado, memoria, input hash e output hash;
4. artifact refs sem vazar dados sensiveis;
5. finding normalizado quando houver erro, drift ou gap;
6. link para `DecisionReceipt`.

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
8. scheduler canonico;
9. `EmbeddingService` alem de manifest/chunking/privacy, adapter governado
   `semantic_rag`/OpenAI ou falha explicita antes de AP `python_ai_data`.

## Definition Of Done

Antes de implementar runtime Python, Go ou Swift: spec curta, runtime owner,
payload/result schema, DecisionReceipt, Evidence Ledger, health check, teste de
contrato, docs/Code Intelligence atualizados, `docs-health` e
`architecture-validate` verdes.

## Resumo

Contrato canonico que separa o papel de Laravel, Python, Go e Swift no Atlas AI para impedir microservicos paralelos, decisao fora do Kernel e duplicacao de runtime.

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
