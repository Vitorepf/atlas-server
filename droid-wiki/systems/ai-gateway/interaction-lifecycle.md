# Interaction lifecycle

This page traces one interaction end to end, from `POST /ai/interactions` through the controller's pre-processor pipeline and `AiGatewayService::enqueueInteraction`, down to the persisted `ai_traces` and `ai_jobs` rows. It is the "front half" of the gateway: everything that happens before the [local worker](the-local-worker.md) claims the job. The worker's claim loop and provider execution are covered separately.

## Key abstractions

| Path | Role |
|---|---|
| `app/Http/Controllers/AiInteractionController.php` | HTTP entry; runs the pre-processor pipeline then calls the gateway |
| `app/Services/Ai/RouterRuntime/AtlasHyperflowEntryService.php` | Intent to domain to flow to dispatch envelope, built before the legacy router |
| `app/Services/Ai/Router/AtlasAiRouterService.php` | Legacy router `decide()` (back-compat path) |
| `app/Services/Ai/Product/AtlasAutonomousProductDeliveryRuntimeService.php` | Plans autonomous product delivery into the payload |
| `app/Services/Ai/Product/AtlasAiAssistedExecutionQualityService.php` | Builds the assisted-execution quality envelope |
| `app/Services/Ai/Programming/AtlasDevRuntimeService.php` | Applies the dev runtime (workspace, plan); can reject with 422 |
| `app/Services/Ai/Router/AtlasAiSpecialistFlowRuntimeService.php` | Specialist-flow runtime enrichment |
| `app/Services/Ai/Router/AtlasAiSpecialistFlowExecutionService.php` | Specialist-flow execution binding |
| `app/Services/Ai/AgenticEngineeringOs/AtlasAaeosHttpPathFacadeService.php` | AAEOS placement facade; can block with 422 before any provider call |
| `app/Services/Ai/AiGatewayService.php` | `enqueueInteraction()` — provider selection, prompt build, trace+job persistence |
| `app/Services/Ai/AiPromptBuilder.php` | Assembles the final provider prompt from agent, context pack, execution plan |
| `app/Services/Ai/AiPrompt.php` | Immutable prompt DTO returned by the builder |
| `app/Services/Ai/AiIntentRouter.php` | Keyword to agent/intent classifier |
| `app/Services/Ai/CapturePrivacyService.php` | Privacy gate; can block an AI call on protected captures |
| `app/Models/AiTrace.php`, `AiJob.php` | The two persisted entities created in the enqueue transaction |

## How it works

### 1. HTTP entry and attachments

`AiInteractionController::store` is the single entry point. It first raises the request execution time and memory floor (the synchronous create pipeline can exceed the default 30s / 128M on a cold php-fpm request), then ingests attachments. Images and documents arrive three ways: direct `UploadedFile`s, chunked-upload IDs resolved through `AiChunkedUploadService`, and a `rich_input_payload` blob. Each is normalized into `payload.attachments` and `payload.image_attachments`. A bad attachment returns `422` with code `invalid_attachment` before any routing happens.

### 2. The pre-processor pipeline

After attachments, the controller runs an ordered pipeline that enriches `$data['payload']` in place. Each step is a service injected into `store()`. Order matters: the canonical RouterRuntime envelope is built first so non-programming intents get a full intent to domain to flow to dispatch receipt, then the legacy router runs for back-compat.

