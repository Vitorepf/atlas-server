> Cleanup status: superseded_source_material.
> Canonical replacement: docs/engineering-knowledge-base/atlas-ai-operating-system.md; docs/engineering-knowledge-base/atlas-ai-pipeline.md; docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md; docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md.
> Cleanup note: P0 source material already partially promoted. Preserve terminology, but canonical authority is now the KB hierarchy.

> Authority warning: this draft uses "canonical" in historical sections. Current authority is the replacement set above plus the Canonical Architecture Index.

# Atlas Domain Profile Orchestration Architecture

Status: canonical architecture draft
Date: 2026-05-03
Scope: Atlas AI, Atlas app, Atlas CLI, Atlas Decide, domain profiles, flow profiles, model policy, skills, memory, runtimes, quality gates

## Document Hierarchy

This is the parent architecture for Atlas AI domain execution.

It defines the canonical structure for:

- domain profiles
- flow profiles
- model policies
- orchestration policies
- skill policies
- memory policies
- tool policies
- gate policies
- domain orchestrators
- domain runtimes

Domain-specific documents must be treated as specializations of this architecture, not as competing sources of truth.

Current specialization:

- `2026-05-03-atlas-programming-product-architecture.md` specializes this architecture for the `programming` domain, especially `programming.dev` and `programming.forge`.

If this document and a domain-specific document appear to conflict, resolve the conflict by keeping this document as the conceptual parent and updating the domain-specific document to explain its specialization.

## Executive Decision

Atlas profiles are not model presets.

The canonical rule is:

> A profile is a domain operating system. It defines models, providers, orchestrator, runtime, memory, skills, tools, gates, autonomy, evidence, fallback, background policy, and learning loop.

Any implementation that treats `general`, `research`, `finance`, `programming`, `health`, `personal_development`, `qa`, or `security` as just a prompt, a provider choice, or a model dropdown is architecturally wrong.

Atlas must not become a pile of provider presets. Atlas must become a set of professional domain workflows, all governed by the same policy and trace contracts.

## Why This Exists

The Atlas app will eventually expose choices such as:

- general
- research
- super research
- programming
- finance
- health
- personal development
- learning
- writing
- QA
- security
- operations

These labels look small in the UI, but they must map to large, structured systems behind the scenes.

Example:

- `programming.forge` is not "Codex with a stronger model". It is task contract, blueprint, repository context, sandbox/worktree, implementation, tests, repair loop, quality scan, patch artifact, evidence, score, and memory.
- `research.super` is not "Gemini with long context". It is clarification, source discovery, evidence extraction, contradiction search, source map, synthesis, uncertainty, citation gate, decision memo, and memory.
- `personal_development.book_to_action` is not "Claude as coach". It is book context, personal history, goals, values, constraints, behavior design, action plan, accountability loop, review gate, and memory delta.

The architecture must make this explicit so future implementations do not collapse the system into simple presets.

## Non-Negotiable Principles

### 1. Atlas Is The Identity, Providers Are Engines

Claude, Codex, Gemini, GPT, local models, and future models are replaceable engines.

They must not own:

- memory
- identity
- workflow policy
- task state
- quality standard
- domain semantics
- final product behavior

Atlas owns those.

### 2. Domain Profile Is Not Flow Profile

A domain is a broad operating area.

Examples:

- `programming`
- `research`
- `finance`
- `personal_development`
- `health`
- `learning`
- `writing`
- `security`
- `qa`
- `operations`

A flow is a concrete workflow inside a domain.

Examples:

- `programming.dev`
- `programming.forge`
- `research.quick`
- `research.super`
- `finance.portfolio_review`
- `personal_development.book_to_action`
- `security.threat_review`
- `qa.regression_review`

Do not overload one field to mean both.

### 3. Profiles Select Orchestration, Not Just Models

Every profile must be able to declare:

- provider/model policy
- context strategy
- orchestrator
- runtime
- skills
- tools
- memory sources
- gates
- autonomy
- evidence requirements
- background permissions
- fallback graph
- learning/memory behavior

### 4. Atlas Decide Routes, Domain Orchestrators Execute

`AtlasDecideService` should decide:

- domain
- flow
- provider/model candidates
- fallback
- graph shape
- budget and safety constraints
- context strategy

Domain orchestrators should decide:

- exact execution phases
- task-specific contracts
- runtime invocation
- repair policy
- domain-specific quality gates
- evidence packet
- memory delta

Atlas Decide must not become a giant monolith with custom logic for every domain.

### 5. Every Domain Can Have A Mini Runtime

The Engineering Harness is the heavy runtime for programming/engineering.

