# Loop Brain Architecture v2 — Compreensão Onipresente (Parte 1) + Motor que Nunca Seca (Parte 2)

> Status: ARQUITETURA v2 (v1 + teardown de 18 must-fixes integrados, cada um verificado contra o código real read-only). Escopo: o CÉREBRO DO LOOP dentro do `atlas-server`.
> Princípio-mãe: o `atlas-server` é o coração; o cérebro PLANEJA (maior alavancagem), o harness IMPLEMENTA. Honestidade > verde. Anti-Goodhart pétreo.
> **Mudança estrutural v1→v2:** a v1 vendia a Parte 2 como "~90% pronto / composição fina". O teardown PROVOU isso falso em 7 pontos fatais. A v2 reclassifica honestamente o que é mecânico, o que é OBRA net-new custeada, e o que é cap model-bound — e declara, na seção 0.1, **a verdade central que o operador precisa ouvir**.

---

## 0. Tese e a CORREÇÃO de fundo da v1

O cérebro tem DUAS partes; **a Parte 1 existe PARA a Parte 2 funcionar**:

- **PARTE 1 — Compreensão Onipresente**: consciência viva e sempre-atual de um ESCOPO do `atlas-server`.
- **PARTE 2 — Motor que Nunca Seca + Dispatch Harness-Agnóstico**: o cérebro mantém a PRÓPRIA LISTA de tasks de altíssima alavancagem, sempre cheia (R1), servida por contrato genérico a QUALQUER IA com meta/loop (R2), conflict-free no nível de teste/arquivo, evolução Fibonacci.

### 0.1 A VERDADE CENTRAL (o medo do operador, respondido sem conforto)

O operador teme: *"o loop ficou mais de um mês e ainda não faz o papel dele"* e acredita que *"a Parte 2 é fácil: só não-secar + multiplataforma"*. **O teardown derruba essa premissa.** A garantia "implementado = não há como não funcionar" **NÃO se sustenta** se R1/R2 forem entendidos como encanamento. A decomposição honesta da Parte 2 é:

| Camada | O que é | Natureza | Estado |
|---|---|---|---|
| **Serving harness-agnóstico** (claimNext, envelope, contrato 2-verbos) | Não-secar como *plumbing* | Mecânico, fechável | Existe, mas com bugs de concorrência (MF-05/06/07/12/16) que SÃO obras |
| **Não-secar COM ALTÍSSIMA ALAVANCAGEM** | originar material de alto valor em código maduro | **MODEL-BOUND, núcleo irredutível** | Não fechável mecanicamente (MF-01/15) |
| **Aterrar na main limpa** | servir→implementar→mergear de verdade | **FEATURE NÃO CONSTRUÍDA + re-arquitetura do contrato de segurança** | 0% no caminho canônico (MF-03/04) |
| **Reconciliação de stacks** | 1 fonte de verdade | OBRA de consolidação sobre 4 stacks / 3 bombas / god-class de 104k linhas | Não feita (MF-08/09/13/14) |

**A frase honesta:** *"não-secar (plumbing) é fácil; não-secar COM ALTÍSSIMA ALAVANCAGEM é o núcleo model-bound inteiro, e aterrar na main de verdade é uma feature não construída."* Apresentar watermark+cascata+originador como garantia mecânica de R1 era o erro da v1. **A v2 NÃO repete isso.**

### 0.2 O que esta arquitetura corrige das críticas (herdado da v1, agora aterrado)

1. **Não reinventar — MAS o "~90% pronto" da v1 estava errado.** A compreensão e o serving-plumbing existem; a PONTE cérebro→serving (MF-09), o aterrissar-na-main (MF-03/04), o gate anti-Goodhart (MF-10) e o originador real (MF-01) são **net-new ou model-bound**. Inventário honesto na §0.1 e na Parte 4.
2. **Há ~4 pilhas de serving e 3 bombas de fila NÃO reconciliadas** (MF-08). A v1 dizia "2 stacks / 2 bombas". Realidade: Stack A (`AgentControlPlane*`, probes), Stack B (`AtlasSelfConstructionReadinessService::claimNextPacket`+`AtlasSelfConstructionReservationRepository`), **Stack C (o grind VIVO: `AtlasLoopCampaignSupervisor`→`AtlasLoopTaskGrinder`→`AtlasLoopStore`)**, Stack D (`DeliveryPipeline::claimNextProjection`); bombas: `AtlasLoopBacklogAutoFeederService`, `AgentControlPlaneTaskAutoReplenishmentService`, `AtlasLoopQueueRefiller` (a bomba VIVA). **GATE binário de reconciliação na Parte 6 antes de qualquer ARMS.**
3. **ARMS não existe** (verificado: grep vazio). É label do operador. Construído como ponte injetável, **não** "troca-se o `sources()`" (que é método PRIVATE numa god-class — MF-09).
4. **Research vivo é VAPOR** (MF-01 confirma: nenhum caller passa `$backend`; `AtlasEvolutionTaskGenerator:372` só passa um flag `searchToolAvailable`; `AtlasLoopExternalResearchService:83` retorna `no_research_backend (fail-closed)`). ADVISORY-OFF.
5. **R1/R2 NÃO são invariantes pré-existentes** — propostos NOVOS (I-17/I-18), medidos.
6. **`level` nunca vira número** — herda "FACTS not scalar" de `AtlasLoopScopeComprehensionModel`.

