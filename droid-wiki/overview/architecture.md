# Architecture

Atlas Server is a single Laravel application. The HTTP layer is thin (`routes/api.php`, protected by a static `X-Atlas-Token`); the real surface is 1,227 artisan commands dispatched by the `bin/atlas` bash launcher, which injects the caller's working directory as `--workspace`. The intelligence lives under `app/Services/`, overwhelmingly `app/Services/Ai/` (112 subdirectories).

## Layering

The system has eight conceptual layers. Each one is a wiki page set under [systems/](../systems/index.md):

```mermaid
graph TD
    HTTP["HTTP (X-Atlas-Token, thin)"]
    CLI["bin/atlas CLI (1,227 commands)"]
    Capture["Capture & Ingestion"]
    Gateway["AI Gateway"]
    Brain["Open Brain (memory + MCP)"]
    Eng["Engineering plane"]
    Loop["Autonomous Evolution Loop"]
    Gov["Self-Construction Government"]
    Biz["Business Domains"]

    HTTP --> Capture
    HTTP --> Gateway
    CLI --> Gateway
    CLI --> Eng
    CLI --> Loop
    Capture -->|"cognitive quarantine gate"| Brain
    Gateway <-->|"context injection / recall"| Brain
    Gateway -->|"harness runs"| Eng
    Eng -->|"meta_harness engine"| Loop
    Loop -->|"governed by"| Gov
    Loop -->|"business scopes"| Biz
    Gateway -->|"domain orchestrators"| Biz
```

## Data flow: capture to knowledge

A raw capture enters through the HTTP API, is stored with cognitive quarantine metadata, and can only become memory or context after human ratification:

```mermaid
graph LR
    Device["iPhone/Mac app"]
    API["POST /captures or /sync"]
    Store["CaptureService (idempotent by client_id)"]
    Quarantine["Cognitive quarantine metadata"]
    Whisper["whisper.cpp transcription"]
    Clarify["Semantic clarifier"]
    Proposal["Curation proposal (pending)"]
    Ratify["Human ratification"]
    Memory["Canonical memory / Open Brain"]

    Device -->|"audio/text/photo"| API
    API --> Store
    Store --> Quarantine
    Store -->|"audio"| Whisper
    Whisper --> Clarify
    Clarify --> Proposal
    Proposal --> Ratify
    Ratify --> Memory
```

## Data flow: AI interaction lifecycle

The app never calls models directly. An interaction creates a trace and a job; a local worker runs the provider CLI:

```mermaid
sequenceDiagram
    participant App as App / CLI
    participant Ctrl as AiInteractionController
    participant GW as AiGatewayService
    participant DB as PostgreSQL
    participant Worker as atlas:ai:work
    participant CLI as Provider CLI (claude/codex)

    App->>Ctrl: POST /ai/interactions
    Ctrl->>GW: enqueueInteraction(input, options)
    GW->>GW: select provider, resolve model, build prompt
    GW->>DB: INSERT ai_traces + ai_jobs (transaction)
    GW-->>App: 202 Accepted (AiTraceResource)
    Worker->>DB: claim oldest queued job (lockForUpdate)
    Worker->>Worker: run gate chain (fair mode, permissions, policy)
    Worker->>CLI: spawn child process, stream stdout
    CLI-->>Worker: stream-json output
    Worker->>DB: persist ai_job_attempts + result
    Worker->>DB: update ai_jobs.status = succeeded
    App->>Ctrl: GET /ai/interactions/{trace} (poll or SSE stream)
    Ctrl-->>App: result + metadata
```

## The 8-phase evolution cycle

The Autonomous Evolution Loop runs a strict sequential cycle. Any phase failure aborts the cycle. Close-on-main is only "completed" with a real non-null `merged_sha`:

```mermaid
graph TD
    O["1. orient<br/>fix scope + budget + frozen contracts"]
    C["2. comprehend<br/>build grounded scope model (facts only)"]
    L["3. decide-leverage<br/>pick highest-leverage work (band + fresh offset)"]
    A["4. architect<br/>writer/critic projection loop (obligations)"]
    D["5. decompose<br/>split into committable-in-isolation packets"]
    I["6. implement<br/>delegate to Maestro, edit only allowed_files"]
    Cert["7. certify<br/>frozen judge + anti-farm floor + mutation"]
    M["8. close-on-main<br/>merge to main, emit merged_sha"]
    Learn["(9. learn)<br/>append-only outcome ledger"]

    O --> C --> L --> A --> D --> I --> Cert --> M --> Learn
    Cert -->|"regressed => abort"| Abort["cycle aborted"]
    M -->|"no merged_sha => abort"| Abort
```

## Separation of powers

The Self-Construction Government enforces that no single agent spans originate, implement, judge, merge, and promote. Each role is a distinct organ:

```mermaid
graph TD
    subgraph "Observe"
        Cortex["Cortex / World Model"]
    end
    subgraph "Decide"
        Goal["Goal & Value"]
        Strategy["Strategy Council"]
        Arch["Architecture Council"]
    end
    subgraph "Execute"
        Fabric["Task Fabric"]
        Maestro["Maestro Scheduler"]
        Workers["Worker Swarm"]
    end
    subgraph "Verify"
        Court["Verification Court"]
        Merge["Merge Governor"]
    end
    subgraph "Learn"
        Receipts["Receipts / Evidence"]
        Transfer["Learning Transfer"]
        Sync["Knowledge Sync"]
    end

    Cortex --> Goal --> Strategy --> Arch --> Fabric --> Maestro --> Workers
    Workers --> Court --> Merge --> Receipts --> Transfer --> Sync --> Strategy
```

## Language breakdown

The codebase is almost entirely PHP:

| Language | Files | Approximate LOC |
|----------|-------|-----------------|
| PHP (app) | ~7,100 | ~1.73M |
| PHP (tests) | ~5,000 | ~1.0M |
| Bash (bin/, scripts/) | ~30 | ~3,000 |
| Markdown (docs/) | ~1,866 | ~large |
| YAML (config, CI) | ~25 | ~1,000 |

See [by the numbers](../by-the-numbers.md) for the full statistics snapshot.
