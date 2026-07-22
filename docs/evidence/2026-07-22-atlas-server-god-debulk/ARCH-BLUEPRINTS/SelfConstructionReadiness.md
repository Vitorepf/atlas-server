# ARCH BLUEPRINT — SelfConstruction Readiness

> status: draft-v2 (SOBREVIVEU ao verify adversarial 2026-07-22; 4 emendas — evidências verificadas pelo comandante)
> EMENDAS: (1) os testes reflexivos por Section (AtlasAiSelfConstructionReadinessProjection*SectionTest) apontam para classes que a migração deleta — deletar/migrar JUNTO na fatia correspondente (senão vermelho ou fantasma); (2) 9+ geradores de comando em código VIVO emitem flags do MotherCommand (GuidanceBuilder:79-80/288 VERIFICADO, ProofCommandFactory:20-35, ReceiptDraft:133, SmokeEndgame:259, WorkerCommandFormatter:47) — entram no re-apontamento da fase 7 + Scanner varre 'atlas:ai:self-construction --' em app/; (3) refresh de contagens: 735 delegações→656 medidas em HEAD (~11% drift), LOC Δ pequeno pós-fixes do EXECUTE; (4) Fase 0 item 1 parcialmente OBSOLETA: A1-SC-0019 (Schema import) e A1-SC-0020/0021 JÁ CORRIGIDOS em HEAD pelo EXECUTE — a Fase 0 remanescente é characterization matrix + espiões de mutação, não os bugfixes.
> data: 2026-07-22
> obra: GOD Debulk / capability SelfConstruction Readiness
> insumos: docs/evidence/2026-07-22-atlas-server-god-debulk/META-FINDINGS/A1--SelfConstruction.md (A1-SC-0001..0043)

## 1. Contexto

### 1.1 Estado atual (provado)

O agregado é composto por 1 façade god + 21 Sections com back-reference (`setMother`/parent injection),
totalizando >105.000 LOC na pasta `app/Services/Ai/SelfConstruction/Readiness/`:

| Arquivo | LOC | Métodos públicos | Papel real |
|---|---|---|---|
| AtlasSelfConstructionReadinessService.php | 29.744 | 1.242 (735 delegações one-line) | hub de roteamento obrigatório + queries + writers disfarçados |
| ReadinessProjectionAgentReviewMergeSection.php | 14.169 | 109 | máquina de estados review/merge (agente) |
| ReadinessProjectionAgentCodexSection.php | 13.784 | 171 (57 triads) | OS de execução Codex embutido |
| ReadinessProjectionCodexReviewMergeSection.php | 12.751 | 149 (73,2% duplica a de agente) | segunda máquina review/merge (Codex) |
| ReadinessProjectionAgentAutomaticDispatchBatch1/2Section.php | 6.198+ | 50+ | fatia mecânica do scheduler de dispatch |

### 1.2 Três superfícies sincronizadas (falsa abstração central)

Cada método existe 3 vezes: implementação na Section → delegador one-line no service →
rota `FLAG_METHOD` no `AtlasAiSelfConstructionMotherCommand` (despacho dinâmico por nome).
Qualquer mudança exige tri-sincronização manual. As Sections chamam de volta o pai via
`setMother` + `__call` irrestrito (inclusive `ReflectionMethod` sobre métodos não-públicos —
A1-SC-0035).

### 1.3 Consumo externo real (decide a façade)

Dos 167 arquivos que referenciam o service, os consumidores **fora** de Readiness usam fatias estreitas:

- `NativeImplementation/*` (21 arquivos): ~só `atlasSelfConstructionOsCompletionEvidenceStatus` + família completion.
- `ControlPlane/*` (11 arquivos): só família `agentControlPlane*`.
- `Aaeos/Quarantine/*` (3): 1 método cada (workSplitter/durableReservation/packetQueue contract).
- `Support/*`, `Completion/*`: 1-2 métodos cada.
- CLI: MotherCommand (mapa flag→método, ~1.100 rotas), StatusCommand, CodexReviewChainContractCommand.
- Testes: majoritariamente via `artisan()` no MotherCommand (228 invocações no CommandTest) — ou seja,
  a compatibilidade crítica é **nome de flag CLI + schema do payload JSON + hashes estáveis**, não a classe.