---

## PARTE 1 — Compreensão Onipresente do Escopo

(Inalterada em substância — a v1 já era honesta aqui. Resumo aterrado.)

### 1.1 Diagnóstico
- `AtlasLoopScopeComprehensionModel.php` — value-object DESCRITIVO, invariante **emite SETS/FIELDS, NUNCA scalar/rank**.
- `...Builder.php` — determinístico (mesmo snapshot ⇒ byte-idêntico).
- Quebras: efêmero (sem persistência), sem dimensão runtime, sem nível-por-unidade (`CapabilityTrendService` mede slope AGREGADO).

### 1.2 Decisão de escopo (dois degraus)
- **P1-A:** parar de chamar `build()` 3×/refill — UMA instância memoizada por `snapshotId`. ~27s→~9s. Zero migration.
- **P1-B (diferido):** read-model JSON único por `snapshotId`, edges re-derivados por full-grep (não invalidação por-arquivo).

### 1.3 Runtime — sinais grátis primeiro
- Agora: `has_gate_block` (de `AtlasLoopCoverageGapDetector`) + `last_merge_clean` (de `merged_to_main`).
- Futuro: line-coverage real (sub-fatia custeada).

### 1.4 Nível = VETOR DE FATOS (pétreo)
`level_vector = {has_test, gate_clean, orphan, in_clone, doc_gap_open, last_merge_clean}`. `transitionsFor(unit)` devolve transições NOMEADAS via mapa fixo. Substrato diz QUAIS transições existem; QUAL vale mais é julgamento da Parte 2 (WRITER≠JUDGE). **NOTA v2:** essa separação é o ponto onde o LeverageScorer entra — e onde mora o gap MF-11 (strategic_impact sem produtor wired). Ver 2.5.

### 1.5 Contrato de consulta
```php
interface ScopeComprehensionQuery {
  public function model(string $scopeRoot): AtlasLoopScopeComprehensionModel;
  public function transitionsFor(string $fqcn): array;
  public function staleness(string $scopeRoot): array;
}
```

---

## PARTE 2 — Motor que Nunca Seca + Dispatch Harness-Agnóstico

### 2.1 Os dois mundos e a decisão de pilha (CORRIGIDA — MF-08)

- **Mundo A — CÉREBRO** (`AutonomousEvolution/`): comprehension → originate (`AtlasLoopOriginationPipeline`, writer≠judge) → leverage (`AtlasLoopLeverageScorer`) → ambition (`AtlasLoopAmbitionDecider`) → trend (`AtlasLoopCapabilityTrendService`).
- **Mundo B — SERVING** (`SelfConstruction/`): `AgentControlPlaneTaskQueueOrchestrator::claimNext` + `AgentControlPlaneTaskAutoReplenishmentService` + `AgentControlPlaneClaimLeaseRepository`.

**A ponte é a Parte 2.** `grep LeverageScorer|AmbitionDecider|OriginationPipeline|ScopeComprehensionModel em SelfConstruction/` → VAZIO (confirmado, exit 1). **A fronteira cérebro↔serving tem ZERO acoplamento.**

**INVENTÁRIO REAL DE STACKS (MF-08, substitui o "2 stacks" da v1):**

| Stack | Front-door | Quem consome | Bomba associada |
|---|---|---|---|
| **A** `AgentControlPlane*` | `claimNext(agentId, filters)` | **só probes** (`TerminalWorkerBootstrap`, `MultiAgentLoopCertification`, `TerminalLoopOperationalProof`) — NÃO o grind | `AutoReplenishment` (`sources()` self-bootstrap) |
| **B** `AtlasSelfConstructionReadinessService` | `claimNextPacket` + `ReservationRepository` (CLI-wired, flock) | CLI `atlas:ai:self-construction` | — |
| **C (VIVO)** `AtlasLoopStore` | `claimNextTask` via `AtlasLoopCampaignSupervisor`→`AtlasLoopTaskGrinder` | **o grind que de fato roda** | **`AtlasLoopQueueRefiller` (a bomba viva)** |
| **D** `DeliveryPipeline` | `claimNextProjection` | pipeline de entrega | — |

**Decisão de pilha v2 (corrige a v1 que canonizava A — a stack que o grind NÃO usa):**
- A canonização é precedida por um **GATE BINÁRIO de reconciliação** (Parte 6, passo 3): `grep` de callers prova **exatamente 1 stack viva + 1 bomba viva** ANTES de liberar ARMS/contrato. Sem esse gate, construir sobre A cria a 5ª divergência ao lado de C/D vivas.
- **ARMS DELEGA à `QueueRefiller`** (orphan/clone/decompose/coverage-cap já provados e vivos) ou a aposenta explicitamente — NÃO reimplementa supply-lanes dentro de `sources()`. (Disposição MF-08/MF-09.)

### 2.2 ARMS — a ponte injetável (NÃO "troca-se o sources()" — MF-09)