Other domains should have equivalent domain runtimes when needed:

- `ResearchRuntime`
- `FinanceRuntime`
- `PersonalDevelopmentRuntime`
- `LearningRuntime`
- `HealthRuntime`
- `WritingRuntime`
- `SecurityRuntime`
- `QaRuntime`

These should not all be called "Harness" unless they actually share the Engineering Harness execution model. The better generic term is `DomainRuntime`.

### 6. Domain Work Must Be Auditable

Every non-trivial run must produce:

- selected domain
- selected flow
- policy receipt
- model/provider graph
- context refs
- skills used
- tools used
- gates run
- evidence packet
- quality result
- memory delta proposal
- final status

No "it just answered" for enterprise-grade flows.

## Conceptual Stack

```text
Atlas
  Atlas AI
    Runtime Settings
    Domain Profiles
    Flow Profiles
    Model Policies
    Orchestration Policies
    Skill Policies
    Memory Policies
    Tool Policies
    Gate Policies

  Atlas Decide
    classify intent
    select domain/flow
    select provider/model graph
    enforce budget/safety
    emit policy receipt

  Domain Orchestrators
    ProgrammingOrchestrator
    ResearchOrchestrator
    FinanceOrchestrator
    PersonalDevelopmentOrchestrator
    HealthOrchestrator
    LearningOrchestrator
    WritingOrchestrator
    SecurityOrchestrator
    QaOrchestrator

  Domain Runtimes
    EngineeringHarness
    ResearchRuntime
    FinanceRuntime
    PersonalDevelopmentRuntime
    HealthRuntime
    LearningRuntime
    WritingRuntime
    SecurityRuntime
    QaRuntime

  Providers
    Claude
    Codex
    Gemini
    GPT
    local/future engines
```

## Canonical Data Model

### `ai_runtime_settings`

Global runtime settings. This is not enough by itself.

Responsibilities:

- provider availability
- provider manual/auto permissions
- default models
- default model labels/tiers
- budget settings
- global background permission
- global operator mode

Example:

```json
{
  "default_provider": "claude_cli",
  "providers": {
    "claude_cli": {
      "allow_auto": true,
      "allow_manual": true,
      "model": "claude-sonnet-4-6"
    },
    "codex_cli": {
      "allow_auto": false,
      "allow_manual": true,
      "model": "gpt-5.3-codex-spark"
    },
    "gemini_cli": {
      "allow_auto": true,
      "allow_manual": true,
      "model": "gemini-3.1-pro-preview"
    }
  }
}
```

### `ai_domain_profiles`

Domain-level operating profile.

Suggested fields:

```json
{
  "id": "programming",
  "label": "Programming",
  "status": "active",
  "default_flow": "programming.dev",
  "orchestrator": "AtlasProgrammingOrchestrator",
  "runtime_family": "engineering",
  "memory_policy_id": "memory.programming",
  "skill_policy_id": "skills.programming",
  "tool_policy_id": "tools.programming",
  "gate_policy_id": "gates.programming",
  "autonomy_default": "medium",
  "background_allowed": false,
  "description": "Code, debugging, refactor, QA, engineering tasks."
}
```

Rules:

- A domain profile must not contain provider-specific prompt hacks.
- A domain profile may set defaults, but flow profiles override specifics.
- Domain profile ids must be stable. Do not rename casually.
- A domain profile must name an orchestrator or explicitly inherit `standard_response`.

### `ai_flow_profiles`

Concrete workflow inside a domain.

Suggested fields:

```json
{
  "id": "programming.forge",
  "domain": "programming",
  "label": "Forge",
  "status": "active",
  "orchestrator": "AtlasProgrammingOrchestrator",
  "runtime": "EngineeringHarness",
  "model_policy_id": "models.programming.forge",
  "context_policy_id": "context.programming.forge",
  "skill_policy_id": "skills.programming.forge",
  "tool_policy_id": "tools.programming.forge",
  "gate_policy_id": "gates.programming.forge",
  "evidence_policy_id": "evidence.programming.forge",
  "memory_policy_id": "memory.programming.forge",
  "autonomy": "high",
  "background_allowed": false,
  "requires_human_approval_for_destructive": true
}
```

Rules:

- Flow profile is the normal unit selected by Atlas Decide.
- Flow profile determines the expected execution contract.
- Flow profile must have explicit quality gates.
- Flow profile must say whether it can run in background.

### `ai_model_policies`

Model/provider policy by flow.

This must support single-model, fallback, scout/executor, council, and multi-stage graphs.

Example for programming:

```json
{
  "id": "models.programming.forge",
  "default_graph": "scout_execute_review",
  "nodes": [
    {
      "id": "context_scout",
      "role": "context_scout",
      "preferred_provider": "gemini_cli",
      "fallback_order": ["gemini_cli", "claude_cli"],
      "allow_auto": true
    },
    {
      "id": "implementation_executor",
      "role": "executor",
      "preferred_provider": "codex_cli",
      "fallback_order": ["codex_cli", "claude_cli"],
      "allow_auto": true
    },
    {
      "id": "critical_reviewer",
      "role": "reviewer",
      "preferred_provider": "claude_cli",
      "fallback_order": ["claude_cli", "codex_cli"],
      "allow_auto": true
    }
  ],
  "manual_override_allowed": true,
  "respect_global_provider_blocks": true
}
```

Example for super research:

```json
{
  "id": "models.research.super",
  "default_graph": "source_scout_extract_critic_synthesize",
  "nodes": [
    {
      "id": "source_scout",
      "role": "source_discovery",
      "preferred_provider": "gemini_cli"
    },
    {
      "id": "evidence_extractor",
      "role": "evidence_extraction",
      "preferred_provider": "claude_cli"
    },
    {
      "id": "adversarial_critic",
      "role": "critic",
      "preferred_provider": "codex_cli"
    },
    {
      "id": "synthesis",
      "role": "synthesis",
      "preferred_provider": "claude_cli"
    }
  ]
}
```

Rules:

- Model policy is not allowed to bypass global provider settings unless a trusted operator override explicitly allows it.
- Model policy must describe roles, not just providers.
- Every node must be traceable.
- Fallback reason must be recorded.

### `ai_orchestration_policies`

Defines phase structure and execution style.

Example:

```json
{
  "id": "orchestration.research.super",
  "phases": [
    "clarify",
    "scope",
    "source_discovery",
    "evidence_extraction",
    "contradiction_search",
    "synthesis",
    "quality_review",
    "decision_brief",
    "memory_delta"
  ],
  "repair_loop": {
    "enabled": true,
    "max_iterations": 2,
    "repair_when": ["insufficient_sources", "citation_gap", "contradiction_unresolved"]
  }
}
```

Rules:

- Orchestration policy must be declarative enough for UI and traces.
- Domain orchestrator may add implementation details, but should not hide phases.
- Repair loops must have stop conditions.

### `ai_skill_policies`

Skills by domain/flow.

Example:

```json
{
  "id": "skills.personal_development.book_to_action",
  "required": [
    "book_synthesis",
    "personal_memory_recall",
    "behavior_design",
    "weekly_review"
  ],
  "optional": [
    "values_alignment",
    "habit_design",
    "decision_journal"
  ],
  "forbidden": [
    "medical_diagnosis",
    "unlicensed_therapy_claims"
  ]
}
```

Rules:

- Skills are operational contracts, not personas.
- Skills must declare when to use, when not to use, input, output, gates, and evals.
- Skills must be versioned in traces.

### `ai_memory_policies`

Memory selection per domain/flow.

Example:

```json
{
  "id": "memory.programming.forge",
  "sources": [
    "atlas_memory_registry",
    "engineering_knowledge_base",
    "code_intelligence",
    "recent_traces",
    "project_decisions"
  ],
  "required": ["project_decisions", "code_intelligence"],
  "max_budget_chars": 24000,
  "include_provider_safe_only": true,
  "memory_delta": {
    "enabled": true,
    "requires_review": false,
    "record": ["decision", "technical_context", "harness_learning"]
  }
}
```

Example for personal development:

```json
{
  "id": "memory.personal_development.weekly_review",
  "sources": [
    "goals",
    "values",
    "habits",
    "journals",
    "prior_reviews",
    "book_notes"
  ],
  "memory_delta": {
    "enabled": true,
    "requires_review": true,
    "record": ["preference", "learning", "goal_update", "pattern"]
  }
}
```

Rules:

- Memory belongs to Atlas, not providers.
- Provider projections are generated views, not canonical memory.
- Sensitive domains must use stricter privacy and review gates.
- Memory writes must distinguish observation, hypothesis, decision, and preference.

### `ai_gate_policies`

Quality gates by flow.

Example:

```json
{
  "id": "gates.finance.portfolio_review",
  "required": [
    "source_freshness",
    "not_financial_advice_disclaimer",
    "risk_summary",
    "assumptions_list",
    "uncertainty_level",
    "decision_options"
  ],
  "blocking": [
    "missing_prices",
    "stale_market_data",
    "unsupported_recommendation"
  ]
}
```

Rules:

- Gates must be domain-specific.
- A gate must be machine-checkable where possible.
- Subjective gates must produce explicit review findings.
- High-stakes domains require stronger gates.

## Canonical Runtime Contract

Every domain runtime should accept and emit the same broad structure.

Input:

```json
{
  "task_request": {},
  "domain_profile": {},
  "flow_profile": {},
  "model_policy": {},
  "context_pack": {},
  "memory_pack": {},
  "operator_options": {},
  "surface": "app|cli|background|scheduler",
  "permissions": {}
}
```

Output:

```json
{
  "run_id": "uuid",
  "status": "passed|partial|blocked|failed|needs_review",
  "domain": "research",
  "flow": "research.super",
  "orchestrator": "AtlasResearchOrchestrator",
  "runtime": "ResearchRuntime",
  "provider_graph": {},
  "phases": [],
  "evidence_packet": {},
  "quality_gates": [],
  "memory_delta": {},
  "cost": {},
  "duration_ms": 0,
  "next_actions": []
}
```

## Domain Orchestrator Interface

Every domain orchestrator should implement the same conceptual methods.

```php
interface AtlasDomainOrchestrator
{
    public function domain(): string;

    public function supports(TaskRequest $task, EffectiveProfile $profile): bool;

    public function plan(DomainExecutionRequest $request): DomainExecutionPlan;

    public function execute(DomainExecutionRequest $request): DomainExecutionResult;

    public function repair(DomainExecutionResult $result): ?DomainExecutionRequest;

    public function summarize(DomainExecutionResult $result): DomainRunSummary;
}
```

Rules:

- Orchestrator owns workflow.
- Runtime owns execution mechanics.
- Atlas Decide owns routing and policy receipt.
- Gateway/CLI/app are surfaces, not workflow authorities.

## Domain Examples

### General

Purpose:

- normal conversation
- quick answers
- organization
- light reasoning
- simple planning

Default flows:

- `general.answer`
- `general.plan`
- `general.review`

Likely orchestrator:

- `AtlasGeneralOrchestrator`

Typical model policy:

- default provider from runtime settings
- no heavy graph by default
- optional fallback

Required gates:

- response sanity
- memory safety
- no unsupported certainty

### Research

Purpose:

- source-grounded investigation
- comparison
- synthesis
- decision briefs

Flows:

- `research.quick`
- `research.deep`
- `research.super`
- `research.cited_report`
- `research.decision_brief`

Orchestrator:

- `AtlasResearchOrchestrator`

Runtime:

- `ResearchRuntime`

Important phases:

- clarify question
- define scope
- gather context
- source discovery
- evidence extraction
- contradiction search
- synthesis
- citation/source map
- uncertainty statement
- memory delta

Do not:

- answer super research as one direct provider call
- trust uncited claims
- hide uncertainty

### Programming

Purpose:

- code
- debug
- refactor
- QA
- security review
- architecture

Flows:

- `programming.dev`
- `programming.debug`
- `programming.refactor`
- `programming.qa`
- `programming.security`
- `programming.forge`

Orchestrator:

- `AtlasProgrammingOrchestrator`

Runtime:

- simple provider path
- `dev_repair_executor`
- `EngineeringHarness`

Important phases:

- task contract
- code intelligence
- plan
- edit
- tests/static checks
- repair
- review
- patch artifact
- memory update

Do not:

- route every programming task to Harness
- let CLI decide executor privately
- let provider choice replace quality gates

### Finance

Purpose:

- personal finance reasoning
- portfolio review
- market research
- risk analysis
- financial decision support

Flows:

- `finance.quick_answer`
- `finance.research`
- `finance.portfolio_review`
- `finance.risk_check`
- `finance.decision_memo`

Orchestrator:

- `AtlasFinanceOrchestrator`

Runtime:

- `FinanceRuntime`

Important phases:

- identify decision type
- gather current data
- validate source freshness
- identify assumptions
- risk analysis
- scenarios
- recommendation boundaries
- decision memo
- memory delta

Required gates:

- source freshness
- assumptions listed
- risks listed
- not financial advice boundary
- no fabricated prices

Do not:

- use stale financial data silently
- give unsupported buy/sell certainty
- mix personal facts into provider prompts without privacy review

### Personal Development

Purpose:

- self-reflection
- goals
- habits
- book-to-action
- weekly reviews
- behavior change
- long-term continuity

Flows:

- `personal_development.reflect`
- `personal_development.plan`
- `personal_development.book_to_action`
- `personal_development.weekly_review`
- `personal_development.corrective_loop`

Orchestrator:

- `AtlasPersonalDevelopmentOrchestrator`