Conclusão: a superfície de 1.242 métodos é consumida por ~9 famílias funcionais. A façade nova
pode ser pequena; a antiga vira compat de nomes por ≤1 ciclo.

### 1.4 Famílias funcionais reais (mapeadas por prefixo)

1. **Readiness core queries** — digest, scorecard, cold-lane, surface matrix, ownership boundary (~60 singletons).
2. **Control-plane runtime** — queue/lease/replenish/bootstrap/heartbeat/wakeup (`agentControlPlane*`, 238).
3. **Certification workbench** — quartets, chain integrity, coverage, batch (220 call sites de quartet).
4. **Completion evidence + operator handoff** — `atlasSelfConstruction{Completion,Final,Human,Operator,Os,Runtime}*` + SelfProgramming transition (170).
5. **Provider execution (Codex)** — `agentCodex*` (171; 57 triads ContractTemplate/Preflight/ImplementationPacket).
6. **Review/merge lifecycle** — `agentReviewMerge*` (109) + `codexReviewMerge*` (149), 73,2% de overlap normalizado.
7. **Automatic dispatch scheduler** — `agentAutomaticDispatch*` (267, Batch1/2).
8. **Dispatch gates / provider adapters** — `agentDispatch*`, `agentProvider*`, `durableReservation*` (~60).
9. **Projection/hash infra** — ReadinessHash, envelopes, Schema probes (transversal: 121+151+60 `Schema::hasTable`, 229+119+79 hashes, 110+149+50 blocos `non_execution_guarantees`).

## 2. Owners-alvo

Sufixos permitidos: Facade/Runtime/Service/Policy/Projector/Scanner/Evaluator/Gateway/Command/Provider/ValueObject.
Famílias de método: decide*/pack*Context/rank*/certify*/project*/run*. Nenhum PHP novo >2.000 LOC; hot façade ≤800.
Namespace alvo: `app/Services/Ai/SelfConstruction/Readiness/` (infra + core) e subpastas por família
(`ReviewMerge/`, `ProviderExecution/`, `Dispatch/`, `ControlPlane/` já existente, `Completion/` já existente).

### 2.1 Infra transversal (fundação — construída primeiro)

| Classe | Sufixo | Responsabilidade | Teto LOC |
|---|---|---|---|
| `ReadinessProjectionContext` | ValueObject | Contexto **imutável por request**: mapa tabela→existe (cada `Schema::hasTable` no máx. 1x por contexto), snapshots agregados de queries, relógio, memo de projeções de cadeia já computadas. Único lugar onde estado de schema/DB é lido para projeção. | 400 |
| `ReadinessContextProvider` | Provider | Único construtor do contexto; único autorizado a tocar `Schema`/Eloquent para probes. Importa `Illuminate\Support\Facades\Schema` corretamente (mata a classe fatal A1-SC-0019). | 300 |
| `ReadinessFailClosedPolicy` | Policy | **A política fail-closed nomeada única.** `decideOuterStatus(payload): StatusDecision` — `ready` só se blockers==0 ∧ autoridades exigidas true ∧ evidência presente ∧ campos obrigatórios existem; campo ausente ⇒ `blocked` + violação tipada. Estados distintos: `schema_ready` / `evidence_pending` / `authorized` / `executable` / `completed` / `blocked`. Pura, sem I/O. | 400 |
| `ReadinessEnvelopeProjector` | Projector | Único construtor de envelopes de status: `projectEnvelope(payload, ctx)`. Deriva outer status via `ReadinessFailClosedPolicy`; emite `runtime_write_performed` verdadeiro (nunca hard-code `read_only`); deriva `non_execution_guarantees` **uma vez** da policy (mata as 110+149+50 repetições). Hash estável centralizado. | 500 |
| `ReadinessTransitionCatalog` | ValueObject | Catálogo declarativo de transições: cada entrada = {id, família, predecessores, evidência exigida (chaves), hash key produzida, autoridade semântica, tabelas necessárias, provider-neutral?}. Validado na construção: grafo **acíclico**, sem chave/tag/método desconhecido (mata triads handmade, DSL posicional de 83 tags, bridge circular). | 600 |
| `ReadinessTransitionCatalogProvider` | Provider | Carrega + valida os dados do catálogo (arquivos de dados por família, cada um <1.500 LOC: `catalog/ReviewMergeTransitions.php`, `catalog/ProviderExecutionTransitions.php`, `catalog/DispatchTransitions.php`). Erro de validação = fatal tipado no boot do contexto. | 400 |
| `ReadinessChainProjector` | Projector | `projectChain(entry, ctx)`: projeta qualquer transição computando a cadeia de predecessores **uma vez por contexto** (memo no ctx), hash incremental — mata a reconstrução de 108/194 predecessores por chamada. | 700 |