**CORREÇÃO v1:** a v1 dizia "ARMS = trocar o `sources()` do AutoReplenishment / ~90% pronto". Verificado: `sources()` (`AutoReplenishmentService:414`) é **PRIVATE**, retorna **6 fontes SelfConstruction-bound hardcoded** (`terminal_bootstrap_probe`, `current_pointer`, `not_yet_runtime_capable`, `completion_audit_failed_criteria`, `chain_integrity_violations`, `canonical_contract`) e `plan()` fabrica N seeds near-dup num for-loop para bater `target_min_claimable`. **Nenhuma fonte é conceito de cérebro/leverage.** "Trocar `sources()`" = reescrever corpo privado de god-class + criar a 1ª dependência cross-namespace + traduzir candidatos-de-leverage em task_packets. **Isso é a OBRA CENTRAL net-new, não composição fina.**

**Desenho v2:**
1. **Extrair `sources()` para uma interface injetável `SourceProvider[]`** (refactor com teste de caracterização) ANTES de plugar o cérebro. As 6 fontes atuais viram 6 `SourceProvider` concretos (comportamento preservado byte-a-byte).
2. **`BrainLeverageSourceProvider`** — o adapter net-new candidate-de-leverage→task_packet (allowed_files/acceptance FROZEN), alimentado por `OriginationPipeline`/`LeverageScorer`/`Trend`.
3. **Prova de valor obrigatória (anti-Goodhart):** métrica **leverage-admitida-por-tick** medida contra a baseline atual (~0 evolução real do atlas-server; 100% self-scaffolding ACP). Se o adapter não eleva essa métrica acima da baseline, ele NÃO é canonizado.

O laço (acionado por watermark + evento de entrega):
```
0. SCOPE READ      ← ScopeComprehensionQuery.model(scopeRoot)        [Parte 1, memoizada]
1. AMBITION SCALE  ← CapabilityTrendService.trend() → riskTolerance  [Fibonacci, ver 2.6]
2. RESEARCH        ← ExternalResearchService.research(topic)         [ADVISORY-OFF, fail-closed — R-3]
3. ORIGINATE       ← OriginationPipeline.produce(model)              [writer≠judge, design-gated]
                     + DELEGA QueueRefiller supply-lanes (decompose/dedup/orphan/doc-gap)
4. LEVERAGE+AMBITION RANK ← LeverageScorer + AmbitionDecider
5. ADMISSION GATE  ← LeverageAdmissionGate.admit(candidate, floor)   [NET-NEW NÃO PROVADO, ver 2.5]
6. MINT            ← SourceProvider → claimNext-ready packet          [conflict-free na MINTAGEM, ver 2.4]
```

### 2.3 R1 — A FILA NUNCA SECA (RECLASSIFICADO — MF-01/02/18)

**A v2 NÃO apresenta R1 como garantia mecânica.** Verificado:
- `OriginationPipeline`/`Originator` alimentam-se de `model→orphans/cloneClusters/docStatedGaps` (sets FINITOS). `QueueRefiller` confirma as lanes (orphan/clone/decompose/coverage) e auto-documenta que coverage/characterization = **PROXY proibido**.
- Memórias `loop-cannot-deliver-on-own-mature-code` e `loop-material-fuel-gap` provam 2× ao vivo: ~22 orphans e acaba; MiniMax 0 proposta em 15-20min em código maduro.
- **Config default ABSTÉM** (`OriginationPipeline:79`): `proceed_on_grounded_novelty_enabled` default FALSE (`config/atlas.php:2910`) ⇒ `novel && !has_precedent ⇒ park-and-ask`. Comentário no código: *"Default OFF ⇒ byte-identical (novelty parks-and-asks)"*. `leverage_first_origination_enabled` default FALSE (`:2914`).
- **O escape territory-ladder é operator-seeded** (`territory_ladder_rungs` default `[]` `:2941`; `territory_widened_roots_drive_refill` default FALSE `:2945`) — **viola `loop-never-recommend-human-seeded-frontier`** se vendido como modo normal.
- **Deadlock Fibonacci (MF-18):** `canPromote` exige `compounding_trend_up==true`; o trend lê `merged_to_main`; quando o reativo seca → sem entregas → slope flat → `compounding_trend_not_up` → promoção BLOQUEADA. *A condição que exige alargar é a condição que impede alargar.*

**DECISÃO v2 (disposição MF-01):** R1 é **NÃO-FECHÁVEL mecanicamente neste escopo (código maduro)**. A arquitetura adota:
- **(a) Modo normal honesto:** quando o reativo seca, `no_claimable_task` + `escalation: needs_brain_origination` é uma **RESPOSTA HONESTA e o estado esperado**, não placeholder fabricado nem "piso raro". O watermark+cascata+originador deixam de ser apresentados como garantia.
- **(b) Caminho de combustível material real:** apontar o loop para um **escopo com bugs/material reais externos ao próprio código maduro** (a tese `loop-material-fuel-gap`: 24h-autônomo-material exige SEED de fuel real). Isto é decisão de escopo do operador, não automação que finge.
- **Config armada assumida documentada (MF-02):** a Sequência de Construção (Parte 6) declara que, na config DEFAULT, o cérebro **EMITE UMA PERGUNTA, não um packet claimável**. "Fila auto-cheia 24/7" só vale sob config armada **E** material real disponível — e mesmo armada recai no pool finito (MF-01). O doc prova o comportamento sob a config armada explicitamente, em vez de assumir fluxo contínuo.

**Sentinela R1 (`QueueFillSentinel`)** — heartbeat JSONL (padrão `AtlasSchedulerHealthService`): mede `claimable_depth`, `seconds_below_min`; dispara `silent_alarm` ao secar. **É DETECTOR, não garantia.**

