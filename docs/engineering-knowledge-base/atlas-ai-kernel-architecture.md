---
id: atlas-ai-kernel-architecture
type: engineering_knowledge
title: Atlas AI Kernel Architecture - The Mother Specification
status: active
category: architecture
priority: 100
summary: Especificacao kernel formal e enterprise do Atlas AI. Define contratos tipados, maquinas de estado, evidence ledger event-sourced, capability registry executavel, SDKs de dominio/surface/provider, SLOs operacionais, taxonomia de falha, versionamento, multi-tenancy e doutrina como teste arquitetural. Esta e a arquitetura mae do Atlas AI; topology docs (vision, pipeline, core-vs-domain, operating-system, architecture-audit) sao especializacoes desta especificacao.
tags:
  - atlas-ai
  - kernel
  - architecture
  - mother-spec
  - typed-contracts
  - event-sourcing
  - capability-registry
  - domain-sdk
  - surface-sdk
  - provider-sdk
  - slo
  - multi-tenancy
capabilities:
  - kernel_specification
  - operation_envelope_contract
  - decision_receipt_v2_contract
  - evidence_ledger_event_sourcing
  - capability_registry_enforcement
  - domain_manifest_sdk
  - surface_adapter_contract
  - provider_driver_contract
  - architectural_test_doctrine
  - kernel_slo_governance
  - multi_tenancy_foundation
  - identity_persistence_contract
decisions:
  - Atlas AI tem um Kernel formal. Topology nao basta; sem kernel tipado, doutrina nao e enforcable.
  - Toda requisicao Atlas AI e modelada como Operation Envelope tipada, imutavel por estagio, append-only.
  - Decision Receipt v2 e event-sourced, hashable, chainable e replayavel; preview = mesmo codigo com dry_run=true.
  - Evidence Ledger e fonte de verdade de execucao; tabelas operacionais sao projecoes.
  - Capability Registry e artefato executavel; teste arquitetural impede regressao.
  - Dominios plugam via Domain Manifest declarativo; surface plugam via Surface Adapter; provider plugam via Provider Driver.
  - Falhas sao taxonomia fechada com handlers conhecidos; falha sem handler e bug do kernel.
  - SLOs operacionais sao parte da spec; sem medida, doutrina e aspiracional.
  - Multi-tenancy e single-operator sao a mesma arquitetura; OperatorId esta em todo Envelope desde dia 1.
  - Identidade Atlas AI e invariante; provider swap nao quebra continuidade cognitiva.
  - Self-evolution (Atlas AI Curator) opera em cima do Evidence Ledger e propoe mudancas via gates.
maintenance:
  - Esta spec e a mae. Mudancas em pipeline, profile, decide ou orchestration devem ser checadas contra este documento.
  - Adicionar novos campos aos contratos exige bump de schema_version e migracao aditiva.
  - Remover ou renomear contratos exige deprecation cycle minimo de 6 meses.
  - Rodar atlas engineering knowledge sync --prune e index-code --prune apos editar.
  - Architectural tests devem permanecer verdes; falha bloqueia merge.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md
  - docs/engineering-knowledge-base/kernel/failure-domain-taxonomy.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
  - docs/engineering-knowledge-base/atlas-ai-architecture-audit.md
  - docs/engineering-knowledge-base/atlas-ai-resolver-corpus-audit.md
  - docs/engineering-knowledge-base/atlas-ai-vision.md
  - docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
  - docs/engineering-knowledge-base/engineering-blueprint.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
  - app/Services/Ai/AtlasDecideService.php
  - app/Services/Ai/AtlasAiPolicyService.php
  - app/Services/Ai/AiGatewayService.php
  - app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php
  - app/Services/Engineering/EngineeringHarnessRunnerService.php
  - app/Services/Tools/AtlasToolRegistryService.php
  - app/Services/Tools/AtlasToolPolicyEngine.php
  - app/Services/Tools/AtlasToolEvidenceStore.php
  - app/Services/Ai/AtlasMemoryRegistryService.php
  - app/Services/Ai/AtlasOpenBrainContextInjectionService.php
---

# Atlas AI Kernel Architecture - The Mother Specification

> Esta e a especificacao mae do Atlas AI. Documentos de topologia (`atlas-ai-vision.md`, `atlas-ai-pipeline.md`, `atlas-ai-core-vs-domain.md`, `atlas-ai-operating-system.md`, `atlas-ai-architecture-audit.md`, `atlas-ai-resolver-corpus-audit.md`) descrevem **o que existe e como flui**. Esta spec descreve **como o sistema opera fisicamente**: contratos tipados, maquinas de estado, eventos, SDKs, invariantes enforcados.
>
> Sem esta camada, Atlas AI vira documentacao prescritiva que ninguem garante. Com ela, doutrina vira lei de tipo.

## Sumario