### 2.2 Owners por família

| Classe | Sufixo | Responsabilidade | Teto LOC |
|---|---|---|---|
| `SelfConstructionReadinessFacade` | Facade | **Hot façade nova**: ~10-12 entrypoints por família (`projectDigest`, `projectControlPlaneStatus`, `certifyCapability`, `projectReviewMerge`, `runControlPlaneCommand`, ...). Zero lógica: valida input, monta contexto via Provider, delega. | ≤800 |
| `ReadinessStatusProjector` | Projector | Core queries: digest, scorecard, cold-lane, surface matrix, ownership boundary. `project*` only, read-only real (proibido chamar mutador). Scorecard passa a citar path real e consumir evidência via Gateway (A1-SC-0008). | 800 |
| `ControlPlaneStatusProjector` | Projector | Projeções read-only de fila/lease/heartbeat/wakeup sobre snapshot do contexto. Nunca chama orquestradores mutantes. | 800 |
| `AgentControlPlaneRuntime` | Runtime | Comandos mutadores **explícitos e nomeados**: `runLeaseRecovery`, `runQueueReplenishment`, `runClaimNext`, `runWorkerBootstrap`, `runHeartbeat`. Delegam aos services ControlPlane já existentes; envelope reporta `runtime_write_performed=true`, ids de artefato, chave de idempotência. Mata a mentira mode=read_only (A1-SC-0003). | 800 |
| `CertificationWorkbenchEvaluator` | Evaluator | `certifyCapability(catalogEntry, ctx)`: substitui os 220 call sites de `buildCertificationWorkbenchQuartet` e os 67 `wrapCertificationWorkbenchStatus` por 1 avaliador dirigido pelo catálogo. Reusa o QuartetBuilder existente enquanto caracterizado. | 1.200 |
| `CompletionEvidenceEvaluator` | Evaluator | Famílias `atlasSelfConstruction{Completion,Final,Os,Runtime}*` + SelfProgramming transition: avalia evidência de conclusão contra o catálogo; gap-matrix computada 1x por contexto. | 1.500 |
| `OperatorHandoffProjector` | Projector | Famílias `atlasSelfConstruction{Human,Operator}*`: projeções de handoff/receipt humano. Nomes honestos: tudo que não notifica/cria task chama-se `*Preview`. | 800 |
| `CompletionEvidenceGateway` | Gateway | Único I/O de evidência de conclusão (storage/DB/dossiês). | 600 |
| `ReviewMergeLifecyclePolicy` | Policy | `decideMergeAuthorization`, `decideWriterRelease`, `decideEscalation`, `decideLaterCycle` — regras puras do lifecycle review/merge, **provider-neutral** (1 owner para as 2 máquinas duplicadas; provider vira parâmetro). Fail-closed via `ReadinessFailClosedPolicy`. | 800 |
| `ReviewMergeLifecycleProjector` | Projector | Projeta as transições review/merge do catálogo (um catálogo, parametrizado provider=agent\|codex). Substitui os 109+149 métodos = 26.920 LOC das duas Sections. | 1.500 |
| `ReviewMergeEvidenceGateway` | Gateway | I/O de receipts/assinaturas/persistência append-only do review/merge. | 600 |
| `ProviderExecutionPolicy` | Policy | `decideExecutionAuthorization`, `decideDispatchAuthorization`, `decideProcessStart` — autoridade de execução fail-closed; `execution_allowed` nunca é literal, sempre decisão. | 800 |
| `ProviderExecutionProjector` | Projector | Projeta os 57 triads (pre-start + post-start) via catálogo acíclico: conserta hash key fantasma (A1-SC-0020) e pré-requisito circular do bridge (A1-SC-0021) por construção — o catálogo rejeita ciclo na validação. | 1.500 |
| `CodexExecutionProvider` | Provider | Adapter Codex: binários, paths, especificidades de spawn/processo. Segundo provider (agente/manual) prova a abstração — invariante já existe (73% overlap provado). | 800 |
| `ProviderProcessGateway` | Gateway | Único ponto futuro de I/O de processo (spawn/kill/observe). Nasce honesto: métodos `*Preview` sem efeito até autorização real. | 600 |
| `DispatchSchedulerPolicy` | Policy | `decideNextDispatch`, `rankDispatchCandidates`, gates de runtime do scheduler. Capability checks resolvem no owner real, nunca `method_exists($this,...)` (A1-SC-0037). | 800 |
| `DispatchStatusProjector` | Projector | Projeções DB-backed do dispatch usando snapshot do contexto (mata os 60 probes + 22 counts repetidos, A1-SC-0041). | 1.200 |
| `ImplementationPacketEvaluator` | Evaluator | `packImplementationContext(entry, ctx)`: valida cada `allowed_files` contra a árvore atual no momento da emissão; path inexistente ⇒ fail-closed (A1-SC-0038). | 600 |
| `ReadinessCapabilityScanner` | Scanner | Inventário: gera/verifica o mapa flag CLI → owner → método a partir do catálogo (fonte única de verdade; mata a tri-sincronização). Alimenta `codemap-verify`. | 600 |