### 2.4 R2 — O SERVING NUNCA FALHA + Conflict-Free (ENDURECIDO — MF-05/06/07/12/16/17)

A v1 tratava os furos de concorrência como "must-fix de wire". O teardown prova que são OBRAS. R2 = **entregar-exatamente-uma-vez sob N clientes**, não "claimNext sempre responde".

**(1) Lock atômico fail-CLOSED (MF-06 — FATAL).** Verificado: `withLock` (`AgentControlPlaneClaimLeaseRepository:786-815`) é `Storage::exists/put` check-then-act, com **break-no-timeout-de-4s que cai no callback executando a mutação SEM lock**, e `finally` que **deleta o `.lock` incondicionalmente** (sem checar dono), sem stale-detection. Contrasta com `AtlasLoopMergeActuator:50-90` (`fopen`+`flock(LOCK_EX|LOCK_NB)` real, fail-CLOSED).
- **FIX (obra):** trocar por `flock(LOCK_EX)` real igual ao merge actuator. Fail-CLOSED no timeout (abortar o claim, **NUNCA** rodar callback unlocked). `finally` só libera o lock que ESTE processo adquiriu (handle próprio). Stale-detection por token+timestamp.

**(2) Selação-e-claim atômicos / fim do TOCTOU (MF-16).** Verificado: `claimNext` (`Orchestrator:108-151`) faz `queue->list('claimable')` SEM lock (114), itera, chama `leases->claim` por candidato (126), `updateStatus` FORA do lease-lock (130). Lock degradado ⇒ double-claim do MESMO packet ⇒ responder `claimed` a DOIS clientes (pior que falhar).
- **FIX (obra):** mover seleção+reserva para UMA seção crítica do lease repo (claim recebe o filtro, escolhe+reserva sob o mesmo `flock`) OU compare-and-swap no queue record (`claimable→claimed` condicional). Parar de usar "claimNext sempre responde" como prova de R2.

**(3) Reaper de dead-agent AGENDADO + reabilitação atômica (MF-05 — FATAL).** Verificado: `expireLeasesInternal:490-545` só muda `lease_status='expired'` no registry de leases — **zero referência a queue/claimable/updateStatus**. O queue record permanece `'claimed'` indefinidamente; `claimNext` só lista `'claimable'`. Única volta = `LeaseRecoveryService` MANUAL; **nenhum scheduler o invoca** (`grep lease-recovery routes/console.php` → exit 1). TTL 1800s-14400s, sem heartbeat de liveness.
- **FIX (obra):** (i) **reaper AGENDADO** (`Schedule::command`) que rode `expireLeases` E faça `claimed→claimable` pros leases expirados; (ii) `expireLeasesInternal` (ou coordenador) muta o QUEUE record, não só o lease registry; (iii) `claimNext` varre leases expirados e reabilita a task ANTES de listar claimable; (iv) wire heartbeat de `AgentRuntimeRegistry` (TTL 60s) ao lease (TTL 1800s) para early-release. **Sentinela R2 fica DETECTOR; a GARANTIA vem do reaper+reabilitação atômica.**

**(4) Conflito read-write (MF-07).** Verificado: `detectWriteOverlap:570-590` usa `array_intersect($writeSet, $existingWriteSet)` — write-vs-write APENAS; `$readSet` é capturado (`:82`) mas **nunca usado**. Lanes com write_sets disjuntos compartilham `scope_in='app/Services/Ai/SelfConstruction/'`; a colisão lógica (A escreve o que B lê) passa verde; o teste de B foi provado contra a main ANTES da edição de A.
- **FIX:** detecção considera **write_set(A) vs read_set(B) prefix-aware**, não só write-vs-write. Enquanto não houver isolamento por-worktree: **serializar tasks que compartilham qualquer prefixo de subsistema**.

**(5) Prefix-aware em TODOS os ~10 chokepoints (MF-12).** Verificado: `array_intersect` em **33 arquivos** de SelfConstruction; os chokepoints conflict-free incluem `ClaimLeaseSimulator`, `ScopeLockPlanner`, `AgentDispatchPlannerScopeConflictAnalyzer`, `MultiAgentLoopCertification`, `ScopeLockRuntimeValidator`, `ReservationRepository`, `ReadinessService`, `TaskPacketBuilder`. Corrigir só `detectWriteOverlap` deixa os outros vulneráveis ao mesmo dir-vs-file. Assimetria adicional: a enforcement de escopo na conclusão (Orchestrator) usa `array_diff` exact-match ⇒ seeds com `allowed_files=bare-dir` não concluem (`files_changed_outside_allowed_scope`).
- **FIX:** centralizar o predicado num helper único `WriteSetOverlap::conflicts` usado por overlap **E** enforcement de escopo; substituir TODOS os ~10 call-sites com **teste-guard anti-regressão que enumera os chokepoints**; parar de emitir bare-dirs em `allowed_files` (só em `scope_in`). **NÃO vender como "1 arquivo / 15 linhas".**

**(6) Lock de merge single-host (MF-17).** Verificado: `flock` sobre `<repoRoot>/.git/atlas-main-merge.lock` serializa só processos do MESMO host. Para o modelo realista (Atlas local no MacBook, providers como motor no mesmo Mac) o risco é menor, mas o doc não pode vender harness-agnóstico cross-cliente sem qualificar single-host.
- **MODELO CORRETO:** o **CLIENTE entrega patch/branch; o SERVIDOR (único, local) aplica sob o lock**. Cliente externo NUNCA segura o lock de merge. Qualificado no contrato (2.7/2.8).