- [0. Posicionamento](#0-posicionamento)
- [1. Axiomas](#1-axiomas)
- [2. Topologia Macro](#2-topologia-macro)
- [3. Control Plane vs Data Plane](#3-control-plane-vs-data-plane)
- [4. Operation Envelope](#4-operation-envelope)
- [5. Pipeline Como Funcoes Tipadas](#5-pipeline-como-funcoes-tipadas)
- [6. Maquinas De Estado](#6-maquinas-de-estado)
- [7. Decision Receipt v2](#7-decision-receipt-v2)
- [8. Evidence Ledger](#8-evidence-ledger)
- [9. Capability Registry Executavel](#9-capability-registry-executavel)
- [10. Domain Manifest E SDK](#10-domain-manifest-e-sdk)
- [11. Surface Adapter Contract](#11-surface-adapter-contract)
- [12. Provider Driver Contract](#12-provider-driver-contract)
- [13. Policy Engine Declarativo](#13-policy-engine-declarativo)
- [14. Memoria E Contexto Como Servicos Kernel](#14-memoria-e-contexto-como-servicos-kernel)
- [15. Tools Como Servico Kernel](#15-tools-como-servico-kernel)
- [16. Failure Domains](#16-failure-domains)
- [17. SLOs Operacionais](#17-slos-operacionais)
- [18. Cost Model](#18-cost-model)
- [19. Versionamento E Compatibilidade](#19-versionamento-e-compatibilidade)
- [20. Multi-Tenancy Foundation](#20-multi-tenancy-foundation)
- [21. Identidade E Continuidade](#21-identidade-e-continuidade)
- [22. Self-Evolution Loop](#22-self-evolution-loop)
- [23. Anti-Padroes Formais](#23-anti-padroes-formais)
- [24. Doutrina Como Teste Arquitetural](#24-doutrina-como-teste-arquitetural)
- [25. Roadmap De Implementacao](#25-roadmap-de-implementacao)
- [26. Definition Of Done Da Arquitetura](#26-definition-of-done-da-arquitetura)
- [27. Glossario Kernel](#27-glossario-kernel)

---

## 0. Posicionamento

A documentacao Atlas AI tem tres camadas:

```text
+------------------------------------------------------+
| Layer 3 - Domain Specs                               |
|   atlas-programming-architecture                     |
|   atlas-personal-development-domain                  |
|   atlas-finance-domain                               |
|   atlas-curator-domain (futuro)                      |
+------------------------------------------------------+
| Layer 2 - Topology                                   |
|   atlas-ai-vision                                    |
|   atlas-ai-pipeline                                  |
|   atlas-ai-core-vs-domain                            |
|   atlas-ai-operating-system                          |
|   atlas-ai-architecture-audit                        |
+------------------------------------------------------+
| Layer 1 - Kernel (este documento)                    |
|   atlas-ai-kernel-architecture                       |
+------------------------------------------------------+
| Layer 0 - Constitution                               |
|   atlas-ai-layer-0-glossary                          |
|   source material: Atlas_Documento_Mestre_v6         |
+------------------------------------------------------+
```

Esta spec ocupa **Layer 1**. Ela e mais profunda que topologia (que diz "X chama Y") e mais especifica que constituicao (que diz "Atlas AI e core cognitivo persistente"). Ela define **como o sistema realmente funciona em termos enforcaveis por compilador e teste**.

### Quem deve ler

- IA ou humano implementando qualquer fluxo Atlas AI
- IA ou humano adicionando dominio, surface ou provider novo
- IA ou humano alterando policy, decide, orchestrator, harness, runtime ou ledger
- IA ou humano respondendo "isso e duplicacao?" ou "isso pode ser feito em uma surface so?"

### Como ler

Em ordem. Cada secao depende das anteriores. Pulando, voce perde semantica.

---

## 1. Axiomas

A arquitetura mae obedece dez axiomas. Eles sao **invariantes de tipo**, nao slogans.

### Ax-1. Toda requisicao e uma Operation Envelope tipada e imutavel por estagio

Nenhum codigo Atlas AI pode tocar provider, tool, memory ou state sem antes estar dentro de uma `OperationEnvelope`. Surfaces criam o envelope. Estagios consomem e enriquecem campos especificos. Nenhum estagio pode reescrever campos de outro estagio.

**Implicacao**: comando CLI nao decide provider. Settings nao monta context. App nao escolhe orchestrator. Cada estagio escreve apenas seu slot.

### Ax-2. Toda decisao relevante emite Decision Receipt assinado e chainavel

Sem receipt, decisao nao existe. Receipt e hash determinista de inputs + outputs. Receipt referencia receipt anterior na mesma cadeia operacional. Receipt e replayavel: preview = mesmo codigo com `dry_run=true`.

**Implicacao**: nao existe "Atlas Decide foi pulado". Se nao tem receipt, foi bypass — bug.

### Ax-3. Evidence Ledger e fonte de verdade; tabelas sao projecoes

Toda mudanca de estado emite event para o Evidence Ledger. Tabelas operacionais (`ai_traces`, `atlas_engineering_runs`, `atlas_tool_runs`, `atlas_memory_entry_usages`) sao projecoes derivaveis do ledger. Ledger e append-only.

**Implicacao**: replay completo a partir do ledger e possivel. Auditoria nunca depende de log de aplicacao.

### Ax-4. Capacidades horizontais sao artefatos executaveis, nao prosa

Toda capability declarada como horizontal tem `CapabilityManifest` registrado. Surfaces que devem suporta-la passam por `CapabilityComplianceTest`. Falha de compliance e falha de merge.

**Implicacao**: paste-image so em `atlas ask` deixa de ser possivel. Test arquitetural acusa antes do PR mergir.

### Ax-5. Dominio e plugin via Domain Manifest declarativo

Adicionar um dominio (Programming, Personal Dev, Finance, Self-Improvement ou
um Curator dedicado futuro) e adicionar arquivo de manifesto + classes que
implementam `DomainOrchestrator`. Sem hardcode no kernel.

**Implicacao**: o kernel nao sabe sobre Programming. Programming e o dominio que mais usa o kernel.

### Ax-6. Provider e driver substituivel; identidade Atlas AI e invariante

`ProviderDriver` e contrato. Claude, Codex, Gemini, GPT, local sao implementacoes. Trocar provider nao muda comportamento Atlas AI; muda apenas o motor.

**Implicacao**: prompt fragments de identidade (`atlas-ai-master-prompt.md`) sao injetados pelo kernel, nao pelo provider. Provider que ignora identidade falha em compliance.

### Ax-7. Politica e declarativa, resolvida por camadas

`AtlasAiPolicyService` resolve `EffectivePolicy` mergeando: global runtime settings < domain profile < flow profile < surface policy < session override. Policy V2 receipt expoe origem de cada campo.

**Implicacao**: nenhum lugar do codigo tem `if (provider === 'codex') ... else ...`. Tudo decide via `policy.allowed_providers`.

### Ax-8. Falha e taxonomia fechada com handler nomeado

`FailureDomain` e enum. Cada valor tem `FailureHandler`. Falha sem handler nao compila.

**Implicacao**: "deu erro" deixa de existir. Erro sempre tem nome, classificacao e proxima acao.

### Ax-9. SLOs sao parte da spec; sem medida, doutrina e aspiracional

Cada estagio kernel tem latency target, success rate target e cost target. Telemetry mede continuamente. SLO violation alerta.

**Implicacao**: dizer "context pack pequeno" sem medir e nao-cumprir-spec.

### Ax-10. Self-evolution opera dentro de gates; Curator nao bypassa kernel

Atlas AI Curator detecta lacunas e propoe mudancas. Mudancas passam por mesmo pipeline (gates, evidence, review). Curator nao escreve direto em capability registry, domain manifest ou policy.

**Implicacao**: o sistema melhora a si mesmo, mas nao se modifica fora dos contratos.

---

## 2. Topologia Macro

```mermaid
flowchart TB
    subgraph Surfaces["Surface Adapters"]
        CLI["atlas CLI<br/>(dev/forge/fix/ask/continue)"]
        APP["Atlas App<br/>(mobile/desktop)"]
        WORKER["Atlas Worker<br/>(scheduler/queue)"]
        API["Atlas API<br/>(HTTP)"]
        MCP["MCP Server<br/>(read-only/HTTP)"]
        BG["Background<br/>(automation)"]
    end

    subgraph Kernel["Atlas AI Kernel"]
        ENV["Operation Envelope<br/>Factory + Validator"]
        INPUT["Atlas.Input<br/>multimodal normalizer"]
        INTENT["Atlas.Intent<br/>classifier"]
        DOMAIN["Atlas.Domain<br/>resolver"]
        PROFILE["Atlas.Profile<br/>resolver"]
        POLICY["AtlasAiPolicyService<br/>policy compiler"]
        DECIDE["AtlasDecideService v2<br/>Decision Receipt issuer"]
        ROUTER["DomainOrchestratorRouter"]
        CTX["Atlas.Context<br/>composer"]
        MEM["Atlas.Memory<br/>recall + record"]
        TOOLS["Atlas.Tools<br/>Super Tool Runtime"]
        EXEC["Atlas.Executor<br/>simple|repair|harness|council"]
        GATE["Atlas.Gate<br/>composer + evaluator"]
        REPAIR["Atlas.Repair<br/>capsule + loop"]
        EVIDENCE["Atlas.Evidence<br/>packet builder"]
        LEDGER["Evidence Ledger<br/>append-only event store"]
        LEARN["Atlas.Learning<br/>delta + promotion"]
        TELEM["Atlas.Telemetry<br/>SLOs + cost"]
        CAPREG["Capability Registry"]
        DOMREG["Domain Registry"]
        PROVREG["Provider Registry"]
    end

    subgraph Domains["Domain Plugins"]
        PROG["Atlas AI Programming<br/>(dev/forge/qa/refactor/security/db)"]
        PERS["Atlas AI Personal Development<br/>(reflect/review/intervention)"]
        FIN["Atlas AI Finance<br/>(market/portfolio/decision)"]
        CUR["Atlas AI Curator<br/>(self-evolution)"]
        OTHER["Future Domains"]
    end

    subgraph Providers["Provider Drivers"]
        CLAUDE["Claude CLI/API"]
        CODEX["Codex CLI"]
        GEMINI["Gemini CLI"]
        LOCAL["Local Models"]
    end

    Surfaces --> ENV
    ENV --> INPUT --> INTENT --> DOMAIN --> PROFILE --> POLICY --> DECIDE --> ROUTER
    ROUTER --> Domains
    Domains --> CTX
    Domains --> MEM
    Domains --> EXEC
    EXEC --> TOOLS
    EXEC --> Providers
    EXEC --> GATE
    GATE --> REPAIR
    REPAIR --> EXEC
    GATE --> EVIDENCE
    EVIDENCE --> LEDGER
    LEDGER --> LEARN
    LEDGER --> TELEM
    LEARN --> MEM
    CAPREG -.governs.-> Surfaces
    DOMREG -.governs.-> Domains
    PROVREG -.governs.-> Providers
```

**Tres registries** governam o que pode existir:

- `CapabilityRegistry` — capacidades horizontais e quais surfaces devem suporta-las
- `DomainRegistry` — dominios instalados e seus manifests
- `ProviderRegistry` — provider drivers disponiveis

Sem registro, surface/domain/provider e ilegal e nao executa.

---

## 3. Control Plane vs Data Plane

Separacao critica que Codex nao formaliza:

| Plane | Responsabilidade | Estado |
|---|---|---|
| **Control Plane** | classifica, decide, compila policy, escolhe executor, monta receipt | sem mutacao de mundo |
| **Data Plane** | executa, chama provider, roda tool, escreve arquivo, emite evidence | mutacao governada |

```mermaid
flowchart LR
    subgraph CP["Control Plane (sem efeitos colaterais externos)"]
        I[Intent] --> D[Domain]
        D --> F[Flow]
        F --> P[Policy]
        P --> R[Decision Receipt]
    end
    subgraph DP["Data Plane (com efeitos governados)"]
        E[Executor] --> T[Tools]
        E --> PR[Provider]
        E --> G[Gates]
        G --> EV[Evidence Ledger]
    end
    CP -->|signed receipt| DP
    DP -.events.-> CP
```

**Regra dura**: Data Plane recusa execucao se nao receber Decision Receipt valido (assinado, nao expirado, dentro do budget).

**Beneficios**:

- Preview = Control Plane com `dry_run=true`. Mesmo codigo, sem Data Plane.
- Replay = re-emitir eventos do Data Plane a partir do mesmo Decision Receipt.
- Multi-region = Control Plane local, Data Plane pode estar em sandbox/Docker/remoto.
- Testes = Control Plane testavel sem stub de provider.

---

## 4. Operation Envelope

Unidade canonica que flui pelo kernel.

### 4.1 Schema

```php
final class OperationEnvelope
{
    public string $envelope_id;          // ULID
    public ?string $parent_envelope_id;  // chain
    public string $schema_version;       // "atlas.envelope.v1"
    public OperatorContext $operator;
    public Provenance $origin;
    public Input $input;
    public Routing $routing;             // populated progressively
    public ?DecisionReceipt $decision;   // populated by Decide
    public Execution $execution;         // populated by Executor
    public ?Output $output;              // populated at finish
    public Audit $audit;
}

final class OperatorContext
{
    public string $operator_id;          // even if single-user, present
    public string $tenant_id;            // multi-tenancy ready
    public WorkspacePath $workspace;
    public array $preferences;
    public PrivacyClass $default_privacy;
}

final class Provenance
{
    public string $surface_id;           // "atlas_cli", "atlas_app", ...
    public string $surface_version;
    public string $session_id;
    public DateTimeImmutable $received_at;
    public ?string $upstream_envelope_id;
}

final class Input
{
    public InputContent $primary;        // text|audio|image|file|struct
    public Attachment[] $attachments;
    public OperatorHints $hints;         // optional explicit overrides
    public Locale $locale;
    public string $input_hash;           // determinism
}

final class Routing
{
    public ?IntentClassification $intent;
    public ?DomainId $domain;
    public ?FlowId $flow;
    public ?EffectiveProfile $profile;
}

final class Execution
{
    public ?ExecutorKind $executor_kind;
    public ?RuntimeGraph $runtime_graph;
    public ?DateTimeImmutable $started_at;
    public ?DateTimeImmutable $finished_at;
    public ExecutionStatus $status;
}

final class Output
{
    public OperationStatus $status;       // succeeded|partial|blocked|failed|needs_review
    public OperationResult $result;
    public EvidencePacket $evidence;
    public CostSummary $cost;
}

final class Audit
{
    public string $trace_id;
    public ?string $parent_trace_id;
    public string $chain_hash;            // chains envelope to ancestors
}
```

### 4.2 Invariantes

- `envelope_id` e ULID (lexicograficamente ordenavel, ~unique).
- `schema_version` muda apenas com migracao aditiva ou deprecation cycle de 6 meses.
- Apos `decision` ser assinada, ninguem pode altera-la — apenas adicionar nova decisao em `parent_envelope_id` filho.
- `chain_hash = sha256(parent_chain_hash || envelope_canonical_serialization)`.
- `operator.operator_id` e obrigatorio mesmo quando ha um operador unico.

### 4.3 Imutabilidade Por Estagio

Cada estagio recebe `OperationEnvelope`, le seus campos e devolve mutacao **apenas no slot proprio**. Mutacoes externas ao slot sao ilegais.

```php
interface KernelStage
{
    public function stage(): StageId;
    public function reads(): array;       // which slots it reads
    public function writes(): array;      // which slots it may write
    public function execute(OperationEnvelope $env, mixed $stageInput): mixed;
}
```

Compliance test:

```php
test("each stage writes only to its declared slots", function () {
    foreach (Kernel::stages() as $stage) {
        $allowed = $stage->writes();
        $actual  = StageMutationProbe::run($stage);
        expect($actual)->subsetOf($allowed);
    }
});
```

---

## 5. Pipeline Como Funcoes Tipadas

A pipeline nao e prosa. E composicao tipada.

### 5.1 Tipos Base

```php
interface KernelStage<In, Out>
{
    public function execute(OperationEnvelope $env, In $input): Out;
}

type IntentClassifier      = KernelStage<InputContent,            IntentClassification>;
type DomainResolver        = KernelStage<IntentClassification,    DomainId>;
type FlowResolver          = KernelStage<DomainId+IntentSignals,  FlowId>;
type ProfileResolver       = KernelStage<FlowId,                  EffectiveProfile>;
type PolicyCompiler        = KernelStage<EffectiveProfile,        Policy>;
type ContextComposer       = KernelStage<Policy+DomainHints,      ContextPack>;
type Decide                = KernelStage<Policy+ContextPack,      DecisionReceipt>;
type OrchestratorRouter    = KernelStage<DecisionReceipt,         DomainOrchestrator>;
type Orchestrator          = KernelStage<Envelope+Receipt,        ExecutionResult>;
type GateRunner            = KernelStage<ExecutionResult,         GateResult>;
type RepairLoop            = KernelStage<GateResult,              ExecutionResult|Escalation>;
type EvidencePacker        = KernelStage<ExecutionResult+Gates,   EvidencePacket>;
type LearningProposer      = KernelStage<EvidencePacket,          MemoryDelta[]>;
```

### 5.2 Composicao

```php
final class AtlasAiKernel
{
    public function run(InputContent $input, Provenance $origin): OperationEnvelope
    {
        $env = $this->envelopeFactory->create($input, $origin);

        // Control Plane (sem efeito colateral externo)
        $env->routing->intent   = $this->intentClassifier->execute($env, $env->input->primary);
        $env->routing->domain   = $this->domainResolver->execute($env, $env->routing->intent);
        $env->routing->flow     = $this->flowResolver->execute($env, $env->routing->domain);
        $env->routing->profile  = $this->profileResolver->execute($env, $env->routing->flow);
        $policy                 = $this->policyCompiler->execute($env, $env->routing->profile);
        $context                = $this->contextComposer->execute($env, $policy);
        $env->decision          = $this->decide->execute($env, [$policy, $context]);

        // Data Plane (com efeito governado)
        $orchestrator  = $this->router->execute($env, $env->decision);
        $execResult    = $orchestrator->execute($env, $env->decision);
        $gateResult    = $this->gateRunner->execute($env, $execResult);

        if ($gateResult->isRepairable() && $env->decision->permitsRepair()) {
            $execResult = $this->repairLoop->execute($env, $gateResult);
            $gateResult = $this->gateRunner->execute($env, $execResult);
        }

        $env->output = new Output(
            status:   $gateResult->finalStatus(),
            result:   $execResult->payload(),
            evidence: $this->evidencePacker->execute($env, [$execResult, $gateResult]),
            cost:     $this->costAggregator->aggregate($env),
        );

        $deltas = $this->learning->execute($env, $env->output->evidence);
        foreach ($deltas as $delta) {
            $this->memoryRegistry->propose($delta);
        }

        $this->ledger->seal($env);
        return $env;
    }
}
```

Observe:

- Cada estagio e injetavel — substituivel para teste.
- `dry_run=true` em `decision` faz Data Plane abortar antes de executar (preview).
- Composicao linear no caso simples; orquestradores complexos podem rodar grafo de execucao definido em `runtime_graph`.

### 5.3 Pipeline Kernel Scaffold Atual

Status atual: existe uma infraestrutura inicial em
`app/Services/Ai/Kernel/Pipeline/` para representar o pipeline unificado sem
migrar fluxos existentes. O contrato `AtlasKernelPipeline` expoe:

- `stages()`;
- `plan(PipelineInput $input)`;
- `execute(PipelineInput $input)`;
- `complianceReport()`.

A implementacao `ScaffoldAtlasKernelPipeline` roda apenas em modo scaffold/dry-run:

- aceita input normalizado por `PipelineInput`;
- declara a ordem canonica `Input -> OperationEnvelope -> Intent -> Decide ->
  DecisionReceipt -> Domain -> Context -> Policy -> Runtime -> Gate -> Repair
  -> Evidence -> Learning -> Output`;
- declara slots lidos/escritos por estagio;
- valida ordem, numeros sequenciais, nomes de slots, ausencia de duplicidade em
  stages/ordens/escritas e que cada stage so le slots iniciais ou escritos por
  estagios anteriores;
- gera plano auditavel, placeholders de evidence e trace;
- versiona o contrato como `atlas.kernel.pipeline.scaffold.v1`, expoe
  `canonical_flow`, `stage_count`, manifesto de slots, guards de execucao e
  `plan_hash` para consumidores CLI/API compararem payloads sem depender de
  prosa;
- centraliza constantes e guards scaffold em `KernelPipelineContract`, incluindo
  `canonical_flow_hash`, para reduzir drift entre plano, resultado, scanner e
  testes;
- o plano auditavel guarda hash do texto primario, hash de hints completos,
  valores apenas de hints operacionais allowlisted e nomes de metadata, sem
  copiar texto bruto ou valores sensiveis;
- `PipelineInput` normaliza hashes de hints de forma recursiva e ordena
  `metadata_keys`, evitando drift por ordem de payload ou valores nao escalares
  vindos de surface futura;
- `PipelineInput` publica `input_fingerprint` deterministico e o `pipeline_id`
  scaffold usa esse fingerprint como sufixo auditavel;
- retorna `provider_execution_allowed=false`,
  `runtime_execution_allowed=false` e `providerExecutionAttempted=false`;
- nao chama provider real, worker, gateway, surface concreta ou runtime amplo.
- pode ser inspecionado por `atlas:ai:pipeline --json`; com `--execute`, retorna
  resultados scaffold por stage, evidence refs e trace refs sem executar
  provider nem runtime real.
- tambem esta exposto por `POST /ai/pipeline` com `atlas.token`, para App,
  dashboard, mobile e Curator inspecionarem a mesma ponte sem duplicar logica.
  A API aceita `text`, `surface_id`, `operator_id`, `hints`, `metadata` e
  `execute`; sempre forca `dry_run=true`.
- o Repair Loop segue a mesma regra operacional: `atlas:ai:repair --json` e
  `POST /ai/repair` expoem plano/tentativa scaffold, sempre com `dry_run=true`
  nas surfaces CLI/API, sem executar provider, tool, harness ou patch real.

Esse scaffold e uma ponte de contrato. Ele permite testar ordem, slots,
compliance e auditabilidade antes de migrar `atlas dev`, `atlas forge`,
`atlas:ai:chat`, API ou worker para o pipeline real.

---

## 6. Maquinas De Estado

Codex nao definiu maquina de estado. Sem ela, "task pronta" e ambiguo.

### 6.1 Operation State Machine

```text
RECEIVED
   |
   v
ROUTING --(domain unknown)--> NEEDS_CLARIFICATION
   |
   v
DECIDING --(policy violation)--> BLOCKED
   |
   v
EXECUTING --(provider failure)--> RETRYING
   |              |
   |              +--(repeated failure)--> FAILED
   v
GATING --(gate failed, repairable)--> REPAIRING
   |              |
   |              +--(gate failed, terminal)--> FAILED
   |              +--(gate failed, needs review)--> NEEDS_REVIEW
   v
EVIDENCE_SEALED
   |
   v
LEARNING_PROPOSED
   |
   v
COMPLETED
```

Cada transicao emite event. Eventos sao append-only no Evidence Ledger.

### 6.2 Decision Receipt State

```text
DRAFT --(seal)--> ISSUED --(consume)--> CONSUMED
                    |
                    +--(expire)--> EXPIRED
                    +--(revoke)--> REVOKED
```

Receipt `EXPIRED` ou `REVOKED` nao autoriza Data Plane.

### 6.3 Tool Run State

```text
PLANNED --(approve)--> APPROVED --(start)--> RUNNING
                                                |
                                                v
                                            COMPLETED
                                                |
                                                v
                                          NORMALIZED
                                                |
                                                v
                                       EVIDENCE_RECORDED
                                                |
                                                v
                                          GATE_EVALUATED
```

Cada estado tem timeout, retry policy e failure handler explicitos.

---

## 7. Decision Receipt v2

### 7.1 Schema

```php
final class DecisionReceipt
{
    public string $receipt_id;              // ULID
    public string $envelope_id;
    public DateTimeImmutable $decided_at;
    public DateTimeImmutable $expires_at;   // hard expiry
    public string $decision_engine;         // "atlas.decide.v2"
    public string $inputs_hash;             // sha256(canonicalize(policy, context, input_summary))

    public Resolution $resolution;
    public Authority $authority;
    public Selection $selection;
    public Budgets $budgets;
    public RequiredGate[] $required_gates;
    public RequiredEvidence[] $required_evidence;
    public Constraint[] $constraints;
    public ?DryRun $dry_run;

    public Signature $signature;
}

final class Resolution
{
    public DomainId $domain;
    public FlowId $flow;
    public string $profile_id;
    public string $profile_version;
}

final class Authority
{
    public string $policy_profile_id;
    public string $policy_version;
    public ?OverrideSpec $operator_override;
    public ?OverrideSpec $session_override;
    public string $merge_receipt_id;
}

final class Selection
{
    public ExecutorKind $executor_kind;     // simple|repair|harness|council
    public RuntimeGraph $runtime_graph;
    public array $provider_assignments;     // map<NodeId, ProviderAssignment>
    public ProviderAssignment[] $fallback_chain;
    public string $context_strategy;
}

final class Budgets
{
    public CostBudget $cost;
    public LatencyBudget $latency;
    public AutonomyLevel $autonomy;         // A0..A5
    public int $max_repair_iterations;
    public int $max_concurrent_tool_runs;
}

final class Signature
{
    public string $receipt_hash;            // sha256(canonical_serialize(receipt without signature))
    public ?string $parent_receipt_id;
    public string $chain_hash;              // sha256(parent_chain_hash || receipt_hash)
    public string $signed_by;               // "atlas-decide-engine@v2"
}
```

### 7.2 Invariantes

- `inputs_hash` determinista: mesmos inputs, mesmo hash.
- Mesma `receipt_hash` em duas decisoes para `inputs_hash` igual implica decisao determinista (boa propriedade para regressao).
- `expires_at` impede execucao com receipt velho. Default 30s para Data Plane sincrono, 24h para background.
- `dry_run=true` impede Data Plane mesmo com receipt valido — usado para preview.
- `chain_hash` permite verificar integridade de cadeia de decisoes (multi-step operations).

### 7.3 Replay E Preview

```text
preview(input)  = atlas_decide.run(input, dry_run=true)
replay(receipt) = atlas_decide.run_from_receipt(receipt) -> deve produzir receipt determinista identico
```

Replay de receipt antigo permite:

- Verificar se decisao mudaria com nova policy.
- Reproduzir bug a partir de receipt persistido.
- A/B testar mudancas de policy (run em shadow mode com nova policy, comparar receipts).

---

## 8. Evidence Ledger

### 8.1 Por Que Event Sourcing

Codex propoe evidence como packet final. Mas packet final e projecao. A fonte de verdade deve ser o stream de eventos que o produziu. Beneficios:

- **Replay completo** sem depender de tabelas operacionais
- **Audit perfeita** sem confiar em log de aplicacao
- **Compaction segura** de tabelas operacionais (sao projecoes derivaveis)
- **Self-evolution** opera sobre eventos, nao sobre estado mutavel
- **Time travel** para debug

### 8.2 Esquema De Evento

```php
final class LedgerEvent
{
    public string $event_id;                // ULID
    public string $envelope_id;
    public ?string $causation_id;           // event que causou este
    public string $correlation_id;          // grupo logico
    public DateTimeImmutable $occurred_at;
    public string $event_type;              // discriminated union tag
    public string $emitter_stage;
    public string $emitter_version;
    public array $payload;                  // schema by event_type
    public string $payload_hash;
    public string $tenant_id;
    public string $operator_id;
}
```

### 8.3 Event Types (enum fechado)

```php
enum LedgerEventType
{
    case ENVELOPE_CREATED;
    case INPUT_NORMALIZED;
    case INTENT_CLASSIFIED;
    case DOMAIN_RESOLVED;
    case FLOW_RESOLVED;
    case PROFILE_RESOLVED;
    case POLICY_COMPILED;
    case CONTEXT_COMPOSED;
    case CONTEXT_INJECTED;            // Open Brain
    case DECISION_DRAFTED;
    case DECISION_ISSUED;
    case DECISION_CONSUMED;
    case DECISION_EXPIRED;
    case ORCHESTRATOR_SELECTED;
    case EXECUTION_STARTED;
    case PROVIDER_CALLED;
    case PROVIDER_RETURNED;
    case PROVIDER_FALLBACK;
    case TOOL_PLANNED;
    case TOOL_APPROVED;
    case TOOL_INVOKED;
    case TOOL_RETURNED;
    case TOOL_NORMALIZED;
    case TOOL_EVIDENCE_RECORDED;
    case GATE_EVALUATED;
    case GATE_PASSED;
    case GATE_BLOCKED;
    case REPAIR_INITIATED;
    case REPAIR_COMPLETED;
    case ESCALATION_REQUESTED;
    case EVIDENCE_PACKED;
    case LEARNING_PROPOSED;
    case MEMORY_DELTA_ACCEPTED;
    case OPERATION_COMPLETED;
    case OPERATION_FAILED;
    case OPERATION_BLOCKED;
    case OPERATION_NEEDS_REVIEW;
}
```

Adicionar event type novo exige migration aditiva. Nunca remover sem deprecation cycle.

### 8.4 Storage E Projecoes

| Camada | Funcao |
|---|---|
| `atlas_ledger_events` | tabela append-only, particionada por tenant_id e mes |
| `atlas_ledger_snapshots` | snapshot periodico por envelope_id (replay rapido) |
| `ai_traces` | projecao para UI/CLI |
| `atlas_engineering_runs` | projecao para Programming domain |
| `atlas_tool_runs` | projecao para Tool Runtime |
| `atlas_memory_entry_usages` | projecao para Memory Audit |

Reconstruir projecao = reler eventos. Tabelas operacionais podem ser truncadas e regeneradas.

---

## 9. Capability Registry Executavel

A regra "capacidade horizontal vive uma vez, surfaces herdam" so vale se for **enforcada por teste**. Codex documenta o principio. Esta secao formaliza o mecanismo.

### 9.1 Capability Manifest

Um arquivo `capabilities/<id>.yaml` por capacidade:

```yaml
# capabilities/atlas.input.image_paste.yaml
schema_version: atlas.capability.v1
id: atlas.input.image_paste
version: 1.0.0
title: Multimodal image paste
description: Permite ao operador colar imagem do clipboard como input.
contract:
  input:
    type: ImageBlob
    formats: [png, jpeg, webp, heic]
    max_size_mb: 25
  output:
    type: NormalizedImageRef
    fields: [hash, mime, dimensions, storage_path]
  errors:
    - clipboard_empty
    - format_unsupported
    - size_exceeds_budget
required_surfaces:
  - atlas_cli
  - atlas_app
  - atlas_api
optional_surfaces:
  - atlas_worker
  - atlas_background
not_supported:
  - surface: atlas_mcp_readonly
    reason: MCP read-only HTTP nao expoe binary upload nesta fase
test_suite:
  - tests/Capability/ImagePasteCliTest.php
  - tests/Capability/ImagePasteAppTest.php
  - tests/Capability/ImagePasteApiTest.php
introduced_at: atlas-server@2026-05-04
owner: atlas.input
```

### 9.2 Compliance Test

Test arquitetural que **falha o build** se uma surface obrigatoria nao implementa uma capability obrigatoria:

```php
class CapabilityComplianceTest extends TestCase
{
    public function test_every_required_surface_implements_every_required_capability(): void
    {
        $registry = $this->app->make(CapabilityRegistry::class);
        $surfaces = $this->app->make(SurfaceRegistry::class);

        foreach ($registry->all() as $capability) {
            foreach ($capability->required_surfaces as $surface_id) {
                $surface = $surfaces->get($surface_id);

                $this->assertTrue(
                    $surface->implements($capability->id),
                    sprintf(
                        "Surface %s must implement capability %s. Add implementation or declare not_supported with reason.",
                        $surface_id,
                        $capability->id,
                    ),
                );
            }
        }
    }

    public function test_every_capability_has_test_suite(): void
    {
        foreach (CapabilityRegistry::all() as $capability) {
            $this->assertNotEmpty($capability->test_suite,
                "Capability {$capability->id} must declare test_suite.");

            foreach ($capability->test_suite as $test_path) {
                $this->assertFileExists(base_path($test_path),
                    "Capability {$capability->id} declares test {$test_path} that does not exist.");
            }
        }
    }
}
```

Resultado: o paste-image so em `atlas ask` deixa de ser possivel. Quem mergir `atlas dev` sem suporte a image paste, sem `not_supported.reason` valido, quebra o test arquitetural.

### 9.3 Capability Token

Em runtime, surface declara capabilities suportadas em handshake:

```php
final class CapabilityToken
{
    public string $surface_id;
    public string[] $supports;
    public string $signature;
}
```

Kernel valida: `Capability X requested but surface_id Y did not declare support`. Falha imediata, com mensagem acionavel.

---

## 10. Domain Manifest E SDK

Adicionar dominio (Personal Dev, Finance, Self-Improvement ou Curator dedicado
futuro) deve ser **contrato declarativo**, nao alteracao do kernel.

### 10.1 Manifest

```yaml
# domains/personal_development.yaml
schema_version: atlas.domain.v1
id: personal_development
version: 0.1.0
title: Atlas AI Personal Development
description: Dominio de desenvolvimento pessoal, performance, comportamento e cognicao.

intent_classifiers:
  - class: PersonalDevelopment\HabitIntentClassifier
    matches: [habit, routine, foco, energia, sono, performance]
  - class: PersonalDevelopment\WeeklyReviewIntentClassifier
    matches: [revisao semanal, weekly review, retrospectiva]

flows:
  personal_development.reflect:
    title: Reflexao guiada
    orchestrator: PersonalDevelopment\ReflectOrchestrator
    runtime: PersonalDevelopment\PersonalDevelopmentRuntime
    autonomy_default: low
    background_allowed: false
    required_gates:
      - personal_development.privacy_gate
      - personal_development.medical_safety_gate
    evidence_schema_extensions:
      - cognitive_metrics
  personal_development.weekly_review:
    title: Revisao semanal estruturada
    orchestrator: PersonalDevelopment\WeeklyReviewOrchestrator
    runtime: PersonalDevelopment\PersonalDevelopmentRuntime
    autonomy_default: low
    background_allowed: false
    required_gates:
      - personal_development.privacy_gate
    evidence_schema_extensions:
      - decision_revisits
      - life_hypotheses
  personal_development.intervention:
    title: Plano de intervencao baseado em evidencia
    orchestrator: PersonalDevelopment\InterventionOrchestrator
    runtime: PersonalDevelopment\InterventionMeasurementHarness
    autonomy_default: A0           # observa, nao executa
    required_human_approval: true
    required_gates:
      - personal_development.privacy_gate
      - personal_development.medical_safety_gate
      - personal_development.intervention_n_of_1_gate

required_capabilities:
  - atlas.memory.recall
  - atlas.memory.private_classification
  - atlas.input.text
  - atlas.input.image_paste
  - atlas.context.composer

provided_capabilities:
  - personal_development.cognitive_metrics
  - personal_development.n_of_1_harness
  - personal_development.lifestyle_correlation_query

privacy:
  default_class: private
  classes_allowed: [private, sensitive]
  allowed_provider_classes: [provider_safe_only]
  forbidden_categories:
    - medical_diagnosis_claim
    - therapy_substitute_claim

memory_projection:
  scope: autobiographical
  retention: long
  redaction: aggressive

evidence_schema_extensions:
  cognitive_metrics:
    fields: [articulation, reasoning_depth, application_ratio, pattern_count, curation_rate]
    computed_by: PersonalDevelopment\CognitiveMetricsComputer
  decision_revisits:
    fields: [decision_id, original_at, revisit_due_at, revisit_status, learning]
  life_hypotheses:
    fields: [hypothesis, validation_period, metric, result, status]

slo_targets:
  intent_classification_p95_ms: 150
  decision_p95_ms: 300
  privacy_gate_p99_ms: 100

owner: personal_development
maintainer: vitor.epf
introduced_at: atlas-server@TBD
```

### 10.2 Domain SDK

Para implementar dominio, autor:

1. Cria o manifest acima.
2. Implementa `AtlasDomainOrchestrator` por orchestrator canonico:

```php
interface AtlasDomainOrchestrator
{
    public function orchestratorId(): string;
    public function supportedDomains(): array;
    public function supportedFlows(): array;
    public function maturity(): string; // implemented | scaffold | planned
}
```

3. Para dominios com runtime completo, implementa metodos especificos de
   planejamento/execucao no orchestrator concreto. Exemplo atual:
   `AtlasProgrammingOrchestrator::sessionPlan()`,
   `dispatchContract()`, `repairExecutionContract()` e
   `executeWithHarness()`.

4. Implementa gates novos como `Gate` plugavel:

```php
interface Gate
{
    public function id(): string;
    public function evaluate(OperationEnvelope $env, ExecutionResult $result): GateResult;
}
```

5. Registra em `domain_registry`:

```bash
atlas domain register --manifest=domains/personal_development.yaml
```

6. Roda compliance:

```bash
atlas ai architecture-validate
```

`validate` checa: manifest passa schema, todos orchestrators existem,
orchestrators implementam o SDK, cada flow ativo aponta para orchestrator que
declara suporte ao domain/flow, todos gates existem, capabilities listadas
estao no registry e evidence schema extensions sao validas.

Status implementado:

- `AtlasDomainOrchestrator` define o contrato minimo do SDK;
- `AtlasDomainOrchestratorRegistry` resolve nomes curtos de manifest para
  classes PHP reais e maturidade (`implemented`, `scaffold`, `planned`);
- `AtlasDomainManifestValidator` falha quando domain/flow aponta para
  orchestrator desconhecido, sem classe, sem interface ou sem suporte declarado;
- `AtlasProgrammingOrchestrator`, `AtlasSelfImprovementOrchestrator`,
  `AtlasFinanceOrchestrator` e `AtlasPersonalDevelopmentOrchestrator` sao os
  dominios implemented/ready atuais;
- Marketing, General, Research, Health, Learning, Writing, QA, Security,
  Operations e Background possuem scaffolds explicitos para que a divida seja
  visivel e testavel, nao texto solto.

### 10.3 Domain Registry Test

```php
public function test_every_registered_domain_passes_manifest_validation(): void
{
    foreach (DomainRegistry::all() as $domain) {
        $report = $this->validator->validate($domain->manifest);
        $this->assertTrue($report->valid, $report->errors());
    }
}

public function test_every_flow_has_orchestrator_class(): void
{
    foreach (DomainRegistry::flows() as $flow) {
        $definition = $orchestrators->get($flow->orchestrator);
        $this->assertNotNull($definition);
        $this->assertTrue(class_exists($definition['class']));
        $this->assertTrue(is_subclass_of($definition['class'], AtlasDomainOrchestrator::class));
        $this->assertContains($flow->id, app($definition['class'])->supportedFlows());
    }
}
```

---

## 11. Surface Adapter Contract

Surface (CLI, App, Worker, API, MCP, Background) e **plugin tambem**, governado por contrato.

### 11.1 Contrato

```php
interface SurfaceAdapter
{
    public function id(): SurfaceId;
    public function version(): string;

    /** Capabilities que esta surface declara suportar. */
    public function declaredCapabilities(): array;

    /** Cria envelope a partir de input bruto da surface. */
    public function buildEnvelope(SurfaceInput $input): OperationEnvelope;

    /** Renderiza output da operacao para a surface. */
    public function renderOutput(OperationEnvelope $env): SurfaceOutput;

    /** Hints opcionais (provider preference, override, etc.) — passados via envelope. */
    public function collectHints(): OperatorHints;

    /** Validacao pre-flight: surface tem ambiente para esta operacao? */
    public function preflight(OperationEnvelope $env): PreflightResult;
}
```

### 11.2 Proibicoes Formais

Surface NAO pode:

- chamar provider diretamente (precisa do executor do dominio)
- montar context pack proprio (precisa do `Atlas.Context`)
- escolher provider/modelo (e funcao do `AtlasDecide`)
- decidir gate (e funcao do `Gate`)
- ler/escrever Memory diretamente (precisa do `Atlas.Memory`)
- decidir repair (e funcao do `Atlas.Repair`)
- declarar `succeeded` sem evidence packet

Cada proibicao tem teste correspondente que escaneia codigo da surface por strings/imports proibidos.

### 11.3 Hints Vs Override Vs Bypass

| Mecanismo | Permitido | Como |
|---|---|---|
| **Hint** | Sim | Surface passa `OperatorHints` no envelope. Decide considera, nao obriga. |
| **Override** | Sim, com escopo | `--ai=codex --model=5.5` vira `session_override` no envelope. AtlasAiPolicyService merge respeitando precedencia. |
| **Bypass** | Nao | Surface ignorando policy/decide nao compila (test arquitetural). |

### 11.4 Domain/Flow Hints De Surface

Surface pode declarar preferencias de domain/flow, mas nao vira autoridade de catalogo.

- `surface_id` identifica a origem da coleta/renderizacao.
- `supportedCapabilities()` declara capacidades formais usando o vocabulario fechado de `SurfaceCapability`.
- `supportedDomainFlowHints()` usa o vocabulario fechado `SurfaceDomainFlowHintKey` e pode declarar `default_domain_id`, `default_flow_id`, `supported_domain_ids`, `supported_flow_ids`, `task_flow_map`, `prefer_default_flow` e flags de aceitacao.
- Compliance de surface falha para hint key desconhecida, lista malformada, boolean invalido, default fora de supported ids ou task flow fora dos flows suportados declarados.
- `DomainCatalogSurfaceSelectionService` valida qualquer hint, `domain_id` ou `flow_id` contra `AtlasAiDomainCatalogService`.
- `domain_catalog_selection` pode ser reenviado como envelope canonico entre surfaces; campos planos `domain_id` e `flow_id` continuam aceitos e tem precedencia sobre envelopes antigos.
- Surface registrada sem `domain_flow_selection` nao aceita `domain_id` ou `flow_id` explicitos; o selector retorna `surface_domain_flow_selection_not_supported`.
- `supported_domain_ids` e `supported_flow_ids`, quando declarados, limitam a exposicao da surface; escolhas canonicas fora desses limites retornam `surface_domain_not_supported` ou `surface_flow_not_supported`.
- O read model de selecao inclui `surface_hints.supported_capabilities` para renderizacao e diagnostico; capabilities de surface nao concedem autoridade para provider, memoria ou runtime.
- Aliases legados de surface devem ser canonizados antes da selecao: `atlas_cli` para `atlas_cli_dev` e `atlas_api` para `atlas_api_interaction`.
- `flow_id` ou `domain_id` explicito invalido retorna `status=unresolved` com `error.code`, sem fallback silencioso.
- Fallback seguro e permitido apenas quando a selecao veio de UX implicita e o catalogo tem `general.answer`.
- Surface continua proibida de escolher provider, montar contexto/memoria ou chamar runtime diretamente.

Exemplos atuais:

| Surface | Domain/flow behavior |
|---|---|
| `atlas_cli_dev` | `debug/repair -> programming.repair`, `review -> programming.review`, `dev/plan -> programming.dev` |
| `atlas_cli_forge` | `heavy/forge/build/plan -> programming.forge` |
| `atlas_app` e `atlas_api_interaction` | aceitam selecao explicita vinda do catalogo e preservam metadata para o kernel |

---

## 12. Provider Driver Contract

Provider (Claude, Codex, Gemini, council, GPT, local) e plugin governado.

### 12.1 Contrato

```php
interface ProviderDriver
{
    public function id(): ProviderId;
    public function version(): string;

    public function declaredModels(): array;          // ["claude-opus-4-7", "claude-sonnet-4-6"]
    public function declaredCapabilities(): array;    // ["text", "vision", "long_context", "tool_use"]
    public function declaredLimits(): ProviderLimits; // tokens, files, context, rate

    public function health(): ProviderHealth;

    /**
     * Executa request governado.
     * Receipt obriga model + provider; driver nao escolhe.
     * Identity prompt e injetado pelo kernel; driver nao reescreve.
     */
    public function execute(
        ProviderExecutionRequest $request,
        IdentityFragment $identity,
        DecisionReceipt $receipt,
    ): ProviderExecutionResult;
}
```

### 12.2 Identity Injection

Driver recebe `IdentityFragment` (texto Atlas AI canonico extraido de `atlas-ai-master-prompt.md`). Driver e obrigado a injeta-lo. Kernel verifica via `ProviderComplianceTest` que cada driver:

1. Inclui identity no prompt enviado ao motor.
2. Nao adultera identity.
3. Retorna `provider_safe` outputs (sem leak de internal IDs do driver).

### 12.2.1 Estado Atual Do Driver Wrapper

O contrato executavel atual vive em:

- `app/Services/Ai/Kernel/Provider/ProviderPreparedRequestValidator.php`
- `app/Services/Ai/Kernel/Provider/ProviderDriverExecutionPlan.php`
- `app/Services/Ai/Kernel/Provider/ProviderDriverExecutionResult.php`
- `app/Services/Ai/Kernel/Provider/ProviderExecutionAudit.php`
- `app/Services/Ai/Provider/Drivers/*ProviderDriver.php`

Nesta etapa os drivers Claude, Codex, Gemini e Council sao wrappers seguros e auditaveis. Eles preparam request, injetam `IdentityFragment`, calculam `audit.request_hash`, validam via `ProviderPreparedRequestValidator` e retornam `ProviderDriverExecutionResult`. Eles **nao chamam processo real** e mantem `provider_real_execution_called=false`.

`ProviderDriverRegistry::manifest()` publica um snapshot sem side effects para integracao futura: provider id, classe do driver, modelos suportados, identity id/hash, delegate legado, modo de policy, algoritmo/canonicalizacao do request hash, suporte a dry-run e resultado de validacao do prepared request.

Regras enforced antes de qualquer boundary de execucao:

- identity fragment precisa existir, bater id/hash e vir no payload e no audit;
- prepared request precisa estar em `status=prepared`;
- `schema_version`, `provider_driver` e `supported_models` precisam bater com o driver executando;
- `audit.request_hash` precisa existir, declarar `request_hash_algorithm=sha256`, declarar `request_hash_canonicalization=provider_prepared_request.v1` e bater com o envelope canonico preparado (`provider`, `status`, `execution_policy`, `model`, `prompt`, `payload` e audit de identity/hash metadata);
- `prompt` e um snapshot do input operacional sem `payload` e sem `execution_policy`; o payload auditavel fica em `payload` e a policy normalizada fica somente em `execution_policy`;
- `ProviderDriver::legacyProviderClass()` declara o boundary legado esperado, e `execution_policy.delegates_to_legacy_provider` precisa bater com esse contrato;
- `execution_policy.mode=prepare_only`, `provider_real_execution_allowed=false` e `execution_policy.dry_run` precisam estar claros;
- `dry_run=true` no request preparado ou no contexto de execucao retorna status `dry_run` e nunca chega ao boundary legado; contexto nao pode rebaixar um request preparado como dry-run;
- Council preserva identity dos subproviders (`claude_cli`, `codex_cli`) no payload antes do hash.

Execucao real ainda permanece no caminho legado. A troca Claude/Codex/Gemini passa a ter contrato seguro de driver, mas a chamada efetiva de provider so deve ser plugada em sessao futura quando `AiWorker`/gateway puderem ser alterados com guard de DecisionReceipt.

### 12.3 Provider Health & Fallback

`ProviderRegistry` mantem health por driver. Decide consulta health antes de selecionar. Fallback e parte do receipt:

```php
final class ProviderAssignment
{
    public ProviderId $provider;
    public string $model;
    public ?ProviderId $fallback_provider;
    public ?string $fallback_model;
    public string $reason;
}
```

Fallback e **registrado** quando usado: `LedgerEventType::PROVIDER_FALLBACK` com motivo (`rate_limited`, `auth_expired`, `quota_exhausted`, `model_unavailable`, `health_degraded`).

---

## 13. Policy Engine Declarativo

`AtlasAiPolicyService` resolve `EffectivePolicy` por camadas. Codex menciona; aqui formalizo o algoritmo.

### 13.1 Camadas (precedencia decrescente)

1. **Hard kernel invariants** (nao podem ser sobrescritas)
2. **Tenant policy** (multi-tenancy ready)
3. **Operator preferences**
4. **Domain profile** (domain manifest defaults)
5. **Flow profile**
6. **Surface policy** (e.g. background tem policy mais conservadora que app interativo)
7. **Session override** (CLI flag, app temporary toggle)
8. **Operator hint** (suggestion, only respeitada se nao conflita com 1-7)

### 13.2 Algoritmo De Merge

```php
final class PolicyMerger
{
    public function merge(MergeInputs $inputs): EffectivePolicy
    {
        $base = HardKernelInvariants::baseline();
        $merge_receipt = MergeReceipt::start();

        foreach ($this->layersInOrder($inputs) as $layer) {
            $delta = $base->diffWith($layer->policy);
            foreach ($delta as $field => $candidate) {
                if (HardKernelInvariants::locks($field)) {
                    $merge_receipt->reject($field, $layer->id, 'locked_by_kernel');
                    continue;
                }
                if ($base->isSet($field) && $layer->precedence < $base->precedenceOf($field)) {
                    $merge_receipt->reject($field, $layer->id, 'lower_precedence');
                    continue;
                }
                $base->set($field, $candidate, $layer->id);
                $merge_receipt->accept($field, $layer->id);
            }
        }

        return new EffectivePolicy($base, $merge_receipt);
    }
}
```

### 13.3 Receipt Operacional

`EffectivePolicy` carrega `PolicyMergeReceipt` que diz, para cada campo, **qual camada definiu o valor final**. Isso aparece no audit log e no `policy_contracts` que ja existe na implementacao Programming.

### 13.4 Hard Kernel Invariants

```yaml
# kernel/invariants.yaml
- privacy_class.secret implies provider_safe = false  # always
- background autonomy <= A3 unless explicit human approval per run
- destructive tool requires approval unless tier in [T0, T1] AND read_only
- council provider list is intersection of allowed_providers across nodes
- operation expires_at - decided_at <= 30s for synchronous data plane
```

Invariants sao testaveis via `KernelInvariantTest` que tenta forjar policy violando-os e espera bloqueio.

---

## 14. Memoria E Contexto Como Servicos Kernel

Memory Core L1-L13 ja implementado. Aqui formalizo como servico kernel.

### 14.1 Atlas.Memory Contract

```php
interface AtlasMemory
{
    public function recall(RecallQuery $query): MemoryRecallResult;
    public function record(MemoryDeltaProposal $delta): MemoryRecordResult;
    public function project(ProjectionTarget $target): ProjectionResult;
    public function quality(WorkspaceContext $ctx): MemoryQualityReport;
    public function maintain(MaintainOptions $options): MaintainReport;
}
```

Usado por **todos os dominios** atraves do mesmo contrato. Personal Dev nao tem `PersonalMemory` separado; tem `MemoryProjection` para escopo `autobiographical`.

### 14.2 Atlas.Context Contract

```php
interface AtlasContext
{
    public function compose(ContextRequest $request): ContextPack;
    public function inject(OperationEnvelope $env, ContextPack $pack): InjectionReceipt;
}
```

`ContextPack` carrega:

- `task_summary`
- `memory_refs[]`
- `knowledge_refs[]`
- `code_refs[]`
- `tool_evidence_refs[]`
- `historical_traces[]`
- `attachments_refs[]`
- `budget_summary`
- `pack_hash`

Hash determinista permite dedupe e replay.

### 14.3 Open Brain Como Modo De Atlas.Context

Open Brain Context Injection ja existe. Formalmente, Open Brain e `AtlasContext::compose` com policy `auto`/`required`/`off` aplicada por surface/mode.

---

## 15. Tools Como Servico Kernel

Super Tool Runtime ja implementado em Fase 0. Aqui formalizo como **Atlas.Tools** core.

### 15.1 Atlas.Tools Contract

```php
interface AtlasTools
{
    public function plan(ToolPlanRequest $request): ToolPlan;
    public function execute(ToolPlan $plan, ApprovalContext $approval): ToolExecutionResult;
    public function evidence(EvidenceQuery $query): ToolEvidenceResult;
    public function gate(GateRequest $request): GateResult;
    public function release(ReleaseGateRequest $request): ReleaseGateResult;
}
```

Cada dominio que usa tools (Programming, Personal Dev measurement, Finance data scrapers) consome **a mesma instancia de Atlas.Tools**.

### 15.2 Tier T0-T3 Como Atributo De Plan

```php
final class ToolPlan
{
    public ToolId $tool;
    public RecipeId $recipe;
    public ExecutionTier $tier;       // T0..T3
    public SandboxMode $sandbox;
    public PrivacyLevel $privacy;
    public TaskType $task_type;
    public bool $requires_provider_safe;
}
```

Kernel rejeita execucao se `tier > policy.max_execution_tier` ou `sandbox < policy.required_sandbox`.

### 15.3 Authority Matrix Como Mecanismo Anti-Duplicacao De Findings

Implementado. Formalmente, `AtlasToolFindingCorrelationService` agrupa findings por `authority_group` e suprime duplicatas no gate, preservando-as no Evidence Store. Spec confirma.

---

## 16. Failure Domains

Codex tem prosa. Aqui formalizo como enum fechado com handler explicito.

```php
enum FailureDomain: string
{
    // Provider
    case PROVIDER_UNAVAILABLE         = 'provider_unavailable';
    case PROVIDER_RATE_LIMITED        = 'provider_rate_limited';
    case PROVIDER_AUTH_EXPIRED        = 'provider_auth_expired';
    case PROVIDER_QUOTA_EXHAUSTED     = 'provider_quota_exhausted';
    case PROVIDER_MODEL_UNAVAILABLE   = 'provider_model_unavailable';
    case PROVIDER_OUTPUT_UNSAFE       = 'provider_output_unsafe';

    // Policy
    case POLICY_VIOLATION             = 'policy_violation';
    case POLICY_BUDGET_EXCEEDED       = 'policy_budget_exceeded';
    case POLICY_AUTONOMY_INSUFFICIENT = 'policy_autonomy_insufficient';

    // Context
    case CONTEXT_BUDGET_EXCEEDED      = 'context_budget_exceeded';
    case CONTEXT_REQUIRED_MISSING     = 'context_required_missing';
    case CONTEXT_PRIVACY_BLOCKED      = 'context_privacy_blocked';

    // Tool
    case TOOL_NOT_INSTALLED           = 'tool_not_installed';
    case TOOL_REQUIRES_APPROVAL       = 'tool_requires_approval';
    case TOOL_TIER_OVER_BUDGET        = 'tool_tier_over_budget';
    case TOOL_SANDBOX_INSUFFICIENT    = 'tool_sandbox_insufficient';

    // Gate
    case GATE_FAILED_REPAIRABLE       = 'gate_failed_repairable';
    case GATE_FAILED_TERMINAL         = 'gate_failed_terminal';
    case GATE_NEEDS_REVIEW            = 'gate_needs_review';

    // Evidence
    case EVIDENCE_INCOMPLETE          = 'evidence_incomplete';
    case EVIDENCE_DRIFT_DETECTED      = 'evidence_drift_detected';

    // Domain
    case DOMAIN_NOT_REGISTERED        = 'domain_not_registered';
    case DOMAIN_FLOW_UNKNOWN           = 'domain_flow_unknown';
    case DOMAIN_GATE_MISSING          = 'domain_gate_missing';

    // Surface
    case SURFACE_CAPABILITY_MISSING   = 'surface_capability_missing';
    case SURFACE_PREFLIGHT_FAILED     = 'surface_preflight_failed';
}

interface FailureHandler
{
    public function handles(): array;     // FailureDomain[]
    public function handle(OperationEnvelope $env, FailureContext $ctx): HandlerOutcome;
}
```

Compliance test:

```php
public function test_every_failure_domain_has_at_least_one_handler(): void
{
    foreach (FailureDomain::cases() as $domain) {
        $handlers = $this->failureRegistry->handlersFor($domain);
        $this->assertNotEmpty($handlers,
            "FailureDomain::{$domain->name} has no registered handler. Add one or remove the case.");
    }
}
```

---

## 17. SLOs Operacionais

Sem medida, doutrina e aspiracional. Cada estagio kernel tem SLO publicado.

### 17.1 SLO Targets

| Estagio | p50 | p95 | p99 | Success Rate | Notes |
|---|---:|---:|---:|---:|---|
| envelope.create | 5ms | 15ms | 30ms | 99.9% | apenas validacao + ULID |
| input.normalize (text) | 5ms | 20ms | 50ms | 99.9% | |
| input.normalize (image) | 100ms | 400ms | 1000ms | 99.5% | hash, validate, store |
| intent.classify | 30ms | 150ms | 400ms | 99% | local rules; LLM exception |
| domain.resolve | 5ms | 20ms | 50ms | 99.9% | |
| flow.resolve | 5ms | 20ms | 50ms | 99.9% | |
| profile.resolve | 10ms | 30ms | 80ms | 99.9% | |
| policy.compile | 10ms | 50ms | 150ms | 99.9% | merge layers |
| context.compose (light) | 50ms | 200ms | 600ms | 99% | |
| context.compose (heavy) | 200ms | 1500ms | 5000ms | 98% | Open Brain + Engineering Pack |
| decide.issue | 30ms | 200ms | 500ms | 99.5% | |
| ledger.append | 5ms | 25ms | 50ms | 99.99% | hot path |
| gate.evaluate (T0/T1) | 100ms | 500ms | 2000ms | 99% | local tools |
| gate.evaluate (T2) | 5s | 60s | 5min | 95% | PR/review tools |

Status implementado: `KernelSloTarget` e `KernelSloAssessment` sao contratos
versionados (`atlas.kernel.slo_target.v1`) usados por `KernelSloTargets`.
Cada stage canonico declara p50/p95/p99, success rate, severidade e nota
operacional. O avaliador deterministico classifica observacoes como `ok`,
`warning`, `breach` ou `unknown_stage`, com violacoes nomeadas
(`latency_above_p95`, `latency_above_p99`, `stage_failed`,
`slo_stage_not_declared`). `atlas:ai:architecture-validate --json` publica
`schema_version` e a lista de stages SLO ativos, permitindo que Curator,
dashboards e CI comparem runtime real contra o contrato sem reler prosa.

### 17.2 SLO Violation Handling

- Slack/email/inbox alerta quando p95 ultrapassa target por 10 minutos.
- SLO budget consumido > 50% no mes inicia review automatico (Curator).
- SLO budget esgotado pausa promocao de mudancas naquela area ate ser restaurado.

---

## 18. Cost Model

Codex nao trata custo como cidadao primario. Aqui sim.

### 18.1 Cost Object

```php
final class CostSummary
{
    public TokenCost $provider_tokens;
    public Money $provider_dollar_estimated;
    public Duration $compute_seconds;
    public ToolCost[] $tool_runs;
    public StorageCost $storage_writes;
    public AttentionCost $human_attention;     // intervention required count, time blocked
}
```

`Money` e tipo nominal (`USD` ou `BRL`) com 6 casas decimais.

### 18.2 Cost Budget

`DecisionReceipt.budgets.cost` define teto. Executor monitora cumulativo. Excesso aciona handler:

- `T0/T1`: degrada modelo (Sonnet -> Haiku) ou reduz contexto
- `T2`: pausa, pede aprovacao
- `Background`: bloqueia, escala para inbox

### 18.3 Cost Per Outcome

Telemetria publica:

```text
intervention_reduction = baseline_interventions_per_green / atlas_interventions_per_green
cost_per_green_case_usd
cost_per_green_case_brl
attention_minutes_per_green_case
```

Esses sao os indicadores para a meta 5x descrita em `atlas-cli-5x-claude-code-plan.md`.

---

## 19. Versionamento E Compatibilidade

### 19.1 Schema Versions

Cada contrato carrega `schema_version`:

- `atlas.envelope.v1`
- `atlas.decide.v2`
- `atlas.policy.v2`
- `atlas.capability.v1`
- `atlas.domain.v1`
- `atlas.surface.v1`
- `atlas.provider.v1`
- `atlas.ledger_event.v1`

Bump de versao:

- **Aditivo (campo opcional novo)**: bump minor (`v2.1.x`). Compatibilidade preservada.
- **Renomeio/remocao**: requer deprecation cycle de 6 meses, schema_version major bump (`v3.x.x`).
- **Mudanca semantica de campo**: tratar como remocao + adicao.

### 19.2 Deprecation Cycle

| Mes | Acao |
|---|---|
| 0 | Marca deprecated, log warning ao consumir |
| 0-3 | Documenta migracao, fornece adapter |
| 3-6 | Warning vira erro em ambiente nao-prod |
| 6 | Remove suporte; consumers obsoletos falham fechado |

### 19.3 Migration Aditiva De Schema De DB

Lei do projeto (ja em CLAUDE.md):

- `Schema::create` guarda com `if (! Schema::hasTable())`.
- `Schema::table` adicionando coluna guarda com `if (! Schema::hasColumn())`.
- `CREATE INDEX` usa `IF NOT EXISTS`.
- `ADD CONSTRAINT` checa `pg_constraint`.
- Migration nunca e carimbada manualmente.

---

## 20. Multi-Tenancy Foundation

Mesmo com Vitor unico operador hoje, kernel e desenhado para multi-tenant.

### 20.1 Tenant Em Todo Campo Auditavel

`OperationEnvelope.operator.tenant_id` e obrigatorio. Eventos no Ledger carregam `tenant_id`. Tabelas particionadas por tenant. Memory escope inclui `tenant`.

### 20.2 Tenant Policy

Cada tenant pode ter:

- Provider whitelist proprio
- Cost budget proprio
- Privacy class default proprio
- Domain enable/disable proprio

Hoje: `tenant_id = "vitor"` em tudo. Amanha: trivial adicionar segundo operador sem refactor.

### 20.3 Cross-Tenant Isolation

- Memory recall nunca cruza tenant.
- Evidence Ledger query exige `tenant_id`.
- MCP write tools escopam por tenant.
- Provider drivers podem isolar credenciais por tenant.

Architectural test: query sem `tenant_id` em tabelas particionadas falha.

---

## 21. Identidade E Continuidade

### 21.1 Identity Fragment

`atlas-ai-master-prompt.md` extrai-se em `IdentityFragment` injetado em todo provider call.

```php
final class IdentityFragment
{
    public string $version;
    public string $core;            // identity prompt fragment
    public string[] $invariants;    // rules every provider must obey
    public string $operator_brief;  // operator-specific brief (compact)
    public string $hash;
}
```

### 21.2 Continuity Across Surfaces

Operacao iniciada em CLI pode ser retomada em App via:

- `parent_envelope_id` chaining
- `Memory.session_state`
- `atlas continue` reusing context_pack_hash

Test de continuidade: operacao iniciada em surface A, retomada em B, evidence packet final referencia ambos os envelopes.

### 21.3 Provider Swap Mid-Operation

Permitido se policy permitir. Receipt registra `PROVIDER_FALLBACK` com motivo. Identity preservada via injection. Estado intermediario migra via Memory.

---

## 22. Self-Evolution Loop

Atlas AI Curator opera **dentro do mesmo kernel**.

### 22.1 Curator Como Domain

`atlas-curator-domain.md` (a criar) define:

- intents: `detect_duplication`, `propose_capability_promotion`, `audit_slo_drift`, etc.
- flows: `curator.continuous_audit`, `curator.proposal`, `curator.measurement`
- orchestrator: `CuratorOrchestrator`
- runtime: `CuratorRuntime`
- privacy: opera sobre Evidence Ledger e estado interno; nao toca dados de operador externamente

### 22.2 Loop

```mermaid
flowchart LR
    L["Evidence Ledger<br/>(events)"]
    O["Curator Observer<br/>(queries patterns)"]
    P["Curator Proposer<br/>(generates change)"]
    G["Change Gates<br/>(review/sim/AB)"]
    A["Apply Change<br/>(via standard pipeline)"]
    M["Measure Outcome<br/>(was it good?)"]

    L --> O --> P --> G --> A --> L
    A --> M --> L
```

### 22.3 Mudancas Permitidas Por Curator

| Mudanca | Permitida automaticamente? |
|---|---|
| Promover memory delta `accepted` | Sim com confidence >= alta e gates |
| Atualizar threshold de tool authority policy | Sim em workspace; global exige operator |
| Propor flow profile | Nao; cria proposal + inbox |
| Promover capability de optional para required | Nao; cria proposal |
| Rotacionar default provider para flow X | Nao; cria proposal |
| Detectar e sinalizar SLO drift | Sim |
| Detectar surface sem capability obrigatoria | Sim, falha test arquitetural |
| Detectar decision drift | Sim, abre proposal |

Curator e o unico domain que pode propor mudancas em capability registry, domain registry, provider registry — mas todas passam por gates do mesmo kernel.

---

## 23. Anti-Padroes Formais

Cada anti-padrao tem teste arquitetural correspondente.

| ID | Anti-padrao | Teste |
|---|---|---|
| AP-1 | Surface chama provider direto | `KernelArchitectureStaticScanner` em `atlas:ai:architecture-validate` |
| AP-2 | Surface monta context proprio | `KernelArchitectureStaticScanner` em `atlas:ai:architecture-validate` |
| AP-3 | Comando decide provider | Static scan de selecao de provider em comandos sem ir via `AtlasDecide` |
| AP-4 | Capability presa a surface | `CapabilityComplianceTest` |
| AP-5 | Domain reimplementa Memory | Static scan de classes que mantem cache/store de memoria fora de `AtlasMemory` |
| AP-6 | Decision sem receipt | `KernelArchitectureStaticScanner` em `atlas:ai:architecture-validate` + testes de gateway/scout verificando receipt valido em trace, payload e metadata |
| AP-7 | Policy hardcoded | Static scan de `if (provider === 'X')` em codigo fora de `ProviderRegistry` |
| AP-8 | Failure sem handler | `test_every_failure_domain_has_at_least_one_handler` |
| AP-9 | Schema break sem deprecation | Linter de schema diff em CI |
| AP-10 | Tabela como fonte de verdade em vez de Ledger | Code review checklist + lint sobre projecoes |
| AP-11 | Background com autonomia A4-A5 sem human approval | Policy invariant test |
| AP-12 | Provider driver ignora identity ou abre execucao sem validator | `ProviderComplianceTest` + `KernelArchitectureStaticScanner` em `atlas:ai:architecture-validate` |
| AP-13 | Receipt invalido consumido por Data Plane | `DecisionReceiptRuntimeGuard` valida receipt v2 no Data Plane; `AiWorker` bloqueia receipt com schema invalido, `dry_run`, expirado, provider divergente ou modelo divergente antes de lookup/execucao do provider; `KernelArchitectureStaticScanner` em `atlas:ai:architecture-validate` verifica o guard dedicado e a ordem antes do provider |
| AP-14 | Tool T2/T3 em hot path | Tool Runtime bloqueia `execution_tier_above_policy_budget`; `AiGatewayService` bloqueia `execution_tier_hot_path_blocked` e `execution_tier_above_contract`; `KernelArchitectureStaticScanner` publica AP-14 |
| AP-15 | Memory secret entrando em provider | `AtlasMemoryPrivacyService::providerDecision` recalcula privacy class e blocklist antes de provider projection/Open Brain; `KernelArchitectureStaticScanner` verifica filtros provider-safe, redacao e ledger de bloqueio |
| AP-16 | SLO declarado mas nao medido | `KernelSloProbe` mede estagios, `AtlasEvidenceLedger::recordSloObservation` persiste `SLO_OBSERVED`; `KernelArchitectureStaticScanner` publica AP-16 |
| AP-17 | Pipeline Kernel chama provider/runtime real antes da migracao | `AtlasKernelPipeline` e `ScaffoldAtlasKernelPipeline` declaram stages, slots, plano, evidence/trace placeholders e `provider_execution_allowed=false`; `KernelArchitectureStaticScanner` verifica contrato e ausencia de bypass para providers/gateway/worker |

Cada AP e merge-blocking. CI roda todos.

---

## 24. Doutrina Como Teste Arquitetural

A doutrina deste kernel **e codigo**. Reside em `tests/Architecture/`.

### 24.1 Estrutura

```text
tests/Architecture/
  KernelStageWritesTest.php
  CapabilityComplianceTest.php
  DomainManifestValidationTest.php
  SurfaceAdapterContractTest.php
  ProviderDriverContractTest.php
  PolicyInvariantTest.php
  FailureCoverageTest.php
  AntiPatternStaticScanTest.php
  SLOTargetsDeclaredTest.php
  EnvelopeImmutabilityTest.php
  DecisionReceiptDeterminismTest.php
  LedgerAppendOnlyTest.php
  TenantIsolationTest.php
  SchemaVersionDeprecationTest.php
  IdentityInjectionTest.php
```

### 24.2 Run

```bash
/opt/homebrew/bin/php artisan test --testsuite=Architecture
```

CI bloqueia merge se algum teste falhar.

### 24.3 Doutrina Exposta

```bash
atlas kernel doctrine --json
```

Retorna manifesto de invariantes, capabilities, domains, surfaces, providers e SLOs em vigor — para usar em proposal review do Curator.

---

## 25. Roadmap De Implementacao

Implementacao incremental, sem big-bang. Cada fase termina commitavel.

### Fase 0 — Promocao Documental (2-3 dias)

Fundamentos textuais ja implicitos no codigo, agora canonizados:

- Promover specs maio 2026 (`resolver-o-que-vale-a-pena/docs/specs/*`) para KB com aviso de redirect (ja parcialmente feito por Codex como referencias).
- Mover fisicamente os 4 specs P0 para `engineering-knowledge-base/`.
- Promover `Atlas_Gaps_Achamos_Nao_Esquecer.md` como fonte de Personal Dev domain.
- Rodar `atlas engineering knowledge sync --prune` + `index-code --prune`.

### Fase 1 — Operation Envelope Tipada

Status atual: implementado como base tipada inicial. `OperationEnvelopeFactory`
cria envelopes tipados, preserva compatibilidade com traces/jobs atuais e emite
`ENVELOPE_CREATED` no Evidence Ledger.

Proximos incrementos:

- Manter compatibilidade com `AiTrace`/`AiJob` legados (envelope projeta para tabelas atuais).
- Expandir adapters de surfaces existentes (`AiChatCommand`, `AtlasCliDevCommand`, `AtlasAiSheet`) quando cada surface migrar totalmente.
- Ampliar `tests/Architecture/EnvelopeImmutabilityTest`.

### Fase 2 — Decision Receipt v2 Determinista

Status atual: implementado como receipt tipado via `DecisionReceiptIssuer`, com
`dryRun`, `signedBy`, integracao em `AtlasDecideService` e payload deterministico
tipado, nao estrutura ad hoc. `AiGatewayService` propaga `decision_receipt` para
trace e jobs, incluindo jobs normais, scout e council (`claude_codex`), para que
ledger/replay/auditoria por job nao dependam de fallback para trace.
`DecisionReceiptRuntimeGuard` aplica a regra do Data Plane: receipt v2 com
`schema_version` invalido, `dry_run=true`, `expires_at` ausente/invalido ou
expirado e bloqueado antes de qualquer lookup/execucao de provider. O mesmo
guard valida `provider_selection.primary` e `provider_selection.model` contra o
provider/model reais do job, com excecoes formais para council (`claude_codex`
delegando para `claude_cli`/`codex_cli`) e para scout (`context_scout` usando
`gemini_cli`). Esse guard tem teste unitario dedicado, teste de integracao no
worker e tambem e verificado por static scan AP-13.

Proximos incrementos:

- Promover validacao de assinatura/hash do receipt para o mesmo guard quando o
  replay forte passar a rejeitar divergencias de hash em runtime.
- Ampliar `tests/Architecture/DecisionReceiptDeterminismTest`.

### Fase 3 — Evidence Ledger Append-Only

Status atual: implementado como base append-only com `atlas_ledger_events`,
`AtlasEvidenceLedger`, taxonomia inicial e `ENVELOPE_CREATED` emitido pela
`OperationEnvelopeFactory`. Decide emite `DECISION_ISSUED`.

Proximos incrementos:

- Projecao de `ai_traces`, `atlas_engineering_runs`, `atlas_tool_runs` derivada do ledger via worker.
- Ampliar `tests/Architecture/LedgerAppendOnlyTest`.
- Evoluir replay tool: `atlas ledger replay --envelope=<id>`.

### Fase 3.5 — Pipeline Kernel Scaffold

Status atual: implementado como contrato inicial em
`app/Services/Ai/Kernel/Pipeline/`. O scaffold declara a ordem completa do
pipeline, reads/writes por stage, plano auditavel, placeholders de evidence e
trace, e compliance report. Ele nao executa provider, runtime real, worker,
gateway ou adapters concretos.

Proximos incrementos:

- Conectar `OperationEnvelopeFactory`, `DecisionReceiptIssuer` e
  `AtlasEvidenceLedger` ao pipeline real por adapters, mantendo compatibilidade.
- Criar adapters finos para surfaces existentes em PRs separados.
- Migrar `atlas dev`/`forge`/`chat` apenas depois de testes de paridade,
  observabilidade e rollback.

### Fase 4 — Capability Registry Executavel (3-5 dias)

- `app/Kernel/Capability/CapabilityRegistry`.
- 5 capabilities iniciais com manifest: `atlas.input.text`, `atlas.input.image_paste`, `atlas.input.file_attachment`, `atlas.memory.recall`, `atlas.context.compose`.
- `CapabilityComplianceTest` no Architecture suite.
- Resolve a primeira regressao: paste-image em todas surfaces obrigatorias.

### Fase 5 — Domain Manifest E SDK (5-7 dias)

- Schema do manifest.
- `DomainRegistry` carregando manifests no boot.
- Programming domain como primeiro consumer (refactor de `AtlasProgrammingOrchestrator` para implementar `DomainOrchestrator`).
- Validador (`atlas domain validate <id>`).

### Fase 6 — Surface Adapter Contract

Status atual: contrato `SurfaceAdapter` existe como interface tipada do Kernel.
`KernelArchitectureStaticScanner`, exposto por
`atlas:ai:architecture-validate`, faz static scan AP-1 para impedir que a
camada Surface importe/chame providers, gateway ou worker diretamente, e AP-2
para impedir que Surface monte context pack ou memory projection propria. O
mesmo contrato tambem e coberto por `KernelContractComplianceTest`.

Proximos incrementos:

- Adaptar CLI, App, Worker, API, MCP e Background progressivamente.
- Expandir static scan para CLI command entrypoints fora de `app/Services/Ai/Surface`
  conforme cada entrypoint migrar para adapter formal.

### Fase 7 — Provider Driver Contract

Status atual: contratos `ProviderDriver` e `IdentityFragment` existem como
interfaces/value objects do Kernel. `claude_cli`, `codex_cli`, `gemini_cli` e
`claude_codex` ja possuem drivers formais registrados em
`ProviderDriverRegistry`. `AtlasProviderIdentityProjector` injeta uma projecao
canonica do master prompt em cada provider request, com fallback auditavel se o
Vault nao estiver disponivel. Provider ainda deve ser tratado como motor, nao
identidade do Atlas. `KernelArchitectureStaticScanner`, exposto por
`atlas:ai:architecture-validate`, faz static scan AP-12 para impedir que wrappers
abram execucao real sem identidade e validator. O mesmo contrato tambem e
coberto por `KernelContractComplianceTest`.

Proximos incrementos:

- Ligar execucao real ao wrapper sem permitir bypass do `ProviderDriver`.
- Expandir `ProviderComplianceTest` com health, limits e capabilities declaradas.

### Fase 8 — Failure Domains E Handlers

Status atual: `FailureDomain` e `FailureHandler` existem como base.

Proximos incrementos:

- Registrar handlers concretos para cada caso.
- `FailureCoverageTest`.

### Fase 8.1 — Tool Tier Hot Path Guard

Status atual: AP-14 esta executavel em duas camadas. O Super Tool Runtime ja
bloqueia ferramentas acima do budget por `execution_tier_above_policy_budget`.
O Gateway agora tambem projeta `requested_execution_tier`,
`max_execution_tier` e `hot_path` dentro do receipt de tool contracts, bloqueia
T2/T3 em hot path interativo com `execution_tier_hot_path_blocked`, e bloqueia
pedido acima do contrato com `execution_tier_above_contract`. O validator
arquitetural publica `ap14_tool_tier_hot_path`. Antes de lançar
`atlas_tool_contract_policy_violation`, o Gateway registra o bloqueio no
Evidence Ledger via `recordPolicyContractBlocked('programming.tools', ...)`,
com `OPERATION_BLOCKED`, `emitter_stage=atlas.policy_contract` e
`violation_code=programming.tools.<status>`.

Proximos incrementos:

- Expor contadores por surface para detectar surfaces tentando promover tool
  pesada no caminho interativo.

### Fase 9 — SLO Telemetry

Status atual: SLO targets estao declarados no Kernel como contratos
versionados, com success rate, severidade e avaliador deterministico.
`KernelSloProbe` mede blocos reais, classifica a observacao via
`KernelSloAssessment` e persiste `SLO_OBSERVED` no Evidence Ledger por
`recordSloObservation()`. Os primeiros hot paths instrumentados sao
`context.compose` (`AiContextPackBuilder::build`), `provider.prepare`
(preparacao + validacao do provider contract), `decide.issue` (emissao do
`DecisionReceipt v2`), `runtime.execute` (`AiWorker` envolvendo a chamada real
ao provider), `repair.loop` (native programming repair no `AiWorker`) e
`gate.evaluate` (`AtlasToolGateService::evaluate`), `learning.project`
(`AtlasMemoryLearningPromotionService`) e `output.render`
(`BaseSurfaceAdapter::renderOutput`).
`atlas:ai:architecture-validate --json` expoe `schema_version`, stages ativos e
o static scan `ap16_slo_observability`. `AtlasLedgerReplayService` e o read
model reutilizavel para replay/projecoes: `atlas:ai:ledger <envelope> --slo
--json` e `GET /ai/ledger/{envelope}?slo=1` usam
`sloReportForEnvelope()` para transformar eventos `SLO_OBSERVED` em resumo
operacional por envelope: contagem, sucesso, falha, pior status/severidade,
p50/p95/max por stage e violacoes agregadas. `AtlasEvidenceLedger` permanece
focado em append/replay bruto; a projecao vive no replay service para evitar
misturar escrita canonica com dashboard/curadoria. A mesma camada tambem
publica `repairReportForEnvelope()`: `atlas:ai:ledger <envelope> --repair
--json` e `GET /ai/ledger/{envelope}?repair=1` transformam
`REPAIR_INITIATED`/`REPAIR_COMPLETED` em resumo de Repair Loop por envelope,
incluindo initiated/completed count, executed count, status/strategy/reason
counts, latest status/strategy, flag `requires_human_review` e eventos recentes
com `causation_id`, `decision_hash` e `result_hash`. Isso da ao operador,
Curator e Self-Improvement uma leitura canonica de repair sem consultar payload
raw nem recriar logica por surface. A mesma camada publica
`sloReportForWindow()` para agregacao por janela: envelope count, status
counts, success/failure, resumo por stage, dimensoes agregadas e
`recent_breaches`. Cada observacao SLO pode carregar dimensoes canonicas
provider-safe (`domain`, `flow`, `surface_id`, `provider`, `model`, `runtime`,
`tool_id`), permitindo dashboards por dominio/surface/provider/model sem ler
payloads privados nem transformar tabela operacional em fonte de verdade. O
payload `GET /ai/observability` agora inclui `kernel_slo`, `kernel_repair` e
`self_improvement_schedule`,
permitindo dashboard e Self-Improvement/Curator enxergarem drift por janela e
padroes de repair sem reimplementar queries. `self_improvement_schedule` vem de
`AtlasSelfImprovementScheduleService` e publica enabled/time/flows/commands,
`configured_flows`, `invalid_flows`, `defaulted`, `timezone`, `next_run_at` e
`health` do ciclo recorrente efetivo, incluindo o default `nightly_review` +
`repair_loop_review`. O payload tambem publica `plan_hash` com algoritmo
`sha256`, calculado sobre a configuracao efetiva e health issues, mas sem
depender de `next_run_at`, permitindo detectar drift entre CLI, API,
observability e cron sem confundir mudanca natural de data. O scheduler real em
`bootstrap/app.php` consome `scheduledCommands()` e aplica explicitamente
`dailyAt(time)` + `timezone(timezone)` do mesmo contrato somente quando
`schedulable=true`. Schedule desligado, horario invalido ou timezone invalida
permanece auditavel em plano/health, mas nao vira registro real no cron. O bloco
`scheduler_registration` explicita `registered_command_count` e
`skipped_reason` para dashboards e CI distinguirem plano auditavel de registro
real. Assim configuracao
errada fica visivel em CLI, API e observability, sem execucao silenciosa. Horario invalido gera
`invalid_self_improvement_schedule_time`, `health.status=warning` e
`next_run_at=null`; timezone invalida gera
`invalid_self_improvement_schedule_timezone` com o mesmo bloqueio de registro.
`health.status` diferencia `healthy`, `warning` e
`disabled`, com issues e next actions para operacao. O mesmo comando aceita
`--fail-on-schedule-warning`, permitindo health gate em CI/cron sem criar
`AtlasInitiativeRun` nem executar runtime. `--schedule-health` e a API
`GET /ai/self-improvement/schedule/health` expoem o mesmo estado em formato
compacto para shell, App, mobile, cron e monitors que nao precisam carregar
comandos ou configuracao completa.
`kernel_repair` vem de
`repairReportForWindow()` e agrega repair event count, envelope count,
initiated/completed, executed count, status/strategy/reason counts,
latest status/strategy, `requires_human_review` e eventos recentes. O operador
tambem pode usar `atlas:ai:slo --hours=24 --json` para inspecionar SLO por
janela e `atlas:ai:repair-report --hours=24 --json` para inspecionar o Repair
Loop por janela sem carregar todo o payload de observability. A API operacional
equivalente e `GET /ai/repair/report?hours=24`, consumindo o mesmo read model
e retornando `kernel_repair`. O report de Repair Loop tambem aceita filtros
operacionais por `status`, `strategy`, `failure_domain` e `emitter_stage` no
CLI e API, por exemplo `atlas:ai:repair-report --strategy=human_review --json`
ou `GET /ai/repair/report?strategy=human_review&failure_domain=compliance.violation`.
Isso permite que Curator, dashboard e operador isolem padroes de repair sem
reconsultar payload raw nem criar queries paralelas.
`GET /ai/slo` publica a mesma projecao como API operacional autenticada, com
filtros por `domain`, `flow`, `surface_id`/`surface`, `provider`, `model`,
`runtime` e `tool_id`/`tool`. Isso cria uma interface unica para dashboard,
Curator, mobile e scripts sem duplicar consultas ao ledger. O dominio
`self_improvement` tambem consome esses filtros em
`atlas:ai:self-improve --flow=provider_performance_review`, permitindo abrir
proposals de SLO drift por dominio/provider/model em vez de gerar um alerta
global sem dono claro.
`AtlasSelfImprovementRuntime` consome esse read model nos flows noturnos e de
provider performance para gerar proposals revisaveis de SLO drift, mantendo
autonomia baixa: detectar e propor, nunca alterar target/runtime automaticamente.
O mesmo runtime agora consome `repairReportForWindow()` para abrir findings
revisaveis quando o Repair Loop acumula `human_review`, bloqueios/exhaustion ou
estrategias repetidas. Isso fecha o ciclo: repair gera ledger, replay projeta,
observability mostra e Self-Improvement transforma padrao em proposta sem
autoaplicar mudanca critica. Para operacao direcionada, o flow dedicado
`self_improvement.repair_loop_review` consome apenas evidencia de Repair Loop e
aceita os mesmos filtros (`repair status`, `strategy`, `failure_domain`,
`emitter_stage`), preservando-os em `runtime.filters` e
`finding.metadata.filters`. `tool_runtime_review` pode agregar esse mesmo sinal,
mas a curadoria de repair tem executor proprio (`repair_loop_review_runtime`)
para evitar heuristicas paralelas ou mistura acidental com gates/tools.

Proximos incrementos:

- Dashboard visual para dimensoes SLO.
- Alertas.

### Fase 9.1 — Provider Memory Privacy

Status atual: AP-15 esta executavel no hot path de projection. `AtlasMemoryPrivacyService`
nao confia em colunas legadas isoladas: `providerDecision()` recalcula
`privacy_class`, aplica `block_external_ai_for_sensitivity`, bloqueia `secret`
sempre, respeita `metadata.privacy.external_ai_allowed=false` e retorna uma razao
auditavel. `providerAllowed()` e apenas o boolean derivado dessa decisao.
`AtlasProviderProjectionService` registra cada memoria bloqueada no Evidence
Ledger via `recordProviderMemoryBlocked()`, sem gravar titulo, body ou resumo no
payload do evento. `atlas_memory_get` no Open Brain MCP usa a mesma decisao e
tambem registra bloqueios provider-facing. `AtlasHybridMemoryRetrievalService`
continua obrigado a filtrar entradas por `providerAllowed($entry)`, e os campos
expostos ao provider usam fallbacks redigidos (`providerTitle`,
`providerSummary`, `providerBody`). `KernelArchitectureStaticScanner` publica
`ap15_provider_memory_privacy` no architecture validator.

Proximos incrementos:

- Criar amostragem Curator para procurar entradas antigas com privacy drift.
- Expor metricas de memoria provider-safe por dominio/surface.

### Fase 10 — Domain Expansion: Personal Dev / Finance / Self-Improvement

Status: Finance e Personal Development ja foram promovidos para `ready 9/9`.
Curator domain dedicado ainda precisa ser separado do Self-Improvement quando
for necessario distinguir curadoria de produto, governanca e evolucao noturna.

Cada novo dominio:

- Manifest.
- Orchestrator(s).
- Gates especificos.
- Memory projection.
- Test suite.

### Fase 11 — Multi-Tenancy Hardening (3-5 dias)

- `tenant_id` propagado.
- Test de isolation.
- Particionamento de tabelas.

### Fase 12 — Self-Evolution Curator (n+ dias)

- Curator dedicado se e quando precisar separar governanca/proposals do dominio
  implemented/ready `self_improvement`.
- Continuous audit jobs.
- Proposal inbox.

---

## 26. Definition Of Done Da Arquitetura

A arquitetura mae esta em estado **production-grade kernel** quando:

1. Todos os 26 testes arquiteturais (esta spec lista 15+; mais virao com fases) passam em CI.
2. Toda surface declara capabilities suportadas via token; capability missing falha preflight.
3. Toda decisao real emite `DecisionReceipt` valido com hash + chain.
4. Evidence Ledger e fonte de verdade; tabelas projetadas reconstruivel.
5. Pelo menos 2 dominios (Programming + Personal Dev) implementam `DomainOrchestrator` via manifest.
6. Pelo menos 4 providers (Claude, Codex, Gemini, Claude+Codex council) implementam `ProviderDriver`.
7. Failure domains tem handler para todos os 30+ casos.
8. SLOs publicados, medidos e dashboard ativo.
9. Cost model integrado a budget de receipt.
10. Multi-tenancy foundation propagada (mesmo com unico operador).
11. `self_improvement` ou um Curator dedicado audita ledger e gera proposals.
12. Doutrina executavel via `atlas kernel doctrine --json`.
13. Documentacao da spec mae sincronizada via `atlas engineering knowledge sync`.
14. Operator consegue dizer "atlas dev", "atlas finance review", "atlas reflect" e cada um passa pelo mesmo kernel sem que o operador note diferenca arquitetural.

---

## 27. Glossario Kernel

| Termo | Definicao |
|---|---|
| **Atlas AI Kernel** | Camada Layer 1 que materializa axiomas em codigo enforcado |
| **Operation Envelope** | Unidade canonica que flui pelo kernel; tipada, append-only |
| **Decision Receipt** | Contrato assinado emitido por `AtlasDecide`; autoriza Data Plane |
| **Evidence Ledger** | Event store append-only; fonte de verdade da execucao |
| **Capability** | Funcionalidade horizontal declarada e testada em compliance |
| **Domain Manifest** | Arquivo declarativo que registra um dominio Atlas AI |
| **Surface Adapter** | Plugin que conecta uma superficie de operador ao kernel |
| **Provider Driver** | Plugin que conecta um motor de IA externo ao kernel |
| **Effective Policy** | Resultado do merge de camadas de policy; resolvido por `AtlasAiPolicyService` |
| **Identity Fragment** | Fragmento de prompt canonico Atlas AI injetado em todo provider call |
| **Control Plane** | Estagios sem efeito colateral externo (intent, policy, decide) |
| **Data Plane** | Estagios com efeito governado (executor, tools, provider, gates) |
| **Failure Domain** | Categoria fechada de falha com handler nomeado |
| **Tenant** | Escopo de isolamento; default unico em single-operator |
| **Authority Group** | Grupo de tools sobrepostas onde uma e primaria |
| **SLO** | Service Level Objective; alvo medido por estagio kernel |
| **Architectural Test** | Teste que falha merge se doutrina e violada |
| **Hard Kernel Invariant** | Regra que nenhuma policy/profile/override pode quebrar |
| **Curator** | Dominio que opera self-evolution sobre o ledger |
| **Replay** | Re-emissao deterministica de eventos do ledger |
| **Preview** | Execucao com `dry_run=true` que produz receipt sem efeito colateral |

---

## Apendice A — Comparacao Com Documentos Existentes

Esta spec **nao substitui** documentos existentes. Ela os enraiza.

| Documento existente | Papel apos kernel spec |
|---|---|
| `atlas-ai-vision.md` | Capa mental (porta de entrada humana) |
| `atlas-ai-pipeline.md` | Resumo prosa do pipeline; este spec define os tipos |
| `atlas-ai-core-vs-domain.md` | Regra de fronteira; este spec define como enforcar |
| `atlas-ai-operating-system.md` | Topology e ownership; este spec define como cada peca encaixa |
| `atlas-ai-architecture-audit.md` | Diagnostico; este spec define o estado-alvo |
| `atlas-ai-resolver-corpus-audit.md` | Triagem do corpus legacy |
| `atlas-ai-operating-system.md` + `atlas-ai-core-vs-domain.md` | Especializacao desta spec para domains |
| `domains/programming.md` | Especializacao canonica desta spec para Programming domain |
| `app/Services/Ai/Kernel/Pipeline/*` | Contrato/scaffold executavel do pipeline unificado; ainda nao migra fluxos reais |
| `atlas-ai-kernel-architecture.md` + `atlas-ai-operating-system.md` + `atlas-ai-pipeline.md` | Detalhamento canonico atual do estagio Decide |
| `atlas-ai-layer-0-glossary.md` | Source canonico enxuto da `IdentityFragment` e glossario humano; este spec mantem glossario kernel |
| `atlas-ai-layer-0-glossary.md` | Leis/identidade Atlas AI extraidas em forma operacional enxuta |
| `super-tool-runtime-core.md` | Implementacao concreta de `Atlas.Tools` |
| `engineering-blueprint.md` | Specializacao de gates/evidence para Programming |
| `code-intelligence.md` | Implementacao de code refs em context pack |
| `open-brain-context-injection.md` | Implementacao concreta de `Atlas.Context` policy auto/required/off |
| `obsidian-atlas-vault.md` | Espelho humano; nao e fonte primaria do kernel |
| Memory Core docs (5) | Implementacao concreta de `Atlas.Memory` |

## Apendice B — Mapping Estado Atual -> Estado Kernel

Para cada area do codigo atual, indica como ela se torna parte do kernel.

| Codigo atual | Papel no kernel |
|---|---|
| `AtlasDecideService` | Decide engine atual que integra o `DecisionReceiptIssuer` e emite `DECISION_ISSUED`; renomear para `AtlasDecideEngine` e apenas refinamento futuro de nomenclatura/ownership |
| `app/Services/Ai/Kernel/Envelope/*` | Primeira implementacao do `OperationEnvelope` tipado e factory canonica |
| `DecisionReceiptIssuer` | Primeira implementacao executavel do `DecisionReceipt v2` hashable e chainable |
| `AtlasCapabilityRegistry` | Primeira implementacao executavel do Capability Registry baseada em `config/atlas_ai.php` |
| `AtlasDomainManifestValidator` | Primeira implementacao executavel do Domain Manifest/Profile compliance |
| `AtlasDomainOrchestrator` + `AtlasDomainOrchestratorRegistry` | SDK minimo de dominios: nomes curtos de manifest resolvem para classes PHP reais, maturidade e suporte declarado por domain/flow |
| `AtlasAiDomainCatalogService` | Service compartilhado que monta o inventario validado de domains/flows/orchestrators para CLI e API sem duplicacao |
| `AtlasDomainOnboardingScorecard` | Scorecard de onboarding por dominio com 9 fases: charter, profile, context, orchestrator, runtime, gates, learning, surface e maturity_gate; Programming, Self-Improvement, Finance e Personal Development estao implemented/ready; Marketing e os demais dominios listados permanecem scaffold/catalog-ready ate existir runtime/orchestrator proprio |
| `atlas:ai:architecture-validate` | Verificacao operacional dos contratos executaveis de Capability Registry, Domain Orchestrator Registry e Domain/Profile Registry |
| `atlas:ai:domains` + `GET /ai/domains` | Inventario operacional de domains, flows e orchestrators; expoe maturidade, runtime, autonomia, executor preference, onboarding scorecard e validacao em JSON/humano |
| `programming.*` flow profiles | Programming declarado no registry com dev, repair, review, refactor, qa, security, database, visual e forge; todos os flows declaram context policy, memory/learning policy, gate policy e surfaces |
| `marketing.*` flow profiles | Marketing declarado como scaffold/catalog-ready com 15 flows canonicos alvo; nao e implemented/ready ate existir runtime/orchestrator proprio |
| `finance.*` flow profiles | Finance declarado no registry com 10 flows enterprise analysis-only, gates de compliance/source/risk, memoria provider-safe, tool policy read-only e bloqueio de qualquer execucao de mercado |
| `personal_development.*` flow profiles | Personal Development declarado no registry com 10 flows privados plan-only, memoria privada/redacted, gates non-clinical/privacy/no-diagnosis e proibicao de mutacao automatica de calendario/tarefas |
| `AtlasEvidenceLedger` + `atlas_ledger_events` | Primeira implementacao append-only do Evidence Ledger para eventos de kernel |
| `AiWorker` + `atlas:ai:ledger` | Primeira ponte runtime/provider/gate/repair para o ledger: execution started, provider called/returned, gate evaluated/passed/blocked, repair initiated/completed, terminal operation events e replay por envelope |
| `EngineeringHarnessRunnerService` | Ponte do Engineering Harness para o ledger: execution started, context composed, provider returned e terminal operation event por engineering run |
| `AtlasToolEvidenceStore` / `AtlasToolGateService` | Ponte do Super Tool Runtime para o ledger: tool evidence recorded e gate events por envelope/contexto |
| `AtlasSelfImprovementOrchestrator` + `AtlasSelfImprovementRuntime` + `AtlasSelfImprovementScheduleService` + `atlas:ai:self-improve` + `GET /ai/self-improvement/schedule` | Primeira implementacao ready do Curator/Self-Improvement sobre o ledger: resolve profile do dominio, emite plano para 11 flows especializados, suporta `--list-flows`, `--schedule-plan` e `--plan-only`, executa reviews por flow, registra initiative run, learning proposals e proposals seguras opcionais; declara context, gates, learning e surfaces scheduler/CLI/API/app com agendamento multi-flow. O default recorrente agenda `nightly_review` e `repair_loop_review` |
| `AtlasAiPolicyService` | Implementacao do Policy Engine declarativo; inclui guard rails de kernel para `programming.repair` -> `dev_repair_executor` e flows Programming de harness -> `engineering_harness` |
| `AiGatewayService` | Adapter entre kernel e camada legacy de jobs |
| `AtlasOpenBrainContextInjectionService` | Implementacao concreta de `Atlas.Context` mode auto/required/off |
| `EngineeringContextPackService` | Specializacao de `Atlas.Context` para Programming |
| `AtlasMemoryRegistryService` | Backbone de `Atlas.Memory` |
| `EngineeringHarnessRunnerService` | Executor heavy de Programming domain |
| `AtlasProgrammingOrchestrator` | Implementacao de `DomainOrchestrator` para Programming; resolve `programming_flow` por profile/intent/task signals e gera plano para `atlas dev`, `atlas:ai:chat --dev` com ou sem `--dev-plan`, Forge/Harness, repair, review, refactor, qa, security, database, visual e retomada |
| `AtlasProgrammingSurfaceCommandBuilder` | Builder compartilhado que traduz surfaces Programming (`fix`, `continue`) para `atlas:cli:dev` com flags canonicas |
| `AtlasCliFixCommand` / `AtlasCliContinueCommand` | Surfaces finas de Programming: fix marca intent `repair`; continue retoma `dev_execution_plan` ou `programming_session_plan` preservando profile/model/intent via builder compartilhado |
| `AtlasEffectivePolicyComposer` | Composer de policy contract; `dev_repair_executor` deriva contrato `workspace_write` em vez de read-only |
| `AtlasToolRegistryService` + Policy + Executor + EvidenceStore + Gate + ReleaseGate + AuthorityMatrix | Implementacao completa de `Atlas.Tools` |
| `AtlasOpenBrainMcpService` (21 tools) | Adapter MCP do kernel |
| `AtlasVaultManagedNoteService` + Sync | Adapter da Human Knowledge Surface / Personal Knowledge Workspace: Vault humano (Obsidian) |
| `AiTrace` / `AiJob` | Projecao do Evidence Ledger |
| `atlas_engineering_runs` | Projecao do Evidence Ledger para Programming |
| `atlas_tool_runs` | Projecao do Evidence Ledger para Tools |

## Apendice C — Anatomia De Um Bug Que Nao Existe Mais

### Bug-Atlas-2026-05-04: paste-image so em `atlas ask`

**Sintoma**: capability multimodal funcionou em `atlas ask` mas nao em `atlas dev`.

**Causa raiz arquitetural**: capability nasceu em `AiChatCommand` (codigo de surface), nao em `Atlas.Input` (kernel core).

**Como o kernel previne**:

1. `capabilities/atlas.input.image_paste.yaml` declara `required_surfaces: [atlas_cli, atlas_app, atlas_api]`.
2. `CapabilityComplianceTest` falha se `atlas_cli` (que cobre `atlas ask`, `atlas dev`, `atlas chat`, `atlas continue`, `atlas forge`, `atlas fix`) nao implementa.
3. PR que adiciona suporte a paste em `atlas ask` apenas e bloqueado em CI ate todas as surfaces obrigatorias terem suporte ou `not_supported.reason` valido.
4. Implementacao real vive em `Atlas.Input.ImagePasteCapability` (kernel), com teste em `tests/Capability/ImagePasteCliTest.php`. Surfaces apenas chamam o kernel.

Resultado: o bug nao chega a producao.

---

## Apendice D — Resposta Direta Ao Desafio

Esta secao existe porque a spec foi escrita em resposta a:

> "preciso que vc faca uma documentacao mais profissional e super enterprise e ganha do claude"

Codex entregou:

- 5 docs de **topologia** (vision, pipeline, core-vs-domain, operating-system audit, resolver corpus audit)
- ~1.500 linhas no total
- Sintese boa do corpus existente
- Pipeline conceitual descrito em prosa + diagrama

O que faltou em Codex:

1. **Tipos formais** — ele tem JSON shapes, nao interfaces tipadas
2. **Maquinas de estado** — sem state machine para Operation, Receipt, Tool Run
3. **Operation Envelope como unidade canonica** — pipeline dele e funcoes encadeadas; nao define o objeto que flui
4. **Decision Receipt determinista, hashable, chainavel** — Codex tem campos sugeridos, nao algoritmo de hash/chain
5. **Evidence Ledger event-sourced** — Codex menciona evidence, nao define event store
6. **Capability Registry executavel** — Codex documenta o principio, nao o mecanismo de teste arquitetural
7. **Domain Manifest com SDK** — Codex diz "domain plug in", nao define o plugin contract
8. **Surface Adapter Contract** — Codex diz "surface chama pipeline", nao define o adapter contract
9. **Provider Driver Contract com Identity Injection** — Codex menciona providers, nao formaliza driver
10. **Failure Domains como enum fechado com handler** — Codex tem prosa de erros, nao taxonomia
11. **SLOs operacionais quantificados** — Codex nao quantifica
12. **Cost Model como cidadao primario** — Codex nao enderica
13. **Versionamento e Compatibilidade** — Codex nao trata
14. **Multi-Tenancy Foundation** — Codex nao trata
15. **Architectural Tests como doutrina enforcada** — Codex menciona test, nao define suite

Esta spec entrega os 15 pontos. Cada um e materializado em codigo, tipo, schema, teste ou contrato — nao em prosa.

A diferenca pratica:

| Pergunta | Resposta Codex | Resposta Kernel |
|---|---|---|
| "Como evito que paste-image fique so em uma surface?" | "Capability registry com test arquitetural" (prosa) | `capabilities/atlas.input.image_paste.yaml` + `CapabilityComplianceTest` (codigo) |
| "Como adiciono dominio Personal Dev?" | "Cria orchestrator e plugue na pipeline" (prosa) | Cria `domains/personal_development.yaml` + classes + `atlas domain validate` (mecanismo) |
| "Como sei que Decide nao foi pulado?" | "Sempre passe por Decide" (regra) | `DecisionReceipt` obrigatorio para Data Plane; sem receipt, executor recusa (lei de tipo) |
| "Como replico bug em producao?" | "Olhe os logs" | Replay completo a partir do Evidence Ledger por `envelope_id` |
| "Como sei que Atlas e seguro?" | "Tem privacy gate" (regra) | `KernelInvariantTest` + `PrivacyInvariantTest` rodam em CI |
| "Como add provider novo?" | "Implementa driver" (prosa) | Implementa `ProviderDriver` interface + identity injection + `ProviderComplianceTest` (contrato) |

**Diferenca conceitual**:

Codex desenhou o **mapa**. Esta spec define o **kernel** que faz o mapa ser obedecido por compilador, tipo e teste.

Topologia sem kernel e doutrina sem enforcamento. Doutrina sem enforcamento e PowerPoint.

---

## Unified Repair Loop Foundation

O Repair Loop unico do Atlas AI passa a ter um contrato de kernel em
`app/Services/Ai/Kernel/Repair/*`. Esta primeira fundacao nao migra execucoes
existentes e nao altera comportamento de producao: ela define a forma canonica
para qualquer repair futuro ser planejado, limitado, auditado e bloqueado antes
de existir uma tentativa real.

Fluxo alvo:

1. Gate, runtime, provider, tool, harness ou surface falha.
2. A falha e normalizada para `FailureClassification` usando o vocabulario
   fechado de `FailureDomain`.
3. O caller monta `RepairRequest` com `envelope_id`, `receipt_id`,
   classificacao de falha, `RepairPolicy`, tentativa atual, evidencias
   referenciadas e `dry_run`.
   `RepairRequestFactory::fromKernelContext()` e o caminho preferido para
   callers futuros montarem esse request a partir de contexto de kernel ou
   `DecisionRepairPolicy`, sem duplicar normalizacao local.
4. `AtlasRepairOrchestrator::plan()` retorna uma `RepairDecision` com um dos
   estados canonicos: `repair_allowed`, `repair_blocked`,
   `repair_exhausted` ou `needs_human_review`.
   `RepairStrategyResolver` concentra a matriz `FailureDomain` ->
   `RepairStrategy` e quais dominios exigem revisao humana.
5. `AtlasRepairOrchestrator::attempt()` formata um `RepairResult` e payloads
   prontos para o Evidence Ledger, usando eventos `REPAIR_INITIATED` e
   `REPAIR_COMPLETED`, mas sem executar repair real nesta fase.
   `RepairEvidencePayloadFormatter` e a fronteira canonica para montar esses
   payloads e calcular hashes deterministicos.

Pontos de entrada operacionais da fundacao:

- `atlas:ai:repair --json` retorna `planned_scaffold` com `RepairRequest`,
  `RepairDecision`, payload ledger-ready, `evidence_ledger` com status da
  gravacao e compliance report.
- `atlas:ai:repair --attempt-repair --json` retorna `attempted_scaffold` com
  `RepairResult`; a tentativa continua `executed=false` e registra
  `execution_blocked_by_dry_run`. Quando o ledger existe, a tentativa scaffold
  grava o par `REPAIR_INITIATED`/`REPAIR_COMPLETED`; o evento completed usa
  `causation_id` apontando para o `decision_hash`.
- `POST /ai/repair` exposto por `AtlasAiRepairController` aceita
  `envelope_id`, `receipt_id`, `failure_domain` ou
  `failure_classification`, `policy`, `current_attempt`, `evidence_refs` e
  `attempt`. A rota usa `atlas.token`, forca `dry_run=true` e retorna o mesmo
  contrato do CLI para App, dashboard, mobile, Curator e futuras surfaces.
  CLI e API gravam a decisao planejada no Evidence Ledger como
  `REPAIR_INITIATED` quando a tabela `atlas_ledger_events` existe; quando
  `attempt=true`, tambem gravam `REPAIR_COMPLETED` por
  `AtlasEvidenceLedger::recordRepairResult()`. Em ambientes leves sem ledger,
  continuam retornando o mesmo contrato sem quebrar.

Estrategias canonicas iniciais:

- `retry_provider` para timeout/indisponibilidade de provider;
- `refresh_context` para falhas de contexto, memoria, profile ou attachment;
- `collect_evidence` para gate, evidence ou ledger incompleto;
- `repair_output` para output/runtime/decision invalidos;
- `rerun_tool` para falha de tool runtime;
- `rerun_harness` para falha de harness;
- `human_review` quando o dominio de falha exige julgamento humano;
- `none` reservado para estados terminais.

Motivos canonicos de decisao e tentativa:

- `repair_policy_disabled`;
- `max_attempts_reached`;
- `failure_domain_requires_human_review`;
- `no_automatic_strategy_for_failure_domain`;
- `strategy_not_allowed`;
- `heavy_repair_requires_evidence_refs`;
- `repair_planned`;
- `repair_not_allowed`;
- `execution_blocked_by_dry_run`;
- `execution_not_implemented_contract_foundation_only`;
- `envelope_id_required`;
- `current_attempt_must_be_non_negative`;
- `allowed_strategies_required`.

Invariantes:

- Todo motivo emitido por `RepairDecision`, `RepairAttempt` ou payload de
  evidence vem do enum fechado `RepairReason`.
- `envelope_id` e estrategias permitidas sao parte minima do contrato; requests
  sem esses campos retornam `repair_blocked` com motivo auditavel.
- `current_attempt >= max_attempts` sempre retorna `repair_exhausted`.
- Dominios de policy, privacy, security, compliance, provider refusal,
  runtime unsupported, replay mismatch e unknown exigem `needs_human_review`.
- Estrategias pesadas como `rerun_tool` e `rerun_harness` exigem
  `evidence_refs` quando `requires_evidence_for_heavy_repair=true`.
- `dry_run` nunca executa repair real.
- Mesmo fora de `dry_run`, esta fundacao retorna `executed=false` ate uma
  sessao futura plugar executores reais.
- CLI e API nunca permitem desligar `dry_run`; qualquer execucao real futura
  deve entrar por executor aprovado, com policy explicita, evidence refs e
  ledger append-only.
- Payloads de `RepairDecision` e `RepairResult` incluem hashes deterministicos
  para facilitar replay, deduplicacao e auditoria no Evidence Ledger futuro.
- O hash canonico ordena chaves associativas antes de assinar, preservando a
  ordem de listas como `evidence_refs`.
- `RepairPolicy::fromDecisionRepairPolicy()` permite consumir a policy emitida
  pelo Decide sem duplicar contrato central nem alterar `DecisionReceipt`.
- `RepairRequestFactory` e a fronteira recomendada para AiWorker/dev/forge
  futuros montarem requests de repair; callers nao devem montar arrays ad hoc
  quando tiverem `FailureClassification` e policy tipada.
- `RepairStrategyResolver` e a fronteira recomendada para manter a matriz de
  strategy/human-review fora de callers e fora do orchestrator.
- `RepairEvidencePayloadFormatter` concentra eventos de ledger, schema version,
  `decision_hash`, `result_hash` e canonicalizacao de payload.
- `atlas:ai:architecture-validate --json` inclui AP18
  `repair_loop_contract`, que falha se o contrato tipado do Repair Loop,
  o comando `atlas:ai:repair`, a rota `POST /ai/repair` ou os guards de
  scaffold-safe/dry-run forem removidos ou desviados. AP18 tambem verifica
  que o repair nativo do `AiWorker` passa por `AtlasRepairOrchestrator` e
  `RepairRequestFactory` antes de enfileirar novos jobs de reparo, e que
  `AtlasProgrammingOrchestrator::sessionPlan()` publica
  `repair_execution_contract` com policy explicita do Kernel Repair. AP18
  tambem verifica que `EngineeringHarnessExecutionService` anexa
  `kernel_repair_decision` em resultados de Forge/Harness bloqueados, parciais
  ou falhos, grava a decisao no Evidence Ledger como `REPAIR_INITIATED`, e nao
  executa reparo por fora do kernel.

Integracao futura:

- `AiWorker` ja consulta o contrato do Repair Loop no repair nativo de
  programacao: ele monta `RepairRequest`, recebe `RepairDecision`, anexa a
  decisao em metadata/ledger payload e respeita bloqueios do kernel antes de
  enfileirar novo job de reparo. A execucao final do repair continua pelo
  mecanismo legado ate o executor real do kernel ser plugado.
- `AtlasProgrammingOrchestrator` ja faz `atlas dev`, `atlas fix` e
  `atlas chat --dev` nascerem com `repair_execution_contract`, incluindo
  `kernel_repair_contract`, estrategias permitidas, estrategias pesadas e
  obrigatoriedade de evidencia para repair pesado.
- `EngineeringHarnessExecutionService` ja anexa `kernel_repair_decision` e
  `repair_contract` quando o Engineering Harness retorna `blocked`, `partial`
  ou falha de policy preflight. Falhas de contrato read-only viram
  `tool.policy_denied` e exigem `human_review`; falhas de harness com run/evidence
  refs podem planejar `rerun_harness` respeitando limite de tentativas e
  evidence required para repair pesado. A decisao tambem e gravada pelo
  `AtlasEvidenceLedger::recordRepairDecision()` como evento `REPAIR_INITIATED`
  com `emitter_stage=engineering_harness.repair`, deixando replay, auditoria e
  Curator com uma fonte canonica do repair planejado.
- `atlas:ai:repair-report` e `GET /ai/repair/report` expõem o read model de
  Repair Loop por janela com filtros por `status`, `strategy`,
  `failure_domain` e `emitter_stage`. Esses filtros sao parte do contrato
  operacional AP18: operador, dashboard e Curator devem consultar o repair
  pelo replay service, nao por payload raw nem por queries locais duplicadas.
- `atlas dev` e `atlas complete` devem classificar falhas em `FailureDomain` e
  passar pelo `AtlasRepairOrchestrator` antes de acionar repair de engenharia.
- Nenhum caller deve gravar eventos de repair com formato proprio; o payload do
  `RepairDecision`/`RepairResult` deve ser a origem canonica para o Evidence
  Ledger.

**Fim da especificacao.**

Esta e a arquitetura mae do Atlas AI. Topologia abaixo, dominios em cima, surfaces na borda. O cerebro fica aqui.