Runtime:

- `PersonalDevelopmentRuntime`

Important phases:

- recall personal context
- identify objective
- map values/goals
- extract useful concepts
- convert to action
- define review loop
- memory delta proposal

Required gates:

- avoid therapy/medical overclaim
- distinguish fact from interpretation
- action plan must be concrete
- memory writes reviewed when sensitive

Do not:

- treat personal development as generic coaching prompt
- write sensitive memory as truth without review
- ignore long-term goals and history

### Health

Purpose:

- health tracking interpretation
- preparation for doctor conversations
- habit support
- cautious educational explanation

Flows:

- `health.educational`
- `health.symptom_prepare`
- `health.habit_review`
- `health.lab_explanation`

Orchestrator:

- `AtlasHealthOrchestrator`

Runtime:

- `HealthRuntime`

Required gates:

- medical safety boundary
- uncertainty
- urgent-care escalation where appropriate
- no diagnosis claims
- source quality
- privacy review

Do not:

- diagnose
- replace clinician advice
- write sensitive conclusions as memory without review

### QA

Purpose:

- product quality review
- regression review
- acceptance criteria
- trace/evidence inspection

Flows:

- `qa.regression_review`
- `qa.acceptance_review`
- `qa.release_gate`
- `qa.trace_audit`

Orchestrator:

- `AtlasQaOrchestrator`

Runtime:

- `QaRuntime`

Required gates:

- acceptance criteria coverage
- evidence completeness
- regression risk
- failing-test classification
- release recommendation

### Security

Purpose:

- security review
- threat modeling
- dependency risk
- permission review
- data exposure review

Flows:

- `security.threat_model`
- `security.code_review`
- `security.permission_review`
- `security.release_gate`

Orchestrator:

- `AtlasSecurityOrchestrator`

Runtime:

- `SecurityRuntime`

Required gates:

- threat model
- exploitability
- data exposure
- destructive action approval
- remediation priority

Do not:

- execute exploit-like behavior without explicit safe scope
- hide uncertainty
- treat security as ordinary code review

## App Architecture

The app should expose policy configuration in layers.

### Settings: Providers

Global provider controls:

- default provider
- model per provider
- auto allowed
- manual allowed
- budget
- daily token limits
- background allowed

### Settings: Domains

Domain-level controls:

- domain enabled/disabled
- default flow
- allowed providers
- default model policy
- autonomy default
- background allowed
- memory level
- quality level

### Settings: Flows

Flow-level controls:

- enabled/disabled
- executor/runtime
- model graph
- skill policy
- tool policy
- gates
- max iterations
- evidence requirement
- background permission

### Session Override

CLI/app may temporarily override:

- allowed provider set
- preferred model
- max budget
- autonomy
- no background
- require specific gate
- disable specific provider

Session override must not mutate canonical policy.

## CLI Architecture

CLI commands should remain simple.

Canonical commands:

```bash
atlas ask
atlas research
atlas dev
atlas forge
atlas review
atlas status
```

Long-term, the user should not need to remember complex flags.

Advanced flags are allowed for:

- debugging
- testing
- CI
- benchmark
- trusted operator override

But the default path must be intelligent enough to choose the right flow.

## Background Automation

Background execution is a separate policy dimension.

A flow is not background-safe just because it is useful.

Required background fields:

```json
{
  "background_allowed": true,
  "requires_explicit_enable": true,
  "max_autonomy": "low|medium|high",
  "allowed_actions": ["read", "draft", "suggest", "open_pr"],
  "forbidden_actions": ["destructive_write", "external_send"],
  "approval_required_for": ["apply_patch", "send_email", "financial_action"]
}
```

Rules:

- Background default is conservative.
- Background high-risk tasks require explicit allow.
- Background writes require gates.
- Destructive actions require approval.
- Background runs must leave clear traces and summaries.

## Effective Profile Contract

`AtlasAiPolicyService::effectiveProfile()` should eventually return a full effective profile combining:

- runtime settings
- domain profile
- flow profile
- model policy
- memory policy
- skill policy
- tool policy
- gate policy
- surface/session override
- budget
- background policy

Target shape:

```json
{
  "schema_version": 2,
  "profile_id": "programming.forge",
  "domain": "programming",
  "flow": "programming.forge",
  "surface": "cli",
  "mode": "programming",
  "task": "forge",
  "policy_version": "atlas-ai-policy-v2",
  "orchestrator": "AtlasProgrammingOrchestrator",
  "runtime": "EngineeringHarness",
  "model_policy": {},
  "context_policy": {},
  "skill_policy": {},
  "tool_policy": {},
  "memory_policy": {},
  "gate_policy": {},
  "execution_policy": {},
  "background_policy": {},
  "budget_policy": {},
  "fallback_order": [],
  "profile_context": {}
}
```