**Conflict-free na MINTAGEM** (não só no claim): o `LeverageAdmissionGate` checa overlap prefix-aware (write vs write **e** write vs read) contra a fila claimable INTEIRA antes do enqueue. **CAVEAT (MF-06):** isso só vale se a mintagem rodar dentro de uma seção crítica REAL — com o lock furado da v1, a seção "crítica" não é crítica. Depende de (1) acima estar feito.

### 2.5 Anti-Goodhart — o Leverage-Admission Gate (NET-NEW NÃO PROVADO — MF-10/11)

**CORREÇÃO v1 (FATAL):** a v1 dizia "decisão travada no design, não open-problem" e comparava o floor-por-marco com `canPromote`. Verificado:
- `grep LeverageAdmissionGate|QueueFillSentinel|ServingSlaSentinel|ARMS em app/+tests` → **VAZIO**. 100% aspiracional.
- `canPromote` (`AtlasLoopTerritoryLadder:44-101`) decide **PROMOÇÃO DE ESCOPO** (climbar pra root novo, com invariante de frozen-safety-file + `certified_leaps≥K` + `compounding_trend_up`), **NÃO levanta floor de admissão de task individual**. A analogia da v1 conflaciona dois mecanismos distintos. **A reutilização de `canPromote` é INVÁLIDA.**

**DECISÃO v2 (disposição MF-10):** `LeverageAdmissionGate` + floor-por-marco são **NET-NEW NÃO PROVADOS e o item de maior risco anti-Goodhart da Parte 2**. Precisa de mecanismo próprio com prova adversarial dedicada (não "travado no design"). O floor-por-marco sobe por K-leaps certificados sob frozen judge — mas como **mecanismo novo**, não herdando `canPromote`.

**O proxy escondido do LeverageScorer (MF-11):** verificado: `AtlasLoopLeverageScorer.score()` usa `strategic_impact` default `STRATEGIC_DEFAULT=0.30` quando *"the brain has NO signal"* (`:46`,`:74-77`); docstring: *"gathering the brain signals is the producer's job"*. **Esse producer NÃO está wired** (cérebro não toca o serving — grep vazio). Sem `strategic_impact`, `leverage = breadth × compounding / (cost × risk)` = **caller-count + ciclomática**. `producer_leverage_floor` default 0.2 (`config:2228`) é barra baixa. ⇒ mesmo com o AdmissionGate construído, o "floor de leverage" filtra por **proxy de débito estrutural** — o exato Goodhart que o doc jura evitar (fila ranqueada por hub-grande, não por valor real).
- **FIX (disposição MF-11):** especificar de ONDE `strategic_impact` vem para um candidato autônomo (do `ScopeComprehensionModel` + goals/reality do cérebro, via `BrainLeverageSourceProvider`) e PROVAR que não cai no 0.30. **Sem essa fonte wired, declarar como GAP, não como anti-Goodhart resolvido.**

Regras pétreas que JÁ existem e ficam: material-or-reject; grounded-or-reject (`GroundingGate`); test-files fora do candidate-set = PROXY proibido (`QueueRefiller:352`). Métrica de saúde = **leverage-admitida-por-tick**, não tasks-por-tick.

### 2.6 Evolução Fibonacci (CORRIGIDA — MF-18)

A escala é de RETORNO/alavancagem. Mecânica:
- **Medição:** `CapabilityTrendService.slope` sobre `merged_to_main`. Verificado: lê só DB, sem write no caminho de merge; comentário no código: *"1 non-empty bucket ⇒ slope 0 ⇒ capability signal is dead"*.
- **Composição:** mergear limpo → (a) comprehension vê o símbolo wired (breadth↑); (b) slope↑ → riskTolerance permite saltos maiores; (c) K-leaps → floor sobe.

> **NOTA HONESTA v2 (MF-18 — o deadlock):** o Fibonacci **não fecha** hoje. (i) `recordOutcome` NÃO está wired ao trend (verificado: `CapabilityTrendService` só READS DB; `recordOutcome` vive em `AtlasOpenBrainWriteBackService`/`MissionDeliveryOrchestrator` sem tocar `merged_to_main`). (ii) Sem merge real (MF-03), `merged_to_main` nunca sobe → slope plateaua → sem sinal de entrada. (iii) O escape territory-ladder deadlocka exatamente quando seria preciso (slope-flat). **FIX:** wirar `recordOutcome` ao trend; reconhecer que o escape autônomo de R1 precisa de gatilho que NÃO dependa de slope positivo (que some quando o reativo seca) e que NÃO seja rung semeada pelo operador. **Sem originador de material real, R1 não tem escape autônomo** — dizer isso, em vez de listar territory-ladder como mitigação viável.

### 2.7 O contrato de cliente GENÉRICO (harness-agnóstico, single-host qualificado — MF-17)