### 2.3 Compat (vida útil ≤1 ciclo de migração)

- `AtlasSelfConstructionReadinessService` — congela como **compat façade gerada**: cada um dos 1.242
  nomes vira delegação one-line para o owner novo (o corpo encolhe fatia a fatia; nunca cresce).
  Anotada `@deprecated` com data de remoção. Sem lógica, sem estado, sem Sections.
- `AtlasAiSelfConstructionMotherCommand` — `FLAG_METHOD` passa a ser **verificado pelo Scanner**
  contra o catálogo; no fim do ciclo, colapsa para famílias documentadas (≤12 entrypoints canônicos,
  flags antigas viram aliases datados).
- Todos os `ReadinessProjection*Section.php` — deletados ao fim das fatias (nunca recebem código novo).

## 3. Grafo de dependência (one-way, sem exceção)

```
Commands (Mother/Status/ReviewChain)
  │
  ▼
SelfConstructionReadinessFacade          AtlasSelfConstructionReadinessService (compat, ≤1 ciclo)
  │                                          │ (delegação one-line para os mesmos owners)
  ▼                                          ▼
┌────────────────────────────────────────────────────────────┐
│  Policies (puras)      Projectors           Evaluators     │
│  ReadinessFailClosed   ReadinessStatus      CertWorkbench  │
│  ReviewMergeLifecycle  ControlPlaneStatus   CompletionEvid │
│  ProviderExecution     ReviewMergeLifecycle ImplPacket     │
│  DispatchScheduler     ProviderExecution                   │
│                        DispatchStatus                      │
│                        OperatorHandoff                     │
│  Runtime (mutação explícita): AgentControlPlaneRuntime     │
└────────────────────────────────────────────────────────────┘
  │                        │
  ▼                        ▼
Gateways / Providers            Infra
CompletionEvidenceGateway     ReadinessProjectionContext (VO)
ReviewMergeEvidenceGateway    ReadinessContextProvider
ProviderProcessGateway        ReadinessTransitionCatalog (VO)
CodexExecutionProvider        ReadinessTransitionCatalogProvider
(+ services ControlPlane      ReadinessEnvelopeProjector
   já existentes)             ReadinessChainProjector
```