## Atlas Decide Contract

Atlas Decide should produce:

```json
{
  "decision_id": "uuid",
  "domain_selection": {
    "candidate_domain": "programming",
    "selected_domain": "programming",
    "reason": "operator requested dev workflow"
  },
  "flow_selection": {
    "candidate_flow": "programming.dev",
    "selected_flow": "programming.dev",
    "reason": "normal programming task"
  },
  "provider_selection": {},
  "model_graph": {},
  "context_strategy": "repo_focused_context",
  "execution_strategy": "domain_orchestrator",
  "orchestrator": "AtlasProgrammingOrchestrator",
  "runtime": "dev_repair_executor",
  "quality_gates": [],
  "budget_decision": {},
  "policy_profile": {}
}
```

Rules:

- Atlas Decide chooses domain/flow and model graph.
- Atlas Decide does not execute domain phases.
- Atlas Decide should produce a receipt that surfaces can display.

## Anti-Patterns

These are explicitly forbidden.

### Anti-Pattern: Profile As Model Preset

Wrong:

```text
profile = finance
model = claude
prompt = "You are a finance expert"
```

Correct:

```text
profile = finance.portfolio_review
orchestrator = AtlasFinanceOrchestrator
runtime = FinanceRuntime
models = source scout + analyst + critic
gates = source freshness + risk + assumptions + disclaimer
memory = portfolio context + preferences + prior decisions
```

### Anti-Pattern: Giant Atlas Decide

Wrong:

```text
AtlasDecideService knows every phase of every domain.
```

Correct:

```text
AtlasDecideService routes.
Domain orchestrators execute.
Domain runtimes handle mechanics.
```

### Anti-Pattern: CLI/App As Workflow Authority

Wrong:

```text
AiChatCommand decides repair loop.
Settings screen decides domain execution.
CLI command builds private provider strategy.
```

Correct:

```text
CLI/app collect operator intent and display results.
Atlas Decide selects policy.
Domain orchestrator owns workflow.
Runtime executes.
```

### Anti-Pattern: Skills As Prompt Decorations

Wrong:

```text
skills = ["finance", "coach", "research"]
```

Correct:

```text
skills are versioned operational contracts with inputs, outputs, gates, evals, and activation rules.
```

### Anti-Pattern: Memory Dumping

Wrong:

```text
Attach all user memory to every domain prompt.
```

Correct:

```text
Memory policy selects scoped, provider-safe, task-relevant memory with privacy gates.
```

### Anti-Pattern: Background By Default

Wrong:

```text
If flow is useful, let it run automatically.
```

Correct:

```text
Background requires explicit policy, bounded autonomy, gates, and trace.
```

## Implementation Roadmap

### Phase 1: Registry And Schema

Create:

- `ai_domain_profiles`
- `ai_flow_profiles`
- `ai_model_policies`
- `ai_orchestration_policies`
- `ai_skill_policies`
- `ai_memory_policies`
- `ai_gate_policies`

Seed minimal profiles:

- `general.answer`
- `research.quick`
- `research.super`
- `programming.dev`
- `programming.forge`
- `finance.research`
- `personal_development.reflect`

Implementation checkpoint - 2026-05-03/2026-05-04:

- Initial `ai_domain_profiles` and `ai_flow_profiles` registry tables exist as an additive, idempotent schema slice.
- The registry seeds canonical starter domains: `general`, `research`, `programming`, `finance`, `personal_development`, `health`, `learning`, `writing`, `qa`, `security`, `operations`, and `background`.
- The registry seeds starter flows: `general.answer`, `research.quick`, `research.super`, `programming.dev`, `programming.forge`, `finance.research`, `personal_development.reflect`, and `background.safe`.
- `AiDomainProfile` and `AiFlowProfile` models provide the ORM boundary for profile registry rows.
- `AtlasDomainProfileRegistry` resolves flow profiles from the database when available and falls back to canonical static definitions when the tables do not exist yet.
- `AtlasAiPolicyService` now projects `domain`, `flow`, `domain_profile`, `flow_profile`, and `domain_profile_registry` into the effective policy receipt while preserving backward-compatible policy fields.
- This is deliberately a registry/receipt slice only. Flow profile data is visible to policy consumers, but runtime execution still uses the existing `execution_policy` authority until Effective Policy V2 explicitly owns merge/override semantics.

### Phase 2: Effective Policy V2

Extend `AtlasAiPolicyService`:

- resolve domain profile
- resolve flow profile
- merge runtime settings
- merge session override
- expose `schema_version=2`
- preserve backward-compatible fields temporarily

Implementation checkpoint - 2026-05-04:

- `AtlasEffectivePolicyComposer` now builds an explicit `effective_policy` receipt with schema version 2.
- The receipt records source order: domain profile, flow profile, runtime settings, session override, and legacy execution policy.
- Session overrides can lock provider/model/runtime routing fields for a single request or session: `default_provider`, provider model fields, `allowed_models`, fallback order, auto/manual/council/background flags, model policy, and autonomy.
- Session overrides cannot silently change executor authority. `execution_policy` override attempts are recorded as merge warnings and ignored until Effective Policy V2 explicitly owns execution authority.
- The top-level policy remains backward-compatible for existing consumers while exposing `effective_policy` and `policy_merge_receipt` for new consumers.
- `atlas:ai:chat` and `atlas:cli:dev` now project simple CLI flags into this contract. `--ai=codex|claude|gemini` and `--model=<alias-or-id>` generate `ai_policy_override`, so the operator does not need to know the internal JSON shape.
- Interactive `atlas dev`/`atlas forge` forward the selected provider/model into the dev plan and each programming message plan, keeping session model locks visible to Atlas Decide, `AtlasAiPolicyService`, and trace/job payloads.
- Effective Policy V2 now exposes `operational_contracts` for `model_graph`, `context`, `memory`, `skills`, `tools`, and `gates`. These contracts normalize profile JSON into auditable runtime intent: graph nodes, context-pack requirement, memory recall scope, skill bundles, tool/evidence permissions, and blocking gates.
- The same contracts are copied to top-level `policy_contracts` for service consumers that receive the full profile instead of only the effective-policy receipt.
- The programming specialization now propagates these contracts through `AtlasProgrammingOrchestrator` session plans, dispatch contracts, repair contracts, provider metadata, worker completion metadata, and Harness completion contracts.
- Native programming repair now enforces the first contract slice: gate contracts can require evidence/test evaluation, and tool contracts can block write-based repair when the resolved policy is read-only.
- The Engineering Harness facade now enforces the same gate/tool contract slice before runner execution: strict/release/evidence gates upgrade Harness options to auto-test, complete mode, required quality scan, and strict harness policy; read-only tool contracts block provider-backed Harness writes while still allowing diagnostic no-provider runs.
- Direct programming provider execution now has a worker-side policy guard: read-only tool contracts force provider runtime permission to read mode, and strict/release/evidence gates block simple provider execution when the selected executor has no repair/test evidence path.
- `AiGatewayService` now emits `programming_policy_contract_receipt` for context, memory, and skills. The receipt records whether required context packs, Open Brain/memory injection, and required skill traces were satisfied, partial, missing, or pending for the actual prompt payload.
- The same receipt now includes `model_graph` fulfillment. It records selected provider/model, matched node id/role, allowed providers, fallback providers, and whether the runtime selection satisfied the graph, used an allowed fallback, drifted by model, or went out of contract.
- Programming execution now fails closed when the receipt reports `model_graph.status=out_of_contract`. This makes the policy graph more than preview metadata: a provider/model outside both graph nodes and fallback providers is rejected before enqueueing execution. Council execution is also checked as a composed runtime: `claude_codex` is allowed only when the graph explicitly allows `claude_codex` or allows the concrete council providers (`claude_cli` and `codex_cli`). Same-provider model drift is blocked when the graph has `strict_model_match=true`, which is emitted by fixed/locked model policies; flexible policies can still audit drift without failing closed.
- Skill contracts now map to concrete skill bundles and participate in runtime enforcement. Programming defaults no longer require a non-existent `programming` bundle: `programming.dev` requires `dev-quality-gate`, while `programming.forge` requires `engineering-blueprint`, `dev-quality-gate`, and `code-reviewer`. Prompt construction activates required bundles from `policy_contracts.skills.required_bundles`; if `require_skill_trace=true` and the trace is still missing, gateway execution fails with `atlas_skill_contract_policy_violation`.
- Context and memory contracts now participate in runtime enforcement. If `require_context_pack=true` and the prompt has no context pack, gateway execution fails with `atlas_context_contract_policy_violation`. If memory/Open Brain is required and no injection is delivered, execution fails with `atlas_memory_contract_policy_violation`. `AiPromptBuilder` automatically enables Open Brain `auto` mode when programming contracts require memory, while `degraded` Open Brain remains allowed and auditable to avoid treating sparse recall as a false failure.
- Tool contracts now participate in gateway runtime enforcement before trace/job enqueue. If a resolved contract does not allow workspace writes, requested `write`/`danger` permission is blocked with `atlas_tool_contract_policy_violation`; if destructive approval is required, unconfirmed `danger` permission is also blocked.