**Atlas mantém a PRÓPRIA LISTA. O harness IMPLEMENTA; o cérebro PLANEJA.** Superfície mínima: UMA transport (MCP) + CLI, 2 verbos.
```
next   = atlas:task next   --client=<opaque-id> --json     (MCP: atlas_next_task)
report = atlas:task report --task=.. --lease=.. --evidence=- --json   (MCP: atlas_task_report)
```
`<opaque-id>` é o único parâmetro. O servidor NUNCA ramifica em plataforma — **a assinatura de tipo É a prova** (contrato aceita só `client_id:string` opaco, schema fixo sem branch engine-typed; teste que REJEITA campo engine-typed). Zero hardcode.

**Ciclo do cliente (MODELO CLIENTE-ENTREGA-PATCH / SERVIDOR-MERGEIA — MF-17):**
```
PULL  → atlas:task next  (TaskEnvelope: objective, allowed_files, acceptance FROZEN, context embutido)
WORK  → implementa SÓ dentro de allowed_files
PROVE → roda gates de required_evidence (verde honesto; reporta fail)
SUBMIT→ produz PATCH/BRANCH e o ENTREGA via report (NÃO segura lock de merge)
        ↓
[SERVIDOR único, local, host do repo] aplica o patch sob atlas-main-merge.lock com verificação-antes-do-commit
        ↓
REPORT→ atlas:task report → completeReal() (ver 2.8) → recordOutcome ingere na Parte 1 E no trend (MF-18)
NEXT  → PULL de novo
```
**Qualificação (MF-17):** main-limpa cross-cliente assume **servidor-de-merge único no host do repo**. O cliente externo/distribuído NUNCA coordena o `flock` local — ele entrega artefato; o servidor serializa. Para o modelo Atlas-local (providers como motor no mesmo Mac), isso é natural.

### 2.8 Como N agentes aterrizam na MAIN — completeReal() (FEATURE NÃO CONSTRUÍDA — MF-03/04)

**CORREÇÃO v1 (FATAL):** a v1 chamava a ponte serving→merge de "~reuso / advisory off / decisão do operador no passo 9". Verificado: é **net-new pesado + re-arquitetura do contrato de segurança**.

**MF-03 — o elo está fisicamente cortado:** `grep MergeActuator|CycleGitContract|mergeToMain|mergeOneCritical em SelfConstruction/` → **VAZIO (exit 1)**. O Orchestrator (Stack A) termina em `completeDryRun` com `completion_real_allowed=false` (51 ocorrências de `completion_real_allowed=false`). A única máquina de merge real (`AtlasLoopMergeActuator.withMainMergeLock` + `AtlasLoopCycleGitContract`) vive em `AutonomousEvolution` e é usada pelo supervisor single-agent, **NÃO pelo `claimNext`**. **O passo MERGE do ciclo do cliente (v1 §2.7) NÃO existe na stack canônica.** Resultado em 30 dias: cliente puxa, implementa, reporta, recebe `complete_dry_run`, ZERO código na main.

**MF-04 — o gate certifica NÃO-execução:** verificado: `AgentControlPlaneRuntimePilotCertificationService` existe, mas TODOS os checks só PASSAM com `runtime_execution_allowed=false`, `dispatch_allowed=false`, `provider_call_allowed=false`, `token_spend_allowed=false`, `completion_claim_allowed=false`, `dry_run_only=true` (`:114-120`,`:196-201`). `AtlasSelfConstructionOsCompletionAuditService:829` trata `completion_real_allowed=true` como violação `'completion_real_allowed_true'`. **Logo "flip de flag ON → serve real" da v1 NÃO existe: virar as flags QUEBRA a certificação E dispara violação no audit.**

**DESENHO v2 (disposições MF-03 + MF-04):**
1. **`completeReal()` na stack canônica** (net-new): sob o lock compartilhado `atlas-main-merge.lock`, invoca `AtlasLoopCycleGitContract.mergeToMain` com verificação-antes-do-commit (re-prova, apply-check, php-lint, boot-smoke, canário do teste-irmão, broader-regression — qualquer vermelho desfaz o apply, main intocada). Aplica o patch ENTREGUE pelo cliente (MF-17), não o cliente segurando o lock.
2. **Re-arquitetura do contrato de segurança** (obra de SEMANAS, não decisão binária): inverter a semântica da `RuntimePilotCertification` (de "prova não-execução" para "prova execução-governada") **E** reescrever o `CompletionAudit` que marca real-completion como violação, **com nova prova adversarial do floor pétreo** (`AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS` intacto; réu nunca edita o juiz). Estimar como obra própria custeada, **não passo 9 trivial do operador**.

**Recursive-safety pétreo (inalterado):** toda entrega cujo write-set toca alvo proibido → operator-key, jamais auto-merge.

---

## PARTE 3 — Qualidade, Segurança e Governança (moat genérico)

Desacopla o moat single-agent com interface fina:
```php
interface DeliverySource { changedFiles(): array; diff(): string; baseCommit(): string; acceptance(): array; }
```
`AtlasLoopProposal` vira UM adapter. A escada existe: `VerificationDepthPolicy::obligationsFor`. Net-new: `CrossModelPanelRunner` (1 classe, alimenta `AtlasLoopJudgeConsensusGate` existente).

**Multi-arquivo é furo central (MF-15 — compõe com R-1):** verificado: `AtlasLoopProposalOutOfProcessVerifier` reconstrói/escreve/restaura **1 filename por vez** (`targetFilename` único, `:169`,`:232`,`:242`,`:272`). A ÚNICA lane material em código maduro (orphan-wiring) é **multi-file** (provado por `loop-cannot-deliver-on-own-mature-code`). ⇒ mesmo fechando o originador, o serving não certifica o material. **R-1 e R-4 se compõem:** o gargalo não é o encanamento. Envelope começa single-file/small-N; multi-file real é **obra própria custeada**, declarada (não escondida).