Regras pétreas do grafo:
1. Setas só descem. **Proibido** `setMother`, back-reference, `__call` cross-camada, `ReflectionMethod` em colaborador.
2. Policy nunca faz I/O; Gateway nunca decide; Projector nunca muta; Runtime é o único que muta e sempre o declara no envelope.
3. Owners de família não se chamam entre si; composição só via Facade ou via catálogo (predecessores declarados).
4. Provider (Codex) só é referenciado por Gateway/Projector via interface neutra; readiness nunca importa detalhes Codex diretamente.

## 4. Padrões aplicados

1. **Catálogo declarativo de transições** — substitui: 57 triads handmade, DSL posicional de 83 tags,
   108/194 chamadas de predecessor, 110+149+50 blocos non_execution_guarantees. Cada transição declara
   evidência de entrada, invariantes, estado resultante e autoridade permitida. Validação na construção:
   acíclico, chaves conhecidas, produtor existe para toda hash key consumida (mata A1-SC-0020/0021 por construção).
2. **Fail-closed único e nomeado** — `ReadinessFailClosedPolicy`. Nenhum `'status' => '*_ready'` literal;
   nenhum default `true` para campo ausente; outer status ⊇ inner status (envelope nunca mais pronto que o payload).
3. **Contexto de projeção imutável por request** — probes de schema e agregados computados 1x;
   memo de cadeia; orçamentos (query/probe/memória/latência) medíveis na borda do Provider.
4. **Separação policy vs I/O vs projection** — enforced pelo sufixo + grafo; mutação só em Runtime/Gateway com envelope verdadeiro.
5. **Abstração só com invariante ou 2º consumidor** — ReviewMerge unificado tem 2 consumidores provados
   (agent + codex, 73,2% overlap); ProviderExecution tem invariante (autorização fail-closed) + 2º provider planejado;
   nada além disso ganha camada nova.
6. **Nomes honestos** — superfícies inertes chamam-se `*Preview`; `HumanEscalation`/`ManualDecisionRequest`
   sem efeito real são renomeadas (alias antigo mantido no compat por 1 ciclo).
7. **Fonte única para a superfície CLI** — Scanner gera/verifica flag→owner; fim da tri-sincronização.

## 5. Mapa finding → owner/decisão