### Phase 3: Atlas Decide Domain Selection

Extend `AtlasDecideService`:

- classify domain
- classify flow
- return domain/flow selection receipt
- keep provider/model selection as one part of the decision, not the whole decision

### Phase 4: Generic Domain Orchestrator Contract

Create:

- `AtlasDomainOrchestrator` interface
- `DomainExecutionRequest`
- `DomainExecutionPlan`
- `DomainExecutionResult`
- `DomainRunSummary`

Adapt:

- `AtlasProgrammingOrchestrator` to implement the interface where practical.

### Phase 5: Research Orchestrator

Implement the next domain after programming:

- `AtlasResearchOrchestrator`
- `ResearchRuntime`
- `research.quick`
- `research.super`
- citation/source gates
- evidence packet

Reason:

Research validates the multi-model graph, source grounding, evidence, and non-code quality gates.

### Phase 6: App Policy UI

Build app UI:

- global providers
- domains
- flows
- model graph editor or simplified selector
- autonomy/background controls
- gate visibility
- trace viewer

Current implementation status:

- `GET /ai/policies/profiles` exposes the canonical domain/flow profile catalog to the app.
- `POST /ai/policies/preview` returns the `effective_policy` for a profile plus a session override.
- `PATCH /ai/policies/domains/{domain}` updates domain-level policy fields in `ai_domain_profiles`.
- `PATCH /ai/policies/flows/{flow}` updates flow-level policy fields in `ai_flow_profiles` and returns recalculated `effective_policy`.
- `atlas-app/lib/api/client.ts` has typed clients for profile catalog and policy preview.
- `SettingsSheet` reads the profile catalog next to provider runtime settings, shows source/domain/flow counts, includes a first operational flow editor for executor, autonomy, approval, background, minimum gate, model policy, context policy, memory policy, skill policy, and tool policy, and renders an `effective_policy` preview panel for authority/provider/model graph/executor/context/memory/skills/tools/gates.
- Database-backed flow `execution_policy` is now authoritative in `AtlasAiPolicyService`; `effective_policy.execution_authority` becomes `domain_flow_profile` when a DB flow supplies execution policy. `forge` and complete-dev guards still preserve required harness/repair behavior.

This is not yet the final visual policy studio. The first app editor exists, writes flow policy through the canonical API, and previews the merged effective policy plus normalized operational contracts. Programming now carries those contracts in execution metadata, emits fulfillment receipts for model graph/context/memory/skills, and enforces gate/tool contracts in native repair, direct provider execution, and the Engineering Harness facade. Full runtime enforcement and the deeper domain/flow policy editor for exact model graph editing, provider constraints, skill bundle selection, trace previews, and eval visibility remain next steps.

### Phase 7: Evals And Release Gates

Create domain evals:

- programming benchmark
- research citation benchmark
- finance risk benchmark
- personal development continuity benchmark
- QA/security benchmark

No domain should be called product-final without evals.

## Definition Of Done

This architecture is product-ready when:

- app can configure global provider settings
- app can configure domain and flow policies
- CLI can invoke domain flows without complex flags
- Atlas Decide emits domain/flow/model/policy receipts
- at least programming and research have real orchestrators
- domain runs produce evidence packets
- gates are visible in traces
- memory delta is explicit
- background execution is policy-gated
- evals exist for critical domains
- no surface owns domain workflow privately

## Guidance For Future AI Agents

If you are an AI working on Atlas:

1. Do not add a new mode as a prompt string.
2. Do not add a model dropdown and call it a profile.
3. Do not put domain workflow logic inside a controller, command, or app component.
4. Do not bypass Atlas Decide for provider/model/fallback policy.
5. Do not bypass the domain orchestrator for workflow phases.
6. Do not write provider-specific memory as canonical truth.
7. Do not make background automation permissive by default.
8. Do not implement domain flows without gates and traces.

The correct move is almost always:

1. Add or update a domain profile.
2. Add or update a flow profile.
3. Add or update model/context/skill/memory/gate policies.
4. Route through Atlas Decide.
5. Execute through the domain orchestrator.
6. Emit trace/evidence/quality/memory outputs.

## Final Architectural Line

Atlas should not be "Claude/Codex/Gemini with modes".

Atlas should be a cognitive operating system with domain-specific execution systems.

Profiles are not shortcuts. Profiles are professional workflows.