---

## PARTE 4 — Reaproveita vs Net-New vs Model-Bound (HONESTO — v2)

### Reaproveita (verificado)
`AtlasLoopScopeComprehensionModel`(+Builder), `AgentControlPlaneTaskQueueOrchestrator`, `AgentControlPlaneTaskAutoReplenishmentService`, `AgentControlPlaneClaimLeaseRepository`, `AtlasLoopOriginationPipeline`/`Originator`/`GroundingGate`, `AtlasLoopLeverageScorer`/`AmbitionDecider`/`CapabilityTrendService`, `AtlasLoopMergeActuator`(+`CycleGitContract`), `AtlasEvolutionFrozenJudge`, `VerificationDepthPolicy`, `AtlasLoopHarnessGuard`, `AiProviderManager`, `AtlasOpenBrainContextPackService`, `AtlasOpenBrainWriteBackService`, `AtlasSchedulerHealthService`, `AtlasLoopMasterSwitch`, **`AtlasLoopQueueRefiller` (bomba viva — DELEGAR, não reimplementar)**.

### Net-New OBRA (não "composição fina")
- **`completeReal()` + re-arquitetura do contrato de segurança** (MF-03/04 — semanas).
- **`flock` real no claim/lease + reaper agendado + reabilitação atômica de queue** (MF-05/06/16 — obras).
- **`SourceProvider[]` injetável + `BrainLeverageSourceProvider`** (MF-09 — a ponte central, com prova de leverage-admitida-por-tick).
- **`WriteSetOverlap::conflicts` helper único prefix-aware + read-write detection, em ~10 chokepoints com guard anti-regressão** (MF-07/12).
- **`LeverageAdmissionGate` + floor-por-marco (mecanismo PRÓPRIO, prova adversarial)** + fonte de `strategic_impact` wired (MF-10/11).
- **Wire `recordOutcome`→trend** (MF-18).
- **Gating do `MasterSwitch` na stack canônica** (MF-14 — ver abaixo).
- `ScopeComprehensionQuery` + memoização (P1-A); `CrossModelPanelRunner`; `DeliverySource` + adapter; `atlas:task next|report` + MCP; `QueueFillSentinel`/`ServingSlaSentinel` (DETECTORES); invariantes I-17/I-18; extração de `claimNextPacket`/reservation da god-class (MF-13).

### Cap model-bound (não fechável mecanicamente)
- **R-1: originador de material real de altíssima alavancagem em código maduro** (MF-01). Mitigação: abstain-and-ask honesto como modo normal + escopo com material real.
- **R-4: verifier multi-arquivo** (MF-15). Mitigação: envelope single-file/small-N; multi-file = obra custeada.
- **R-6: correção semântica greenfield / captura de intenção** (frozen judge prova teste passa, não intenção). Mitigação: painel cross-model + operator-key.

### Risco de fragmentação subestimado (MF-13/14)
- `SelfConstruction` = **310 arquivos**; `AtlasSelfConstructionReadinessService.php` = **104.222 linhas**, com `claimNextPacket` (Stack B) + reservas embutidas. "Congelar e migrar Stack B" atravessa esse monstro ⇒ **extrair `claimNextPacket`/reservation para serviço nomeado com testes de caracterização ANTES**, como fatia de risco própria com snapshot-cert.
- **`MasterSwitch` não alcança a stack canônica** (`grep AtlasLoopMasterSwitch em SelfConstruction/` → vazio). "OFF=byte-identical no-op" é FALSO para Stack A/AutoReplenishment/Stack C. ⇒ gating do MasterSwitch é parte da reconciliação (passo 3), com teste OFF=no-op no caminho de claim/replenish REAL.

---

## PARTE 5 — Modelo de Dados / Contratos

**TaskEnvelope** (`atlas.task_serving.envelope.v1`): projeção do `task_packet`, com `client_id` ecoado (nunca interpretado), `base_head_sha` advisory, `retry_after_seconds`, `escalation: null|needs_brain_origination`. **v2:** sem flags-fantasma; promoção real gateada fora do envelope, mas agora explicitamente via `completeReal()` (2.8), não "decisão do operador".

**Report** (`atlas.task_serving.report.v1`): reusa `validateCompletionEvidence` 1:1 (`hash_equals` anti-forja, escopo em `allowed_files`, non-verde rejeitado). **v2:** o report carrega o PATCH/branch (MF-17), e o SERVIDOR aplica.

**Comprehension read-model (P1-B, diferido):** `{snapshot_id PK, scope_root, model_json, built_at}` — sem score/level/rank; `level_vector` json de booleanos.

**Mapa fato→transição:** tabela PÉTREA versionada por schema.

---

## PARTE 6 — Sequência de Construção (GATE binário de reconciliação primeiro)

> **v2:** a ordem foi reescrita porque a v1 assentava ARMS+contrato sobre 4 stacks/3 bombas não reconciliadas (MF-08), god-class de 104k (MF-13) e MasterSwitch que não alcança a stack (MF-14). Sem gate bloqueante, constrói-se a 5ª divergência.