```mermaid
sequenceDiagram
    participant App as App / CLI
    participant Ctrl as AiInteractionController
    participant HF as AtlasHyperflowEntryService
    participant Router as AtlasAiRouterService
    participant Prod as ProductDeliveryRuntime
    participant AEQ as AssistedExecutionQuality
    participant Dev as AtlasDevRuntimeService
    participant SF as SpecialistFlow
    participant AAEOS as AaeosHttpPathFacade
    participant GW as AiGatewayService
    participant DB as PostgreSQL

    App->>Ctrl: POST /ai/interactions (input, attachments)
    Ctrl->>Ctrl: ingest images / documents / rich payload
    Ctrl->>Ctrl: applyThreadRuntimePolicy + surface domain selection
    Ctrl->>HF: run(data)  %% intent -> domain -> flow -> dispatch envelope
    Ctrl->>Router: decide(data)  %% legacy router (back-compat)
    Ctrl->>Prod: plan(data)  %% autonomous product delivery
    Ctrl->>AEQ: buildEnvelope(data)  %% assisted-exec quality
    Ctrl->>Dev: apply(data)  %% dev runtime; can 422 (REQUIRES_WORKSPACE_CODE)
    Ctrl->>Ctrl: rejectUnsafeAssistedExecution(data)  %% guard
    Ctrl->>SF: runtime + execution + forge/obra binding
    Ctrl->>AAEOS: run(data, phase)  %% only when atlas.aaeos.http_path_phase active
    alt AAEOS blocks
        AAEOS-->>Ctrl: status=blocked
        Ctrl-->>App: 422 (blocker before any provider call)
    end
    Ctrl->>GW: enqueueInteraction(input_text, data)
    GW->>GW: privacy gate, normalizeOptions, select provider
    GW->>GW: resolve thread + session + handoff + compaction
    GW->>GW: build prompt (AiPromptBuilder)
    GW->>GW: resolve model, budget assert, scout gate, contracts
    GW->>DB: BEGIN; INSERT ai_traces + ai_jobs; COMMIT
    GW-->>Ctrl: AiTrace (status=queued)
    Ctrl->>Ctrl: dispatch ProcessAiAttachmentVisuals (queue=attachments)
    Ctrl-->>App: 202 Accepted (AiTraceResource)
```

The pipeline steps in order:

1. `applyThreadRuntimePolicy` — prevents legacy Inbox metadata from downgrading a conversation into read-only mode.
2. `applySurfaceDomainCatalogSelection` — surface domain catalog selection.
3. `AtlasHyperflowEntryService::run` — the canonical RouterRuntime envelope (intent, domain, flow, dispatch, receipt) attached as `payload.hyperflow_runtime`. Runs before the legacy router on purpose.
4. `applyAtlasAiRouterDecision` — the legacy `AtlasAiRouterService::decide`, kept for back-compat.
5. `applyProductDeliveryRuntime` — `AtlasAutonomousProductDeliveryRuntimeService::plan`.
6. `applyAssistedExecutionQuality` — `AtlasAiAssistedExecutionQualityService::buildEnvelope`.
7. `AtlasDevRuntimeService::apply` — the dev runtime; throws `RuntimeException` with code `REQUIRES_WORKSPACE_CODE` when a programming task has no workspace, which the controller surfaces as `422`.
8. `rejectUnsafeAssistedExecution` — guard that rejects unsafe assisted execution.
9. `specialistFlowRuntime->apply` then `specialistFlowExecution->apply` — specialist-flow runtime and execution.
10. `applyAtlasCodeForgeObraBinding` — Forge/Obra binding.
11. `AtlasAaeosHttpPathFacadeService` — only active when `config('atlas.aaeos.http_path_phase')` is in `{1,2,3,4}`. In legacy mode (default) it is a no-op. When active and the placement gate blocks, the controller returns `422` with the blocker envelope **before any provider call**.

### 3. The gateway enqueue

`AiGatewayService::enqueueInteraction($input, $options)` does the real work. It guards `config('atlas.ai.enabled')`, dedupes by `client_id` (returns the existing trace if present), runs the privacy gate via `CapturePrivacyService`, then:

- `decide->normalizeOptions` — normalizes legacy app/CLI payloads into the AtlasDecide contract (decision mode, operator-requested provider).
- `providerFromOptions` — picks the provider; `enforceFairModeProvider` pins it when fair mode is on. See [Provider selection and routing](provider-selection-and-routing.md).
- `decide->operationalDecision` — AtlasDecide yields the candidate vs selected provider, fallback reason, planned/runtime graph, and provider governance contract. The mesh auto-route can flip `kind` to `mesh` when the sealed advisor routes the mission to a governed many-agent fleet.
- `threads->resolve` then `sessions->ensureActive` — thread and session resolution, compaction, and a provider handoff brief if the provider is switching. See [Sessions, threads and streaming](sessions-threads-streaming.md).
- Persistent context, YouTube knowledge, and PDF-question visuals are merged into the options.
- `prompts->build` — `AiPromptBuilder` assembles the final prompt and returns an immutable `AiPrompt` (agent slug, intent, skill versions, context refs, model, task request, context pack, open brain injection, execution plan, activated skills).
- Programming context, memory, skill, and tool contract asserts run.
- Council check — if the interaction should run as a multi-provider council, `enqueueCouncilInteraction` takes a separate path (one job per council provider).
- `models->resolveWithSource` — resolves the model and its source/label/tier.
- `budgets->assertAllows` — the budget gate; throws if the provider's visible-token budget for the window is exhausted. See [Quality, budget and health](quality-budget-health.md).
- Programming model-graph receipt and contract assert.
- Atlas Scout gate — when enabled, a "scout" dependency job runs first and the executor's `available_at` is deferred until the scout deadline.
- `missionBridge->buildEnvelope` — the Kernel bridge (behind `atlas_ai.kernel_http_integration.enabled`, default false) stamps `mission_id` / `objective_id` / `work_order_id` onto the trace and job. Contractually non-throwing.