| Finding | Resolvido por |
|---|---|
| A1-SC-0001 (god 29.744 LOC) | Divisão em owners §2.2 + compat façade §2.3; teto 2.000/800 |
| A1-SC-0002 (falsa abstração, 3 superfícies) | Scanner (fonte única flag→owner) + deleção das Sections + grafo one-way |
| A1-SC-0003 (Status que muta) | AgentControlPlaneRuntime (`run*`) vs ControlPlaneStatusProjector; EnvelopeProjector reporta `runtime_write_performed` real |
| A1-SC-0004 (fail-open promotion/completion) | ReadinessFailClosedPolicy (campo ausente ⇒ blocked + violação tipada) |
| A1-SC-0005 (220 quartets repetidos) | CertificationWorkbenchEvaluator dirigido pelo catálogo |
| A1-SC-0006 (151 Schema probes, grafos reconstruídos) | ReadinessProjectionContext + ContextProvider (probe 1x) + ChainProjector (memo) |
| A1-SC-0007 (OS overlap no owner genérico) | Famílias separadas: ProviderExecution*/ReviewMerge*/Dispatch*/Completion* fora do core Readiness |
| A1-SC-0008 (cold-lane cita path stale; scorecard autorreferente) | ReadinessStatusProjector: path real + evidência via CompletionEvidenceGateway |
| A1-SC-0009 (Section 14.169 LOC) | ReviewMergeLifecycle{Policy,Projector} + Gateway; Section deletada |
| A1-SC-0010 (3 superfícies × 109) | Scanner + compat ≤1 ciclo + owner único |
| A1-SC-0011 (*_ready ignora blockers) | ReadinessFailClosedPolicy: outer ⊇ inner; ready ⇒ blockers==0 |
| A1-SC-0012 (HumanEscalation inerte) | Renome `*Preview` (OperatorHandoffProjector); alias compat datado |
| A1-SC-0013 (template farm 110 guarantees) | Catálogo declarativo + guarantees derivadas 1x da policy |
| A1-SC-0014 (108 predecessores por chamada) | ReadinessChainProjector + memo no contexto |
| A1-SC-0015 (teste só reflexivo) | Fase 0 da migração: characterization matrix por família (pré-condição de fatia) |
| A1-SC-0016 (review/merge OS embutido) | ReviewMerge* provider-neutral fora do core readiness |
| A1-SC-0017 (Section Codex 13.784 LOC) | ProviderExecution{Policy,Projector} + CodexExecutionProvider + ProviderProcessGateway |
| A1-SC-0018 (3 superfícies × 171) | Scanner + compat ≤1 ciclo |
| A1-SC-0019 (Schema fatal — import ausente) | Fase 0 bugfix imediato; depois só ContextProvider importa Schema |
| A1-SC-0020 (hash key fantasma) | Catálogo valida produtor para toda hash key consumida (erro de construção) |
| A1-SC-0021 (bridge circular) | Catálogo acíclico por validação; direção produtor→consumidor explícita |
| A1-SC-0022 (nomes execution/final com tudo false) | ProviderExecutionPolicy decide autoridade; nomes `*Preview` para inertes |
| A1-SC-0023 (194 calls, 229 hashes, 121 probes) | Contexto imutável + ChainProjector + hash incremental |
| A1-SC-0024 (teste não executa nenhum dos 171) | Fase 0: corpus executado método a método antes da fatia 4 |
| A1-SC-0025 (OS Codex dentro de Readiness) | ProviderExecution* como família própria; readiness só projeta estado dos owners |
| A1-SC-0026..0027 (Section CodexReviewMerge god + __call) | Mesmos owners ReviewMerge* (catálogo parametrizado por provider); __call proibido |
| A1-SC-0028 (outer ready vs inner blocked) | ReadinessFailClosedPolicy |
| A1-SC-0029 (nomes desonestos sessão/decisão) | Renome `*Preview` + OperatorHandoffProjector |
| A1-SC-0030 (73% duplicação agent/codex) | Owner único ReviewMergeLifecycleProjector, provider como parâmetro |
| A1-SC-0031 (recomputação + 126MB pico) | Contexto + memo + orçamentos no ContextProvider |
| A1-SC-0032 (golden hash sem semântica) | Fase 0: matriz semântica além do corpus hash |
| A1-SC-0033 (segundo OS review/merge) | ReviewMerge* provider-neutral |
| A1-SC-0034..0043 (Batch1: god, reflection forwarding, 19/20 rotas fatais, method_exists falso, paths stale, ready hard-coded, farm, probes, test gap, OS dispatch) | Dispatch{SchedulerPolicy,StatusProjector} + ImplementationPacketEvaluator + Fase 0 (reachability) + catálogo + contexto |

## 6. Ordem de migração (characterization → fatia → alias compat → repeat)

Cada fatia: (a) characterization tests congelam schema/hash/flags/erros; (b) owner novo implementado;
(c) delegadores do compat façade + FLAG_METHOD re-apontados; (d) Section correspondente esvaziada/deletada;
(e) Scanner verifica que nenhuma rota ficou órfã.

**Fase 0 — Reachability + characterization (sem mudança estrutural)**
1. Bugfixes mínimos que destravam caracterização: import de `Schema` no AgentCodexSection (A1-SC-0019),
   hash key duplicada (A1-SC-0020), input duplicado do bridge (A1-SC-0021), stale paths do packet (A1-SC-0038).
   Sem renomear nada; testes de regressão explícitos para cada um.
2. Matriz de caracterização por família: corpus executado (não reflexivo), outer/inner status,
   blockers, hashes, espiões de mutação por `*Status` (distingue read-only/preview/command/writer).