1. **P1-A — Compreensão barata:** memoizar `build()` por `snapshotId` (27s→9s). Zero migration.
2. **Concorrência fail-CLOSED (obra):** `flock` real no `withLock` (MF-06) + claim atômico/anti-TOCTOU (MF-16) + reaper agendado + reabilitação de queue (MF-05). Com guard anti-regressão.
3. **GATE BINÁRIO DE RECONCILIAÇÃO (bloqueante):** inventariar A/B/C/D + 3 bombas; extrair `claimNextPacket`/reservation da god-class (MF-13); decidir destino das vivas (C/D/QueueRefiller); **provar por `grep` de callers: exatamente 1 stack viva + 1 bomba viva**; gating do `MasterSwitch` na stack escolhida com teste OFF=no-op no caminho REAL (MF-14). Conflict-free centralizado em `WriteSetOverlap::conflicts` nos ~10 chokepoints + read-write detection (MF-07/12). **Sem este gate verde, NADA abaixo é liberado.**
4. **ARMS = ponte injetável (obra central):** extrair `sources()`→`SourceProvider[]`; `BrainLeverageSourceProvider` (cérebro→packet) com prova de leverage-admitida-por-tick > baseline (MF-09). `LeverageAdmissionGate` + floor-por-marco como mecanismo PRÓPRIO com prova adversarial + fonte de `strategic_impact` wired (MF-10/11).
5. **Contrato genérico:** `atlas:task next|report` + MCP, schema fixo, single-host qualificado (MF-17). Modelo cliente-entrega-patch / servidor-mergeia.
6. **Sentinels R1/R2 (DETECTORES) + invariantes I-17/I-18.** Wire `recordOutcome`→trend (MF-18).
7. **Moat genérico:** `DeliverySource` + adapter; `CrossModelPanelRunner`. Single-file primeiro.
8. **P1-B + nível/transições + runtime grátis:** só com consumidor da série temporal.
9. **`completeReal()` + re-arquitetura do contrato de segurança (OBRA de semanas, custeada):** inverter semântica da `RuntimePilotCertification` + reescrever `CompletionAudit`, nova prova adversarial do floor pétreo (MF-03/04). **NÃO é decisão binária do operador.**
10. **(Decisão de escopo do operador) Combustível material real:** apontar o loop para escopo com bugs/material reais (MF-01) — porque R1 não tem escape autônomo em código maduro.

---

## PARTE 7 — Problemas Difíceis / Riscos (honesto, sem vapor)

- **R-1 (model-bound, o mais duro):** R1 só completo com originador de material real; em código maduro o reativo seca (provado 2×). Abstain-and-ask honesto é o **modo normal**, não fraqueza. Watermark+cascata+originador **não são garantia mecânica** (MF-01). Config default ABSTÉM (MF-02). Escape territory-ladder deadlocka slope-flat e é operator-seeded (MF-18).
- **R-2 (Goodhart no floor):** `LeverageAdmissionGate`+floor-por-marco **não existem** (MF-10) e a analogia `canPromote` é inválida. `LeverageScorer` colapsa para proxy caller-count+ciclomática (`strategic_impact` default 0.30, producer não wired — MF-11). NET-NEW NÃO PROVADO, maior risco anti-Goodhart.
- **R-3 (research = VAPOR):** nenhum caller passa `$backend`; fail-closes (MF-01). ADVISORY-OFF até backend Hermes-web wired (egress filter + cost-governor).
- **R-4 (multi-arquivo):** verifier single-file (MF-15). Compõe com R-1. Envelope começa single-file/small-N; multi-file = obra custeada.
- **R-5 (merge real cross-cliente):** **FEATURE NÃO CONSTRUÍDA**, não "flag off". Elo serving→merge cortado (MF-03); destravar = re-arquitetura do contrato de segurança (MF-04). Obra de semanas.
- **R-6 (semântica greenfield):** frozen judge prova teste passa, não intenção. Painel cross-model + operator-key.
- **R-7 (custo 24/7):** cost-governor + cadência por watermark.
- **R-8 (merge serial single-host):** lock único serializa; cliente entrega patch, servidor único mergeia (MF-17). Aceitável para N pequeno / Atlas-local.
- **R-9 (concorrência sob N clientes — NOVO):** lock fail-open não-atômico (MF-06) + TOCTOU (MF-16) + read-write não detectado (MF-07) + dir-vs-file em ~10 chokepoints (MF-12) + dead-agent strand sem reaper (MF-05). "Conflict-free no nível de teste/arquivo" da v1 é **falso até as obras do passo 2-3**.
- **R-10 (fragmentação bloqueia a própria construção — NOVO):** 4 stacks / 3 bombas (MF-08), god-class 104k (MF-13), MasterSwitch fora da stack (MF-14). Sem gate de reconciliação, constrói-se a 5ª divergência.

**Anti-Goodhart (resumo pétreo):** nível = vetor de fatos, nunca scalar; WRITER≠JUDGE; `leverage-admitida-por-tick` (não tasks-por-tick); test-files fora do candidate-set = PROXY proibido; report prova estruturalmente; runtime lê FATO; `no_claimable_task` honesto; floor sobe por marco (mecanismo PRÓPRIO, não `canPromote`). **A v2 acrescenta:** nenhum dos 7 fatais é "passo da lista de 9"; cada um é OBRA custeada ou cap model-bound nomeado. Honestidade > verde em todo ponto.