### 4. Persistence (one transaction)

Everything above is wrapped in `DB::transaction`. The gateway locks the thread and session, builds the decision receipt, then creates the `AiTrace` (status `queued`, with intent, agent, provider, model, skill versions, context refs, prompt hash, and a rich `metadata` JSONB holding privacy, thread/session, compaction, handoff, model runtime, task request, context pack, open brain injection, execution plan, skills, compute effort, decision receipt, AtlasDecide execution, kernel envelope, preflight, cognitive function, and hyperflow runtime) and the `AiJob` (status `queued`, kind, priority 0-100, the built prompt, payload, `available_at`, `max_attempts`, `timeout_seconds`).

If the scout gate is on, an Atlas Scout dependency job is enqueued at a higher priority (one less than the executor). The user message is recorded (`AiConversationRecorder`), session state is updated, a context snapshot is taken, and telemetry + audit events are emitted (`trace_created`, `job_enqueued`, `ai_trace_queued`).

The controller returns `202 Accepted` with an `AiTraceResource`. If PDF attachment background processing is enabled, `ProcessAiAttachmentVisuals` is dispatched on the `attachments` queue.

### 5. Council path

When `shouldRunCouncil` is true, `enqueueCouncilInteraction` creates one `AiJob` per council provider (claude_cli + codex_cli, kind `council`). The trace's provider is set to `claude_codex` and metadata carries `execution_policy: dual_review` plus the council progress counters. The [worker](the-local-worker.md) runs each child job and `AiCouncilCoordinator::sync` aggregates them into one combined/failed/cancelled trace response. Council is the deliberate multi-provider exception, covered in [Provider selection and routing](provider-selection-and-routing.md).

## Integration points

- **HTTP routes** — `POST /ai/interactions` (create), `GET /ai/interactions/{trace}` (show), `GET /ai/interactions/{trace}/stream` (SSE), `POST /ai/interactions/{trace}/feedback`, `GET /ai/interactions/{trace}/flow-status`. Full reference in [the HTTP API](../../api/ai-gateway-api.md).
- **Open Brain** — `AiPromptBuilder` injects brain recall and the context pack into the prompt. See [Open Brain](../open-brain/index.md).
- **Engineering plane** — `AtlasDevRuntimeService` and the Forge/Obra binding tie interactions to the engineering harness. See [Engineering](../engineering/index.md).
- **Self-Construction Government** — the AAEOS facade can block an interaction before any provider call when placement gates fail. See [Self-Construction Government](../self-construction-government/index.md).
- **Provider execution** — once the job is persisted, the [local worker](the-local-worker.md) claims and runs it.

## Key source files

| File | What to read |
|---|---|
| `app/Http/Controllers/AiInteractionController.php` | `store()` — the pre-processor pipeline and gateway call |
| `app/Services/Ai/AiGatewayService.php` | `enqueueInteraction()` and `enqueueCouncilInteraction()` |
| `app/Services/Ai/RouterRuntime/AtlasHyperflowEntryService.php` | `run()` — the canonical envelope |
| `app/Services/Ai/Programming/AtlasDevRuntimeService.php` | `apply()` and `REQUIRES_WORKSPACE_CODE` |
| `app/Services/Ai/AgenticEngineeringOs/AtlasAaeosHttpPathFacadeService.php` | `run()` and `isActive()` |
| `app/Services/Ai/AiPromptBuilder.php` | `build()` — prompt assembly |
| `app/Services/Ai/AiPrompt.php` | The immutable prompt DTO fields |
| `app/Services/Ai/AtlasDecideService.php` | `normalizeOptions()` and `operationalDecision()` |
| `routes/api.php` | The `ai/interactions*` route definitions |