**Fase 1 — Infra + primeira fatia concreta: Control-Plane runtime/status**
- Construir: ReadinessProjectionContext, ReadinessContextProvider, ReadinessFailClosedPolicy, ReadinessEnvelopeProjector.
- **Primeira fatia**: separar `agentControlPlane*` em ControlPlaneStatusProjector (read-only) +
  AgentControlPlaneRuntime (`run*` mutadores). Escolhida porque: (i) é o bug de segurança mais grave
  em produção (envelope mente `read_only` enquanto muta — A1-SC-0003); (ii) os mutadores reais já
  vivem fora do god (orquestrador/services ControlPlane), então a fatia é re-roteamento, não reescrita;
  (iii) já existem testes de feature (TaskLeaseRecovery, AutoReplenishment, TerminalWorkerBootstrap) como rede.
- Compat: os 238 nomes `agentControlPlane*` do service viram delegação para os 2 owners novos.

**Fase 2 — Certification workbench**
- CertificationWorkbenchEvaluator + primeira versão do catálogo (entradas de certificação).
  Mata os 220 call sites de quartet. Fail-closed aplicado aos defaults permissivos (A1-SC-0004).

**Fase 3 — Review/merge unificado**
- Catálogo `ReviewMergeTransitions` + ReviewMergeLifecycle{Policy,Projector} + Gateway.
  Migra as duas Sections (109 + 149 métodos) para um owner, provider como parâmetro.
  Corpus golden hash mantido como evidência de compat onde caracterização provar consumidor vivo.
  Deleta 26.920 LOC.

**Fase 4 — Provider execution (Codex)**
- Catálogo `ProviderExecutionTransitions` (57 triads, acíclico) + ProviderExecution{Policy,Projector} +
  CodexExecutionProvider + ProviderProcessGateway. Deleta AgentCodexSection (13.784 LOC).

**Fase 5 — Automatic dispatch**
- Dispatch{SchedulerPolicy,StatusProjector} + ImplementationPacketEvaluator; deleta Batch1/2 e o
  reflection forwarding. Capability checks no owner real.

**Fase 6 — Completion evidence + operator handoff**
- CompletionEvidenceEvaluator + OperatorHandoffProjector + CompletionEvidenceGateway; renomes honestos
  com aliases datados. Consumidores NativeImplementation re-apontados (21 arquivos, ~1 método cada).

**Fase 7 — Colapso final**
- SelfConstructionReadinessFacade (≤800) vira a única entrada nova; MotherCommand colapsa para famílias
  (≤12 entrypoints documentados; flags antigas = aliases datados); Scanner passa a gate de CI;
  remoção do compat façade e das flags datadas ao fim do ciclo; Sections deletadas;
  `find ... | awk '$1 > 2000'` vazio.

## 7. Riscos

1. **Compat de hash/corpus** — 229+119+79 hashes estáveis e o golden corpus podem depender
   de arrays embutidos que o memo/dedupe muda. Mitigação: caracterização decide *quais* hashes têm
   consumidor vivo; só esses ficam byte-compat; o resto ganha `schema_version` novo.
2. **Catálogo virar novo godfile** — dados declarativos de 3 famílias podem inflar. Mitigação: um arquivo
   de dados por família (<1.500 LOC cada) + validação estrutural no Provider; catálogo é dado, não código.
3. **228 snapshots do CommandTest** — re-apontar FLAG_METHOD pode quebrar snapshots em massa.
   Mitigação: fatia por família, snapshot diff revisado por fatia; Scanner prova equivalência flag→owner.
4. **Mutação escondida em `*Status` não mapeada** — os 16 mutadores explícitos são conhecidos, mas espiões
   da Fase 0 podem revelar mais. Mitigação: nenhuma fatia migra método sem classificação de efeito.
5. **Fail-closed muda comportamento observável** — consumidores podem depender do fail-open atual
   (promotion_allowed=true em payload malformado). Mitigação: caracterização lista consumidores desses
   campos; mudança de default entra como breaking change documentado da fatia 2, não silencioso.
6. **Disciplina do ciclo de compat** — aliases "temporários" tendem a virar permanentes. Mitigação:
   toda delegação compat carrega data de remoção; Scanner falha CI após a data.
7. **Renomes honestos vs operadores** — flags CLI renomeadas podem quebrar runbooks humanos.
   Mitigação: aliases de flag por 1 ciclo + nota de deprecação no output humano do MotherCommand.
